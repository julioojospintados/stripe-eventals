# Brief del progetto

Autore: Giulio Occhipinti. Aggiornato al 05/11/2026.

## Cosa serve

Un sito per gestire e vendere l'accesso a eventi (conferenze, festival, giornate di formazione) con:

- **agenda** a più livelli: evento → giorni → tracce o sale → sessioni → sottosezioni (interventi dentro una
  sessione, per esempio i talk di un panel o i moduli di un workshop);
- **speaker** con scheda, foto, biografia e sessioni in cui parlano;
- **vendita dell'accesso** con prezzi diversi per ruolo: pubblico (non membro), membro, studente;
- tutto incassato con **Stripe**.

## Decisioni di Giulio (05/11/2026)

| Tema | Scelta |
|---|---|
| Chi vende | Un solo organizzatore, un solo account Stripe (niente Stripe Connect per ora) |
| Membership | Venduta sul sito, come abbonamento Stripe; dà il prezzo «membro» su biglietti e sessioni |
| Sconti | Anche codici sconto, oltre ai prezzi per ruolo |
| Cosa si compra | Pass all'evento intero, pass giornaliero **e** sessioni a pagamento (workshop, masterclass) a parte |
| Studenti | Da verificare (proposta: email universitaria o documento approvato a mano) |

## Fuori ambito per ora

Codice dell'applicazione. Questa fase produce solo architettura informatica e UX/UI (documenti, schema dei dati,
prototipo cliccabile in HTML statico).

## Stack di partenza suggerito

Lo stesso che Giulio usa già in Eventy (Next.js su Vercel, Supabase), salvo motivi forti per cambiare.
