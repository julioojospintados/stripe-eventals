# Modello dei dati (Laravel + Filament, MySQL / MariaDB)

Autore: Giulio Occhipinti. Aggiornato al 06/11/2026 (secondo giro di risposte del cliente).

Sostituisce `db/schema.sql` (Postgres su Supabase), che resta come riferimento storico in
[`archivio/schema-postgres-supabase.sql`](archivio/schema-postgres-supabase.sql). Il modello di dominio e le regole
provate (capienza, idempotenza dei webhook, prezzo membro per evento e tipo, sconti calcolati dal server) restano;
dal 06/11/2026 il database principale è **MySQL 8.0.16+ o MariaDB 10.6+** (hosting condiviso del cliente) e le
migrazioni bozza sono scritte per quello. Il ramo Postgres della mattina, provato su Postgres 16.15, resta nella storia
di git. Migrazioni di esempio per le parti delicate in [`migrazioni-bozza/`](migrazioni-bozza/), prove in
[`prove/`](prove/).

Etichette: `[sure]` provato in questa sessione, `[likely]` fonte affidabile non ricontrollata ora, `[uncertain]`
da verificare.

---

## 1. Convenzioni

| Tema | Scelta |
|---|---|
| Database | MySQL 8.0.16+ o MariaDB 10.6+, InnoDB, `utf8mb4`; migrazioni provate su MySQL 8.0.46 e MariaDB 10.11.14 (§6) |
| Chiavi | `users.id` bigint (default di Laravel, compatibile con Filament e spatie/laravel-permission); tutte le altre tabelle UUID con `HasUuids`. Su MySQL `char(36)`, su MariaDB il tipo nativo `UUID` (scelta di Laravel 13 `[sure]`): nelle espressioni con testo serve `cast(… as char(36))` |
| Importi | Centesimi interi, mai float. Valuta ISO minuscola come in Stripe (`eur`) |
| Date | `datetime` in UTC (`$table->dateTime()`, `$table->datetimes()`), non `timestamp` (limite del 2038 e conversioni di fuso); il fuso dell'evento (`Europe/Lisbon`) solo per mostrare le ore |
| Stati | Colonne `string` + `CHECK` + enum PHP (`App\Enums\OrderStatus` ecc.). Niente `enum` nativi di MySQL |
| `CHECK` | Ogni confronto su una colonna che può essere `NULL` porta il suo `is not null`: un `CHECK` che vale `NULL` passa (§5) |
| Chiavi esterne | `RESTRICT` sulle colonne usate in un `CHECK` o in una colonna generata: MySQL e MariaDB rifiutano `SET NULL` e `CASCADE` lì (§5). Ordini, biglietti, prezzi usati, codici e fasce non si cancellano: si disattivano |
| Email | Salvate in minuscolo dal model (mutator), indice unico normale |
| Testi tradotti | Colonne JSON `{"en": "...", "it": "..."}` con `spatie/laravel-translatable`; **lingua predefinita e di riserva `en`** |
| Nomi | Le sessioni del programma si chiamano **`program_sessions`** (colonna `program_session_id`): `sessions` è la tabella delle sessioni HTTP di Laravel (scontro trovato installando Laravel il 06/11/2026) |
| Cancellazioni | Ordini, biglietti, rimborsi e registro audit non si cancellano mai; un utente che chiede la cancellazione si anonimizza |

## 2. Dove stanno le regole

| Livello | Cosa ci va | Perché |
|---|---|---|
| **DB, sempre** | Capienza (`CHECK held + confirmed <= capacity`), indici unici (una riserva aperta per persona, un prezzo membro per membro/evento/tipo, un prezzo per tipo × fascia × ruolo, un accompagnatore per posto del pass, un biglietto per riga e numero, un evento Stripe una volta sola), coerenza degli importi, forma di omaggi e tipi di biglietto | Ultima difesa: vale anche per una query scritta male, un comando `tinker`, un job doppio. Non dipende dai trigger |
| **DB, con i trigger** | Transizioni di stato degli ordini; ruolo, fascia, origine e importi dell'ordine immutabili; nominativo immutabile salvo correzione firmata; biglietti non cancellabili e non riattivabili; fasce non sovrapposte; registro audit solo in aggiunta | Servono `TRIGGER` e, con il log binario attivo, `log_bin_trust_function_creators = 1` sull'hosting (`docs/architettura.md` §3). Senza, `DB_TRIGGERS_OPTIONAL=true` li salta e queste regole restano solo nel Service |
| **Service** | Processi: calcolo del prezzo (fascia e ruolo) e dello sconto, riserva, conferma del pagamento, rilascio, riprenotazione tardiva, omaggi, accompagnatori, correzione del nominativo, rimborso, import del programma. Sempre dentro `DB::transaction($fn, attempts: 3)` con `lockForUpdate()` sull'ordine e poi sui pool in ordine di id | Un posto solo per la logica, provato con test Pest sulla stessa versione di MySQL o MariaDB dell'hosting |
| **Policy** | Chi può vedere o fare cosa: acquirente sui propri ordini e biglietti, ruoli sui video, admin / commerciale / organizzatore nel pannello | Sostituisce l'RLS di Supabase (`docs/architettura.md` §5) |
| **Job** | Lavori lenti o verso servizi esterni, avviati dal cron ogni minuto: eventi Stripe, scadenza delle sessioni, rimborsi automatici, email, export, import, Mailchimp | Regola 3 del ruolo; niente processi sempre attivi sull'hosting |

