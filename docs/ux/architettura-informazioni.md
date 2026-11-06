<!--
  Autore: Giulio Occhipinti
-->
# Architettura delle informazioni e UX

Autore: Giulio Occhipinti. Fase: progettazione. Aggiornato al 06/11/2026.
Riferimenti: `docs/brief.md` (decisioni di Giulio), prototipo cliccabile in `prototipo/` (apri `index.html`).

Nome evento (dal 06/11/2026): **EAS8 Lisbon**, 8–10 aprile 2027, Lisbona, 6.000 posti (risposte del cliente in
`docs/cliente/risposte-2026-11-06.md`; date da confermare, vedi 11.3). Prezzi, membership e capienza sono quelli del cliente;
speaker, sessioni, orari, sede, video e vendite sono segnaposto e dichiarati tali nell'interfaccia. Quello che manca è scritto «da definire», non riempito.
I capitoli 1-10 sono stati scritti sul primo prototipo («Convegno 2027», prezzi inventati): dove i numeri differiscono vale il capitolo 11.

## 1. Direzione visiva

> **Superata il 06/11/2026.** Il cliente vuole la coerenza con eas-aligners.com: vedi il capitolo 11 («Identità EAS e scostamenti per accessibilità»). La tabella qui sotto descrive la versione editoriale precedente, conservata in `proposte/versione-migliorata/`.

| Scelta | Perché |
|---|---|
| Programma stampato di un festival: filetti sottili, numeri grandi, raggi quasi squadrati | Evento professionale, non template SaaS |
| Un solo accento (oltremare) che nella home riempie un'intera fascia | Riconoscibile, regge il contrasto AA |
| Titoli a peso e scala, cifre tabellari, font di sistema per ora | Nessun font scaricato finché non si sceglie e si ospita una famiglia (una riga: `--font-display`) |
| Tema chiaro e scuro con `prefers-color-scheme` | Uso in sala con luce bassa e all'aperto |
| Primo schermo della home: nome, tre date in grande, bottone «Compra i biglietti» subito dopo le date (sopra y≈600 a 360×740, non coperto dalla tab bar), poi i giorni e il prezzo del pass intero | Risposta e azione senza scorrere; la barra in basso non copre il bottone |
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
| Biglietti | `biglietti.html` | P | Tabella prezzi per ruolo, ordine, codice sconto, come ottenere il prezzo membro, fascia early bird aperta |
| Membership | `membership.html` | P | Vantaggi, quota, confronto con/senza |
| Esito pagamento | `checkout-esito.html?stato=` | P | Riuscito, in elaborazione, annullato, fallito; simula Stripe |
| Accesso / registrazione | `accesso.html` | P | Codice di 6 cifre via email (niente password), account creato al primo accesso, nessun test cognitivo. «Continua senza account» non c'è: vedi capitolo 10 |
| Condizioni, privacy, rimborsi, contatti, accessibilità della sede | — | — | Pagine legali: vanno alla catena legale prima di scriverle |

### 2.2 Area personale (con accesso)

| Pagina | Contenuto |
|---|---|
| I miei biglietti | Pass, sessioni a pagamento, codice QR per l'ingresso, stato (valido, rimborsato) |
| Le mie prenotazioni | Sessioni incluse prenotate, annulla, lista d'attesa |
| Il mio programma | Sessioni scelte in ordine di orario, sovrapposizioni segnalate |
| Membership | Stato, scadenza, rinnovo automatico, disdetta, fatture |
| Ordini e ricevute | Elenco ordini, ricevute; nessuna richiesta di rimborso (decisione del 05/11/2026): al suo posto il cambio nominativo gratuito fino a 48 ore prima |
| Profilo | Dati, email, lingua, esigenze di accessibilità, cancellazione account |

### 2.3 Admin (organizzatore)

| Area | Contenuto |
|---|---|
| Evento | Nome, date, luogo, giorni, sale, tracce |
| Programma | Sessioni e interventi, speaker, capienze, incluse o a pagamento, prezzi per ruolo |
| Prodotti e prezzi | Pass intero e giornaliero, membership, prezzi per ruolo, collegamento ai prodotti Stripe |
| Codici sconto | Percentuale o importo, validità, limite d'uso, prodotti a cui si applica |
| Ordini | Elenco, stato, rimborsi (si avviano su Stripe), esportazione |
| Prenotazioni e liste d'attesa | Per sessione, spostamenti manuali |
| Check-in | Lettura del QR da telefono, ricerca per nome, ingresso già usato, elenco entrati |
| Utenti e ruoli | Membri, staff con permessi |

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
| Visitatore (non connesso) | Due righe: Pubblico, Membro «con la membership, sul tuo biglietto» | 486,50 € · 276,50 € (fascia early bird −30%) |
| Utente non membro | «Il tuo prezzo · Pubblico» grande; sotto «Altro prezzo» e link «Come ottenere il prezzo membro» | 486,50 € grande; Membro 276,50 € |
| Membro | «Il tuo prezzo · Membro» grande, prezzo pubblico barrato, riga «vale sul tuo biglietto, chi viene con te paga il pubblico» | 276,50 € grande, 486,50 € barrato |
| Ex membro | Prezzo pubblico grande, riga «membership scaduta: rinnova per tornare a 276,50 €» | 486,50 € grande |

