<?php

// Regole che in docs/tecnico/regole.md erano «senza test» (backend). Ogni prova cita il vincolo o il Service.

use App\Database\CreatesTriggers;
use App\Enums\EventStatus;
use App\Enums\PriceRole;
use App\Enums\StaffRole;
use App\Jobs\GenerateCsvExport;
use App\Jobs\ProcessStripeEvent;
use App\Jobs\RefundOrderFully;
use App\Jobs\SendTicketsEmail;
use App\Mail\StaffAlert;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\LoginCodeService;
use App\Services\ShopException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('non allunga la riserva a prezzo membro se intanto la membership finisce', function () {
    $user = member();
    $order = reserve($user, [line(passType(), $user)]);
    expect($order->price_role)->toBe(PriceRole::Member);

    $user->memberships()->update(['current_period_end' => now()->addMinutes(CheckoutService::SESSION_MINUTES)]);
    $this->travel(2)->minutes();   // la sessione Stripe ora va allungata oltre la fine della membership

    expect(fn () => app(CheckoutService::class)->startCheckout($order))->toThrow(ShopException::class, 'order_not_pending');
});

it('order_items_amounts_valid rifiuta importi incoerenti', function (array $amounts) {
    expect(fn () => OrderItem::factory()->for(Order::factory())->create($amounts))
        ->toThrow(QueryException::class, 'order_items_amounts_valid');
})->with([
    'totale sbagliato' => [['total_cents' => 1]],
    'sconto oltre il listino' => [['list_unit_amount_cents' => 100, 'unit_discount_cents' => 200, 'unit_amount_cents' => -100]],
    'quantità oltre 50' => [['quantity' => 51]],
]);

it('un codice solo per il pubblico non vale per un membro', function () {
    DiscountCode::factory()->create(['code' => 'PUBLIC10', 'eligible_roles' => ['public']]);
    $member = member();
    $public = User::factory()->create();

    expect(fn () => reserve($member, [line(passType(), $member)], 'PUBLIC10'))->toThrow(ShopException::class, 'discount_invalid')
        ->and(reserve($public, [line(passType(), $public)], 'PUBLIC10')->discount_cents)->toBeGreaterThan(0);
});

it('un solo utilizzo di codice per ordine (discount_code_redemptions_order_id_unique)', function () {
    $order = Order::factory()->create();
    $row = fn () => DB::table('discount_code_redemptions')->insert([
        'id' => (string) Str::uuid(), 'discount_code_id' => DiscountCode::factory()->create()->id,
        'order_id' => $order->id, 'user_id' => $order->user_id,
    ]);
    $row();

    expect($row)->toThrow(QueryException::class, 'discount_code_redemptions_order_id_unique');
});

it('non vende se l\'evento non è pubblicato o le vendite sono chiuse', function (array $change) {
    $event = eas8();
    $type = passType();
    $user = User::factory()->create();
    $event->update($change);

    expect(fn () => app(CheckoutService::class)->reserve($user, $event->refresh(), [line($type, $user)]))
        ->toThrow(ShopException::class, 'not_available');
})->with([
    'bozza' => [['status' => EventStatus::Draft]],
    'vendite chiuse' => [fn () => ['sales_open_at' => now()->subDays(2), 'sales_close_at' => now()->subDay()]],
    'vendite non ancora aperte' => [fn () => ['sales_open_at' => now()->addDay(), 'sales_close_at' => null]],
]);

it('i CHECK rifiutano date al contrario su eventi e tipi di biglietto', function (Closure $write, string $constraint) {
    expect($write)->toThrow(QueryException::class, $constraint);
})->with([
    'evento' => [fn () => Event::factory()->create(['starts_on' => '2027-04-10', 'ends_on' => '2027-04-08']), 'events_dates_valid'],
    'vendite evento' => [fn () => Event::factory()->create(['sales_open_at' => '2027-01-02', 'sales_close_at' => '2027-01-01']), 'events_sales_window'],
    'vendite tipo' => [fn () => passType()->update(['sales_start' => '2027-01-02', 'sales_end' => '2027-01-01']), 'ticket_types_sales_window'],
]);