---

## 3. Tabelle

Legenda nella colonna «Regole»: **DB**, **Service**, **Policy**, **Job** come nel §2. Fase 2 = fuori
dall'apertura delle vendite (`docs/architettura.md` §18).

### 3.1 Persone, ruoli, accesso

#### `users`

| Colonne | Vincoli e indici | Regole |
|---|---|---|
| `id`, `email`, `name`, `password` (null per gli acquirenti), `locale` (`en`/`it`, predefinito `en`), `stripe_customer_id`, `marketing_consent_at`, `email_verified_at`, colonne MFA di Filament per lo staff, `anonymized_at`, timestamp | `email` unica; `stripe_customer_id` unico | **Service**: un solo Customer Stripe per persona. **Policy**: l'utente legge e modifica solo sé stesso. Password dello staff con **argon2id** |

Membro **non è un ruolo salvato**: si deriva da `memberships` (`MembershipService::isMemberUntil($user, $at)`).
Speaker è un ruolo derivato anche lui (`speakers.user_id` o un omaggio con motivo `speaker`), usato per i video.

#### Ruoli dello staff (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`)

Tabelle di `spatie/laravel-permission`, ruoli e permessi definiti nel codice.

| Ruolo | Permessi (dettaglio in `docs/architettura.md` §5.3) |
|---|---|
| `admin` | Tutto, compresi utenti, ruoli, registro audit, chiavi del partner e **correzione di un refuso nel nominativo** |
| `commerciale` | Ordini, pagamenti, rimborsi, fatture, membership (lettura), export contabili, **codici sconto**, codici d'invito |
| `organizzatore` | Evento e date, fasce e prezzi, tipi di biglietto, capienze, **codici sconto**, omaggi, programma e speaker (con import), video, export dei partecipanti |

#### `login_codes`, `consents`, `partner_clients`

Invariate: codice di accesso salvato come hash (10 minuti, 5 tentativi); consensi solo in aggiunta (ora sincronizzati
con **Mailchimp**); chiavi del partner con segreto cifrato (fase 2).

### 3.2 Evento e programma

Tutti i testi visibili al pubblico sono JSON tradotti (`en`, `it`).

