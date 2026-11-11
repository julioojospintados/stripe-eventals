<?php

// Fatture di Stripe: invoice_creation sul Checkout dei biglietti, link sull'ordine e sulla membership.

use App\Enums\StaffRole;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\Membership;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Livewire\Livewire;

beforeEach(function () {
    eas8();
    $this->user = User::factory()->create();
});

function checkoutParams(): array
{
    return test()->stripe->callsTo('createCheckoutSession')[0][1];
}

/** Ordine pagato con il webhook e fattura arrivata con invoice.paid. */
function invoicedOrder(User $user)
{
    $order = reserveAndCheckout($user, [line(passType(), $user)]);
    postWebhook(stripeEvent('checkout.session.completed', test()->stripe->pay($order->stripe_checkout_session_id)))->assertOk();
    $invoiceId = $order->refresh()->stripe_invoice_id;
    postWebhook(stripeEvent('invoice.paid', ['id' => $invoiceId, 'object' => 'invoice', 'parent' => null,
        'metadata' => ['order_id' => $order->id], 'hosted_invoice_url' => "https://invoice.stripe.test/$invoiceId"]))->assertOk();

    return $order->refresh();
}

it('chiede a Stripe la fattura, l\'indirizzo di fatturazione e la partita IVA facoltativa', function () {
    config(['services.stripe.invoice_footer' => 'EAS8 · Lisbon']);
    $order = reserveAndCheckout($this->user, [line(passType(), $this->user)]);

    expect(checkoutParams()['invoice_creation'])->toBe(['enabled' => true, 'invoice_data' => [
        'footer' => 'EAS8 · Lisbon', 'metadata' => ['order_id' => $order->id],
    ]])
        ->and(checkoutParams()['billing_address_collection'])->toBe('required')
        ->and(checkoutParams()['tax_id_collection'])->toBe(['enabled' => true])
        ->and(checkoutParams())->not->toHaveKey('customer_update');
});

it('con un Customer Stripe già noto aggiorna nome e indirizzo (richiesto per la partita IVA)', function () {
    $this->user->forceFill(['stripe_customer_id' => 'cus_test_known'])->save();
    reserveAndCheckout($this->user, [line(passType(), $this->user)]);

    expect(checkoutParams()['customer'])->toBe('cus_test_known')
        ->and(checkoutParams()['customer_update'])->toBe(['name' => 'auto', 'address' => 'auto']);
});

it('con l\'interruttore spento non manda nessun parametro di fattura', function () {
    config(['services.stripe.invoices' => false]);
    reserveAndCheckout($this->user, [line(passType(), $this->user)]);

    expect(checkoutParams())->not->toHaveKeys(['invoice_creation', 'billing_address_collection', 'tax_id_collection', 'customer_update']);
});

it('salva id e link della fattura; il link «Invoice» lo vede solo chi ha fatto l\'ordine', function () {
    $order = invoicedOrder($this->user);

    expect($order->stripe_invoice_id)->toStartWith('in_test_')
        ->and($order->stripe_invoice_url)->toBe("https://invoice.stripe.test/{$order->stripe_invoice_id}");
    $this->actingAs($this->user)->get(route('account.orders.show', $order))->assertSee($order->stripe_invoice_url)->assertSee('Invoice');
    $this->actingAs(User::factory()->create())->get(route('account.orders.show', $order))->assertNotFound();
});

it('nel pannello il link della fattura lo vede solo lo staff che vede gli importi', function (StaffRole $role, bool $sees) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $order = invoicedOrder($this->user);
    $this->actingAs(User::factory()->staff($role)->create());

    $page = Livewire::test(ManageOrders::class)->mountAction(TestAction::make(ViewAction::class)->table($order));
    $sees ? $page->assertMountedActionModalSeeHtml($order->stripe_invoice_url) : $page->assertMountedActionModalDontSeeHtml($order->stripe_invoice_url);
})->with([
    'commerciale' => [StaffRole::Commerciale, true],
    'organizzatore' => [StaffRole::Organizzatore, false],
]);

it('invoice.paid della membership mostra il link all\'ultima fattura nell\'area personale', function () {
    $this->actingAs($this->user)->post('/membership');
    $session = $this->stripe->pay(array_key_last($this->stripe->sessions));
    postWebhook(stripeEvent('checkout.session.completed', $session))->assertOk();
    $this->actingAs($this->user)->get('/account')->assertDontSee('Latest invoice');

    $event = stripeEvent('invoice.paid', ['id' => 'in_sub_1', 'hosted_invoice_url' => 'https://invoice.stripe.test/in_sub_1',
        'parent' => ['subscription_details' => ['subscription' => $session['subscription']]]]);
    $event['created'] = now()->addMinute()->timestamp;
    postWebhook($event)->assertOk();

    expect(Membership::sole()->latest_invoice_url)->toBe('https://invoice.stripe.test/in_sub_1');
    $this->get('/account')->assertSee('https://invoice.stripe.test/in_sub_1')->assertSee('Latest invoice');
});
