# Verifica dell'hosting

Autore: Giulio Occhipinti. Versione 1.0 del 06/11/2026.

`verifica-hosting.php` controlla da solo se l'hosting può ospitare il sito dell'EAS8 (Laravel 13 + Filament 5 con
MySQL o MariaDB) e stampa un rapporto che si capisce anche senza essere tecnici. Prende il posto delle domande sui
requisiti dell'hosting (`docs/architettura.md` §14.1).

## Per Simone: cinque passi, circa dieci minuti

**Prima di cominciare.** Nel pannello dell'hosting crea un **database vuoto** per il sito e un **utente** con tutti i
permessi su quel database (come farai poi per il sito vero). Annota: nome del database, utente, password e server del
database (quasi sempre `localhost`).

1. **Carica il file via SFTP** (per esempio con FileZilla: protocollo SFTP, porta 22, le credenziali SSH) nella tua
   **cartella principale**, quella in cui entri appena colleghi. **Non** dentro `public_html`, `www` o `httpdocs`.
2. **Collegati via SSH** e lancia:

   ```
   cd ~
   php verifica-hosting.php
   ```

   Lo script chiede i dati del database. La password **non si vede** mentre la scrivi: è normale, scrivila e premi
   Invio. Le prove durano pochi secondi.
3. **Leggi l'esito** in fondo. Ogni voce ha un livello:

   | Livello | Cosa vuol dire |
   |---|---|
   | `OK` | A posto |
   | `ATTENZIONE` | Si può partire, ma leggi la nota «Cosa fare»: a volte basta una spunta nel pannello |
   | `BLOCCANTE` | Va sistemato prima di cominciare: di solito dal pannello o chiedendo all'hosting |

4. **Copia il riepilogo**: il blocco tra `RIEPILOGO VERIFICA HOSTING EAS8: INIZIO` e `FINE`. Incollalo in un'email o
   in un messaggio a Giulio e completa le ultime due righe con quello che vedi nel pannello (versione PHP del sito,
   cron ogni minuto, cartella pubblica, HTTPS, paese del data center e fornitore, processi PHP in parallelo, secondo
   database). Il riepilogo non contiene password.
5. **Cancella il file** dal server:

   ```
   rm ~/verifica-hosting.php
   ```

Se il comando `php` risponde con una versione vecchia, lo script te lo dice e mostra gli altri PHP che trova sul
server (per esempio `/opt/cpanel/ea-php84/root/usr/bin/php`): rilancialo con quello, per esempio
`/opt/cpanel/ea-php84/root/usr/bin/php verifica-hosting.php`.

### Se compare «Trigger: non permessi (errore 1419)»

È il caso più probabile su un hosting condiviso: il database ha il «log binario» acceso e un utente normale non può
creare trigger. **Il sito funziona lo stesso**: capienza, prezzo membro, pagamenti ripetuti e biglietti doppi restano
protetti dal database; altre 11 difese restano solo nel codice. Lo script stampa un testo in inglese da mandare
all'assistenza dell'hosting per chiedere `log_bin_trust_function_creators = 1`. Se l'hosting rifiuta, va bene: diccelo
e il sito si installa in modalità senza trigger (`DB_TRIGGERS_OPTIONAL=true`, `docs/architettura.md` §3).

## Cosa controlla

| Sezione | Cosa | Perché |
|---|---|---|
| 1. PHP | Versione (minimo 8.3, consigliata 8.4), altri PHP sul server, 64 bit | Laravel 13 chiede PHP 8.3+ |
| 2. Estensioni | `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `intl`, `json`, `mbstring`, `openssl`, `pcre`, `pdo_mysql`, `session`, `sodium`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip`; `gd` o `imagick`; argon2id; `pcntl` e `bcmath` consigliate | Laravel, Filament, QR firmati in PNG, export e import Excel, password dello staff |
| 3. Limiti | `memory_limit` (512M da riga di comando, e se si può alzare), `upload_max_filesize` e `post_max_size` (20M), `open_basedir` | Coda, import, foto |
| 4. Funzioni | `proc_open` e simili, `exec`, `symlink`, `putenv`, `set_time_limit`, `ini_set`, `pcntl_*`, `escapeshellarg` in `disable_functions` | Composer, scheduler, deploy, backup |
| 5. Programmi | Composer 2, git, rsync, mysqldump, `crontab -l` (senza stampare il contenuto), pannello e sistema | Deploy, backup, cron ogni minuto |
| 6. Disco | Scrittura, lettura, rinomina e link simbolici nella cartella, spazio libero (5 GB), cartella pubblica | Release, log, foto |
| 7. Rete | Una richiesta HTTPS vuota a `api.stripe.com` e a `us1.api.mailchimp.com` (risposta attesa 401) | Pagamenti, newsletter |
| 8. Database | Versione (MySQL 8.0.16+, MariaDB 10.7+), impostazioni (`log_bin`, `log_bin_trust_function_creators`, isolamento, collegamenti), permessi; prove reali: tabella InnoDB `utf8mb4` con JSON, `ALTER` e indice, chiave esterna applicata, colonna generata con indice unico (con lo stesso tipo di identificativo che userà Laravel), `CHECK` applicato, aggiornamento condizionale, `SELECT … FOR UPDATE` con due collegamenti, `CREATE TRIGGER` con `SIGNAL`, pulizia e controllo che non resti nulla | Le difese del modello dati (`db/modello-dati.md` §2 e §5) |
| 9. Pannello | Elenco delle voci che lo script non vede | Da scrivere nel riepilogo |

