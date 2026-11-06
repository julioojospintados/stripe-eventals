# Content templates · Modelli per i contenuti

Author · Autore: Giulio Occhipinti. 06/11/2026.

**EN** · These CSV files collect, in one place, the content that today is spread across emails and documents: rooms,
speakers, sessions and their parts, ticket types, price tiers and prices. Each file is imported as it is into the admin
panel (Filament CSV import), so please keep the column names exactly as they are.

**IT** · Questi file CSV raccolgono in un posto solo i contenuti che oggi sono sparsi tra email e documenti: sale,
speaker, sessioni e loro parti, tipi di biglietto, fasce e prezzi. Ogni file si importa così com'è nel pannello
(import CSV di Filament): i nomi delle colonne vanno lasciati identici.

| File | EN | IT | When · Quando |
|---|---|---|---|
| [`rooms.csv`](rooms.csv) | The 12 rooms | Le 12 sale | Phase 2 · Fase 2 |
| [`speakers.csv`](speakers.csv) | About 30 speakers | Circa 30 speaker | Phase 2 · Fase 2 |
| [`sessions.csv`](sessions.csv) | About 120 sessions, workshops included | Circa 120 sessioni, workshop compresi | Phase 2 (workshops: Phase 1) · Fase 2 (workshop: Fase 1) |
| [`session_parts.csv`](session_parts.csv) | Talks inside a session | Interventi dentro una sessione | Phase 2 · Fase 2 |
| [`ticket_types.csv`](ticket_types.csv) | Ticket types (known rows prefilled) | Tipi di biglietto (righe note già compilate) | Phase 1 · Fase 1 |
| [`price_tiers.csv`](price_tiers.csv) | Early bird, regular, last minute and their dates | Early bird, regular, last minute e le loro date | Phase 1 · Fase 1 |
| [`ticket_prices.csv`](ticket_prices.csv) | Prices per type × tier × role (known prices prefilled) | Prezzi per tipo × fascia × ruolo (prezzi noti già compilati) | Phase 1 · Fase 1 |

Phase 1 = sales opening; phase 2 = full programme (`docs/architettura.md` §18). · Fase 1 = apertura delle vendite;
fase 2 = programma completo.

---

## 1. General rules · Regole generali

