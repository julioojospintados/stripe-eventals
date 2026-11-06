# Modello dei dati (Laravel + Filament)

Autore: Giulio Occhipinti. Aggiornato al 06/11/2026.

Sostituisce `db/schema.sql` (Postgres su Supabase), che resta come riferimento storico in
[`archivio/schema-postgres-supabase.sql`](archivio/schema-postgres-supabase.sql). Il modello di dominio e le regole
sono gli stessi; cambia **dove** stanno: prima quasi tutto in funzioni SQL e policy RLS, ora nei Service PHP con le
difese ultime nel database. Migrazioni di esempio per le parti delicate in [`migrazioni-bozza/`](migrazioni-bozza/).

Etichette: `[sure]` provato in questa sessione, `[likely]` fonte affidabile non ricontrollata ora, `[uncertain]`
da verificare.

---

## 1. Convenzioni

| Tema | Scelta |
|---|---|
| Database | Postgres 16+ consigliato; MySQL 8.0.16+ o MariaDB 10.6+ ammessi con le differenze del §5 |
| Chiavi | `users.id` bigint (default di Laravel, compatibile con Filament e spatie/laravel-permission); tutte le altre tabelle UUID con il trait `HasUuids` (UUID ordinati nel tempo nelle versioni recenti di Laravel `[likely]`) |
| Importi | Centesimi interi (`integer`), mai float. Valuta ISO minuscola come in Stripe (`eur`) |
| Date | `timestampTz`, tutto salvato in UTC; il fuso dell'evento (`Europe/Lisbon`) solo per mostrare le ore |
| Stati | Colonne `string` + vincolo `CHECK` + enum PHP (`App\Enums\OrderStatus` ecc.). Niente tipi `enum` nativi: aggiungere un valore non chiede di riscrivere la colonna |
| Email | Salvate in minuscolo dal model (mutator), indice unico normale: niente `citext`, uguale su Postgres e MySQL |
| Testi tradotti | Colonne JSON `{"it": "...", "en": "..."}` gestite da `spatie/laravel-translatable` (da far passare a `supply-chain-risk-auditor`); lingua di riserva `en` |
| Cancellazioni | Ordini, biglietti, rimborsi e registro audit non si cancellano mai (`restrictOnDelete`); un utente che chiede la cancellazione si anonimizza |

## 2. Dove stanno le regole

| Livello | Cosa ci va | Perché |
|---|---|---|
| **DB** | Invarianti che un bug non deve poter rompere: capienza (`CHECK held + confirmed <= capacity`), indici unici (una riserva aperta per persona, un prezzo membro per membro/evento/tipo, un biglietto per riga e numero, un evento Stripe una volta sola, un ingresso accettato per biglietto/giorno/varco), coerenza degli importi, transizioni di stato degli ordini (trigger), registro audit solo in aggiunta (trigger) | È l'ultima difesa: vale anche per una query scritta male, un comando `tinker`, un job doppio |
| **Service** | I processi: calcolo del prezzo e dello sconto, riserva, conferma del pagamento, rilascio, riprenotazione tardiva, cambio nominativo, rimborso. Sempre dentro `DB::transaction()` con `lockForUpdate()` sull'ordine e poi sui pool in ordine di id | Un posto solo per la logica, provato con test Pest su Postgres (e MySQL se si sceglie quello) |
| **Policy** | Chi può vedere o fare cosa: acquirente sui propri ordini e biglietti, membro sui video, admin / commerciale / organizzatore nel pannello Filament | Sostituisce l'RLS di Supabase (`docs/architettura.md` §5) |
| **Job** | Lavori lenti o verso servizi esterni: elaborazione degli eventi Stripe, scadenza delle sessioni, rimborsi automatici, email, export, sincronizzazioni | Regola 3 del ruolo: niente lavori pesanti nella richiesta dell'utente |

---

## 3. Tabelle

Legenda nella colonna «Regole»: **DB**, **Service**, **Policy**, **Job** come nel §2.

