<?php

// Prove in parallelo con processi separati e transazioni vere (db/prove/prove_mysql.php, sezione 7): ogni processo
// tiene i lock 0,8 secondi e il secondo parte 0,1 secondi dopo, quindi deve aspettare il primo.

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/** @param array<string, array{0: string, 1: float, 2: list<string>}> $tasks nome => [scenario, ritardo, argomenti] */
function parallel(array $tasks): array
{
    $start = microtime(true) + 2;   // il tempo di avviare i processi
    $env = [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => config('database.default'), 'DB_DATABASE' => DB::getDatabaseName(),
        'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    ];
    DB::disconnect();

    $results = Process::concurrently(function (Pool $pool) use ($tasks, $start, $env) {
        foreach ($tasks as $name => [$scenario, $delay, $args]) {
            $pool->as($name)->env($env)->timeout(60)
                ->command(['php', base_path('tests/Concurrency/worker.php'), $scenario, (string) ($start + $delay), ...$args]);
        }
    });

    return collect($results)->map(fn ($r) => trim($r->output()) ?: 'error: '.trim($r->errorOutput()))->all();
}

it('ultimo posto: due carrelli sul workshop da 1 posto, uno riserva e l\'altro trova esaurito', function () {
    $event = eas8();
    workshop()->pools()->update(['capacity' => 1]);
    [$a, $b] = User::factory()->count(2)->create();
    $types = [passType()->id, workshop()->id];

    $r = parallel([
        'a' => ['reserve', 0, [(string) $a->id, $event->id, ...$types]],
        'b' => ['reserve', 0.1, [(string) $b->id, $event->id, ...$types]],
    ]);

    expect($r)->toBe(['a' => 'reserved', 'b' => 'sold_out'])
        ->and(workshop()->pools()->first()->held)->toBe(1)
        ->and(Order::count())->toBe(1);
});

it('prezzo membro su due ordini in parallelo: la seconda transazione aspetta e fallisce sull\'indice unico', function () {
    $member = member();
    $items = collect([1, 2])->map(function () use ($member) {
        $order = Order::factory()->create(['event_id' => eas8()->id, 'user_id' => $member->id, 'status' => OrderStatus::Processing, 'price_role' => 'member']);

        return $order->items()->create([
            'ticket_type_id' => passType()->id, 'quantity' => 1, 'price_role' => 'member', 'list_unit_amount_cents' => 10000,
            'unit_amount_cents' => 10000, 'total_cents' => 10000, 'stripe_line_name' => 'Full pass',
            'attendees' => [['name' => $member->name, 'email' => $member->email]],
        ]);
    });

    $started = microtime(true);
    $r = parallel(['a' => ['claim', 0, [$items[0]->id]], 'b' => ['claim', 0.1, [$items[1]->id]]]);

    expect($r)->toBe(['a' => 'claimed', 'b' => 'member_price_limit'])
        ->and(DB::table('member_price_claims')->whereNull('released_at')->count())->toBe(1)
        ->and(microtime(true) - $started)->toBeGreaterThan(2.8);   // 2 s di avvio + 0,8 s di attesa del primo
});

it('webhook, pagina di successo e reinvio in parallelo: paid una volta, 2 biglietti e non 4 o 6', function () {
    workshop()->pools()->update(['capacity' => 2]);
    $user = User::factory()->create();
    $order = reserve($user, [line(passType(), $user, ['name' => 'Bea', 'email' => 'bea@example.test']), line(workshop(), $user, ['name' => 'Bea', 'email' => 'bea@example.test'])]);
    app(CheckoutService::class)->startCheckout($order);

    $r = parallel([
        'webhook' => ['pay', 0, [$order->id]],
        'success_page' => ['pay', 0.1, [$order->id]],
        'retry' => ['pay', 0.2, [$order->id]],
    ]);

    expect($r)->toBe(['webhook' => 'paid', 'success_page' => 'already_done', 'retry' => 'already_done'])
        ->and($order->tickets()->count())->toBe(4)   // 2 pass + 2 workshop, una volta sola
        ->and(workshop()->pools()->first()->only('held', 'confirmed'))->toEqual(['held' => 0, 'confirmed' => 2]);
});
