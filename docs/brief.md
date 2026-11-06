# Brief del progetto

Autore: Giulio Occhipinti. Aggiornato al 05/11/2026.

## Cosa serve

Un sito per gestire e vendere l'accesso a eventi (conferenze, festival, giornate di formazione) con:

- **agenda** a più livelli: evento → giorni → tracce o sale → sessioni → sottosezioni (interventi dentro una
  sessione, per esempio i talk di un panel o i moduli di un workshop);
- **speaker** con scheda, foto, biografia e sessioni in cui parlano;
- **vendita dell'accesso** con prezzi diversi per ruolo: pubblico (non membro) e membro;
- tutto incassato con **Stripe**.

## Decisioni di Giulio (05/11/2026)

| Tema | Scelta |
|---|---|
| Chi vende | Un solo organizzatore, un solo account Stripe (niente Stripe Connect per ora) |
| Membership | Venduta sul sito, come abbonamento Stripe; dà il prezzo «membro» su biglietti e sessioni |
| Sconti | Anche codici sconto, oltre ai prezzi per ruolo |
| Cosa si compra | Pass all'evento intero, pass giornaliero **e** sessioni a pagamento (workshop, masterclass) a parte |
| Studenti | Nessun ruolo studente (deciso il 05/11/2026): solo prezzo pubblico e prezzo membro |
| Prezzo membro | Solo sul biglietto del membro che compra; gli accompagnatori pagano il prezzo pubblico |
| Limite prezzo membro | Al massimo un biglietto a prezzo membro per membro, per evento e per tipo (un pass, un giornaliero per giorno, uno per ogni sessione a pagamento), anche su più ordini |
| Rimborsi | Nessun rimborso; cambio nominativo gratuito fino a 48 ore prima (da far confermare alla catena legale) |
| Durata membership | 12 mesi dall'acquisto, rinnovo automatico annullabile dal portale clienti Stripe |
| Su misura o pretix | Tutto su misura, niente pretix |
| Legale e fisco | ~~IVA, fattura elettronica, condizioni di vendita e rimborsi alla catena legale-fiscale prima dello sviluppo~~ (superata il 06/11/2026) |

## Decisioni del 06/11/2026 (dopo le risposte del cliente)

| Tema | Scelta |
|---|---|
| Stack | Laravel + Filament sul server del cliente, dati in UE (al posto di Next.js + Supabase su Vercel) |
| Evento | EAS8, Lisbona, 8–10 aprile 2027 (come il sito pubblico; il modulo diceva 10-14, da confermare) |
| Aspetto | Coerente con eas-aligners.com; la versione migliorata resta in `proposte/` da proporre dopo |
| Legale e fisco | Non si chiedono più a Giulio: li gestisce il cliente a lavoro ultimato |

Dettagli delle risposte: [`docs/cliente/risposte-2026-11-06.md`](cliente/risposte-2026-11-06.md).

## Decisioni del cliente nel secondo giro (06/11/2026)

| Tema | Scelta |
|---|---|
| Server | Hosting condiviso con SSH e MySQL/MariaDB, gestito da Simone |
| Prezzi | Fasce early bird, regular e last minute con date modificabili; early bird (−30%) solo per i membri |
| Biglietti | Nessun cambio di nome; solo carta; in più accompagnatori, omaggi per speaker e staff, espositori e sponsor |
| Membership | Attiva subito dopo l'acquisto |
| Lingua | Inglese predefinito, italiano come seconda lingua |
| Video | Wistia e Vimeo, accesso per ruolo |
| Fuori dalla prima apertura | Abstract e poster, attestati, crediti formativi |

Dettagli: [`docs/cliente/risposte-2026-11-06-secondo-giro.md`](cliente/risposte-2026-11-06-secondo-giro.md).

## Fuori ambito per ora

Codice dell'applicazione. Questa fase produce solo architettura informatica e UX/UI (documenti, schema dei dati,
prototipo cliccabile in HTML statico).

## Stack

Laravel + Filament dal 06/11/2026 (prima: Next.js su Vercel e Supabase, come Eventy).