### 3.1 Persone, ruoli, accesso

#### `users`

| Colonne | Vincoli e indici | Regole |
|---|---|---|
| `id`, `email`, `name`, `password` (null per gli acquirenti: accedono con codice via email), `locale` (`it`/`en`), `stripe_customer_id`, `marketing_consent_at`, `email_verified_at`, colonne MFA di Filament per lo staff, `anonymized_at`, timestamp | `email` unica; `stripe_customer_id` unico | **Service**: un solo Customer Stripe per persona, creato al primo acquisto. **Policy**: l'utente legge e modifica solo sé stesso. Password dello staff con **argon2id** (`config/hashing.php`, regola 1 del ruolo) |

Membro **non è un ruolo salvato**: si deriva da `memberships` al momento dell'acquisto (`MembershipService::isMemberUntil($user, $at)`), come nello schema precedente.

#### Ruoli dello staff (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`)

Tabelle di `spatie/laravel-permission`. Ruoli e permessi sono definiti **nel codice** (seeder `RolesAndPermissionsSeeder`), non modificabili dal pannello; dal pannello l'admin assegna solo i ruoli alle persone.

| Ruolo | Permessi (dettaglio in `docs/architettura.md` §5) |
|---|---|
| `admin` | Tutto, compresi utenti, ruoli, registro audit, impostazioni, chiavi del partner |
| `commerciale` | Ordini, pagamenti, rimborsi, fatture, membership (lettura), cambio nominativo su richiesta, export contabili, codici sconto (proposta) |
| `organizzatore` | Eventi, giorni, sale, tracce, sessioni e interventi, speaker, tipi di biglietto, tier e prezzi, capienze, video della member area, export dei partecipanti per il check-in |

#### `login_codes` (nuova)

| Colonne | Vincoli e indici | Regole |
|---|---|---|
| `id`, `email`, `code_hash` (hash del codice di 6 cifre, mai il codice in chiaro), `expires_at` (10 minuti), `attempts`, `consumed_at`, `ip`, `created_at` | indice (`email`, `created_at`) | **Service**: un codice valido alla volta per email, massimo 5 tentativi, poi nuovo codice. Limiti con `RateLimiter` per email e per IP (regola 1). **Job**: pulizia giornaliera |

#### `consents` (nuova)

| Colonne | Vincoli e indici | Regole |
|---|---|---|
| `id`, `user_id` (null se solo email), `email`, `purpose` (`newsletter`, …), `granted` (bool), `text_version`, `source` (pagina o modulo), `ip`, `occurred_at` | indice (`email`, `purpose`, `occurred_at`) | Solo in aggiunta: ogni consenso o revoca è una riga nuova. **Job**: sincronizzazione con la newsletter. Base giuridica e testi: catena legale |

#### `partner_clients` (nuova, per il check-in del partner)

| Colonne | Vincoli e indici | Regole |
|---|---|---|
| `id`, `name`, `event_id`, `key_id`, `secret` (cast `encrypted`), `allowed_ips` (JSON), `active`, `last_used_at`, timestamp | `key_id` unico | **Policy**: solo admin crea o revoca. **Service**: firma HMAC delle richieste (`docs/architettura.md` §11) |

### 3.2 Evento e programma

Tutti i testi visibili al pubblico sono JSON tradotti (`it`, `en`).

