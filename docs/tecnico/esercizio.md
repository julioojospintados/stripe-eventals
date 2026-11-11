# Installazione e manutenzione sul server

Autore: Giulio Occhipinti. Aggiornato all'11/11/2026. Per Simone.

Il server del cliente è pieno (nginx, PHP 8.4, MariaDB 11.4, SSH, root): file d'esempio e passi per nginx, worker
della coda e scheduler in [`strumenti/server/`](../../strumenti/server/README.md), con un ambiente di staging separato
(`sito/.env.staging.example`). Le note per hosting condiviso restano valide come variante. Comandi pensati per SSH,
PHP da riga di comando e MariaDB o MySQL. `<utente>`, `<dominio>`, `<db>`
sono segnaposto. Le motivazioni sono negli [ADR](adr/README.md); i flussi in [flussi.md](flussi.md).

## 1. Requisiti

Su un hosting condiviso lancia prima [`strumenti/verifica-hosting/`](../../strumenti/verifica-hosting/README.md)
(sul server del cliente non serve). In breve:

| Cosa | Minimo |
|---|---|
| PHP web e riga di comando | 8.4 (8.4.1+), stessa versione per PHP-FPM e CLI; `pdo_mysql`, `mbstring`, `intl`, `sodium`, `gd`, `zip`, argon2 (`PASSWORD_ARGON2ID`) |
| Funzioni | `proc_open`, `proc_get_status` non disabilitate; `fastcgi_finish_request` o `litespeed_finish_request` |
| Database | **MariaDB 11.4 LTS** (riferimento); vanno anche MariaDB 10.11 e MySQL **8.0.19+** (serve `DROP CONSTRAINT`); InnoDB, `utf8mb4` |
| Privilegi | tutti sul database del sito, compreso `TRIGGER`; con il log binario attivo `log_bin_trust_function_creators = 1` nel server o un utente con `SUPER` |
| Web | HTTPS, document root impostabile su `current/public` |
| Coda e scheduler | server: worker con systemd o supervisor (`QUEUE_WORKER_MODE=daemon`) e `schedule:run` ogni minuto (cron o timer); hosting condiviso: una riga di cron |

Se i trigger non si possono creare (errore 1419) e l'hosting non cambia l'impostazione: `DB_TRIGGERS_OPTIONAL=true`
(le regole restano nei servizi; vedi [ADR 0002](adr/0002-mysql-mariadb-vincoli-trigger.md)).

## 2. Struttura sul server

```
/home/<utente>/eas8/
  releases/20261107-1530/   una cartella per rilascio (contenuto di sito/)
  shared/.env               unico .env, permessi 600
  shared/storage/           storage/ condiviso tra i rilasci
  current -> releases/…     link al rilascio attivo
```

Il dominio punta a `/home/<utente>/eas8/current/public`. Se il pannello dell'hosting impone `public_html`, sostituiscila
con un link: `ln -s /home/<utente>/eas8/current/public /home/<utente>/public_html` (dopo averne salvato il contenuto).
Nessun altro file del progetto, `.env` compreso, deve essere raggiungibile dal web.

## 3. Prima installazione

1. Crea database e utente dal pannello dell'hosting (`utf8mb4`, `utf8mb4_unicode_ci`).
2. Copia il codice in `releases/<data>` (git clone del tag, o rsync dalla CI) e crea `shared/`:
   ```
   mkdir -p ~/eas8/shared/storage/{app/public,app/private,framework/{cache,sessions,views},logs}
   cp ~/eas8/releases/<data>/.env.example ~/eas8/shared/.env && chmod 600 ~/eas8/shared/.env
   ```
3. Compila `shared/.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<dominio>`,
   `SESSION_SECURE_COOKIE=true`, `HASH_DRIVER=argon2id`, database, SMTP, `MAIL_RATE_PER_MINUTE`, Stripe (§6), QR (§7).
