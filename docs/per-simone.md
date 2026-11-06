# EAS8 Lisbona: a che punto siamo

Autore: Giulio Occhipinti. Aggiornato al 06/11/2026.

Ciao Simone, qui trovi in un solo posto cosa è pronto, cosa ti chiediamo adesso e le poche decisioni che restano.
Grazie per le risposte ai due moduli: le abbiamo usate tutte.

## In breve

- L'architettura del sito (Laravel + Filament sul vostro hosting, MySQL/MariaDB, Stripe) e il prototipo cliccabile
  sono pronti.
- Le regole più delicate (posti, prezzo membro, pagamenti confermati due volte) sono state provate su MySQL 8 e
  MariaDB 10.11.
- **La vendita dei biglietti non può aprire subito:** la prima fase richiede circa 12–16 settimane di lavoro di uno
  sviluppatore. L'apertura realistica è **tra metà gennaio e marzo 2027**, a seconda che lavorino uno o due
  sviluppatori.
- **Nel frattempo** può andare online in pochi giorni una **pagina di preregistrazione** che raccoglie le email nel
  vostro Mailchimp: all'apertura scrivete a chi si è iscritto, e ai membri per l'early bird riservato.

## Cosa è pronto

| Cosa | Dove | A cosa serve |
|---|---|---|
| Prototipo cliccabile del sito, in inglese | `prototipo/` (apri `index.html`) | Vedere pagine, prezzi per ruolo e fasce, area personale e pannello admin prima di sviluppare |
| Pagina di preregistrazione | `preregistrazione/` | Da mettere online subito, collegata a Mailchimp, senza server nostro |
| Script di verifica dell'hosting | `strumenti/verifica-hosting/` | Ci dice da solo se il vostro hosting ha tutto quello che serve |
| Modelli per i contenuti | `contenuti/modelli/` | File da compilare in Excel o Google Sheets con sale, speaker, sessioni, biglietti e prezzi |
| Tre proposte di layout alternative (home e biglietti) | `proposte/` (leggi `proposte/README.md`) | Da mostrare al tuo capo: tre direzioni visive diverse dallo stile attuale |
| Architettura e modello dei dati | `docs/architettura.md`, `db/modello-dati.md` | Per chi svilupperà: componenti, regole, piano di lavoro |

Per aprire il prototipo sul tuo computer: `cd prototipo && python3 -m http.server 8000`, poi
`http://localhost:8000`. In alto c'è il selettore «View as» per vedere i prezzi da visitatore, utente o membro.

## Cosa ti chiediamo adesso (4 azioni)

| # | Azione | Istruzioni | Tempo stimato |
|---|---|---|---|
| 1 | Lanciare lo script di verifica sull'hosting via SSH e mandarci il riepilogo che stampa | `strumenti/verifica-hosting/README.md` | 10 minuti |
| 2 | Mandarci l'indirizzo del modulo Mailchimp per la preregistrazione (*Audience → Signup forms → Embedded forms*, l'indirizzo dopo `action=`) e dirci se la doppia conferma è attiva. **Non mandare la chiave API** | `preregistrazione/README.md` | 10 minuti |
| 3 | Comprare `orthocongress.org` e dirci chi gestisce i DNS | — | Quando potete: senza dominio niente pagina online né email affidabili |
| 4 | Iniziare a raccogliere i contenuti nei modelli: prima tipi di biglietto, fasce e prezzi, poi sale, speaker e sessioni | `contenuti/modelli/README.md` | Il programma serve più avanti, i prezzi subito |

Se lo script segnala un problema con i **trigger** del database, stampa anche un breve testo in inglese da girare
all'assistenza dell'hosting: di solito lo risolvono in poco tempo.

## Decisioni che restano

Se una risposta manca, vale la proposta della terza colonna e si può cambiare dopo.

| # | Domanda | Proposta se non rispondi |
|---|---|---|
| 5 | Data di apertura delle vendite e quanti sviluppatori lavorano | Uno sviluppatore, apertura tra metà febbraio e marzo 2027, preregistrazione online prima |
| 6 | Date di early bird e regular (dopo aver scelto la data di apertura) | Prezzi base 695 € pubblico e 395 € membro finché non ci sono date; early bird −30% solo membri (276,50 €) |
| 7 | Prezzo e date del last minute | Nessun last minute: dopo il regular valgono i prezzi base |
| 8 | Workshop: solo con il pass intero? Quanti per ordine? Due alla stessa ora? | Solo con il pass intero, massimo 2 per ordine, mai due alla stessa ora |
| 9 | Accompagnatori ed espositori: prezzo, cosa comprendono, quanti per pass, codice d'invito per le aziende | Fuori dall'apertura delle vendite, arrivano nella seconda fase |
| 10 | Biglietti omaggio per speaker e staff servono già all'apertura? Circa quanti? | Seconda fase, comunque prima dell'evento |
| 11 | Partner del check-in: chi è, contatto tecnico, legge il nostro QR o vuole un formato suo? | QR firmato nostro più un file CSV dei biglietti validi |
| 12 | Video: chi vede cosa? Wistia e Vimeo permettono di limitarli al vostro dominio? | Archivio dei congressi passati ai membri, video dell'EAS8 ai partecipanti |
| 13 | Testi della pagina di preregistrazione (inglese, ed eventualmente italiano), email di contatto pubblica, link alla vostra privacy, logo ad alta risoluzione | Usiamo la bozza già pronta con i testi di eas-aligners.com, da approvare |

Le domande sono spiegate una per una, con il motivo, in `docs/domande-simone-terzo-giro.md`.

## Già deciso

| Tema | Decisione |
|---|---|
| Sito | Nuovo, su orthocongress.org, separato da eas-aligners.com; si parte senza account esistenti |
| Tecnica | Laravel + Filament sul vostro hosting condiviso con SSH, MySQL 8 o MariaDB 10.7 e successive, dati in UE |
| Pannello | Tre ruoli: admin; commerciale (pagamenti, ordini, codici sconto); organizzatore (evento, date, programma, biglietti, fasce, prezzi, codici sconto) |
| Evento | EAS8, Lisbona, 8–10 aprile 2027, 6.000 posti; date modificabili dal pannello |
| Prezzi | Pass 695 € pubblico, 395 € membro; early bird −30% solo per i membri; workshop 100 € per tutti (10 da 20 posti); membership 200 € l'anno, attiva subito |
| Pagamenti | Solo carta tramite Stripe (Apple Pay e Google Pay inclusi), account in Francia, in euro |
| Biglietti | Nominativi; il nome non si cambia, l'admin può solo correggere un refuso |
| Email e newsletter | Casella SMTP esistente; Mailchimp con consenso esplicito |
| Lingua | Inglese principale, italiano come seconda lingua |
| Aspetto | Coerente con eas-aligners.com (logo, colori, font Inter) |

## Cosa non è compreso adesso

- **Aspetti legali e fiscali** (IVA, fatture, condizioni di vendita, privacy): restano a voi e al vostro
  commercialista. I segnaposto nei testi sono marcati.
- **Seconda fase:** programma completo con 12 sale, speaker, video per i membri, sincronizzazione con Mailchimp,
  accompagnatori ed espositori, invio di abstract e poster, attestati e crediti formativi.

## Prossimi passi

1. Tu: le 4 azioni sopra, appena puoi.
2. Noi: con l'indirizzo di Mailchimp mettiamo a punto la pagina di preregistrazione; con il riepilogo dello script
   confermiamo l'hosting.
3. Insieme: data di apertura e date delle fasce, poi si parte con lo sviluppo della prima fase.
