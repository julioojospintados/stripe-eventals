# ADR 0005: Webhook in coda con tabella `stripe_events`

Autore: Giulio Occhipinti. Stato: accettata. Data: 06/11/2026. Fonte: `docs/decisioni.md` (righe «Webhook», «Idempotenza dei webhook», «Conferma idempotente»), `docs/architettura.md` §6.6.

## Contesto

Stripe ripete gli eventi, li consegna in ordine qualsiasi e si aspetta una risposta veloce. La pagina di successo e
il cron possono confermare lo stesso pagamento nello stesso momento. Il lavoro (biglietti, email) non deve stare nella
richiesta.

## Decisione

- `StripeWebhookController`: verifica firma e `livemode`, `insertOrIgnore` in `stripe_events` (id evento come
  chiave) e job `ProcessStripeEvent` nella **stessa transazione** (coda `database`), risposta 200 subito.
- `ProcessStripeEvent`: lock sulla riga dell'evento, uscita se già `processed`/`ignored`; i servizi bloccano l'ordine e
  cambiano stato solo dagli stati ammessi; biglietti con `unique (order_item_id, seq)`.
- Pagina di successo, `eventals:expire-orders` ed `eventals:reconcile` usano lo stesso `PaymentService`.
- Laravel Cashier non si usa: SDK `stripe-php` diretto, un solo webhook e una sola tabella di idempotenza.

## Alternative

- Elaborazione dentro la richiesta del webhook: tempi lunghi, timeout di Stripe, lavori pesanti nella richiesta.
- Laravel Cashier: un secondo webhook e una seconda copia dello stato della membership.

## Conseguenze

- Nessun evento perso: se il job fallisce resta `failed` con l'errore e si riprova (5 volte), poi avviso.
- Fino a un minuto di latenza (cron): per questo la pagina di successo conferma da sola.
- Gli eventi `failed` e i `failed_jobs` vanno controllati in esercizio.
