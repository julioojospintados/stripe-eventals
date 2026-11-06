<?php
/*
 * verifica-hosting.php
 *
 * Controlla se l'hosting condiviso può ospitare il sito dell'EAS8 (Laravel 13 + Filament 5 su MySQL o MariaDB) e
 * stampa un rapporto con tre livelli: OK, ATTENZIONE, BLOCCANTE. In fondo c'è un riepilogo da incollare in un messaggio.
 *
 * Autore: Giulio Occhipinti. Versione 1.0 del 06/11/2026. Requisiti in docs/architettura.md §1, §3, §8 e §14.1.
 *
 * Uso, solo da riga di comando via SSH:
 *     php verifica-hosting.php
 *     php verifica-hosting.php --help
 * Da web risponde 403 e non stampa nulla.
 *
 * Credenziali del database: chieste a schermo (la password non si vede mentre si scrive) oppure lette dalle variabili
 * d'ambiente DB_HOST, DB_PORT, DB_SOCKET, DB_DATABASE, DB_USERNAME, DB_PASSWORD (gli stessi nomi del file .env di
 * Laravel). Host, porta, socket, database e utente si possono dare anche come argomenti; la password no. Non vengono
 * mai salvate né stampate.
 *
 * Cosa lascia sul server: niente. I file di prova (cartella .verifica-hosting-<caso>) e le tabelle e il trigger di
 * prova (prefisso zz_verifica_) si cancellano alla fine, anche se lo script viene interrotto con Ctrl-C; se resta
 * qualcosa da un giro interrotto in modo brusco, il giro successivo lo trova e lo cancella.
 *
 * Collegamenti verso l'esterno: due richieste HTTPS di prova senza dati né chiavi (api.stripe.com e
 * us1.api.mailchimp.com) per vedere se il server le raggiunge. Si saltano con --senza-rete.
 *
 * Sintassi compatibile con PHP 5.6 e successivi, così anche un PHP vecchio stampa un rapporto invece di un errore.
 */

if (PHP_SAPI !== 'cli' || isset($_SERVER['REQUEST_METHOD']) || isset($_SERVER['GATEWAY_INTERFACE'])) {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Length: 0');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
    }
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');

define('VH_VERSIONE', '1.0');
define('VH_PHP_MINIMO', '8.3.0');
define('VH_PHP_CONSIGLIATO', '8.4.0');
define('VH_PREFISSO', 'zz_verifica_');

$GLOBALS['VH_ESITI'] = array();
$GLOBALS['VH_FATTI'] = array();
$GLOBALS['VH_PULIZIA'] = array();
$GLOBALS['VH_STTY_SPENTO'] = false;
$GLOBALS['VH_SECONDO_COLLEGAMENTO'] = null;
$GLOBALS['VH_COLORI'] = vh_tty(STDOUT) && getenv('NO_COLOR') === false;

register_shutdown_function('vh_alla_fine');
if (function_exists('pcntl_async_signals') && vh_funzione('pcntl_signal') && defined('SIGINT')) {
    pcntl_async_signals(true);
    $interrompi = function () {
        fwrite(STDOUT, "\n\nInterrotto: cancello quello che ho creato e mi fermo.\n");
        exit(130);
    };
    pcntl_signal(SIGINT, $interrompi);
    pcntl_signal(SIGTERM, $interrompi);
}

vh_principale($argv);

/* ------------------------------------------------------------------------------------------------------------------
 * Programma principale
 * ---------------------------------------------------------------------------------------------------------------- */

function vh_principale($argv)
{
    $opzioni = vh_opzioni();
    if (isset($opzioni['help']) || isset($opzioni['aiuto'])) {
        vh_aiuto();
        exit(0);
    }
    if (isset($opzioni['password'])) {
        fwrite(STDERR, "Per sicurezza la password non si passa come argomento: resterebbe nella cronologia della shell\n"
            . "e altri utenti del server potrebbero vederla. Lancia lo script senza --password e scrivila quando\n"
            . "te la chiede (non si vede mentre scrivi), oppure usa la variabile d'ambiente DB_PASSWORD.\n");
        exit(64);
    }

    $db = vh_credenziali($opzioni);

    vh_titolo();
    vh_controlla_php();
    vh_controlla_estensioni();
    vh_controlla_limiti();
    vh_controlla_funzioni();
    vh_controlla_programmi();
    vh_controlla_disco();
    if (isset($opzioni['senza-rete'])) {
        vh_sezione('7. Collegamenti verso l\'esterno');
        vh_esito('rete', 'INFO', 'Collegamenti verso l\'esterno non controllati (--senza-rete)');
    } else {
        vh_controlla_rete();
    }
    vh_controlla_database($db);
    vh_pulisci();
    vh_da_controllare_nel_pannello();
    $codice = vh_riepilogo();
    exit($codice);
}

function vh_opzioni()
{
    $lunghe = array('help', 'aiuto', 'host:', 'port:', 'socket:', 'database:', 'user:', 'password::',
        'senza-database', 'senza-rete');
    $o = getopt('h', $lunghe);
    if ($o === false) {
        $o = array();
    }
    if (isset($o['h'])) {
        $o['help'] = true;
    }

    return $o;
}

function vh_aiuto()
{
    echo "Verifica dell'hosting per il sito EAS8 (Laravel 13 + Filament 5, MySQL o MariaDB). Versione " . VH_VERSIONE . ".\n\n"
        . "Uso:  php verifica-hosting.php [opzioni]\n\n"
        . "Senza opzioni lo script chiede a schermo i dati del database (la password non si vede).\n\n"
        . "Opzioni:\n"
        . "  --host=NOME        server del database (predefinito: localhost)\n"
        . "  --port=NUMERO      porta del database (predefinita: 3306)\n"
        . "  --socket=PERCORSO  socket del database, al posto di host e porta\n"
        . "  --database=NOME    nome del database (quello vuoto creato per il sito)\n"
        . "  --user=NOME        utente del database\n"
        . "  --senza-database   salta le prove sul database\n"
        . "  --senza-rete       salta le due richieste HTTPS di prova a Stripe e Mailchimp\n"
        . "  --help             questo aiuto\n\n"
        . "Variabili d'ambiente (alternative alle opzioni): DB_HOST, DB_PORT, DB_SOCKET, DB_DATABASE, DB_USERNAME,\n"
        . "DB_PASSWORD. La password non si passa mai come argomento.\n\n"
        . "Codice di uscita: 0 tutto OK, 1 qualche ATTENZIONE, 2 almeno un BLOCCANTE.\n";
}

/* ------------------------------------------------------------------------------------------------------------------
 * Credenziali del database (mai salvate, mai stampate)
 * ---------------------------------------------------------------------------------------------------------------- */

function vh_credenziali($o)
{
    if (isset($o['senza-database'])) {
        return array('salta' => 'senza-database');
    }

    $c = array(
        'host' => vh_valore($o, 'host', 'DB_HOST'),
        'port' => vh_valore($o, 'port', 'DB_PORT'),
        'socket' => vh_valore($o, 'socket', 'DB_SOCKET'),
        'database' => vh_valore($o, 'database', 'DB_DATABASE'),
        'user' => vh_valore($o, 'user', 'DB_USERNAME'),
        'password' => getenv('DB_PASSWORD') === false ? null : (string) getenv('DB_PASSWORD'),
    );

    $interattivo = vh_tty(STDIN);
    $mancaQualcosa = $c['database'] === null || $c['user'] === null || $c['password'] === null;

    if ($mancaQualcosa && !$interattivo) {
        if ($c['database'] !== null && $c['user'] !== null && $c['password'] === null) {
            // Senza terminale (es. dentro uno script) la password si legge dalla prima riga dell'input.
            $riga = fgets(STDIN);
            if ($riga !== false) {
                $c['password'] = rtrim($riga, "\r\n");

                return vh_completa_credenziali($c);
            }
        }

        return array('salta' => 'non-interattivo');
    }

    if ($mancaQualcosa) {
        echo "\nPrima di cominciare: i dati del database.\n"
            . "Li trovi nel pannello dell'hosting, nella sezione MySQL o MariaDB (usa il database vuoto creato per il sito).\n"
            . "Non vengono salvati da nessuna parte. Premi Invio sul nome del database per saltare questa parte.\n\n";
        if ($c['host'] === null && $c['socket'] === null) {
            $h = vh_chiedi('  Server del database [localhost]: ');
            $c['host'] = $h === '' ? 'localhost' : $h;
        }
        if ($c['database'] === null) {
            $c['database'] = vh_chiedi('  Nome del database: ');
            if ($c['database'] === '') {
                return array('salta' => 'scelta');
            }
        }
        if ($c['user'] === null) {
            $c['user'] = vh_chiedi('  Utente del database: ');
        }
        if ($c['password'] === null) {
            $c['password'] = vh_chiedi_nascosto('  Password (non si vede mentre scrivi): ');
        }
        echo "\n";
    }

    return vh_completa_credenziali($c);
}

function vh_completa_credenziali($c)
{
    if ($c['host'] === null && $c['socket'] === null) {
        $c['host'] = 'localhost';
    }
    if ($c['port'] === null || $c['port'] === '') {
        $c['port'] = '3306';
    }

    return $c;
}

function vh_valore($o, $opzione, $variabile)
{
    if (isset($o[$opzione]) && is_string($o[$opzione]) && $o[$opzione] !== '') {
        return $o[$opzione];
    }
    $v = getenv($variabile);

    return ($v === false || $v === '') ? null : $v;
}

function vh_chiedi($domanda)
{
    fwrite(STDOUT, $domanda);
    $riga = fgets(STDIN);

    return $riga === false ? '' : trim($riga);
}

function vh_chiedi_nascosto($domanda)
{
    fwrite(STDOUT, $domanda);
    $nascosto = false;
    if (vh_funzione('shell_exec')) {
        $r = @shell_exec('stty -echo 2>/dev/null && echo spento');
        $nascosto = is_string($r) && trim($r) === 'spento';
    }
    $GLOBALS['VH_STTY_SPENTO'] = $nascosto;
    if (!$nascosto) {
        fwrite(STDOUT, "\n  (Attenzione: su questo server la password resta visibile mentre la scrivi.)\n  Password: ");
    }
    $riga = fgets(STDIN);
    if ($nascosto) {
        @shell_exec('stty echo 2>/dev/null');
        $GLOBALS['VH_STTY_SPENTO'] = false;
        fwrite(STDOUT, "\n");
    }

    return $riga === false ? '' : rtrim($riga, "\r\n");
}

