# Registro delle decisioni

Autore: Giulio Occhipinti. Aggiornato al 05/11/2026.

Le righe senza nota sono decisioni di Giulio (dal brief). Le righe «proposta, da confermare» sono proposte
dell'architettura (`docs/architettura.md`) in attesa del suo via libera.

| Data | Decisione | Motivo |
|---|---|---|
| 05/11/2026 | Un solo organizzatore e un solo account Stripe, niente Stripe Connect per ora | Decisione di Giulio: semplicità per la prima versione |
| 05/11/2026 | Membership venduta sul sito come abbonamento Stripe; dà il prezzo «membro» su biglietti e sessioni | Decisione di Giulio |
| 05/11/2026 | Codici sconto oltre ai prezzi per ruolo | Decisione di Giulio |
| 05/11/2026 | Si vendono pass all'evento intero, pass giornaliero e sessioni a pagamento a parte | Decisione di Giulio |
| 05/11/2026 | Studenti da verificare (email universitaria o documento approvato a mano) | Decisione di Giulio, con la proposta indicata nel brief |
| 05/11/2026 | Questa fase produce solo architettura, schema dati e UX/UI, niente codice applicativo | Decisione di Giulio (brief, «Fuori ambito») |
| 05/11/2026 | Stack: Next.js App Router su Vercel `fra1`, Supabase Postgres, Auth e Storage in `eu-central-1` (proposta, da confermare) | Stesso stack di Eventy, suggerito nel brief; nessun motivo forte per cambiarlo; dati in UE |
| 05/11/2026 | Stripe Checkout ospitato per biglietti (`payment`) e membership (`subscription`), Customer Portal per la membership (proposta, da confermare) | Meno codice, nessun dato di carta e nessuna chiave Stripe nel browser, SCA e wallet già gestiti |
| 05/11/2026 | Prezzi dei biglietti passati con `price_data.product_data`, importo per unità già scontato e sconto scritto nel nome della riga; Price vero solo per la membership (proposta, da confermare; sostituisce la prima bozza con un Product per tipo) | Una sola sorgente dei prezzi (il database); sconto calcolabile riga per riga |
| 05/11/2026 | Membro e studente sono stati derivati, non ruoli salvati; ruoli salvati solo admin e staff check-in (proposta, da confermare) | Nessun ruolo da tenere allineato a mano; il prezzo si decide sempre lato server dai dati reali |
| 05/11/2026 | Il ruolo di prezzo deve valere fino alla scadenza della Checkout Session e viene «fotografato» nell'ordine (proposta, da confermare) | Evita il prezzo membro a chi scade durante il pagamento; il prezzo non cambia dopo il redirect |
| 05/11/2026 | Prezzo di ruolo solo per il biglietto intestato all'acquirente; gli accompagnatori a prezzo pubblico (proposta, da confermare) | Impedisce a un membro di comprare molti biglietti scontati per altri |
| 05/11/2026 | Chi è sia membro sia studente paga il minore dei due prezzi (proposta, da confermare) | Regola semplice e a favore del cliente |
| 05/11/2026 | Codici sconto calcolati solo lato server, nessun Coupon o Promotion Code Stripe per i biglietti; utilizzi contati con `UPDATE ... WHERE redeemed_count < max_redemptions` nella transazione della riserva (proposta, da confermare; sostituisce la prima bozza con Coupon speculari) | Checkout accetta un solo coupon per sessione e non distingue righe dello stesso prodotto: la regola riga per riga non si esprime con i Coupon |
| 05/11/2026 | Cumulo: riga per riga si applica il migliore tra prezzo di ruolo e prezzo pubblico scontato, mai entrambi; cumulo solo per codici marcati (proposta, da confermare) | Evita sconti doppi non voluti senza penalizzare chi ha un codice |
| 05/11/2026 | Capienza con pool di posti e vincolo `held + confirmed <= capacity` nel database; riserva di almeno 30 minuti (minimo Stripe) + 5 di margine (proposta, da confermare) | Overselling impossibile anche con un bug nel codice |
| 05/11/2026 | Correzione di un bug della prima bozza: le riserve scadute si liberano solo dopo che Stripe conferma la scadenza (webhook `expired` o job che chiama `expire`); niente rilascio cieco da `pg_cron` (proposta, da confermare) | Il rilascio cieco, con un webhook di pagamento in ritardo, emetteva biglietti senza contare i posti |
| 05/11/2026 | La conferma del pagamento riprenota i posti se la riserva non c'è più; se sono esauriti, ordine `needs_refund`, nessun biglietto, rimborso automatico e avviso all'admin (proposta, da confermare) | I soldi presi non si ignorano e un posto non si vende due volte; provato con dati finti |
| 05/11/2026 | Conferma idempotente: lock `SELECT ... FOR UPDATE` sull'ordine, transizioni ammesse solo da `pending` (o `processing` per i pagamenti asincroni), `unique (order_item_id, seq)` sui biglietti (proposta, da confermare) | Pagina di successo e consegne parallele del webhook non emettono biglietti doppi; provato con due sessioni parallele |
| 05/11/2026 | Una sola riserva aperta per persona; `expire` della sessione precedente quando si torna al carrello; limite per ordine anche per pool (es. 2 sui workshop) (proposta, da confermare) | Contro l'accaparramento di posti |
| 05/11/2026 | Per i biglietti esclusi i metodi di pagamento a notifica differita (proposta, da confermare) | Evita riserve di posti aperte per giorni |
| 05/11/2026 | Idempotenza dei webhook con tabella `stripe_events` (id evento come chiave) ed elaborazione in una funzione SQL unica (proposta, da confermare) | Reinvii innocui; supabase-js non apre transazioni su più chiamate |
| 05/11/2026 | Rimborsi dal pannello admin a regime; nella prima versione dalla Dashboard Stripe, registrati dal webhook, parziali nella coda «da riconciliare» (proposta, da confermare) | Il pannello collega il rimborso ai biglietti giusti, ma non serve per partire |
| 05/11/2026 | Prima versione (MVP): restano capienza nel database, conferma idempotente, membership, prezzo di ruolo; si tagliano PWA offline (check-in online + CSV), lista d'attesa, API pubblica, upload documenti studenti, cambio nominativo self-service, pannello rimborsi (proposta, da confermare) | Vendere il primo evento senza perdere soldi né posti, con meno codice |
| 05/11/2026 | Un biglietto nominativo per partecipante; QR con firma Ed25519, chiave privata solo sul server (proposta, da confermare) | QR non indovinabile né falsificabile; verificabile offline con la sola chiave pubblica |
| 05/11/2026 | Check-in come PWA offline-tollerante con sincronizzazione e un solo ingresso accettato per biglietto, giorno e varco (proposta, da confermare) | Funziona anche senza rete nel luogo dell'evento; niente app negli store per ora |
| 05/11/2026 | Cambio nominativo entro una scadenza per evento, massimo 2 volte, biglietti a prezzo di ruolo non cedibili (proposta, da confermare) | Evita il mercato secondario dei biglietti scontati |
| 05/11/2026 | Documenti studenti in bucket privato, URL firmati di 60 secondi, file cancellato entro 24 ore dall'esito (proposta, da confermare) | Minimizzazione dei dati personali |
| 05/11/2026 | Accesso con codice via email (nessuna password salvata) e TOTP obbligatorio per gli admin; cookie di sessione `httpOnly` lato server (proposta, da confermare) | Rispetta la regola sulle aree riservate senza gestire password; Supabase salverebbe le password con bcrypt, non scrypt o argon2 |
| 05/11/2026 | Lavori pesanti in una coda `jobs` in Postgres, eseguita da Vercel Cron e `pg_cron` (proposta, da confermare) | Fuori dalle richieste degli utenti, senza fornitori in più |
| 05/11/2026 | Email transazionali con Resend, anche come SMTP di Supabase Auth (proposta, da confermare) | Piano gratuito per partire, API semplice |
| 05/11/2026 | Ambienti separati: Stripe test e Supabase staging per Preview e locale, live solo in Production (proposta, da confermare) | Nessun pagamento vero nei test; il webhook rifiuta la modalità sbagliata |
| 05/11/2026 | Stripe Tax, IVA, fattura elettronica: non decisi | Domanda fiscale aperta, da passare alla catena legale-fiscale |
| 05/11/2026 | Su misura, pretix o soluzione mista (agenda, speaker e membership su misura, biglietteria su pretix): **aperta, decide Giulio** dopo una prova di 1-2 giorni | Pretix ha già membership che sbloccano prodotti, check-in offline e QR firmati; da capire se gestisce una membership in abbonamento Stripe ricorrente |
