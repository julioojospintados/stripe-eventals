# ADR 0009: Nessun cambio di nome sul biglietto

Autore: Giulio Occhipinti. Stato: accettata (la correzione del refuso è proposta). Data: 06/11/2026. Fonte: `docs/decisioni.md` (riga «Nessun cambio di nome»), `docs/architettura.md` §6.7.

## Contesto

Il cliente non vuole cessioni di biglietti (mercato secondario, biglietti a prezzo membro passati ad altri). Un errore di
battitura però renderebbe il biglietto inutilizzabile.

## Decisione

Nominativo immutabile (trigger `tickets_guard`). Unica eccezione: correzione di un refuso da parte di un admin
(`tickets.correct_attendee`), con motivo, audit, massimo 3 volte (`CHECK tickets_numbers_valid`); se cambia l'email,
nuovo `qr_version` e nuovo invio.

## Alternative

- Cambio nominativo gratuito fino a 48 ore prima (prima bozza): tolto su richiesta del cliente.
- Nessuna eccezione: un refuso costerebbe un rimborso e un nuovo acquisto.

## Conseguenze

- Niente funzione self-service, niente regole sulle scadenze.
- La correzione è tracciata e limitata: l'admin non può usarla per cedere biglietti senza lasciare traccia.
