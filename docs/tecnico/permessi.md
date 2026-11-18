# Permessi dello staff

Autore: Giulio Occhipinti. Aggiornato al 18/11/2026.

## Come funzionano

- **Definizione nel codice:** `sito/app/Enums/StaffRole.php`. Risorse (`RESOURCES`) con i verbi `view`, `create`,
  `update`, `delete`, più le azioni (`ACTIONS`). `permissions()` dà i permessi predefiniti di ogni ruolo.
- **Seeder:** `sito/database/seeders/RolesAndPermissionsSeeder.php`, si può rilanciare in produzione
  (`php artisan db:seed --class=RolesAndPermissionsSeeder --force`): crea i permessi nuovi, cancella quelli tolti dal
  codice, dà a ogni ruolo esistente (admin compreso) solo i permessi **nuovi** e lascia le scelte fatte nel pannello
  (Roles). Un ruolo nuovo riceve i permessi predefiniti.
- **Admin modificabile, ma non fino a chiudersi fuori:** il ruolo admin si cambia dal pannello come gli altri, ma
  tiene sempre `StaffRole::ADMIN_LOCKED` (`roles.view`, `roles.update`, `staff.view`, `staff.create`,
  `staff.update`): se li si toglie, al salvataggio tornano (e li rimette anche il seeder).
- **Elimina (`<risorsa>.delete`):** admin su tutte le risorse, organizzatore sulle sue (evento, vendite, codici
  sconto, programma), commerciale mai. Vale solo per la configurazione non ancora usata da ordini, biglietti,
  riserve o utilizzi (`app/Services/ConfigDeletion.php`, messaggio «Usato da N ordini: disattivalo invece di
  eliminarlo», audit `config.deleted`). Lo staff non ha `delete` (`StaffRole::NOT_DELETABLE`); ordini, biglietti,
  rimborsi, contestazioni, membership, eventi Stripe e registro audit non hanno nessuna azione di eliminazione.
  Sulle installazioni già seminate la migrazione `2026_11_18_090000_grant_config_delete_permissions` dà ad admin e
  organizzatore i loro `*.delete`, che prima nessuno aveva.
- **Controllo:** le Policy in `sito/app/Policies/` chiedono sempre un permesso (`$user->can('orders.refund')`), mai
  il nome del ruolo. `ResourcePolicy` copre le risorse standard; `OrderPolicy`, `TicketPolicy`, `RolePolicy`,
  `MembershipPlanPolicy`, `MembershipPolicy`, `DisputePolicy`, `AuditLogPolicy` le azioni; `ProgramSessionPolicy::import`
  l'import CSV del programma (le azioni di import sono visibili solo con `program.import`, perché gli Importer di
  Filament non passano dalle Policy).
- **Fuori dal pannello:** l'accesso a `/admin` passa da `User::canAccessPanel` (solo chi ha un ruolo dello staff);
  il download degli export (`ExportDownloadController`) ricontrolla ruolo e permesso del tipo di export.
- **Membro** non è un ruolo: si deriva da `memberships` (`User::isMemberUntil`).
- **Test:** `tests/Feature/Panel/PermissionsTest.php` («i ruoli hanno esattamente i permessi predefiniti; elimina solo
  admin e organizzatore, mai lo staff», «il seeder si rilancia…», «ogni ruolo può solo le azioni di §5.3») e
  `tests/Feature/Panel/PolicyTest.php`.

## Matrice ruolo × permesso

Generata dal database dopo `php artisan migrate:fresh --seed`, quindi dal seeder. Per rigenerarla:
`php strumenti/genera-doc-db/genera.php` (aggiorna anche `database.md`). Non modificare a mano il blocco qui sotto.

<!-- matrice-permessi:inizio -->

