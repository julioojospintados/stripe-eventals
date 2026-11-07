# ADR 0002: MySQL / MariaDB con vincoli e trigger opzionali

Autore: Giulio Occhipinti. Stato: accettata. Data: 06/11/2026. Fonte: `docs/decisioni.md` (righe «Database MySQL / MariaDB», «MariaDB minimo 10.7», «Trigger di difesa facoltativi», «Dove stanno le regole»), `docs/architettura.md` §3.

## Contesto

L'hosting offre MySQL o MariaDB, non Postgres. Le regole che fanno perdere soldi o posti (capienza, prezzo membro,
idempotenza, biglietti unici) devono reggere anche a un bug nel codice. MySQL non ha indici unici parziali; con il log
binario attivo un utente senza `SUPER` non crea trigger (errore 1419) se manca `log_bin_trust_function_creators = 1`.

## Decisione

- Database principale MySQL **8.0.19+** (serve `ALTER TABLE … DROP CONSTRAINT` per i CHECK) o MariaDB **10.7+**
  (prima Laravel crea gli `uuid` come `char(36)` e MariaDB rifiuta le colonne generate su quella chiave esterna,
  errore 1901); in pratica MariaDB 10.11 o 11.4.
- Invarianti nel database: `CHECK`, indici unici, chiavi esterne `RESTRICT`, indici unici «parziali» con colonne
  generate che valgono `NULL` quando la riga non deve contare (`orders.pending_user_id`,
  `member_price_claims.active_key`, `companion_claims.active_key`, `ticket_prices.tier_key`).
- Trigger di difesa per transizioni di stato, immutabilità e tabelle solo in aggiunta (`orders_guard`,
  `tickets_guard`, `price_tiers_no_overlap_*`, `*_no_update`, `*_no_delete`).
- Se l'hosting non permette i trigger: `DB_TRIGGERS_OPTIONAL=true`, il trigger si salta con un avviso nel log
  (`App\Database\CreatesTriggers`); le stesse regole restano nei servizi e nelle enum.

## Alternative

- Postgres: più difese native, ma non disponibile sull'hosting.
- Regole solo nel codice: un bug o una query a mano vende un posto due volte.
- Trigger obbligatori: un hosting che non li permette bloccherebbe il deploy.

## Conseguenze

- Test sempre su MySQL/MariaDB, mai solo SQLite (i vincoli sono parte delle regole).
- Ogni regola in un trigger ha il suo doppione nel servizio (`OrderStatus::canTransitionTo`).
- Senza trigger restano scoperte le modifiche fatte a mano in SQL: da sapere in esercizio.
