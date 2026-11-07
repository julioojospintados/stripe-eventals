# EAS8 – applicazione Laravel

Autore: Giulio Occhipinti.

Applicazione per vendere l'accesso all'EAS8 (Lisbona, 8–10 aprile 2027): Laravel 13, pannello Filament su `/admin`,
MySQL o MariaDB, Stripe. Il perché delle scelte è in [`../docs/architettura.md`](../docs/architettura.md), tabelle e
regole in [`../db/modello-dati.md`](../db/modello-dati.md).

## Requisiti

- PHP 8.3 o 8.4 con `pdo_mysql`, `mbstring`, `intl`, `sodium` (firma dei QR) e argon2
  (`php -r "var_dump(defined('PASSWORD_ARGON2ID'));"` deve dire `true`).
- Composer 2.
- **MySQL 8.0.19+ o MariaDB 10.7+** (in pratica 10.11 o 11.4). Niente SQLite: i vincoli del database fanno parte
  delle regole e i test girano sul database vero.
- Sull'hosting, per i trigger: `log_bin_trust_function_creators = 1` oppure il log binario spento; altrimenti
  `DB_TRIGGERS_OPTIONAL=true` (vedi sotto).

| Pacchetto | Versione installata | A cosa serve |
|---|---|---|
| `laravel/framework` | 13.40 | framework |
| `filament/filament` | 5.11 (con Livewire 4.4) | pannello `/admin` |
| `spatie/laravel-permission` | 8.5 | ruoli e permessi dello staff |
| `stripe/stripe-php` | 22.1 | Stripe (SDK diretto, senza Cashier) |
| `bacon/bacon-qr-code` | 3.1 | immagine del QR dei biglietti (la usa già la MFA di Filament) |
| `pestphp/pest` | 4.7 (con PHPUnit 12) | test |

Pest 5 chiede PHP 8.4: con PHP 8.3 si resta alla 4. Dopo ogni modifica alle dipendenze: `composer audit`.

## Installazione in locale

```bash
cd sito
composer install
cp .env.example .env
php artisan key:generate
```

Database e utente (esempio con MariaDB; la password è solo locale e va solo nel `.env`):

```sql
create database eas8 character set utf8mb4 collate utf8mb4_unicode_ci;
create database eas8_test character set utf8mb4 collate utf8mb4_unicode_ci;
create user 'eas8'@'localhost' identified by 'una-password-locale';
grant all on eas8.* to 'eas8'@'localhost';
grant all on eas8_test.* to 'eas8'@'localhost';
```

Poi `DB_PASSWORD` nel `.env` e:

```bash
php artisan migrate:fresh --seed
php artisan eas:create-admin tua@email.it --name="Il tuo nome"
php artisan serve
```

Pannello su `http://localhost:8000/admin`. Al primo accesso Filament chiede di attivare la MFA con un'app TOTP
(obbligatoria per tutto lo staff) e mostra i codici di recupero.

**Con Docker** (`compose.yaml`: PHP 8.3, MariaDB 10.11, Mailpit): `docker compose up` crea `.env`, chiave, tabelle e
dati di prova e avvia sito (`:8000`, con lo scheduler per coda ed email) e Mailpit (`:8025`, tutte le email).
Admin: `docker compose exec app php artisan eas:create-admin <email>`; test: `docker compose exec app php artisan test`.

## Il file `.env`

Mai nel repository (è in `.gitignore`). `.env.example` elenca tutte le variabili con un commento; le principali:

