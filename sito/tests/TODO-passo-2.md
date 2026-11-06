# Test del passo 2 (Service, checkout, webhook): chiuso

Autore: Giulio Occhipinti.

Elenco chiuso al passo 2: ogni prova dice dove sta. Quelle che riguardano azioni del pannello passano al passo 3.
Tutto su MariaDB/MySQL; le prove in parallelo usano processi separati (`tests/Concurrency/worker.php`) e transazioni
vere, non `RefreshDatabase`.

## Da `prove_mysql.php`

| Prova | Test |
|---|---|
| Prezzi: membro e pubblico in early bird e regular, workshop membro 100 € | `Feature/Shop/PricingTest` («PriceResolver») |
| Ultimo posto in parallelo: uno `reserved`, l'altro `sold_out`, `held = 1` | `Concurrency/ParallelTest` |
| Prezzo membro in parallelo su due ordini: la seconda aspetta e fallisce sull'indice unico | `Concurrency/ParallelTest` |
| Webhook + pagina di successo + reinvio in parallelo: `paid` / `already_done` / `already_done`, biglietti una volta, `held 0` | `Concurrency/ParallelTest`; in sequenza e via HTTP in `PaymentTest` e `WebhookTest` |
| Conferma tardiva senza posti: `needs_refund`, 0 biglietti, rimborso in coda, avviso admin | `Feature/Shop/PaymentTest` |
| Conferma tardiva con posto libero: `expired → paid`, riserva `converted` | `Feature/Shop/PaymentTest` |

## Da `db/modello-dati.md` §4

| Regola | Test |
|---|---|
| Riserva sotto i 30 minuti (`hold_too_short`) | `CheckoutTest` |
| Secondo `pending`: la sessione Stripe precedente scade e l'ordine si chiude (o si conferma, se pagato) | `CheckoutTest` |
| Limite per ordine sul pool (`too_many_for_pool`) e per tipo | `CheckoutTest` |
| Rilascio solo dopo Stripe: `open` → `expire` poi chiusura; `complete` → `markPaid` | `PaymentTest` («eas:expire-orders») |
| Sessione diversa da quella dell'ordine (`session_mismatch`) | `PaymentTest`, `WebhookTest`, `AccountTest` |
| Webhook: firma, `livemode`, evento salvato una volta, lavorato in coda una volta | `WebhookTest` |
| Codice sconto: contato alla riserva, restituito alla scadenza (`discount_exhausted`), limite per persona | `PricingTest` |
| Fascia fissata alla riserva | `PricingTest` |
| Membership attiva subito: prezzo membro al ritorno dalla pagina di successo | `MembershipTest` |
| Transizioni senza trigger: le regole valgono nel Service | `PaymentTest` («chiude solo ordini pending o processing») |
| IDOR su ordini, biglietti e checkout | `Feature/Account/AccountTest` |

Anche: accesso con codice (`LoginCodeTest`), sconto contro prezzo membro e ordini a costo zero (`PricingTest`),
regole dei workshop e parametri della Checkout Session (`CheckoutTest`), QR firmato, email e `eas:resend-ticket`
(`TicketTest`), rimborsi con `charge.refunded` (`PaymentTest`).

## Passano al passo 3 (azioni del pannello)

Fatte al passo 3 in `tests/Feature/Panel/` (`ActionsTest`, `PolicyTest`, `ExportTest`), salvo dove indicato.

- Fasce sovrapposte: errore leggibile nel form; il trigger c'è già (`SalesSchemaTest`). Il lock sull'evento contro due
  salvataggi in parallelo non c'è: lo copre il trigger.
- Omaggio: a pool pieno rifiutato, revoca → posto liberato, registro audit.
- Accompagnatore senza pass o secondo sullo stesso pass (le difese del database sono in `OrdersSchemaTest`): ancora
  da fare, il tipo è spento.
- Correzione del nominativo: commerciale senza l'azione, quarta correzione, audit, `qr_version + 1`.
- Espositore senza codice d'invito: già non acquistabile (canale `invite`, `CheckoutTest` «non vende tipi spenti o di un
  altro canale»); i codici d'invito sono della Fase 2.
- Azioni Livewire e Filament su ordini e biglietti di un altro utente (IDOR nel pannello).