/* ------------------------------------------------------------------------------------------------------------------
 * 1. PHP
 * ---------------------------------------------------------------------------------------------------------------- */

function vh_controlla_php()
{
    vh_sezione('1. PHP');
    $v = PHP_VERSION;
    $alternative = vh_php_alternativi();
    $suggerimento = '';
    if ($alternative) {
        $suggerimento = ' Sul server ci sono anche: ' . implode(', ', $alternative)
            . '. Se una è 8.4, usala per Composer e per il cron al posto di «php».';
    }

    if (version_compare($v, VH_PHP_MINIMO, '<')) {
        vh_esito('php.versione', 'BLOCCANTE', "PHP $v da riga di comando: troppo vecchio (serve almeno 8.3, meglio 8.4)",
            'Laravel 13 chiede PHP 8.3 o più recente; Filament 5 gira sopra Laravel 13.',
            'Nel pannello scegli PHP 8.4 (o almeno 8.3) per il sito e per la riga di comando, poi rilancia lo script.'
            . $suggerimento);
    } elseif (version_compare($v, VH_PHP_CONSIGLIATO, '<')) {
        vh_esito('php.versione', 'ATTENZIONE', "PHP $v da riga di comando: va bene, ma meglio 8.4",
            'Laravel 13 e Filament 5 funzionano con 8.3; alcune librerie nelle ultime versioni chiedono già 8.4.',
            'Se il pannello offre PHP 8.4, sceglilo per il sito e per la riga di comando.' . $suggerimento);
    } else {
        vh_esito('php.versione', 'OK', "PHP $v da riga di comando (serve almeno 8.3, consigliato 8.4)");
    }

    if (PHP_INT_SIZE < 8) {
        vh_esito('php.64bit', 'BLOCCANTE', 'PHP a 32 bit', 'Date dopo il 2038 e numeri grandi non funzionano bene.',
            'Chiedi all\'hosting un PHP a 64 bit.');
    }

    $ini = function_exists('php_ini_loaded_file') ? php_ini_loaded_file() : false;
    vh_fatto('php.binario', PHP_BINARY !== '' ? PHP_BINARY : '(sconosciuto)');
    vh_esito('php.dove', 'INFO', 'Comando PHP usato: ' . (PHP_BINARY !== '' ? PHP_BINARY : '(sconosciuto)'),
        'File di configurazione: ' . ($ini ? $ini : 'nessuno') . '. La versione PHP del sito web può essere diversa '
        . 'da quella della riga di comando: va controllata nel pannello.');
}

function vh_php_alternativi()
{
    $trovati = array();
    $nomi = array('php8.5', 'php85', 'php8.4', 'php84', 'php8.3', 'php83', 'php-8.4', 'php-8.3');
    foreach ($nomi as $n) {
        $p = vh_trova_programma($n);
        if ($p !== null) {
            $trovati[$p] = true;
        }
    }
    $schemi = array('/opt/cpanel/ea-php8*/root/usr/bin/php', '/opt/alt/php8*/usr/bin/php', '/opt/plesk/php/8.*/bin/php',
        '/usr/local/php8*/bin/php', '/usr/local/bin/php8*', '/usr/bin/php8*');
    foreach ($schemi as $s) {
        $g = @glob($s);
        if (is_array($g)) {
            foreach ($g as $p) {
                if (@is_file($p) && @is_executable($p)) {
                    $trovati[$p] = true;
                }
            }
        }
    }
    $reale = @realpath(PHP_BINARY);
    $elenco = array();
    foreach (array_keys($trovati) as $p) {
        if ($p === PHP_BINARY || ($reale !== false && @realpath($p) === $reale)) {
            continue;
        }
        $elenco[] = $p;
        if (count($elenco) >= 6) {
            break;
        }
    }

    return $elenco;
}

/* ------------------------------------------------------------------------------------------------------------------
 * 2. Estensioni
 * ---------------------------------------------------------------------------------------------------------------- */

function vh_controlla_estensioni()
{
    vh_sezione('2. Estensioni PHP');
    $necessarie = array(
        'ctype' => 'Laravel',
        'curl' => 'chiamate a Stripe e Mailchimp',
        'dom' => 'Laravel e Filament',
        'fileinfo' => 'riconoscere il tipo dei file caricati (foto, import)',
        'filter' => 'Laravel (controllo dei dati)',
        'hash' => 'Laravel (firme e sessioni)',
        'intl' => 'date, numeri e prezzi in inglese e italiano; Filament',
        'json' => 'Laravel',
        'mbstring' => 'testi con accenti',
        'openssl' => 'HTTPS e cifratura',
        'pcre' => 'Laravel',
        'pdo_mysql' => 'collegamento al database',
        'session' => 'Laravel (sessioni)',
        'sodium' => 'firma dei QR dei biglietti',
        'tokenizer' => 'Laravel',
        'xml' => 'Laravel e Composer',
        'xmlreader' => 'import da Excel',
        'xmlwriter' => 'export in Excel',
        'zip' => 'export e import in Excel, Composer',
    );
    $mancanti = array();
    foreach ($necessarie as $e => $perche) {
        if (!extension_loaded($e)) {
            $mancanti[] = $e;
            vh_esito('ext.' . $e, 'BLOCCANTE', "Estensione $e mancante", "Serve per: $perche.",
                "Attivala nel pannello (sezione PHP, «estensioni» o «moduli»); se non c'è, chiedila all'hosting.");
        }
    }
    if (!$mancanti) {
        vh_esito('ext.necessarie', 'OK', 'Estensioni necessarie: tutte presenti (' . count($necessarie) . ')',
            implode(', ', array_keys($necessarie)));
    }
    if (extension_loaded('bcmath')) {
        vh_esito('ext.bcmath', 'OK', 'Estensione bcmath (consigliata): presente');
    } else {
        vh_esito('ext.bcmath', 'ATTENZIONE', 'Estensione bcmath (consigliata): assente',
            'Laravel e Filament funzionano anche senza (è un «suggerimento» delle loro librerie): con bcmath alcuni '
            . 'calcoli sui numeri grandi sono più veloci.', 'Se il pannello la offre, attivala; altrimenti va bene così.');
    }

    $gd = extension_loaded('gd') && function_exists('imagepng');
    $imagick = extension_loaded('imagick');
    if ($gd || $imagick) {
        vh_esito('ext.immagini', 'OK', 'Immagini: ' . trim(($gd ? 'gd ' : '') . ($imagick ? 'imagick' : '')),
            'Servono per l\'immagine del QR nelle email e per le foto degli speaker.');
    } else {
        vh_esito('ext.immagini', 'BLOCCANTE', 'Immagini: né gd né imagick',
            'Senza, il QR dei biglietti non si può mettere nelle email come immagine e le foto degli speaker non si '
            . 'possono ridimensionare.', 'Attiva gd (o imagick) nel pannello.');
    }

    if (defined('PASSWORD_ARGON2ID')) {
        vh_esito('php.argon2id', 'OK', 'Password con argon2id disponibili', 'Per le password dello staff.');
    } else {
        vh_esito('php.argon2id', 'BLOCCANTE', 'Password con argon2id non disponibili',
            'Le password dello staff vanno salvate con argon2id (PHP compilato con libargon2 o con sodium).',
            'Chiedi all\'hosting un PHP con il supporto argon2 (quasi tutti i PHP 8 recenti lo hanno).');
    }

    $pcntl = extension_loaded('pcntl') && vh_funzione('pcntl_signal') && vh_funzione('pcntl_alarm');
    if ($pcntl) {
        vh_esito('ext.pcntl', 'OK', 'pcntl da riga di comando: presente', 'Serve per fermare un lavoro in coda che si blocca.');
    } else {
        vh_esito('ext.pcntl', 'ATTENZIONE', 'pcntl da riga di comando: assente o disattivata',
            'Senza, un lavoro in coda bloccato non si ferma da solo dopo un tempo massimo: si scrivono lavori brevi.',
            'Se il pannello la offre per la riga di comando, attivala; altrimenti va bene così.');
    }
}

/* ------------------------------------------------------------------------------------------------------------------
 * 3. Limiti
 * ---------------------------------------------------------------------------------------------------------------- */

function vh_controlla_limiti()
{
    vh_sezione('3. Limiti di PHP (letti da riga di comando)');

    $memoria = (string) ini_get('memory_limit');
    $byte = vh_byte($memoria);
    $alzabile = false;
    if ($byte !== -1 && $byte < 512 * 1048576 && vh_funzione('ini_set')) {
        $prima = ini_get('memory_limit');
        $alzabile = @ini_set('memory_limit', '512M') !== false && vh_byte((string) ini_get('memory_limit')) >= 512 * 1048576;
        @ini_set('memory_limit', $prima);
    }
    if ($byte === -1 || $byte >= 512 * 1048576) {
        vh_esito('limiti.memoria', 'OK', "Memoria per PHP da riga di comando: $memoria (servono 512M)");
    } else {
        vh_esito('limiti.memoria', 'ATTENZIONE', "Memoria per PHP da riga di comando: $memoria (servono 512M)",
            $alzabile ? 'Si può alzare nel comando stesso (php -d memory_limit=512M), e lo script l\'ha verificato.'
                : 'Lo script non è riuscito ad alzarla da solo.',
            $alzabile ? 'Nessuna azione: il cron userà «php -d memory_limit=512M».'
                : 'Nel pannello porta memory_limit a 512M per la riga di comando e almeno 256M per il sito.');
    }

    vh_esito('limiti.tempo', 'INFO', 'Tempo massimo da riga di comando: ' . ((int) ini_get('max_execution_time') === 0 ? 'nessun limite (normale)' : ini_get('max_execution_time') . ' s'),
        'Per il sito servono almeno 60 secondi (max_execution_time): quel valore si vede nel pannello, non da qui.');

    $upload = (string) ini_get('upload_max_filesize');
    $post = (string) ini_get('post_max_size');
    $minimo = 20 * 1048576;
    $ub = vh_byte($upload);
    $pb = vh_byte($post);
    if (($ub === -1 || $ub >= $minimo) && ($pb === -1 || $pb === 0 || $pb >= $minimo)) {
        vh_esito('limiti.upload', 'OK', "Caricamento file: upload_max_filesize $upload, post_max_size $post (servono 20M)",
            'Valori letti da riga di comando: di solito il sito ha gli stessi, ma conviene guardarli nel pannello.');
    } else {
        vh_esito('limiti.upload', 'ATTENZIONE', "Caricamento file: upload_max_filesize $upload, post_max_size $post (servono 20M)",
            'Servono per caricare le foto degli speaker e i fogli del programma. Valori letti da riga di comando.',
            'Nel pannello porta upload_max_filesize e post_max_size ad almeno 20M per il sito.');
    }

    $basedir = (string) ini_get('open_basedir');
    if ($basedir !== '') {
        vh_esito('limiti.open_basedir', 'ATTENZIONE', 'open_basedir attivo: PHP vede solo alcune cartelle',
            "Cartelle ammesse: $basedir", 'Nessuna, se la cartella del progetto e /tmp sono comprese; altrimenti chiedi di aggiungerle.');
    }
}

