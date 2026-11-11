{{-- Ambiente di prova (APP_ENV=staging): striscia in cima alle pagine e al pannello. Il noindex è nel <head>. --}}
@if (app()->environment('staging'))
    <div role="status" style="background:#b45309;color:#fff;font:600 14px/1.4 system-ui,sans-serif;text-align:center;padding:6px 12px;">
        STAGING · {{ __('Test environment: Stripe test mode, emails are not delivered.') }}
    </div>
@endif
