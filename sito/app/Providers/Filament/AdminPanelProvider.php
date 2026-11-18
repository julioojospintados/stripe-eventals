<?php

namespace App\Providers\Filament;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * MFA obbligatoria per lo staff. Si può spegnere solo fuori produzione (STAFF_MFA_REQUIRED=false per lo
     * sviluppo locale): in produzione resta obbligatoria anche se il .env dice il contrario.
     */
    public static function staffMfaRequired(): bool
    {
        return app()->isProduction() || (bool) config('eventals.staff_mfa_required', true);
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(fn () => config('eventals.organizer.name'))
            // Solo lo staff (User::canAccessPanel), con password argon2id e MFA TOTP obbligatoria (architettura §5.2).
            ->login()
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ], isRequired: self::staffMfaRequired())
            ->colors([
                'primary' => Color::Teal,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            // Avviso di fine import CSV del programma (la coda gira nel cron, quindi arriva come notifica salvata).
            ->databaseNotifications()
            // Ambiente di prova: striscia «STAGING» e noindex (resources/views/partials/staging.blade.php).
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => app()->environment('staging') ? '<meta name="robots" content="noindex, nofollow">' : '')
            ->renderHook(PanelsRenderHook::BODY_START, fn () => view('partials.staging'))
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->navigationGroups(['Event', 'Programme', 'Sales', 'Orders', 'Exports', 'System'])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
