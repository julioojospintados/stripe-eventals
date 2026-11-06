<?php

// Prove con dati finti sullo schema delle migrazioni bozza (MySQL 8 / MariaDB).
// Autore: Giulio Occhipinti. 06/11/2026.
//
// Uso: CHECK_CODE=3819 php prove_mysql.php <socket> mysql8     (MySQL: codice 3819 per i CHECK violati)
//      CHECK_CODE=4025 php prove_mysql.php <socket> mariadb    (MariaDB: codice 4025)
// Le migrazioni (2026_11_06_080000_create_stub_prerequisites.php + db/migrazioni-bozza/) devono essere già applicate
// con php artisan migrate:fresh sul database "lab", utente "app" (password "app", solo per la prova).
// Le prove in parallelo usano pcntl_fork: ogni processo apre la sua connessione e la sua transazione.
// Dati finti: nessun dato reale, nessun servizio esterno.

declare(strict_types=1);

$socket = $argv[1];
$label = $argv[2];
$tmp = sys_get_temp_dir().'/prove-'.$label;
@mkdir($tmp);

function db(): PDO
{
    global $socket;
    $pdo = new PDO("mysql:unix_socket=$socket;dbname=lab;charset=utf8mb4", 'app', 'app', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("set time_zone = '+00:00'");

    return $pdo;
}

function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function code8(): string
{
    $a = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 8; $i++) {
        $s .= $a[random_int(0, strlen($a) - 1)];
    }

    return $s;
}

$results = [];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$name, $ok, $detail];
    printf("%s %s%s\n", $ok ? 'OK  ' : 'FAIL', $name, $detail !== '' ? " — $detail" : '');
}

/** Esegue $fn e si aspetta un errore che contenga $needle (codice o messaggio). */
function expectError(string $name, string $needle, callable $fn): void
{
    try {
        $fn();
        check($name, false, 'nessun errore');
    } catch (PDOException $e) {
        $msg = ($e->errorInfo[1] ?? '').' '.($e->errorInfo[2] ?? $e->getMessage());
        check($name, str_contains($msg, $needle), trim($msg));
    }
}

function expectOk(string $name, callable $fn): void
{
    try {
        $fn();
        check($name, true);
    } catch (PDOException $e) {
        check($name, false, $e->getMessage());
    }
}

$CHECK = getenv('CHECK_CODE') ?: '3819'; // MySQL 3819, MariaDB 4025

$pdo = db();
$now = gmdate('Y-m-d H:i:s');

// --- Dati di base -------------------------------------------------------------------------------------------
$ins = function (string $table, array $row) use (&$pdo) {
    $cols = implode(',', array_keys($row));
    $ph = implode(',', array_fill(0, count($row), '?'));
    $pdo->prepare("insert into $table ($cols) values ($ph)")->execute(array_values($row));

    return $row['id'] ?? null;
};

$users = [];
foreach (['membro', 'pubblico', 'admin', 'speaker', 'altro'] as $i => $n) {
    $pdo->prepare('insert into users (name, email, password, created_at) values (?, ?, ?, ?)')
        ->execute([$n, "$n@example.test", 'x', $now]);
    $users[$n] = (int) $pdo->lastInsertId();
}
$ev = $ins('events', ['id' => uuid(), 'slug' => 'eas8-'.$label]);
$days = [];
foreach (['2027-04-08', '2027-04-09', '2027-04-10'] as $d) {
    $days[] = $ins('event_days', ['id' => uuid(), 'event_id' => $ev, 'day' => $d]);
}
$ws = $ins('program_sessions', ['id' => uuid()]);
$ws2 = $ins('program_sessions', ['id' => uuid()]);

$tt = fn (string $kind, string $channel = 'public', array $extra = []) => $ins('ticket_types', array_merge([
    'id' => uuid(), 'event_id' => $ev, 'kind' => $kind, 'channel' => $channel,
    'name' => json_encode(['en' => $kind, 'it' => $kind]), 'created_at' => $now,
], $extra));
$pass = $tt('full_pass');
$workshop = $tt('paid_session', 'public', ['program_session_id' => $ws]);
$workshop2 = $tt('paid_session', 'public', ['program_session_id' => $ws2]);
$companion = $tt('companion', 'public', ['max_per_host' => 1]);
$staffPass = $tt('staff_pass', 'panel');

