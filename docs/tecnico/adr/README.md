# Decisioni architetturali (ADR)

Autore: Giulio Occhipinti. Aggiornato al 07/11/2026.

Formato breve: contesto, decisione, alternative, conseguenze. Le motivazioni vengono da `docs/decisioni.md` e
`docs/architettura.md`; «proposta, da confermare» è lo stato che quei documenti danno alla scelta.

| ADR | Decisione | Stato |
|---|---|---|
| [0001](0001-laravel-filament-hosting-condiviso.md) | Laravel + Filament su hosting condiviso | accettata |
| [0002](0002-mysql-mariadb-vincoli-trigger.md) | MySQL / MariaDB con vincoli e trigger opzionali | accettata |
| [0003](0003-price-data-sconti-dal-server.md) | `price_data` al posto dei Price Stripe, sconti calcolati dal server | accettata |
| [0004](0004-riserve-di-capienza-con-scadenza.md) | Riserve di capienza con scadenza | accettata |
| [0005](0005-webhook-in-coda-stripe-events.md) | Webhook in coda con tabella `stripe_events` | accettata |
| [0006](0006-qr-firmato-ed25519.md) | QR firmato Ed25519 | proposta, da confermare |
| [0007](0007-accesso-clienti-codice-staff-mfa.md) | Accesso clienti con codice, staff con password e MFA | proposta, da confermare |
| [0008](0008-code-con-cron.md) | Code con cron, senza processi sempre attivi | proposta, da confermare |
| [0009](0009-nessun-cambio-di-nome.md) | Nessun cambio di nome sul biglietto | accettata (la correzione del refuso è proposta) |
| [0010](0010-permessi-granulari-policy.md) | Permessi granulari con Policy | proposta, da confermare |
