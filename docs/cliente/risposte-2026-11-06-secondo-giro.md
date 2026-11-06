# Risposte del cliente, secondo giro (06/11/2026)

Autore: Giulio Occhipinti. Risposte di Simone al modulo «Dettagli tecnici EAS8», inviate il 06/11/2026 alle 12:35 UTC.

| Tema | Risposta |
|---|---|
| Server | Hosting condiviso con accesso SSH, database MySQL o MariaDB; lo gestisce Simone |
| Stripe | Account in Francia, incasso in euro |
| Evento | EAS8, 8–10 aprile 2027; le date devono essere modificabili dal pannello |
| Fasce di prezzo | Early bird, regular e last minute, con date modificabili dal pannello |
| Early bird (−30%) | Solo per i membri |
| Workshop | 10 workshop da 20 posti; nessun prezzo membro (100 € per tutti) |
| Email | Casella SMTP esistente, circa 1.000 email l'ora |
| Newsletter | Mailchimp, account già attivo |
| Check-in | Il partner legge il QR |
| Video | Su Wistia e Vimeo, associati a ruoli diversi dal pannello; ore non note |
| Codici sconto | Li creano sia il commerciale sia l'organizzatore |
| Lingua predefinita | Inglese |
| Scelte di default | Membership attiva subito dopo l'acquisto; **nessun cambio di nome** sui biglietti; solo carta tramite Stripe |
| Apertura vendite | «Subito» |
| Sito | Nuovo e separato, su orthocongress.org (dominio da comprare); eas-aligners.com solo come esempio di colori e testi |
| Account esistenti | Si riparte da zero, nessuna importazione |
| Altri biglietti | Accompagnatori, omaggi per speaker e staff, espositori e sponsor |
| Bonifico | No, solo carta |
| Funzioni da congresso | Abstract e poster, attestato di partecipazione, crediti formativi; nessuna serve all'apertura delle vendite |
| Programma | 12 sale in parallelo, circa 120 sessioni, circa 30 speaker; contenuti sparsi tra email e documenti |

## Cosa cambia

- 🔴 «Vendite subito» con dominio da comprare e contenuti da raccogliere: la prima versione va tagliata al minimo.
- 🟡 Hosting condiviso con MySQL: code e job con cron invece di un processo sempre attivo; il ramo MySQL dello schema non è ancora provato.
- 🟡 Prezzi: fasce a tempo (early bird, regular, last minute) incrociate con il ruolo; l'early bird esiste solo per i membri; prezzo last minute da chiedere.
- 🟡 Nessun cambio di nome: va tolta la regola delle 48 ore decisa il 05/11/2026.
- 🟡 Nuovi tipi di biglietto: accompagnatori, omaggi (emessi dal pannello, senza pagamento), espositori e sponsor.
- 🟡 Video su Wistia e Vimeo con accesso per ruolo, al posto di Bunny Stream.
