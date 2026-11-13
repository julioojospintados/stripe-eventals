<?php

namespace App\Services;

use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Accesso dei clienti con un codice di 6 cifre via email, senza password (docs/architettura.md §5.1). Il codice si
 * salva solo come HMAC con APP_KEY, scade in 10 minuti, ammette 5 tentativi; limiti per email (anche al giorno) e per IP.
 * Lo staff entra solo dal pannello (password + MFA): il codice non gli apre la sessione.
 */
class LoginCodeService
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public function send(string $email, string $ip): void
    {
        $email = mb_strtolower(trim($email));
        $this->throttle('login-code-send:'.sha1($email), 3, 600);
        // Tetto giornaliero per email: con IP diversi un attaccante resta a 10 codici × 5 tentativi = 50 prove al giorno su 10^6.
        $this->throttle('login-code-send-day:'.sha1($email), 10, 86400);
        $this->throttle('login-code-send-ip:'.$ip, 20, 3600);

        if (User::where('email', $email)->first()?->isStaff()) {
            return;  // stessa risposta di un invio riuscito: non si rivela chi è dello staff
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::transaction(function () use ($email, $code) {
            LoginCode::where('email', $email)->delete();
            LoginCode::create(['email' => $email, 'code_hash' => $this->hash($email, $code), 'expires_at' => now()->addMinutes(self::TTL_MINUTES)]);
        });

        // Non passa dalla coda del cron (arriverebbe fino a un minuto dopo): parte dopo aver chiuso la risposta.
        dispatch(fn () => Mail::to($email)->send(new LoginCodeMail($code)))->afterResponse();
    }

    /** L'utente (creato al primo accesso) se il codice è giusto, altrimenti un errore di validazione. */
    public function verify(string $email, string $code, string $ip): User
    {
        $email = mb_strtolower(trim($email));
        $this->throttle('login-code-verify-ip:'.$ip, 30, 3600);

        $user = DB::transaction(function () use ($email, $code) {
            $row = LoginCode::where('email', $email)->whereNull('used_at')->where('expires_at', '>', now())
                ->lockForUpdate()->latest('id')->first();
            if (! $row || $row->attempts >= self::MAX_ATTEMPTS) {
                return null;
            }
            if (! hash_equals($row->code_hash, $this->hash($email, $code))) {
                $row->increment('attempts');

                return null;
            }
            $row->update(['used_at' => now()]);

            return User::firstOrCreate(['email' => $email], ['name' => Str::before($email, '@')]);
        });

        if (! $user || $user->isStaff()) {
            throw ValidationException::withMessages(['code' => __('auth.failed')]);
        }

        return $user;
    }

    private function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', $email.'|'.$code, config('app.key'));
    }

    private function throttle(string $key, int $max, int $seconds): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages(['email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])]);
        }
        RateLimiter::hit($key, $seconds);
    }
}