/* ------------------------------------------------------------------------------------------------------------------
 * 4. Funzioni disattivate
 * ---------------------------------------------------------------------------------------------------------------- */

function vh_controlla_funzioni()
{
    vh_sezione('4. Funzioni di PHP disattivate dall\'hosting');
    $elenco = (string) ini_get('disable_functions');
    vh_fatto('php.disable_functions', $elenco === '' ? '(nessuna)' : $elenco);

    $processi = array('proc_open', 'proc_get_status', 'proc_close', 'proc_terminate');
    $mancano = vh_mancanti($processi);
    if (!$mancano) {
        vh_esito('funzioni.processi', 'OK', 'Avvio di altri programmi (proc_open): permesso',
            'Serve a Composer e allo scheduler di Laravel, che dal cron avvia la coda dei lavori.');
    } else {
        vh_esito('funzioni.processi', 'ATTENZIONE', 'Avvio di altri programmi disattivato: ' . implode(', ', $mancano),
            'Composer non gira sul server e lo scheduler di Laravel non può avviare la coda.',
            'Si lavora lo stesso: dipendenze preparate fuori e caricate, e una riga di cron per ogni comando. '
            . 'Se l\'hosting può riattivare proc_open per la riga di comando, è meglio.');
    }

    $altre = array(
        'exec' => 'alcune librerie lo usano al posto di proc_open',
        'shell_exec' => 'alcune librerie lo usano al posto di proc_open',
        'symlink' => 'deploy con il cambio di versione istantaneo e link pubblico delle foto (storage:link)',
        'putenv' => 'Composer e alcune configurazioni',
        'set_time_limit' => 'export e import lunghi',
        'ini_set' => 'Laravel cambia alcune impostazioni da solo',
        'pcntl_signal' => 'fermare un lavoro in coda bloccato',
        'pcntl_alarm' => 'fermare un lavoro in coda bloccato',
        'escapeshellarg' => 'backup del database con mysqldump',
    );
    $spente = array();
    foreach ($altre as $f => $perche) {
        if (vh_in_disabilitate($f)) {
            $spente[] = "$f ($perche)";
        }
    }
    if (!$spente) {
        vh_esito('funzioni.altre', 'OK', 'Altre funzioni usate (exec, symlink, putenv, set_time_limit, ini_set...): permesse');
    } else {
        vh_esito('funzioni.altre', 'ATTENZIONE', 'Altre funzioni disattivate: ' . count($spente),
            implode('; ', $spente) . '.',
            'Non bloccano, ma cambiano il modo di installare e di lavorare: mandaci il riepilogo, decidiamo noi.');
    }
}

/* ------------------------------------------------------------------------------------------------------------------
 * 5. Programmi sul server: composer, git, rsync, mysqldump, crontab
 * ---------------------------------------------------------------------------------------------------------------- */

function vh_controlla_programmi()
{
    vh_sezione('5. Programmi disponibili da SSH');
    $puoEseguire = vh_puo_eseguire();
    $home = getenv('HOME');
    $extra = $home ? array($home . '/bin', $home . '/.local/bin', $home . '/.config/composer/vendor/bin', $home) : array();
    $extra[] = '/opt/cpanel/composer/bin';

    // Composer
    $composer = null;
    foreach (array('composer', 'composer2', 'composer.phar') as $n) {
        $composer = vh_trova_programma($n, $extra);
        if ($composer !== null) {
            break;
        }
    }
    $phar = null;
    if ($composer === null) {
        foreach (array_merge($home ? array($home . '/composer.phar') : array(), array(__DIR__ . '/composer.phar')) as $p) {
            if (@is_file($p)) {
                $phar = $p;
                break;
            }
        }
    }
    if ($composer === null && $phar === null) {
        vh_esito('programmi.composer', 'ATTENZIONE', 'Composer non trovato',
            'Composer installa le librerie di Laravel. Non è indispensabile sul server: si possono preparare fuori e caricare.',
            'Se il pannello offre Composer, attivalo; altrimenti va bene così.');
    } elseif (!$puoEseguire) {
        vh_esito('programmi.composer', 'ATTENZIONE', 'Composer trovato ma non avviabile da PHP: ' . ($composer ? $composer : $phar),
            'Le funzioni per avviare programmi sono disattivate (sezione 4).');
    } else {
        $cmd = $composer !== null ? vh_q($composer) : vh_q(PHP_BINARY) . ' ' . vh_q($phar);
        $out = vh_esegui('COMPOSER_ALLOW_SUPERUSER=1 ' . $cmd . ' --version --no-interaction --no-ansi', $c, 30);
        if ($out !== null && preg_match('/Composer (?:version )?(\d+)\.(\d+)\.(\d+)/', $out, $m)) {
            $ver = "$m[1].$m[2].$m[3]";
            vh_fatto('composer', $ver);
            if ((int) $m[1] >= 2) {
                vh_esito('programmi.composer', 'OK', "Composer $ver", $composer !== null ? $composer : $phar);
            } else {
                vh_esito('programmi.composer', 'ATTENZIONE', "Composer $ver: troppo vecchio (serve la 2)",
                    '', 'Aggiorna con «composer self-update --2» o carica composer.phar della versione 2.');
            }
        } else {
            vh_esito('programmi.composer', 'ATTENZIONE', 'Composer trovato ma non risponde come previsto',
                vh_breve((string) $out), 'Prova a mano «composer --version» e mandaci cosa scrive.');
        }
    }

    // git, rsync, mysqldump
    $programmi = array(
        'git' => array('--version', '/git version (\S+)/', 'INFO', 'Non indispensabile: il deploy copia i file già pronti.'),
        'rsync' => array('--version', '/rsync\s+version\s+v?(\S+)/', 'ATTENZIONE', 'Serve al deploy per copiare i file via SSH; senza, si usa SFTP (più lento).'),
        'mysqldump' => array('--version', '/(?:Ver|from)\s+(\S+)/', 'ATTENZIONE', 'Serve ai backup orari del database durante le vendite.'),
    );
    foreach ($programmi as $nome => $d) {
        $percorso = vh_trova_programma($nome, $extra);
        if ($percorso === null && $nome === 'mysqldump') {
            $percorso = vh_trova_programma('mariadb-dump', $extra);
        }
        if ($percorso === null) {
            vh_esito('programmi.' . $nome, $d[2], "$nome non trovato", $d[3]);
            continue;
        }
        $versione = '';
        if ($puoEseguire) {
            $out = vh_esegui(vh_q($percorso) . ' ' . $d[0], $c, 15);
            if ($out !== null && preg_match($d[1], $out, $m)) {
                $versione = ' ' . rtrim($m[1], ',');
            }
        }
        vh_fatto($nome, trim($versione) !== '' ? trim($versione) : 'presente');
        vh_esito('programmi.' . $nome, 'OK', "$nome$versione", $percorso);
    }

    // crontab
    $crontab = vh_trova_programma('crontab', array('/usr/bin', '/bin'));
    if ($crontab === null) {
        vh_esito('programmi.crontab', 'ATTENZIONE', 'Comando crontab non disponibile da SSH',
            'Il sito ha bisogno di un lavoro programmato ogni minuto (coda delle email, pagamenti, scadenze).',
            'Usa la sezione «Cron» del pannello e verifica che permetta di scegliere «ogni minuto».');
    } elseif (!$puoEseguire) {
        vh_esito('programmi.crontab', 'ATTENZIONE', 'crontab presente ma non provato (avvio di programmi disattivato)',
            '', 'Verifica nel pannello (sezione «Cron») che si possa impostare un lavoro ogni minuto.');
    } else {
        $out = (string) vh_esegui(vh_q($crontab) . ' -l', $c, 10);
        $basso = strtolower($out);
        if ($c === 0) {
            $righe = 0;
            foreach (preg_split('/\R/', $out) as $r) {
                $r = trim($r);
                if ($r !== '' && $r[0] !== '#' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*=/', $r)) {
                    $righe++;
                }
            }
            vh_esito('programmi.crontab', 'OK', "crontab utilizzabile ($righe righe attive)",
                'Il contenuto non viene stampato. Resta da verificare che l\'hosting permetta un lavoro ogni minuto.');
        } elseif (strpos($basso, 'no crontab') !== false) {
            vh_esito('programmi.crontab', 'OK', 'crontab utilizzabile (ancora vuoto)',
                'Resta da verificare che l\'hosting permetta un lavoro ogni minuto.');
        } elseif (strpos($basso, 'not allowed') !== false || strpos($basso, 'permission denied') !== false
            || strpos($basso, 'non è permesso') !== false) {
            vh_esito('programmi.crontab', 'ATTENZIONE', 'crontab non permesso a questo utente',
                vh_breve($out), 'Usa la sezione «Cron» del pannello e verifica che permetta «ogni minuto».');
        } else {
            vh_esito('programmi.crontab', 'ATTENZIONE', 'crontab risponde in modo inatteso (codice ' . var_export($c, true) . ')',
                vh_breve($out), 'Verifica nel pannello (sezione «Cron») che si possa impostare un lavoro ogni minuto.');
        }
    }

    vh_esito('server', 'INFO', 'Server: ' . vh_descrivi_server());
}

