# stripe-eventals

Autore: Giulio Occhipinti.

Sito per gestire eventi (agenda con giorni, sale, sessioni e sottosezioni; speaker) e vendere l'accesso con
prezzi per ruolo (pubblico e membro), membership annuale e codici sconto, tutto incassato con Stripe.

Fase attuale: **architettura e UX/UI**. Il codice dell'applicazione non c'è ancora.

| File | Cosa contiene |
|---|---|
| [`docs/brief.md`](docs/brief.md) | richiesta e decisioni di Giulio |
| [`docs/architettura.md`](docs/architettura.md) | stack Laravel + Filament, componenti, Stripe, ruoli, capienza, hosting, sicurezza, costi, MVP, domande aperte |
| [`docs/decisioni.md`](docs/decisioni.md) | registro delle decisioni |
| [`db/modello-dati.md`](db/modello-dati.md) | modello dei dati per Laravel: tabelle, vincoli, indici, dove sta ogni regola |
| [`db/migrazioni-bozza/`](db/migrazioni-bozza/) | migrazioni Laravel di esempio per ordini, capienza, prezzo membro, eventi Stripe |
| [`db/archivio/`](db/archivio/) | schema Postgres per Supabase del 05/11/2026, solo come riferimento storico |
| [`docs/ux/architettura-informazioni.md`](docs/ux/architettura-informazioni.md) | mappa del sito, flussi, casi limite, accessibilità |
| [`prototipo/`](prototipo/index.html) | prototipo cliccabile in HTML statico: sito pubblico, area personale, admin, check-in |

## Aprire il prototipo

```
cd prototipo && python3 -m http.server 8000
```

Poi `http://localhost:8000`. Il selettore «Vedi come» in alto mostra i prezzi da visitatore, utente, membro ed
ex membro.