4. Collega `.env` e `storage` e installa:
   ```
   cd ~/eas8/releases/<data>
   rm -rf storage && ln -s ~/eas8/shared/storage storage && ln -s ~/eas8/shared/.env .env
   composer install --no-dev --optimize-autoloader --no-interaction
   php artisan key:generate --force        # solo la prima volta: APP_KEY non va mai rigenerata dopo
   php artisan migrate --force
   php artisan db:seed --class=RolesAndPermissionsSeeder --force
   php artisan db:seed --class=Eas8Seeder --force      # solo se i dati dell'evento non si inseriscono dal pannello
   php artisan storage:link
   php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan filament:optimize
   chmod -R u+rwX,go-rwx ~/eas8/shared/storage bootstrap/cache
   ln -sfn ~/eas8/releases/<data> ~/eas8/current
   php artisan eas:create-admin <email> --name="<nome>" --role=admin
   ```
5. Imposta la document root (§2), il cron (§5) e il webhook (§6). Primo accesso a `/admin`: password e attivazione
   della MFA; conserva i codici di recupero.
6. Controlla che i trigger ci siano: `php strumenti/genera-doc-db/genera.php --out=/tmp/db.md` dalla copia del
   repository, oppure `SHOW TRIGGERS;` (devono essere 9).

Gli asset (`public/build`) si compilano in CI o in locale con `npm ci && npm run build` e si copiano: sull'hosting non
serve Node.

## 4. Deploy di una nuova versione

**Due modalità** in [`strumenti/deploy/`](../../strumenti/deploy/README.md): `deploy-pull.sh` (cartella unica,
`git pull --ff-only`, come lavora Simone) e `deploy.sh` (rilasci con switch atomico e `rollback.sh`). Entrambe fanno
backup, Composer, migrazioni, cache e `queue:restart` e si fermano al primo errore.

**Con lo script a rilasci** [`strumenti/deploy/`](../../strumenti/deploy/README.md): `deploy.sh` fa i passi qui sotto (backup
compreso, con [`strumenti/backup/backup.sh`](../../strumenti/backup/README.md)) e tiene 5 rilasci; `rollback.sh` torna
al precedente e avvisa se il rilascio attivo ha migrazioni nuove. Il seeder dei permessi (passo 5) resta a mano.
A mano:

1. **Backup del database** (§8) e controllo che sia leggibile.
2. Copia del codice in `releases/<data nuova>`, link a `shared/.env` e `shared/storage`.
3. `composer install --no-dev --optimize-autoloader --no-interaction`
4. `php artisan migrate --force` (leggi prima §9 se la versione ha migrazioni).
5. `php artisan db:seed --class=RolesAndPermissionsSeeder --force` se sono cambiati i permessi in `StaffRole`.
6. `php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan filament:optimize`
7. `ln -sfn ~/eas8/releases/<data nuova> ~/eas8/current`
8. `php artisan queue:restart` (il worker esce dopo il job in corso; systemd o supervisor lo riavviano sul codice
   nuovo; con il cron lo prende il giro dopo).
9. Prova: home, `/tickets`, `/admin`, un evento di test dalla Dashboard Stripe (§6).
10. Tieni le ultime 5 release. **Ritorno indietro:** link `current` alla release precedente e `queue:restart`; se la
    versione aveva migrazioni non compatibili, ripristino dal backup (§8) e riconciliazione.

## 5. Coda e cron

**Server pieno:** `QUEUE_WORKER_MODE=daemon` nel `.env`, worker `eas8-queue.service` (o supervisor) e la riga qui
sotto (o `eas8-scheduler.timer`): [`strumenti/server/`](../../strumenti/server/README.md). Controllo:
`systemctl status eas8-queue`, `php artisan queue:monitor database:critical,database:default --max=50`.