| Permesso | Significato | admin | commerciale | organizzatore |
|---|---|---|---|---|
| `event.view` | Evento, date e giorni: vedi | ✔ | ✔ | ✔ |
| `event.create` | Evento, date e giorni: crea | ✔ | — | ✔ |
| `event.update` | Evento, date e giorni: modifica | ✔ | — | ✔ |
| `event.delete` | Evento, date e giorni: elimina | ✔ | — | ✔ |
| `ticket_types.view` | Tipi di biglietto: vedi | ✔ | ✔ | ✔ |
| `ticket_types.create` | Tipi di biglietto: crea | ✔ | — | ✔ |
| `ticket_types.update` | Tipi di biglietto: modifica | ✔ | — | ✔ |
| `ticket_types.delete` | Tipi di biglietto: elimina | ✔ | — | ✔ |
| `price_tiers.view` | Fasce di prezzo: vedi | ✔ | ✔ | ✔ |
| `price_tiers.create` | Fasce di prezzo: crea | ✔ | — | ✔ |
| `price_tiers.update` | Fasce di prezzo: modifica | ✔ | — | ✔ |
| `price_tiers.delete` | Fasce di prezzo: elimina | ✔ | — | ✔ |
| `ticket_prices.view` | Prezzi tipo × fascia × ruolo: vedi | ✔ | ✔ | ✔ |
| `ticket_prices.create` | Prezzi tipo × fascia × ruolo: crea | ✔ | — | ✔ |
| `ticket_prices.update` | Prezzi tipo × fascia × ruolo: modifica | ✔ | — | ✔ |
| `ticket_prices.delete` | Prezzi tipo × fascia × ruolo: elimina | ✔ | — | ✔ |
| `capacity_pools.view` | Capienze: vedi | ✔ | ✔ | ✔ |
| `capacity_pools.create` | Capienze: crea | ✔ | — | ✔ |
| `capacity_pools.update` | Capienze: modifica | ✔ | — | ✔ |
| `capacity_pools.delete` | Capienze: elimina | ✔ | — | ✔ |
| `discount_codes.view` | Codici sconto: vedi | ✔ | ✔ | ✔ |
| `discount_codes.create` | Codici sconto: crea | ✔ | ✔ | ✔ |
| `discount_codes.update` | Codici sconto: modifica | ✔ | ✔ | ✔ |
| `discount_codes.delete` | Codici sconto: elimina | ✔ | — | ✔ |
| `staff.view` | Utenti dello staff: vedi | ✔ | — | — |
| `staff.create` | Utenti dello staff: crea | ✔ | — | — |
| `staff.update` | Utenti dello staff: modifica | ✔ | — | — |
| `rooms.view` | Sale: vedi | ✔ | — | ✔ |
| `rooms.create` | Sale: crea | ✔ | — | ✔ |
| `rooms.update` | Sale: modifica | ✔ | — | ✔ |
| `rooms.delete` | Sale: elimina | ✔ | — | ✔ |
| `tracks.view` | Tracce: vedi | ✔ | — | ✔ |
| `tracks.create` | Tracce: crea | ✔ | — | ✔ |
| `tracks.update` | Tracce: modifica | ✔ | — | ✔ |
| `tracks.delete` | Tracce: elimina | ✔ | — | ✔ |
| `program_sessions.view` | Sessioni del programma, interventi e speaker per sessione: vedi | ✔ | — | ✔ |
| `program_sessions.create` | Sessioni del programma, interventi e speaker per sessione: crea | ✔ | — | ✔ |
| `program_sessions.update` | Sessioni del programma, interventi e speaker per sessione: modifica | ✔ | — | ✔ |
| `program_sessions.delete` | Sessioni del programma, interventi e speaker per sessione: elimina | ✔ | — | ✔ |
| `speakers.view` | Speaker: vedi | ✔ | — | ✔ |
| `speakers.create` | Speaker: crea | ✔ | — | ✔ |
| `speakers.update` | Speaker: modifica | ✔ | — | ✔ |
| `speakers.delete` | Speaker: elimina | ✔ | — | ✔ |
| `orders.view` | Ordini: vedi (senza importi) | ✔ | ✔ | ✔ |
| `orders.view_amounts` | Ordini, pagamenti, rimborsi: vedi gli importi | ✔ | ✔ | — |
| `orders.refund` | Ordini: rimborsa | ✔ | ✔ | — |
| `orders.review` | Ordini da rivedere: decidi (emetti i biglietti o rimborsa) | ✔ | ✔ | — |
| `orders.export` | Export contabile degli ordini | ✔ | ✔ | — |
| `complimentary.view` | Omaggi: vedi | ✔ | ✔ | ✔ |
| `complimentary.issue` | Omaggi: emetti e revoca | ✔ | — | ✔ |
| `tickets.view` | Biglietti: vedi | ✔ | ✔ | ✔ |
| `tickets.correct_attendee` | Biglietti: correggi un refuso nel nome | ✔ | — | — |
| `tickets.resend` | Biglietti: reinvia per email | ✔ | ✔ | — |
| `attendees.export` | Export dei partecipanti per il check-in | ✔ | ✔ | ✔ |
| `consents.export` | Export degli iscritti alla newsletter con consenso | ✔ | ✔ | — |
| `memberships.view` | Membership: vedi (si modificano in Stripe) | ✔ | ✔ | — |
| `membership_plans.view` | Prezzo della membership: vedi | ✔ | ✔ | — |
| `membership_plans.update` | Prezzo della membership e Price ID Stripe: modifica | ✔ | — | — |
| `disputes.view` | Contestazioni dei pagamenti: vedi | ✔ | ✔ | — |
| `reconciliation.run` | Riconciliazione con Stripe: avvia | ✔ | ✔ | — |
| `roles.view` | Ruoli e permessi: vedi | ✔ | — | — |
| `roles.update` | Ruoli e permessi: modifica | ✔ | — | — |
| `audit_log.view` | Registro audit: vedi | ✔ | — | — |
| `invite_codes.view` | Codici d'invito per espositori (fase 2): vedi | ✔ | ✔ | ✔ |
| `invite_codes.manage` | Codici d'invito per espositori (fase 2): crea e modifica | ✔ | ✔ | — |
| `videos.manage` | Video e ruoli ammessi (fase 2) | ✔ | — | ✔ |
| `program.import` | Programma: import da CSV di sale, speaker, sessioni e interventi | ✔ | — | ✔ |

