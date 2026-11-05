<!--
  Autore: Giulio Occhipinti
-->
# Architettura delle informazioni e UX

Autore: Giulio Occhipinti. Fase: progettazione. Aggiornato al 05/11/2026.
Riferimenti: `docs/brief.md` (decisioni di Giulio), prototipo cliccabile in `prototipo/` (apri `index.html`).

Nome evento: **«Convegno 2027»** è un segnaposto. Persone, luoghi, date e prezzi del prototipo sono inventati e
dichiarati tali nell'interfaccia. Quello che manca è scritto «da definire», non riempito.

## 1. Direzione visiva

| Scelta | Perché |
|---|---|
| Programma stampato di un festival: filetti sottili, numeri grandi, raggi quasi squadrati | Evento professionale, non template SaaS |
| Un solo accento (oltremare) che nella home riempie un'intera fascia | Riconoscibile, regge il contrasto AA |
| Titoli a peso e scala, cifre tabellari, font di sistema per ora | Nessun font scaricato finché non si sceglie e si ospita una famiglia (una riga: `--font-display`) |
| Tema chiaro e scuro con `prefers-color-scheme` | Uso in sala con luce bassa e all'aperto |
| Primo schermo della home: nome, tre date in grande, prezzo del pass intero, bottone «Compra i biglietti» in basso | Risposta e azione senza scorrere, nella zona del pollice |
| Evitato: etichette sopra i titoli, numerazione di sezione, card con icona, bordi colorati laterali, ombre dure, emoji come icone | Regole antigenerico della skill `impeccable` |

## 2. Mappa del sito

Legenda: **P** = prototipato in `prototipo/`, **—** = da progettare dopo.

### 2.1 Pubblico (senza accesso)

| Pagina | File | Stato | Scopo |
|---|---|---|---|
| Home evento | `index.html` | P | Date, luogo, prezzo pass, CTA biglietti, highlights, speaker |
| Programma | `agenda.html` | P | Giorni, sale, tracce, filtri, griglia (desktop) o elenco |
| Sessione | `sessione.html?id=` | P | Dettaglio, interventi, speaker, prezzo/inclusa, prenota o aggiungi |
| Speaker (elenco) | `speaker.html` | P | Persone in ordine alfabetico |
| Speaker (scheda) | `speaker-dettaglio.html?id=` | P | Bio, sessioni in cui parla |
| Biglietti | `biglietti.html` | P | Tabella prezzi per ruolo, ordine, codice sconto, come ottenere i prezzi ridotti |
| Membership | `membership.html` | P | Vantaggi, quota, confronto con/senza |
| Esito pagamento | `checkout-esito.html?stato=` | P | Riuscito, in elaborazione, annullato, fallito; simula Stripe |
| Accesso / registrazione | — | — | Email + link o password; nessun test cognitivo |
| Condizioni, privacy, rimborsi, contatti, accessibilità della sede | — | — | Pagine legali: vanno alla catena legale prima di scriverle |

### 2.2 Area personale (con accesso)

| Pagina | Contenuto |
|---|---|
| I miei biglietti | Pass, sessioni a pagamento, codice QR per l'ingresso, stato (valido, rimborsato) |
| Le mie prenotazioni | Sessioni incluse prenotate, annulla, lista d'attesa |
| Il mio programma | Sessioni scelte in ordine di orario, sovrapposizioni segnalate |
| Membership | Stato, scadenza, rinnovo automatico, disdetta, fatture |
| Verifica studente | Stato (nessuna, in attesa, approvata, rifiutata con motivo, scaduta), nuova richiesta |
| Ordini e ricevute | Elenco ordini, ricevute, richiesta di rimborso |
| Profilo | Dati, email, lingua, esigenze di accessibilità, cancellazione account |

### 2.3 Admin (organizzatore)

