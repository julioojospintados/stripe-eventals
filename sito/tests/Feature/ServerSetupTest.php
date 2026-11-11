<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;

/** Ricarica routes/console.php su uno scheduler nuovo, con la modalità del worker indicata. */
function scheduledCommands(string $mode): string
{
    config(['queue.worker_mode' => $mode]);
    app()->forgetInstance(Schedule::class);
    Facade::clearResolvedInstance(Schedule::class);
    require base_path('routes/console.php');

    return collect(app(Schedule::class)->events())->map->command->implode(' ');
}

it('lancia queue:work dallo scheduler solo in modalità cron', function () {
    expect(scheduledCommands('cron'))->toContain('queue:work')->toContain('eas:expire-orders');
    expect(scheduledCommands('daemon'))->not->toContain('queue:work')->toContain('eas:expire-orders');
});

it('mostra la striscia STAGING e il noindex solo in staging', function () {
    eas8();
    $this->get('/')->assertOk()->assertDontSee('STAGING')->assertDontSee('noindex', false);
    $this->get('/admin/login')->assertOk()->assertDontSee('STAGING');

    app()['env'] = 'staging';
    $this->get('/')->assertOk()->assertSee('STAGING')->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    $this->get('/admin/login')->assertOk()->assertSee('STAGING')->assertSee('noindex, nofollow', false);
});
