# ADR 0010: Permessi granulari con Policy

Autore: Giulio Occhipinti. Stato: proposta, da confermare. Data: 06/11/2026. Fonte: `docs/decisioni.md` (righe «Ruoli dello staff», «Codici sconto»), `docs/architettura.md` §5.3.

## Contesto

Tre ruoli dello staff (admin, commerciale, organizzatore) con aree diverse; il cliente potrebbe volerli ritoccare
senza uno sviluppatore. Il ruolo «membro» non è dello staff.

## Decisione

`spatie/laravel-permission` con permessi granulari (`<risorsa>.<verbo>` e azioni) definiti in `App\Enums\StaffRole`
e creati dal seeder `RolesAndPermissionsSeeder`, rilanciabile in produzione. Le Policy controllano solo permessi, mai
il nome del ruolo. L'admin ritocca i permessi di commerciale e organizzatore dal pannello (Roles); il ruolo admin non si
modifica. Nessun ruolo cancella. Membro derivato da `memberships`. Matrice in [`../permessi.md`](../permessi.md).

## Alternative

- Controlli sul nome del ruolo (`hasRole('admin')`): ogni modifica dei ruoli richiede codice.
- Filament Shield: una dipendenza in più per generare quello che `StaffRole` fa in poche righe.

## Conseguenze

- Un permesso nuovo richiede una riga in `StaffRole` e il seeder al deploy.
- Le modifiche dell'admin sopravvivono al seeder; un permesso nuovo arriva comunque ai ruoli predefiniti.