// Fasce: early bird fino al 31/01/2027, regular fino al 31/03/2027, last minute fino all'evento.
$tier = fn ($code, $from, $to) => $ins('price_tiers', ['id' => uuid(), 'event_id' => $ev, 'code' => $code,
    'name' => json_encode(['en' => $code]), 'starts_at' => $from, 'ends_at' => $to, 'created_at' => $now]);
$tEarly = $tier('early_bird', '2026-11-10 00:00:00', '2027-02-01 00:00:00');
$tRegular = $tier('regular', '2027-02-01 00:00:00', '2027-04-01 00:00:00');
$tLast = $tier('last_minute', '2027-04-01 00:00:00', '2027-04-10 18:00:00');

$price = fn ($type, $tierId, $role, $cents) => $ins('ticket_prices', ['id' => uuid(), 'ticket_type_id' => $type,
    'price_tier_id' => $tierId, 'price_role' => $role, 'amount_cents' => $cents, 'created_at' => $now]);
$pPassPublic = $price($pass, null, 'public', 69500);
$pPassMember = $price($pass, null, 'member', 39500);
$pPassEarlyMember = $price($pass, $tEarly, 'member', 27650);  // early bird -30% solo per i membri
$pWorkshop = $price($workshop, null, 'public', 10000);
$pWorkshop2 = $price($workshop2, null, 'public', 10000);
$pCompanion = $price($companion, null, 'public', 15000);      // prezzo finto: domanda aperta

$pool = fn ($name, $cap, $max = null) => $ins('capacity_pools', ['id' => uuid(), 'event_id' => $ev, 'name' => $name,
    'capacity' => $cap, 'max_per_order' => $max, 'created_at' => $now]);
$poolWs = $pool('Workshop 1', 1, 2);       // ultimo posto
$poolWs2 = $pool('Workshop 2', 2, 2);
$poolDay = $pool('Giorno 1', 6000);
$ins('ticket_type_pools', ['ticket_type_id' => $pass, 'pool_id' => $poolDay]);
$ins('ticket_type_pools', ['ticket_type_id' => $workshop, 'pool_id' => $poolWs]);

$order = function (int $user, array $o = []) use ($ins, $ev, $now) {
    return $ins('orders', array_merge([
        'id' => uuid(), 'event_id' => $ev, 'user_id' => $user, 'buyer_email' => "u$user@example.test",
        'status' => 'pending', 'origin' => 'checkout', 'price_role' => 'public', 'subtotal_cents' => 10000,
        'discount_cents' => 0, 'total_cents' => 10000, 'livemode' => 0, 'created_at' => $now,
    ], $o));
};
$item = function (string $orderId, string $type, array $o = []) use ($ins, $now) {
    $q = $o['quantity'] ?? 1;
    $unit = $o['unit'] ?? 10000;

    return $ins('order_items', array_merge([
        'id' => uuid(), 'order_id' => $orderId, 'ticket_type_id' => $type, 'quantity' => $q, 'price_role' => 'public',
        'list_unit_amount_cents' => $unit, 'unit_discount_cents' => 0, 'unit_amount_cents' => $unit,
        'total_cents' => $unit * $q, 'stripe_line_name' => 'riga',
        'attendees' => json_encode(array_fill(0, $q, ['name' => 'A', 'email' => 'a@example.test'])),
        'created_at' => $now,
    ], array_diff_key($o, ['unit' => 1])));
};

echo "== $label: ".$pdo->query('select version()')->fetchColumn().', isolamento '.
    $pdo->query('select @@session.'.($label === 'mysql8' ? 'transaction_isolation' : 'tx_isolation'))->fetchColumn()."\n";

// --- 1. Fasce e prezzi ------------------------------------------------------------------------------------------
expectError('Fascia sovrapposta a un\'altra dello stesso evento', 'price_tier_overlap',
    fn () => $tier('promo', '2027-01-15 00:00:00', '2027-02-15 00:00:00'));
expectError('Fascia spostata sopra un\'altra (update)', 'price_tier_overlap',
    fn () => $pdo->prepare('update price_tiers set ends_at = ? where id = ?')->execute(['2027-02-10 00:00:00', $tEarly]));
expectOk('Fascia adiacente (fine = inizio della successiva) ammessa', fn () => $pdo->prepare(
    'update price_tiers set ends_at = ? where id = ?')->execute(['2027-02-01 00:00:00', $tEarly]));