| Tabella | Colonne principali | Vincoli e indici | Regole |
|---|---|---|---|
| `events` | `slug`, `title`*, `summary`*, `description`*, `status` (`draft`, `published`, `cancelled`, `archived`), `timezone` (`Europe/Lisbon`), `starts_on`, `ends_on`, `venue_name`, `venue_address`, `currency`, `sales_open_at`, `sales_close_at`, `name_change_deadline_hours` (48) | `slug` unico; CHECK `ends_on >= starts_on`, finestra vendite coerente, ore ≥ 0 | **Policy**: organizzatore e admin scrivono; il pubblico legge solo `published` (scope `published()`) |
| `event_days` | `event_id`, `day`, `label`* | unico (`event_id`, `day`) | |
| `rooms` | `event_id`, `name`*, `physical_capacity` | CHECK capienza > 0 | |
| `tracks` | `event_id`, `name`*, `color`, `position` | | |
| `sessions` | `event_id`, `event_day_id`, `room_id`, `track_id`, `slug`, `title`*, `abstract`*, `format`, `access` (`included`, `included_booking`, `paid`), `capacity_pool_id`, `starts_at`, `ends_at`, `published` | unico (`event_id`, `slug`); CHECK `ends_at > starts_at`; CHECK `access = 'included' or capacity_pool_id is not null`. **Postgres**: vincolo di esclusione «stessa sala, orari sovrapposti» (`btree_gist`) | **Service** + validazione del form Filament: giorno, sala e pool dello stesso evento (su Postgres anche FK composte); su MySQL la sovrapposizione delle sale si controlla solo nel Service |
| `session_parts` | `session_id`, `position`, `title`*, `abstract`*, `starts_at`, `ends_at` | unico (`session_id`, `position`) | |
| `speakers` | `slug`, `full_name`, `headline`*, `bio`* (HTML ripulito), `photo_path`, `website`, `links` (JSON), `user_id`, `published` | `slug` unico | **Service**: foto ridimensionata e senza dati EXIF, nome file casuale; HTML della bio ripulito prima del salvataggio |
| `session_speakers` | `session_id`, `session_part_id`, `speaker_id`, `role`, `position` | unico (`session_id`, `session_part_id`, `speaker_id`) | |

`*` = campo tradotto.

### 3.3 Cosa si vende

| Tabella | Colonne principali | Vincoli e indici | Regole |
|---|---|---|---|
| `ticket_types` | `event_id`, `kind` (`full_pass`, `day_pass`, `paid_session`), `name`*, `description`*, `event_day_id`, `session_id`, `max_per_order` (4), `sales_start`, `sales_end`, `active`, `position` | CHECK forma: `full_pass` senza giorno né sessione, `day_pass` con giorno, `paid_session` con sessione | Per ora **nessun giornaliero** (indicazione del 06/11/2026): il tipo `day_pass` resta nel modello ma non si crea |
| `capacity_pools` | `event_id`, `name`, `capacity`, `held`, `confirmed`, `max_per_order` | **DB**: CHECK `held + confirmed <= capacity`, contatori ≥ 0 (migrazione `100100`) | **Service**: aggiornamenti solo condizionali; ridurre la capienza sotto i posti presi fallisce, voluto |
| `ticket_type_pools` | `ticket_type_id`, `pool_id` | chiave primaria composta | Il pass intero consuma il pool di ogni giorno; il workshop il suo pool |
| `ticket_prices` | `ticket_type_id`, `price_role` (`public`, `member`), `amount_cents`, `currency`, `label`* («Early bird»), `valid_from`, `valid_to` | CHECK importo ≥ 0, `valid_to > valid_from`. **Postgres**: esclusione «due prezzi validi nello stesso istante per la stessa coppia» | Su MySQL: **Service** con `lockForUpdate` sul tipo di biglietto prima di salvare un prezzo. Manca il prezzo `member` → il membro paga il `public` |
| `membership_plans` | `name`*, `stripe_price_id`, `amount_cents` (20000), `currency`, `billing_interval` (`year`), `active` | `stripe_price_id` unico | Il Price ricorrente esiste in Stripe (serve al Customer Portal) |
| `memberships` | `user_id`, `plan_id`, `stripe_subscription_id`, `stripe_customer_id`, `status` (stati Stripe), `current_period_start`, `current_period_end`, `cancel_at_period_end`, `canceled_at`, `ended_at`, `last_stripe_event_at` | `stripe_subscription_id` unico; indice (`user_id`, `status`, `current_period_end`) | **Job**: aggiornata solo dagli eventi Stripe, rileggendo la subscription; un evento più vecchio di `last_stripe_event_at` si ignora. Il sito legge solo questa (regola 2) |
| `discount_codes` | `code` (6–40 caratteri, salvato in maiuscolo), `description`, `event_id`, `discount_type`, `percent_off`, `amount_off_cents`, `currency`, `eligible_roles` (JSON), `combinable_with_role_price`, `applies_to_membership`, `max_redemptions`, `per_user_limit`, `redeemed_count`, `valid_from`, `valid_to`, `active`, `created_by` | `code` unico; CHECK forma percentuale/importo | **Service**: calcolo riga per riga lato server, utilizzo contato con `UPDATE … WHERE redeemed_count < max_redemptions` nella transazione della riserva. Nessun Coupon Stripe per i biglietti |
| `discount_code_ticket_types` | `discount_code_id`, `ticket_type_id` | chiave primaria composta | Nessuna riga = vale per tutti i tipi dell'evento |

