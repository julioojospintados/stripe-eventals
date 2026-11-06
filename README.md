# stripe-eventals

Autore: Giulio Occhipinti.

Sito per gestire eventi (agenda con giorni, sale, sessioni e sottosezioni; speaker) e vendere l'accesso con
prezzi per fascia (early bird, regular, last minute) e per ruolo (pubblico e membro), membership annuale e codici
sconto, tutto incassato con Stripe. Primo evento: EAS8, Lisbona, 8–10 aprile 2027; Laravel + Filament su hosting
condiviso con MySQL o MariaDB.

Fase attuale: **architettura e UX/UI**. Il codice dell'applicazione non c'è ancora.

| File | Cosa contiene |
|---|---|
| [`docs/brief.md`](docs/brief.md) | richiesta e decisioni di Giulio |
| [`docs/cliente/`](docs/cliente/) | risposte del cliente (primo e secondo giro del 06/11/2026) |
| [`docs/architettura.md`](docs/architettura.md) | stack Laravel + Filament, MySQL / MariaDB, Stripe solo carta, fasce di prezzo, ruoli, capienza, code con cron, hosting condiviso, sicurezza, costi, Fase 1 e Fase 2 con stima, domande aperte |
| [`docs/decisioni.md`](docs/decisioni.md) | registro delle decisioni |
| [`db/modello-dati.md`](db/modello-dati.md) | modello dei dati per Laravel su MySQL / MariaDB: tabelle, vincoli, indici, dove sta ogni regola, prove |
| [`db/migrazioni-bozza/`](db/migrazioni-bozza/) | migrazioni Laravel per MySQL / MariaDB: tipi di biglietto, fasce e prezzi, ordini e omaggi, capienza, prezzo membro, eventi Stripe, biglietti e accompagnatori |
| [`db/prove/`](db/prove/) | script delle prove con dati finti sulle migrazioni (MySQL 8 e MariaDB) e tabelle minime che presuppongono |
| [`db/archivio/`](db/archivio/) | schema Postgres per Supabase del 05/11/2026, solo come riferimento storico (le migrazioni Postgres del 06/11/2026 mattina sono nella storia di git) |
| [`docs/ux/architettura-informazioni.md`](docs/ux/architettura-informazioni.md) | mappa del sito, flussi, casi limite, accessibilità |
| [`prototipo/`](prototipo/index.html) | prototipo cliccabile in HTML statico: sito pubblico, area personale, admin, check-in |

## Aprire il prototipo

```
cd prototipo && python3 -m http.server 8000
```

Poi `http://localhost:8000`. Il selettore «Vedi come» in alto mostra i prezzi da visitatore, utente, membro ed
ex membro.