expectError('Secondo prezzo base per (pass, public)', '1062', fn () => $price($pass, null, 'public', 1));
expectError('Secondo prezzo early bird per (pass, member)', '1062', fn () => $price($pass, $tEarly, 'member', 1));
expectError('Omaggio (staff_pass) vendibile sul sito', $CHECK, fn () => $tt('staff_pass', 'public'));
expectError('Accompagnatore senza limite per pass', $CHECK, fn () => $tt('companion'));

// Risoluzione del prezzo (PriceResolver emulato in SQL): fascia attiva → prezzo della fascia → prezzo base →
// per il membro, poi il pubblico.
$resolve = function (string $type, string $role, string $at) use (&$pdo): ?int {
    $q = $pdo->prepare("select p.amount_cents from ticket_prices p
        left join price_tiers t on t.id = (select id from price_tiers where event_id =
            (select event_id from ticket_types where id = ?) and starts_at <= ? and ? < ends_at)
        where p.ticket_type_id = ? and p.price_role in (?, 'public')
          and (p.price_tier_id = t.id or p.price_tier_id is null)
        order by (p.price_role = ?) desc, (p.price_tier_id is not null) desc limit 1");
    $q->execute([$type, $at, $at, $type, $role, $role]);
    $v = $q->fetchColumn();

    return $v === false ? null : (int) $v;
};
$cases = [
    ['pass membro in early bird', $pass, 'member', '2026-12-01 10:00:00', 27650],
    ['pass pubblico in early bird', $pass, 'public', '2026-12-01 10:00:00', 69500],
    ['pass membro in regular', $pass, 'member', '2027-03-01 10:00:00', 39500],
    ['pass pubblico in regular', $pass, 'public', '2027-03-01 10:00:00', 69500],
    ['workshop membro (nessun prezzo membro)', $workshop, 'member', '2026-12-01 10:00:00', 10000],
];
foreach ($cases as [$n, $t, $r, $at, $exp]) {
    $got = $resolve($t, $r, $at);
    check("Prezzo: $n = ".number_format($exp / 100, 2, ',', '.').' €', $got === $exp, "trovato $got");
}

// --- 2. Ordini -----------------------------------------------------------------------------------------------
$o1 = $order($users['pubblico'], ['price_tier_id' => $tEarly]);
expectError('Secondo ordine pending della stessa persona', '1062', fn () => $order($users['pubblico']));
expectError('Totale diverso da subtotale − sconto', $CHECK, fn () => $order($users['altro'], ['total_cents' => 1]));
expectError('Ordine paid senza data', $CHECK, fn () => $order($users['altro'], ['status' => 'paid']));
expectError('Transizione pending → refunded', 'order_invalid_transition',
    fn () => $pdo->exec("update orders set status = 'refunded' where id = '$o1'"));
expectError('Fascia cambiata dopo la riserva', 'order_snapshot_immutable',
    fn () => $pdo->exec("update orders set price_tier_id = '$tRegular' where id = '$o1'"));
expectError('Totale cambiato dopo la riserva', 'order_snapshot_immutable',
    fn () => $pdo->exec("update orders set subtotal_cents = 1, total_cents = 1 where id = '$o1'"));
expectError('Riga member di quantità 2', $CHECK, fn () => $item($o1, $pass, ['price_role' => 'member', 'quantity' => 2]));
expectError('Nominativi diversi dalla quantità', $CHECK, fn () => $item($o1, $pass, ['quantity' => 2,
    'attendees' => json_encode([['name' => 'A']])]));
expectError('Ordine pagato con Stripe annullato senza rimborso (paid → cancelled)', 'order_invalid_transition',
    function () use ($order, $users, $pdo, $now) {
        $o = $order($users['altro'], ['status' => 'paid', 'paid_at' => $now]);
        $pdo->exec("update orders set status = 'cancelled' where id = '$o'");
    });

// Omaggi
$compBase = ['origin' => 'complimentary', 'status' => 'paid', 'paid_at' => $now, 'subtotal_cents' => 0,
    'total_cents' => 0, 'issued_by' => $users['admin'], 'comp_reason' => 'speaker'];
expectError('Omaggio con importo > 0', $CHECK, fn () => $order($users['speaker'],
    array_merge($compBase, ['subtotal_cents' => 100, 'total_cents' => 100])));
expectError('Omaggio senza chi lo emette', $CHECK, fn () => $order($users['speaker'], array_merge($compBase, ['issued_by' => null])));
expectError('Omaggio senza motivo', $CHECK, fn () => $order($users['speaker'], array_merge($compBase, ['comp_reason' => null])));
expectError('Omaggio pending (deve nascere pagato)', $CHECK, fn () => $order($users['speaker'],
    array_merge($compBase, ['status' => 'pending', 'paid_at' => null])));
$pendingSpeaker = $order($users['speaker']);
$comp = null;
expectOk('Omaggio emesso a chi ha già un checkout aperto (nessun conflitto con la riserva)',
    function () use (&$comp, $order, $users, $compBase) { $comp = $order($users['speaker'], $compBase); });
expectOk('Omaggio revocato (paid → cancelled)', fn () => $pdo->exec("update orders set status = 'cancelled' where id = '$comp'"));

// --- 3. Capienza: difese del database -------------------------------------------------------------------------
expectError('Aumento cieco dei venduti oltre la capienza', $CHECK,
    fn () => $pdo->exec("update capacity_pools set confirmed = confirmed + 2 where id = '$poolWs'"));
expectError('Capienza ridotta sotto i posti presi', $CHECK, function () use ($pdo, $poolWs2) {
    $pdo->exec("update capacity_pools set confirmed = 2 where id = '$poolWs2'");
    $pdo->exec("update capacity_pools set capacity = 1 where id = '$poolWs2'");
});
$pdo->exec("update capacity_pools set confirmed = 0 where id = '$poolWs2'");
$hold = fn ($o, $p, $extra = []) => $ins('capacity_holds', array_merge(['id' => uuid(), 'order_id' => $o, 'pool_id' => $p,
    'quantity' => 1, 'status' => 'active', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 2160), 'created_at' => $now], $extra));