| Tabella | Colonne principali | Vincoli e indici | Regole |
|---|---|---|---|
| `events` | `slug`, `title`*, `summary`*, `description`*, `status` (`draft`, `published`, `cancelled`, `archived`), `timezone` (`Europe/Lisbon`), `starts_on`, `ends_on`, `venue_name`, `venue_address`, `currency`, `sales_open_at`, `sales_close_at` | `slug` unico; CHECK `ends_on >= starts_on`, finestra di vendita coerente | **Policy**: organizzatore e admin scrivono, **date comprese** (modificabili dal pannello); il pubblico legge solo `published`. Tolta `name_change_deadline_hours` (nessun cambio di nome) |
| `event_days` | `event_id`, `day`, `label`* | unico (`event_id`, `day`) | **Service**: cambiando le date dell'evento il pannello propone di aggiornare i giorni; un giorno con biglietti venduti non si cancella |
| `rooms` | `event_id`, `code`, `name`*, `physical_capacity`, `position` | unico (`event_id`, `code`); CHECK capienza > 0 | 12 sale per l'EAS8 |
| `tracks` | `event_id`, `name`*, `color`, `position` | | |
| `program_sessions` | `event_id`, `code` (chiave del foglio di import), `event_day_id`, `room_id`, `track_id`, `slug`, `title`*, `abstract`*, `format`, `access` (`included`, `included_booking`, `paid`), `capacity_pool_id`, `starts_at`, `ends_at`, `published` | unico (`event_id`, `slug`) e (`event_id`, `code`); CHECK `ends_at > starts_at`; CHECK `access = 'included' or capacity_pool_id is not null` | **Service** + validazione del form e dell'import: giorno, sala e pool dello stesso evento; **sale sovrapposte** controllate nel Service con `lockForUpdate` sulla sala (MySQL non ha vincoli di esclusione). Fase 2 (~120 sessioni) |
| `session_parts` | `program_session_id`, `position`, `title`*, `abstract`*, `starts_at`, `ends_at` | unico (`program_session_id`, `position`) | Fase 2 |
| `speakers` | `code` (chiave del foglio di import), `slug`, `full_name`, `headline`*, `bio`* (HTML ripulito), `photo_path`, `website`, `links` (JSON), `user_id`, `published` | `slug` e `code` unici | Fase 2 (~30 speaker). **Service**: foto ricodificate senza EXIF, bio ripulite anche all'import |
| `session_speakers` | `program_session_id`, `session_part_id`, `speaker_id`, `role`, `position` | unico (`program_session_id`, `session_part_id`, `speaker_id`) | Fase 2 |

`*` = campo tradotto. L'import da CSV usa le tabelle `imports` e `failed_import_rows` di Filament (§3.6).

### 3.3 Cosa si vende

| Tabella | Colonne principali | Vincoli e indici | Regole |
|---|---|---|---|
| `ticket_types` | `event_id`, `kind` (`full_pass`, `day_pass`, `paid_session`, `companion`, `exhibitor`, `staff_pass`), `channel` (`public`, `invite`, `panel`), `name`*, `description`*, `event_day_id`, `program_session_id`, `max_per_order` (4), `max_per_host` (solo companion), `sales_start`, `sales_end`, `active`, `position` | **DB** (migrazione `090000`): CHECK forma per tipo (giornaliero con giorno, workshop con sessione, gli altri senza); `staff_pass` solo canale `panel`; `companion` con `max_per_host` tra 1 e 5, gli altri senza | Nessun giornaliero per ora. **Service**: canale `invite` acquistabile solo con un codice d'invito valido; canale `panel` mai nel carrello |
| `price_tiers` (nuova) | `event_id`, `code` (`early_bird`, `regular`, `last_minute`), `name`*, `starts_at`, `ends_at`, `position` | **DB** (`090000`): unico (`event_id`, `code`); CHECK `ends_at > starts_at`; **trigger** che rifiuta fasce sovrapposte nello stesso evento | **Service**: lock sull'evento prima di salvare, errore leggibile nel form. Date modificabili dal pannello. A ogni istante al massimo una fascia attiva, quindi una sola per ogni tipo di biglietto |
| `ticket_prices` | `ticket_type_id`, `price_tier_id` (null = **prezzo base**), `price_role` (`public`, `member`), `amount_cents`, `currency` | **DB** (`090000`): unico (`ticket_type_id`, fascia o `*`, `price_role`) con colonna generata `tier_key`; importo ≥ 0 | **Service** `PriceResolver`: fascia attiva → prezzo della fascia → prezzo base → per il membro senza prezzo, il pubblico → nessun prezzo = non acquistabile. Sostituisce `valid_from`/`valid_to` e il vincolo di esclusione di Postgres |
| `capacity_pools`, `ticket_type_pools` | invariati (migrazione `100100`) | **DB**: CHECK `held + confirmed <= capacity` | Pass, espositore, accompagnatore e omaggio consumano il pool di ogni giorno (**proposta**); ogni workshop il suo pool da 20 |
| `membership_plans` | `name`*, `stripe_price_id`, `amount_cents` (20000), `currency`, `billing_interval` (`year`), `active` | `stripe_price_id` unico | |
| `memberships` | `user_id`, `plan_id`, `stripe_subscription_id`, `stripe_customer_id`, `status`, `current_period_start`, `current_period_end`, `cancel_at_period_end`, `canceled_at`, `ended_at`, `activated_at`, `last_stripe_event_at` | `stripe_subscription_id` unico; indice (`user_id`, `status`, `current_period_end`) | **Service** `activateFromCheckout()`: **attiva subito** a `checkout.session.completed` pagato (webhook o pagina di successo, idempotente). Poi aggiornata solo dagli eventi Stripe |
| `discount_codes` | `code`, `description`, `event_id`, `discount_type`, `percent_off`, `amount_off_cents`, `currency`, `eligible_roles` (JSON), `combinable_with_role_price`, `applies_to_membership`, `max_redemptions`, `per_user_limit`, `redeemed_count`, `valid_from`, `valid_to`, `active`, `created_by` | `code` unico; CHECK forma percentuale/importo | **Policy**: commerciale e organizzatore (e admin) creano; audit con l'autore. **Service**: calcolo riga per riga, utilizzo contato alla riserva |
| `discount_code_ticket_types` | `discount_code_id`, `ticket_type_id` | chiave primaria composta | Nessuna riga = vale per tutti i tipi dell'evento |
| `invite_codes` (nuova, fase 2) | `code`, `event_id`, `ticket_type_id` (tipo `exhibitor`), `company_name`, `max_uses`, `used_count`, `valid_to`, `active`, `created_by` | `code` unico; CHECK `used_count <= max_uses` | **Service**: utilizzo contato come i codici sconto (`UPDATE … WHERE used_count < max_uses` nella riserva, restituito alla scadenza). **Policy**: commerciale e admin |