| Variabile | Note |
|---|---|
| `APP_KEY` | `php artisan key:generate`; cifra sessioni e colonne cifrate (MFA): non cambiarla dopo il lancio |
| `APP_URL` | indirizzo pubblico, senza barra finale |
| `HASH_DRIVER=argon2id` | password dello staff |
| `DB_CONNECTION` | `mysql` per MySQL, `mariadb` per MariaDB |
| `DB_TRIGGERS_OPTIONAL` | `false`; `true` solo se l'hosting rifiuta i trigger (errore 1419): le migrazioni li saltano con un avviso nel log |
| `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_API_VERSION` | **solo chiavi di test** (`sk_test_…`) finché il cliente non dice altro; senza versione vale quella di `stripe-php` (`2026-10-28.endive`). Una chiave `sk_live_`/`rk_live_` rende l'ambiente «live»: i webhook con `livemode` diverso sono rifiutati |
| `STRIPE_MEMBERSHIP_PRICE` | id del Price annuale della membership (`price_…`), creato nella Dashboard |
| `MAIL_*` | casella SMTP del cliente; in locale `MAIL_MAILER=log` |
| `MAIL_RATE_PER_MINUTE` | email in coda al minuto (predefinito 15, sotto le circa 1.000 l'ora della casella) |
| `QR_SIGNING_PRIVATE_KEY`, `QR_SIGNING_KEY_ID` | firma Ed25519 dei QR; il comando per generare la chiave è nel `.env.example` |

## Migrazioni e dati

`database/migrations/` contiene lo schema della Fase 1, scritto per MySQL e MariaDB (le migrazioni si fermano su un
altro database). Le regole che non devono mai saltare stanno nel database:

- `CHECK`: importi coerenti, capienza (`held + confirmed <= capacity`), forma di tipi di biglietto, omaggi e codici
  sconto, formato del codice breve del biglietto, prefisso `evt_` degli eventi Stripe.
- Indici unici, anche «parziali» con una colonna generata: un ordine `pending` per persona, un prezzo per
  tipo × fascia × ruolo, un prezzo membro per membro/evento/tipo, un accompagnatore per posto del pass, un biglietto
  per riga e numero, un evento Stripe una volta sola.
- Trigger (creati da `App\Database\CreatesTriggers`): transizioni di stato degli ordini, dati fotografati
  dell'ordine, nominativo immutabile salvo correzione firmata, biglietti non cancellabili, fasce attive non
  sovrapposte, consensi e registro audit solo in aggiunta. Senza trigger le stesse regole vanno garantite dai
  Service (passo 2).

Il DDL di MySQL non è transazionale: una migrazione che fallisce a metà lascia le tabelle create. Backup prima di
ogni `migrate` in produzione.

`php artisan db:seed` (o `migrate:fresh --seed`) lancia:

- `RolesAndPermissionsSeeder`: ruoli `admin`, `commerciale`, `organizzatore` e permessi, presi da
  `App\Enums\StaffRole` (vedi «Permessi» sotto). Si può rilanciare anche in produzione: crea i permessi nuovi e li dà
  ai ruoli come da `StaffRole`, cancella quelli tolti dal codice, riallinea `admin`; le modifiche fatte dall'admin nel
  pannello ai permessi di commerciale e organizzatore restano.
- `Eas8Seeder`: l'EAS8 con i dati veri. 3 giorni da 6.000 posti; pass intero 695 € pubblico e 395 € membro
  (prezzi base), early bird solo membri 276,50 €; last minute presente ma spenta e senza prezzo; 10 workshop da 20
  posti a 100 € per tutti (massimo 2 per ordine); accompagnatore, espositore e omaggio presenti ma spenti e senza
  prezzo; membership annuale 200 €. Importi sempre in centesimi interi. **Le date delle fasce sono provvisorie** (early
  bird fino al 31/01/2027, regular fino al 31/03/2027): si cambiano dal pannello. Se l'evento esiste già non fa nulla.

Nessuna password nei seeder: gli utenti dello staff si creano con

```bash
php artisan eas:create-admin email@esempio.it --name="Nome" --role=admin   # oppure commerciale, organizzatore
```

La password si scrive a video (almeno 12 caratteri) e non passa dalla riga di comando; l'operazione va nel registro
audit.

## Test

```bash
php artisan test
```

Pest su MariaDB/MySQL, database `eas8_test` (impostato in `phpunit.xml`; host, utente e password vengono dal
`.env`). `RefreshDatabase` lancia le migrazioni una volta e chiude ogni test in una transazione. Se il database non ha
i trigger, i test che li riguardano vengono saltati con un messaggio (`requireTrigger()` in `tests/Pest.php`).

- `tests/Feature/Database/`: le prove di schema portate da `db/prove/prove_mysql.php` e quelle delle tabelle nuove.
- `tests/Feature/Eas8SeederTest.php`: dati dell'EAS8.
- `tests/Feature/StaffAccessTest.php`: accesso al pannello, permessi dei ruoli, comando `eas:create-admin`.
- `tests/Feature/Panel/PermissionsTest.php`: matrice ruolo × azione scritta a mano da §5.3, seeder rilanciato, ruoli e
  permessi nel pannello, «Resend ticket», prezzo della membership.
- `tests/Feature/Shop/StripeClosuresTest.php`: rimborsi rifiutati, importo diverso dal totale, contestazioni,
  riconciliazione, eventi ignorati con il motivo.
- `tests/Feature/Shop/`: prezzi e codici sconto, riserva e checkout, pagamenti e conferma tardiva, webhook, membership.
- `tests/Feature/Account/`: accesso con codice, area personale (IDOR), biglietti, QR ed email.
- `tests/Feature/Panel/`: pagine e azioni per ruolo (403 fuori dal ruolo), omaggio, revoca, correzione del nome,
  rimborso, fasce sovrapposte, codici sconto e prezzi, export (colonne, solo biglietti validi, coda e file privato).
- `tests/Feature/PublicPagesTest.php`: pagine pubbliche (200, prezzi, posti, lingua).
- `tests/Concurrency/`: ultimo posto, prezzo membro e pagamento confermato tre volte **in parallelo**, con processi
  separati (`tests/Concurrency/worker.php`) e transazioni vere che si aspettano sui lock. Usano `DatabaseTruncation`
  (dati confermati, tabelle svuotate prima di ogni test) e girano dopo `Feature`.

Prima di una pull request, gli stessi controlli della CI (`.github/workflows/ci.yml`, test anche su MySQL 8.0):

```bash
composer audit && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G && php artisan test
```

PHPStan con Larastan, livello 5 (`phpstan.neon`, zero errori, nessuna baseline); `vendor/bin/pint` corregge lo stile.

Stripe non viene mai chiamato nei test: `TestCase` mette nel container `Tests\Fakes\FakeStripe` al posto di
`App\Services\StripeGateway` (sessioni in memoria, chiamate registrate con la loro Idempotency-Key).
`tests/TODO-passo-2.md` dice dove sta ogni prova del passo 2 e cosa passa al passo 3.

## Cartelle

| Cartella | Cosa c'è |
|---|---|
| `app/Models` | un model per tabella, con relazioni e `casts`; email salvate in minuscolo, codici sconto in maiuscolo |
| `app/Enums` | stati e tipi (`OrderStatus` con le transizioni ammesse, `TicketKind`, `PriceRole`…) e `StaffRole` con i permessi |
| `app/Database/CreatesTriggers.php` | creazione dei trigger nelle migrazioni, con `DB_TRIGGERS_OPTIONAL` |
| `app/Services` | le regole di vendita: `PriceResolver`, `CapacityService`, `CheckoutService`, `PaymentService`, `TicketService`, `MembershipService`, `LoginCodeService`, `ComplimentaryService`, `DisputeService`, `Catalog` (prezzi da mostrare), `CsvExports`; `StripeGateway` (le sole chiamate a Stripe) |
| `app/Jobs` | `ProcessStripeEvent`, `RefundOrderFully` (coda `critical`), `SendTicketsEmail` (coda `default`, con limite di invio), `GenerateCsvExport` |
| `app/Http/Controllers` | pagina dell'evento, login con codice, carrello e checkout, area personale, membership, webhook Stripe, scaricamento degli export |
| `app/Mail`, `resources/views` | email (codice, biglietti con QR, avvisi allo staff), pagine pubbliche e area personale |
| `app/Filament`, `app/Policies` | risorse del pannello, pagina Export, widget del cruscotto; una Policy per model |
| `public/css/site.css`, `public/fonts/inter`, `lang/` | stile delle pagine pubbliche (senza build), Inter servito in locale, testi `lang/it.json` e `lang/{en,it}/*.php` |
| `app/Console/Commands` | `eas:create-admin`, `eas:expire-orders`, `eas:resend-ticket`, `eas:reconcile` |
| `app/Providers/Filament/AdminPanelProvider.php` | pannello `/admin`: login, MFA obbligatoria |
| `config/tickets.php` | chiave di firma dei QR; Stripe è in `config/services.php` |
| `database/migrations`, `database/factories`, `database/seeders` | schema, factory per i test, dati |
| `tests/` | test Pest |

Convenzioni (da `db/modello-dati.md` §1): chiavi UUID tranne `users` (intero), importi in centesimi, date
`datetime` in UTC (il fuso `Europe/Lisbon` solo per mostrare le ore), stati come `string` + `CHECK` + enum PHP, testi
tradotti in colonne JSON `{"en": "…", "it": "…"}`. Ordini, biglietti e registro audit non si cancellano.

## Passo 2: soldi e biglietti

Il perché è in `docs/architettura.md` §5.1, §6, §7, §8, §9, §11; qui cosa fa il codice.

| Cosa | Dove | Regole |
|---|---|---|
| Accesso clienti | `LoginCodeService`, `/login` | codice di 6 cifre via email, salvato come HMAC con `APP_KEY`, 10 minuti, 5 tentativi, limiti per email (3 ogni 10 minuti) e per IP; account creato al primo accesso, nessuna password; `session()->regenerate()`. Lo staff non entra con il codice (solo dal pannello con password e MFA). Il codice parte dopo la risposta (`afterResponse`), non dalla coda del cron |
| Prezzo | `PriceResolver`, `CheckoutService` | fascia attiva fissata sull'ordine; membro se la membership copre tutta la sessione (31 minuti); prezzo membro solo sul biglietto intestato al membro che compra, una volta per evento e tipo (`member_price_claims`); codice sconto riga per riga, sulla riga del membro il migliore tra prezzo membro e pubblico scontato (mai entrambi, salvo `combinable_with_role_price`); utilizzo contato alla riserva e restituito se l'ordine non si paga; importi in centesimi |
| Riserva | `CapacityService`, `CheckoutService::reserve()` | lock sull'ordine e sui pool in ordine di id, `UPDATE … WHERE held + confirmed + n <= capacity`; sessione 31 minuti, riserva 36 (sotto i 30 rifiutata); un ordine aperto per persona (il precedente si chiude dopo aver fatto scadere la sua sessione Stripe); limiti per tipo e per pool; workshop solo con un pass intero (nell'ordine o già comprato), al massimo 2 per ordine, mai due alla stessa ora |
| Checkout | `CheckoutService::startCheckout()`, `POST /orders/{id}/checkout` | conferma dei nominativi obbligatoria (non si cambiano più); `price_data`, solo carta (Apple Pay e Google Pay compresi), `metadata.order_id`, `Idempotency-Key: checkout-<id>`; ordini a costo zero confermati senza Stripe |
| Webhook | `StripeWebhookController`, `ProcessStripeEvent` | firma verificata, `livemode` controllato, `insertOrIgnore` in `stripe_events` e job nella stessa transazione, risposta subito; il job blocca l'evento e lo elabora una volta: `checkout.session.completed/expired`, `async_payment_*`, `charge.refunded`, `refund.failed`/`refund.updated`, `charge.dispute.*`, `customer.subscription.*`, `invoice.*`; altri eventi e oggetti sconosciuti (ordine o pagamento non nostro) `ignored` con il motivo in `ignored_reason` (`unhandled_type`, `unknown_order`…); errore → `failed` con l'errore, nuovi tentativi, avviso agli admin all'ultimo |
| Pagamento | `PaymentService` | lock sull'ordine, transizioni solo da `pending`/`processing` (o pagamento tardivo), stessa tabella del trigger anche senza trigger; conferma tardiva: riprenota se c'è posto (`paid_late`), altrimenti `needs_refund`, nessun biglietto, `RefundOrderFully` in coda nella stessa transazione (`Idempotency-Key: auto-refund-<id>`) e avviso agli admin. La pagina di successo rilegge la sessione da Stripe e chiama lo stesso codice |
| Importo diverso | `PaymentService::markPaid()` | se `amount_total` di Stripe non è il totale dell'ordine: posti tenuti, nessun biglietto, ordine `needs_review` (`review_reason = amount_mismatch`), avviso agli admin. Nel pannello «Issue tickets» (`approveReview`) o «Refund instead» (`refundReview`: posti liberati, `needs_refund`, rimborso di quanto incassato su Stripe), con il motivo e l'audit |
| Rimborso rifiutato | `PaymentService::refundFailed()` | `refund.failed` o `refund.updated` con stato `failed`/`canceled` (anche in risposta a `createRefund`): rimborso segnato così e `needs_review`, ordine con `review_reason = refund_failed`, avviso; ordine e biglietti non cambiano stato (niente riattivati in silenzio). Un nuovo rimborso usa una Idempotency-Key nuova (`…-retry-<n>`) |
| Contestazioni | `DisputeService`, tabella `disputes` | `charge.dispute.created/updated`: biglietti dell'ordine `disputed` (fuori dall'export del check-in), avviso; `closed` vinta (`won`, `warning_closed`, `prevented`) → di nuovo `valid`; persa → `cancelled`, posti liberati, `review_reason = dispute_lost`. Chiusa una volta: eventi arrivati dopo non cambiano nulla. Le prove si mandano dalla Dashboard |
| Riconciliazione | `eas:reconcile` (ogni notte alle 3:30, ora di Lisbona) | con `StripeGateway::listCheckoutSessions()`/`listRefunds()` confronta le ultime 48 ore: pagato su Stripe ma non qui → evento `evt_reconcile_<sessione>` in `stripe_events` e lo stesso job `ProcessStripeEvent` (una volta sola); pagato qui ma non su Stripe e rimborsi non registrati → solo segnalati. Rapporto `stripe.reconciled` nel registro audit, avviso agli admin se ci sono differenze |
| Scadenze | `eas:expire-orders` (ogni minuto) | riserve scadute: prima legge la sessione su Stripe (aperta → `expire`; pagata → conferma), poi rilascia posti, prezzo membro e codice |
| Membership | `MembershipService`, `/membership` | Checkout `mode=subscription` con `STRIPE_MEMBERSHIP_PRICE`; attiva subito (pagina di successo o webhook, idempotente); poi solo rileggendo la subscription, ignorando eventi più vecchi dell'ultimo; Customer Portal |
| Biglietti | `TicketService`, `SendTicketsEmail`, `eas:resend-ticket` | un biglietto per nominativo (unique su riga e numero); QR `EAS1.<dati>.<firma>` con ticket, `qr_version`, evento e `qr_key_id`, firmato Ed25519 (chiave nel `.env`, niente nome nel QR); email con il QR in PNG all'acquirente (tutti) e a ogni partecipante (il suo), in coda con `MAIL_RATE_PER_MINUTE` e `sent_messages` contro i doppi invii |
| Area personale | `/account` | ordini e biglietti solo dalle relazioni dell'utente (un id altrui dà 404) |

