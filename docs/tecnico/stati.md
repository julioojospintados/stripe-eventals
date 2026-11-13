# Macchine a stati

Autore: Giulio Occhipinti. Aggiornato al 07/11/2026, dal codice di `sito/` (enum in `app/Enums/`, servizi in
`app/Services/`, job in `app/Jobs/`, comandi in `app/Console/Commands/`, trigger nelle migrazioni).

Chi provoca il passaggio, nelle etichette:

| Sigla | Chi | Esempi |
|---|---|---|
| **U** | utente (richiesta HTTP) | carrello, pagina di successo `/account/orders/{id}?session_id=…`, `/membership/success` |
| **W** | webhook Stripe, elaborato dal job `ProcessStripeEvent` in coda | `checkout.session.completed`, `charge.refunded` |
| **J** | job in coda | `RefundOrderFully`, `SendTicketsEmail` |
| **S** | staff dal pannello Filament | rimborso, decisione su `needs_review`, omaggio, correzione del nome |
| **C** | comando o scheduler | `eventals:expire-orders` (ogni minuto), `eventals:reconcile` (ogni notte) |

La pagina di successo, `eventals:expire-orders` ed `eventals:reconcile` chiamano lo stesso `PaymentService` del webhook: un
pagamento si conferma da qualunque delle quattro strade arrivi prima, una volta sola (lock sull'ordine).

## Ordine (`orders.status`)

Enum `App\Enums\OrderStatus`; le transizioni ammesse sono in `OrderStatus::canTransitionTo()` e, identiche, nel
trigger `orders_guard` (migrazioni `2026_11_06_100000` e `2026_11_08_090000`). `PaymentService::transition()` le
ricontrolla anche sugli hosting senza trigger.

```mermaid
stateDiagram-v2
    [*] --> pending : U reserve, posti in riserva
    [*] --> paid : S omaggio, origin complimentary
    pending --> paid : W completed, U successo, C expire o reconcile, U ordine a 0 euro
    pending --> processing : W completed con pagamento non ancora riuscito
    pending --> expired : W checkout.session.expired, C eas expire-orders
    pending --> cancelled : U nuovo carrello, ordine sostituito
    pending --> failed : W async_payment_failed
    processing --> paid : W async_payment_succeeded
    processing --> failed : W async_payment_failed
    expired --> paid : W o C pagamento tardivo, posti riprenotati
    failed --> paid : pagamento tardivo, posti riprenotati
    cancelled --> paid : pagamento tardivo, posti riprenotati
    expired --> needs_refund : pagamento tardivo senza posti
    failed --> needs_refund : pagamento tardivo senza posti
    cancelled --> needs_refund : pagamento tardivo senza posti
    pending --> needs_review : importo Stripe diverso dal totale
    processing --> needs_review : importo diverso
    expired --> needs_review : tardivo con importo diverso
    needs_review --> paid : S emetti i biglietti
    needs_review --> needs_refund : S rimborsa invece
    needs_review --> refunded : W charge.refunded dalla Dashboard
    needs_refund --> refunded : J RefundOrderFully poi W charge.refunded
    paid --> partially_refunded : W charge.refunded parziale
    paid --> refunded : S rimborso, J, poi W charge.refunded
    partially_refunded --> refunded : W charge.refunded completo
    paid --> cancelled : S revoca omaggio, solo complimentary
    refunded --> [*]
```

- Anche `failed` e `cancelled` vanno in `needs_review` come `expired` (stessa regola, omesso per leggibilità).
- Ammesse dal trigger ma mai usate dal codice: `pending → needs_refund`.
- Immutabili dopo la riserva (trigger `orders_guard`, errore `order_snapshot_immutable`): `origin`, `price_role`,
  `price_tier_id`, `user_id`, `event_id`, `subtotal_cents`, `discount_cents`, `total_cents`.
- `review_reason` non è uno stato: segnala `amount_mismatch`, `refund_failed`, `dispute_lost` senza cambiare
  `status`. Una contestazione non cambia lo stato dell'ordine, solo quello dei biglietti.
- `OrderStatus::moneyTaken()`: `paid`, `partially_refunded`, `refunded`, `needs_refund`, `needs_review`.

## Biglietto (`tickets.status`)

Enum `App\Enums\TicketStatus`. Emissione in `TicketService::issue()` (idempotente: `unique (order_item_id, seq)`).
Trigger `tickets_guard`: da `cancelled` e `refunded` non si esce; nominativo immutabile salvo correzione firmata;
`tickets_no_delete` impedisce la cancellazione.

```mermaid
stateDiagram-v2
    [*] --> valid : ordine paid, W U C S
    valid --> valid : S correzione del nome, max 3, qr_version +1 se cambia email
    valid --> disputed : W charge.dispute.created o updated
    disputed --> valid : W dispute closed won
    disputed --> cancelled : W dispute closed lost, posti liberati
    valid --> refunded : W charge.refunded completo
    valid --> cancelled : S revoca omaggio
    cancelled --> [*]
    refunded --> [*]
```

- Un rimborso parziale non tocca i biglietti: restano `valid`.
- Solo i biglietti `valid` hanno QR nell'area personale, ricevono email e finiscono nell'export del check-in.

## Riserva di capienza (`capacity_holds.status`)

Enum `App\Enums\CapacityHoldStatus`, metodi in `CapacityService`. Ogni passaggio aggiorna nella stessa transazione
`capacity_pools.held` e `confirmed` (CHECK `capacity_pools_no_oversell`: `held + confirmed <= capacity`).

```mermaid
stateDiagram-v2
    [*] --> active : U reserve, held +n, scadenza 36 minuti
    [*] --> converted : S omaggio, confirmed +n
    active --> active : U checkout dopo attesa, scadenza allungata
    active --> awaiting_payment : W completed non pagato, scadenza tolta
    active --> converted : pagamento nei tempi, held -n confirmed +n
    awaiting_payment --> converted : W async_payment_succeeded
    active --> released : W expired, C expire-orders, U nuovo carrello
    awaiting_payment --> released : W async_payment_failed
    released --> converted : pagamento tardivo, rebook se ci sono posti
    converted --> released : W charge.refunded, S rimborsa needs_review, W dispute lost, S revoca omaggio
```

- I posti di una riserva scaduta si liberano **solo dopo** che Stripe conferma la scadenza (webhook `expired` o
  `eventals:expire-orders`, che prima chiama `expire` sulla sessione): un job fermo fa vendere meno, mai due volte.
- CHECK: `active` ha sempre `expires_at`, `released` ha sempre `released_at`.

## Rimborso (`refunds.status`)

Stati di Stripe salvati come testo: `pending`, `succeeded`, `failed`, `canceled` (`Refund::NOT_MONEY` = `failed`,
`canceled`). `source`: `auto` (pagato senza posti), `staff` (pannello), `dashboard` (fatto in Stripe, registrato
dal webhook con `needs_review = 1`).

```mermaid
stateDiagram-v2
    [*] --> pending : J RefundOrderFully, riga con idempotency_key
    [*] --> succeeded : W charge.refunded di un rimborso fatto in Dashboard
    pending --> succeeded : J risposta di Stripe, W charge.refunded
    pending --> failed : W refund.failed, J rifiuto immediato
    pending --> canceled : W refund.updated canceled
    succeeded --> failed : W refund.failed o charge.refund.updated
    failed --> [*]
    canceled --> [*]
    succeeded --> [*]
```

- Dopo un `failed` o `canceled` l'ordine riceve `review_reason = refund_failed` e lo staff un avviso; ordine e
  biglietti **non** cambiano stato. Il nuovo tentativo usa la chiave `…-retry-N` e crea una riga nuova.
- Lo stato `refunded` dell'ordine arriva solo da `charge.refunded`, mai dal job.

## Membership (`memberships.status`)

Copia degli stati della subscription Stripe (enum `App\Enums\MembershipStatus`). Il codice non decide transizioni:
`MembershipService::sync()` rilegge la subscription da Stripe e la salva, ignorando gli eventi più vecchi di
`last_stripe_event_at`. Chiamata da W `checkout.session.completed` (mode subscription), `customer.subscription.*`,
`invoice.*`, e da U `/membership/success`.

```mermaid
stateDiagram-v2
    [*] --> active : W checkout completed, U pagina di successo
    [*] --> incomplete : W subscription.created senza pagamento
    incomplete --> active : W invoice.paid
    incomplete --> incomplete_expired : W dopo 23 ore senza pagamento
    active --> past_due : W invoice.payment_failed al rinnovo
    past_due --> active : W invoice.paid
    past_due --> unpaid : W tentativi di Stripe finiti
    past_due --> canceled : W subscription.deleted
    active --> canceled : W subscription.deleted, annullamento dal Customer Portal a fine periodo
    trialing --> active : W, non usato oggi
    active --> paused : W, non usato oggi
    canceled --> [*]
    incomplete_expired --> [*]
```

- **Prezzo membro** se esiste una membership `active` o `trialing` con `current_period_end` successivo alla
  scadenza della Checkout Session (`User::isMemberUntil`), così chi scade durante il pagamento non lo ottiene.
- L'annullamento dal Customer Portal mette `cancel_at_period_end = 1`: resta `active` fino a fine periodo.

## Contestazione (`disputes.status`)

Stati di Stripe salvati come testo; chiusi (`Dispute::CLOSED`): `won`, `lost`, `prevented`, `warning_closed`. Logica in
`DisputeService::apply()`. La risposta con le prove si dà dalla Dashboard Stripe (S fuori dal pannello).

```mermaid
stateDiagram-v2
    [*] --> needs_response : W charge.dispute.created, biglietti disputed, avviso
    [*] --> warning_needs_response : W richiesta d'informazioni
    needs_response --> under_review : S prove dalla Dashboard, W updated
    warning_needs_response --> warning_under_review : W updated
    under_review --> won : W closed, biglietti di nuovo valid
    under_review --> lost : W closed, biglietti cancelled, posti liberati
    needs_response --> lost : W closed senza risposta
    warning_under_review --> warning_closed : W closed
    won --> [*]
    lost --> [*]
    warning_closed --> [*]
```

- Una volta `closed_at` valorizzato, ogni evento successivo è ignorato (`already_closed`): Stripe non garantisce
  l'ordine di consegna.

## Evento Stripe (`stripe_events.status`)

Enum `App\Enums\StripeEventStatus`; righe scritte da `StripeWebhookController` e da `eventals:reconcile`
(`evt_reconcile_<session>`), elaborate da `ProcessStripeEvent` (5 tentativi, attese 10 s, 1, 5, 15 minuti).

```mermaid
stateDiagram-v2
    [*] --> received : W firma valida, insertOrIgnore, C reconcile
    received --> processed : J elaborato
    received --> ignored : J tipo non gestito, ordine sconosciuto, nessuna subscription
    received --> failed : J eccezione, last_error, attempts +1
    failed --> processed : J nuovo tentativo
    failed --> ignored : J nuovo tentativo
    failed --> failed : J dopo 5 tentativi, failed_jobs e avviso allo staff
    processed --> [*]
    ignored --> [*]
```

- `processing` esiste nell'enum e nel CHECK ma il codice non lo usa: l'esclusione reciproca la dà il lock sulla riga
  (`lockForUpdate`) dentro la transazione del job.
- Un evento `processed` o `ignored` rielaborato non fa nulla.