expectError('Riserva attiva senza scadenza', $CHECK, fn () => $hold($o1, $poolWs2, ['expires_at' => null]));
expectError('Riserva rilasciata senza data', $CHECK, fn () => $hold($o1, $poolWs2, ['status' => 'released']));
$hold($o1, $poolWs2);
expectError('Doppia riserva per lo stesso ordine e pool', '1062', fn () => $hold($o1, $poolWs2));
$pdo->exec("delete from capacity_holds where order_id = '$o1'");

// --- 4. Eventi Stripe ------------------------------------------------------------------------------------------
$evt = fn ($id, $extra = []) => $ins('stripe_events', array_merge(['id' => $id, 'type' => 'checkout.session.completed',
    'livemode' => 0, 'stripe_created_at' => $now], $extra));
$evt('evt_1abc');
$pdo->exec("insert ignore into stripe_events (id, type, livemode, stripe_created_at) values ('evt_1abc', 'checkout.session.completed', 0, '$now')");
check('Evento Stripe ripetuto con insertOrIgnore: una riga sola',
    (int) $pdo->query("select count(*) from stripe_events where id = 'evt_1abc'")->fetchColumn() === 1);
expectError('Id evento senza evt_', $CHECK, fn () => $evt('xyz_1'));
expectError('Id evento "evtX" (il _ non fa da jolly)', $CHECK, fn () => $evt('evtX1'));
expectError('Evento processed senza data', $CHECK, fn () => $evt('evt_2', ['status' => 'processed']));

// --- 5. Biglietti: nominativo immutabile -----------------------------------------------------------------------
$oPaid = $order($users['membro'], ['status' => 'paid', 'paid_at' => $now, 'price_role' => 'member']);
$iPaid = $item($oPaid, $pass, ['price_role' => 'member']);
$ticket = fn ($o, $i, $seq, $extra = []) => $ins('tickets', array_merge(['id' => uuid(), 'event_id' => $ev, 'order_id' => $o,
    'order_item_id' => $i, 'seq' => $seq, 'ticket_type_id' => $pass, 'price_role' => 'member',
    'attendee_name' => 'Mario Rosi', 'attendee_email' => 'membro@example.test', 'qr_key_id' => 'k1',
    'short_code' => code8(), 'issued_at' => $now, 'created_at' => $now], $extra));
