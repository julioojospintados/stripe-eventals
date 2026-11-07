<?php

/**
 * Genera docs/tecnico/database.md dal database vero (information_schema): diagramma ER in Mermaid e dizionario dati
 * con colonne, chiavi, indici unici, colonne generate, CHECK, chiavi esterne e trigger.
 *
 * Autore: Giulio Occhipinti.
 *
 * Uso (dalla radice del repository, dopo `php artisan migrate`):
 *   php strumenti/genera-doc-db/genera.php [--env=sito/.env] [--out=docs/tecnico/database.md]
 *
 * Credenziali: DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD (e DB_SOCKET) dalle variabili d'ambiente, o in
 * mancanza dal file .env indicato. Lo script legge soltanto: nessuna scrittura sul database.
 * Richiede PHP 8.3+ con pdo_mysql; MySQL 8.0.19+ o MariaDB 10.7+.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$options = getopt('', ['env::', 'out::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "php strumenti/genera-doc-db/genera.php [--env=sito/.env] [--out=docs/tecnico/database.md]\n");
    exit(0);
}
$envFile = resolvePath($options['env'] ?? 'sito/.env', $root);
$outFile = resolvePath($options['out'] ?? 'docs/tecnico/database.md', $root);

$config = dbConfig($envFile);
$dsn = $config['DB_SOCKET'] !== ''
    ? "mysql:unix_socket={$config['DB_SOCKET']};dbname={$config['DB_DATABASE']};charset=utf8mb4"
    : "mysql:host={$config['DB_HOST']};port={$config['DB_PORT']};dbname={$config['DB_DATABASE']};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $config['DB_USERNAME'], $config['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Connessione al database non riuscita: {$e->getMessage()}\n");
    exit(1);
}

$schema = $config['DB_DATABASE'];
$descriptions = require __DIR__.'/descrizioni.php';

/** Tabelle tecniche di Laravel escluse dal diagramma ER (restano nel dizionario). */
$frameworkTables = ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'password_reset_tokens', 'migrations', 'imports', 'failed_import_rows', 'notifications'];

$q = function (string $sql, array $params = []) use ($pdo): array {
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll();
};

$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
$isMaria = stripos($version, 'mariadb') !== false;

$tables = array_column($q(
    "SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
    [$schema],
), 't');
if ($tables === []) {
    fwrite(STDERR, "Nessuna tabella in `$schema`: lancia prima le migrazioni.\n");
    exit(1);
}

$columns = groupBy($q(
    'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA,
            GENERATION_EXPRESSION, COLUMN_COMMENT, ORDINAL_POSITION
       FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
    [$schema],
), 'TABLE_NAME');

$indexRows = $q(
    'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
       FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
    [$schema],
);
$indexes = [];
foreach ($indexRows as $r) {
    $indexes[$r['TABLE_NAME']][$r['INDEX_NAME']]['unique'] = (int) $r['NON_UNIQUE'] === 0;
    $indexes[$r['TABLE_NAME']][$r['INDEX_NAME']]['columns'][] = $r['COLUMN_NAME'];
}

$foreignKeys = groupBy($q(
    'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
            r.DELETE_RULE, r.UPDATE_RULE
       FROM information_schema.KEY_COLUMN_USAGE k
       JOIN information_schema.REFERENTIAL_CONSTRAINTS r
         ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
      WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
      ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
    [$schema],
), 'TABLE_NAME');

