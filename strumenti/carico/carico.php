<?php

/**
 * Prova di carico dell'apertura delle vendite (docs/tecnico/carico.md). Prepara un database usa e getta con l'evento
 * dimostrativo, lascia N posti del pass e M del workshop, crea gli utenti (una parte membri, una parte che prova due
 * volte in parallelo, come con due schede) e lancia --workers processi PHP che riservano e pagano tutti nello stesso
 * istante con i Service veri su MariaDB/MySQL. Poi controlla: niente overselling, niente doppio prezzo membro,
 * nessun deadlock o lock wait non gestito; stampa p50/p95 dei tempi. Esce con 1 se un controllo fallisce.
 *
 * Uso (dalla radice del repository, con sito/vendor installato anche con le dipendenze dev):
 *   php strumenti/carico/carico.php [--db=eas8_carico] [--users=500] [--workers=50] [--pass=100] [--workshop=20]
 *       [--members=40] [--duplicates=10] [--with-workshop=50]
 * Le percentuali sono sugli utenti. Il database deve finire con _carico o _test: lo script lo svuota.
 * Autore: Giulio Occhipinti.
 */

declare(strict_types=1);

use App\Enums\TicketKind;
use App\Models\CapacityPool;
use App\Models\Event;
use App\Models\Membership;
use App\Models\MembershipPlan;
use Database\Seeders\Eas8Seeder;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

require __DIR__.'/app.php';

$o = getopt('', ['db::', 'users::', 'workers::', 'pass::', 'workshop::', 'members::', 'duplicates::', 'with-workshop::']);
$opt = fn (string $k, int|string $d) => $o[$k] ?? $d;
$users = (int) $opt('users', 500);
$workers = (int) $opt('workers', 50);
$passSeats = (int) $opt('pass', 100);
$workshopSeats = (int) $opt('workshop', 20);

$env = [
    'DB_DATABASE' => (string) $opt('db', 'eas8_carico'),
    'QR_SIGNING_PRIVATE_KEY' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())),
];
loadApp($env);

echo "Preparo {$env['DB_DATABASE']}: migrazioni, evento, $users utenti...\n";
Artisan::call('migrate:fresh', ['--force' => true]);
(new Eas8Seeder)->run();
$event = Event::where('slug', 'eas8-lisbon-2027')->firstOrFail();
$pass = $event->ticketTypes()->where('kind', TicketKind::FullPass)->firstOrFail();
$workshop = $event->ticketTypes()->where('kind', TicketKind::PaidSession)->orderBy('position')->firstOrFail();
CapacityPool::whereIn('id', $pass->pools()->pluck('capacity_pools.id'))->update(['capacity' => $passSeats]);
CapacityPool::whereIn('id', $workshop->pools()->pluck('capacity_pools.id')->diff($pass->pools()->pluck('capacity_pools.id')))
    ->update(['capacity' => $workshopSeats]);

$password = Hash::make(Str::random(20));
DB::table('users')->insert(array_map(fn ($i) => [
    'name' => "Carico $i", 'email' => "carico$i@example.test", 'password' => $password, 'locale' => 'en',
    'created_at' => now(), 'updated_at' => now(),
], range(1, $users)));
$ids = DB::table('users')->orderBy('id')->pluck('id')->shuffle()->all();
$plan = MembershipPlan::factory()->create();
foreach (array_slice($ids, 0, intdiv($users * (int) $opt('members', 40), 100)) as $id) {
    Membership::factory()->create(['user_id' => $id, 'plan_id' => $plan->id]);
}
shuffle($ids);
$withWorkshop = array_slice($ids, 0, intdiv($users * (int) $opt('with-workshop', 50), 100));

// Ogni utente in un processo; i «doppi» anche in un altro processo, così le due prove corrono in parallelo.
$queue = $ids;
foreach (array_slice($ids, 0, intdiv($users * (int) $opt('duplicates', 10), 100)) as $id) {
    $queue[] = $id;
}
$lists = array_fill(0, $workers, []);
foreach ($queue as $n => $id) {
    $lists[$n % $workers][] = $id;
}

$planFile = tempnam(sys_get_temp_dir(), 'carico');
$startAt = microtime(true) + max(3, $workers * 0.08);   // il tempo di avviare i processi
file_put_contents($planFile, json_encode([
    'env' => $env, 'event_id' => $event->id, 'pass_id' => $pass->id, 'workshop_id' => $workshop->id,
    'with_workshop' => $withWorkshop, 'workers' => $lists, 'start_at' => $startAt,
]));
DB::disconnect();

