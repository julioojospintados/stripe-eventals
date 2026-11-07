<?php

// Rimborsi rifiutati, importo diverso dal totale, contestazioni, riconciliazione, eventi ignorati con il motivo.

use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Enums\StripeEventStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\Disputes\Pages\ManageDisputes;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Jobs\ProcessStripeEvent;
use App\Jobs\RefundOrderFully;
use App\Mail\StaffAlert;
use App\Mail\TicketsMail;
use App\Models\AuditLog;
use App\Models\CapacityPool;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\StripeEvent;
use App\Models\User;
use App\Services\CsvExports;
use App\Services\PaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->staff(StaffRole::Admin)->create();   // destinatario degli avvisi
    $this->payments = app(PaymentService::class);
    Mail::fake();
});

function closuresPaid(): Order
{
    $buyer = User::factory()->create();
    $order = reserveAndCheckout($buyer, [line(passType(), $buyer)]);
    app(PaymentService::class)->markPaid($order, test()->stripe->pay($order->stripe_checkout_session_id));

    return $order->refresh();
}

/** Ordine needs_review: Stripe ha incassato 1 € in meno del totale. */
function closuresMismatch(): Order
{
    $buyer = User::factory()->create();
    $order = reserveAndCheckout($buyer, [line(passType(), $buyer)]);
    $session = test()->stripe->pay($order->stripe_checkout_session_id);
    $session['amount_total'] = $order->total_cents - 100;
    test()->stripe->sessions[$session['id']] = $session;
    app(PaymentService::class)->markPaid($order, $session);

    return $order->refresh();
}

function closuresWebhook(string $type, array $object): StripeEvent
{
    $event = stripeEvent($type, $object);
    postWebhook($event)->assertOk();

    return StripeEvent::findOrFail($event['id']);
}

function dispute(Order $order, string $status, string $id = 'du_test_1'): array
{
    return ['id' => $id, 'object' => 'dispute', 'charge' => 'ch_test_1', 'payment_intent' => $order->stripe_payment_intent_id,
        'amount' => $order->total_cents, 'currency' => 'eur', 'reason' => 'fraudulent', 'status' => $status,
        'evidence_details' => ['due_by' => now()->addDays(7)->timestamp]];
}

function alerted(string $title): void
{
    Mail::assertQueued(StaffAlert::class, fn ($m) => str_contains($m->title, $title));
}

// 1. Rimborso rifiutato

it('refund.failed: rimborso failed, ordine da rivedere, avviso, biglietti non riattivati', function () {
    $order = closuresPaid();
    RefundOrderFully::dispatch($order->id, 'other', $this->admin->id, 'test');
    $refund = $order->refunds()->sole();
    closuresWebhook('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => $order->stripe_payment_intent_id,
        'amount' => $order->total_cents, 'amount_refunded' => $order->total_cents, 'refunded' => true]);

    $event = closuresWebhook('refund.failed', ['id' => $refund->stripe_refund_id, 'object' => 'refund', 'status' => 'failed',
        'amount' => $refund->amount_cents, 'payment_intent' => $order->stripe_payment_intent_id, 'failure_reason' => 'expired_or_canceled_card']);

    $order->refresh();
    expect($event->status)->toBe(StripeEventStatus::Processed)
        ->and($refund->refresh()->only('status', 'needs_review'))->toBe(['status' => 'failed', 'needs_review' => true])
        ->and($order->review_reason)->toBe('refund_failed')
        ->and($order->status)->toBe(OrderStatus::Refunded)
        ->and($order->tickets()->where('status', TicketStatus::Valid)->count())->toBe(0);
    alerted('Rimborso rifiutato');
});

it('refund.updated canceled sul rimborso di un ordine pagato: niente cambia di stato, si può rifare il rimborso', function () {
    $order = closuresPaid();
    RefundOrderFully::dispatch($order->id, 'other', $this->admin->id, 'test');
    $refund = $order->refunds()->sole();

    closuresWebhook('refund.updated', ['id' => $refund->stripe_refund_id, 'object' => 'refund', 'status' => 'canceled',
        'amount' => $refund->amount_cents, 'payment_intent' => $order->stripe_payment_intent_id]);
    RefundOrderFully::dispatch($order->id, 'other', $this->admin->id, 'retry');

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->tickets()->sole()->status)->toBe(TicketStatus::Valid)
        ->and($this->stripe->callsTo('createRefund'))->toHaveCount(2)
        ->and($this->stripe->callsTo('createRefund')[1][2])->toBe("staff-refund-{$order->id}-retry-1");
});