it('i CHECK di refunds rifiutano importo, origine e causa non validi', function (array $row, string $constraint) {
    $order = Order::factory()->create();

    expect(fn () => DB::table('refunds')->insert([
        'id' => (string) Str::uuid(), 'order_id' => $order->id, 'amount_cents' => 100, 'currency' => 'eur', 'source' => 'staff', 'cause' => 'other', ...$row,
    ]))->toThrow(QueryException::class, $constraint);
})->with([
    'importo zero' => [['amount_cents' => 0], 'refunds_amount_positive'],
    'origine sconosciuta' => [['source' => 'web'], 'refunds_source_valid'],
    'staff senza causa' => [['cause' => null], 'refunds_cause_required'],
]);

it('avvisa lo staff dopo l\'ultimo tentativo di ogni job', function (Closure $job, string $title) {
    Mail::fake();
    config(['eventals.alerts_email' => 'alerts@example.test']);

    $job()->failed(new RuntimeException('down'));

    Mail::assertQueued(StaffAlert::class, fn (StaffAlert $mail) => $mail->title === $title);
})->with([
    'webhook' => [fn () => new ProcessStripeEvent('evt_x'), 'Evento Stripe non elaborato'],
    'rimborso' => [fn () => new RefundOrderFully('order-x', 'no_capacity'), 'Rimborso automatico fallito'],
    'email biglietti' => [fn () => new SendTicketsEmail('a@example.test', [], 'tickets-x'), 'Email dei biglietti non inviata'],
    'export' => [fn () => new GenerateCsvExport('orders', 1, 'orders.csv'), 'Export CSV non generato'],
]);

it('limita per IP le richieste di codice: 10 al minuto sulla rotta, 20 l\'ora nel Service', function () {
    Mail::fake();
    foreach (range(1, 10) as $i) {
        $this->post('/login', ['email' => "u$i@example.test"])->assertRedirect('/login/code');
    }
    $this->post('/login', ['email' => 'u11@example.test'])->assertTooManyRequests();

    $codes = app(LoginCodeService::class);
    foreach (range(1, 10) as $i) {
        $codes->send("v$i@example.test", '127.0.0.1');   // 10 dalla rotta + 10 qui = 20
    }
    expect(fn () => $codes->send('v11@example.test', '127.0.0.1'))->toThrow(ValidationException::class);
});

it('limita per IP le verifiche del codice: 30 l\'ora', function () {
    $codes = app(LoginCodeService::class);
    foreach (range(1, 30) as $i) {
        try {
            $codes->verify("w$i@example.test", '000000', '198.51.100.7');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('code');   // codice sbagliato, non ancora il limite
        }
    }

    try {
        $codes->verify('w31@example.test', '000000', '198.51.100.7');
        $this->fail('Limite per IP non applicato');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('email');
    }
});

it('manda alla configurazione della MFA lo staff che non l\'ha ancora attivata', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $staff = User::factory()->create();
    $staff->assignRole(StaffRole::Admin->value);

    $this->actingAs($staff)->get('/admin')->assertRedirectContains('multi-factor-authentication');
    $this->actingAs(User::factory()->staff()->create())->get('/admin')->assertOk();
});

it('con DB_TRIGGERS_OPTIONAL salta solo l\'errore 1419, con un avviso nel log', function () {
    if (DB::connection()->getDriverName() !== 'mariadb') {
        $this->markTestSkipped('SIGNAL fuori da una procedura: solo MariaDB.');
    }
    $migration = new class
    {
        use CreatesTriggers;

        public function run(string $sql): void
        {
            $this->trigger($sql);
        }
    };
    $fake1419 = "SIGNAL SQLSTATE 'HY000' SET MYSQL_ERRNO = 1419, MESSAGE_TEXT = 'You do not have the SUPER privilege'";
    Log::spy();

    config(['database.triggers_optional' => false]);
    expect(fn () => $migration->run($fake1419))->toThrow(QueryException::class);

    config(['database.triggers_optional' => true]);
    $migration->run($fake1419);
    Log::shouldHaveReceived('warning')->once();
    expect(fn () => $migration->run("SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'other'"))->toThrow(QueryException::class);
});