function vh_descrivi_server()
{
    $parti = array(php_uname('s') . ' ' . php_uname('r'));
    $pannelli = array('/usr/local/cpanel' => 'cPanel', '/usr/local/psa' => 'Plesk', '/usr/local/directadmin' => 'DirectAdmin',
        '/usr/local/hestia' => 'Hestia', '/usr/local/CyberCP' => 'CyberPanel');
    foreach ($pannelli as $p => $n) {
        if (@is_dir($p)) {
            $parti[] = "pannello $n";
        }
    }
    if (@is_file('/etc/cloudlinux-release') || stripos(php_uname('r'), 'lve') !== false || @is_file('/usr/sbin/cagefsctl')) {
        $parti[] = 'CloudLinux';
    }
    $cpu = @file_get_contents('/proc/cpuinfo');
    if (is_string($cpu) && $cpu !== '') {
        $parti[] = preg_match_all('/^processor\s*:/m', $cpu) . ' CPU visibili';
    }
    $nome = php_uname('n');
    if ($nome !== '') {
        $parti[] = "nome $nome";
    }
    $descrizione = implode(', ', $parti);
    vh_fatto('server', $descrizione);

    return $descrizione;
}

/* ------------------------------------------------------------------------------------------------------------------
 * 6. Disco
 * ---------------------------------------------------------------------------------------------------------------- */

function vh_controlla_disco()
{
    vh_sezione('6. Disco e cartella');
    $qui = __DIR__;

    if (preg_match('~/(public_html|www|htdocs|httpdocs|html|web|wwwroot)(/|$)~i', $qui)) {
        vh_esito('disco.cartella', 'ATTENZIONE', 'Lo script è in una cartella pubblicata sul web',
            "Cartella: $qui. Da web risponde 403 e non mostra nulla, ma è meglio non lasciarlo lì.",
            'Cancellalo appena finito (o la prossima volta mettilo nella cartella principale, fuori da quella del sito).');
    }

    $dir = $qui . '/.verifica-hosting-' . vh_casuale(4);
    $ok = @mkdir($dir, 0700);
    if (!$ok) {
        vh_esito('disco.scrittura', 'BLOCCANTE', 'Non riesco a scrivere nella cartella dello script', "Cartella: $qui",
            'Controlla i permessi della cartella, o lancia lo script dalla tua cartella principale.');
    } else {
        $GLOBALS['VH_PULIZIA']['disco'] = function () use ($dir) {
            foreach (array('collegamento', 'prova.txt', 'rinominato.txt') as $f) {
                if (@is_link("$dir/$f") || @is_file("$dir/$f")) {
                    @unlink("$dir/$f");
                }
            }
            @rmdir($dir);
        };
        $dati = str_repeat("Prova di scrittura àèìòù € \xF0\x9D\x84\x9E\n", 2048);
        $scritto = @file_put_contents("$dir/prova.txt", $dati);
        $letto = @file_get_contents("$dir/prova.txt");
        $rinominato = @rename("$dir/prova.txt", "$dir/rinominato.txt");
        if ($scritto === strlen($dati) && $letto === $dati && $rinominato) {
            vh_esito('disco.scrittura', 'OK', 'Scrittura, lettura e rinomina di file nella cartella: funzionano', $qui);
        } else {
            vh_esito('disco.scrittura', 'BLOCCANTE', 'Scrittura su disco: qualcosa non funziona',
                'Scrittura ' . ($scritto === strlen($dati) ? 'ok' : 'fallita') . ', lettura ' . ($letto === $dati ? 'ok' : 'diversa')
                . ', rinomina ' . ($rinominato ? 'ok' : 'fallita') . ".", 'Controlla i permessi e lo spazio (quota) del tuo account.');
        }

        $link = vh_funzione('symlink') && @symlink("$dir/rinominato.txt", "$dir/collegamento")
            && @is_link("$dir/collegamento") && @file_get_contents("$dir/collegamento") === $dati;
        if ($link) {
            vh_esito('disco.link', 'OK', 'Collegamenti simbolici: funzionano', 'Servono al deploy (cambio di versione in un istante) e alle foto pubbliche.');
        } else {
            vh_esito('disco.link', 'ATTENZIONE', 'Collegamenti simbolici: non funzionano',
                'Il deploy userà una cartella fissa con il sito in manutenzione per pochi secondi; le foto andranno copiate.',
                'Nessuna, salvo che l\'hosting possa permetterli.');
        }
        call_user_func($GLOBALS['VH_PULIZIA']['disco']);
        unset($GLOBALS['VH_PULIZIA']['disco']);
        if (@is_dir($dir)) {
            vh_esito('disco.pulizia', 'ATTENZIONE', 'Non sono riuscito a cancellare la cartella di prova', $dir, 'Cancellala a mano.');
        }
    }

    $libero = vh_funzione('disk_free_space') ? @disk_free_space($qui) : false;
    if ($libero === false || $libero === null) {
        vh_esito('disco.spazio', 'INFO', 'Spazio libero: non leggibile da qui', 'Controlla nel pannello: servono almeno 5 GB.');
    } else {
        $gb = $libero / 1073741824;
        $testo = sprintf('Spazio libero sul disco: %.1f GB (servono 5 GB)', $gb);
        $nota = 'Su un hosting condiviso conta anche la quota del tuo piano, che si vede nel pannello.';
        if ($gb >= 5) {
            vh_esito('disco.spazio', 'OK', $testo, $nota);
        } elseif ($gb >= 1) {
            vh_esito('disco.spazio', 'ATTENZIONE', $testo, $nota, 'Libera spazio o chiedi un piano più grande prima dell\'apertura delle vendite.');
        } else {
            vh_esito('disco.spazio', 'BLOCCANTE', $testo, $nota, 'Libera spazio o chiedi un piano più grande.');
        }
    }
}

/* ------------------------------------------------------------------------------------------------------------------
 * 7. Rete in uscita
 * ---------------------------------------------------------------------------------------------------------------- */

function vh_controlla_rete()
{
    vh_sezione('7. Collegamenti verso l\'esterno (richieste di prova senza dati)');
    if (!extension_loaded('curl') || !vh_funzione('curl_init') || !vh_funzione('curl_exec')) {
        vh_esito('rete', 'ATTENZIONE', 'Non provati: estensione curl assente o disattivata', '', 'Vedi la sezione 2.');

        return;
    }
    $prove = array(
        array('rete.stripe', 'Stripe (api.stripe.com)', 'https://api.stripe.com/v1/charges', 'BLOCCANTE',
            'Senza, il sito non può creare i pagamenti né confermarli.'),
        array('rete.mailchimp', 'Mailchimp (api.mailchimp.com)', 'https://us1.api.mailchimp.com/3.0/ping', 'ATTENZIONE',
            'Serve per iscrivere alla newsletter chi dà il consenso (fase 2).'),
    );
    foreach ($prove as $p) {
        $ch = curl_init($p[2]);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'verifica-hosting/' . VH_VERSIONE,
        ));
        curl_exec($ch);
        $codice = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $errore = curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        $ch = null;
        if ($errno === 0 && $codice >= 200 && $codice < 500) {
            vh_esito($p[0], 'OK', $p[1] . ': raggiungibile', "Risposta HTTP $codice (senza chiavi la risposta normale è 401: va bene così).");
        } elseif ($errno === 60 || $errno === 77) {
            vh_esito($p[0], $p[3], $p[1] . ': certificati HTTPS non verificabili', "Errore curl $errno: $errore. " . $p[4],
                'Chiedi all\'hosting di aggiornare i certificati delle autorità (CA bundle) di PHP.');
        } else {
            vh_esito($p[0], $p[3], $p[1] . ': non raggiungibile', ($errno ? "Errore curl $errno: $errore. " : "Risposta HTTP $codice. ") . $p[4],
                'Chiedi all\'hosting se blocca le connessioni in uscita verso questo indirizzo (porta 443).');
        }
    }
}

/* ------------------------------------------------------------------------------------------------------------------
 * 8. Database
 * ---------------------------------------------------------------------------------------------------------------- */

