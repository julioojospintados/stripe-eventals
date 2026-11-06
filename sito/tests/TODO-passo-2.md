# Test da scrivere nel passo 2 (Service, checkout, webhook)

Autore: Giulio Occhipinti.

Le prove di schema di `db/prove/prove_mysql.php` sono già in `tests/Feature/Database/`. Restano quelle che hanno
bisogno dei Service PHP (non ancora scritti) e le regole del Service elencate in `db/modello-dati.md` §4. Tutte
su MariaDB/MySQL, come gli altri test.

## Da `prove_mysql.php`

| Prova | Sezione dello script | Service |
|---|---|---|
| Prezzo: membro in early bird 276,50 €, pubblico in early bird 695 €, membro regular 395 €, pubblico regular 695 €, workshop membro 100 € | 1 (`$resolve`) | `PriceResolver` |
| Ultimo posto in parallelo: due checkout sul workshop da 1 posto, uno `reserved` e l'altro `sold_out`, `held = 1` | 7a | `CapacityService` |
| Prezzo membro in parallelo su due ordini: la seconda transazione aspetta e fallisce sull'indice unico, una prenotazione attiva | 7b | `OrderService` |
| Webhook ripetuto + pagina di successo + reinvio in parallelo: esiti `paid` / `already_done` / `already_done`, 2 biglietti e non 4 o 6, `held 0`, `confirmed 2` | 7c | `PaymentService::markPaid()` |
| Conferma tardiva senza posti: `expired → needs_refund`, 0 biglietti, rimborso in coda nella stessa transazione | 8 | `PaymentService` + job `RefundOrderFully` |
| Conferma tardiva con posto libero: `expired → paid`, riserva `released → converted` | 8 | `PaymentService` |

Le prove in parallelo vanno fatte con processi separati (come lo script, con `pcntl_fork` o due processi
`php artisan`), non con `RefreshDatabase`: servono transazioni vere che si aspettano a vicenda.

Non portata: «`CHECK` su una colonna con FK `SET NULL` rifiutato» (sezione 6 dello script). Prova il motore, non il
nostro schema, e crea una tabella (DDL), che chiuderebbe la transazione di `RefreshDatabase`.

## Da `db/modello-dati.md` §4

- Riserva sotto i 30 minuti rifiutata (`hold_too_short`).
- Secondo `pending` della stessa persona: il Service fa scadere la sessione Stripe precedente.
- Limite per ordine sul pool: 3 posti su un workshop con limite 2 → `too_many_for_pool`.
- Rilascio solo dopo la conferma di Stripe: sessione `open` → `expire` poi chiusura; `complete` → `markPaid`.
- Sessione Stripe diversa da quella dell'ordine → `session_mismatch`.
- Webhook: firma verificata, `livemode` controllato, evento salvato una volta e lavorato in coda.
- Codice sconto: utilizzo contato alla riserva e restituito alla scadenza (`discount_exhausted`), limite per persona.
- Fascia fissata alla riserva: la fascia finisce durante il pagamento e il prezzo non cambia.
- Fasce sovrapposte: errore leggibile nel form del pannello (lock sull'evento), non solo il trigger.
- Membership attiva subito: prezzo membro disponibile al ritorno dalla pagina di successo, prima del webhook.
- Omaggio: a pool pieno rifiutato; revoca → posto liberato; tutto nel registro audit.
- Accompagnatore senza pass → rifiutato; secondo sullo stesso pass → rifiutato (errore leggibile).
- Espositore senza codice d'invito: non visibile né acquistabile (Fase 2).
- Correzione del nominativo: commerciale → 403, quarta correzione → errore leggibile, audit, `qr_version + 1`.
- Transizioni di stato senza trigger: con `DB_TRIGGERS_OPTIONAL=true` le stesse regole devono valere nel Service
  (`OrderStatus::canTransitionTo()` e i controlli di `TicketService`).
- IDOR: ogni rotta dell'area personale e ogni azione Livewire su ordini e biglietti di un altro utente → 403/404.