it('rimborso che Stripe rifiuta subito: segnato failed con avviso', function () {
    $order = closuresPaid();
    $this->stripe->refundStatus = 'failed';

    RefundOrderFully::dispatch($order->id, 'other', $this->admin->id, 'test');

    expect($order->refunds()->sole()->status)->toBe('failed')
        ->and($order->refresh()->review_reason)->toBe('refund_failed');
    alerted('Rimborso rifiutato');
});

it('refund.updated succeeded non tocca nulla', function () {
    $order = closuresPaid();
    $event = closuresWebhook('refund.updated', ['id' => 're_x', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 100,
        'payment_intent' => $order->stripe_payment_intent_id]);

    expect($event->status)->toBe(StripeEventStatus::Processed)
        ->and($order->refunds()->count())->toBe(0);
});

// 2. Importo diverso dal totale

it('importo diverso: nessun biglietto, posti tenuti, avviso; lo staff emette i biglietti', function () {
    $order = closuresMismatch();
    expect($order->status)->toBe(OrderStatus::NeedsReview)
        ->and($order->tickets()->count())->toBe(0)
        ->and(CapacityPool::where('name', 'like', 'Day %')->pluck('confirmed')->all())->toBe([1, 1, 1]);
    alerted('Importo pagato diverso');

    $this->actingAs(User::factory()->staff(StaffRole::Commerciale)->create());
    Livewire::test(ManageOrders::class)
        ->callAction(TestAction::make('approveReview')->table($order), data: ['note' => 'Ok, differenza nota'])
        ->assertHasNoActionErrors();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->review_reason)->toBeNull()
        ->and($order->tickets()->sole()->status)->toBe(TicketStatus::Valid)
        ->and(AuditLog::where('action', 'order.review_approved')->count())->toBe(1);
    Mail::assertSent(TicketsMail::class);
});

it('importo diverso: lo staff rimborsa, posti liberi e rimborso di quanto pagato su Stripe', function () {
    $order = closuresMismatch();

    $this->actingAs($this->admin);
    Livewire::test(ManageOrders::class)
        ->callAction(TestAction::make('refundReview')->table($order), data: ['note' => 'Importo sbagliato']);

    $call = $this->stripe->callsTo('createRefund')[0];
    expect($order->refresh()->status)->toBe(OrderStatus::NeedsRefund)
        ->and($order->tickets()->count())->toBe(0)
        ->and(CapacityPool::sum('confirmed'))->toBe(0)
        ->and($call[1]['amount'])->toBe($order->total_cents - 100)
        ->and(AuditLog::where('action', 'order.review_refunded')->count())->toBe(1);

    closuresWebhook('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => $order->stripe_payment_intent_id,
        'amount' => $order->total_cents - 100, 'amount_refunded' => $order->total_cents - 100, 'refunded' => true]);
    expect($order->refresh()->status)->toBe(OrderStatus::Refunded);
});

it('le azioni sugli ordini da rivedere non compaiono all\'organizzatore', function () {
    $order = closuresMismatch();
    $this->actingAs(User::factory()->staff(StaffRole::Organizzatore)->create());

    Livewire::test(ManageOrders::class)
        ->assertActionHidden(TestAction::make('approveReview')->table($order))
        ->assertActionHidden(TestAction::make('refundReview')->table($order));
});

// 3. Contestazioni

it('contestazione aperta sospende i biglietti, vinta li riattiva', function () {
    $order = closuresPaid();

    closuresWebhook('charge.dispute.created', dispute($order, 'needs_response'));
    expect($order->tickets()->sole()->status)->toBe(TicketStatus::Disputed)
        ->and(Dispute::sole()->only('status', 'amount_cents'))->toBe(['status' => 'needs_response', 'amount_cents' => $order->total_cents]);
    alerted('Pagamento contestato');

    closuresWebhook('charge.dispute.updated', dispute($order, 'under_review'));
    closuresWebhook('charge.dispute.closed', dispute($order, 'won'));

    expect($order->tickets()->sole()->status)->toBe(TicketStatus::Valid)
        ->and(Dispute::sole()->status)->toBe('won')
        ->and(Dispute::sole()->closed_at)->not->toBeNull();
    alerted('Contestazione chiusa a favore');
});

it('contestazione persa revoca i biglietti e libera i posti; un evento arrivato dopo non riapre nulla', function () {
    $order = closuresPaid();
    closuresWebhook('charge.dispute.created', dispute($order, 'needs_response'));
    closuresWebhook('charge.dispute.closed', dispute($order, 'lost'));
    $late = closuresWebhook('charge.dispute.updated', dispute($order, 'under_review'));

    $ticket = $order->tickets()->sole();
    expect($ticket->status)->toBe(TicketStatus::Cancelled)
        ->and($ticket->cancelled_at)->not->toBeNull()
        ->and($order->refresh()->review_reason)->toBe('dispute_lost')
        ->and(CapacityPool::sum('confirmed'))->toBe(0)
        ->and(Dispute::sole()->status)->toBe('lost')
        ->and($late->status)->toBe(StripeEventStatus::Processed);
    alerted('Contestazione persa');
});

