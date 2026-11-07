<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Membership annuale con Stripe Billing (docs/architettura.md §6.5): Checkout in modalità subscription, attiva subito
 * al checkout.session.completed (webhook o pagina di successo), poi aggiornata solo rileggendo la subscription.
 */
class MembershipService
{
    public function __construct(private StripeGateway $stripe) {}

    public function startCheckout(User $user): string
    {
        if ($user->isMemberUntil(now())) {
            throw new ShopException('already_member');
        }

        $session = $this->stripe->createCheckoutSession([
            'mode' => 'subscription',
            // Price ID dal pannello (Membership price); senza, quello del .env.
            'line_items' => [['price' => MembershipPlan::where('active', true)->value('stripe_price_id') ?: config('services.stripe.membership_price'), 'quantity' => 1]],
            'client_reference_id' => (string) $user->id,
            ...($user->stripe_customer_id ? ['customer' => $user->stripe_customer_id] : ['customer_email' => $user->email]),
            'payment_method_types' => ['card'],
            'subscription_data' => ['metadata' => ['user_id' => (string) $user->id]],
            'metadata' => ['kind' => 'membership', 'user_id' => (string) $user->id, 'env' => app()->environment()],
            'locale' => $user->locale,
            'success_url' => route('membership.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('account.index'),
        ], 'membership-'.$user->id.'-'.now()->format('YmdHi'));

        return $session['url'];
    }

    /** Idempotente: la chiamano il webhook e la pagina di successo. */
    public function activateFromCheckout(array $session): ?Membership
    {
        if ($session['mode'] !== 'subscription' || $session['payment_status'] !== 'paid' || empty($session['subscription'])) {
            return null;
        }
        $user = User::findOrFail($session['client_reference_id']);
        $user->stripe_customer_id ??= $session['customer'];
        $user->save();

        return $this->sync($session['subscription'], now());
    }

    /** customer.subscription.* e invoice.*: si rilegge la subscription; un evento più vecchio dell'ultimo non conta. */
    public function sync(string $subscriptionId, Carbon $eventAt): ?Membership
    {
        $sub = $this->stripe->retrieveSubscription($subscriptionId);
        $userId = $sub['metadata']['user_id'] ?? null;

        return DB::transaction(function () use ($sub, $userId, $eventAt) {
            $membership = Membership::where('stripe_subscription_id', $sub['id'])->lockForUpdate()->first();
            if (! $membership && ! $userId) {
                return null;
            }
            if ($membership?->last_stripe_event_at?->gt($eventAt)) {
                return $membership;
            }

            // Nelle versioni recenti dell'API le date del periodo stanno sulle voci della subscription.
            $item = $sub['items']['data'][0] ?? [];
            $start = $item['current_period_start'] ?? $sub['current_period_start'] ?? null;
            $end = $item['current_period_end'] ?? $sub['current_period_end'] ?? null;
            $date = fn ($ts) => $ts ? Carbon::createFromTimestamp($ts) : null;

            $membership ??= new Membership([
                'user_id' => $userId,
                'plan_id' => MembershipPlan::where('active', true)->value('id'),
                'stripe_subscription_id' => $sub['id'],
            ]);
            $membership->fill([
                'stripe_customer_id' => $sub['customer'],
                'status' => $sub['status'],
                'current_period_start' => $date($start),
                'current_period_end' => $date($end),
                'cancel_at_period_end' => (bool) ($sub['cancel_at_period_end'] ?? false),
                'canceled_at' => $date($sub['canceled_at'] ?? null),
                'ended_at' => $date($sub['ended_at'] ?? null),
                'last_stripe_event_at' => $eventAt,
            ]);
            if ($sub['status'] === 'active') {
                $membership->activated_at ??= now();
            }
            $membership->save();

            return $membership;
        });
    }

    public function portalUrl(User $user): ?string
    {
        return $user->stripe_customer_id
            ? $this->stripe->createPortalSession($user->stripe_customer_id, route('account.index'))['url']
            : null;
    }
}
