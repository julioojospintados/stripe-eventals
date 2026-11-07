# Flussi principali

Autore: Giulio Occhipinti. Aggiornato al 07/11/2026, dal codice di `sito/`. Gli stati sono in [stati.md](stati.md),
le regole e i test in [regole.md](regole.md).

Convenzioni: `DB` è MySQL/MariaDB; «tx» è una `DB::transaction` (con 3 tentativi sui deadlock); «lock» è
`SELECT … FOR UPDATE`. La coda è il driver `database` (tabella `jobs`), vuotata dal cron ogni minuto.

## 1. Acquisto con Checkout Stripe

`ShopController::reserve` → `CheckoutService::reserve()`, poi `ShopController::checkout` →
`CheckoutService::startCheckout()`, poi la pagina di successo `AccountController::order`.

```mermaid
sequenceDiagram
    autonumber
    actor U as Acquirente
    participant App as Laravel
    participant DB
    participant S as Stripe
    U->>App: POST /cart con tipi e nominativi
    App->>S: expire della sessione del suo ordine pending precedente, se c'è
    App->>DB: chiude il precedente (cancelled) o lo conferma se intanto è pagato
    App->>DB: tx: ruolo (membro fino alla scadenza della sessione), fascia attiva, limiti
    App->>DB: lock del codice sconto, prezzi riga per riga dal database
    App->>DB: INSERT orders pending (indice unico: un pending per persona)
    App->>DB: INSERT order_items e member_price_claims (indice unico)
    App->>DB: lock dei pool in ordine di id, UPDATE held+n WHERE held+confirmed+n <= capacity
    App->>DB: INSERT capacity_holds active (scadenza 36 min), utilizzo del codice
    App-->>U: pagina di conferma dei nominativi
    U->>App: POST /orders/{id}/checkout
    alt totale 0
        App->>DB: markPaid senza Stripe, biglietti
    else sessione già creata
        App->>S: retrieve della sessione, se open la riusa
    else prima volta
        App->>DB: allunga la riserva se è passato tempo
        App->>S: crea Checkout Session (price_data, solo carta, expires_at, Idempotency-Key checkout-id)
        App->>DB: salva stripe_checkout_session_id
    end
    App-->>U: redirect alla pagina Stripe
    U->>S: paga con carta
    S-->>U: redirect a /account/orders/{id}?session_id=...
    U->>App: GET pagina di successo
    App->>S: retrieve della sessione
    App->>DB: PaymentService applySession, stesso codice del webhook
    App-->>U: esito con i QR
```

## 2. Webhook in coda con idempotenza e lock

`StripeWebhookController` e `ProcessStripeEvent`.

```mermaid
sequenceDiagram
    autonumber
    participant S as Stripe
    participant W as StripeWebhookController
    participant DB
    participant Cron as cron schedule run
    participant J as ProcessStripeEvent
    participant P as PaymentService
    S->>W: POST /stripe/webhook con Stripe-Signature
    W->>W: verifica firma e livemode
    alt firma o modalità sbagliata
        W-->>S: 400, nulla scritto
    end
    W->>DB: tx: insertOrIgnore stripe_events (id evento = PK)
    alt riga nuova
        W->>DB: INSERT jobs nella stessa tx
    else reinvio
        W->>W: nessun job
    end
    W-->>S: 200 ok subito
    Cron->>J: queue work, coda critical, entro un minuto
    J->>DB: tx: lock della riga stripe_events
    alt già processed o ignored
        J->>J: esce
    else da elaborare
        J->>P: applySession, markPaid, close, recordRefund...
        P->>DB: lock dell'ordine, transizione solo da pending o processing
        P->>DB: lock dei pool, biglietti con unique order_item_id e seq
        J->>DB: status processed o ignored con ignored_reason
    end
    opt eccezione
        J->>DB: status failed, last_error, attempts +1
        J->>J: nuovo tentativo dopo 10 s, 1, 5, 15 min
        J->>J: dopo 5 tentativi failed_jobs e StaffAlert agli admin
    end
```

## 3. Pagamento tardivo: riprenotazione o rimborso

Il pagamento arriva quando l'ordine è già `expired`, `failed` o `cancelled` (`PaymentService::markPaid`).

```mermaid
sequenceDiagram
    autonumber
    participant Src as Webhook, successo, expire-orders o reconcile
    participant P as PaymentService
    participant C as CapacityService
    participant DB
    participant Q as Coda
    participant S as Stripe
    Src->>P: markPaid con la sessione pagata
    P->>DB: tx: lock dell'ordine, controllo session id
    P->>C: rebook
    C->>DB: lock dei pool, held+confirmed+n <= capacity per tutti
    alt posti ancora liberi
        C->>DB: confirmed +n, hold converted
        P->>DB: riprende prezzo membro e codice sconto
        Note over P,DB: se nel frattempo il limite è finito il biglietto vale lo stesso e parte un avviso
        P->>DB: ordine paid, biglietti, email in coda
    else posti finiti
        P->>DB: ordine needs_refund, nessun biglietto
        P->>Q: RefundOrderFully nella stessa tx, StaffAlert
        Q->>S: refunds create, Idempotency-Key auto-refund-id
        S-->>Q: refund pending o succeeded
        Q->>DB: refunds aggiornato
        S->>Src: charge.refunded
        Src->>P: recordRefund
        P->>DB: ordine refunded
    end
```

## 4. Rimborso dal pannello

`OrderResource::refundAction` (permesso `orders.refund`) → `RefundOrderFully` → webhook `charge.refunded`.