Dove c'è una fascia di vendita (pass intero) una riga sotto il prezzo dice quale è aperta e quando finisce, con il prezzo che seguirà: «Fascia «Early bird −30%» fino al 31/01/2027 (data di esempio) · poi pubblico 695 €, membro 395 €». I prezzi dei pass vengono dalla fascia aperta alla data (nel prototipo simulata: 06/11/2026), quindi sito pubblico e admin mostrano sempre la stessa cifra. Se nessuna fascia è aperta lo si scrive.

Regole di contorno:

- Il barrato ha un testo nascosto «Prezzo pubblico» per i lettori di schermo: non si affida alla sola riga barrata.
- Nelle righe di elenco (programma, speaker) la versione compatta: visitatore «Pubblico 395 € · Membro 276,50 €» (esempio sul pass; nella griglia del programma «Membri … · pubblico …»: il ruolo è sempre scritto, mai un «da X €» senza dire per chi), connesso «Tuo prezzo · Membro 276,50 € (pubblico 486,50 €)». **Workshop:** oggi 100 € per tutti, quindi la riga dice «100 € · prezzo unico: oggi uguale per pubblico e membro», senza barrati finti (vedi 11.3).
- Nella pagina biglietti la tabella dei prezzi evidenzia la colonna del ruolo con fondo e con un'etichetta «Tu» (non solo colore).
- Le sessioni incluse non hanno prezzo: hanno il badge pieno «Inclusa nel pass» (le a pagamento: badge con solo contorno «A pagamento»).
- La membership si propone solo dove conviene: nel riepilogo compare «risparmio netto» se il risparmio supera la quota, altrimenti «per questo solo ordine non conviene». Mai pressione.
- **Prezzo membro solo sul biglietto del membro che compra** (decisione di Giulio, 05/11/2026). In un ordine con più persone il primo biglietto è intestato a chi compra (dati dell'account, non modificabili nella pagina) ed è a prezzo membro; gli altri sono al prezzo pubblico. Una riga lo dice prima del pagamento («Prezzo membro solo sul tuo biglietto…»), il riepilogo separa «Partecipante 1 (tu) · prezzo membro» da «Partecipanti 2–N · prezzo pubblico». Un amico membro compra con il proprio account.

## 4. Flussi principali

Convenzione: **Sistema** è ciò che risponde; **Se…** è un ramo di errore o limite (casi estesi nel capitolo 5).

### 4.1 Acquisto del pass da non membro

1. Dalla home «Compra i biglietti» (o voce Biglietti) porta a `biglietti.html`. Il visitatore vede la tabella con i due prezzi (pubblico e membro) e la fascia di vendita aperta.
2. Sceglie «Pass intero» (o giornaliero, e allora il giorno).
3. Imposta le persone (1-6); per ognuna nome ed email.
4. Facoltativo: sessioni a pagamento, codice sconto.
5. Legge il riepilogo (prezzo pubblico, totale) e spunta condizioni e privacy.
6. «Vai al pagamento su Stripe». **Sistema:** controlla i campi; se mancano, riepilogo errori in alto con link ai campi. Se tutto è a posto, chiede accesso o registrazione (da definire: vedi domanda 4), crea l'ordine, apre Stripe Checkout.
7. Paga su Stripe. Ritorno su `checkout-esito.html`: riuscito, in elaborazione, annullato o fallito.
8. Riuscito: conferma a schermo, email con biglietti nominativi e codice QR, invito a prenotare le sessioni incluse con posti limitati.

### 4.2 Acquisto da membro con prezzo membro

1. Il membro accede. L'intestazione dice «Vista: Membro»; la home già mostra 276,50 € grande con 486,50 € barrato (fascia early bird).
2. Passi 1-5 come sopra: il **primo biglietto dell'ordine** è intestato al membro (nome ed email dall'account, in sola lettura) e prende il prezzo membro; i biglietti degli altri partecipanti sono al prezzo pubblico. Una riga nella sezione «Per chi» lo spiega, anche con una persona sola («se aggiungi altre persone, loro pagano il prezzo pubblico»).
3. Il riepilogo ha una riga per il Partecipante 1 (prezzo membro) e una per gli altri (prezzo pubblico), più la nota «Il prezzo membro è applicato solo al tuo biglietto»; nessuna proposta di membership. Le sessioni a pagamento vanno al Partecipante 1 e prendono il prezzo membro.
4. Pagamento e ritorno come in 4.1.
5. **Se la membership è scaduta:** avviso giallo in cima («la tua membership è scaduta, paghi il prezzo pubblico») con link a «Rinnova». Mai applicare il prezzo membro in silenzio e poi correggerlo al pagamento.
6. **Diventare membro:** `membership.html`, «Diventa membro», abbonamento su Stripe; al ritorno la membership è attiva e i prezzi si aggiornano subito.