**Prezzi dell'EAS8** (dal cliente, 06/11/2026), da inserire dal pannello:

| `ticket_prices` | Fascia | Ruolo | Importo |
|---|---|---|---|
| Pass intero | base | public | 69500 |
| Pass intero | base | member | 39500 |
| Pass intero | early_bird | member | 27650 (−30%, solo membri) |
| Pass intero | last_minute | public / member | da definire (domanda aperta) |
| Workshop (10 tipi, uno per workshop) | base | public | 10000 (nessun prezzo membro) |
| Accompagnatore, espositore | base | public | da definire |

### 3.4 Ordini, riserve, biglietti

| Tabella | Colonne principali | Vincoli e indici | Regole |
|---|---|---|---|
| `orders` | `event_id`, `user_id`, `buyer_email`, `status`, **`origin`** (`checkout`, `complimentary`), `price_role` e **`price_tier_id`** (fotografati alla riserva), `currency`, `subtotal_cents`, `discount_cents`, `total_cents`, `tax_cents`, `discount_code_id`, **`issued_by`**, **`comp_reason`** (`speaker`, `staff`, `sponsor`, `other`), `stripe_checkout_session_id`, `stripe_payment_intent_id`, `checkout_expires_at`, `discount_reserved`, `paid_at`, `livemode`, `stripe_amount_total_cents`, `locale`, `billing_details` | **DB** (migrazione `100000`): `total = subtotal − discount`; `paid` ha `paid_at`; **una sola `pending` per persona** (colonna generata `pending_user_id` + indice unico); **omaggio** a costo zero, senza codice né sessione Stripe, con chi lo emette e il motivo, nato già `paid`; **trigger**: transizioni ammesse (`paid → cancelled` solo per gli omaggi), ruolo, fascia, origine, persona, evento e importi immutabili | **Service**: `OrderService`, `ComplimentaryService`, `PaymentService::markPaid()` (l'unico che emette biglietti). **Policy**: l'acquirente vede i propri; commerciale e admin tutti; omaggi da organizzatore e admin |
| `order_items` | `order_id`, `ticket_type_id`, `quantity`, `price_role`, `list_unit_amount_cents`, `unit_discount_cents`, `unit_amount_cents`, `total_cents`, `stripe_line_name`, `ticket_price_id` (`RESTRICT`), `attendees` (JSON) | **DB**: importi coerenti; righe `member` di quantità 1; un nominativo per unità | **Service**: riga `member` intestata all'acquirente; i nominativi diventano quelli dei biglietti e non cambiano più |
| `member_price_claims` | `user_id`, `event_id`, `kind`, `event_day_id`, `program_session_id`, `order_id`, `order_item_id`, `created_at`, `released_at`, `release_reason` | **DB** (migrazione `100200`): una sola prenotazione attiva per (membro, evento, tipo, giorno, sessione) con colonna generata `active_key`; CHECK sulla forma della chiave | Vale in ogni fascia: l'early bird del membro consuma la stessa prenotazione. Accompagnatori, espositori e omaggi non ne creano |
| `capacity_holds` | `order_id`, `pool_id`, `quantity`, `status` (`active`, `awaiting_payment`, `converted`, `released`), `expires_at`, `released_at`, `release_reason` | **DB** (migrazione `100100`): unica per (ordine, pool); `active` ha scadenza; `released` ha data | **Service** + **Job**: si rilascia solo dopo la conferma di Stripe; gli omaggi nascono `converted` |
| `tickets` | `event_id`, `order_id`, `order_item_id`, `seq`, `ticket_type_id`, `price_role`, `attendee_name`, `attendee_email`, `attendee_user_id`, **`host_ticket_id`** (accompagnatori), `status` (`valid`, `cancelled`, `refunded`, `disputed`), `qr_version`, `qr_key_id`, `short_code`, `issued_at`, `cancelled_at`, **`attendee_corrections_count`**, **`attendee_corrected_at`**, **`attendee_corrected_by`** | **DB** (migrazione `100400`): unico (`order_item_id`, `seq`) contro l'emissione doppia; `short_code` unico e nel formato giusto; massimo 3 correzioni; **trigger**: nominativo immutabile salvo correzione firmata (contatore + 1, chi, quando), ordine/riga/tipo/ruolo/pass ospite immutabili, annullato o rimborsato non torna valido, nessuna cancellazione | **Nessun cambio di nome** (tolti `name_changes_count`, la regola delle 48 ore e `TicketService::changeAttendee()`). **Service** `TicketService::correctAttendee()`: solo admin con MFA, motivo, audit; nuova email → `qr_version + 1` e nuovo invio |
| `companion_claims` (nuova) | `host_ticket_id`, `slot`, `order_id`, `order_item_id`, `created_at`, `released_at`, `release_reason` | **DB** (migrazione `100400`): un solo accompagnatore attivo per (pass, posto) con colonna generata; `slot` tra 1 e 5 | **Service** `CompanionService`: pass valido (`full_pass` o `exhibitor`) intestato all'acquirente, posto libero più basso fino a `max_per_host`; liberata come il prezzo membro |
| `session_bookings` | `program_session_id`, `ticket_id`, `status`, `cancelled_at` | **DB**: una prenotazione attiva per (sessione, biglietto) con colonna generata | Fase 2 |
| `waitlist_entries` | `pool_id`, `user_id`, `quantity`, `status`, `offered_until` | **DB**: una iscrizione aperta per (pool, persona) con colonna generata | Fuori dalle due fasi per ora |
| `checkins` | `id` (generato da chi scansiona), `ticket_id`, `event_id`, `event_day_id`, `program_session_id`, `qr_version`, `scanned_at`, `received_at`, `source`, `partner_client_id`, `device_result`, `server_result` | **DB**: un solo ingresso accettato per (biglietto, giorno, varco) con colonna generata | Fase 2 (API del partner) |
| `refunds` | invariata: `order_id`, `ticket_id`, `amount_cents`, `currency`, `cause`, `reason`, `status`, `source`, `stripe_refund_id`, `idempotency_key`, `needs_review`, `requested_by` | **DB**: CHECK causa obbligatoria per i rimborsi nati da noi | **Policy**: solo commerciale e admin. Gli omaggi non si rimborsano: si annullano |

### 3.5 Video (fase 2)

| Tabella | Colonne principali | Vincoli e indici | Regole |
|---|---|---|---|
| `video_collections` | `slug`, `title`*, `description`*, `event_id` (congresso, anche passato), `published`, `position` | `slug` unico | **Policy**: organizzatore e admin scrivono |
| `videos` | `collection_id`, `event_id`, `provider` (`wistia`, `vimeo`), `provider_video_id`, `title`*, `description`*, `duration_seconds`, `thumbnail_url`, `published`, `position` | unico (`provider`, `provider_video_id`); CHECK `provider in ('wistia', 'vimeo')` | Nessun servizio video nuovo: i video restano sugli account del cliente, privati e incorporabili solo sul dominio del sito |
| `video_access_roles` (nuova) | `video_id`, `role` (`public`, `registered`, `member`, `attendee`, `speaker`, `staff`) | chiave primaria (`video_id`, `role`); CHECK sul ruolo | **Policy** `VideoPolicy::view`: almeno un ruolo della persona adesso (membership attiva, biglietto valido per l'`event_id` del video, speaker, staff). Senza permesso la pagina non contiene l'id del video |
| `video_speakers` | `video_id`, `speaker_id` | chiave primaria composta | |

### 3.6 Integrazione e servizio

| Tabella | Colonne principali | Vincoli e indici | Regole |
|---|---|---|---|
| `stripe_events` | invariata (migrazione `100300`) | **DB**: chiave primaria sull'id dell'evento; CHECK prefisso `evt_` e stato | **Job** `ProcessStripeEvent` dal cron; `payload` azzerato dopo 90 giorni |
| `sent_messages` | `dedupe_key`, `mailable`, `to_hash`, `sent_at` | `dedupe_key` unico | **Job**: un'email parte una volta anche se il job si ripete |
| `audit_log` | `occurred_at`, `actor_id`, `actor_type`, `action`, `entity_type`, `entity_id`, `ip`, `user_agent`, `data` (JSON) | **DB**: trigger che vieta UPDATE e DELETE (se i trigger sono ammessi) | **Service**: azioni dello staff, **omaggi**, **correzioni del nominativo**, codici sconto e d'invito, fasce e prezzi, rimborsi, export e **import**, accessi al pannello |
| `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions` | Tabelle standard di Laravel | | Coda su driver `database` (il job nasce nella stessa transazione dei dati); `cache_locks` serve a `withoutOverlapping` del cron; `sessions` = sessioni HTTP |
| `exports`, `imports`, `failed_import_rows`, `notifications` | Tabelle delle azioni di export e import di Filament `[likely]` | | Export e import in coda, file privati con scadenza; import del programma idempotente sulla colonna `code` |

---

## 4. Regole provate e come si riprovano

Le regole sotto sono provate nel database (§6). La logica dei Service va scritta come **test Pest** sulla stessa
versione di MySQL o MariaDB dell'hosting prima del rilascio.

| Regola | Dove | Test da scrivere |
|---|---|---|
| Riserva sotto i 30 minuti rifiutata | Service | `hold_too_short` |
| Una sola riserva aperta per persona | DB + Service | secondo `pending` rifiutato; `expire` della sessione precedente |
| Esaurito senza overselling, anche in parallelo | DB + Service | due checkout sull'ultimo posto: uno passa, l'altro `sold_out` |
| Limite per ordine sul pool | Service | 3 posti su un workshop con limite 2 → `too_many_for_pool` |
| Rilascio solo dopo conferma di Stripe | Service + Job | sessione `open` → `expire` poi chiusura; `complete` → `markPaid` |
| Pagamento tardivo senza posti / con posti | Service | `needs_refund` con rimborso in coda / `paid_late` |
| Webhook ripetuto o in parallelo con la pagina di successo | DB + Service | `already_done`; 2 biglietti e non 4 |
| Sessione Stripe diversa dall'ordine | Service | `session_mismatch` |
| Codice sconto: utilizzo contato alla riserva, restituito alla scadenza; creato da commerciale e organizzatore | Service + Policy | `discount_exhausted`; Policy dei due ruoli |
| Un biglietto a prezzo membro per membro, evento e tipo, in qualunque fascia | DB + Service | secondo pass membro (early bird o regular) → prezzo pubblico |
| Fascia attiva e prezzo: early bird solo membri, workshop senza prezzo membro | Service | 276,50 / 695 / 395 / 100 € come nel §6 |
| Fascia fissata alla riserva | DB + Service | la fascia finisce durante il pagamento: prezzo invariato; modifica rifiutata |
| Fasce sovrapposte | DB + Service | errore leggibile nel pannello |
| Membership attiva subito | Service | prezzo membro disponibile appena tornati dalla pagina di successo, prima del webhook |
| Omaggio: costo zero, conta nella capienza, audit | DB + Service | omaggio a pool pieno → rifiutato; revoca → posto liberato |
| Accompagnatore solo con un pass, uno per pass | DB + Service | senza pass → rifiutato; secondo → rifiutato |
| Espositore solo con codice d'invito | Service | senza codice → non visibile né acquistabile |
| Nominativo immutabile; correzione solo admin, con audit, massimo 3 | DB + Service + Policy | commerciale → 403; quarta correzione → rifiutata |
| Rimborso senza causa | DB | rifiutato dal `CHECK` |
| Transizioni di stato non ammesse | DB + enum PHP | `pending → refunded` rifiutata |
| Import del programma idempotente, con sale sovrapposte segnalate | Service | stesso file due volte = stesse righe |

---

## 5. MySQL e MariaDB: come si scrivono i vincoli

Lezioni delle prove del 06/11/2026 (§6), valide per tutte le migrazioni nuove.

| Tema | Regola | Esito della prova |
|---|---|---|
| Indici unici «parziali» | Colonna generata `storedAs("case when <attiva> then <chiave> end")` + indice unico: più `NULL` sono ammessi. Al posto di `NULLS NOT DISTINCT`, i `NULL` della chiave diventano `'-'` o `'*'` con `coalesce` | 🟢 su entrambi |
| UUID su MariaDB | `cast(colonna_uuid as char(36))` dentro `coalesce` e simili | 🔴 senza cast: «Incorrect uuid value: '*'» su MariaDB |
| `CHECK` e `NULL` | `(x is not null and x between 1 and 5)`, mai solo `x between 1 and 5`; `comp_reason is not null and comp_reason in (…)` | 🔴 senza: accompagnatore senza limite e omaggio senza motivo accettati |
| `CHECK` e chiavi esterne | Le colonne usate in un `CHECK` o in una colonna generata hanno FK `RESTRICT` | 🔴 con `SET NULL`: errore 3823 (MySQL), 1901 (MariaDB) |
| Trigger | `before insert/update … for each row begin … signal sqlstate '45000' set message_text = '<codice>'; end`, creati con `DB::unprepared` (niente `DELIMITER`). Possono leggere la tabella che li attiva, non modificarla | 🟢 su entrambi |
| Trigger e log binario | Errore 1419 per un utente senza `SUPER`: serve `log_bin_trust_function_creators = 1`, altrimenti `DB_TRIGGERS_OPTIONAL=true` | 🔴 1419 su MySQL 8.0.46 (log binario attivo di default) e su MariaDB con `--log-bin` |
| Lock | `lockForUpdate()` sull'ordine e sui pool in ordine di id; `UPDATE` condizionale; `DB::transaction($fn, attempts: 3)` per i deadlock di `REPEATABLE READ` | 🟢 la seconda transazione aspetta e trova il posto preso |
| Errori nella transazione | MySQL annulla solo l'istruzione fallita, ma i Service usano lo stesso una transazione annidata (savepoint) dove intercettano un errore di unicità (prezzo membro, accompagnatore) | — |
| `LIKE` nei `CHECK` | `'evt\_%'`: il `_` senza `\` sarebbe un jolly | 🟢 `evtX1` rifiutato |
| DDL | Non transazionale: una migrazione per gruppo di tabelle, backup prima del deploy | 🟢 `migrate:rollback` delle 6 migrazioni e di nuovo `migrate` |

---

## 6. Prove fatte sulle migrazioni bozza

### 6.1 Su MySQL e MariaDB (06/11/2026, pomeriggio)

Ambiente `[sure]`: Laravel **13.40.0** installato in una cartella di prova con Composer, PHP 8.3.6; **MySQL 8.0.46**
(log binario attivo, `REPEATABLE READ`) e **MariaDB 10.11.14** (`REPEATABLE READ`), utente dell'applicazione con tutti
i permessi sul solo database e senza `SUPER`, come su un hosting condiviso. Le 6 migrazioni bozza applicate con il vero
`Schema` di Laravel (`migrate:fresh`, `migrate:rollback --step=6`, di nuovo `migrate`) sopra le tabelle di Laravel e
quattro tabelle minime presupposte ([`prove/2026_11_06_080000_create_stub_prerequisites.php`](prove/2026_11_06_080000_create_stub_prerequisites.php)).
Poi lo script [`prove/prove_mysql.php`](prove/prove_mysql.php): **59 prove con dati finti per database, 59 come atteso
su entrambi**. Le prove in parallelo usano processi separati (`pcntl_fork`) con transazioni vere.

| Prova | MySQL 8.0.46 | MariaDB 10.11.14 |
|---|---|---|
| **Ultimo posto in parallelo**: due checkout sul workshop da 1 posto | 🟢 il primo riserva, il secondo aspetta il lock e aggiorna 0 righe (`sold_out`); `held = 1` | 🟢 uguale |
| **Prezzo membro una volta per evento e tipo, anche su due ordini**: secondo pass membro (early bird) in un altro ordine | 🟢 rifiutato (1062 su `member_price_claims_one_active`); workshop membro nello stesso ordine ammesso; dopo il rilascio (ordine rimborsato) nuovo pass membro ammesso | 🟢 uguale |
| Prezzo membro in parallelo su due ordini | 🟢 la seconda transazione aspetta la prima (0,8 s) e fallisce sull'indice unico; 1 prenotazione attiva | 🟢 uguale |
| **Webhook ripetuto**: `insert ignore` dello stesso evento | 🟢 una riga sola | 🟢 uguale |
| Webhook + pagina di successo + reinvio in parallelo sullo stesso ordine | 🟢 esiti `paid`, `already_done`, `already_done`; **2 biglietti** e non 4 o 6; `held 0`, `confirmed 2` | 🟢 uguale |
| **Conferma tardiva senza posti**: sessione scaduta e rilasciata, posto venduto a un altro, poi arriva il pagamento | 🟢 `expired → needs_refund`, 0 biglietti, job di rimborso in coda nella stessa transazione, `confirmed 1/1` | 🟢 uguale |
| **Conferma tardiva con riprenotazione**: stessa cosa con un posto libero | 🟢 `expired → paid`, riserva `released → converted`, `confirmed 2/2` | 🟢 uguale |
| Fasce sovrapposte (insert e update); fascia adiacente | 🟢 rifiutate (`price_tier_overlap`); adiacente ammessa | 🟢 uguale |
| Prezzi: secondo prezzo base o early bird per lo stesso tipo e ruolo | 🟢 rifiutati (1062) | 🟢 uguale |
| Calcolo del prezzo: membro in early bird 276,50 €, pubblico in early bird 695 €, membro regular 395 €, pubblico regular 695 €, workshop membro 100 € | 🟢 tutti | 🟢 tutti |
| Tipi: omaggio vendibile sul sito; accompagnatore senza limite | 🟢 rifiutati (il secondo dopo la correzione del `CHECK`) | 🟢 uguale |
| Ordini: secondo `pending`, totale sbagliato, `paid` senza data, `pending → refunded`, fascia o totale cambiati dopo la riserva, `paid → cancelled` di un ordine Stripe, riga member di quantità 2, nominativi diversi dalla quantità | 🟢 tutti rifiutati | 🟢 uguale |
| Omaggi: importo > 0, senza chi lo emette, senza motivo, `pending` | 🟢 rifiutati; omaggio a chi ha già un checkout aperto ammesso; revoca ammessa | 🟢 uguale |
| Capienza: aumento cieco dei venduti, capienza ridotta sotto i posti presi; riserve senza scadenza, rilasciate senza data, doppie | 🟢 tutti rifiutati | 🟢 uguale |
| Eventi Stripe: id senza `evt_`, `evtX1`, `processed` senza data | 🟢 rifiutati | 🟢 uguale |
| Biglietti: doppio per riga e numero; **cambio di nome**; correzione senza firma; 3 correzioni firmate ammesse, la quarta no; tipo cambiato; codice breve ambiguo; cancellazione; rimborsato che torna valido | 🟢 tutti come atteso | 🟢 uguale |
| Accompagnatori: secondo sullo stesso pass; dopo il rilascio del primo | 🟢 rifiutato / ammesso | 🟢 uguale |
| `CHECK` su colonna con FK `SET NULL` | 🟢 rifiutato (3823): motivo dei `RESTRICT` | 🟢 rifiutato (1901) |

Problemi trovati e corretti durante le prove: due `CHECK` che lasciavano passare `NULL`, le colonne generate con
`coalesce` su UUID nativi di MariaDB, lo scontro di nomi tra le sessioni del programma e la tabella `sessions` di
Laravel.

**Senza trigger** (MariaDB con log binario, `DB_TRIGGERS_OPTIONAL=true`): le migrazioni passano con 5 avvisi; 11
prove falliscono, tutte quelle che dipendono dai trigger (fasce sovrapposte, transizioni, dati fotografati
dell'ordine, nominativo, cancellazione e riattivazione dei biglietti). Capienza, prezzo membro, idempotenza, biglietti
unici, omaggi e conferma tardiva restano 🟢 `[sure]`.

Per rifarle: Laravel 13 in una cartella vuota, le tabelle minime e le migrazioni bozza in `database/migrations`,
`php artisan migrate:fresh` sul database `lab`, poi `CHECK_CODE=3819 php prove_mysql.php <socket> mysql8` (MySQL) o
`CHECK_CODE=4025 php prove_mysql.php <socket> mariadb` (MariaDB).

Non provati: i Service PHP (non esistono ancora), Filament, il carico.

### 6.2 Su Postgres (06/11/2026, mattina; storia)

Le 4 migrazioni di allora, ramo Postgres, su Postgres 16.15 con dati finti: tutte le difese come atteso `[sure]`
(secondo `pending`, `CHECK` di importi e date, transizioni, ultimo posto in parallelo, prezzo membro in parallelo,
evento Stripe ripetuto). Il ramo Postgres non è più mantenuto.