**Hosting condiviso** (`QUEUE_WORKER_MODE=cron`): basta questa riga. La riga dello scheduler (crontab dell'utente) è
la stessa nei due casi:

```
* * * * * cd /home/<utente>/eas8/current && php artisan schedule:run >> /dev/null 2>&1
```

Lo scheduler (`sito/routes/console.php`) lancia: in modalità `cron` `queue:work --queue=critical,default
--stop-when-empty --max-time=50` ogni minuto senza sovrapposizioni, `eas:expire-orders` ogni minuto, `eas:reconcile` alle 3:30
(Europe/Lisbon), pulizia dei codici di accesso e degli export ogni giorno. Verifica: `php artisan schedule:list`.

Se l'hosting vieta `proc_open`, tre righe separate:

```
* * * * * cd …/current && php artisan queue:work --queue=critical,default --stop-when-empty --max-time=50 --tries=5
* * * * * cd …/current && php artisan eas:expire-orders
30 3 * * * cd …/current && php artisan eas:reconcile
```

## 6. Webhook Stripe in produzione

1. Finché il cliente non dice altro, **solo modalità test** (`sk_test_…`). Un ambiente con chiave `sk_live_` rifiuta
   gli eventi di test e viceversa (`livemode`).
2. Dashboard Stripe → Developers → Webhooks → endpoint `https://<dominio>/stripe/webhook`, **un endpoint per ambiente**
   (staging e produzione hanno segreti diversi). Eventi da selezionare (quelli gestiti da `ProcessStripeEvent`):
   `checkout.session.completed`, `checkout.session.expired`, `checkout.session.async_payment_succeeded`,
   `checkout.session.async_payment_failed`, `charge.refunded`, `refund.failed`, `refund.updated`,
   `charge.refund.updated`, `charge.dispute.created`, `charge.dispute.updated`, `charge.dispute.closed`,
   `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`,
   `invoice.payment_failed`, `invoice.payment_action_required`. Gli altri arrivano come `ignored`.
3. Versione API dell'endpoint uguale a `STRIPE_API_VERSION` del `.env` (se vuota vale quella di `stripe-php`).
4. `STRIPE_WEBHOOK_SECRET=whsec_…` nel `.env`, poi `php artisan config:cache`.
5. Membership: Price ricorrente annuale in Stripe, ID nel pannello (Membership price) o in
   `STRIPE_MEMBERSHIP_PRICE`; Customer Portal configurato (annullamento a fine periodo, aggiornamento della carta).
6. Prova: «Send test event» dalla Dashboard → riga in `stripe_events` con `status = ignored` o `processed`.

## 7. Chiavi e rotazione

| Chiave | Dove | Rotazione |
|---|---|---|
| `APP_KEY` | `.env` | **Non rigenerarla.** Firma e cifra cookie e sessioni e fa da segreto per l'HMAC dei codici di accesso. Se è compromessa: nuova chiave e la vecchia in `APP_PREVIOUS_KEYS` (Laravel la usa per decifrare); tutti gli utenti si ricollegano. Verifica prima che anche i segreti MFA dello staff restino leggibili |
| `STRIPE_SECRET_KEY` | `.env` | Dashboard → API keys → «Roll key» con scadenza della vecchia (per esempio 1 ora); aggiorna `.env`, `config:cache`. Meglio una restricted key con i soli permessi usati |
| `STRIPE_WEBHOOK_SECRET` | `.env` | Dashboard → endpoint → «Roll secret» con periodo di sovrapposizione; aggiorna `.env`, `config:cache` |
| `QR_SIGNING_PRIVATE_KEY`, `QR_SIGNING_KEY_ID`, `QR_PREVIOUS_PUBLIC_KEYS` | `.env` | Vedi sotto |
| SMTP, database | `.env` | Dal pannello dell'hosting; aggiorna `.env`, `config:cache`, `queue:restart` |

**Chiave dei QR.** Generazione (una volta):

```
php -r 'echo base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), PHP_EOL;'
```

Chiave pubblica da dare al partner del check-in (dalla privata, senza scriverla su disco):

```
php -r '$k=base64_decode(getenv("QR_KEY")); echo base64_encode(sodium_crypto_sign_publickey_from_secretkey($k)), PHP_EOL;'
```

(con `QR_KEY` letta dal `.env` nella stessa riga di comando). Conserva la privata in un gestore di password, separata
dai backup.

**Come funziona la rotazione.** Il QR porta in `k` l'identificativo della chiave che l'ha firmato. Si firma sempre
con la chiave attiva (`QR_SIGNING_PRIVATE_KEY` + `QR_SIGNING_KEY_ID`); si verifica con la chiave attiva e con le
pubbliche elencate in `QR_PREVIOUS_PUBLIC_KEYS` (`k1:base64,k2:base64`), che servono solo a verificare. Un QR con un
`k` sconosciuto è rifiutato. I QR già mandati restano validi; quelli rigenerati dall'area personale usano la chiave
nuova. `qr_key_id` sul biglietto resta la chiave dell'emissione.

**Rotazione programmata** (nessuna compromissione):

1. calcola la chiave pubblica della chiave attuale (comando sopra) e aggiungila a `QR_PREVIOUS_PUBLIC_KEYS` con il
   suo id, per esempio `k1:<pubblica>`;
2. genera una nuova privata, mettila in `QR_SIGNING_PRIVATE_KEY` e cambia `QR_SIGNING_KEY_ID` (per esempio `k2`);
3. `php artisan config:cache` e `queue:restart`;
4. manda al partner la nuova chiave pubblica e l'elenco completo con gli id.

**Chiave compromessa:** stessi passi ma **senza** mettere la vecchia in `QR_PREVIOUS_PUBLIC_KEYS` (chi ha la privata
può creare QR validi), poi reinvio di tutti i biglietti validi e avviso ai partecipanti.

## 8. Backup e prova di ripristino

**Con lo script** [`strumenti/backup/`](../../strumenti/backup/README.md): `backup.sh` fa dump cifrato del database e
dei file (credenziali da `~/.my.cnf` con permessi 600) con rotazione giornaliera e settimanale; `restore-test.sh` fa la
prova di ripristino qui sotto (righe nelle tabelle principali, trigger, archivio dei file) in un database di prova.
Provati il 07/11/2026 su MariaDB 10.11. A mano:

**Database.** Ogni ora durante le vendite, ogni giorno negli altri periodi:

```
mysqldump --defaults-extra-file=$HOME/.my.cnf --single-transaction --routines --triggers --hex-blob \
  --no-tablespaces --default-character-set=utf8mb4 <db> | gzip | openssl enc -aes-256-cbc -pbkdf2 -pass file:$HOME/.backup-pass \
  > ~/backup/eas8-$(date +%Y%m%d-%H%M).sql.gz.enc
```

(credenziali in `~/.my.cnf` con permessi 600, mai sulla riga di comando; `--defaults-extra-file` va per primo).
Cifrare è obbligatorio: il dump contiene dati personali, hash delle password e dati MFA dello staff.

**File.** `shared/storage/app` (foto degli speaker e file privati; gli export si cancellano dopo 7 giorni) ogni giorno.
`.env` e chiave dei QR **non** vanno nei backup: stanno nel gestore di password.

**Copie fuori dall'hosting**, in UE: 30 giornalieri e 12 mensili. Pulizia locale dopo 48 ore.

**Prova di ripristino, ogni mese** (e prima del go-live):

1. Database vuoto `eas8_restore` (stesso server o locale).
2. `openssl enc -d -aes-256-cbc -pbkdf2 -pass file:$HOME/.backup-pass -in <file> | gunzip | mysql eas8_restore`
3. Controlli: `SHOW TRIGGERS` (9), numero di ordini `paid` e biglietti `valid` uguale alla produzione all'ora del dump,
   `DB_DATABASE=eas8_restore php strumenti/genera-doc-db/genera.php --out=/tmp/restore.md` e confronto con
   `docs/tecnico/database.md` (`diff`: devono cambiare solo le righe di intestazione).
4. Annota data ed esito.

**Dopo un ripristino vero:** i pagamenti arrivati dopo il dump non ci sono. Lancia
`php artisan eas:reconcile --hours=<ore dal dump + 2>`: rielabora i pagamenti presenti solo su Stripe; i rimborsi
segnalati come `refund_not_recorded` si registrano rielaborando l'evento da Stripe (Dashboard → evento → «Resend»).

## 9. Migrazioni sicure in produzione

- **Backup prima**, sempre (§8). Il DDL di MySQL/MariaDB non è transazionale: una migrazione che si ferma a metà lascia
  le tabelle già modificate.
- Guarda cosa farà: `php artisan migrate --pretend`.
- **Espandi, poi contrai.** Prima release: colonna nuova `nullable` o con default, codice che scrive su entrambe.
  Release successiva: il codice legge la nuova. Solo dopo, in una terza release, si toglie la vecchia. **Mai** `drop`
  o `rename` di una colonna che il codice in esercizio usa, nella stessa release.
- `CHECK`: per cambiarne uno si fa `DROP CONSTRAINT` e poi `ADD CONSTRAINT` (MySQL 8.0.19+, MariaDB 10.7+); i dati
  esistenti devono già rispettarlo, altrimenti l'`ALTER` fallisce.
- Trigger: si ricreano con `DROP TRIGGER IF EXISTS` + `CREATE TRIGGER` nella migrazione (`CreatesTriggers`); servono
  gli stessi privilegi della prima installazione.
- Le migrazioni vanno provate prima su una copia del database di produzione (staging) con MySQL/MariaDB della stessa
  versione.
- Durante le vendite, migrazioni solo se necessarie e fuori dagli orari di picco; `php artisan down` solo se la
  migrazione blocca tabelle grandi (oggi sono piccole).
- Dopo la migrazione: `php strumenti/genera-doc-db/genera.php` sulla copia locale e commit di `docs/tecnico/`.

## 10. Monitoraggio

Ogni giorno (gli avvisi `StaffAlert` arrivano per email agli admin, ma non bastano):

| Cosa | Come | Cosa fare |
|---|---|---|
| Job falliti | `php artisan queue:failed` | Leggi l'errore; dopo la correzione `php artisan queue:retry <id>` (o `all`) |
| Coda ferma | `SELECT queue, COUNT(*), MIN(FROM_UNIXTIME(created_at)) FROM jobs GROUP BY queue;` | Job più vecchi di 5 minuti = cron fermo o worker bloccato (§12) |
| Eventi Stripe da rivedere | `SELECT id, type, attempts, last_error FROM stripe_events WHERE status = 'failed' OR (status = 'received' AND received_at < NOW() - INTERVAL 10 MINUTE);` | Correggi la causa, poi rimetti in coda: `php artisan tinker --execute="App\Jobs\ProcessStripeEvent::dispatch('<id>');"` |
| Eventi ignorati | `SELECT ignored_reason, COUNT(*) FROM stripe_events WHERE status = 'ignored' GROUP BY ignored_reason;` | `unknown_order` frequenti = un altro ambiente sullo stesso account o metadata mancanti |
| Ordini da decidere | `SELECT id, status, review_reason, buyer_email FROM orders WHERE status IN ('needs_review','needs_refund') OR review_reason IS NOT NULL;` | Pannello → Orders: «Issue tickets» o «Refund instead»; `needs_refund` più vecchi di un'ora = rimborso automatico fallito |
| Rimborsi da riconciliare | `SELECT order_id, amount_cents, status, source FROM refunds WHERE needs_review = 1;` | Rimborsi fatti dalla Dashboard o falliti: verifica su Stripe e rifai il rimborso se serve |
| Contestazioni aperte | Pannello → Disputes, o `SELECT * FROM disputes WHERE closed_at IS NULL ORDER BY evidence_due_by;` | Rispondi dalla Dashboard Stripe entro `evidence_due_by` |
| Riconciliazione | `SELECT occurred_at, JSON_LENGTH(JSON_EXTRACT(data, '$.differences')) FROM audit_log WHERE action = 'stripe.reconciled' ORDER BY id DESC LIMIT 3;` | Una riga per notte; nessuna riga = cron fermo |
| Log | `storage/logs/laravel-<data>.log` | Cerca `ERROR` e gli avvisi «Trigger di difesa non creato» |

## 11. Checklist di go-live

- [ ] `verifica-hosting` senza voci bloccanti; PHP con argon2 e `sodium`.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, HTTPS con redirect, `APP_URL` giusto.
- [ ] Document root su `current/public`; `https://<dominio>/.env` risponde 404.
- [ ] Migrazioni fatte, 9 trigger presenti (o `DB_TRIGGERS_OPTIONAL=true` deciso e annotato).
- [ ] Seeder dei permessi eseguito; admin creato con MFA attiva; codici di recupero conservati.
- [ ] Dati dell'evento, date delle fasce, prezzi e capienze controllati dal pannello.
- [ ] Cron attivo: `jobs` si svuota entro un minuto, `schedule:list` mostra i comandi.
- [ ] SMTP: email del codice e dei biglietti ricevute, SPF/DKIM/DMARC del dominio a posto, `MAIL_RATE_PER_MINUTE`
      entro i limiti della casella.
- [ ] Stripe: endpoint del webhook con gli eventi di §6, segreto nel `.env`, evento di prova `processed`/`ignored`;
      Price della membership e Customer Portal configurati.
- [ ] Acquisto completo in modalità test: biglietto, QR, email, rimborso dal pannello, scadenza di una sessione non
      pagata, membership.
- [ ] Chiave QR generata, chiave pubblica consegnata al partner, export del check-in provato con lui.
- [ ] Backup orario attivo, copia fuori dall'hosting, una prova di ripristino riuscita.
- [ ] Passaggio a `sk_live_` solo con il via libera del cliente: nuovo endpoint live, nuovo `whsec_`, `config:cache`,
      un acquisto vero piccolo e il suo rimborso.

## 12. Cosa fare se

**Il webhook non arriva o risponde errore.** Dashboard Stripe → Webhooks → endpoint: stato delle consegne. 400
«invalid signature» = `STRIPE_WEBHOOK_SECRET` sbagliato o `config:cache` non rifatto; 400 «wrong mode» = chiave live
con eventi di test o viceversa; 5xx = log di Laravel. Stripe riprova per 3 giorni: corretta la causa, gli eventi
arrivano da soli; per quelli più vecchi «Resend» dalla Dashboard. Intanto i pagamenti si confermano dalla pagina di
successo e da `eas:expire-orders`; dopo, `php artisan eas:reconcile --hours=<durata del guasto + 2>`.

**La coda è bloccata.** `SELECT COUNT(*) FROM jobs;` cresce: controlla che il cron giri (log del cron dell'hosting,
`php artisan schedule:run` a mano). Se c'è un lock rimasto da un worker ucciso: `php artisan cache:clear` libera il
`withoutOverlapping` (anche la cache del RateLimiter). Job con `reserved_at` vecchi: tornano disponibili dopo
`retry_after` (`config/queue.php`). Lancia a mano `php artisan queue:work --queue=critical,default --stop-when-empty`
e guarda l'errore.

**Le email non partono.** `queue:failed` per errori SMTP; `jobs` con molti `SendTicketsEmail` = limite di invio
(`MAIL_RATE_PER_MINUTE`): aspettano, non si perdono (si riprovano per 24 ore). Prova la casella con
`php artisan tinker --execute="Mail::raw('prova', fn(\$m) => \$m->to('<tu>'))"`. Se la casella ha raggiunto il limite
giornaliero, piano B: fornitore SMTP transazionale in UE e nuove credenziali nel `.env`. Il codice di accesso non
passa dalla coda: se i clienti non lo ricevono, il problema è l'SMTP stesso. Un biglietto si rimanda con
`php artisan eas:resend-ticket <id o codice breve> [--to=<email>]`.

**Ordine pagato senza biglietti.** Cerca l'ordine nel pannello e lo stato:

| Stato | Causa | Cosa fare |
|---|---|---|
| `pending`, `expired`, `cancelled` ma pagato su Stripe | Webhook non elaborato | `stripe_events` per la sessione (`object_id`): se `failed`, correggi e rimetti in coda; se manca, `php artisan eas:reconcile` |
| `needs_review` | Importo pagato diverso dal totale | Decidi: «Issue tickets» o «Refund instead» |
| `needs_refund` | Pagamento tardivo senza posti | Il rimborso è automatico; se dopo un'ora non è `refunded`, `queue:failed` e Dashboard Stripe |
| `paid` senza righe in `tickets` | Errore durante l'emissione (non dovrebbe: stessa transazione) | Segnala a Giulio con l'id; non inserire biglietti a mano in SQL |
| `paid` con biglietti, email non ricevuta | Email | `eas:resend-ticket`; l'acquirente li vede comunque in `/account` |
