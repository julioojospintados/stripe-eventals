<?php

namespace App\Services;

use App\Enums\CompReason;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\PriceRole;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketsEmail;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Omaggi per speaker, staff e sponsor (docs/architettura.md §7.4): ordine a 0 € che nasce pagato, posti presi
 * direttamente su confirmed (contano nella capienza), biglietto per email, tutto nel registro audit.
 */
class ComplimentaryService
{
    public function __construct(private CapacityService $capacity, private TicketService $tickets) {}

    public function issue(User $staff, TicketType $type, string $name, string $email, CompReason $reason, ?string $note = null): Order
    {
        return DB::transaction(function () use ($staff, $type, $name, $email, $reason, $note) {
            $attendee = ['name' => trim($name), 'email' => mb_strtolower(trim($email))];
            // L'ordine ha sempre una persona: il beneficiario, con un account creato senza password se manca.
            $user = User::firstOrCreate(['email' => $attendee['email']], ['name' => $attendee['name']]);

            $order = Order::create([
                'event_id' => $type->event_id, 'user_id' => $user->id, 'buyer_email' => $user->email,
                'status' => OrderStatus::Paid, 'origin' => OrderOrigin::Complimentary, 'price_role' => PriceRole::Public,
                'currency' => $type->event->currency, 'subtotal_cents' => 0, 'total_cents' => 0,
                'issued_by' => $staff->id, 'comp_reason' => $reason, 'paid_at' => now(), 'livemode' => false,
                'locale' => $user->locale ?? 'en',
            ]);
            $order->items()->create([
                'ticket_type_id' => $type->id, 'quantity' => 1, 'price_role' => PriceRole::Public,
                'list_unit_amount_cents' => 0, 'unit_amount_cents' => 0, 'total_cents' => 0,
                'stripe_line_name' => $type->name['en'], 'attendees' => [$attendee],
            ]);

            $this->capacity->confirmDirect($order, $type->pools->mapWithKeys(fn ($pool) => [$pool->id => 1])->all());
            $this->tickets->issue($order);
            SendTicketsEmail::dispatchForOrder($order);
            AuditLog::record('order.complimentary_issued', $order, $staff, [
                'ticket_type' => $type->id, 'attendee' => $attendee, 'reason' => $reason->value, 'note' => $note,
            ]);

            return $order;
        }, 3);
    }

    /** Revoca: ordine cancelled, biglietti annullati, posti di nuovo liberi. */
    public function revoke(Order $order, User $staff, string $note): void
    {
        DB::transaction(function () use ($order, $staff, $note) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if ($order->origin !== OrderOrigin::Complimentary || $order->status !== OrderStatus::Paid) {
                throw new ShopException('order_not_revocable');
            }

            $this->capacity->releaseConfirmed($order, 'comp_revoked');
            $order->tickets()->where('status', TicketStatus::Valid)
                ->update(['status' => TicketStatus::Cancelled, 'cancelled_at' => now()]);
            $order->update(['status' => OrderStatus::Cancelled]);
            AuditLog::record('order.complimentary_revoked', $order, $staff, ['note' => $note]);
        }, 3);
    }
}
