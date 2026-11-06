# stripe-eventals

Autore: Giulio Occhipinti.

Sito per gestire eventi (agenda con giorni, sale, sessioni e sottosezioni; speaker) e vendere l'accesso con
prezzi per fascia (early bird, regular, last minute) e per ruolo (pubblico e membro), membership annuale e codici
sconto, tutto incassato con Stripe. Primo evento: EAS8, Lisbona, 8–10 aprile 2027; Laravel + Filament su hosting
condiviso con MySQL o MariaDB.

Fase attuale: **Fase 1 completa in sviluppo** (passi 1–3 fatti). L'applicazione Laravel è in [`sito/`](sito/README.md):
schema con i vincoli, vendita con Stripe e biglietti con QR, pannello Filament con i ruoli, export CSV, sito pubblico
EN/IT. Da fare prima dell'apertura: contenuti e date definitive dal cliente, hosting, prova con Stripe in test.

| File | Cosa contiene |
|---|---|
| [`sito/`](sito/README.md) | applicazione Laravel 13 + Filament 5: schema della Fase 1 con i vincoli del database, vendita con Stripe, biglietti con QR, pannello `/admin` con le Policy per ruolo, export CSV, pagine pubbliche EN/IT, test Pest su MariaDB/MySQL; installazione e stato dei passi nel suo README |
| [`docs/per-simone.md`](docs/per-simone.md) | riepilogo per il cliente: cosa è pronto, cosa serve da lui, decisioni |
| [`docs/brief.md`](docs/brief.md) | richiesta e decisioni di Giulio |
| [`docs/cliente/`](docs/cliente/) | risposte del cliente (primo e secondo giro del 06/11/2026) |
| [`docs/architettura.md`](docs/architettura.md) | stack Laravel + Filament, MySQL / MariaDB, Stripe solo carta, fasce di prezzo, ruoli, capienza, code con cron, hosting condiviso, sicurezza, costi, Fase 1 e Fase 2 con stima, domande aperte |
| [`docs/decisioni.md`](docs/decisioni.md) | registro delle decisioni |
| [`db/modello-dati.md`](db/modello-dati.md) | modello dei dati per Laravel su MySQL / MariaDB: tabelle, vincoli, indici, dove sta ogni regola, prove |
| [`db/migrazioni-bozza/`](db/migrazioni-bozza/) | migrazioni Laravel per MySQL / MariaDB: tipi di biglietto, fasce e prezzi, ordini e omaggi, capienza, prezzo membro, eventi Stripe, biglietti e accompagnatori |
| [`db/prove/`](db/prove/) | script delle prove con dati finti sulle migrazioni (MySQL 8 e MariaDB) e tabelle minime che presuppongono |
| [`db/archivio/`](db/archivio/) | schema Postgres per Supabase del 05/11/2026, solo come riferimento storico (le migrazioni Postgres del 06/11/2026 mattina sono nella storia di git) |
| [`docs/domande-simone-terzo-giro.md`](docs/domande-simone-terzo-giro.md) | domande aperte per Simone (terzo giro), solo tecniche e di prodotto, con cosa si fa nel frattempo |
| [`strumenti/verifica-hosting/`](strumenti/verifica-hosting/README.md) | script PHP da lanciare via SSH che verifica l'hosting (PHP, estensioni, limiti, funzioni, Composer, cron, disco, rete, MySQL o MariaDB con prove reali di vincoli, lock e trigger) e stampa un riepilogo da incollare |
| [`contenuti/modelli/`](contenuti/modelli/README.md) | modelli CSV (EN/IT) per raccogliere sale, speaker, sessioni, interventi, tipi di biglietto, fasce e prezzi, coerenti con il modello dati e l'import di Filament |
| [`docs/ux/architettura-informazioni.md`](docs/ux/architettura-informazioni.md) | mappa del sito, flussi, casi limite, accessibilità |
| [`proposte/`](proposte/README.md) | tre layout alternativi (Notte di Lisbona, Clinico, Editoriale) da proporre al cliente |
| [`prototipo/`](prototipo/index.html) | prototipo cliccabile in HTML statico: sito pubblico, area personale, admin, check-in |

## Aprire il prototipo

```
cd prototipo && python3 -m http.server 8000
```

Poi `http://localhost:8000`. Il selettore «Vedi come» in alto mostra i prezzi da visitatore, utente, membro ed
ex membro.
