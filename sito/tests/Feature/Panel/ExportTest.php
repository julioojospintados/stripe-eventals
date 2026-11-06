<?php

// Export CSV: colonne concordate (architettura §11.2), solo biglietti validi, coda e file privato per quelli grandi.

use App\Enums\StaffRole;
use App\Enums\TicketStatus;
use App\Filament\Pages\Exports;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\User;
use App\Services\CsvExports;
use App\Services\PaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $buyer = User::factory()->create(['name' => '=HYPERLINK("x")']);
    $friend = User::factory()->create(['name' => 'Rui Costa']);
    $order = reserveAndCheckout($buyer, [line(passType(), $buyer, $friend), line(workshop(1), $buyer)]);
    app(PaymentService::class)->markPaid($order, $this->stripe->pay($order->stripe_checkout_session_id));
    $this->tickets = $order->tickets()->get();
    $this->tickets->firstWhere('attendee_name', 'Rui Costa')->update(['status' => TicketStatus::Cancelled, 'cancelled_at' => now()]);
});

function csvRows(string $type): array
{
    $handle = fopen('php://memory', 'w+');
    app(CsvExports::class)->write($type, $handle);
    rewind($handle);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }

    return $rows;
}

it('esporta per il check-in solo i biglietti validi, con le colonne concordate', function () {
    $rows = csvRows('tickets');

    expect($rows[0])->toBe(['ticket_id', 'qr_version', 'short_code', 'attendee_name', 'ticket_type', 'ticket_name', 'days', 'status'])
        ->and($rows)->toHaveCount(3)   // pass e workshop del compratore, non il pass annullato dell'amico
        ->and(array_column(array_slice($rows, 1), 7))->each->toBe('valid')
        ->and(array_column(array_slice($rows, 1), 3))->not->toContain('Rui Costa');

    $pass = collect($rows)->firstWhere(4, 'full_pass');
    expect($pass[6])->toBe('2027-04-08;2027-04-09;2027-04-10')
        ->and($pass[3])->toBe("'=HYPERLINK(\"x\")");   // niente formule nei fogli di calcolo
});

it('esporta ordini e consensi con le loro colonne', function () {
    Consent::create(['email' => 'a@example.test', 'granted' => true, 'source' => 'checkout', 'occurred_at' => now()->subDay(), 'text_version' => 'v1']);
    Consent::create(['email' => 'a@example.test', 'granted' => false, 'source' => 'account', 'occurred_at' => now(), 'text_version' => 'v1']);
    Consent::create(['email' => 'b@example.test', 'granted' => true, 'source' => 'checkout', 'occurred_at' => now(), 'text_version' => 'v1']);

    $orders = csvRows('orders');
    expect($orders[0])->toBe(CsvExports::HEADERS['orders'])
        ->and($orders[1][11])->toBe('1490.00');

    $consents = csvRows('consents');
    expect($consents[0])->toBe(CsvExports::HEADERS['consents'])
        ->and(array_column(array_slice($consents, 1), 0))->toBe(['b@example.test']);   // a@ ha revocato
});

it('scarica subito un export piccolo e lo scrive nel registro audit', function () {
    $this->actingAs(User::factory()->staff(StaffRole::Organizzatore)->create());

    Livewire::test(Exports::class)
        ->callAction('export_tickets')
        ->assertFileDownloaded();

    expect(AuditLog::where('action', 'export.tickets')->sole()->data)->toMatchArray(['rows' => 2, 'mode' => 'download']);
});

it('prepara in coda un export grande, scaricabile solo da chi l\'ha chiesto', function () {
    Storage::fake('local');
    CsvExports::$syncLimit = 0;
    $staff = User::factory()->staff(StaffRole::Commerciale)->create();
    $this->actingAs($staff);

    try {
        Livewire::test(Exports::class)->callAction('export_orders')->assertNotified('Export queued');
    } finally {
        CsvExports::$syncLimit = 2000;
    }

    $file = basename(Storage::disk('local')->files(CsvExports::path($staff))[0]);
    $this->get(route('exports.download', $file))->assertOk()->assertDownload($file);
    Livewire::test(Exports::class)->assertSee($file);

    $this->actingAs(User::factory()->staff(StaffRole::Admin)->create())->get(route('exports.download', $file))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('exports.download', $file))->assertForbidden();
});

it('nasconde gli export che il ruolo non può fare', function () {
    $this->actingAs(User::factory()->staff(StaffRole::Organizzatore)->create());

    Livewire::test(Exports::class)
        ->assertActionVisible('export_tickets')
        ->assertActionHidden('export_orders')
        ->assertActionHidden('export_consents');
});
