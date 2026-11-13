# ADR 0007: Accesso clienti con codice, staff con password e MFA

Autore: Giulio Occhipinti. Stato: proposta, da confermare. Data: 06/11/2026. Fonte: `docs/decisioni.md` (righe «Accesso», «Lo staff entra solo dal pannello»), `docs/architettura.md` §5.1, §5.2.

## Contesto

Chi compra entra poche volte e non deve ricordare una password. Lo staff maneggia soldi e dati personali. Regola del
ruolo: password come hash scrypt o argon2, cookie `httpOnly`, limite ai tentativi.

## Decisione

- Clienti: codice di 6 cifre via email, salvato come HMAC con `APP_KEY`, 10 minuti, 5 tentativi, limiti per email
  e per IP; account creato al primo accesso; email mandata dopo la risposta (`afterResponse`), non dalla coda.
- Staff: solo da `/admin`, password argon2id (`HASH_DRIVER=argon2id`) e TOTP obbligatorio di Filament con codici di
  recupero. Un indirizzo dello staff non riceve il codice e non apre la sessione con il codice.
- Primo admin con `php artisan eventals:create-admin`.

## Alternative

- Password per tutti: più supporto e reset, nessun vantaggio per chi compra una volta l'anno.
- Codice anche per lo staff: aggirerebbe la MFA (stessa sessione del pannello).
- Link magico: si apre anche dal filtro antispam che visita i link.

## Conseguenze

- La consegna delle email è parte dell'accesso: se l'SMTP si ferma i clienti non entrano (esercizio).
- PHP deve avere argon2; altrimenti va segnalato (bcrypt non rispetta la regola).