function vh_controlla_database($cred)
{
    vh_sezione('8. Database (MySQL o MariaDB)');

    if (isset($cred['salta'])) {
        $perche = array(
            'senza-database' => 'saltato su richiesta (--senza-database)',
            'non-interattivo' => 'mancano nome del database, utente o password (variabili DB_DATABASE, DB_USERNAME, DB_PASSWORD)',
            'scelta' => 'nessun nome di database inserito',
        );
        vh_esito('db', 'ATTENZIONE', 'Database non controllato: ' . $perche[$cred['salta']],
            'La parte più importante (trigger, vincoli, lock) resta da verificare.',
            'Rilancia lo script e inserisci i dati del database vuoto creato per il sito.');

        return;
    }
    if (!extension_loaded('pdo_mysql')) {
        vh_esito('db', 'BLOCCANTE', 'Database non controllabile: manca l\'estensione pdo_mysql', '', 'Vedi la sezione 2.');

        return;
    }

    $dsn = $cred['socket'] !== null
        ? 'mysql:unix_socket=' . $cred['socket'] . ';dbname=' . $cred['database'] . ';charset=utf8mb4'
        : 'mysql:host=' . $cred['host'] . ';port=' . (int) $cred['port'] . ';dbname=' . $cred['database'] . ';charset=utf8mb4';
    $connetti = function () use ($dsn, $cred) {
        $pdo = new PDO($dsn, $cred['user'], $cred['password'], array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ));
        $pdo->exec("set time_zone = '+00:00'");
        // Nessuna attesa infinita sui lock delle tabelle (il valore predefinito è un anno).
        $pdo->exec('set session lock_wait_timeout = 15');

        return $pdo;
    };

    try {
        $pdo = $connetti();
    } catch (Exception $e) {
        $codice = vh_codice_errore($e);
        $spiegazioni = array(
            1045 => 'utente o password sbagliati',
            1044 => 'l\'utente non ha accesso a questo database (o il database non esiste)',
            1049 => 'il database non esiste',
            1130 => 'l\'utente non può collegarsi da questo server',
            2002 => 'server del database non raggiungibile (nome del server, porta o socket sbagliati)',
            2005 => 'nome del server del database sconosciuto',
            2006 => 'il server del database ha chiuso il collegamento',
        );
        $testo = isset($spiegazioni[$codice]) ? $spiegazioni[$codice] : 'errore ' . ($codice ? $codice : 'sconosciuto');
        vh_esito('db.connessione', 'BLOCCANTE', "Collegamento al database non riuscito: $testo",
            vh_pulisci_messaggio($e->getMessage()),
            'Ricontrolla nel pannello nome del server, database, utente e password, poi rilancia lo script.');

        return;
    }
    vh_esito('db.connessione', 'OK', 'Collegamento al database: riuscito');

    // Versione
    $versione = (string) vh_valore_sql($pdo, 'select version()');
    $commento = (string) vh_valore_sql($pdo, 'select @@version_comment');
    $mariadb = stripos($versione . ' ' . $commento, 'mariadb') !== false;
    preg_match('/^(\d+)\.(\d+)\.(\d+)/', $versione, $m);
    $numero = $m ? "$m[1].$m[2].$m[3]" : $versione;
    $nome = $mariadb ? 'MariaDB' : 'MySQL';
    vh_fatto('db.versione', "$nome $numero");
    vh_valuta_versione_db($mariadb, $numero, $nome);

    // Impostazioni
    $var = array();
    try {
        foreach ($pdo->query("show variables where Variable_name in ('log_bin', 'log_bin_trust_function_creators',
            'binlog_format', 'transaction_isolation', 'tx_isolation', 'default_storage_engine', 'max_connections',
            'max_user_connections', 'character_set_server', 'lower_case_table_names', 'innodb_lock_wait_timeout',
            'sql_mode', 'time_zone')") as $r) {
            $var[strtolower($r['Variable_name'])] = $r['Value'];
        }
    } catch (Exception $e) {
        // Alcuni hosting nascondono SHOW VARIABLES: si va avanti con le prove.
    }
    $isolamento = isset($var['transaction_isolation']) ? $var['transaction_isolation'] : (isset($var['tx_isolation']) ? $var['tx_isolation'] : '?');
    $logBin = isset($var['log_bin']) ? $var['log_bin'] : '?';
    $fiducia = isset($var['log_bin_trust_function_creators']) ? $var['log_bin_trust_function_creators'] : '?';
    $maxUtente = (int) vh_valore_sql($pdo, 'select @@max_user_connections');
    $impostazioni = "log_bin=$logBin, log_bin_trust_function_creators=$fiducia, isolamento=$isolamento, "
        . 'max_user_connections=' . ($maxUtente === 0 ? 'nessun limite' : $maxUtente)
        . (isset($var['max_connections']) ? ', max_connections=' . $var['max_connections'] : '')
        . (isset($var['default_storage_engine']) ? ', motore=' . $var['default_storage_engine'] : '');
    vh_fatto('db.impostazioni', $impostazioni);
    vh_esito('db.impostazioni', 'INFO', 'Impostazioni del database', $impostazioni);
    if ($maxUtente > 0 && $maxUtente < 15) {
        vh_esito('db.connessioni', 'ATTENZIONE', "Massimo $maxUtente collegamenti contemporanei per l'utente",
            'All\'apertura delle vendite ogni visitatore attivo usa un collegamento: con un limite basso le pagine danno errore.',
            'Chiedi all\'hosting quanti collegamenti contemporanei permette il piano e se si può alzare (almeno 25).');
    }

    // Permessi dichiarati (senza mai stampare la riga con la password che MariaDB mette in SHOW GRANTS)
    $permessi = vh_permessi($pdo);
    if ($permessi !== '') {
        vh_fatto('db.permessi', $permessi);
        vh_esito('db.permessi', 'INFO', 'Permessi dell\'utente', $permessi);
    }

    // Stesso tipo che Laravel 13 userà per gli identificativi su questo server: uuid nativo da MariaDB 10.7,
    // char(36) su MySQL e su MariaDB più vecchie (MariaDbGrammar::typeUuid).
    $tipoId = $mariadb && version_compare($numero, '10.7.0', '>=') ? 'uuid' : 'char(36)';
    vh_prove_database($pdo, $connetti, $mariadb, $logBin, $fiducia, $tipoId);
}

function vh_valuta_versione_db($mariadb, $numero, $nome)
{
    $titolo = "$nome $numero";
    if (!$mariadb) {
        if (version_compare($numero, '8.0.16', '<')) {
            vh_esito('db.versione', 'BLOCCANTE', "$titolo: troppo vecchio (serve MySQL 8.0.16 o più recente)",
                'Le versioni precedenti ignorano i controlli (CHECK) che proteggono capienza e importi.',
                'Chiedi all\'hosting MySQL 8.4 o MariaDB 10.11 o 11.4.');
        } elseif (version_compare($numero, '8.4.0', '<')) {
            vh_esito('db.versione', 'ATTENZIONE', "$titolo: funziona, ma la serie 8.0 non riceve più aggiornamenti",
                'Le prove del progetto sono state fatte su MySQL 8.0.46 e vanno bene; la serie 8.0 ha chiuso il supporto nel 2026.',
                'Chiedi all\'hosting se è previsto il passaggio a MySQL 8.4 (o a MariaDB 10.11 o 11.4).');
        } else {
            vh_esito('db.versione', 'OK', "$titolo (serve MySQL 8.0.16 o più recente)");
        }

        return;
    }
    $serie = implode('.', array_slice(explode('.', $numero), 0, 2));
    $lunghe = array('10.11', '11.4', '11.8');
    if (version_compare($numero, '10.7.0', '<')) {
        vh_esito('db.versione', 'BLOCCANTE', "$titolo: troppo vecchio (serve MariaDB 10.7 o più recente, meglio 10.11 o 11.4)",
            'Prima della 10.7 Laravel salva gli identificativi come testo e MariaDB rifiuta le colonne generate che ne '
            . 'dipendono (le usano le regole «un solo prezzo membro» e simili): vedi la prova della colonna generata.',
            'Chiedi all\'hosting MariaDB 10.11 o 11.4 (o MySQL 8.4).');
    } elseif (in_array($serie, $lunghe, true) || version_compare($numero, '12.0.0', '>=')) {
        vh_esito('db.versione', 'OK', "$titolo (serve MariaDB 10.7 o più recente)");
    } else {
        vh_esito('db.versione', 'ATTENZIONE', "$titolo: funziona, ma non è una versione con supporto lungo",
            'Le prove del progetto sono state fatte su MariaDB 10.11; la serie ' . $serie . ' è vicina o oltre la fine del supporto.',
            'Chiedi all\'hosting se è previsto il passaggio a MariaDB 10.11 o 11.4.');
    }
}

function vh_permessi($pdo)
{
    try {
        $righe = $pdo->query('show grants')->fetchAll(PDO::FETCH_NUM);
    } catch (Exception $e) {
        return '';
    }
    $parti = array();
    foreach ($righe as $r) {
        if (preg_match('/^GRANT (.+?) ON (\S+) TO /i', (string) $r[0], $m)) {
            $parti[] = str_replace('`', '', $m[2]) . ': ' . $m[1];
        } elseif (preg_match('/^GRANT (.+?) TO /i', (string) $r[0], $m)) {
            $parti[] = 'ruolo: ' . str_replace('`', '', $m[1]);
        }
    }

    return implode('; ', $parti);
}

