# ADR 0004: Riserve di capienza con scadenza

Autore: Giulio Occhipinti. Stato: accettata. Data: 05/11/2026. Fonte: `docs/decisioni.md` (righe del 05/11/2026 su capienza, rilascio, riprenotazione, una riserva per persona), `docs/architettura.md` §7.

## Contesto

Il pagamento su Stripe dura fino a 30 minuti e più; tra carrello e pagamento il posto non deve essere venduto a un altro
né restare bloccato per sempre. Un webhook di pagamento può arrivare dopo la scadenza.

## Decisione

- Pool di posti con contatori `held` e `confirmed` e `CHECK held + confirmed <= capacity`; ogni presa è un UPDATE
  condizionale sotto lock dei pool in ordine di id.
- Riserva (`capacity_holds`) di 36 minuti: sessione Stripe di 31 (minimo Stripe 30) più 5 di margine.
- I posti si liberano **solo dopo** che Stripe conferma la scadenza (webhook `expired` o `eas:expire-orders`, che
  prima chiama `expire`).
- Pagamento tardivo: riprenota se ci sono ancora posti, altrimenti `needs_refund`, nessun biglietto, rimborso
  automatico e avviso.
- Un solo ordine `pending` per persona (indice unico): il carrello nuovo chiude il precedente.

## Alternative

- Rilascio cieco a tempo: con un webhook in ritardo emetteva biglietti senza posti (bug della prima bozza).
- Nessuna riserva, controllo solo al pagamento: si incassa e poi si rimborsa chi resta senza posto.

## Conseguenze

- Overselling impossibile anche con un bug nel codice.
- Se il cron si ferma si vende meno (posti bloccati), mai due volte.
- Un pagamento tardivo può finire in rimborso automatico: costo della commissione Stripe non restituita.
