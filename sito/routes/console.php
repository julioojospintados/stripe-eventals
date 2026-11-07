<?php

use App\Models\LoginCode;
use App\Services\CsvExports;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

// Hosting condiviso: nessun processo sempre attivo. Una sola riga di cron lancia lo scheduler ogni minuto
// (README, «Cron sull'hosting»); il worker vuota la coda e si ferma entro 50 secondi, prima del giro successivo.
Schedule::command('queue:work --queue=critical,default --stop-when-empty --max-time=50 --tries=5 --memory=192')
    ->everyMinute()->withoutOverlapping(5)->runInBackground();

Schedule::command('eas:expire-orders')->everyMinute()->withoutOverlapping(5);

// Riconciliazione con Stripe delle ultime 48 ore: rapporto nel registro audit, avviso se ci sono differenze.
Schedule::command('eas:reconcile')->dailyAt('03:30')->timezone('Europe/Lisbon')->withoutOverlapping(30);

Schedule::call(fn () => LoginCode::where('expires_at', '<', now()->subDay())->delete())->daily()->name('prune-login-codes');

// Export CSV del pannello: file privati tenuti 7 giorni.
Schedule::call(function () {
    $disk = Storage::disk('local');
    foreach ($disk->allFiles('exports') as $path) {
        if ($disk->lastModified($path) < now()->subDays(CsvExports::KEEP_DAYS)->getTimestamp()) {
            $disk->delete($path);
        }
    }
})->daily()->name('prune-exports');
