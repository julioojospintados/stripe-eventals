<?php

use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('mostra la pagina di accesso del pannello', function () {
    $this->get('/admin/login')->assertOk();
});

it('non apre il pannello a chi non è dello staff', function () {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});

it('apre il pannello ai tre ruoli dello staff', function (StaffRole $role) {
    $user = User::factory()->staff($role)->create();

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
})->with(StaffRole::cases());

it('dà i codici sconto a commerciale e organizzatore, i prezzi solo all\'organizzatore', function () {
    $commerciale = User::factory()->staff(StaffRole::Commerciale)->create();
    $organizzatore = User::factory()->staff(StaffRole::Organizzatore)->create();

    expect($commerciale->can('discount_codes.create'))->toBeTrue()
        ->and($organizzatore->can('discount_codes.create'))->toBeTrue()
        ->and($commerciale->can('ticket_prices.update'))->toBeFalse()
        ->and($organizzatore->can('ticket_prices.update'))->toBeTrue()
        ->and($organizzatore->can('orders.refund'))->toBeFalse()
        ->and($commerciale->can('tickets.correct_attendee'))->toBeFalse()
        ->and(User::factory()->staff()->create()->can('tickets.correct_attendee'))->toBeTrue();
});

it('crea un admin con eas:create-admin', function () {
    $this->artisan('eas:create-admin', ['email' => 'Giulia@Example.test', '--name' => 'Giulia'])
        ->expectsQuestion('Password (almeno 12 caratteri)', 'una-password-lunga')
        ->expectsQuestion('Ripeti la password', 'una-password-lunga')
        ->assertSuccessful();

    $user = User::where('email', 'giulia@example.test')->sole();
    expect($user->hasRole('admin'))->toBeTrue()
        ->and(password_get_info($user->password)['algoName'])->toBe('argon2id')
        ->and(AuditLog::where('action', 'staff.created')->count())->toBe(1);
});

it('rifiuta una password corta o un ruolo sconosciuto', function () {
    $this->artisan('eas:create-admin', ['email' => 'a@example.test'])
        ->expectsQuestion('Password (almeno 12 caratteri)', 'corta')
        ->expectsQuestion('Ripeti la password', 'corta')
        ->assertFailed();
    $this->artisan('eas:create-admin', ['email' => 'a@example.test', '--role' => 'membro'])->assertFailed();

    expect(User::count())->toBe(0);
});