// MariaDB ha TABLE_NAME in CHECK_CONSTRAINTS; MySQL no: si passa da TABLE_CONSTRAINTS.
$checks = groupBy($isMaria
    ? $q('SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
           WHERE CONSTRAINT_SCHEMA = ? ORDER BY TABLE_NAME, CONSTRAINT_NAME', [$schema])
    : $q("SELECT t.TABLE_NAME, c.CONSTRAINT_NAME, c.CHECK_CLAUSE
            FROM information_schema.CHECK_CONSTRAINTS c
            JOIN information_schema.TABLE_CONSTRAINTS t
              ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME AND t.CONSTRAINT_TYPE = 'CHECK'
           WHERE c.CONSTRAINT_SCHEMA = ? ORDER BY t.TABLE_NAME, c.CONSTRAINT_NAME", [$schema]), 'TABLE_NAME');

$triggers = groupBy($q(
    'SELECT EVENT_OBJECT_TABLE AS TABLE_NAME, TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
       FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME',
    [$schema],
), 'TABLE_NAME');

$lastMigration = in_array('migrations', $tables, true)
    ? (string) $pdo->query('SELECT migration FROM migrations ORDER BY id DESC LIMIT 1')->fetchColumn()
    : '—';

// Colonne JSON di MariaDB: longtext con un CHECK json_valid() che porta il nome della colonna.
$jsonColumns = [];
foreach ($checks as $table => $rows) {
    foreach ($rows as $r) {
        if (preg_match('/^json_valid\(`?(\w+)`?\)$/i', trim($r['CHECK_CLAUSE']), $m) && $m[1] === $r['CONSTRAINT_NAME']) {
            $jsonColumns[$table][$m[1]] = true;
        }
    }
}

$missing = array_values(array_diff($tables, array_keys($descriptions)));

// ---------------------------------------------------------------------------------------------------------------
$md = [];
$md[] = '# Database: diagramma ER e dizionario dati';
$md[] = '';
$md[] = 'Autore: Giulio Occhipinti.';
$md[] = '';
$md[] = '> File **generato**: non modificarlo a mano. Dopo ogni migrazione, dalla radice del repository:';
$md[] = '> `php strumenti/genera-doc-db/genera.php` (dettagli in [`strumenti/genera-doc-db/README.md`](../../strumenti/genera-doc-db/README.md)).';
$md[] = '> Le descrizioni delle tabelle stanno in `strumenti/genera-doc-db/descrizioni.php`.';
$md[] = '';
$md[] = '| Generato da | Valore |';
$md[] = '|---|---|';
$md[] = '| Server | '.cell($version).' |';
$md[] = '| Database | `'.cell($schema).'` |';
$md[] = '| Ultima migrazione | `'.cell($lastMigration).'` |';
$md[] = '| Tabelle | '.count($tables).' |';
$md[] = '| Trigger | '.array_sum(array_map('count', $triggers)).' |';
$md[] = '';
$md[] = 'Nota per MySQL: le colonne JSON qui risultano `longtext` con un `CHECK json_valid()` perché il documento è';
$md[] = 'generato su '.($isMaria ? 'MariaDB' : 'MySQL').'; su MySQL 8 sono di tipo `json`. Le colonne generate `*_key` o `active_*`';
$md[] = 'emulano gli indici unici parziali di Postgres: valgono `NULL` quando la riga non deve contare, e `NULL` non collide';
$md[] = 'in un indice unico.';
$md[] = '';
$md[] = '## Indice delle tabelle';
$md[] = '';
$md[] = '| Tabella | A cosa serve |';
$md[] = '|---|---|';
foreach ($tables as $t) {
    $md[] = "| [`$t`](#".anchor($t).') | '.cell($descriptions[$t] ?? '(descrizione mancante)').' |';
}
$md[] = '';

// Diagramma ER ------------------------------------------------------------------------------------------------------
$md[] = '## Diagramma ER';
$md[] = '';
$md[] = 'Solo le colonne chiave (PK, FK, UK) e gli stati; tutte le colonne sono nel dizionario. Escluse le tabelle';
$md[] = 'tecniche di Laravel: '.implode(', ', array_map(fn ($t) => "`$t`", array_intersect($frameworkTables, $tables))).'.';
$md[] = '';
$md[] = '```mermaid';
$md[] = 'erDiagram';
foreach ($tables as $t) {
    if (in_array($t, $frameworkTables, true)) {
        continue;
    }
    $fkCols = array_column($foreignKeys[$t] ?? [], 'COLUMN_NAME');
    $ukCols = [];
    foreach ($indexes[$t] ?? [] as $name => $ix) {
        if ($ix['unique'] && $name !== 'PRIMARY') {
            array_push($ukCols, ...$ix['columns']);
        }
    }
    $md[] = "    $t {";
    foreach ($columns[$t] ?? [] as $c) {
        $name = $c['COLUMN_NAME'];
        $keys = [];
        if ($c['COLUMN_KEY'] === 'PRI') {
            $keys[] = 'PK';
        }
        if (in_array($name, $fkCols, true)) {
            $keys[] = 'FK';
        }
        if (in_array($name, $ukCols, true)) {
            $keys[] = 'UK';
        }
        if ($keys === [] && $name !== 'status') {
            continue;
        }
        $type = isset($jsonColumns[$t][$name]) ? 'json' : preg_replace('/[^a-z_]/', '', strtolower($c['DATA_TYPE']));
        $comment = $c['GENERATION_EXPRESSION'] !== null && $c['GENERATION_EXPRESSION'] !== '' ? ' "generata"' : '';
        $md[] = "        $type $name".($keys ? ' '.implode(',', $keys) : '').$comment;
    }
    $md[] = '    }';
}
foreach ($tables as $t) {
    if (in_array($t, $frameworkTables, true)) {
        continue;
    }
    foreach ($foreignKeys[$t] ?? [] as $fk) {
        if (in_array($fk['REFERENCED_TABLE_NAME'], $frameworkTables, true)) {
            continue;
        }
        $col = colInfo($columns[$t], $fk['COLUMN_NAME']);
        $parent = $col['IS_NULLABLE'] === 'YES' ? '|o' : '||';
        $child = soleUnique($indexes[$t] ?? [], $fk['COLUMN_NAME']) ? 'o|' : 'o{';
        $md[] = "    {$fk['REFERENCED_TABLE_NAME']} $parent--$child $t : \"{$fk['COLUMN_NAME']}\"";
    }
}
$md[] = '```';
$md[] = '';

// Dizionario --------------------------------------------------------------------------------------------------------
$md[] = '## Dizionario dati';
$md[] = '';
foreach ($tables as $t) {
    $md[] = "### `$t`";
    $md[] = '';
    $md[] = cell($descriptions[$t] ?? '(descrizione mancante)');
    $md[] = '';
    $md[] = '| Colonna | Tipo | Null | Default | Note |';
    $md[] = '|---|---|---|---|---|';
    foreach ($columns[$t] ?? [] as $c) {
        $notes = [];
        if ($c['COLUMN_KEY'] === 'PRI') {
            $notes[] = 'PK';
        }
        if (str_contains($c['EXTRA'], 'auto_increment')) {
            $notes[] = 'auto_increment';
        }
        if ($c['GENERATION_EXPRESSION'] !== null && $c['GENERATION_EXPRESSION'] !== '') {
            $kind = stripos($c['EXTRA'], 'stored') !== false || stripos($c['EXTRA'], 'persistent') !== false ? 'stored' : 'virtual';
            $notes[] = "generata ($kind): `".code($c['GENERATION_EXPRESSION']).'`';
        }
        if (stripos($c['EXTRA'], 'on update') !== false) {
            $notes[] = cell($c['EXTRA']);
        }
        if ($c['COLUMN_COMMENT'] !== '') {
            $notes[] = cell($c['COLUMN_COMMENT']);
        }
        $type = isset($jsonColumns[$t][$c['COLUMN_NAME']]) ? 'json ('.$c['COLUMN_TYPE'].')' : $c['COLUMN_TYPE'];
        $default = $c['COLUMN_DEFAULT'];
        $default = ($default === null || strtoupper($default) === 'NULL') ? '' : '`'.code($default).'`';
        $md[] = '| `'.$c['COLUMN_NAME'].'` | '.cell($type).' | '.($c['IS_NULLABLE'] === 'YES' ? 'sì' : 'no').' | '.$default.' | '.implode('; ', $notes).' |';
    }
    $md[] = '';

    $unique = array_filter($indexes[$t] ?? [], fn ($ix, $name) => $ix['unique'] && $name !== 'PRIMARY', ARRAY_FILTER_USE_BOTH);
    $plain = array_filter($indexes[$t] ?? [], fn ($ix) => ! $ix['unique']);
    if (isset($indexes[$t]['PRIMARY'])) {
        $md[] = '- **Chiave primaria:** `'.implode('`, `', $indexes[$t]['PRIMARY']['columns']).'`';
    }
    foreach ($unique as $name => $ix) {
        $md[] = "- **Unico** `$name`: `".implode('`, `', $ix['columns']).'`';
    }
    if ($plain) {
        $md[] = '- **Indici:** '.implode('; ', array_map(
            fn ($name, $ix) => "`$name` (".implode(', ', $ix['columns']).')',
            array_keys($plain), $plain,
        ));
    }
    foreach ($foreignKeys[$t] ?? [] as $fk) {
        $md[] = "- **FK** `{$fk['COLUMN_NAME']}` → `{$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}`"
            ." ON DELETE {$fk['DELETE_RULE']}, ON UPDATE {$fk['UPDATE_RULE']} (`{$fk['CONSTRAINT_NAME']}`)";
    }
    foreach ($checks[$t] ?? [] as $ck) {
        if (isset($jsonColumns[$t][$ck['CONSTRAINT_NAME']])) {
            continue;
        }
        $md[] = "- **CHECK** `{$ck['CONSTRAINT_NAME']}`: `".code($ck['CHECK_CLAUSE']).'`';
    }
    foreach ($triggers[$t] ?? [] as $tr) {
        $md[] = "- **Trigger** `{$tr['TRIGGER_NAME']}` ({$tr['ACTION_TIMING']} {$tr['EVENT_MANIPULATION']})";
    }
    foreach ($triggers[$t] ?? [] as $tr) {
        $md[] = '';
        $md[] = "<details><summary>Corpo di <code>{$tr['TRIGGER_NAME']}</code></summary>";
        $md[] = '';
        $md[] = '```sql';
        $md[] = rtrim(str_replace("\r", '', $tr['ACTION_STATEMENT']));
        $md[] = '```';
        $md[] = '';
        $md[] = '</details>';
    }
    $md[] = '';
}

if (! is_dir(dirname($outFile)) && ! mkdir(dirname($outFile), 0775, true)) {
    fwrite(STDERR, "Cartella di destinazione non creata: ".dirname($outFile)."\n");
    exit(1);
}
file_put_contents($outFile, implode("\n", $md));
fwrite(STDOUT, 'Scritto '.$outFile.': '.count($tables).' tabelle, '.array_sum(array_map('count', $triggers))." trigger.\n");
if ($missing) {
    fwrite(STDERR, 'Attenzione, tabelle senza descrizione in descrizioni.php: '.implode(', ', $missing)."\n");
}

// Matrice ruolo × permesso in docs/tecnico/permessi.md, tra i due segnaposto, dalle tabelle di spatie/laravel-permission
// (dopo `db:seed`); i significati dei permessi da App\Enums\StaffRole se vendor/ è installato.
$permFile = dirname($outFile).'/permessi.md';
$begin = '<!-- matrice-permessi:inizio -->';
$end = '<!-- matrice-permessi:fine -->';
if (in_array('role_has_permissions', $tables, true) && is_file($permFile) && str_contains((string) file_get_contents($permFile), $begin)) {
    $labels = [];
    if (is_file($root.'/sito/vendor/autoload.php')) {
        require $root.'/sito/vendor/autoload.php';
        if (enum_exists(\App\Enums\StaffRole::class)) {
            $labels = \App\Enums\StaffRole::allPermissions();
        }
    }
    $roles = array_column($q("SELECT id, name FROM roles WHERE guard_name = 'web' ORDER BY id"), 'name', 'id');
    $perms = array_column($q("SELECT id, name FROM permissions WHERE guard_name = 'web' ORDER BY name"), 'name', 'id');
    $grants = [];
    foreach ($q('SELECT role_id, permission_id FROM role_has_permissions') as $r) {
        $grants[$r['role_id']][$r['permission_id']] = true;
    }
    $order = array_keys($labels);
    $pos = fn (string $name) => ($i = array_search($name, $order, true)) === false ? PHP_INT_MAX : $i;
    uasort($perms, fn ($a, $b) => $pos($a) <=> $pos($b) ?: strcmp($a, $b));
    $block = [$begin, '', '| Permesso | Significato | '.implode(' | ', $roles).' |', '|---|---|'.str_repeat('---|', count($roles))];
    foreach ($perms as $pid => $pname) {
        $row = '| `'.$pname.'` | '.cell($labels[$pname] ?? '').' |';
        foreach (array_keys($roles) as $rid) {
            $row .= isset($grants[$rid][$pid]) ? ' ✔ |' : ' — |';
        }
        $block[] = $row;
    }
    $block[] = '';
    $block[] = 'Generata il '.date('d/m/Y').' da `strumenti/genera-doc-db/genera.php` sul database `'.cell($schema).'` ('.count($perms).' permessi).';
    $block[] = '';
    $block[] = $end;
    $text = (string) file_get_contents($permFile);
    $text = substr($text, 0, strpos($text, $begin)).implode("\n", $block).substr($text, strpos($text, $end) + strlen($end));
    file_put_contents($permFile, $text);
    fwrite(STDOUT, 'Aggiornata la matrice dei permessi in '.$permFile.': '.count($roles).' ruoli, '.count($perms)." permessi.\n");
}
exit(0);

// ---------------------------------------------------------------------------------------------------------------

function resolvePath(string $path, string $root): string
{
    return str_starts_with($path, '/') ? $path : $root.'/'.$path;
}

/** @return array<string, string> */
function dbConfig(string $envFile): array
{
    $file = [];
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^\s*(?:export\s+)?(DB_[A-Z_]+)\s*=\s*(.*)$/', $line, $m)) {
                $value = trim($m[2]);
                if (preg_match('/^"(.*)"$/', $value, $v) || preg_match("/^'(.*)'$/", $value, $v)) {
                    $value = $v[1];
                } else {
                    $value = trim(preg_replace('/\s+#.*$/', '', $value));
                }
                $file[$m[1]] = $value;
            }
        }
    }
    $defaults = ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => '', 'DB_USERNAME' => '', 'DB_PASSWORD' => '', 'DB_SOCKET' => ''];
    $config = [];
    foreach ($defaults as $key => $default) {
        $env = getenv($key);
        $config[$key] = $env !== false ? $env : ($file[$key] ?? $default);
    }
    if ($config['DB_DATABASE'] === '' || $config['DB_USERNAME'] === '') {
        fwrite(STDERR, "Mancano DB_DATABASE o DB_USERNAME (variabili d'ambiente o $envFile).\n");
        exit(1);
    }

    return $config;
}

function groupBy(array $rows, string $key): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[$r[$key]][] = $r;
    }

    return $out;
}

function colInfo(array $cols, string $name): array
{
    foreach ($cols as $c) {
        if ($c['COLUMN_NAME'] === $name) {
            return $c;
        }
    }

    return ['IS_NULLABLE' => 'NO'];
}

/** La colonna da sola forma un indice unico (o la chiave primaria): relazione uno a uno. */
function soleUnique(array $tableIndexes, string $column): bool
{
    foreach ($tableIndexes as $ix) {
        if ($ix['unique'] && $ix['columns'] === [$column]) {
            return true;
        }
    }

    return false;
}

function cell(string $s): string
{
    return str_replace(['|', "\n", "\r"], ['\|', ' ', ''], $s);
}

/** Testo per uno span di codice in una cella: senza backtick, a capo e barre verticali. */
function code(string $s): string
{
    return cell(preg_replace('/\s+/', ' ', str_replace('`', '', $s)));
}

function anchor(string $table): string
{
    return strtolower($table);  // ancora GitHub di «### `tabella`»
}