```mermaid
sequenceDiagram
    autonumber
    actor St as Commerciale o admin
    participant F as Pannello Filament
    participant DB
    participant J as RefundOrderFully
    participant S as Stripe
    participant P as PaymentService
    St->>F: Refund in full, causa e motivo
    F->>F: OrderPolicy refund
    F->>DB: audit_log order.refund_requested
    F->>J: dispatch nella coda critical
    J->>DB: ordine paid, partially_refunded o needs_refund
    J->>DB: firstOrCreate refunds per idempotency_key, importo pagato meno già rimborsato
    alt riga con stripe_refund_id
        J->>J: esce, già chiesto
    else primo invio
        J->>S: refunds create, Idempotency-Key staff-refund-id
        S-->>J: id e stato
        J->>DB: salva stripe_refund_id e stato
    end
    S->>P: charge.refunded via webhook e job
    P->>DB: lock ordine, posti confirmed liberati, claim rilasciati
    P->>DB: biglietti valid diventano refunded, ordine refunded
    opt Stripe rifiuta
        S->>P: refund.failed o refund.updated canceled
        P->>DB: refunds failed, review_reason refund_failed, StaffAlert
    end
```

## 5. Membership

`MembershipController` → `MembershipService`.

```mermaid
sequenceDiagram
    autonumber
    actor U as Utente
    participant App as Laravel
    participant S as Stripe
    participant DB
    U->>App: POST /membership
    App->>App: già membro? errore already_member
    App->>S: Checkout Session mode subscription, Price del piano attivo, solo carta
    App-->>U: redirect a Stripe
    U->>S: paga
    S-->>U: redirect a /membership/success
    U->>App: GET con session_id
    App->>S: retrieve sessione e subscription
    App->>DB: tx: lock membership per subscription id, salva stato e periodo
    App-->>U: prezzo membro attivo subito
    S->>App: checkout.session.completed, customer.subscription.*, invoice.* (webhook in coda)
    App->>S: retrieve subscription
    App->>DB: aggiorna solo se l'evento non è più vecchio di last_stripe_event_at
    U->>App: POST /membership/portal
    App->>S: Customer Portal session
    App-->>U: redirect al portale, annullamento e carta
```

## 6. Riconciliazione notturna

`eas:reconcile` alle 3:30 (Europe/Lisbon), o dal pannello in coda (permesso `reconciliation.run`).

```mermaid
sequenceDiagram
    autonumber
    participant Cron as Scheduler
    participant R as ReconcileStripe
    participant S as Stripe
    participant DB
    participant J as ProcessStripeEvent
    Cron->>R: eas reconcile, ultime 48 ore
    R->>S: lista Checkout Session (49 ore) e rimborsi
    loop sessioni complete e paid
        R->>DB: ordine in uno stato senza soldi?
        alt sì
            R->>DB: tx: insertOrIgnore stripe_events evt_reconcile_sessione e job
            DB-->>J: stesso percorso del webhook, una volta sola
        end
    end
    loop ordini pagati qui nella finestra
        R->>R: sessione non paid su Stripe, differenza paid_here_only
    end
    loop rimborsi Stripe per PaymentIntent
        R->>R: somma maggiore di quella locale, differenza refund_not_recorded
    end
    R->>DB: audit_log stripe.reconciled con il rapporto
    opt differenze
        R->>R: StaffAlert agli admin
    end
```

Le differenze `paid_here_only` e `refund_not_recorded` sono solo segnalate: si correggono a mano (vedi
[esercizio.md](esercizio.md)).

## 7. Contestazione

`charge.dispute.created`, `updated`, `closed` → `DisputeService::apply()`.

```mermaid
sequenceDiagram
    autonumber
    participant S as Stripe
    participant J as ProcessStripeEvent
    participant D as DisputeService
    participant DB
    actor St as Staff
    S->>J: charge.dispute.created via webhook
    J->>D: apply
    D->>DB: tx: lock ordine e riga disputes
    D->>DB: INSERT disputes, biglietti valid diventano disputed
    D->>St: StaffAlert, rispondere dalla Dashboard entro evidence_due_by
    St->>S: prove dalla Dashboard Stripe
    S->>J: charge.dispute.updated
    J->>D: aggiorna stato
    S->>J: charge.dispute.closed
    J->>D: apply
    alt won
        D->>DB: biglietti disputed tornano valid
    else lost
        D->>DB: biglietti cancelled, posti confirmed liberati, review_reason dispute_lost
    end
    D->>St: StaffAlert con l'esito
    Note over D,DB: dopo closed_at ogni altro evento è ignorato
```

## 8. Login con codice

`LoginController` → `LoginCodeService`. Lo staff entra solo da `/admin` con password argon2id e TOTP.

```mermaid
sequenceDiagram
    autonumber
    actor U as Utente
    participant App as Laravel
    participant DB
    participant M as SMTP
    U->>App: POST /login con email
    App->>App: limiti, 3 ogni 10 min per email, 20 all'ora per IP, 10 al minuto per rotta
    alt email dello staff
        App-->>U: stessa risposta, nessun codice
    else cliente
        App->>DB: tx: cancella i codici vecchi, INSERT login_codes con HMAC, scadenza 10 min
        App-->>U: pagina del codice
        App->>M: email dopo la risposta (afterResponse), non dalla coda
    end
    U->>App: POST /login/code
    App->>DB: tx: lock del codice valido più recente
    alt tentativi 5 o più, scaduto o usato
        App-->>U: errore
    else HMAC diverso
        App->>DB: attempts +1
        App-->>U: errore
    else giusto
        App->>DB: used_at, utente creato al primo accesso
        App->>App: session regenerate
        App-->>U: area personale
    end
```
