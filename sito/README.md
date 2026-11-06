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
| `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_API_VERSION` | **solo chiavi di test** (`sk_test_…`) finché il cliente non dice altro |
| `MAIL_*` | casella SMTP del cliente; in locale `MAIL_MAILER=log` |
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
- `tests/TODO-passo-2.md`: le prove che aspettano i Service (prezzi, capienza in parallelo, webhook, conferma
  tardiva).

## Cartelle

| Cartella | Cosa c'è |
|---|---|
| `app/Models` | un model per tabella, con relazioni e `casts`; email salvate in minuscolo, codici sconto in maiuscolo |
| `app/Enums` | stati e tipi (`OrderStatus` con le transizioni ammesse, `TicketKind`, `PriceRole`…) e `StaffRole` con i permessi |
| `app/Database/CreatesTriggers.php` | creazione dei trigger nelle migrazioni, con `DB_TRIGGERS_OPTIONAL` |
| `app/Console/Commands` | `eas:create-admin` |
| `app/Providers/Filament/AdminPanelProvider.php` | pannello `/admin`: login, MFA obbligatoria |
| `config/tickets.php` | chiave di firma dei QR; Stripe è in `config/services.php` |
| `database/migrations`, `database/factories`, `database/seeders` | schema, factory per i test, dati |
| `tests/` | test Pest |

Convenzioni (da `db/modello-dati.md` §1): chiavi UUID tranne `users` (intero), importi in centesimi, date
`datetime` in UTC (il fuso `Europe/Lisbon` solo per mostrare le ore), stati come `string` + `CHECK` + enum PHP, testi
tradotti in colonne JSON `{"en": "…", "it": "…"}`. Ordini, biglietti e registro audit non si cancellano.

## Cosa c'è e cosa manca

**Passo 1 (questo):** dipendenze, schema della Fase 1 con i vincoli, model, enum, factory, seeder dell'EAS8, ruoli e
permessi, pannello `/admin` con accesso solo allo staff e MFA, comando `eas:create-admin`, test di schema.

**Passo 2:** i Service (`PriceResolver`, `CapacityService`, `OrderService`, `PaymentService`, `TicketService`,
`MembershipService`), checkout Stripe e webhook in coda, scadenze e conferma tardiva dal cron, codici sconto calcolati
dal server, accesso con codice via email (tabella `login_codes`), area personale, biglietti con QR firmato ed email in
coda (tabella `sent_messages`), rimborsi (tabella `refunds`), sito pubblico EN/IT, test dell'elenco
`tests/TODO-passo-2.md`. Da decidere: `spatie/laravel-translatable` per i campi tradotti (l'ultima versione chiede
PHP 8.4).

**Passo 3:** risorse Filament con le Policy (evento e date, tipi, fasce e prezzi, capienze, codici sconto, ordini e
membership in lettura, utenti dello staff, registro audit, eventi Stripe, job falliti), export CSV per il partner del
check-in e per la contabilità, export dei consensi, sessione dello staff più breve, elenco di IP ammessi se l'hosting
lo permette.

**Fase 2** (fuori da questi passi): programma completo con sale, tracce e speaker (le colonne mancanti di
`program_sessions` arrivano con lui), video, Mailchimp, codici d'invito per gli espositori, API del partner.