| Rule · Regola | EN | IT |
|---|---|---|
| Encoding · Codifica | UTF-8 (accents and any alphabet work). | UTF-8 (funzionano accenti e ogni alfabeto). |
| Separator · Separatore | Comma `,`. Text containing commas or line breaks is put in double quotes `"…"` (Excel and Google Sheets do it for you). | Virgola `,`. Il testo con virgole o a capo va tra virgolette `"…"` (Excel e Google Sheets lo fanno da soli). |
| Dates · Date | ISO: `2027-04-08` (year-month-day). | ISO: `2027-04-08` (anno-mese-giorno). |
| Times · Orari | 24 hours: `09:00`, `14:30`. Local time in **Lisbon** (time zone `Europe/Lisbon`; in April it is UTC+1): just write the time printed in the programme. | 24 ore: `09:00`, `14:30`. Ora locale di **Lisbona** (fuso `Europe/Lisbon`; ad aprile è UTC+1): si scrive l'ora del programma stampato. |
| Date and time · Data e ora | `2027-01-15 00:00` (a space between them), Lisbon time. End dates are **excluded**: a tier ending `2027-02-01 00:00` lasts until 31 January at midnight. | `2027-01-15 00:00` (uno spazio in mezzo), ora di Lisbona. Le date di fine sono **escluse**: una fascia che finisce `2027-02-01 00:00` vale fino alla mezzanotte del 31 gennaio. |
| Amounts · Importi | Euro with a dot for decimals, no `€`, no thousands separator: `276.50`. | Euro con il punto per i decimali, senza `€` né separatore delle migliaia: `276.50`. |
| Yes / no · Sì / no | `yes` or `no`, lowercase. | `yes` o `no`, minuscolo. |
| Lists · Elenchi | Codes separated by a vertical bar, no spaces: `spk-001\|spk-002`. The order is the order shown on the site. | Codici separati da una barra verticale, senza spazi: `spk-001\|spk-002`. L'ordine è quello mostrato sul sito. |
| Empty cell · Cella vuota | Means «no value» or «not decided yet». | Vuol dire «nessun valore» o «non ancora deciso». |
| Codes · Codici | The `code` column is the row's permanent key: letters, digits and `-`, unique in the file (e.g. `spk-001`, `ses-001`, `room-01`, `ws-01`). **Never change a code after the first import**: a second import with the same code updates the row instead of creating a copy. | La colonna `code` è la chiave fissa della riga: lettere, cifre e `-`, unica nel file (es. `spk-001`, `ses-001`, `room-01`, `ws-01`). **Non cambiare un codice dopo il primo import**: un secondo import con lo stesso codice aggiorna la riga invece di crearne una copia. |
| Example rows · Righe d'esempio | Rows whose code starts with `EXAMPLE-` (and the names with `EXAMPLE` / `ESEMPIO`) are fictitious: delete them. The import skips them anyway. | Le righe con il codice che comincia per `EXAMPLE-` (e i nomi con `EXAMPLE` / `ESEMPIO`) sono finte: cancellale. L'import le salta comunque. |
| `notes` | Free notes for the staff, never imported or published. | Note libere per lo staff, mai importate né pubblicate. |
| Translations · Traduzioni | `_en` columns are required, `_it` columns are optional: if empty, the Italian site shows the English text. | Le colonne `_en` sono obbligatorie, quelle `_it` facoltative: se vuote, il sito in italiano mostra il testo inglese. |
| Check before saving · Prova prima di salvare | The panel first runs a dry run and lists the rows with errors (unknown room or speaker, day outside the event, overlapping sessions in the same room); nothing is saved until they are fixed. | Il pannello fa prima una prova a vuoto ed elenca le righe con errori (sala o speaker sconosciuti, giorno fuori dall'evento, sessioni sovrapposte nella stessa sala); non salva nulla finché non sono corrette. |

Import order · Ordine di import: `rooms` → `speakers` → `sessions` → `session_parts`; `ticket_types` →
`price_tiers` → `ticket_prices`.

---

## 2. Columns · Colonne

Required · Obbligatoria: **R**. Optional · Facoltativa: –.

### `rooms.csv`

| Column | R | Format | EN | IT |
|---|---|---|---|---|
| `code` | **R** | code | Room key, used in `sessions.csv` | Chiave della sala, usata in `sessions.csv` |
| `name_en`, `name_it` | **R** / – | text | Room name | Nome della sala |
| `capacity` | **R** | whole number | Seats in the room | Posti a sedere |
| `floor` | – | whole number | Floor: `0` ground floor, `1` first, `-1` basement | Piano: `0` terra, `1` primo, `-1` interrato |
| `position` | – | whole number | Order in the programme grid (1 = first column) | Ordine nella griglia del programma (1 = prima colonna) |
| `notes` | – | text | Internal notes | Note interne |

### `speakers.csv`

| Column | R | Format | EN | IT |
|---|---|---|---|---|
| `code` | **R** | code | Speaker key, used in sessions | Chiave dello speaker, usata nelle sessioni |
| `honorific` | – | text | Title before the name: `Dr.`, `Prof.` | Titolo prima del nome: `Dr.`, `Prof.` |
| `first_name`, `last_name` | **R** | text | Name and surname as they should appear | Nome e cognome come vanno mostrati |
| `affiliation` | – | text | University, clinic or company | Università, clinica o azienda |
| `country` | – | 2 letters | ISO country code: `PT`, `IT`, `FR`, `US` | Codice ISO del paese: `PT`, `IT`, `FR`, `US` |
| `bio_en`, `bio_it` | **R** / – | text | Short biography, plain text, up to about 1,000 characters; new paragraph = line break inside the cell | Biografia breve, testo semplice, fino a circa 1.000 caratteri; nuovo paragrafo = a capo dentro la cella |
| `photo_file` | – | file name | Photo file name (section 3) | Nome del file della foto (sezione 3) |
| `website` | – | URL | Main web page, with `https://` | Pagina web principale, con `https://` |
| `other_links` | – | URLs with `\|` | Other public profiles | Altri profili pubblici |
| `email` | – | email | **Never published**: only for the complimentary ticket and contacts | **Mai pubblicata**: serve solo per il biglietto omaggio e i contatti |
| `notes` | – | text | Internal notes | Note interne |

### `sessions.csv`

| Column | R | Format | EN | IT |
|---|---|---|---|---|
| `code` | **R** | code | Session key | Chiave della sessione |
| `day` | **R** | `YYYY-MM-DD` | Day, within the event dates (8–10 April 2027) | Giorno, dentro le date dell'evento (8–10 aprile 2027) |
| `start`, `end` | **R** | `HH:MM` | Lisbon time; `end` after `start` | Ora di Lisbona; `end` dopo `start` |
| `room_code` | **R** | code | A `code` from `rooms.csv` | Un `code` di `rooms.csv` |
| `track` | – | text | Thematic track; new tracks are created after confirmation | Percorso tematico; i percorsi nuovi si creano dopo conferma |
| `format` | **R** | one word | `plenary`, `keynote`, `lecture`, `panel`, `workshop`, `symposium`, `course`, `break`, `social` | Come a sinistra |
| `title_en`, `title_it` | **R** / – | text | Title | Titolo |
| `abstract_en`, `abstract_it` | – | text | Abstract, plain text | Abstract, testo semplice |
| `speaker_codes` | – | codes with `\|` | Speakers, in order | Speaker, in ordine |
| `moderator_codes` | – | codes with `\|` | Chairs / moderators | Moderatori |
| `paid` | **R** | `yes` / `no` | `yes` = sold separately (workshops); `no` = included in the pass | `yes` = si compra a parte (workshop); `no` = compresa nel pass |
| `seats` | if `paid=yes` | whole number | Places for sale (20 for the workshops); with `paid=no`, places to book for free (empty = no booking) | Posti in vendita (20 per i workshop); con `paid=no`, posti da prenotare gratis (vuoto = nessuna prenotazione) |
| `price_eur` | – | amount | Only if different from the workshop price in `ticket_prices.csv` (100.00) | Solo se diverso dal prezzo dei workshop in `ticket_prices.csv` (100,00) |
| `notes` | – | text | Internal notes | Note interne |

### `session_parts.csv`

Talks or segments inside one session (e.g. a symposium with three speakers). · Interventi o parti dentro una sessione
(es. un simposio con tre relatori).

| Column | R | Format | EN | IT |
|---|---|---|---|---|
| `session_code` | **R** | code | A `code` from `sessions.csv` | Un `code` di `sessions.csv` |
| `position` | **R** | whole number | Order inside the session: 1, 2, 3… (unique per session) | Ordine dentro la sessione: 1, 2, 3… (unico per sessione) |
| `start`, `end` | – | `HH:MM` | Within the session times | Dentro gli orari della sessione |
| `title_en`, `title_it` | **R** / – | text | Title of the talk | Titolo dell'intervento |
| `abstract_en`, `abstract_it` | – | text | Abstract | Abstract |
| `speaker_codes` | – | codes with `\|` | Speakers of this part | Relatori di questa parte |
| `notes` | – | text | Internal notes | Note interne |

### `ticket_types.csv`

Prefilled with the four known types; workshops are not listed here: each row of `sessions.csv` with `paid=yes`
becomes its own ticket. · Già compilato con i quattro tipi noti; i workshop non stanno qui: ogni riga di
`sessions.csv` con `paid=yes` diventa un biglietto a sé.

| Column | R | Format | EN | IT |
|---|---|---|---|---|
| `code` | **R** | code | Type key, used in `ticket_prices.csv` | Chiave del tipo, usata in `ticket_prices.csv` |
| `kind` | **R** | one word | `full_pass`, `companion`, `exhibitor`, `staff_pass` (`day_pass` if day tickets are ever sold) | Come a sinistra (`day_pass` se un giorno si vendono biglietti giornalieri) |
| `channel` | **R** | one word | `public` on sale on the site; `invite` only with an invitation code; `panel` issued by the staff, never sold | `public` in vendita sul sito; `invite` solo con codice d'invito; `panel` emesso dallo staff, mai in vendita |
| `name_en`, `name_it` | **R** / – | text | Name shown on the site and on the ticket | Nome mostrato sul sito e sul biglietto |
| `description_en`, `description_it` | – | text | What the ticket includes | Cosa comprende il biglietto |
| `max_per_order` | – | whole number | Maximum per order (empty = 4) | Massimo per ordine (vuoto = 4) |
| `max_per_host` | `companion` only | 1–5 | Companions per pass | Accompagnatori per pass |
| `sales_start`, `sales_end` | – | date and time | Own sale window, inside the event's one (empty = the event's) | Finestra di vendita propria, dentro quella dell'evento (vuoto = quella dell'evento) |
| `active` | **R** | `yes` / `no` | `no` = prepared but not on sale | `no` = preparato ma non in vendita |
| `position` | – | whole number | Order on the tickets page | Ordine nella pagina dei biglietti |
| `notes` | – | text | Internal notes | Note interne |