$t1 = $ticket($oPaid, $iPaid, 1);
expectError('Secondo biglietto per la stessa riga e numero', '1062', fn () => $ticket($oPaid, $iPaid, 1));
expectError('Cambio di nome', 'ticket_attendee_immutable',
    fn () => $pdo->exec("update tickets set attendee_name = 'Luigi Verdi', attendee_email = 'luigi@example.test' where id = '$t1'"));
expectError('Correzione senza firma di chi corregge', 'ticket_attendee_immutable',
    fn () => $pdo->exec("update tickets set attendee_name = 'Mario Rossi', attendee_corrections_count = 1 where id = '$t1'"));
for ($k = 1; $k <= 3; $k++) {
    expectOk("Correzione admin di un refuso n. $k (contatore + 1, firma, data)", fn () => $pdo->exec(
        "update tickets set attendee_name = 'Mario Rossi $k', attendee_corrections_count = attendee_corrections_count + 1,
         attendee_corrected_by = {$users['admin']}, attendee_corrected_at = now(6) + interval $k second where id = '$t1'"));
}
expectError('Quarta correzione', $CHECK, fn () => $pdo->exec(
    "update tickets set attendee_name = 'Mario Rossi 4', attendee_corrections_count = 4,
     attendee_corrected_by = {$users['admin']}, attendee_corrected_at = now() + interval 9 second where id = '$t1'"));
expectError('Tipo del biglietto cambiato', 'ticket_immutable',
    fn () => $pdo->exec("update tickets set ticket_type_id = '$workshop' where id = '$t1'"));
expectError('Codice breve con caratteri ambigui', $CHECK, fn () => $ticket($oPaid, $iPaid, 2, ['short_code' => 'ABCDEF01']));
expectError('Biglietto cancellato dal database', 'ticket_delete_forbidden', fn () => $pdo->exec("delete from tickets where id = '$t1'"));
$pdo->exec("update tickets set status = 'refunded', cancelled_at = now() where id = '$t1'");
expectError('Biglietto rimborsato che torna valido', 'ticket_invalid_transition',
    fn () => $pdo->exec("update tickets set status = 'valid' where id = '$t1'"));

// Accompagnatori
$host = $ticket($oPaid, $iPaid, 2);
$cl = fn ($o, $i, $slot = 1) => $ins('companion_claims', ['id' => uuid(), 'host_ticket_id' => $host, 'slot' => $slot,
    'order_id' => $o, 'order_item_id' => $i]);
$oc1 = $order($users['membro'], ['status' => 'processing']);
$ic1 = $item($oc1, $companion, ['unit' => 15000]);
$cl($oc1, $ic1);
$oc2 = $order($users['membro'], ['status' => 'processing']);
$ic2 = $item($oc2, $companion, ['unit' => 15000]);
expectError('Secondo accompagnatore sullo stesso pass (limite 1)', '1062', fn () => $cl($oc2, $ic2));
$pdo->exec("update companion_claims set released_at = now(), release_reason = 'order_expired' where order_id = '$oc1'");
expectOk('Accompagnatore dopo il rilascio del primo', fn () => $cl($oc2, $ic2));

// --- 6. Prezzo membro una volta per evento e tipo, anche su due ordini ----------------------------------------
$claim = fn ($o, $i, $kind = 'full_pass', $extra = []) => $ins('member_price_claims', array_merge(['id' => uuid(),
    'user_id' => $users['membro'], 'event_id' => $ev, 'kind' => $kind, 'order_id' => $o, 'order_item_id' => $i], $extra));
$claim($oPaid, $iPaid);  // primo pass a prezzo membro (ordine pagato)
$oM2 = $order($users['membro'], ['price_role' => 'member']);  // secondo ordine, pending
$iM2 = $item($oM2, $pass, ['price_role' => 'member', 'unit' => 27650]);
expectError('Secondo pass a prezzo membro (early bird) in un secondo ordine', '1062', fn () => $claim($oM2, $iM2));
$iMw = $item($oM2, $workshop, ['price_role' => 'member']);
expectOk('Workshop a prezzo membro nello stesso ordine (tipo diverso)',
    fn () => $claim($oM2, $iMw, 'paid_session', ['program_session_id' => $ws]));
expectError('Forma della chiave sbagliata (pass con sessione)', $CHECK,
    fn () => $claim($oM2, $item($oM2, $pass), 'full_pass', ['program_session_id' => $ws]));
$pdo->exec("update member_price_claims set released_at = now(), release_reason = 'refunded' where order_id = '$oPaid'");
expectOk('Prenotazione liberata (ordine rimborsato) e nuovo pass membro', fn () => $claim($oM2, $iM2));

// MySQL: CHECK su una colonna con FK ON DELETE SET NULL
$fkSetNull = function () use ($pdo) {
    $pdo->exec('create table t_fk (id int primary key, o char(36) null,
        foreign key (o) references orders(id) on delete set null, check (o is null or o <> \'x\'))');
};
expectError('CHECK su una colonna con FK SET NULL rifiutato (motivo dei RESTRICT)', $label === 'mysql8' ? '3823' : '1901', $fkSetNull);
$pdo->exec('drop table if exists t_fk');

// --- 7. Prove in parallelo ------------------------------------------------------------------------------------
function parallel(array $tasks): array
{
    global $tmp;
    $pids = [];
    foreach ($tasks as $k => $task) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $r = $task(db());
            } catch (Throwable $e) {
                $r = 'error: '.$e->getMessage();
            }
            file_put_contents("$tmp/$k.txt", (string) $r);
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $st);
    }
    $out = [];
    foreach (array_keys($tasks) as $k) {
        $out[$k] = file_get_contents("$tmp/$k.txt");
    }

    return $out;
}

// 7a. Ultimo posto: due checkout in parallelo sul workshop da 1 posto.
$oA = $order($users['altro']);
$oB = $order($users['admin']);
$pdo = null;  // nessuna connessione aperta nel processo padre durante il fork
$reserve = fn (string $orderId, float $wait) => function (PDO $c) use ($orderId, $poolWs, $wait) {
    usleep((int) ($wait * 1e6));
    $c->beginTransaction();
    $c->query("select id from orders where id = '$orderId' for update");
    $c->query("select id from capacity_pools where id = '$poolWs' for update");
    $n = $c->exec("update capacity_pools set held = held + 1 where id = '$poolWs' and held + confirmed + 1 <= capacity");
    if ($n === 1) {
        $c->exec("insert into capacity_holds (id, order_id, pool_id, quantity, status, expires_at) values
            (uuid(), '$orderId', '$poolWs', 1, 'active', utc_timestamp() + interval 36 minute)");
    }
    usleep(800000);  // tiene il lock: l'altra transazione aspetta
    $c->commit();

    return $n === 1 ? 'reserved' : 'sold_out';
};
$r = parallel(['a' => $reserve($oA, 0), 'b' => $reserve($oB, 0.1)]);
$pdo = db();
$held = (int) $pdo->query("select held from capacity_pools where id = '$poolWs'")->fetchColumn();
check('Ultimo posto in parallelo: uno riserva, l\'altro esaurito', $r['a'] === 'reserved' && $r['b'] === 'sold_out' && $held === 1,
    "a={$r['a']}, b={$r['b']}, held=$held");

// 7b. Due prenotazioni del prezzo membro in parallelo (due ordini dello stesso membro, diversi da pending).
$pdo->exec("update member_price_claims set released_at = now(), release_reason = 'test' where released_at is null");
$oP1 = $order($users['membro'], ['status' => 'processing', 'price_role' => 'member']);
$oP2 = $order($users['membro'], ['status' => 'processing', 'price_role' => 'member']);
$iP1 = $item($oP1, $pass, ['price_role' => 'member']);
$iP2 = $item($oP2, $pass, ['price_role' => 'member']);
$pdo = null;
$mclaim = fn ($o, $i, $wait) => function (PDO $c) use ($o, $i, $wait, $users, $ev) {
    usleep((int) ($wait * 1e6));
    $c->beginTransaction();
    try {
        $c->exec("insert into member_price_claims (id, user_id, event_id, kind, order_id, order_item_id)
            values (uuid(), {$users['membro']}, '$ev', 'full_pass', '$o', '$i')");
        usleep(800000);
        $c->commit();

        return 'claimed';
    } catch (PDOException $e) {
        $c->rollBack();

        return 'member_price_limit ('.$e->errorInfo[1].')';
    }
};
$t0 = microtime(true);
$r = parallel(['a' => $mclaim($oP1, $iP1, 0), 'b' => $mclaim($oP2, $iP2, 0.1)]);
$dt = microtime(true) - $t0;
$pdo = db();
$active = (int) $pdo->query("select count(*) from member_price_claims where released_at is null and kind = 'full_pass'")->fetchColumn();
check('Prezzo membro in parallelo su due ordini: uno solo', $r['a'] === 'claimed' && str_starts_with($r['b'], 'member_price_limit') && $active === 1,
    sprintf('a=%s, b=%s, attive=%d, %.1fs (la seconda aspetta la prima)', $r['a'], $r['b'], $active, $dt));

// 7c. Webhook ripetuto e pagina di successo in parallelo sullo stesso ordine: biglietti emessi una volta.
$oW = $order($users['pubblico'], ['status' => 'processing', 'subtotal_cents' => 20000, 'total_cents' => 20000]);
// (l'ordine pending di "pubblico" è $o1: questo è un secondo ordine, in processing come un pagamento in corso)
$iW = $item($oW, $workshop2, ['quantity' => 2]);
$pdo->exec("update capacity_pools set held = held + 2 where id = '$poolWs2'");
$hold($oW, $poolWs2, ['quantity' => 2]);
$pdo = null;
$markPaid = fn (string $evtId, float $wait) => function (PDO $c) use ($evtId, $wait, $oW, $iW, $poolWs2, $ev, $workshop2) {
    usleep((int) ($wait * 1e6));
    $c->beginTransaction();
    $c->exec("insert ignore into stripe_events (id, type, livemode, stripe_created_at) values ('$evtId', 'checkout.session.completed', 0, utc_timestamp())");
    $st = $c->query("select status from orders where id = '$oW' for update")->fetchColumn();
    if ($st === 'paid') {
        $c->commit();

        return 'already_done';
    }
    $c->query("select id from capacity_pools where id = '$poolWs2' for update");
    $c->exec("update capacity_holds set status = 'converted' where order_id = '$oW' and status = 'active'");
    $c->exec("update capacity_pools set held = held - 2, confirmed = confirmed + 2 where id = '$poolWs2'");
    foreach ([1, 2] as $seq) {
        $c->exec("insert into tickets (id, event_id, order_id, order_item_id, seq, ticket_type_id, price_role, attendee_name,
            attendee_email, qr_key_id, short_code, issued_at) values (uuid(), '$ev', '$oW', '$iW', $seq, '$workshop2', 'public',
            'A', 'a@example.test', 'k1', '".code8()."', utc_timestamp())");
    }
    $c->exec("update orders set status = 'paid', paid_at = utc_timestamp() where id = '$oW'");
    usleep(800000);
    $c->commit();

    return 'paid';
};
$r = parallel(['webhook' => $markPaid('evt_pay_1', 0), 'success_page' => $markPaid('evt_pay_1', 0.1), 'retry' => $markPaid('evt_pay_1', 0.2)]);
$pdo = db();
$nt = (int) $pdo->query("select count(*) from tickets where order_id = '$oW'")->fetchColumn();
$ne = (int) $pdo->query("select count(*) from stripe_events where id = 'evt_pay_1'")->fetchColumn();
$pc = $pdo->query("select held, confirmed from capacity_pools where id = '$poolWs2'")->fetch();
check('Webhook ripetuto + pagina di successo in parallelo: 2 biglietti e non 4 o 6',
    $nt === 2 && $ne === 1 && (int) $pc['confirmed'] === 2 && (int) $pc['held'] === 0,
    "esiti ".implode('/', $r).", biglietti=$nt, eventi=$ne, held={$pc['held']}, confirmed={$pc['confirmed']}");

// --- 8. Conferma tardiva con riprenotazione -------------------------------------------------------------------
// Ordine A ha l'ultimo posto del workshop 1 in riserva (7a). Stripe conferma la scadenza: rilascio.
$pdo->beginTransaction();
$pdo->query("select id from orders where id = '$oA' for update");
$pdo->exec("update capacity_holds set status = 'released', released_at = utc_timestamp(), release_reason = 'checkout_expired', expires_at = null where order_id = '$oA'");
$pdo->exec("update capacity_pools set held = held - 1 where id = '$poolWs'");
$pdo->exec("update orders set status = 'expired' where id = '$oA'");
$pdo->commit();
// Un altro acquirente prende e paga l'ultimo posto.
$late = function (string $orderId, string $poolId) use (&$pdo): string {
    $pdo->beginTransaction();
    $st = $pdo->query("select status from orders where id = '$orderId' for update")->fetchColumn();
    $pdo->query("select id from capacity_pools where id = '$poolId' for update");
    $n = $pdo->exec("update capacity_pools set confirmed = confirmed + 1 where id = '$poolId' and held + confirmed + 1 <= capacity");
    if ($n === 1) {
        $pdo->exec("update capacity_holds set status = 'converted', expires_at = null where order_id = '$orderId' and pool_id = '$poolId'");
        $pdo->exec("update orders set status = 'paid', paid_at = utc_timestamp() where id = '$orderId'");
        $res = "paid_late (da $st)";
    } else {
        $pdo->exec("update orders set status = 'needs_refund' where id = '$orderId'");
        // RefundOrderFully va in coda nella stessa transazione (qui una riga finta nella tabella jobs).
        $pdo->exec("insert into jobs (queue, payload, attempts, available_at, created_at) values
            ('critical', '{\"job\":\"RefundOrderFully\",\"order\":\"$orderId\"}', 0, unix_timestamp(), unix_timestamp())");
        $res = "needs_refund (da $st)";
    }
    $pdo->commit();

    return $res;
};
// B era "sold_out" in 7a: il suo ordine pending va chiuso; qui B ripete il checkout e prende il posto liberato.
$pdo->exec("update orders set status = 'expired' where id = '$oB'");
$oB2 = $order($users['admin']);
$pdo->exec("update capacity_pools set held = held + 1 where id = '$poolWs' and held + confirmed + 1 <= capacity");
$hold($oB2, $poolWs);
$pdo->exec("update capacity_holds set status = 'converted', expires_at = null where order_id = '$oB2'");
$pdo->exec("update capacity_pools set held = held - 1, confirmed = confirmed + 1 where id = '$poolWs'");
$pdo->exec("update orders set status = 'paid', paid_at = utc_timestamp() where id = '$oB2'");
// Ora arriva il pagamento tardivo di A: posti finiti.
$res = $late($oA, $poolWs);
$row = $pdo->query("select status from orders where id = '$oA'")->fetch();
$pc = $pdo->query("select held, confirmed, capacity from capacity_pools where id = '$poolWs'")->fetch();
$jobs = (int) $pdo->query("select count(*) from jobs where payload like '%$oA%'")->fetchColumn();
$tk = (int) $pdo->query("select count(*) from tickets where order_id = '$oA'")->fetchColumn();
check('Conferma tardiva senza posti: needs_refund, 0 biglietti, rimborso in coda, nessun overselling',
    $row['status'] === 'needs_refund' && (int) $pc['confirmed'] === 1 && $jobs === 1 && $tk === 0,
    "$res, confirmed={$pc['confirmed']}/{$pc['capacity']}, job=$jobs");
// Variante con posto libero: capienza portata a 2 dallo staff, poi un altro ordine scaduto paga in ritardo.
$pdo->exec("update capacity_pools set capacity = 2 where id = '$poolWs'");
$oD = $order($users['altro']);
$pdo->exec("update capacity_pools set held = held + 1 where id = '$poolWs'");
$hold($oD, $poolWs);
$pdo->exec("update capacity_holds set status = 'released', released_at = utc_timestamp(), expires_at = null where order_id = '$oD'");
$pdo->exec("update capacity_pools set held = held - 1 where id = '$poolWs'");
$pdo->exec("update orders set status = 'expired' where id = '$oD'");
$res = $late($oD, $poolWs);
$row = $pdo->query("select status, paid_at from orders where id = '$oD'")->fetch();
$h = $pdo->query("select status from capacity_holds where order_id = '$oD'")->fetchColumn();
$pc = $pdo->query("select held, confirmed from capacity_pools where id = '$poolWs'")->fetch();
check('Conferma tardiva con posto libero: riprenotato (expired → paid), riserva converted',
    $row['status'] === 'paid' && $row['paid_at'] !== null && $h === 'converted' && (int) $pc['confirmed'] === 2,
    "$res, riserva=$h, confirmed={$pc['confirmed']}");

$fail = array_filter($results, fn ($r) => ! $r[1]);
printf("== %s: %d prove, %d fallite\n", $label, count($results), count($fail));
exit($fail ? 1 : 0);