Codice di uscita: `0` tutto OK, `1` qualche ATTENZIONE, `2` almeno un BLOCCANTE.

## Sicurezza

- **Solo da riga di comando.** Chiamato da web risponde `403` con corpo vuoto (provato con il server web di PHP:
  GET, POST e HEAD). Meglio comunque non metterlo nella cartella pubblica e cancellarlo dopo.
- **Credenziali mai salvate né stampate.** Si scrivono a schermo (password nascosta con `stty -echo`) oppure si
  passano con le variabili `DB_HOST`, `DB_PORT`, `DB_SOCKET`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Host,
  porta, socket, database e utente vanno bene anche come argomenti (`--host`, `--port`, `--socket`, `--database`,
  `--user`); la password no: `--password` viene rifiutato perché resterebbe nella cronologia della shell e sarebbe
  visibile con `ps`. I permessi si leggono da `SHOW GRANTS` senza riportare la riga con l'hash della password che
  MariaDB vi aggiunge.
- **Niente lasciato sul server.** File di prova in `.verifica-hosting-<caso>/` e tabelle e trigger con prefisso
  `zz_verifica_<caso>_`, cancellati alla fine; un giro interrotto in modo brusco (`kill -9`, collegamento SSH caduto)
  lascia al massimo due tabelle di prova vuote, che il giro successivo riconosce e cancella. Durante la prova dei
  lock un Ctrl-C non viene raccolto da PHP: la prova finisce da sola in circa due secondi e pulisce.
- **Nessuna dipendenza**, un solo file. Sintassi compatibile con PHP 5.6+ (controllata con PHPCompatibility 9.3), così
  anche un PHP vecchio stampa il rapporto invece di un errore.

Altre opzioni: `--senza-database`, `--senza-rete`, `--help`.

## Prove fatte (06/11/2026)

PHP 8.3.6 da riga di comando, utente del database con tutti i permessi sul solo database e senza `SUPER`, come su un
hosting condiviso. Server di prova avviati apposta, separati da quelli delle prove delle migrazioni.

| Caso | Esito |
|---|---|
| MariaDB 10.11.14 con log binario, `log_bin_trust_function_creators=OFF` | 🟢 tutte le prove OK; trigger: **ATTENZIONE, errore 1419** con il testo per l'hosting `[sure]` |
| MySQL 8.0.46 con log binario (predefinito), `log_bin_trust_function_creators=OFF` | 🟢 come sopra, **errore 1419** riprodotto; versione: ATTENZIONE (serie 8.0) `[sure]` |
| Stessi server con `log_bin_trust_function_creators=ON` | 🟢 trigger creato, scattato (SQLSTATE 45000) e cancellato `[sure]` |
| MariaDB senza log binario | 🟢 trigger OK, «il log binario è spento» `[sure]` |
| Utente senza il permesso `TRIGGER` | 🟢 errore 1419 con il log binario; errore 1142 «permesso TRIGGER» senza `[sure]` |
| Identificativi `char(36)` su MariaDB (quelli che Laravel 13 crea prima della 10.7) | 🔴 colonna generata rifiutata (errore 1901): BLOCCANTE con il consiglio di MariaDB 10.11 o 11.4 `[sure]` |
| Password sbagliata, database inesistente, collegamento via TCP | 🟢 messaggi chiari (1045, 1044), TCP OK `[sure]` |
| `php -n` (nessuna estensione), funzioni disattivate (`proc_open`, `exec`, `shell_exec`, `symlink`, `putenv`, `pcntl_*`) | 🟢 voci BLOCCANTE o ATTENZIONE corrette, nessun errore PHP `[sure]` |
| Chiamata da web (GET, POST, HEAD) | 🟢 `403`, 0 byte `[sure]` |
| Password scritta a schermo in un terminale vero | 🟢 non compare nell'output `[sure]` |
| `kill -9` a metà delle prove sul database | 🟢 restano 2 tabelle vuote, cancellate dal giro dopo `[sure]` |
| Rete | 🟡 da questa sessione `api.stripe.com` e Mailchimp sono bloccati dal proxy (BLOCCANTE e ATTENZIONE, come previsto); il ramo «raggiungibile» provato su un altro indirizzo `[sure]` |

Non provati: PHP 8.4, 5.6 e 7.x reali, MariaDB 10.6 reale, un hosting con cPanel o Plesk.