Generata il 18/11/2026 da `strumenti/genera-doc-db/genera.php` sul database `eas8` (67 permessi).

<!-- matrice-permessi:fine -->

## Scelte da confermare

| # | Scelta nel codice | Perché è da confermare |
|---|---|---|
| 1 | `*.delete` ad admin e organizzatore, solo per la configurazione mai usata (18/11/2026, dopo la prova di Simone); ciò che è usato si spegne (`active`) | Un errore di inserimento si cancella; nulla che tocchi ordini o soldi sparisce |
| 2 | L'organizzatore vede gli ordini **senza importi** (manca `orders.view_amounts`) e non vede contestazioni né membership | §5.3 diceva «lettura senza importi»: confermato; contestazioni e membership non erano nominate |
| 3 | `consents.export` (iscritti alla newsletter) a commerciale e admin, non all'organizzatore | Dati personali: decide il cliente chi li scarica |
| 4 | `tickets.resend` (reinvio del biglietto, anche a un altro indirizzo) a commerciale e admin, non all'organizzatore | Mandare un QR a un indirizzo nuovo equivale a cederlo |
| 5 | `orders.review` (ordini `needs_review`: emetti o rimborsa) a commerciale e admin | È una decisione sui soldi, come il rimborso |
| 6 | `reconciliation.run` (avvio manuale della riconciliazione) a commerciale e admin | Innocua (solo lettura su Stripe e rielaborazione idempotente) |
| 7 | `membership_plans.update` (prezzo e Price ID Stripe della membership) solo admin | Un Price ID sbagliato fa pagare l'importo sbagliato |
| 8 | `tickets.correct_attendee` solo admin (massimo 3 correzioni, motivo, audit) | Unica eccezione al «nessun cambio di nome» (decisioni 06/11/2026) |
| 9 | `staff.*`, `roles.*`, `audit_log.view` solo admin; il ruolo admin si modifica dal pannello ma tiene sempre `StaffRole::ADMIN_LOCKED` | Evita che l'admin si chiuda fuori |
| 10 | Permessi di fase 2 già presenti senza funzione: `invite_codes.*` (commerciale gestisce, organizzatore vede), `videos.manage` (organizzatore e admin) | Nessun effetto oggi; da rivedere quando arrivano le funzioni |
| 11 | Le modifiche fatte nel pannello ai ruoli (admin compreso) sopravvivono al seeder, ma un permesso **nuovo** nel codice arriva comunque al ruolo predefinito | Se l'admin ha tolto un'area a un ruolo, un permesso nuovo di quell'area torna: da sapere prima di un rilascio |
| 12 | La riga «Fatture, export contabili» di §5.3 copre solo `orders.export`: le fatture si fanno in Stripe e Xero, non nel pannello | Nessun permesso «fatture» nel codice |
| 13 | Programma (`rooms.*`, `tracks.*`, `program_sessions.*`, `speakers.*`) e `program.import` a organizzatore e admin; il commerciale non vede il programma nel pannello (lo vede sul sito) | Richiesta della Fase 2; se il commerciale deve controllare le sessioni basta dargli i `.view` |