echo count($queue)." tentativi su $workers processi; pass $passSeats posti, workshop $workshopSeats.\n";
$results = Process::concurrently(function (Pool $pool) use ($workers, $planFile) {
    foreach (range(0, $workers - 1) as $i) {
        $pool->as((string) $i)->timeout(600)->command([PHP_BINARY, __DIR__.'/worker.php', $planFile, (string) $i]);
    }
});
$elapsed = microtime(true) - $startAt;
unlink($planFile);

$rows = [];
$crashed = [];
foreach ($results as $i => $result) {
    foreach (array_filter(explode("\n", $result->output())) as $line) {
        $rows[] = json_decode($line, true);
    }
    if (! $result->successful()) {
        $crashed[] = "processo $i: ".mb_substr(trim($result->errorOutput()), 0, 300);
    }
}

// Controlli sul database.
$pools = DB::table('capacity_pools')->where('event_id', $event->id)->get()->map(fn ($p) => [
    'name' => $p->name, 'capacity' => $p->capacity, 'held' => $p->held, 'confirmed' => $p->confirmed,
    'holds_active' => (int) DB::table('capacity_holds')->where('pool_id', $p->id)->whereIn('status', ['active', 'awaiting_payment'])->sum('quantity'),
    'holds_converted' => (int) DB::table('capacity_holds')->where('pool_id', $p->id)->where('status', 'converted')->sum('quantity'),
]);
$validTickets = fn (string $typeId) => DB::table('tickets')->where('ticket_type_id', $typeId)->where('status', 'valid')->count();
$doubleClaims = DB::table('member_price_claims')->whereNull('released_at')
    ->selectRaw('count(*) n')->groupBy('user_id', 'event_id', 'kind', 'event_day_id', 'program_session_id')->havingRaw('count(*) > 1')->get()->count();
$doubleMemberTickets = DB::table('tickets')->join('orders', 'orders.id', '=', 'tickets.order_id')->join('ticket_types', 'ticket_types.id', '=', 'tickets.ticket_type_id')
    ->where('tickets.status', 'valid')->where('tickets.price_role', 'member')
    ->selectRaw('count(*) n')->groupBy('orders.user_id', 'ticket_types.id')->havingRaw('count(*) > 1')->get()->count();
$errors = array_filter($rows, fn ($r) => str_starts_with($r['reserve'], 'error:') || str_starts_with($r['pay'] ?? '', 'error:'));

$checks = [
    'nessun pool oltre la capienza' => $pools->every(fn ($p) => $p['capacity'] >= $p['held'] + $p['confirmed']),
    'contatori dei pool = somma delle riserve' => $pools->every(fn ($p) => $p['held'] === $p['holds_active'] && $p['confirmed'] === $p['holds_converted']),
    "biglietti pass validi <= $passSeats" => $validTickets($pass->id) <= $passSeats,
    "biglietti workshop validi <= $workshopSeats" => $validTickets($workshop->id) <= $workshopSeats,
    'un solo prezzo membro attivo per persona e tipo' => $doubleClaims === 0,
    'nessun biglietto membro doppio pagato' => $doubleMemberTickets === 0,
    'nessun deadlock, lock wait o errore non gestito' => $errors === [] && $crashed === [],
];

$pct = function (array $values, float $p): float {
    sort($values);

    return $values === [] ? 0.0 : $values[(int) min(count($values) - 1, floor($p * count($values)))];
};
$timing = fn (string $key) => ($v = array_column(array_filter($rows, fn ($r) => isset($r[$key])), $key))
    ? sprintf('p50 %.0f ms · p95 %.0f ms · max %.0f ms (%d)', $pct($v, .5), $pct($v, .95), max($v), count($v)) : '—';
$count = fn (string $key) => array_count_values(array_map(fn ($r) => $r[$key] ?? '—', $rows));

echo "\nDurata dalla partenza all'ultimo processo: ".round($elapsed, 1)." s\n";
echo 'Riserva:  '.$timing('reserve_ms')."\nCheckout: ".$timing('checkout_ms')."\nPagamento: ".$timing('pay_ms')."\n";
echo 'Esiti riserva: '.json_encode($count('reserve'))."\nEsiti pagamento: ".json_encode($count('pay'))."\n";
echo "Pass validi: {$validTickets($pass->id)}/$passSeats · workshop validi: {$validTickets($workshop->id)}/$workshopSeats\n";
echo 'Ordini per stato: '.json_encode(DB::table('orders')->groupBy('status')->selectRaw('status, count(*) n')->pluck('n', 'status'))."\n\n";
foreach ($checks as $name => $ok) {
    echo ($ok ? 'OK    ' : 'ERRORE').' '.$name."\n";
}
foreach (array_slice([...$crashed, ...array_map('json_encode', $errors)], 0, 10) as $line) {
    echo "  $line\n";
}

exit(in_array(false, $checks, true) ? 1 : 0);
