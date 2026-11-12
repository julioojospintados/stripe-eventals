<?php

namespace App\Providers;

use App\Models\Event;
use App\Policies\RolePolicy;
use App\Services\StripeGateway;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
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

        // Ore del pannello per ciò che non appartiene a un evento (registro, membership, contestazioni).
        FilamentTimezone::set(fn () => config('eventals.timezone'));

        // Intestazione e piede delle pagine pubbliche: l'evento corrente, o solo l'organizzatore se non ce n'è.
        View::composer('layouts.app', function ($view) {
            $event = Event::current();
            $organizer = config('eventals.organizer.name');
            $view->with('site', [
                'name' => $event ? localized($event->title) : $organizer,
                'description' => $event?->summary ? localized($event->summary) : ($event ? localized($event->title) : $organizer),
                'dates' => $event ? $event->starts_on->format('j').'–'.$event->ends_on->translatedFormat('j F Y') : null,
                'organizer' => $organizer,
                'logo' => config('eventals.organizer.logo'),
            ]);
        });
    }
}
