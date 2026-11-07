# ADR 0006: QR firmato Ed25519

Autore: Giulio Occhipinti. Stato: proposta, da confermare. Data: 06/11/2026. Fonte: `docs/decisioni.md` (righe «QR dei biglietti firmato con Ed25519», «Check-in del partner»), `docs/architettura.md` §11.

## Contesto

Il check-in lo fa un partner sul posto, forse senza rete. Il QR non deve essere indovinabile né falsificabile, e il
partner non deve avere un segreto che gli permetta di creare biglietti.

## Decisione

QR `EAS1.<dati>.<firma>`: dati JSON con `ticket_id`, `qr_version`, `event_id`, `qr_key_id`, firmati Ed25519 con
`ext-sodium`; chiave privata solo nel `.env` (`QR_SIGNING_PRIVATE_KEY`). Niente nome nel QR. Il partner verifica con
la chiave pubblica e confronta `qr_version` con l'export CSV dei biglietti validi.

## Alternative

- HMAC: il partner dovrebbe avere il segreto, quindi potrebbe creare QR validi.
- Codice casuale verificato online: non funziona senza rete.

## Conseguenze

- Una correzione dell'email aumenta `qr_version`: il QR vecchio non vale più.
- Rotazione: il campo `k` dice quale chiave ha firmato. Si firma con la chiave attiva e si verifica con l'attiva più
  le pubbliche precedenti di `QR_PREVIOUS_PUBLIC_KEYS`; un `k` sconosciuto è rifiutato. I QR già inviati restano
  validi. Procedura in `esercizio.md`, «Chiavi e rotazione».