function vh_prove_database($pdo, $connetti, $mariadb, $logBin, $fiducia, $tipoId)
{
    $p = VH_PREFISSO . vh_casuale(3);
    $pool = $p . '_pool';
    $claim = $p . '_claim';
    $trigger = $p . '_guard';

    // Pulizia, registrata subito: vale anche con Ctrl-C o con un errore a metà.
    $GLOBALS['VH_PULIZIA']['db'] = function () use (&$pdo, $connetti, $pool, $claim, $trigger) {
        // Prima si chiude il secondo collegamento della prova dei lock: se fosse ancora aperto, terrebbe ferma la
        // cancellazione delle tabelle.
        $GLOBALS['VH_SECONDO_COLLEGAMENTO'] = null;
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec('set session lock_wait_timeout = 10');
        } catch (Exception $e) {
            try {
                $pdo = call_user_func($connetti);
                $pdo->exec('set session lock_wait_timeout = 10');
            } catch (Exception $e2) {
                return;
            }
        }
        foreach (array("drop trigger if exists `$trigger`", "drop table if exists `$claim`", "drop table if exists `$pool`") as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Exception $e) {
                // Si segnala dopo, controllando cosa è rimasto.
            }
        }
    };

    vh_avanzi_precedenti($pdo);

    // 1. CREATE TABLE con JSON e testo utf8mb4
    try {
        $pdo->exec("create table `$pool` (
            id $tipoId not null primary key,
            name varchar(255) not null,
            info json null,
            capacity int unsigned not null,
            held int unsigned not null default 0,
            confirmed int unsigned not null default 0,
            status varchar(20) not null default 'open'
        ) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        vh_esito('db.create', 'BLOCCANTE', 'Creare una tabella: non permesso (' . vh_codice_errore($e) . ')',
            vh_pulisci_messaggio($e->getMessage()), 'Nel pannello dai all\'utente tutti i permessi sul database del sito.');
        vh_esito('db.altre', 'ATTENZIONE', 'Le altre prove sul database non sono state fatte');

        return;
    }
    $motore = (string) vh_valore_sql($pdo, "select engine from information_schema.tables where table_schema = database() and table_name = '$pool'");
    $idPool = vh_uuid();
    $testo = "Sala Ação \xF0\x9D\x84\x9E";
    try {
        $st = $pdo->prepare("insert into `$pool` (id, name, info, capacity) values (?, ?, ?, 2)");
        $st->execute(array($idPool, $testo, '{"en": "Room", "it": "Sala"}'));
        $riletto = vh_valore_sql($pdo, "select name from `$pool` where id = '$idPool'");
        $json = vh_valore_sql($pdo, "select json_unquote(json_extract(info, '$.it')) from `$pool` where id = '$idPool'");
        $tutto = strtolower($motore) === 'innodb' && $riletto === $testo && $json === 'Sala';
        if ($tutto) {
            vh_esito('db.create', 'OK', 'Creare una tabella di prova (InnoDB, utf8mb4, colonna JSON): funziona');
        } else {
            vh_esito('db.create', 'BLOCCANTE', 'Tabella di prova creata, ma qualcosa non torna',
                "Motore: $motore (serve InnoDB); testo riletto " . ($riletto === $testo ? 'uguale' : 'diverso')
                . '; JSON ' . ($json === 'Sala' ? 'ok' : 'non letto'), 'Mandaci il riepilogo.');
        }
    } catch (Exception $e) {
        vh_esito('db.create', 'BLOCCANTE', 'Scrivere nella tabella di prova: non riuscito (' . vh_codice_errore($e) . ')',
            vh_pulisci_messaggio($e->getMessage()), 'Mandaci il riepilogo.');
    }

    // 2. ALTER TABLE e indice
    vh_prova_ok($pdo, 'db.alter', 'Modificare una tabella e aggiungere un indice (ALTER, INDEX)',
        array("alter table `$pool` add column notes varchar(255) null", "create index `{$p}_pool_name` on `$pool` (name)"),
        'BLOCCANTE', 'Nel pannello dai all\'utente i permessi ALTER e INDEX (o tutti i permessi) sul database.');

    // 3. Chiave esterna
    $fkOk = vh_prova_ok($pdo, 'db.chiave_esterna', 'Chiave esterna tra due tabelle (REFERENCES)',
        array("create table `$claim` (
            id bigint unsigned not null auto_increment primary key,
            pool_id $tipoId not null,
            released_at datetime null,
            constraint `{$p}_claim_pool` foreign key (pool_id) references `$pool` (id) on delete restrict
        ) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci"),
        'BLOCCANTE', 'Nel pannello dai all\'utente il permesso REFERENCES (o tutti i permessi) sul database.', true);
    if ($fkOk) {
        try {
            $pdo->exec("insert into `$claim` (pool_id) values ('" . vh_uuid() . "')");
            vh_esito('db.chiave_esterna', 'BLOCCANTE', 'Chiave esterna creata ma non applicata', 'Una riga senza «genitore» è stata accettata.',
                'Mandaci il riepilogo: probabilmente il motore non è InnoDB.');
        } catch (Exception $e) {
            if (in_array(vh_codice_errore($e), array(1216, 1452), true)) {
                vh_esito('db.chiave_esterna', 'OK', 'Chiave esterna tra due tabelle: creata e applicata');
            } else {
                vh_esito('db.chiave_esterna', 'ATTENZIONE', 'Chiave esterna: errore inatteso (' . vh_codice_errore($e) . ')', vh_pulisci_messaggio($e->getMessage()));
            }
        }
    }

    // 4. Colonna generata + indice unico (gli indici unici «parziali» del progetto)
    if ($fkOk) {
        $cosaFare = $mariadb && $tipoId === 'char(36)'
            ? 'Su MariaDB prima della 10.7 le colonne generate su una chiave esterna di testo non sono ammesse: chiedi '
              . 'all\'hosting MariaDB 10.11 o 11.4 (o MySQL 8.4).'
            : 'Mandaci il riepilogo: serve per «un solo prezzo membro per persona» e regole simili.';
        $creata = vh_prova_ok($pdo, 'db.colonna_generata', 'Colonna generata con indice unico',
            array("alter table `$claim` add column active_key varchar(36)
                    generated always as (case when released_at is null then cast(pool_id as char(36)) end) stored",
                "create unique index `{$p}_claim_one_active` on `$claim` (active_key)"),
            'BLOCCANTE', $cosaFare, true);
        if ($creata) {
            try {
                $pdo->exec("insert into `$claim` (pool_id) values ('$idPool')");
                $doppio = false;
                try {
                    $pdo->exec("insert into `$claim` (pool_id) values ('$idPool')");
                } catch (Exception $e) {
                    $doppio = vh_codice_errore($e) === 1062;
                }
                $pdo->exec("update `$claim` set released_at = utc_timestamp() where pool_id = '$idPool'");
                $pdo->exec("insert into `$claim` (pool_id) values ('$idPool')");
                if ($doppio) {
                    vh_esito('db.colonna_generata', 'OK', 'Colonna generata con indice unico: funziona',
                        'Il secondo «attivo» è rifiutato, dopo il rilascio se ne può creare uno nuovo.');
                } else {
                    vh_esito('db.colonna_generata', 'BLOCCANTE', 'Colonna generata creata, ma l\'indice unico non blocca il doppione', '',
                        'Mandaci il riepilogo.');
                }
            } catch (Exception $e) {
                vh_esito('db.colonna_generata', 'BLOCCANTE', 'Colonna generata: errore nella prova (' . vh_codice_errore($e) . ')',
                    vh_pulisci_messaggio($e->getMessage()), 'Mandaci il riepilogo.');
            }
        }
    }

    // 5. CHECK
    $checkCreato = vh_prova_ok($pdo, 'db.check', 'Controllo CHECK (capienza mai superata)',
        array("alter table `$pool` add constraint `{$p}_pool_capacity` check (held + confirmed <= capacity)"),
        'BLOCCANTE', 'Mandaci il riepilogo: serve una versione del database che applichi i CHECK.', true);
    if ($checkCreato) {
        try {
            $pdo->exec("update `$pool` set held = capacity + 1 where id = '$idPool'");
            vh_esito('db.check', 'BLOCCANTE', 'Controllo CHECK creato ma ignorato dal database',
                'Una capienza superata è stata accettata: è il comportamento di MySQL prima della 8.0.16.',
                'Chiedi all\'hosting MySQL 8.4 o MariaDB 10.11 o 11.4.');
        } catch (Exception $e) {
            $c = vh_codice_errore($e);
            if ($c === 3819 || $c === 4025) {
                vh_esito('db.check', 'OK', 'Controllo CHECK: creato e applicato', "Capienza superata rifiutata (errore $c, atteso).");
            } else {
                vh_esito('db.check', 'ATTENZIONE', "Controllo CHECK: errore inatteso ($c)", vh_pulisci_messaggio($e->getMessage()));
            }
        }
    }

    // 6. Transazione con SELECT ... FOR UPDATE e aggiornamento condizionale, con due collegamenti
    vh_prova_lock($pdo, $connetti, $pool, $idPool);

    // 7. Trigger
    vh_prova_trigger($pdo, $pool, $trigger, $idPool, $mariadb, $logBin, $fiducia);

    // 8. Pulizia e controllo
    call_user_func($GLOBALS['VH_PULIZIA']['db']);
    unset($GLOBALS['VH_PULIZIA']['db']);
    $resti = vh_resti($pdo, $p);
    if (!$resti) {
        vh_esito('db.pulizia', 'OK', 'Pulizia: tabelle e trigger di prova cancellati, nel database non resta nulla');
    } else {
        vh_esito('db.pulizia', 'ATTENZIONE', 'Pulizia incompleta: restano ' . implode(', ', $resti),
            'Sono solo oggetti di prova, vuoti di dati reali.',
            'Cancellali da phpMyAdmin, o mandaci il riepilogo e lo facciamo noi.');
    }
}

function vh_prova_ok($pdo, $codice, $titolo, $sql, $livello, $cosaFare, $soloSeErrore = false)
{
    try {
        foreach ($sql as $s) {
            $pdo->exec($s);
        }
    } catch (Exception $e) {
        vh_esito($codice, $livello, $titolo . ': non riuscito (errore ' . vh_codice_errore($e) . ')',
            vh_pulisci_messaggio($e->getMessage()), $cosaFare);

        return false;
    }
    if (!$soloSeErrore) {
        vh_esito($codice, 'OK', $titolo . ': funziona');
    }

    return true;
}