| Area | Contenuto |
|---|---|
| Evento | Nome, date, luogo, giorni, sale, tracce |
| Programma | Sessioni e interventi, speaker, capienze, incluse o a pagamento, prezzi per ruolo |
| Prodotti e prezzi | Pass intero e giornaliero, membership, prezzi per ruolo, collegamento ai prodotti Stripe |
| Codici sconto | Percentuale o importo, validità, limite d'uso, prodotti a cui si applica |
| Verifiche studenti | Coda da approvare o rifiutare, documento visibile solo qui, motivo del rifiuto |
| Ordini | Elenco, stato, rimborsi (si avviano su Stripe), esportazione |
| Prenotazioni e liste d'attesa | Per sessione, spostamenti manuali |
| Check-in | Lettura del QR da telefono, ricerca per nome, ingresso già usato, elenco entrati |
| Utenti e ruoli | Membri, studenti, staff con permessi |

### 2.4 Navigazione comune

- **Telefono:** barra in basso con quattro voci (Programma, Speaker, Biglietti, Membership), alta 60px; il logotipo in alto porta alla home. Le azioni principali stanno nella metà bassa.
- **Desktop (da 1024px):** la stessa barra diventa il menu in alto, con sottolineatura spessa sulla pagina corrente.
- **Pagina corrente:** `aria-current="page"`, voce in grassetto e barra di 4px: non solo colore.
- **Ruolo visibile:** nell'intestazione compare «Vista: Membro» (nel sito vero: il nome o il ruolo di chi è connesso).
- **Prototipo:** la striscia scura in cima ha il selettore «Vedi come» e la casella «Ho già un pass». Non esiste nel sito vero.

## 3. Come si mostra il prezzo per ruolo

Regola unica: **chi non è connesso vede tutti i prezzi con chi ne ha diritto; chi è connesso vede il suo in evidenza e gli altri in piccolo.** Lo stesso componente (`prezzoHTML` nel prototipo, classi `.prezzi`) serve a home, programma, sessione, biglietti.

| Chi guarda | Cosa vede | Esempio (pass intero) |
|---|---|---|
| Visitatore (non connesso) | Tre righe: Pubblico, Membro «con la membership», Studente «con verifica studente» | 180 € · 120 € · 60 € |
| Utente non membro | «Il tuo prezzo · Pubblico» grande; sotto «Altri prezzi» e link «Come ottenere i prezzi ridotti» | 180 € grande; Membro 120 €, Studente 60 € |
| Membro | «Il tuo prezzo · Membro» grande, prezzo pubblico barrato | 120 € grande, 180 € barrato |
| Studente verificato | «Il tuo prezzo · Studente» grande, pubblico barrato | 60 € grande, 180 € barrato |
| Studente in attesa | Prezzo pubblico grande, riga «il prezzo studente (60 €) si attiva quando la verifica viene approvata» | 180 € grande |
| Ex membro | Prezzo pubblico grande, riga «membership scaduta: rinnova per tornare a 120 €» | 180 € grande |

Regole di contorno:

- Il barrato ha un testo nascosto «Prezzo pubblico» per i lettori di schermo: non si affida alla sola riga barrata.
- Nelle righe di elenco (programma, speaker) la versione compatta: visitatore «Pubblico 45 € · Membro 35 € · Studente 20 €», connesso «Tuo prezzo · Membro 35 € (pubblico 45 €)».
- Nella pagina biglietti la tabella dei prezzi evidenzia la colonna del ruolo con fondo e con un'etichetta «Tu» (non solo colore).
- Le sessioni incluse non hanno prezzo: hanno il badge pieno «Inclusa nel pass» (le a pagamento: badge con solo contorno «A pagamento»).
- La membership si propone solo dove conviene: nel riepilogo compare «risparmio netto» se il risparmio supera la quota, altrimenti «per questo solo ordine non conviene». Mai pressione.
- Ordine con più persone: il prezzo ridotto spetta all'**account** di chi ne ha diritto. Il Partecipante 1 (chi compra) paga il suo prezzo, gli altri il pubblico. Una nota lo dice prima del pagamento. Vedi domanda aperta 1.

## 4. Flussi principali

Convenzione: **Sistema** è ciò che risponde; **Se…** è un ramo di errore o limite (casi estesi nel capitolo 5).

