<?php

namespace App\Providers;

use App\Policies\RolePolicy;
use App\Services\StripeGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Nei test si sostituisce con Tests\Fakes\FakeStripe.
        $this->app->singleton(StripeGateway::class);
    }

    public function boot(): void
    {
        // Limite di invio delle email in coda (casella SMTP del cliente).
        RateLimiter::for('mail', fn () => Limit::perMinute(config('mail.rate_per_minute')));

        // Il model Role è di spatie: la Policy non si trova da sola.
        Gate::policy(Role::class, RolePolicy::class);
    }
}