Rotte: `/login`, `/login/code`, `/tickets` (carrello), `POST /cart`, `POST /orders/{id}/checkout`, `/account`,
`/account/orders/{id}` (anche pagina di successo), `/account/tickets/{id}/qr`, `POST /membership`,
`/membership/success`, `POST /membership/portal`, `POST /stripe/webhook`. Gli errori di vendita sono codici stabili
(`sold_out`, `too_many_for_pool`…) con il testo in `lang/{en,it}/shop.php`; con `Accept: application/json` le
rotte rispondono in JSON.

Comandi:

```bash
php artisan eas:expire-orders                     # lo lancia lo scheduler ogni minuto
php artisan eas:reconcile                         # lo lancia lo scheduler ogni notte; --hours=72 per una finestra più lunga
php artisan eas:resend-ticket ABCD2345            # codice breve o id del biglietto
php artisan eas:resend-ticket ABCD2345 --to=altro@esempio.it   # anche dal pannello: Tickets → Resend ticket
```

Dal pannello si fa tutto tranne la creazione del primo admin (`eas:create-admin`).

### Provare in locale con Stripe in modalità test

1. Nel `.env`: `STRIPE_SECRET_KEY=sk_test_…`, `QR_SIGNING_PRIVATE_KEY` (comando nel `.env.example`), e per la
   membership un Price annuale di test in `STRIPE_MEMBERSHIP_PRICE`.