it('contestazione persa senza created (evento perso): biglietti valid revocati, posti liberati una volta sola', function () {
    $other = closuresPaid();   // un altro ordine tiene i suoi posti: il conteggio non deve scendere sotto
    $confirmedByOther = (int) CapacityPool::sum('confirmed');
    $order = closuresPaid();
    closuresWebhook('charge.dispute.closed', dispute($order, 'lost'));
    $again = closuresWebhook('charge.dispute.closed', dispute($order, 'lost'));

    expect($order->tickets()->sole()->status)->toBe(TicketStatus::Cancelled)
        ->and($other->tickets()->sole()->status)->toBe(TicketStatus::Valid)
        ->and((int) CapacityPool::sum('confirmed'))->toBe($confirmedByOther)
        ->and(Dispute::sole()->status)->toBe('lost')
        ->and($again->status)->toBe(StripeEventStatus::Processed);
    Mail::assertQueued(StaffAlert::class, fn ($m) => str_contains($m->title, 'Contestazione persa'));
    expect(Mail::queued(StaffAlert::class, fn ($m) => str_contains($m->title, 'Contestazione persa')))->toHaveCount(1);
});

it('contestazione updated o won senza created: updated sospende con avviso, won lascia i biglietti validi', function () {
    $order = closuresPaid();
    closuresWebhook('charge.dispute.updated', dispute($order, 'under_review'));
    expect($order->tickets()->sole()->status)->toBe(TicketStatus::Disputed);
    alerted('Pagamento contestato');

    $won = closuresPaid();
    closuresWebhook('charge.dispute.closed', dispute($won, 'won', 'du_test_2'));
    expect($won->tickets()->sole()->status)->toBe(TicketStatus::Valid)
        ->and(Dispute::where('stripe_dispute_id', 'du_test_2')->sole()->closed_at)->not->toBeNull();
});

it('i biglietti contestati non escono nell\'export del check-in e le contestazioni si vedono in sola lettura', function () {
    $order = closuresPaid();
    closuresWebhook('charge.dispute.created', dispute($order, 'needs_response'));

    expect(app(CsvExports::class)->count('tickets'))->toBe(0);

    $this->actingAs(User::factory()->staff(StaffRole::Commerciale)->create());
    $this->get('/admin/disputes')->assertOk();
    Livewire::test(ManageDisputes::class)->assertSee($order->buyer_email);
    $this->actingAs(User::factory()->staff(StaffRole::Organizzatore)->create());
    $this->get('/admin/disputes')->assertForbidden();
});

// 5. Eventi non riconosciuti

it('contestazione o rimborso fallito di un pagamento sconosciuto: ignorati con il motivo', function () {
    $dispute = closuresWebhook('charge.dispute.created', ['id' => 'du_x', 'payment_intent' => 'pi_nobody', 'status' => 'needs_response']);
    $refund = closuresWebhook('refund.failed', ['id' => 're_x', 'status' => 'failed', 'payment_intent' => 'pi_nobody', 'amount' => 100]);
    $other = closuresWebhook('payment_intent.created', ['id' => 'pi_x']);

    expect($dispute->only('status', 'ignored_reason'))->toEqual(['status' => StripeEventStatus::Ignored, 'ignored_reason' => 'unknown_order'])
        ->and($refund->only('status', 'ignored_reason'))->toEqual(['status' => StripeEventStatus::Ignored, 'ignored_reason' => 'unknown_order'])
        ->and($other->only('status', 'ignored_reason'))->toEqual(['status' => StripeEventStatus::Ignored, 'ignored_reason' => 'unhandled_type'])
        ->and(Dispute::count())->toBe(0);
});

// 4. Riconciliazione

it('riconciliazione senza differenze: rapporto nel registro audit, nessun avviso', function () {
    closuresPaid();

    $this->artisan('eas:reconcile')->expectsOutput('0 differenze.')->assertSuccessful();

    $log = AuditLog::where('action', 'stripe.reconciled')->sole();
    expect($log->data['differences'])->toBe([])
        ->and($log->data['paid_orders'])->toBe(1);
    Mail::assertNotQueued(StaffAlert::class);
});

it('riconciliazione: pagato su Stripe ma non qui → rielaborato dallo stesso job, una volta sola', function () {
    $buyer = User::factory()->create();
    $order = reserveAndCheckout($buyer, [line(passType(), $buyer)]);
    $this->stripe->pay($order->stripe_checkout_session_id);   // webhook perso

    $this->artisan('eas:reconcile')->assertSuccessful();
    $this->artisan('eas:reconcile')->assertSuccessful();

    $event = StripeEvent::sole();
    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->tickets)->toHaveCount(1)
        ->and($event->id)->toBe('evt_reconcile_'.$order->stripe_checkout_session_id)
        ->and($event->status)->toBe(StripeEventStatus::Processed)
        ->and(AuditLog::where('action', 'stripe.reconciled')->get()->flatMap(fn ($l) => $l->data['differences'])->pluck('type')->all())
        ->toBe(['paid_on_stripe_only']);
    alerted('Riconciliazione Stripe');
});