### `price_tiers.csv`

| Column | R | Format | EN | IT |
|---|---|---|---|---|
| `code` | **R** | one word | `early_bird`, `regular`, `last_minute` | Come a sinistra |
| `name_en`, `name_it` | **R** / – | text | Name shown on the site | Nome mostrato sul sito |
| `starts_at`, `ends_at` | **R** | date and time | Start included, end excluded, Lisbon time. Tiers **must not overlap**; they can touch (one ends when the next starts) | Inizio compreso, fine esclusa, ora di Lisbona. Le fasce **non si sovrappongono**; possono toccarsi (una finisce quando comincia la successiva) |
| `position` | – | whole number | Order | Ordine |
| `notes` | – | text | Internal notes | Note interne |

Dates are still to be decided: they can also be changed later from the panel. · Le date sono da decidere: si possono
anche cambiare dopo dal pannello.

### `ticket_prices.csv`

One row per price. · Una riga per prezzo.

| Column | R | Format | EN | IT |
|---|---|---|---|---|
| `ticket_type_code` | **R** | code | A `code` from `ticket_types.csv`, or `workshop` for all the workshops | Un `code` di `ticket_types.csv`, oppure `workshop` per tutti i workshop |
| `tier_code` | – | code | A tier from `price_tiers.csv`; **empty = base price**, valid in every tier that has no price of its own | Una fascia di `price_tiers.csv`; **vuoto = prezzo base**, valido in ogni fascia senza un prezzo suo |
| `role` | **R** | `public` / `member` | Who pays this price; with no `member` row, members pay the public price | Chi paga questo prezzo; senza riga `member`, i membri pagano il prezzo pubblico |
| `amount_eur` | **R** | amount | Price per ticket, in euro | Prezzo per biglietto, in euro |
| `notes` | – | text | Internal notes | Note interne |