### 4.1 Acquisto del pass da non membro

1. Dalla home «Compra i biglietti» (o voce Biglietti) porta a `biglietti.html`. Il visitatore vede la tabella con i tre prezzi.
2. Sceglie «Pass intero» (o giornaliero, e allora il giorno).
3. Imposta le persone (1-6); per ognuna nome ed email.
4. Facoltativo: sessioni a pagamento, codice sconto.
5. Legge il riepilogo (prezzo pubblico, totale) e spunta condizioni e privacy.
6. «Vai al pagamento su Stripe». **Sistema:** controlla i campi; se mancano, riepilogo errori in alto con link ai campi. Se tutto è a posto, chiede accesso o registrazione (da definire: vedi domanda 4), crea l'ordine, apre Stripe Checkout.
7. Paga su Stripe. Ritorno su `checkout-esito.html`: riuscito, in elaborazione, annullato o fallito.
8. Riuscito: conferma a schermo, email con biglietti nominativi e codice QR, invito a prenotare le sessioni incluse con posti limitati.

### 4.2 Acquisto da membro con prezzo ridotto

1. Il membro accede. L'intestazione dice «Vista: Membro»; la home già mostra 120 € grande con 180 € barrato.
2. Passi 1-5 come sopra: il prezzo membro si applica da solo al Partecipante 1; un avviso spiega perché gli altri pagano il pubblico.
3. Il riepilogo mostra il prezzo membro; nessuna proposta di membership.
4. Pagamento e ritorno come in 4.1.
5. **Se la membership è scaduta:** avviso giallo in cima («la tua membership è scaduta, paghi il prezzo pubblico») con link a «Rinnova». Mai applicare il prezzo membro in silenzio e poi correggerlo al pagamento.
6. **Diventare membro:** `membership.html`, «Diventa membro», abbonamento su Stripe; al ritorno la membership è attiva e i prezzi si aggiornano subito.

### 4.3 Studente che chiede la verifica