it('riconciliazione: ordine needs_review pagato su Stripe è già a posto, scaduto e pagato viene rielaborato', function () {
    $review = closuresMismatch();
    $buyer = User::factory()->create();
    $expired = reserveAndCheckout($buyer, [line(passType(), $buyer)]);
    $this->stripe->expireCheckoutSession($expired->stripe_checkout_session_id);
    $this->payments->close($expired, OrderStatus::Expired, 'checkout_expired', $this->stripe->retrieveCheckoutSession($expired->stripe_checkout_session_id));
    $this->stripe->sessions[$expired->stripe_checkout_session_id]['status'] = 'open';
    $this->stripe->pay($expired->stripe_checkout_session_id);   // pagato tardi, webhook perso

    $this->artisan('eas:reconcile')->assertSuccessful();

    $differences = AuditLog::where('action', 'stripe.reconciled')->sole()->data['differences'];
    // toEqual: MySQL riordina le chiavi delle colonne JSON, MariaDB no.
    expect($differences)->toEqual([['type' => 'paid_on_stripe_only', 'order' => $expired->id,
        'session' => $expired->stripe_checkout_session_id, 'status' => 'expired']])
        ->and(StripeEvent::sole()->status)->toBe(StripeEventStatus::Processed)
        ->and($expired->refresh()->status)->toBeIn(OrderStatus::moneyTaken())
        ->and($review->refresh()->status)->toBe(OrderStatus::NeedsReview);
});

it('riconciliazione: un evento evt_reconcile_ rimasto failed si rimette in coda, processed no', function () {
    Queue::fake([ProcessStripeEvent::class]);
    $buyer = User::factory()->create();
    $order = reserveAndCheckout($buyer, [line(passType(), $buyer)]);
    $this->stripe->pay($order->stripe_checkout_session_id);
    $id = 'evt_reconcile_'.$order->stripe_checkout_session_id;

    $this->artisan('eas:reconcile')->assertSuccessful();
    StripeEvent::whereKey($id)->update(['status' => StripeEventStatus::Failed, 'attempts' => 5]);
    $this->artisan('eas:reconcile')->assertSuccessful();
    StripeEvent::whereKey($id)->update(['status' => StripeEventStatus::Processed, 'processed_at' => now()]);
    $this->artisan('eas:reconcile')->assertSuccessful();

    Queue::assertPushed(ProcessStripeEvent::class, 2);
    Queue::assertPushed(ProcessStripeEvent::class, fn ($job) => $job->eventId === $id);
    expect(StripeEvent::count())->toBe(1);
});

it('riconciliazione: pagato qui ma non su Stripe e rimborso non registrato → segnalati', function () {
    Queue::fake([ProcessStripeEvent::class]);
    $paidHere = closuresPaid();
    $this->stripe->sessions[$paidHere->stripe_checkout_session_id]['payment_status'] = 'unpaid';
    $refunded = closuresPaid();
    $this->stripe->refunds['re_dash'] = ['id' => 're_dash', 'status' => 'succeeded', 'amount' => 5000,
        'payment_intent' => $refunded->stripe_payment_intent_id, 'created' => now()->timestamp];

    $this->artisan('eas:reconcile')->expectsOutput('2 differenze.')->assertSuccessful();

    $types = collect(AuditLog::where('action', 'stripe.reconciled')->sole()->data['differences'])->pluck('type', 'order')->all();
    expect($types)->toBe([$paidHere->id => 'paid_here_only', $refunded->id => 'refund_not_recorded']);
    Queue::assertNothingPushed();
    alerted('Riconciliazione Stripe');
});

it('la riconciliazione è nello scheduler e si avvia dal pannello in coda', function () {
    $events = collect(app(Schedule::class)->events())->map->command->implode(' ');
    expect($events)->toContain('eas:reconcile');

    Queue::fake();
    $this->actingAs(User::factory()->staff(StaffRole::Commerciale)->create());
    Livewire::test(ManageOrders::class)->callAction('reconcile');
    Queue::assertPushed(QueuedCommand::class);
    expect(AuditLog::where('action', 'stripe.reconcile_requested')->count())->toBe(1);

    $this->actingAs(User::factory()->staff(StaffRole::Organizzatore)->create());
    Livewire::test(ManageOrders::class)->assertActionHidden('reconcile');
});
