<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PriceRole;
use App\Enums\TicketChannel;
use App\Enums\TicketKind;
use App\Enums\TicketStatus;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\MemberPriceClaim;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceTier;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Carrello → ordine pending con posti in riserva → Checkout Session (docs/architettura.md §6.2). Prezzi, ruolo, fascia e
 * sconto si calcolano qui dal database, mai dal browser, e si fissano sull'ordine.
 */
class CheckoutService
{
    /** Minuti della Checkout Session (minimo Stripe 30); la riserva dura 5 minuti in più. */
    public const SESSION_MINUTES = 31;

    public const HOLD_EXTRA_MINUTES = 5;

    public const MAX_WORKSHOPS_PER_ORDER = 2;

    public function __construct(
        private PriceResolver $prices,
        private CapacityService $capacity,
        private PaymentService $payments,
        private StripeGateway $stripe,
    ) {}

    /**
     * @param  list<array{ticket_type_id: string, attendees: list<array{name: string, email: string}>}>  $lines
     */
    public function reserve(User $user, Event $event, array $lines, ?string $discountCode = null, string $locale = 'en'): Order
    {
        $this->closePreviousPending($user);

        return DB::transaction(function () use ($user, $event, $lines, $discountCode, $locale) {
            $expiresAt = now()->addMinutes(self::SESSION_MINUTES);
            $role = $user->isMemberUntil($expiresAt) ? PriceRole::Member : PriceRole::Public;
            $tier = $this->prices->activeTier($event, now());
            $types = $this->sellableTypes($event, array_column($lines, 'ticket_type_id'));
            $this->checkLimits($user, $event, $lines, $types);
            $code = $discountCode ? $this->findDiscount($discountCode, $event, $user, $role) : null;

            $rows = [];
            foreach ($lines as $line) {
                array_push($rows, ...$this->priceLine($user, $role, $tier, $types[$line['ticket_type_id']], $line['attendees'], $code, $locale));
            }

            $subtotal = array_sum(array_map(fn ($r) => $r['list_unit_amount_cents'] * $r['quantity'], $rows));
            $discount = array_sum(array_map(fn ($r) => $r['unit_discount_cents'] * $r['quantity'], $rows));

            try {
                $order = Order::create([
                    'event_id' => $event->id, 'user_id' => $user->id, 'buyer_email' => $user->email,
                    'status' => OrderStatus::Pending, 'price_role' => $role, 'price_tier_id' => $tier?->id,
                    'currency' => $event->currency, 'subtotal_cents' => $subtotal, 'discount_cents' => $discount,
                    'total_cents' => $subtotal - $discount, 'discount_code_id' => $discount > 0 ? $code->id : null,
                    'discount_reserved' => $discount > 0, 'checkout_expires_at' => $expiresAt,
                    'livemode' => $this->stripe->livemode(), 'locale' => $locale,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new ShopException('pending_order_exists');
            }

            $pools = [];
            foreach ($rows as $row) {
                $type = $row['type'];
                unset($row['type']);
                $item = $order->items()->create($row);
                if ($item->price_role === PriceRole::Member) {
                    $this->claimMemberPrice($item, $user, $type);
                }
                foreach ($type->pools as $pool) {
                    $pools[$pool->id] = ($pools[$pool->id] ?? 0) + $item->quantity;
                }
            }

            $this->capacity->hold($order, $pools, $expiresAt->copy()->addMinutes(self::HOLD_EXTRA_MINUTES));
            if ($discount > 0) {
                $this->redeem($code, $order, $user);
            }

            return $order;
        }, 3);
    }

    /** Prenotazione del prezzo membro: l'indice unico la fa vincere a una sola transazione, anche in parallelo. */
    public function claimMemberPrice(OrderItem $item, User $user, TicketType $type): MemberPriceClaim
    {
        try {
            return MemberPriceClaim::create([
                'user_id' => $user->id, 'event_id' => $type->event_id, 'kind' => $type->kind,
                'event_day_id' => $type->event_day_id, 'program_session_id' => $type->program_session_id,
                'order_id' => $item->order_id, 'order_item_id' => $item->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new ShopException('member_price_limit');
        }
    }

    /** Indirizzo a cui mandare l'acquirente: la pagina Stripe, o l'ordine se è già chiuso o a costo zero. */
    public function startCheckout(Order $order): string
    {
        $orderUrl = route('account.orders.show', $order);

        if ($order->status === OrderStatus::Pending && $order->total_cents === 0) {
            $this->payments->markPaid($order, null);

            return $orderUrl;
        }
        if ($order->status !== OrderStatus::Pending) {
            throw new ShopException('order_not_pending');
        }
        if ($order->stripe_checkout_session_id) {
            $session = $this->stripe->retrieveCheckoutSession($order->stripe_checkout_session_id);
            if ($session['status'] === 'open') {
                return $session['url'];
            }
            $this->payments->applySession($order, $session);

            return $orderUrl;
        }

        $this->extendIfNeeded($order);
        $order->load('items', 'priceTier', 'discountCode', 'user');

        $session = $this->stripe->createCheckoutSession([
            'mode' => 'payment',
            'client_reference_id' => $order->id,
            ...($order->user->stripe_customer_id ? ['customer' => $order->user->stripe_customer_id] : ['customer_email' => $order->buyer_email]),
            'line_items' => $order->items->map(fn (OrderItem $item) => [
                'price_data' => [
                    'currency' => $order->currency,
                    'unit_amount' => $item->unit_amount_cents,
                    'product_data' => ['name' => $item->stripe_line_name, 'metadata' => ['order_item_id' => $item->id]],
                ],
                'quantity' => $item->quantity,
            ])->all(),
            // Solo carta: Apple Pay e Google Pay passano come carta (§6.9).
            'payment_method_types' => ['card'],
            'allow_promotion_codes' => false,
            'metadata' => [
                'order_id' => $order->id, 'kind' => 'tickets', 'price_role' => $order->price_role->value,
                'price_tier' => $order->priceTier?->code, 'discount_code' => $order->discountCode?->code, 'env' => app()->environment(),
            ],
            'payment_intent_data' => ['metadata' => ['order_id' => $order->id]],
            'expires_at' => $order->checkout_expires_at->timestamp,
            'locale' => $order->locale,
            'success_url' => $orderUrl.'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('shop.index'),
        ], 'checkout-'.$order->id);

        $order->update(['stripe_checkout_session_id' => $session['id']]);

        return $session['url'];
    }

    /** Un solo ordine aperto per persona: il precedente si chiude solo dopo aver chiuso (o letto) la sua sessione. */
    private function closePreviousPending(User $user): void
    {
        $previous = $user->orders()->where('status', OrderStatus::Pending)->first();
        if (! $previous) {
            return;
        }
        if ($previous->stripe_checkout_session_id) {
            $session = $this->stripe->expireCheckoutSession($previous->stripe_checkout_session_id);
            if ($session['status'] !== 'expired') {
                $this->payments->applySession($previous, $session);

                return;
            }
        }
        $this->payments->close($previous, OrderStatus::Cancelled, 'replaced_by_new_order', $previous->stripe_checkout_session_id ? $session : null);
    }

    /** La sessione Stripe dura almeno 30 minuti da quando nasce: se l'acquirente ha aspettato, si allunga la riserva. */
    private function extendIfNeeded(Order $order): void
    {
        $expiresAt = now()->addMinutes(self::SESSION_MINUTES);
        if ($order->checkout_expires_at->gte($expiresAt->copy()->subMinute())) {
            return;
        }
        if ($order->price_role === PriceRole::Member && ! $order->user->isMemberUntil($expiresAt)) {
            throw new ShopException('order_not_pending');
        }
        DB::transaction(function () use ($order, $expiresAt) {
            Order::whereKey($order->id)->lockForUpdate()->first();
            $order->update(['checkout_expires_at' => $expiresAt]);
            $order->holds()->where('status', 'active')->update(['expires_at' => $expiresAt->copy()->addMinutes(self::HOLD_EXTRA_MINUTES)]);
        });
    }

    /** @return Collection<string, TicketType> */
    private function sellableTypes(Event $event, array $ids): Collection
    {
        $types = $event->ticketTypes()
            ->with('pools', 'programSession')
            ->whereIn('id', $ids)
            ->where('active', true)
            ->where('channel', TicketChannel::Public)
            ->where(fn ($q) => $q->whereNull('sales_start')->orWhere('sales_start', '<=', now()))
            ->where(fn ($q) => $q->whereNull('sales_end')->orWhere('sales_end', '>', now()))
            ->get()->keyBy('id');

        $salesOpen = $event->status === EventStatus::Published
            && (! $event->sales_open_at || $event->sales_open_at->lte(now()))
            && (! $event->sales_close_at || $event->sales_close_at->gt(now()));

        if (! $salesOpen || $ids === [] || count(array_diff($ids, $types->keys()->all())) > 0) {
            throw new ShopException('not_available');
        }

        return $types;
    }

    /** Limiti per tipo e per pool, workshop solo con pass intero, al massimo 2 e mai due alla stessa ora. */
    private function checkLimits(User $user, Event $event, array $lines, Collection $types): void
    {
        $perType = [];
        $perPool = [];
        foreach ($lines as $line) {
            $type = $types[$line['ticket_type_id']];
            $n = count($line['attendees']);
            $perType[$type->id] = ($perType[$type->id] ?? 0) + $n;
            foreach ($type->pools as $pool) {
                $perPool[$pool->id] = ($perPool[$pool->id] ?? 0) + $n;
                if ($pool->max_per_order && $perPool[$pool->id] > $pool->max_per_order) {
                    throw new ShopException('too_many_for_pool');
                }
            }
        }
        foreach ($perType as $id => $n) {
            if ($n > $types[$id]->max_per_order) {
                throw new ShopException('too_many_for_type');
            }
        }

        $workshops = $types->filter(fn ($t) => $t->kind === TicketKind::PaidSession);
        if ($workshops->isEmpty()) {
            return;
        }
        if ($workshops->sum(fn ($t) => $perType[$t->id]) > self::MAX_WORKSHOPS_PER_ORDER) {
            throw new ShopException('too_many_workshops');
        }

        $passInOrder = $types->contains(fn ($t) => $t->kind === TicketKind::FullPass);
        $passOwned = Ticket::where('event_id', $event->id)->where('status', TicketStatus::Valid)
            ->whereRelation('ticketType', 'kind', TicketKind::FullPass)
            ->where(fn ($q) => $q->where('attendee_user_id', $user->id)->orWhereRelation('order', 'user_id', $user->id))
            ->exists();
        if (! $passInOrder && ! $passOwned) {
            throw new ShopException('workshop_needs_pass');
        }

        $sessions = $workshops->map->programSession->filter(fn ($s) => $s->starts_at && $s->ends_at)->values();
        foreach ($sessions as $i => $a) {
            foreach ($sessions->slice($i + 1) as $b) {
                if ($a->starts_at->lt($b->ends_at) && $b->starts_at->lt($a->ends_at)) {
                    throw new ShopException('workshop_overlap');
                }
            }
        }
    }

    /**
     * Righe d'ordine di una riga del carrello. Prezzo membro solo sul biglietto intestato al membro che compra e una
     * volta per evento e tipo; su quella riga vale il migliore tra prezzo membro e prezzo pubblico scontato, mai entrambi
     * (salvo codici combinable_with_role_price). Gli altri biglietti: prezzo pubblico, con il codice se vale.
     */
    private function priceLine(User $user, PriceRole $role, ?PriceTier $tier, TicketType $type, array $attendees, ?DiscountCode $code, string $locale): array
    {
        $public = $this->prices->resolve($type, PriceRole::Public, $tier) ?? throw new ShopException('not_available');
        $publicDiscount = $code ? $this->prices->unitDiscount($code, $type, $public->amount_cents) : 0;
        $name = $type->name[$locale] ?? $type->name['en'];
        $rows = [];

        $mine = collect($attendees)->search(fn ($a) => mb_strtolower(trim($a['email'])) === $user->email);
        $member = $role === PriceRole::Member && $mine !== false ? $this->prices->resolve($type, PriceRole::Member, $tier) : null;
        if ($member?->price_role === PriceRole::Member && ! $this->hasActiveClaim($user, $type)) {
            $memberDiscount = $code?->combinable_with_role_price ? $this->prices->unitDiscount($code, $type, $member->amount_cents) : 0;
            if ($member->amount_cents - $memberDiscount <= $public->amount_cents - $publicDiscount) {
                $rows[] = $this->row($type, $member, $memberDiscount, [$attendees[$mine]], $name);
                array_splice($attendees, $mine, 1);
            }
        }

        if ($attendees !== []) {
            $rows[] = $this->row($type, $public, $publicDiscount, array_values($attendees), $name);
        }

        return $rows;
    }

    private function row(TicketType $type, $price, int $unitDiscount, array $attendees, string $name): array
    {
        $unit = $price->amount_cents - $unitDiscount;

        return [
            'type' => $type, 'ticket_type_id' => $type->id, 'ticket_price_id' => $price->id, 'price_role' => $price->price_role,
            'quantity' => count($attendees), 'list_unit_amount_cents' => $price->amount_cents,
            'unit_discount_cents' => $unitDiscount, 'unit_amount_cents' => $unit, 'total_cents' => $unit * count($attendees),
            'stripe_line_name' => mb_substr($name, 0, 250),
            'attendees' => array_map(fn ($a) => ['name' => trim($a['name']), 'email' => mb_strtolower(trim($a['email']))], $attendees),
        ];
    }

    private function hasActiveClaim(User $user, TicketType $type): bool
    {
        return MemberPriceClaim::where('user_id', $user->id)->where('event_id', $type->event_id)
            ->where('kind', $type->kind)->where('event_day_id', $type->event_day_id)
            ->where('program_session_id', $type->program_session_id)->whereNull('released_at')->exists();
    }

    private function findDiscount(string $input, Event $event, User $user, PriceRole $role): DiscountCode
    {
        $code = DiscountCode::with('ticketTypes')->where('code', mb_strtoupper(trim($input)))->lockForUpdate()->first();

        $valid = $code && $code->active
            && ($code->event_id === null || $code->event_id === $event->id)
            && (! $code->valid_from || $code->valid_from->lte(now()))
            && (! $code->valid_to || $code->valid_to->gt(now()))
            && ($code->eligible_roles === null || in_array($role->value, $code->eligible_roles, true));
        if (! $valid) {
            throw new ShopException('discount_invalid');
        }
        if ($code->max_redemptions !== null && $code->redeemed_count >= $code->max_redemptions) {
            throw new ShopException('discount_exhausted');
        }
        if ($code->per_user_limit !== null
            && $code->redemptions()->where('user_id', $user->id)->whereNull('released_at')->count() >= $code->per_user_limit) {
            throw new ShopException('discount_user_limit');
        }

        return $code;
    }

    /** Utilizzo contato alla riserva con UPDATE condizionale e restituito se l'ordine non si paga. */
    private function redeem(DiscountCode $code, Order $order, User $user): void
    {
        $taken = DiscountCode::whereKey($code->id)
            ->where(fn ($q) => $q->whereNull('max_redemptions')->orWhereColumn('redeemed_count', '<', 'max_redemptions'))
            ->increment('redeemed_count');
        if ($taken === 0) {
            throw new ShopException('discount_exhausted');
        }
        $code->redemptions()->create(['order_id' => $order->id, 'user_id' => $user->id]);
    }
}