1. In `biglietti.html`, sezione «Prezzi ridotti: come ottenerli», colonna Studente: tre passi (accedi, scegli come verificarti, compra dopo l'approvazione).
2. Se non è connesso: avviso «Per chiedere la verifica devi prima accedere».
3. Apre «Chiedi la verifica studente» e sceglie il metodo: **email universitaria** (link di conferma) o **documento** (caricamento, controllo a mano).
4. Invia. **Sistema:** controlla il campo (errore scritto accanto al campo), poi mostra «Richiesta in attesa. Ti scriviamo appena è controllata»; il ruolo diventa «studente in attesa».
5. Nell'attesa può comprare al prezzo pubblico; il prezzo studente compare dove serve, con la riga che spiega quando si attiva.
6. Esito per email e nell'area personale: approvata (prezzo studente attivo), rifiutata (motivo scritto, può riprovare), scaduta (da definire la durata).
7. Admin: coda di verifiche con approva/rifiuta e motivo; il documento si vede solo lì e si cancella dopo l'esito (regola da confermare con la consulenza privacy).

### 4.4 Acquisto di una sessione a pagamento aggiunta al pass

1. Da programma o da `sessione.html` il pannello a destra (su telefono: subito sotto il titolo) mostra il prezzo per ruolo e i posti.
2. **Con pass:** bottone «Aggiungi al mio pass». **Senza pass:** «Acquista questa sessione» (si può comprare anche senza pass: vedi domanda 3).
3. `biglietti.html?sessione=…&modo=aggiunta`: la sessione è già spuntata, «Solo sessioni a pagamento» già scelto, avviso «Stai aggiungendo … al tuo pass».
4. Può aggiungere altre sessioni. **Se due si sovrappongono:** avviso giallo che nomina le due sessioni; si può comprare lo stesso.
5. Nome ed email, codice sconto opzionale, riepilogo, Stripe.
6. **Se i posti finiscono mentre decide:** la sessione compare disattivata con «Posti esauriti»; se finiscono durante il pagamento si decide la regola (domanda 6).

### 4.5 Uso di un codice sconto

1. Campo «Hai un codice?» con aiuto «Il codice si somma ai prezzi per ruolo».
2. «Applica» (o Invio). **Sistema:** controlla il codice.
3. **Valido:** avviso verde «Codice applicato: 10% di sconto», riga sconto nel riepilogo, totale aggiornato, bottone «Togli il codice».
4. **Scaduto:** «Il codice … è scaduto il 31/12/2026. Prova un altro codice».
5. **Non riconosciuto:** «Codice non riconosciuto. Controlla di averlo scritto giusto: lettere e numeri, senza spazi».
6. **Campo vuoto:** «Scrivi un codice, poi premi Applica».
7. Il messaggio sta in una zona `role="status"` e il fuoco non si sposta, così lo legge anche chi usa uno screen reader.
8. Nel prototipo: `BENVENUTO10` funziona, `SCADUTO24` è scaduto. Regole di cumulo e di validità: domanda 5.

### 4.6 Prenotazione gratuita di una sessione inclusa

1. Serve il pass. **Senza pass:** il pannello dice «Per partecipare ti serve un pass», mostra il prezzo del pass intero per ruolo e il bottone «Compra un pass».
2. **Con pass:** bottone «Prenota il mio posto, gratis», con la riga «puoi annullare quando vuoi».
3. **Sistema:** riduce i posti, mostra «Posto prenotato», conferma via email e area personale, propone «Annulla la prenotazione».
4. **Ingresso libero:** le sessioni senza limite di posti dicono «Per questa sessione non serve prenotare».
5. **Posti esauriti:** testo «Posti esauriti» con icona, bottone disattivato, offerta «Avvisami se si libera un posto» (lista d'attesa: domanda 6).
6. **Posti non noti:** si dichiara «Posti: dato non ancora disponibile» e il bottone è disattivato con «Prenotazione non ancora disponibile».

### 4.7 Check-in

1. Il giorno prima: email con il codice QR e il link ai biglietti; il codice è anche in «I miei biglietti», leggibile con luminosità alta.
2. All'ingresso lo staff apre la pagina admin «Check-in» dal telefono e inquadra il codice (alternativa: ricerca per nome o email).
3. **Sistema:** verde con testo «Valido · Nome Cognome · Pass intero» e suono/vibrazione opzionali; mai solo colore.
4. **Già usato:** giallo con ora del primo ingresso. **Non valido o rimborsato:** rosso con motivo. **Pass giornaliero di un altro giorno:** rosso con il giorno corretto.
5. Per le sessioni a posti limitati si può fare un secondo check-in in sala (da definire se serve).
6. Se manca la rete: ricerca in elenco già scaricato e coda di sincronizzazione (da valutare nella fase tecnica).

## 5. Stati e casi limite

| Situazione | Che cosa vede la persona | Che cosa succede |
|---|---|---|
| **Posti esauriti** (sessione) | Testo «Posti esauriti» barrato nel colore errore con icona, bottone disattivato (bordo tratteggiato), offerta di avviso | Nessuna vendita o prenotazione; lista d'attesa da decidere |
| **Pochi posti** (10 o meno) | «Ultimi 6 posti su 24» con icona, colore di avvertimento | Solo informazione |
| **Posti non noti** | «Posti: dato non ancora disponibile» in corsivo | Si dichiara, non si nasconde |
| **Pass esaurito o vendita chiusa** | Avviso nella pagina biglietti, opzione disattivata con motivo | Nessun ordine possibile |
| **Membership scaduta** | Avviso giallo, prezzo pubblico, link a «Rinnova» | Il prezzo membro non si applica; rinnovo da Stripe |
| **Membership in scadenza prima dell'evento** | Nota «scade il … prima dell'evento» | Domanda 2: conta la data d'acquisto o quella dell'evento |
| **Verifica studente in attesa** | Prezzo pubblico, riga «si attiva quando la verifica viene approvata», avviso con orologio | Compra al pubblico oppure aspetta; nessun rimborso automatico della differenza (domanda 7) |
| **Verifica rifiutata** | Motivo scritto, bottone per riprovare | Resta il prezzo pubblico |
| **Pagamento fallito** | Titolo «Il pagamento non è andato a buon fine», avviso rosso `role="alert"`, «Non ti abbiamo addebitato nulla», bottoni «Riprova» e «Torna al riepilogo» | L'ordine resta aperto |
| **Pagamento in elaborazione** | «Stiamo aspettando la conferma da Stripe. Non pagare di nuovo.» | La conferma arriva dal webhook di Stripe; la pagina non dichiara il successo da sola |
| **Pagamento annullato** | «Non ti abbiamo addebitato nulla», ordine ancora recuperabile | Posti delle sessioni a pagamento tenuti per un tempo da definire |
| **Rimborso** | In «Ordini»: stato «Rimborsato» con data e importo; il biglietto passa a «Non valido»; email di conferma | Si avvia da admin su Stripe; il check-in rifiuta il codice; i posti tornano disponibili. Regole su termini e importi: consulenza legale |
| **Codice sconto** | Messaggi del paragrafo 4.5 | Controllo lato server |
| **Due sessioni sovrapposte** | Avviso giallo che nomina entrambe | Non blocca |
| **Doppio acquisto** (stesso pass due volte) | Avviso «hai già un pass» sopra l'ordine | Non blocca |
| **Account non connesso a metà ordine** | L'ordine si conserva e si riprende dopo l'accesso | — |
| **Tempo scaduto** (posti tenuti) | Messaggio con bottone «Riprova» | Da decidere insieme alla durata |
| **Dato mancante** (orari porte, accessibilità sede, contatti) | «Da definire» o «da confermare» scritto | Niente invenzioni |
| **Senza JavaScript** | Il prototipo non mostra i dati | Nel sito vero la pagina è generata dal server |

## 6. Accessibilità: WCAG 2.2 AA applicata

I contrasti sono calcolati a mano sui colori dei token (elenco in testa a `prototipo/styles.css`); la misura sulla build la fa `ux-reviewer`.

| Principio | Come è applicato |
|---|---|
| Contrasto del testo (1.4.3) | Testo ≥ 4,5:1 su tutti i fondi, nei due temi; il secondario è ≥ 6,6:1 |
| Contrasto dei componenti (1.4.11) | Bordi dei campi #6B717A (4,4:1 sul fondo), icone e ring di fuoco ≥ 3:1 |
| Non solo colore (1.4.1) | Incluse/a pagamento: pieno contro contorno e testo; tracce: forma (cerchio, quadrato, rombo, triangolo) e nome; posti: icona e parole; errori: icona e testo; pagina corrente: grassetto e barra; colonna «Tu»: etichetta |
| Fuoco visibile e non coperto (2.4.7, 2.4.11) | Anello di 3px con distanza di 2px su tutto; `scroll-padding` evita che barra in basso e barra del totale lo coprano |
| Dimensione dei bersagli (2.5.8) | Minimo 44px: bottoni 48px, chip 44px, voci menu 60px, campi 48px, `summary` 48px |
| Reflusso e zoom (1.4.10, 1.4.4) | Nessuno scorrimento orizzontale a 360px; tabelle che a telefono diventano blocchi; testo ≥ 17px, campi 16px (niente zoom forzato su iOS) |
| Movimento (2.3.3, 2.2.2) | `prefers-reduced-motion` ferma ogni animazione e transizione; l'unico momento animato è l'ingresso dei tre giorni nella home; nessun video né audio |
| Scheda e menu | Schede dei giorni con modello ARIA (frecce, Home, Fine); sezioni espandibili con `<details>`, nativo e da tastiera |
| Mappa e griglia | La griglia per sala ha sempre l'alternativa elenco (bottone «Elenco»); su telefono c'è solo l'elenco; nessuna mappa geografica nel prototipo |
| Form (3.3.1, 3.3.2, 3.3.3, 3.3.7) | Etichette visibili, aiuti collegati con `aria-describedby`, errori scritti accanto al campo con `aria-invalid`, riepilogo errori in alto con link e fuoco; i dati già scritti non si richiedono due volte |
| Accesso (3.3.8) | Nessun test cognitivo né CAPTCHA a puzzle; incolla e gestori di password consentiti; email con link come alternativa alla password |
| Stati annunciati | Esito dei filtri, codice sconto, prenotazione e pagamento in `role="status"`; errori bloccanti in `role="alert"` |
| Struttura | Una sola `h1` per pagina, titoli in ordine, `lang="it"`, link «Vai al contenuto», punti di riferimento (`header`, `nav`, `main`, `footer`) |
| Temi | Chiaro e scuro automatici; modalità contrasto forzato gestita; `color-scheme` dichiarato |
| Linguaggio | Frasi corte, bottoni con il verbo («Prenota il mio posto», «Vai al pagamento su Stripe»), errori che dicono il problema e la via d'uscita |

## 7. Segnali per la Desiderabilità (pPoC)

Attrito e chiarezza osservabili, da misurare sul prototipo e poi sul sito:

| Segnale | Obiettivo di partenza |
|---|---|
| Passaggi dal primo schermo al pagamento (pass da visitatore) | 4 o meno: home, biglietti, dati, Stripe |
| Il prezzo giusto è leggibile senza aprire niente | Sì in home, programma, sessione, biglietti |
| Errori di compilazione per ordine | Il riepilogo errori deve essere letto da chi lo riceve |
| Domande al supporto su «quale prezzo mi spetta» | Dovrebbero calare con la tabella e la colonna «Tu» |

Gli obiettivi sono ipotesi di lavoro, non misure.

## 8. Limiti del prototipo

- I contrasti sono calcolati a mano, il prototipo non è stato aperto in un browser né provato con screen reader o a 360px: serve il giro di `ux-reviewer`.
- Dati, nomi, luoghi e prezzi sono inventati (elenco in `prototipo/prototipo.js`); le foto degli speaker sono monogrammi segnaposto.
- Il ruolo e il pass si scelgono con il selettore in cima: nel sito vero vengono dall'account e da Stripe.
- Pagine non fatte: accesso, area personale, admin, pagine legali.
- Nella griglia per sala gli interventi interni non si espandono: restano nell'elenco e nella pagina sessione.
- Il font è di sistema; la famiglia vera va scelta con licenza e ospitata in proprio.

## 9. Domande aperte per Giulio

| # | Domanda | Proposta |
|---|---|---|
| 1 | Chi compra per altri: a chi spetta il prezzo ridotto? | Segue l'account del partecipante; chi compra paga il suo prezzo per sé e il pubblico per gli altri (come nel prototipo) |
| 2 | La membership conta alla data d'acquisto o a quella dell'evento? | Alla data d'acquisto; avviso se scade prima dell'evento |
| 3 | La sessione a pagamento si compra anche senza pass? | Sì |
| 4 | Acquisto senza account (ospite)? | Account obbligatorio, con link via email, per ritrovare biglietti e dare il prezzo giusto |
| 5 | I codici si sommano al prezzo ridotto? Limiti, scadenza, uno per ordine? | Sconto sul totale già ridotto; un solo codice per ordine |
| 6 | Posti delle sessioni durante il pagamento e lista d'attesa? | Posti tenuti per un tempo breve; lista d'attesa semplice |
| 7 | Studente in attesa che ha già pagato il pubblico: differenza rimborsata? | Da decidere con la consulenza legale e fiscale |
| 8 | Nome vero dell'evento, luogo, date, orari porte, accessibilità della sede | Servono da te |
| 9 | Altri vantaggi della membership oltre al prezzo | Elenco da te |
| 10 | Durata di una verifica studente e tempi di approvazione | Un anno accademico; risposta entro un numero di giorni da definire |
| 11 | Famiglia di caratteri e logo | Scelta con licenza e ospitata in proprio |
| 12 | Condizioni di vendita, recesso, rimborsi, IVA, privacy dei documenti studente | Alla catena legale-fiscale prima di scrivere le pagine |
