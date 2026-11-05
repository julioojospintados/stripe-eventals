# stripe-eventals

Autore: Giulio Occhipinti.

Sito per gestire eventi (agenda con giorni, sale, sessioni e sottosezioni; speaker) e vendere l'accesso con
prezzi per ruolo (pubblico e membro), membership annuale e codici sconto, tutto incassato con Stripe.

Fase attuale: **architettura e UX/UI**. Il codice dell'applicazione non c'è ancora.

| File | Cosa contiene |
|---|---|
| [`docs/brief.md`](docs/brief.md) | richiesta e decisioni di Giulio |
| [`docs/architettura.md`](docs/architettura.md) | stack, componenti, Stripe, ruoli di prezzo, capienza, sicurezza, costi, MVP, domande aperte |
| [`docs/decisioni.md`](docs/decisioni.md) | registro delle decisioni |
| [`db/schema.sql`](db/schema.sql) | bozza dello schema Postgres 16 (Supabase) |
| [`docs/ux/architettura-informazioni.md`](docs/ux/architettura-informazioni.md) | mappa del sito, flussi, casi limite, accessibilità |
| [`prototipo/`](prototipo/index.html) | prototipo cliccabile in HTML statico: sito pubblico, area personale, admin, check-in |

## Aprire il prototipo

```
cd prototipo && python3 -m http.server 8000
```

Poi `http://localhost:8000`. Il selettore «Vedi come» in alto mostra i prezzi da visitatore, utente, membro ed
ex membro.