### 4.3 Chi compra per altri

1. In `biglietti.html`, sezione «Il prezzo membro: come ottenerlo», colonna «Chi compra per altri»: il primo biglietto è di chi compra, gli altri costano il prezzo pubblico; un amico membro compra con il proprio account.
2. Se non è connesso: avviso «Per comprare al prezzo membro devi prima accedere» con il link a «Accedi».
3. Nota di decisione: il ruolo «studente» non esiste (Giulio, 05/11/2026). Niente verifica, niente documenti da custodire, niente coda admin.

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
| **Ordine con più persone, acquirente membro** | Riga «Prezzo membro solo sul tuo biglietto», riepilogo con una riga per il Partecipante 1 e una per gli altri | Il prezzo membro si applica solo al primo biglietto, intestato all'account |
| **Nessuna fascia di vendita aperta** | Riga «oggi nessuna fascia di vendita è aperta: il pass non si può comprare» sotto il prezzo | Nessun ordine di pass possibile |
| **Pagamento fallito** | Titolo «Il pagamento non è andato a buon fine», avviso rosso `role="alert"`, «Non ti abbiamo addebitato nulla», bottoni «Riprova» e «Torna al riepilogo» | L'ordine resta aperto |
| **Pagamento in elaborazione** | «Stiamo aspettando la conferma da Stripe. Non pagare di nuovo.» | La conferma arriva dal webhook di Stripe; la pagina non dichiara il successo da sola |
| **Pagamento annullato** | «Non ti abbiamo addebitato nulla», ordine ancora recuperabile | Posti delle sessioni a pagamento tenuti per un tempo da definire |
| **Rimborso** | In «Ordini»: stato «Rimborsato» con data e importo; il biglietto passa a «Non valido»; email di conferma | Solo dall'admin, solo per evento annullato o pagato senza posti (decisione del 05/11/2026, da far confermare alla catena legale); il check-in rifiuta il codice; i posti tornano disponibili |
| **Codice sconto** | Messaggi del paragrafo 4.5 | Controllo lato server |
| **Due sessioni sovrapposte** | Avviso giallo che nomina entrambe | Non blocca |
| **Doppio acquisto** (stesso pass due volte) | Avviso «hai già un pass» sopra l'ordine | Non blocca; il secondo biglietto dello stesso tipo per sé è a prezzo pubblico (un prezzo membro per evento e tipo, anche su ordini diversi) |
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
- Il font è Inter (come il sito del cliente), caricato da Google Fonts nel prototipo; in produzione va ospitato in proprio (11.2).

## 9. Domande aperte per Giulio

| # | Domanda | Proposta |
|---|---|---|
| 1 | ~~Chi compra per altri: a chi spetta il prezzo ridotto?~~ | **Decisa il 05/11/2026:** solo il biglietto del membro che compra; gli accompagnatori pagano il pubblico. Resta da definire se un accompagnatore già membro, che compra con il proprio account, abbia il suo prezzo (sì, per come è scritta la regola) |
| 2 | La membership conta alla data d'acquisto o a quella dell'evento? | Alla data d'acquisto; avviso se scade prima dell'evento |
| 3 | La sessione a pagamento si compra anche senza pass? | Sì |
| 4 | Acquisto senza account (ospite)? | Account obbligatorio, con link via email, per ritrovare biglietti e dare il prezzo giusto |
| 5 | I codici si sommano al prezzo ridotto? Limiti, scadenza, uno per ordine? | Sconto sul totale già ridotto; un solo codice per ordine |
| 6 | Posti delle sessioni durante il pagamento e lista d'attesa? | Posti tenuti per un tempo breve; lista d'attesa semplice |
| 8 | Nome vero dell'evento, luogo, date, orari porte, accessibilità della sede | Servono da te |
| 9 | Altri vantaggi della membership oltre al prezzo | Elenco da te |
| 11 | Famiglia di caratteri e logo | Scelta con licenza e ospitata in proprio |
| 12 | Condizioni di vendita, recesso, rimborsi, IVA, privacy | Alla catena legale-fiscale prima di scrivere le pagine |
| 13 | Accesso con Google | `docs/architettura.md` prevede solo il codice via email: il bottone nel prototipo è spento e dichiarato «da decidere» |
| 14 | Rimborso: pannello o Dashboard Stripe? | Il prototipo segue l'architettura (§4.8): si rimborsa dal pannello, la Dashboard serve a guardare. Un rimborso fatto in Dashboard finisce in «Da rivedere» |

