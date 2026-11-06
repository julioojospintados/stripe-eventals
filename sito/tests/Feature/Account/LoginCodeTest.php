<?php

// Accesso dei clienti con codice di 6 cifre via email, senza password (§5.1).

use App\Enums\StaffRole;
use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

/** Chiede il codice e lo legge dall'email finta. */
function requestCode(string $email): string
{
    test()->post('/login', ['email' => $email])->assertRedirect('/login/code');
    $code = null;
    Mail::assertSent(LoginCodeMail::class, function ($mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return $code;
}

it('manda un codice di 6 cifre e salva solo il suo hash, con scadenza a 10 minuti', function () {
    $code = requestCode('Ana@Example.test');
    $row = LoginCode::sole();

    expect($code)->toMatch('/^\d{6}$/')
        ->and($row->email)->toBe('ana@example.test')
        ->and($row->code_hash)->not->toContain($code)
        ->and($row->expires_at->diffInMinutes(now(), true))->toEqualWithDelta(10, 0.1);
});

it('con il codice giusto crea l\'account e apre la sessione', function () {
    $code = requestCode('ana@example.test');

    $this->post('/login/code', ['code' => $code])->assertRedirect('/account');

    $this->assertAuthenticatedAs(User::where('email', 'ana@example.test')->sole());
    expect(User::sole()->password)->toBeNull()->and(LoginCode::sole()->used_at)->not->toBeNull();
});

it('il codice vale una volta sola', function () {
    $code = requestCode('ana@example.test');
    $this->post('/login/code', ['code' => $code]);
    $this->post('/logout');

    $this->withSession(['login_email' => 'ana@example.test'])->post('/login/code', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('scade dopo 10 minuti', function () {
    $code = requestCode('ana@example.test');
    $this->travel(11)->minutes();

    $this->post('/login/code', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('dopo 5 tentativi sbagliati il codice non vale più', function () {
    $code = requestCode('ana@example.test');
    $wrong = str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    foreach (range(1, 5) as $i) {
        $this->post('/login/code', ['code' => $wrong])->assertSessionHasErrors('code');
    }

    $this->post('/login/code', ['code' => $code])->assertSessionHasErrors('code');
    expect(LoginCode::sole()->attempts)->toBe(5);
    $this->assertGuest();
});

it('limita le richieste di codice per email', function () {
    foreach (range(1, 3) as $i) {
        $this->post('/login', ['email' => 'ana@example.test'])->assertSessionHasNoErrors();
    }

    $this->post('/login', ['email' => 'ana@example.test'])->assertSessionHasErrors('email');
});

it('non apre la sessione allo staff: entra solo dal pannello con password e MFA', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    User::factory()->staff(StaffRole::Admin)->create(['email' => 'admin@example.test']);

    $this->post('/login', ['email' => 'admin@example.test'])->assertRedirect('/login/code');

    Mail::assertNothingSent();
    expect(LoginCode::count())->toBe(0);
});