2. Inoltro dei webhook con la [Stripe CLI](https://docs.stripe.com/stripe-cli) (installata dal pacchetto ufficiale, non
   con script remoti):

   ```bash
   stripe login
   stripe listen --forward-to localhost:8000/stripe/webhook
   ```

   `stripe listen` stampa il segreto `whsec_…`: va in `STRIPE_WEBHOOK_SECRET` (poi `php artisan config:clear`).
3. In tre terminali: `php artisan serve`, `stripe listen …` e `php artisan schedule:work` (fa quello che il cron fa
   sull'hosting: worker della coda e scadenze ogni minuto). In alternativa `php artisan queue:work --queue=critical,default`.
4. `http://localhost:8000/login`, codice nel log (`MAIL_MAILER=log`, `storage/logs/`), poi `/tickets`. Carta di prova
   `4242 4242 4242 4242`, data futura, CVC qualsiasi. Per simulare un evento: `stripe trigger checkout.session.completed`.

### Cron sull'hosting

Una sola riga di cron, ogni minuto (nessun processo sempre attivo):

```text
* * * * * cd /home/<utente>/eas8/current && php artisan schedule:run >> /dev/null 2>&1
```

Lo scheduler (`routes/console.php`) lancia ogni minuto `queue:work --queue=critical,default --stop-when-empty
--max-time=50` (vuota la coda e si ferma prima del giro successivo; `withoutOverlapping` evita due worker insieme) ed
`eas:expire-orders`; ogni notte alle 3:30 (Lisbona) `eas:reconcile`; ogni giorno pulisce i codici di accesso scaduti. Un job aspetta quindi al massimo circa un minuto:
per questo il codice di accesso parte fuori coda e la pagina di successo conferma il pagamento da sola. Al deploy:
`php artisan queue:restart`. Se l'hosting vieta `proc_open`, righe di cron separate per `queue:work` ed
`eas:expire-orders` (docs/architettura.md §8).

## Passo 3: pannello e pagine

### Pannello `/admin` e ruoli

Le Policy (`app/Policies`) controllano solo permessi, mai nomi di ruolo (vedi «Permessi»). Fuori dal permesso la
pagina risponde 403 e le azioni non compaiono.

| Menu | Risorsa | admin | commerciale | organizzatore |
|---|---|---|---|---|
| Event | Evento, date e giorni | modifica | lettura | modifica |
| Sales | Tipi di biglietto (con i pool), fasce, prezzi tipo × fascia × ruolo (in euro), capienze | modifica | lettura | modifica |
| Sales | Codici sconto (autore e registro audit) | ✔ | ✔ | ✔ |
| Orders | Ordini in lettura con righe, biglietti e rimborsi | ✔ | ✔ | senza importi |
| Orders | Azione «Refund in full» (con `RefundOrderFully`, causa e motivo) | ✔ | ✔ | — |
| Orders | «Issue complimentary ticket» e revoca dell'omaggio | ✔ | lettura | ✔ |
| Orders | Biglietti; «Correct name» (refuso, massimo 3, motivo, audit; nuova email = nuovo QR e invio) | ✔ | lettura | lettura |
| Orders | «Resend ticket» (all'intestatario o a un altro indirizzo, audit) | ✔ | ✔ | — |
| Orders | Ordini `needs_review`: «Issue tickets» o «Refund instead»; «Reconcile with Stripe» (in coda) | ✔ | ✔ | — |
| Orders | Membership in lettura (si cambiano in Stripe) | ✔ | ✔ | — |
| Orders | Contestazioni in lettura | ✔ | ✔ | — |
| Sales | Prezzo della membership e Price ID Stripe (senza Price ID vale `STRIPE_MEMBERSHIP_PRICE`) | modifica | lettura | — |
| Exports | Export CSV (vedi sotto) | tutti | tutti | check-in |
| System | Utenti dello staff (ruolo, password di almeno 12 caratteri), registro audit | ✔ | — | — |
| System | Ruoli e permessi: lettura; permessi di commerciale e organizzatore modificabili, con audit (admin no) | ✔ | — | — |

### Permessi

In `App\Enums\StaffRole`: per ogni risorsa `<risorsa>.view|create|update|delete` (`event`, `ticket_types`,
`price_tiers`, `ticket_prices`, `capacity_pools`, `discount_codes`, `staff`) e le azioni `orders.view`,
`orders.view_amounts`, `orders.refund`, `orders.review`, `orders.export`, `complimentary.view`, `complimentary.issue`,
`tickets.view`, `tickets.correct_attendee`, `tickets.resend`, `attendees.export`, `consents.export`, `memberships.view`,
`membership_plans.view`, `membership_plans.update`, `disputes.view`, `reconciliation.run`, `roles.view`, `roles.update`,
`audit_log.view` (più `invite_codes.*` e `videos.manage` per la fase 2). `admin` ha tutto tranne `*.delete`: nessun ruolo
cancella (si spegne, non si cancella). Le Policy di vendita estendono `ResourcePolicy`; `RolePolicy` è registrata in
`AppServiceProvider`. Per aggiungere un permesso: una riga in `StaffRole`, il controllo nella Policy, poi
`php artisan db:seed --class=RolesAndPermissionsSeeder` e una riga nella matrice di `PermissionsTest`.

Regole nel form, prima del database: fasce attive sovrapposte (messaggio con la fascia in conflitto; il trigger resta
l'ultima difesa), capienza sotto i posti già presi, prezzo doppio per tipo × fascia × ruolo, forma del codice sconto.
L'omaggio (`ComplimentaryService`) crea un ordine `complimentary` già pagato a 0 €, prende i posti su `confirmed` con
lo stesso UPDATE condizionale (pool pieno = errore), manda il biglietto e scrive l'audit; la revoca libera i posti.
Il rimborso dal pannello usa lo stesso job del rimborso automatico (`Idempotency-Key: staff-refund-<id>`, una sola
volta per ordine); ordine e biglietti diventano `refunded` quando arriva `charge.refunded`.

Il cruscotto mostra ordini in `needs_refund` e `needs_review` (con link al filtro), ordini pagati e biglietti validi, biglietti venduti
per tipo e fascia, posti rimasti per pool.

### Export CSV

Pagina «CSV exports». Ogni export va nel registro audit. Fino a 2.000 righe il file si scarica subito; oltre si prepara
in coda (`GenerateCsvExport`) in `storage/app/private/exports/<id utente>/`, compare nella pagina e si scarica solo
da chi l'ha chiesto (`/admin/exports/{file}`); lo scheduler cancella i file dopo 7 giorni. Testi che iniziano con
`= + - @` escono preceduti da `'` (niente formule nei fogli di calcolo).

| Export | Permesso | Colonne |
|---|---|---|
| Check-in partner | `attendees.export` (tutti) | `ticket_id`, `qr_version`, `short_code`, `attendee_name`, `ticket_type`, `ticket_name`, `days`, `status`: solo biglietti `valid` (non quelli contestati) |
| Ordini | `orders.export` (admin, commerciale) | id, date, stato, origine, email, ruolo, fascia, importi in euro, rimborsato, codice sconto, payment intent |
| Consensi newsletter | `consents.export` (admin, commerciale) | `email`, `granted_at`, `source`, `locale`, `text_version`: l'ultima riga per email, solo se è un consenso |

### Pagine pubbliche

Blade senza JavaScript e senza build (`public/css/site.css`), aspetto del prototipo: Inter servito dal sito (con il
font di sistema come riserva), grafite, arancio solo su fondo scuro, oro per l'azione principale; mobile-first,
bersagli di almeno 44px, fuoco visibile, WCAG 2.2 AA.

| Rotta | Pagina |
|---|---|
| `/` | evento: date, luogo, prezzo del pass nella fascia attiva (pubblico e membro), workshop, membership |
| `/tickets` | fasce con prezzi pubblico e membro, nominativi del pass, workshop con posti liberi, codice sconto |
| `/account/orders/{id}` | conferma dei nomi e pagamento, poi esito (pagato, in corso, scaduto, in rimborso) con i QR |
| `/membership` | prezzo, cosa comprende, attivazione o portale Stripe |
| `/login`, `/login/code` | accesso con codice via email |
| `/account` | biglietti con QR, membership e portale, ordini |
| `/language/{en,it}` | selettore della lingua (sessione e `users.locale`; senza scelta vale la lingua del browser) |

Testi in inglese nelle viste, italiano in `lang/it.json`; stati in `lang/{en,it}/status.php`; contenuti tradotti con
`localized()` e importi con `euro()` (`app/helpers.php`).

## Cosa c'è e cosa manca

**Passo 1:** dipendenze, schema della Fase 1 con i vincoli, model, enum, factory, seeder dell'EAS8, ruoli e
permessi, pannello `/admin` con accesso solo allo staff e MFA, comando `eas:create-admin`, test di schema.

**Passo 2:** vendita, Stripe, webhook, biglietti con QR, membership, area personale, con le tabelle `login_codes`,
`sent_messages` e `refunds` e i test di `tests/TODO-passo-2.md`.

**Passo 3 (questo):** pannello con Policy per ruolo, omaggi, correzione del nominativo, rimborso dal pannello,
cruscotto, export CSV, pagine pubbliche EN/IT.

**Chiusure del backend:** rimborsi rifiutati, ordini `needs_review` per importo diverso, contestazioni, riconciliazione
notturna `eas:reconcile`, eventi ignorati con il motivo, permessi granulari con ruoli e permessi nel pannello,
«Resend ticket» e prezzo della membership dal pannello (migrazione `2026_11_08_090000`).

**Ancora aperto nella Fase 1:** eventi Stripe e job falliti nel pannello, sessione dello staff più breve e IP ammessi (dipendono dall'hosting), pulizia
dei payload Stripe dopo 90 giorni, raccolta del consenso newsletter nel checkout (la tabella e l'export ci sono),
accompagnatori, pagine termini e privacy (dal cliente), messaggi di validazione in italiano (`lang/it/validation.php`).
Scelte da confermare: URL senza prefisso `/en/` `/it/` (la lingua sta in sessione: niente `hreflang` per pagina, a
differenza di §10 dell'architettura); il commerciale vede evento e vendita in sola lettura come in §5.3.

**Fase 2** (fuori da questi passi): programma completo con sale, tracce e speaker (le colonne mancanti di
`program_sessions` arrivano con lui), video, Mailchimp, codici d'invito per gli espositori, API del partner.
