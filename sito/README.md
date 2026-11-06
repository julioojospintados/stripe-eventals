# EAS8 – applicazione Laravel

Autore: Giulio Occhipinti.

Applicazione per vendere l'accesso all'EAS8 (Lisbona, 8–10 aprile 2027): Laravel 13, pannello Filament su `/admin`,
MySQL o MariaDB, Stripe. Il perché delle scelte è in [`../docs/architettura.md`](../docs/architettura.md), tabelle e
regole in [`../db/modello-dati.md`](../db/modello-dati.md).

## Requisiti

- PHP 8.3 o 8.4 con `pdo_mysql`, `mbstring`, `intl`, `sodium` (firma dei QR) e argon2
  (`php -r "var_dump(defined('PASSWORD_ARGON2ID'));"` deve dire `true`).
- Composer 2.
- **MySQL 8.0.16+ o MariaDB 10.7+** (in pratica 10.11 o 11.4). Niente SQLite: i vincoli del database fanno parte
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
  `App\Enums\StaffRole` (la tabella dei permessi è lì, uguale a `docs/architettura.md` §5.3). Si può rilanciare.
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
- `tests/Feature/Shop/`: prezzi e codici sconto, riserva e checkout, pagamenti e conferma tardiva, webhook, membership.
- `tests/Feature/Account/`: accesso con codice, area personale (IDOR), biglietti, QR ed email.
- `tests/Concurrency/`: ultimo posto, prezzo membro e pagamento confermato tre volte **in parallelo**, con processi
  separati (`tests/Concurrency/worker.php`) e transazioni vere che si aspettano sui lock. Usano `DatabaseTruncation`
  (dati confermati, tabelle svuotate prima di ogni test) e girano dopo `Feature`.

Stripe non viene mai chiamato nei test: `TestCase` mette nel container `Tests\Fakes\FakeStripe` al posto di
`App\Services\StripeGateway` (sessioni in memoria, chiamate registrate con la loro Idempotency-Key).
`tests/TODO-passo-2.md` dice dove sta ogni prova del passo 2 e cosa passa al passo 3.

## Cartelle

| Cartella | Cosa c'è |
|---|---|
| `app/Models` | un model per tabella, con relazioni e `casts`; email salvate in minuscolo, codici sconto in maiuscolo |
| `app/Enums` | stati e tipi (`OrderStatus` con le transizioni ammesse, `TicketKind`, `PriceRole`…) e `StaffRole` con i permessi |
| `app/Database/CreatesTriggers.php` | creazione dei trigger nelle migrazioni, con `DB_TRIGGERS_OPTIONAL` |
| `app/Services` | le regole di vendita: `PriceResolver`, `CapacityService`, `CheckoutService`, `PaymentService`, `TicketService`, `MembershipService`, `LoginCodeService`; `StripeGateway` (le sole chiamate a Stripe) |
| `app/Jobs` | `ProcessStripeEvent`, `RefundOrderFully` (coda `critical`), `SendTicketsEmail` (coda `default`, con limite di invio) |
| `app/Http/Controllers` | login con codice, carrello e checkout, area personale, membership, webhook Stripe |
| `app/Mail`, `resources/views` | email (codice, biglietti con QR, avvisi allo staff) e pagine essenziali (l'aspetto arriva al passo 3) |
| `app/Console/Commands` | `eas:create-admin`, `eas:expire-orders`, `eas:resend-ticket` |
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
| Webhook | `StripeWebhookController`, `ProcessStripeEvent` | firma verificata, `livemode` controllato, `insertOrIgnore` in `stripe_events` e job nella stessa transazione, risposta subito; il job blocca l'evento e lo elabora una volta: `checkout.session.completed/expired`, `async_payment_*`, `charge.refunded`, `customer.subscription.*`, `invoice.*`; altri eventi `ignored`; errore → `failed` con l'errore, nuovi tentativi, avviso agli admin all'ultimo |
| Pagamento | `PaymentService` | lock sull'ordine, transizioni solo da `pending`/`processing` (o pagamento tardivo), stessa tabella del trigger anche senza trigger; conferma tardiva: riprenota se c'è posto (`paid_late`), altrimenti `needs_refund`, nessun biglietto, `RefundOrderFully` in coda nella stessa transazione (`Idempotency-Key: auto-refund-<id>`) e avviso agli admin. La pagina di successo rilegge la sessione da Stripe e chiama lo stesso codice |
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
php artisan eas:resend-ticket ABCD2345            # codice breve o id del biglietto
php artisan eas:resend-ticket ABCD2345 --to=altro@esempio.it
```

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
`eas:expire-orders`; ogni giorno pulisce i codici di accesso scaduti. Un job aspetta quindi al massimo circa un minuto:
per questo il codice di accesso parte fuori coda e la pagina di successo conferma il pagamento da sola. Al deploy:
`php artisan queue:restart`. Se l'hosting vieta `proc_open`, righe di cron separate per `queue:work` ed
`eas:expire-orders` (docs/architettura.md §8).

## Cosa c'è e cosa manca

**Passo 1:** dipendenze, schema della Fase 1 con i vincoli, model, enum, factory, seeder dell'EAS8, ruoli e
permessi, pannello `/admin` con accesso solo allo staff e MFA, comando `eas:create-admin`, test di schema.

**Passo 2 (questo):** quanto sopra, con le tabelle `login_codes`, `sent_messages` e `refunds`, e i test di
`tests/TODO-passo-2.md`. Non ancora: riconciliazione notturna (`ReconcileStripe`), contestazioni
(`charge.dispute.*`), omaggi, accompagnatori e correzione del nominativo (sono azioni del pannello), pulizia dei
payload Stripe dopo 90 giorni.

**Passo 3:** sito pubblico EN/IT con l'aspetto definitivo, risorse Filament con le Policy (evento e date, tipi, fasce e prezzi, capienze, codici sconto, ordini e
membership in lettura, utenti dello staff, registro audit, eventi Stripe, job falliti), export CSV per il partner del
check-in e per la contabilità, export dei consensi, sessione dello staff più breve, elenco di IP ammessi se l'hosting
lo permette; omaggi, accompagnatori, correzione del nominativo e fasce con errore leggibile nel form; riconciliazione
notturna e contestazioni. Da decidere: `spatie/laravel-translatable` per i campi tradotti (l'ultima versione chiede
PHP 8.4).

**Fase 2** (fuori da questi passi): programma completo con sale, tracce e speaker (le colonne mancanti di
`program_sessions` arrivano con lui), video, Mailchimp, codici d'invito per gli espositori, API del partner.