function vh_prova_lock($pdo, $connetti, $pool, $idPool)
{
    $titolo = 'Transazione con blocco della riga (SELECT ... FOR UPDATE)';
    try {
        $pdo->exec("update `$pool` set held = 0, confirmed = 0, capacity = 2 where id = '$idPool'");
        $cond = $pdo->prepare("update `$pool` set held = held + 1 where id = ? and held + confirmed + 1 <= capacity");
        $esiti = array();
        for ($i = 0; $i < 3; $i++) {
            $cond->execute(array($idPool));
            $esiti[] = $cond->rowCount();
        }
        if ($esiti === array(1, 1, 0)) {
            vh_esito('db.aggiornamento_condizionale', 'OK', 'Aggiornamento condizionale (mai oltre la capienza): funziona',
                'Due posti su due riservati, il terzo rifiutato.');
        } else {
            vh_esito('db.aggiornamento_condizionale', 'BLOCCANTE', 'Aggiornamento condizionale: risultato inatteso',
                'Righe aggiornate: ' . implode(', ', $esiti) . ' (atteso 1, 1, 0).', 'Mandaci il riepilogo.');
        }
    } catch (Exception $e) {
        vh_esito('db.aggiornamento_condizionale', 'BLOCCANTE', 'Aggiornamento condizionale: errore ' . vh_codice_errore($e),
            vh_pulisci_messaggio($e->getMessage()), 'Mandaci il riepilogo.');
    }

    try {
        $altro = call_user_func($connetti);
        $GLOBALS['VH_SECONDO_COLLEGAMENTO'] = $altro;
    } catch (Exception $e) {
        vh_esito('db.lock', 'ATTENZIONE', $titolo . ': non provata, secondo collegamento rifiutato (' . vh_codice_errore($e) . ')',
            'Il piano potrebbe permettere un solo collegamento alla volta: all\'apertura delle vendite sarebbe un problema.',
            'Chiedi all\'hosting quanti collegamenti contemporanei al database permette il piano.');

        return;
    }
    try {
        $pdo->beginTransaction();
        $pdo->query("select id from `$pool` where id = '$idPool' for update")->fetchAll();
        $altro->exec('set session innodb_lock_wait_timeout = 2');
        $altro->beginTransaction();
        $inizio = microtime(true);
        $attesa = false;
        $codice = 0;
        try {
            $altro->query("select id from `$pool` where id = '$idPool' for update")->fetchAll();
        } catch (Exception $e) {
            $codice = vh_codice_errore($e);
            $attesa = $codice === 1205;
        }
        $secondi = microtime(true) - $inizio;
        if ($altro->inTransaction()) {
            $altro->rollBack();
        }
        $pdo->commit();
        if ($attesa) {
            vh_esito('db.lock', 'OK', $titolo . ': funziona',
                sprintf('Il secondo collegamento ha aspettato %.1f s e si è fermato (errore 1205, atteso): due acquisti '
                    . 'sull\'ultimo posto non passano insieme.', $secondi));
        } else {
            vh_esito('db.lock', 'BLOCCANTE', $titolo . ': la riga non risulta bloccata',
                $codice ? "Errore inatteso $codice." : 'Il secondo collegamento ha letto la riga senza aspettare.',
                'Mandaci il riepilogo: senza blocchi si rischia di vendere due volte l\'ultimo posto.');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        vh_esito('db.lock', 'BLOCCANTE', $titolo . ': errore ' . vh_codice_errore($e), vh_pulisci_messaggio($e->getMessage()),
            'Mandaci il riepilogo.');
    }
    $altro = null;
    $GLOBALS['VH_SECONDO_COLLEGAMENTO'] = null;
}

function vh_prova_trigger($pdo, $pool, $trigger, $idPool, $mariadb, $logBin, $fiducia)
{
    $sql = "create trigger `$trigger` before update on `$pool` for each row
            begin
              if new.status = 'vietato' then
                signal sqlstate '45000' set message_text = 'verifica_trigger';
              end if;
            end";
    try {
        $pdo->exec($sql);
    } catch (Exception $e) {
        $c = vh_codice_errore($e);
        $richiesta = 'Testo da mandare all\'hosting (in inglese): «Our Laravel application creates BEFORE INSERT/UPDATE '
            . 'triggers in its own database (no stored functions or procedures). With binary logging enabled this fails '
            . 'with error 1419 for a user without SUPER. Could you set log_bin_trust_function_creators = 1 on the '
            . 'MySQL/MariaDB server, or tell us another way to allow CREATE TRIGGER for our database user?»';
        if ($c === 1419) {
            vh_esito('db.trigger', 'ATTENZIONE', 'Trigger: non permessi (errore 1419, log binario attivo)',
                'Il database ha il log binario acceso (log_bin=' . $logBin . ') e log_bin_trust_function_creators='
                . $fiducia . ': un utente senza il privilegio SUPER non può creare trigger. Il sito funziona lo stesso: '
                . 'capienza, prezzo membro, pagamenti ripetuti e biglietti doppi restano protetti dal database; altre 11 '
                . 'difese (stati degli ordini, nominativo non modificabile, fasce sovrapposte) restano solo nel codice. '
                . $richiesta,
                'Chiedi all\'hosting di impostare log_bin_trust_function_creators = 1 (testo pronto qui sopra). '
                . 'Se rifiuta, va bene lo stesso: diccelo e attiviamo la modalità senza trigger.');
        } elseif ($c === 1142 || $c === 1227 || $c === 1044) {
            vh_esito('db.trigger', 'ATTENZIONE', "Trigger: l'utente non ha il permesso TRIGGER (errore $c)",
                vh_pulisci_messaggio($e->getMessage()) . ' Il sito funziona lo stesso, ma 11 difese restano solo nel codice.',
                'Nel pannello dai all\'utente il permesso TRIGGER (o tutti i permessi) sul database, poi rilancia lo script.');
        } else {
            vh_esito('db.trigger', 'ATTENZIONE', "Trigger: non creato (errore $c)", vh_pulisci_messaggio($e->getMessage()),
                'Mandaci il riepilogo.');
        }

        return;
    }

    try {
        $pdo->exec("update `$pool` set status = 'vietato' where id = '$idPool'");
        vh_esito('db.trigger', 'ATTENZIONE', 'Trigger creato ma non scattato', 'La modifica vietata è passata.', 'Mandaci il riepilogo.');
    } catch (Exception $e) {
        $info = $e instanceof PDOException ? $e->errorInfo : null;
        $stato = is_array($info) && isset($info[0]) ? $info[0] : '';
        if ($stato === '45000' && strpos($e->getMessage(), 'verifica_trigger') !== false) {
            $nota = $logBin === 'ON' || $logBin === '1'
                ? ' Il log binario è acceso, ma l\'hosting permette i trigger' . ($fiducia === 'ON' || $fiducia === '1' ? ' (log_bin_trust_function_creators=ON).' : ' (l\'utente ha i privilegi necessari).')
                : ' Il log binario è spento: per i trigger non serve altro.';
            vh_esito('db.trigger', 'OK', 'Trigger: creato e scattato come previsto', 'Modifica vietata rifiutata con SQLSTATE 45000.' . $nota);
        } else {
            vh_esito('db.trigger', 'ATTENZIONE', 'Trigger creato, errore inatteso (' . vh_codice_errore($e) . ')',
                vh_pulisci_messaggio($e->getMessage()), 'Mandaci il riepilogo.');
        }
    }
    try {
        $pdo->exec("drop trigger `$trigger`");
    } catch (Exception $e) {
        vh_esito('db.trigger_drop', 'ATTENZIONE', 'Cancellare il trigger di prova: non riuscito (' . vh_codice_errore($e) . ')',
            vh_pulisci_messaggio($e->getMessage()), 'Il trigger si cancella insieme alla tabella di prova, alla fine.');
    }
}

/** Oggetti lasciati da un giro precedente interrotto in modo brusco: si riconoscono dal nome e si cancellano. */
function vh_avanzi_precedenti($pdo)
{
    try {
        $tabelle = $pdo->query("select table_name as t from information_schema.tables where table_schema = database()
            and table_name like 'zz\\_verifica\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return;
    }
    $nostre = array();
    foreach ($tabelle as $t) {
        if (preg_match('/^zz_verifica_[0-9a-f]{6}_(pool|claim)$/', $t)) {
            $nostre[] = $t;
        }
    }
    if (!$nostre) {
        return;
    }
    $ordinate = array();
    foreach (array('_claim', '_pool') as $fine) {
        foreach ($nostre as $t) {
            if (substr($t, -strlen($fine)) === $fine) {
                $ordinate[] = $t;
            }
        }
    }
    foreach ($ordinate as $t) {
        try {
            $pdo->exec("drop table if exists `$t`");
        } catch (Exception $e) {
            // segnalato dal controllo finale
        }
    }
    vh_esito('db.avanzi', 'INFO', 'Cancellate ' . count($nostre) . ' tabelle di prova rimaste da un giro precedente interrotto',
        implode(', ', $nostre));
}

function vh_resti($pdo, $p)
{
    $resti = array();
    try {
        $like = str_replace('_', '\\_', $p) . '%';
        foreach (array('tables' => 'table_name', 'triggers' => 'trigger_name') as $tabella => $colonna) {
            $schema = $tabella === 'tables' ? 'table_schema' : 'trigger_schema';
            $st = $pdo->prepare("select $colonna from information_schema.$tabella where $schema = database() and $colonna like ?");
            $st->execute(array($like));
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
                $resti[] = $n;
            }
        }
    } catch (Exception $e) {
        $resti[] = '(controllo non riuscito: ' . vh_codice_errore($e) . ')';
    }

    return $resti;
}

/* ------------------------------------------------------------------------------------------------------------------
 * Voci che lo script non può vedere
 * ---------------------------------------------------------------------------------------------------------------- */

function vh_da_controllare_nel_pannello()
{
    vh_sezione('9. Da guardare nel pannello (lo script non le vede da qui)');
    $voci = array(
        'Versione PHP del sito web: la stessa della riga di comando (8.4, o almeno 8.3).',
        'Cron: si può impostare un lavoro «ogni minuto»?',
        'Cartella pubblica del sito (document root): si può far puntare a una sottocartella (es. eas8/current/public)?',
        'HTTPS: certificato gratuito (Let\'s Encrypt) attivabile per il dominio nuovo?',
        'Dove sono i server (paese del data center) e nome del fornitore.',
        'Quanti processi PHP in parallelo permette il piano («entry processes», «PHP workers» o simili)?',
        'Si può creare un secondo database (per le prove prima di andare online)?',
    );
    foreach ($voci as $i => $v) {
        echo '  ' . ($i + 1) . '. ' . $v . "\n";
    }
}

/* ------------------------------------------------------------------------------------------------------------------
 * Rapporto e riepilogo
 * ---------------------------------------------------------------------------------------------------------------- */

function vh_titolo()
{
    $riga = str_repeat('=', 78);
    echo "\n$riga\n" . vh_colore("Verifica dell'hosting per il sito EAS8 (Laravel 13 + Filament 5)", '1') . ' - versione ' . VH_VERSIONE . "\n"
        . 'Data: ' . @gmdate('Y-m-d H:i') . " UTC\n"
        . "Lo script legge le impostazioni del server e fa alcune prove che poi cancella. Non cambia nulla del sito,\n"
        . "non salva password e non manda dati a nessuno (solo due richieste di prova, vuote, a Stripe e Mailchimp).\n"
        . "Legenda: OK = a posto; ATTENZIONE = si può partire, ma leggi la nota; BLOCCANTE = va sistemato prima.\n"
        . "$riga\n";
}

function vh_sezione($titolo)
{
    echo "\n" . vh_colore($titolo, '1') . "\n" . str_repeat('-', min(78, strlen($titolo))) . "\n";
}

function vh_esito($codice, $stato, $titolo, $dettaglio = '', $cosaFare = '')
{
    // Un esito dello stesso codice sostituisce il precedente (es. la chiave esterna: creata, poi provata).
    foreach ($GLOBALS['VH_ESITI'] as $i => $e) {
        if ($e['codice'] === $codice && $codice !== 'db') {
            unset($GLOBALS['VH_ESITI'][$i]);
        }
    }
    $GLOBALS['VH_ESITI'][] = array('codice' => $codice, 'stato' => $stato, 'titolo' => $titolo);
    $colori = array('OK' => '32', 'ATTENZIONE' => '33', 'BLOCCANTE' => '1;31', 'INFO' => '36');
    $etichetta = str_pad('[' . ($stato === 'INFO' ? 'info' : $stato) . ']', 12);
    echo '  ' . vh_colore($etichetta, $colori[$stato]) . ' ' . $titolo . "\n";
    $rientro = str_repeat(' ', 15);
    if ($dettaglio !== '') {
        echo $rientro . wordwrap($dettaglio, 92, "\n" . $rientro, true) . "\n";
    }
    if ($cosaFare !== '') {
        echo $rientro . vh_colore('Cosa fare: ', '1') . wordwrap($cosaFare, 81, "\n" . $rientro, true) . "\n";
    }
}

function vh_fatto($chiave, $valore)
{
    $GLOBALS['VH_FATTI'][$chiave] = $valore;
}

function vh_riepilogo()
{
    $conta = array('OK' => 0, 'ATTENZIONE' => 0, 'BLOCCANTE' => 0);
    foreach ($GLOBALS['VH_ESITI'] as $e) {
        if (isset($conta[$e['stato']])) {
            $conta[$e['stato']]++;
        }
    }
    if ($conta['BLOCCANTE'] > 0) {
        $esito = 'DA SISTEMARE: ci sono voci BLOCCANTE';
        $frase = "Così non si può partire: vanno sistemate le voci BLOCCANTE (di solito dal pannello o chiedendo all'hosting).";
        $codice = 2;
    } elseif ($conta['ATTENZIONE'] > 0) {
        $esito = 'SI PUO\' PARTIRE, con le note ATTENZIONE';
        $frase = 'Si può partire. Le voci ATTENZIONE vanno lette: alcune si sistemano dal pannello, altre cambiano solo il modo di lavorare.';
        $codice = 1;
    } else {
        $esito = 'TUTTO A POSTO';
        $frase = 'Tutto a posto.';
        $codice = 0;
    }

    $riga = str_repeat('=', 78);
    echo "\n$riga\n" . vh_colore('Esito: ' . $frase, '1') . "\n";
    echo "Ora copia tutto il blocco qui sotto, da INIZIO a FINE, e incollalo in un messaggio per Giulio.\n";
    echo "Poi cancella questo file dal server: rm " . vh_q(__FILE__) . "\n$riga\n\n";

    $f = $GLOBALS['VH_FATTI'];
    $out = array();
    $out[] = '----- RIEPILOGO VERIFICA HOSTING EAS8: INIZIO -----';
    $out[] = 'verifica-hosting ' . VH_VERSIONE . ' | ' . @gmdate('Y-m-d H:i') . ' UTC';
    $out[] = "Esito: $esito | bloccanti {$conta['BLOCCANTE']}, attenzione {$conta['ATTENZIONE']}, ok {$conta['OK']}";
    $out[] = 'PHP ' . PHP_VERSION . ' (' . (isset($f['php.binario']) ? $f['php.binario'] : '?') . ')'
        . (isset($f['db.versione']) ? ' | DB ' . $f['db.versione'] : ' | DB non controllato');
    if (isset($f['server'])) {
        $out[] = 'Server: ' . $f['server'];
    }
    foreach (array('BLOCCANTE', 'ATTENZIONE') as $livello) {
        foreach ($GLOBALS['VH_ESITI'] as $e) {
            if ($e['stato'] === $livello) {
                $out[] = $livello . ' ' . $e['codice'] . ': ' . $e['titolo'];
            }
        }
    }
    $ok = array();
    foreach ($GLOBALS['VH_ESITI'] as $e) {
        if ($e['stato'] === 'OK') {
            $ok[] = $e['codice'];
        }
    }
    if ($ok) {
        $out[] = 'OK: ' . implode(', ', $ok);
    }
    if (isset($f['db.impostazioni'])) {
        $out[] = 'DB: ' . $f['db.impostazioni'];
    }
    if (isset($f['db.permessi'])) {
        $out[] = 'Permessi DB: ' . $f['db.permessi'];
    }
    $programmi = array();
    foreach (array('composer', 'git', 'rsync', 'mysqldump') as $p) {
        if (isset($f[$p])) {
            $programmi[] = "$p " . $f[$p];
        }
    }
    if ($programmi) {
        $out[] = 'Programmi: ' . implode(', ', $programmi);
    }
    $out[] = 'Funzioni disattivate: ' . (isset($f['php.disable_functions']) ? vh_breve($f['php.disable_functions'], 400) : '?');
    $out[] = 'Dal pannello (da scrivere qui sotto): PHP del sito = ?, cron ogni minuto = ?, document root su sottocartella = ?,';
    $out[] = '  HTTPS gratuito = ?, paese del data center e fornitore = ?, processi PHP in parallelo = ?, secondo database = ?';
    $out[] = '----- RIEPILOGO VERIFICA HOSTING EAS8: FINE -----';
    echo implode("\n", $out) . "\n\n";

    return $codice;
}

/* ------------------------------------------------------------------------------------------------------------------
 * Pulizia finale
 * ---------------------------------------------------------------------------------------------------------------- */

function vh_pulisci()
{
    $lavori = array_reverse($GLOBALS['VH_PULIZIA'], true);
    $GLOBALS['VH_PULIZIA'] = array();
    foreach ($lavori as $f) {
        try {
            call_user_func($f);
        } catch (Throwable $t) {
            // si prova il lavoro successivo
        } catch (Exception $e) {
            // PHP 5
        }
    }
}

function vh_alla_fine()
{
    if (!empty($GLOBALS['VH_STTY_SPENTO'])) {
        @shell_exec('stty echo 2>/dev/null');
    }
    vh_pulisci();
}

/* ------------------------------------------------------------------------------------------------------------------
 * Strumenti
 * ---------------------------------------------------------------------------------------------------------------- */

function vh_tty($stream)
{
    if (function_exists('stream_isatty')) {
        return @stream_isatty($stream);
    }
    if (function_exists('posix_isatty')) {
        return @posix_isatty($stream);
    }

    return false;
}

function vh_colore($testo, $codice)
{
    return $GLOBALS['VH_COLORI'] ? "\033[" . $codice . 'm' . $testo . "\033[0m" : $testo;
}

function vh_in_disabilitate($f)
{
    static $lista = null;
    if ($lista === null) {
        $lista = array();
        foreach (explode(',', strtolower((string) ini_get('disable_functions'))) as $x) {
            $x = trim($x);
            if ($x !== '') {
                $lista[$x] = true;
            }
        }
    }

    return isset($lista[strtolower($f)]);
}

function vh_funzione($f)
{
    return function_exists($f) && !vh_in_disabilitate($f);
}

function vh_mancanti($funzioni)
{
    $m = array();
    foreach ($funzioni as $f) {
        if (!vh_funzione($f)) {
            $m[] = $f;
        }
    }

    return $m;
}

function vh_puo_eseguire()
{
    return (vh_funzione('proc_open') && vh_funzione('proc_get_status') && vh_funzione('proc_close'))
        || vh_funzione('exec') || vh_funzione('shell_exec');
}

/** Quota un argomento per la shell senza escapeshellarg (che alcuni hosting disattivano). */
function vh_q($s)
{
    return "'" . str_replace("'", "'\\''", (string) $s) . "'";
}

/** Avvia un comando con un tempo massimo; restituisce l'output (stdout + stderr) o null se non si può. */
function vh_esegui($comando, &$codiceUscita, $timeout)
{
    $codiceUscita = null;
    if (vh_funzione('proc_open') && vh_funzione('proc_get_status') && vh_funzione('proc_close')) {
        $tubi = array();
        $proc = @proc_open($comando, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $tubi);
        if (!is_resource($proc)) {
            return null;
        }
        stream_set_blocking($tubi[1], false);
        stream_set_blocking($tubi[2], false);
        $out = '';
        $inizio = microtime(true);
        while (true) {
            $out .= (string) stream_get_contents($tubi[1]) . (string) stream_get_contents($tubi[2]);
            $stato = proc_get_status($proc);
            if (!$stato['running']) {
                $codiceUscita = $stato['exitcode'];
                break;
            }
            if (microtime(true) - $inizio > $timeout) {
                if (vh_funzione('proc_terminate')) {
                    proc_terminate($proc, 9);
                }
                $codiceUscita = -1;
                break;
            }
            usleep(50000);
        }
        $out .= (string) stream_get_contents($tubi[1]) . (string) stream_get_contents($tubi[2]);
        fclose($tubi[1]);
        fclose($tubi[2]);
        proc_close($proc);

        return $out;
    }
    if (vh_funzione('exec')) {
        $righe = array();
        @exec($comando . ' 2>&1 </dev/null', $righe, $c);
        $codiceUscita = $c;

        return implode("\n", $righe);
    }
    if (vh_funzione('shell_exec')) {
        $out = @shell_exec($comando . ' 2>&1 </dev/null; echo "__uscita=$?"');
        if (!is_string($out)) {
            return null;
        }
        if (preg_match('/__uscita=(\d+)\s*$/', $out, $m)) {
            $codiceUscita = (int) $m[1];
            $out = preg_replace('/__uscita=\d+\s*$/', '', $out);
        }

        return $out;
    }

    return null;
}

function vh_trova_programma($nome, $extra = array())
{
    $path = getenv('PATH');
    $cartelle = explode(PATH_SEPARATOR, $path ? $path : '/usr/local/bin:/usr/bin:/bin');
    foreach (array_merge($cartelle, $extra) as $d) {
        if ($d === '') {
            continue;
        }
        $f = rtrim($d, '/') . '/' . $nome;
        if (@is_file($f) && @is_executable($f)) {
            return $f;
        }
    }

    return null;
}

function vh_byte($valore)
{
    $v = trim((string) $valore);
    if ($v === '' || $v === '-1') {
        return -1;
    }
    $n = (float) $v;
    switch (strtolower(substr($v, -1))) {
        case 'g':
            $n *= 1024;
            // no break
        case 'm':
            $n *= 1024;
            // no break
        case 'k':
            $n *= 1024;
    }

    return (int) $n;
}

function vh_casuale($byte)
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($byte));
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes($byte));
    }
    $s = '';
    for ($i = 0; $i < $byte; $i++) {
        $s .= sprintf('%02x', mt_rand(0, 255));
    }

    return $s;
}

function vh_uuid()
{
    $h = vh_casuale(16);

    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
}

function vh_valore_sql($pdo, $sql)
{
    try {
        $r = $pdo->query($sql)->fetch(PDO::FETCH_NUM);

        return $r ? $r[0] : null;
    } catch (Exception $e) {
        return null;
    }
}

function vh_codice_errore($e)
{
    if ($e instanceof PDOException && is_array($e->errorInfo) && isset($e->errorInfo[1]) && $e->errorInfo[1]) {
        return (int) $e->errorInfo[1];
    }
    if (preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) {
        return (int) $m[1];
    }

    return 0;
}

/** Toglie dal messaggio d'errore le parti che non servono a chi legge (prefisso SQLSTATE). */
function vh_pulisci_messaggio($m)
{
    $m = preg_replace('/^SQLSTATE\[[^\]]*\]:?\s*/', '', (string) $m);
    $m = preg_replace('/IDENTIFIED (BY|WITH|VIA) .*/i', 'IDENTIFIED ...', $m);

    return vh_breve($m, 300);
}

function vh_breve($s, $max = 200)
{
    $s = trim(preg_replace('/\s+/', ' ', (string) $s));

    return strlen($s) > $max ? substr($s, 0, $max - 3) . '...' : $s;
}