## 10. Area personale, admin e check-in (seconda parte)

File: `prototipo/accesso.html`, `account.html`, `admin/*.html`, `checkin.html`. Coerente con `docs/architettura.md`
(ruoli derivati dai dati, ordini, check-in con QR firmato). Il modello dei dati è in `db/modello-dati.md` (Laravel + Filament dal 06/11/2026).

### 10.1 Accesso

1. Email, poi «Mandami il codice». Errore scritto sotto il campo se l'email non è valida.
2. Codice di 6 cifre (campo con `autocomplete="one-time-code"`, si può incollare). Errori: formato sbagliato, codice errato con tentativi rimasti, troppi tentativi (chiedi un nuovo codice). «Rinvia il codice» si riattiva dopo 30 secondi; «Cambia email» torna indietro.
3. Si entra e si torna alla pagina da cui si veniva (`?next=`, solo pagine dell'elenco consentito).
4. **Senza account non si compra**: servono il prezzo di ruolo calcolato dal server e il ritrovamento dei biglietti. Programma, speaker e prezzi restano pubblici. La pagina lo dice, non offre il bottone.
5. Gli admin hanno un secondo passaggio (TOTP) dopo il codice: citato nella pagina, non prototipato.

### 10.2 Area personale (`account.html`, schede con modello ARIA, `#biglietti` ecc. nell'indirizzo)

| Scheda | Cosa fa | Casi coperti |
|---|---|---|
| I miei biglietti | Un biglietto per persona con QR nero su bianco, codice breve a 8 caratteri, stato, PDF e wallet (simulati) | Valido, rimborsato (QR sostituito dal testo «QR revocato»). Il QR non contiene il nome. L'importo pagato dice se era prezzo membro o pubblico |
| Cambio nominativo | Modulo nel biglietto: nome ed email nuovi, avviso che il QR vecchio smette di valere, gratis fino a 48 ore prima dell'inizio, massimo 2 cambi (proposta) | Non cedibile se il prezzo è ridotto (ruolo): spiega perché e rimanda all'organizzazione; meno di 48 ore all'inizio; limite cambi raggiunto; errori sui campi |
| La mia agenda | Sessioni prenotate e a pagamento per giorno | Orari sovrapposti: avviso che nomina le due sessioni e offre «Annulla la prenotazione» solo dove è gratis; sessioni a pagamento in conflitto: nessun annullamento, rimando all'organizzazione |
| Membership | Stato, scadenza, rinnovo, apertura del portale clienti Stripe (simulato in pagina) | Nessuna, attiva, disdetta a fine periodo, pagamento fallito (non vale come membro, regola proposta), scaduta con «Rinnova» |
| Ordini e ricevute | Elenco con stato e ricevuta | Pagato, in elaborazione («non pagare di nuovo»), non riuscito e scaduto (con «Riprova»), rimborsato |

I selettori «Prototipo: simula lo stato» nelle schede servono solo a vedere tutti gli stati; nel sito vero lo stato viene dal database.

### 10.3 Admin (`admin/`)

Pensato per desktop (menu a sinistra), usabile a 390px (menu a tendina, tabelle che diventano blocchi, bersagli da 44px). Barra con «Sessione con MFA verificata»: ogni scrittura vera richiede `aal2` e finisce nel registro audit (la scritta compare dopo ogni salvataggio simulato).

| Pagina | Flusso | Controlli e casi limite |
|---|---|---|
| Cruscotto | «Da fare» in cima (ordini da rivedere, pagamenti in elaborazione), vendite per tipo e ruolo, posti rimasti per pass e per sessioni a pagamento, ultimi ordini | Posti: venduti, in riserva, rimasti con barra **e** numero; il pass intero conta il giorno più pieno; sessione esaurita scritta |
| Programma | Giorni, sale e tracce (sale e tracce riordinabili); sessioni per giorno con modulo completo e interventi riordinabili | Ogni campo degli interventi ha un nome accessibile per riga («Ora, intervento «…»»); i riquadri che contengono il riordino lo dicono nel titolo («dentro: elenco e riordino»). Sala occupata nello stesso orario (errore), fine prima dell'inizio, capienza sotto i venduti, prezzi mancanti, interventi fuori orario o non in ordine, speaker in due sessioni contemporanee (avviso), sala o traccia usata da sessioni non rimovibile, sessione con posti venduti non eliminabile, eliminazione in due passi |
| Riordino | Trascinamento con il mouse **e** pulsanti Su e Giù (tastiera, telefono); la nuova posizione è annunciata ai lettori di schermo | Il trascinamento HTML non funziona al tocco: i pulsanti sono l'alternativa completa |
| Speaker | Elenco, modulo, foto con testo alternativo obbligatorio | Foto troppo pesante o di tipo sbagliato, speaker con sessioni non eliminabile |
| Biglietti e prezzi | Matrice tipo × due ruoli (pass dalla fascia in corso, sessioni modificabili), fasce di vendita con early bird (stessa fonte dati del sito pubblico: la fascia aperta è quella che si vede in home e in biglietti), capienze | Fasce sovrapposte per lo stesso pass, fine prima dell'inizio, nessuna fascia aperta alla data simulata (pass non acquistabile), prezzo membro sopra il pubblico (avviso: nessun vantaggio), capienza sotto venduti più riserva |
| Codici sconto | Elenco con stato, creazione con ruoli, biglietti, finestra, limiti, cumulabilità | Codice troppo corto o duplicato, percentuale oltre 100, usi per persona sopra i totali; testo sul cumulo come in `architettura.md` §4.4 |
| Ordini | Ricerca per numero, nome o email, filtro per stato, dettaglio con eventi Stripe | Da rivedere (importo che non torna) con «Segna come riconciliato»; in elaborazione con «Ricontrolla su Stripe»; link alla Dashboard solo per guardare |
| Rimborso | Scelta dei biglietti (importo al netto dello sconto), posti da rimettere in vendita, motivo, conferma con avvertenze, invio a Stripe, conferma simulata | Motivo obbligatorio, solo «Evento annullato» o «Pagato senza posti» (decisione del 05/11/2026); avvisi: biglietti revocati subito, commissioni non restituite, rimborsi in Dashboard non si collegano ai biglietti; esito: rimborsato o parziale |
| Membri | Elenco con stato, ricerca, dettaglio; apertura del cliente su Stripe | Pagamento non riuscito con «Rimanda l'email»; il prezzo membro vale solo sul biglietto di chi compra; sola lettura |

### 10.4 Check-in (`checkin.html`, telefono, una mano)

1. Stato di rete sempre visibile con icona e testo: online con ultima sincronizzazione, offline con numero di check-in da inviare. Casella del prototipo per simulare l'assenza di rete; al ritorno online compare l'avviso su eventuali doppi ingressi da altri varchi.
2. Impostazioni in una tendina: varco, giorno, che cosa controlli (ingresso all'evento o a una sessione), vibrazione e suono (spenti di partenza).
3. L'esito occupa la parte alta dello schermo: bordo spesso, **parola grande**, icona grande e dettaglio. Mai solo colore.

| Esito | Parola | Dettaglio |
|---|---|---|
| Entra | VALIDO (verde, spunta) | Nome e tipo di biglietto |
| Già entrato | GIÀ USATO (giallo, avviso) | Ora e varco del primo ingresso; chiedere un documento |
| QR non riconosciuto | NON VALIDO (rosso, croce) | Firma che non torna: non far entrare |
| Rimborsato | NON VALIDO (rosso, croce) | Biglietto rimborsato |
| Altro giorno | GIORNO SBAGLIATO (rosso, croce) | Giorni validi e giorno di oggi |
| Altra sessione | SESSIONE SBAGLIATA (rosso, croce) | Sessione che il biglietto non include |
| Firma valida ma non in lista | NON IN LISTA (giallo, avviso) | Controllare un documento e poi «fai entrare» (come in `architettura.md` §6.2) |

4. Le scansioni sono simulate con pulsanti (la fotocamera vera la gestirà la PWA).
5. Barra in basso con «Prossimo biglietto» (azzera l'esito) e «Cerca».
6. **Ricerca per nome o codice breve**, alternativa alla scansione: almeno 2 caratteri, risultato con stato e pulsante «Segna come entrato» solo se può entrare.
7. Ultimi cinque ingressi e contatore degli entrati. Errori e avvisi sono annunciati in modo assertivo, gli esiti positivi in modo cortese.

### 10.5 Cosa resta fuori o è semplificato

- Doppio ingresso tra due varchi offline: si dichiara l'avviso alla sincronizzazione, ma la regola di risoluzione non è prototipata.
- Accesso admin con TOTP, registro audit consultabile, lista d'attesa e liquidazione dei biglietti in massa (evento annullato) non sono disegnati.
- Il pannello admin non ha ancora una vista del registro delle azioni né dei lavori in coda.
- Tutti i contrasti nuovi usano i token esistenti (avvisi e tinte già calcolati); non misurati sulla build.

## 11. Identità EAS e scostamenti per accessibilità

Autore: Giulio Occhipinti. Prototipo rivestito il 06/11/2026 con l'identità di eas-aligners.com (misurata dal CSS e dagli stili calcolati della
pagina pubblica; robots.txt consente `/`). Il cliente vuole coerenza col suo sito «anche dove è fatto male»: stessa aria, senza rompere WCAG 2.2 AA.
I contrasti sono calcolati a mano con la formula WCAG 2.x (elenco in testa a `prototipo/styles.css`); la misura sulla build la fa `ux-reviewer`.
La versione editoriale precedente resta in `proposte/versione-migliorata/`, non toccata.

### 11.1 Cosa è stato preso dal sito del cliente

| Elemento | Valore | Dove |
|---|---|---|
| Font | Inter (Google Fonts, 100..900): titoli 600, parole chiave dell'hero 900, corpo 400 | tutto |
| Hero, barra di navigazione | #202020, testo #f5f5f5, filetto sottile; logo PNG bianco 84×47 a sinistra, menu, Login a destra | header di ogni pagina |
| Hero della home | «EAS8» bianco 900, «Lisbon» arancio #f28e1c 900, date arancio, tagline in inglese (`lang="en"`), CTA oro e bianca | `index.html` |
| CTA principale | oro #fdc30c, testo #111111, raggio 6px; secondaria #f5f5f5 con testo #111111 | bottoni |
| Fascia speaker | azzurro #3d8fc9 | home |
| Fascia sponsor | blu notte #14364b, titolo grigio-azzurro | home (elenco sponsor: da fornire) |
| Footer | #080b0c: logo, link, contatti (office@eas-aligners.com e indirizzo pubblici del loro sito), newsletter | tutte le pagine pubbliche |
| Variabili | stessi nomi del loro CSS (`--eas-primary`, `--eas-gold`, …) dove esistono | `styles.css` |
| Contenuti lunghi (programma, biglietti, account, admin) | fondo #f5f5f5, testo #111111, come i loro moduli | tutte le pagine tranne hero/nav/footer |

Il sito del cliente è solo scuro. Qui hero, navigazione e footer sono scuri sempre (anche con il sistema in modo chiaro); il tema scuro di
sistema per i contenuti è stato tolto. Telefono: barra in basso a quattro voci (Programma, Speaker, Biglietti, Membership) su fondo scuro, pagina corrente con barra acqua (#2ec3c0) e grassetto.
Menu del nostro sito: Programma, Speaker, Biglietti, Membership, Login (diventa «Account» da connessi). Le altre voci del loro menu (Call for Abstracts, Road to Lisbon, EBAO, Events, FAQ, About Us, Contact Us) non sono nostre e non compaiono.

### 11.2 Scostamenti dall'identità, con il motivo

| # | Loro | Qui | Motivo (contrasto calcolato) |
|---|---|---|---|
| 1 | Corpo del testo Inter 200 (`--eas-font-weight`) | Inter 400 | 200 è troppo sottile a 17px: illeggibile, specie su telefono |
| 2 | Arancio #f28e1c anche su chiaro (possibile) | arancio solo su fondo scuro | 6,7:1 su #202020 e 5,2:1 su #14364b; su #f5f5f5 sarebbe 2,2:1 |
| 3 | Titolo sponsor #667b83 su #14364b | #7b9097, solo per il titolo grande | #667b83 = 2,85:1 (sotto 3:1 anche per testo grande); #7b9097 = 3,8:1. Il testo corrente è #f5f5f5 (11,6:1) |
| 4 | Testi secondari #667b83 | #4f6775 (`--eas-blue-jeans`) | #667b83 su #f5f5f5 = 4,1:1 (< 4,5:1); #4f6775 = 5,5:1 |
| 5 | Pulsante Login grigio #667b83, testo bianco | #4f6775 con #f5f5f5 | 4,1:1 contro 5,5:1 |
| 6 | Titolo bianco su azzurro #3d8fc9 | tenuto, ma solo per il titolo grande (≥ 24px) | 3,2:1: passa solo per testo grande. Il testo dentro la fascia è #111111 (5,4:1) |
| 7 | Bottone oro senza bordo | sulle parti chiare bordo 2px #111111 | oro su #f5f5f5 = 1,5:1: il bottone non si distinguerebbe dallo sfondo (1.4.11). Sul fondo scuro resta senza bordo visibile |
| 8 | Rosso #e53835 / verde #2e7d31 per i testi di stato | #9b1c1c e #0b5d2e su tinta chiara | #2e7d31 su tinta verde = 4,4:1 (< 4,5:1); il rosso su tinta rosa non arriva a 4,5:1. Restano solo come colori decorativi |
| 9 | Raggio 1.5rem per gli elementi | 1rem per le schede, 6px per i bottoni | con 1rem di spazio interno il testo non tocca l'angolo arrotondato |
| 10 | Menu con molte voci, sottomenu, riga social in alto | 4 voci + Login; niente riga social | Bersagli ≥ 44px e nessuno scorrimento orizzontale a 360px; i link ai profili social vanno chiesti al cliente (non inventati) |
| 11 | Bottoni affiancati che a telefono vanno a capo parola per parola (visibile nel loro screenshot mobile) | bottoni uno sotto l'altro, a tutta larghezza, `white-space: nowrap`, CTA nella prima schermata a 360×740 | A 360px nel loro hero il testo dei bottoni si spezza; qui stima: barra prototipo 47px + intestazione 65px + hero fino ai bottoni ≈ 555px, sopra la barra in basso (680px). **Non misurato in un browser** |
| 12 | Testo bianco e arancio solo scuro | focus visibile: oro #fdc30c su scuro (10,1:1), #14364b su chiaro (11,6:1), #111111 sulla fascia azzurra | 2.4.7 e 2.4.11 |
| 13 | Google Fonts caricato dal loro sito | stesso nel prototipo | In produzione Inter va ospitato in proprio: una richiesta a Google da un browser UE è un problema di privacy (da far vedere alla catena legale) |

Stati mai solo a colore: invariati dal capitolo 6 (forma delle tracce, icona e testo degli avvisi, sottolineatura dei link, grassetto e barra della pagina corrente).

### 11.3 Dati veri e segnaposto (06/11/2026)

| Dato | Valore | Stato |
|---|---|---|
| Evento | EAS8 Lisbon, Lisbona | vero (cliente) |
| Date | 8–10 aprile 2027 | **da confermare**: il modulo dice 10-14 aprile, il sito pubblico 8–10. Si usa 8–10; la nota «date da confermare» sta solo nella barra del prototipo (summary e testo) |
| Posti | 6.000 | vero (cliente) |
| Pass intero | 695 € pubblico · 395 € membro | vero |
| Early bird | −30% → 486,50 € · 276,50 € | percentuale vera; **fascia di esempio fino al 31/01/2027** (data inventata, inizio 01/11/2026 pure) |
| Workshop a pagamento | 100 € | vero. **Stesso prezzo per il membro** finché il cliente non ne indica uno diverso: l'interfaccia lo dichiara («prezzo unico: oggi uguale per pubblico e membro») e non mostra barrati o risparmi finti |
| Membership | 200 € all'anno; vantaggi: accesso early bird, member area con i video dei congressi passati | vero |
| Sede | «Sede da confermare» | da fornire |
| Speaker, sessioni, orari, sale, tracce, video, ordini, membri, vendite, capienze delle sale | inventati, con l'etichetta «segnaposto» (speaker con nomi evidentemente fittizi) | segnaposto |
| Sponsor, social | non forniti | da chiedere |

**Pass giornaliero: non offerto per ora.** Il cliente ha scritto «giornaliero non indicato». È stato tolto da tutta l'interfaccia (biglietti, membership, account, admin, sconti, check-in). Se tornasse: un tipo di pass in più, un pool di posti per giorno, fasce e prezzi propri, e il pass intero tornerebbe a consumare un posto in ciascun giorno (come nel primo prototipo). La capienza di 6.000 posti vale oggi per il pass intero; se serve una capienza per giorno va chiesta.
**«Accesso early bird» come vantaggio della membership** è ambiguo: nel prototipo la fascia early bird è aperta a tutti e il membro ha il suo prezzo (276,50 €). Se invece l'early bird fosse riservato ai membri per un periodo, cambia la logica di vendita. Domanda per il cliente.
**Masterclass** non esiste più come tipo: restano keynote, panel, talk e workshop (a pagamento).

### 11.4 Lingua: italiano e inglese

Il sito sarà in italiano e in inglese; il prototipo resta in italiano.

- **Nel prototipo:** selettore «IT/EN» nell'intestazione (etichetta «Lingua / Language»). Cambia solo l'attributo `lang` della pagina e mostra un avviso («versione inglese simulata»). Non salva la scelta. Mettere `lang="en"` su testo italiano è scorretto per i lettori di schermo: vale solo per il prototipo. Il tagline del hero è già in inglese e ha `lang="en"` anche nella pagina italiana.
- **Come si gestiranno le traduzioni (proposta, da confermare con la scelta dello stack):**
  - testi dell'interfaccia: file di traduzione di Laravel (`lang/it`, `lang/en`), una chiave per frase, mai testo scritto dentro i template;
  - contenuti dell'evento (titoli e descrizioni di sessioni, bio degli speaker, vantaggi della membership): campi traducibili nel database, compilabili da Filament in due lingue; se manca l'inglese si mostra l'italiano con l'etichetta «solo in italiano», mai un campo vuoto [uncertain: esistono plugin Filament per i campi traducibili, da verificare versione e manutenzione];
  - indirizzi distinti per lingua (`/it/…`, `/en/…`) con `hreflang`, `<html lang>` vero e titolo di pagina tradotto;
  - la lingua scelta si ricorda nel profilo (per chi è connesso) o in un cookie tecnico; email e ricevute partono nella lingua dell'utente; Stripe Checkout riceve la lingua;
  - importi, date e numeri formattati per lingua (`486,50 €` in italiano, `€486.50` in inglese);
  - chi traduce: l'organizzatore dal pannello; testi legali tradotti solo dopo la revisione della catena legale;
  - stringhe corte e senza frasi spezzate in più pezzi, perché l'inglese cambia l'ordine delle parole.

### 11.5 Ruoli dell'admin (Laravel + Filament)

Selettore «Vedi come ruolo» in cima a ogni pagina admin: nasconde le voci di menu, le sezioni del cruscotto e le pagine non permesse (una pagina vietata mostra solo l'avviso). Nel sito vero i permessi sono policy di Laravel e il pannello non ha il selettore.

| Voce | Admin | Commerciale (pagamenti e fatture) | Organizzatore (biglietti, tier, date) |
|---|---|---|---|
| Cruscotto: «Da fare», vendite, ultimi ordini | sì | sì | no |
| Cruscotto: posti rimasti, check-in con il partner | sì | no | sì |
| Programma e speaker (date, sale, sessioni) | sì | no | sì |
| Biglietti e prezzi (fasce early bird = «tier», capienze) | sì | no | sì |
| Codici sconto | sì | sì | no (ipotesi: da confermare con il cliente) |
| Ordini e fatture, rimborsi | sì | sì | no |
| Membri | sì | sì | no |
| Esporta biglietti validi per il partner (CSV) | sì | no | sì |

La fatturazione vera (Stripe + Xero) non è prototipata: «Ordini e fatture» mostra gli ordini, le fatture sono da definire.
**Check-in:** lo fa un partner sul posto, che scansiona i biglietti digitali dalla member area. `checkin.html` resta come riferimento (con un avviso in cima). Nel pannello c'è «Esporta biglietti validi per il partner (CSV)», **simulata**: non crea file. Colonne proposte: codice breve, nominativo, tipo, sessione (workshop); niente email né importi (minimizzazione). Solo biglietti di ordini pagati e non rimborsati. Aperti: formato vero che il partner accetta (CSV o API), aggiornamento dopo rimborsi o cambi di nominativo (un CSV scaricato prima non li vede: serve un'API o un nuovo invio), e un accordo sul trattamento dei dati con il partner (alla catena legale). Il QR del biglietto è firmato (capitolo 10): il partner deve poterlo verificare.

### 11.6 Member area: video dei congressi

Nuova scheda «Video dei congressi» in `account.html` (elenco segnaposto di quattro video).

| Chi guarda | Cosa vede |
|---|---|
| Membro | Elenco con anteprima, titolo, edizione, durata e pulsante «Guarda» (nel prototipo mostra un avviso: non c'è un lettore) |
| Utente non membro | Elenco **bloccato**: icona lucchetto, badge «Bloccato · solo membri», nessun pulsante; avviso «Riservato ai membri» con «Diventa membro» (200 € all'anno) |
| Ex membro | Come sopra, con «Rinnova la membership» |
| Visitatore | Scheda dietro l'accesso, come tutta l'area personale |

Regole per i video veri: nessun avvio automatico, mai audio senza un tocco; sottotitoli (obbligatori per l'accessibilità di contenuti preregistrati, 1.2.2) e trascrizione dove possibile; modalità risparmio dati (non caricare il video finché non lo si avvia); controlli da tastiera; `prefers-reduced-motion` rispettato. Serve un servizio di video con accesso protetto (link firmati, scadenza); non è previsto dal piano attuale e va stimato (costo e dati in UE, vincolo del cliente). I diritti sui video dei congressi passati (relatori, partner) vanno verificati dalla catena legale prima di metterli online.

### 11.7 Domande aperte nate da questo giro

| # | Domanda | Per chi |
|---|---|---|
| 15 | Date: 8–10 aprile (sito) o 10-14 aprile (modulo)? | cliente |
| 16 | «Accesso early bird» per i membri: fascia riservata o prezzo membro già scontato? Date vere di inizio e fine della fascia | cliente |
| 17 | Prezzo membro dei workshop: uguale (100 €) o diverso? | cliente |
| 18 | Pass giornaliero: mai, o più avanti? Capienza per giorno? | cliente |
| 19 | Sede, elenco sponsor, profili social, relatori veri e foto | cliente |
| 20 | Hosting di Inter in proprio e nessuna richiesta a Google Fonts | Giulio / catena legale |
| 21 | Export per il partner: formato, accordo sul trattamento dei dati | cliente / catena legale |
| 22 | Video dei congressi: servizio di hosting, diritti, sottotitoli | Giulio / catena legale |

### 11.8 Limiti di questo giro

- Il prototipo non è stato aperto in un browser: contrasti calcolati a mano, 360×740 stimato, nessuna prova con screen reader o tastiera. Serve il giro di `ux-reviewer`.
- Header e footer sono scritti da `prototipo.js` (nel sito vero sono parti Blade); senza JavaScript il footer mostra una sola riga e il selettore di lingua non c'è.
- Il QR del biglietto è un segnaposto non leggibile; le anteprime dei video sono riquadri a righe.
