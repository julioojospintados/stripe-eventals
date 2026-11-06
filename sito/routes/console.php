<?php

use App\Models\LoginCode;
use Illuminate\Support\Facades\Schedule;

// Hosting condiviso: nessun processo sempre attivo. Una sola riga di cron lancia lo scheduler ogni minuto
// (README, «Cron sull'hosting»); il worker vuota la coda e si ferma entro 50 secondi, prima del giro successivo.
Schedule::command('queue:work --queue=critical,default --stop-when-empty --max-time=50 --tries=5 --memory=192')
    ->everyMinute()->withoutOverlapping(5)->runInBackground();

Schedule::command('eas:expire-orders')->everyMinute()->withoutOverlapping(5);

Schedule::call(fn () => LoginCode::where('expires_at', '<', now()->subDay())->delete())->daily()->name('prune-login-codes');
