# EAS8 Lisbona: a che punto siamo

Autore: Giulio Occhipinti. Aggiornato al 06/11/2026 (Fase 1 sviluppata).

Ciao Simone, qui trovi in un solo posto cosa è pronto, cosa ti chiediamo adesso e le poche decisioni che restano.
Grazie per le risposte ai due moduli: le abbiamo usate tutte.

## In breve

- **La Fase 1 è sviluppata:** applicazione Laravel 13 + Filament 5 in `sito/`, con vendita dei biglietti su Stripe,
  membership, biglietti con QR via email, pannello `/admin` a tre ruoli ed export CSV. 189 test automatici verdi su
  MariaDB.
- **Non è ancora online:** mancano la prova con le vostre chiavi Stripe in modalità test, l'installazione sul vostro
  hosting, il dominio e i contenuti definitivi (date delle fasce, testi).
- Il prototipo e le proposte di layout restano pagine HTML statiche: servono a scegliere l'aspetto, non sono il
  sito.
- **Nel frattempo** può andare online la **pagina di preregistrazione** collegata al vostro Mailchimp.

## Cosa è pronto

| Cosa | Dove | A cosa serve |
|---|---|---|
| **Applicazione Laravel della Fase 1** | `sito/` (leggi `sito/README.md`) | Il sito vero: vendita, pagamenti, biglietti, pannello, export |
| Prototipo cliccabile del sito, in inglese | `prototipo/` (apri `index.html`) | Vedere pagine, prezzi per ruolo e fasce, area personale e pannello admin prima di sviluppare |
| Pagina di preregistrazione | `preregistrazione/` | Da mettere online subito, collegata a Mailchimp, senza server nostro |
| Script di verifica dell'hosting | `strumenti/verifica-hosting/` | Ci dice da solo se il vostro hosting ha tutto quello che serve |
| Modelli per i contenuti | `contenuti/modelli/` | File da compilare in Excel o Google Sheets con sale, speaker, sessioni, biglietti e prezzi |
| Tre proposte di layout alternative (home e biglietti) | `proposte/` (leggi `proposte/README.md`) | Da mostrare al tuo capo: tre direzioni visive diverse dallo stile attuale |
| Architettura e modello dei dati | `docs/architettura.md`, `db/modello-dati.md` | Per chi svilupperà: componenti, regole, piano di lavoro |

Per aprire il prototipo sul tuo computer: `cd prototipo && python3 -m http.server 8000`, poi
`http://localhost:8000`. In alto c'è il selettore «View as» per vedere i prezzi da visitatore, utente o membro.

## Per te come sviluppatore

Istruzioni complete in `sito/README.md`. In breve:

```sh
cd sito
composer install
cp .env.example .env && php artisan key:generate   # poi DB MySQL/MariaDB, Stripe test, SMTP, chiavi QR
php artisan migrate:fresh --seed                    # dati EAS8 di esempio
php artisan eas:create-admin                        # primo utente del pannello, con MFA
php artisan test                                    # 189 test, su MySQL/MariaDB (non SQLite)
php artisan serve                                   # sito su http://localhost:8000, pannello su /admin
stripe listen --forward-to localhost:8000/stripe/webhook
```

| Dove guardare | Cosa c'è |
|---|---|
| `app/Services/` | Prezzi e fasce, riserve di posti, pagamenti, rimborsi, biglietti e QR, omaggi |
| `app/Services/StripeGateway.php` | L'unico punto che parla con Stripe (nei test lo sostituisce `tests/Fakes/FakeStripe.php`) |
| `app/Jobs/`, `routes/console.php` | Webhook elaborati in coda, scadenza degli ordini, rimborsi; pensati per cron ogni minuto su hosting condiviso |
| `app/Filament/`, `app/Policies/` | Pannello e permessi per ruolo |
| `database/migrations/` | Tabelle con i vincoli che proteggono posti e prezzo membro |
| `tests/` | Test Pest, compresi quelli in parallelo in `tests/Concurrency/` |

Scelte da confermare: QR firmato con Ed25519 (il partner verifica con la chiave pubblica, senza segreto); date delle
fasce di esempio (early bird fino al 31/01/2027, regular fino al 31/03/2027); lo staff entra solo dal pannello con
password e MFA, non con il codice via email dei clienti.

Prima del lancio mancano: prova completa con Stripe in modalità test (anche Apple Pay e Google Pay), gestione del
rimborso rifiutato da Stripe, riconciliazione notturna, contestazioni, lingua nell'indirizzo (`/en/`, `/it/`).

## Cosa ti chiediamo adesso (5 azioni)

| # | Azione | Istruzioni | Tempo stimato |
|---|---|---|---|
| 1 | Lanciare lo script di verifica sull'hosting via SSH e mandarci il riepilogo che stampa | `strumenti/verifica-hosting/README.md` | 10 minuti |
| 2 | Mandarci l'indirizzo del modulo Mailchimp per la preregistrazione (*Audience → Signup forms → Embedded forms*, l'indirizzo dopo `action=`) e dirci se la doppia conferma è attiva. **Non mandare la chiave API** | `preregistrazione/README.md` | 10 minuti |
| 3 | Comprare `orthocongress.org` e dirci chi gestisce i DNS | — | Quando potete: senza dominio niente pagina online né email affidabili |
| 5 | Provare l'applicazione in locale con le chiavi Stripe **di test** del vostro account e dirci cosa non va | `sito/README.md` | Mezza giornata |
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

1. Tu: le 5 azioni sopra, appena puoi.
2. Noi: con il riepilogo dello script prepariamo l'installazione sul vostro hosting; con l'indirizzo di Mailchimp
   mettiamo online la preregistrazione.
3. Insieme: data di apertura e date delle fasce, prova con Stripe in test, poi apertura delle vendite e Fase 2.
