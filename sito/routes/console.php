<?php

use App\Models\LoginCode;
use App\Services\CsvExports;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

// QUEUE_WORKER_MODE=daemon: il worker è un processo stabile (systemd o supervisor, strumenti/server/).
// cron (hosting condiviso): lo scheduler lancia ogni minuto un worker che vuota la coda e si ferma entro 50 secondi.
if (config('queue.worker_mode') !== 'daemon') {
    Schedule::command('queue:work --queue=critical,default --stop-when-empty --max-time=50 --tries=5 --memory=192')
        ->everyMinute()->withoutOverlapping(5)->runInBackground();
}

Schedule::command('eventals:expire-orders')->everyMinute()->withoutOverlapping(5);

// Riconciliazione con Stripe delle ultime 48 ore: rapporto nel registro audit, avviso se ci sono differenze.
Schedule::command('eventals:reconcile')->dailyAt('03:30')->timezone(config('eventals.timezone'))->withoutOverlapping(30);

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