Prefilled · Già compilati: full pass 695.00 public and 395.00 member (base); early bird **members only** 276.50 (no
public early bird row: the public pays 695.00); workshops 100.00 for everyone, no member price. Empty amounts (last
minute, companion, exhibitor) are still to be decided and are skipped by the import. · Pass intero 695,00 pubblico e
395,00 membro (base); early bird **solo membri** 276,50 (nessuna riga early bird pubblica: il pubblico paga 695,00);
workshop 100,00 per tutti, senza prezzo membro. Gli importi vuoti (last minute, accompagnatore, espositore) sono da
decidere e l'import li salta.

---

## 3. Photos · Foto

| | EN | IT |
|---|---|---|
| Shape · Forma | Square (1:1), face centred with some space around it, preferably on a plain background | Quadrata (1:1), viso al centro con un po' di spazio intorno, meglio su sfondo semplice |
| Size · Dimensioni | At least 800 × 800 pixels, ideally 1200 × 1200; at most 5 MB | Almeno 800 × 800 pixel, meglio 1200 × 1200; al massimo 5 MB |
| Format · Formato | JPG (or PNG) | JPG (o PNG) |
| File name · Nome del file | The speaker's `code` in lowercase plus the extension: `spk-001.jpg`. No spaces, no accents. Write the same name in `photo_file` | Il `code` dello speaker in minuscolo più l'estensione: `spk-001.jpg`. Niente spazi né accenti. Lo stesso nome va in `photo_file` |
| Sending · Invio | All photos in one folder, sent as a ZIP file or shared folder together with `speakers.csv` | Tutte le foto in una cartella, mandata come ZIP o cartella condivisa insieme a `speakers.csv` |
| What the site does · Cosa fa il sito | Resizes the photo and removes hidden data (camera, GPS position) | Ridimensiona la foto e toglie i dati nascosti (fotocamera, posizione GPS) |

