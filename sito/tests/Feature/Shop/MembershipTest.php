<?php

// Membership con Stripe Billing: attiva subito, poi aggiornata dagli eventi Stripe (§6.5).

use App\Enums\MembershipStatus;
use App\Enums\PriceRole;
use App\Models\Membership;
use App\Models\User;

beforeEach(function () {
    eas8();
    $this->user = User::factory()->create();
});

/** Il cliente compra la membership: Checkout in abbonamento e pagamento sulla pagina Stripe. */
function buyMembership(User $user): array
{
    test()->actingAs($user)->post('/membership')->assertRedirectContains('checkout.stripe.test');
    $id = array_key_last(test()->stripe->sessions);

    return test()->stripe->pay($id);
}

it('apre il Checkout in abbonamento con il prezzo annuale da config e solo carta', function () {
    buyMembership($this->user);
    [, $params] = $this->stripe->callsTo('createCheckoutSession')[0];

    expect($params['mode'])->toBe('subscription')
        ->and($params['line_items'])->toBe([['price' => 'price_test_membership', 'quantity' => 1]])
        ->and($params['payment_method_types'])->toBe(['card'])
        ->and($params['client_reference_id'])->toBe((string) $this->user->id)
        ->and($params['subscription_data']['metadata']['user_id'])->toBe((string) $this->user->id);
});

it('attiva la membership al ritorno da Stripe, prima del webhook: subito il prezzo membro', function () {
    $session = buyMembership($this->user);

    $this->get('/membership/success?session_id='.$session['id'])->assertRedirect('/account');

    expect($this->user->refresh()->isMemberUntil(now()->addMonths(11)))->toBeTrue()
        ->and($this->user->stripe_customer_id)->toBe($session['customer'])
        ->and(reserve($this->user, [line(passType(), $this->user)])->items->sole()->unit_amount_cents)->toBe(27650);
});

it('non attiva la membership di un altro utente dalla pagina di successo', function () {
    $session = buyMembership(User::factory()->create());

    $this->actingAs($this->user)->get('/membership/success?session_id='.$session['id'])->assertNotFound();
});

it('il webhook dopo la pagina di successo non crea una seconda membership', function () {
    $session = buyMembership($this->user);
    $this->get('/membership/success?session_id='.$session['id']);

    postWebhook(stripeEvent('checkout.session.completed', $session))->assertOk();

    expect(Membership::count())->toBe(1)->and(Membership::sole()->status)->toBe(MembershipStatus::Active);
});

it('customer.subscription.deleted la chiude; un evento più vecchio non la riapre', function () {
    $session = buyMembership($this->user);
    postWebhook(stripeEvent('checkout.session.completed', $session))->assertOk();
    $sub = $session['subscription'];

    $this->stripe->subscriptions[$sub]['status'] = 'canceled';
    $deleted = stripeEvent('customer.subscription.deleted', $this->stripe->subscriptions[$sub]);
    $deleted['created'] = now()->addMinute()->timestamp;
    postWebhook($deleted)->assertOk();

    $this->stripe->subscriptions[$sub]['status'] = 'active';
    $old = stripeEvent('customer.subscription.updated', $this->stripe->subscriptions[$sub]);
    $old['created'] = now()->subMinute()->timestamp;
    postWebhook($old)->assertOk();

    expect(Membership::sole()->status)->toBe(MembershipStatus::Canceled)
        ->and($this->user->isMemberUntil(now()))->toBeFalse()
        ->and(reserve($this->user, [line(passType(), $this->user)])->price_role)->toBe(PriceRole::Public);
});

it('invoice.paid aggiorna il periodo (subscription in parent, API recenti)', function () {
    $session = buyMembership($this->user);
    postWebhook(stripeEvent('checkout.session.completed', $session))->assertOk();
    $sub = $session['subscription'];
    $this->stripe->subscriptions[$sub]['items']['data'][0]['current_period_end'] = now()->addYears(2)->timestamp;

    $event = stripeEvent('invoice.paid', ['id' => 'in_1', 'parent' => ['subscription_details' => ['subscription' => $sub]]]);
    $event['created'] = now()->addMinute()->timestamp;
    postWebhook($event)->assertOk();

    expect(Membership::sole()->current_period_end->year)->toBe(now()->addYears(2)->year);
});

it('chi è già membro non riapre il Checkout; il portale è disponibile', function () {
    $session = buyMembership($this->user);
    $this->get('/membership/success?session_id='.$session['id']);

    $this->actingAs($this->user->refresh())->post('/membership')->assertSessionHasErrors('shop');
    $this->post('/membership/portal')->assertRedirect('https://billing.stripe.test/'.$session['customer']);
});

it('senza cliente Stripe non c\'è portale', function () {
    $this->actingAs($this->user)->post('/membership/portal')->assertNotFound();
});