**Prezzi dell'EAS8** (dal cliente, 06/11/2026), da inserire da Filament:

| Tipo | Pubblico | Membro | Early bird −30% (pubblico / membro) |
|---|---|---|---|
| Pass intero | 695,00 € | 395,00 € | 486,50 € / 276,50 € (se lo sconto vale anche sul prezzo membro: da chiarire) |
| Workshop | 100,00 € | non indicato (paga il pubblico) | da chiarire se l'early bird vale anche qui |
| Membership | 200,00 €/anno | — | — |

### 3.4 Ordini, riserve, biglietti

| Tabella | Colonne principali | Vincoli e indici | Regole |
|---|---|---|---|
| `orders` | `event_id`, `user_id`, `buyer_email`, `status`, `price_role` (fotografato), `currency`, `subtotal_cents`, `discount_cents`, `total_cents`, `tax_cents`, `discount_code_id`, `stripe_checkout_session_id`, `stripe_payment_intent_id`, `stripe_invoice_id`, `checkout_expires_at`, `discount_reserved`, `paid_at`, `livemode`, `stripe_amount_total_cents`, `locale`, `billing_details` (JSON: ragione sociale, partita IVA, indirizzo, se servono per la fattura) | **DB** (migrazione `100000`): `total = subtotal − discount`; `paid` ha `paid_at`; **una sola `pending` per persona** (indice unico parziale); **transizioni ammesse** (trigger) | **Service**: `OrderService`, `PaymentService::markPaid()` (l'unico che emette biglietti). **Policy**: l'acquirente vede i propri; commerciale e admin tutti |
| `order_items` | `order_id`, `ticket_type_id`, `quantity`, `price_role`, `list_unit_amount_cents`, `unit_discount_cents`, `unit_amount_cents`, `total_cents`, `stripe_line_name`, `ticket_price_id`, `attendees` (JSON) | **DB**: importi coerenti; righe `member` di quantità 1; un nominativo per unità | **Service**: riga `member` intestata all'acquirente; ordine, tipo e ruolo di una riga non cambiano dopo l'insert |
| `member_price_claims` | `user_id`, `event_id`, `kind`, `event_day_id`, `session_id`, `order_id`, `order_item_id`, `created_at`, `released_at`, `release_reason` | **DB** (migrazione `100200`): una sola prenotazione attiva per (membro, evento, tipo, giorno, sessione); CHECK sulla forma della chiave | **Service**: creata con la riga member, liberata quando l'ordine o il biglietto si chiude, ripresa al pagamento tardivo (con avviso se nel frattempo è stata usata) |
| `capacity_holds` | `order_id`, `pool_id`, `quantity`, `status` (`active`, `awaiting_payment`, `converted`, `released`), `expires_at`, `released_at`, `release_reason` | **DB** (migrazione `100100`): unica per (ordine, pool); `active` ha scadenza; `released` ha data | **Service** + **Job**: si rilascia solo dopo la conferma di Stripe che la sessione non si può più pagare |
| `tickets` | `event_id`, `order_id`, `order_item_id`, `seq`, `ticket_type_id`, `price_role`, `attendee_name`, `attendee_email`, `attendee_user_id`, `status` (`valid`, `cancelled`, `refunded`, `disputed`), `qr_version`, `qr_key_id`, `short_code`, `issued_at`, `cancelled_at`, `name_changes_count` | **DB**: unico (`order_item_id`, `seq`) contro l'emissione doppia; `short_code` unico (8 caratteri senza ambigui) | **Service** `TicketService::changeAttendee()`: cambio email fino a 48 ore prima dell'inizio (prima sessione pubblicata), mai sui biglietti a prezzo membro, `qr_version + 1`, audit, email al nuovo partecipante. **Postgres** (consigliato): lo stesso controllo anche in un trigger, come nello schema precedente |
| `session_bookings` | `session_id`, `ticket_id`, `status`, `cancelled_at` | **DB**: una prenotazione attiva per (sessione, biglietto) (indice parziale; su MySQL colonna generata) | **Service** `book()`: posto preso dal pool con l'aggiornamento condizionale |
| `waitlist_entries` | `pool_id`, `user_id`, `quantity`, `status`, `offered_until` | **DB**: una iscrizione aperta per (pool, persona) | Fuori dal MVP |
| `checkins` | `id` (generato da chi scansiona), `ticket_id`, `event_id`, `event_day_id`, `session_id`, `qr_version`, `scanned_at`, `received_at`, `source` (`partner`, `staff`), `partner_client_id`, `device_result`, `server_result` | **DB**: un solo ingresso accettato per (biglietto, giorno, varco) | Riempita solo se il partner rimanda gli ingressi via API (fase 2) |
| `refunds` | `order_id`, `ticket_id`, `amount_cents`, `currency`, `cause` (`event_cancelled`, `paid_without_capacity`), `reason`, `status`, `source` (`admin_panel`, `automatic`, `stripe_dashboard`, `dispute`), `stripe_refund_id`, `idempotency_key`, `needs_review`, `requested_by` | **DB**: CHECK causa obbligatoria per i rimborsi nati da noi; `automatic` solo per `paid_without_capacity` | **Policy**: solo commerciale e admin, con MFA. Nessun rimborso self-service |

### 3.5 Member area

| Tabella | Colonne principali | Vincoli e indici | Regole |
|---|---|---|---|
| `video_collections` (nuova) | `slug`, `title`*, `description`*, `event_id` (congresso passato, facoltativo), `published`, `position` | `slug` unico | **Policy**: organizzatore e admin scrivono |
| `videos` (nuova) | `collection_id`, `title`*, `description`*, `speaker_ids` (o tabella ponte), `provider` (`bunny`, …), `provider_video_id`, `duration_seconds`, `published`, `members_only` (true) | unico (`provider`, `provider_video_id`) | **Policy** `VideoPolicy::view`: membership attiva adesso. **Service**: link firmato con scadenza breve, creato a ogni apertura della pagina |

### 3.6 Integrazione e servizio

| Tabella | Colonne principali | Vincoli e indici | Regole |
|---|---|---|---|
| `stripe_events` | `id` (`evt_…`), `type`, `livemode`, `api_version`, `stripe_created_at`, `received_at`, `status`, `attempts`, `last_error`, `processed_at`, `object_id`, `payload` | **DB** (migrazione `100300`): chiave primaria sull'id dell'evento; CHECK prefisso e stato | **Job** `ProcessStripeEvent`; `payload` azzerato dopo 90 giorni |
| `sent_messages` (nuova) | `dedupe_key`, `mailable`, `to_hash`, `sent_at` | `dedupe_key` unico | **Job**: un'email «ordine confermato» parte una volta anche se il job si ripete |
| `audit_log` | `occurred_at`, `actor_id`, `actor_type` (`user`, `staff`, `system`, `stripe`, `partner`), `action`, `entity_type`, `entity_id`, `ip`, `user_agent`, `data` (JSON) | **DB**: trigger che vieta UPDATE e DELETE (Postgres e MySQL) | **Service**: azioni dello staff, cambi nominativo, rimborsi, export, accessi al pannello |
| `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions` | Tabelle standard di Laravel | | Coda su driver `database`: il job nasce nella stessa transazione dei dati |
| `exports`, `imports`, `failed_import_rows`, `notifications` | Tabelle delle azioni di export di Filament `[likely]` | | Export in coda, file privati con scadenza |

Tabelle dello schema precedente non più usate: `profiles` (diventa `users`), `user_roles` (diventa spatie/laravel-permission), `rate_limits` (diventa il `RateLimiter` di Laravel sulla cache), `jobs` personalizzata (diventa la coda di Laravel). Il ruolo `checkin_staff` sparisce: il check-in lo fa il partner.

---

## 4. Regole provate e come si riprovano

Le regole sotto sono state provate su Postgres 16 con lo schema precedente il 05/11/2026 (`docs/architettura.md` §7.3).
Ora che la logica passa dalle funzioni SQL ai Service PHP, **ogni prova diventa un test Pest** da far girare sul
database scelto prima del rilascio. Le difese del database delle 4 migrazioni sono già state provate il 06/11/2026
(§6).

| Regola | Dove | Test da scrivere |
|---|---|---|
| Riserva sotto i 30 minuti rifiutata | Service | `hold_too_short` |
| Una sola riserva aperta per persona | DB + Service | secondo `pending` rifiutato; `expire` della sessione precedente |
| Esaurito senza overselling, anche in parallelo | DB + Service | due checkout sull'ultimo posto: uno passa, l'altro `sold_out` |
| Limite per ordine sul pool | Service | 3 posti su un pool con limite 2 → `too_many_for_pool` |
| Rilascio solo dopo conferma di Stripe | Service + Job | job con sessione `open` → `expire` poi chiusura; `complete` → `markPaid` |
| Pagamento tardivo senza posti | Service | `needs_refund`, 0 biglietti, rimborso automatico in coda, avviso, email |
| Pagamento tardivo con posti | Service | `paid_late`, posto riprenotato |
| Webhook ripetuto o in parallelo | DB + Service | `already_done`; 2 biglietti e non 4 |
| Sessione Stripe diversa dall'ordine | Service | `session_mismatch` |
| Codice sconto: utilizzo contato alla riserva, restituito alla scadenza | Service | `discount_exhausted` al secondo; disponibile dopo la scadenza |
| Un biglietto a prezzo membro per membro, evento e tipo | DB + Service | secondo pass membro in un secondo ordine → prezzo pubblico; workshop membro ammesso |
| Prenotazione liberata da ordine annullato | Service | nuovo pass membro ammesso |
| Pagamento tardivo dopo un nuovo pass membro | Service | biglietto emesso e avviso `member_price_limit_late` |
| Cambio nominativo a 47 / 49 ore | Service (+ trigger su Postgres) | rifiutato / ammesso |
| Biglietto membro non cedibile, correzione del solo nome | Service (+ trigger su Postgres) | rifiutato / ammessa |
| Rimborso senza causa | DB | rifiutato dal `CHECK` |
| Transizioni di stato non ammesse | DB + enum PHP | `pending → refunded` rifiutata |

---

## 5. Postgres o MySQL: cosa cambia

| Tema | Postgres 16+ | MySQL 8.0.16+ / MariaDB |
|---|---|---|
| `CHECK` | Applicati da sempre | Applicati da MySQL 8.0.16 `[likely]` (prima venivano letti e ignorati) e da MariaDB 10.2 `[likely]`. MySQL vieta i `CHECK` su colonne usate da azioni referenziali delle FK `[likely]`: da provare sulla migrazione `100200` |
| Indici unici parziali (`… where status = 'pending'`) | Nativi; `nulls not distinct` da Postgres 15 | Non esistono: **colonna generata** che vale la chiave solo quando la riga è «attiva» e `NULL` altrimenti, con indice unico (più `NULL` sono ammessi). Esempi nelle migrazioni `100000` e `100200` |
| Vincoli di esclusione (sale sovrapposte, listini sovrapposti) | Nativi con `btree_gist` | Non esistono: controllo nel Service con `lockForUpdate` sulla riga padre (sala o tipo di biglietto) |
| Trigger | PL/pgSQL, possono annullare con `raise exception` | Esistono, con `SIGNAL SQLSTATE '45000'`; non possono modificare la tabella che li ha attivati `[likely]` |
| Lock | `SELECT … FOR UPDATE` (`lockForUpdate()`), livello `READ COMMITTED`: l'`UPDATE` condizionale aspetta il lock e rilegge la riga aggiornata | Stesso metodo; livello di default `REPEATABLE READ` con gap lock: più deadlock possibili, quindi `DB::transaction($fn, attempts: 3)` e lock sempre nello stesso ordine `[likely]` |
| Errori dentro una transazione | Un errore annulla tutta la transazione: serve una transazione annidata (savepoint) per intercettare un `unique_violation` e continuare | Un errore annulla solo l'istruzione; i savepoint servono lo stesso per coerenza |
| JSON | `jsonb` con indici GIN | `json`; `->jsonb()` di Laravel diventa `json` |
| Date | `timestamptz` | `timestamp` ha il limite del 2038 `[likely]`: preferire `datetime` in UTC |

Raccomandazione: **Postgres** se il server lo permette. Con MySQL le difese restano, ma più regole passano dal database ai soli Service.

---

## 6. Prove fatte sulle migrazioni bozza (06/11/2026)

Le 4 migrazioni sono state controllate con `php -l` (PHP 8.3.6, nessun errore) `[sure]`. Il loro ramo Postgres è
stato tradotto in SQL con un finto schema builder (Laravel non è installato: il DDL è vicino a quello di Laravel ma
non identico) ed eseguito su **Postgres 16.15** con dati finti `[sure]`:

| Prova | Esito |
|---|---|
| Secondo ordine `pending` della stessa persona | 🟢 rifiutato (`orders_one_pending_per_user`) |
| Totale diverso da subtotale − sconto; `paid` senza data | 🟢 rifiutati dai `CHECK` |
| Transizioni `pending → refunded` e `refunded → paid` | 🟢 rifiutate (`order_invalid_transition`); `expired → paid` (pagamento tardivo) ammessa |
| Riga `member` di quantità 2; nominativi diversi dalla quantità | 🟢 rifiutate |
| Aggiornamento condizionale oltre la capienza | 🟢 0 righe (esaurito) |
| Aumento «cieco» dei venduti oltre la capienza; capienza ridotta sotto i posti presi | 🟢 rifiutati (`capacity_pools_no_oversell`) |
| Due transazioni in parallelo sull'ultimo posto | 🟢 la prima prende il posto, la seconda aspetta il lock e aggiorna 0 righe |
| Riserva attiva senza scadenza; rilasciata senza data; doppia per lo stesso ordine e pool | 🟢 rifiutate |
| Secondo pass a prezzo membro dello stesso membro in un altro ordine | 🟢 rifiutato (`member_price_claims_one_active`); workshop a prezzo membro ammesso |
| Due prenotazioni del prezzo membro in parallelo | 🟢 la seconda aspetta la prima e fallisce sull'indice unico |
| Prenotazione liberata (ordine rimborsato) e nuovo pass membro | 🟢 ammesso |
| Evento Stripe ripetuto con `on conflict do nothing` | 🟢 una riga sola |
| Id evento senza `evt_`; `processed` senza data | 🟢 rifiutati |

Non provati: il ramo MySQL (nessun MySQL in questa sessione) e le migrazioni con il vero `Schema` di Laravel.