Use photos supplied by the speakers themselves. · Usate foto fornite dagli speaker stessi.

---

## 4. Filling in the files · Come compilarli

### Google Sheets (recommended · consigliato)

**EN**
1. *File → Import → Upload*, choose the CSV, *Import location: Replace spreadsheet*, *Separator type: Detect
   automatically*.
2. Select the date, time and code columns and choose *Format → Number → Plain text*, so that `2027-04-08` and `09:00`
   stay as they are.
3. A line break inside a cell: *Ctrl+Enter* (*⌘+Enter* on Mac).
4. *File → Download → Comma-separated values (.csv)*. It saves only the open sheet: one CSV per file, same file name.

**IT**
1. *File → Importa → Carica*, scegli il CSV, *Posizione di importazione: Sostituisci foglio di lavoro*, *Tipo di
   separatore: Rileva automaticamente*.
2. Seleziona le colonne di date, orari e codici e scegli *Formato → Numero → Testo normale*, così `2027-04-08` e
   `09:00` restano come sono.
3. Andare a capo dentro una cella: *Ctrl+Invio* (*⌘+Invio* su Mac).
4. *File → Scarica → Valori separati da virgola (.csv)*. Salva solo il foglio aperto: un CSV per file, con lo stesso
   nome.

### Excel

**EN**
1. Open with *Data → From Text/CSV*: *File origin 65001: Unicode (UTF-8)*, *Delimiter: Comma*, then *Transform Data*
   and set the date, time and code columns to *Text* before *Close & Load*. (Double-clicking the file also works, but
   Excel may turn `2027-04-08` into `08/04/2027` and `09:00` into a number.)
2. A line break inside a cell: *Alt+Enter* (*Ctrl+Option+Enter* on Mac).
3. *File → Save As → CSV UTF-8 (Comma delimited) (\*.csv)*.
4. Excel set up for Italy or Portugal saves with **semicolons** and decimal **commas** (`276,50`): that is fine too,
   the import accepts both.
5. Do not merge cells, add title rows above the headers or rename the headers. Colours and column widths are lost
   when saving: that is normal.

**IT**
1. Apri con *Dati → Da testo/CSV*: *Origine file 65001: Unicode (UTF-8)*, *Delimitatore: Virgola*, poi *Trasforma
   dati* e imposta le colonne di date, orari e codici come *Testo* prima di *Chiudi e carica*. (Anche il doppio clic
   funziona, ma Excel può trasformare `2027-04-08` in `08/04/2027` e `09:00` in un numero.)
2. Andare a capo dentro una cella: *Alt+Invio* (*Ctrl+Opzione+Invio* su Mac).
3. *File → Salva con nome → CSV UTF-8 (delimitato da virgole) (\*.csv)*.
4. Excel impostato per l'Italia o il Portogallo salva con il **punto e virgola** e i decimali con la **virgola**
   (`276,50`): va bene lo stesso, l'import accetta entrambi.
5. Non unire celle, non aggiungere righe di titolo sopra le intestazioni e non rinominarle. Colori e larghezze delle
   colonne si perdono al salvataggio: è normale.

---

## 5. Notes for the developers · Note per gli sviluppatori

Consistent with `db/modello-dati.md` §3.2–§3.3. Import rules to implement (proposal, `docs/architettura.md` §13.4):
headers matched by name; UTF-8 with or without BOM; delimiter `,` or `;` detected from the header row, and with `;`
decimal comma accepted; rows with `code` (or `session_code`) starting with `EXAMPLE-` skipped; empty `amount_eur`
skipped; `|` as list separator; `paid=yes` → `program_sessions.access = 'paid'` plus a `paid_session` ticket type, a
capacity pool of `seats` and a base public price (`price_eur` or the `workshop` row); `paid=no` with `seats` →
`included_booking`; times converted from `Europe/Lisbon` to UTC; `rooms.floor`, `speakers.honorific`,
`first_name`, `last_name`, `affiliation`, `country` and a private `email` are columns of the model.
