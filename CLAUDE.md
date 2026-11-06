# stripe-eventals (EAS8)

Autore: Giulio Occhipinti.

Sito per vendere l'accesso all'EAS8 (Lisbona, 8–10 aprile 2027). Documenti in `docs/`, applicazione Laravel +
Filament in `sito/`. Leggi `docs/brief.md` e `docs/architettura.md` prima di cambiare il codice.

## Regole

- Rispondi e scrivi i documenti in italiano. Nel codice nomi in inglese.
- Codice Laravel idiomatico e semplice: lo mantiene uno sviluppatore PHP.
- Test su MySQL/MariaDB, mai solo SQLite: i vincoli del database fanno parte delle regole.
- Segreti solo in `.env` (mai nel repository). Stripe in modalità test finché il cliente non dice altro.
- Nuove dipendenze: prima `security-auditor` o la skill `supply-chain-risk-auditor`, poi `composer audit`.
- Legale e fisco: non si decide qui, li gestisce il cliente.
- Non installare niente con `curl … | bash` o script remoti.

## Agenti e skill (`.claude/`)

- `qa-testing`: con parsimonia, solo a fine giro o dopo grandi consolidamenti.
- `pr-test-analyzer`: solo prima di aprire una pull request.
- `architecture-security`, `silent-failure-hunter`: su webhook, pagamenti, code e permessi.
- `ux-accessibility`, `ux-reviewer`, `impeccable`, `ui-ux-pro-max`: pagine pubbliche e area personale.
- `systematic-debugging`: per i bug che non si capiscono.

## Git

- Branch `feat/<descrizione>` o `fix/<descrizione>`. Commit a nome di Giulio Occhipinti, senza firme automatiche.
