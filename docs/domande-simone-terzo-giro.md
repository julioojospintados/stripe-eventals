# Domande per Simone, terzo giro

Autore: Giulio Occhipinti. 06/11/2026.

Solo domande tecniche e di prodotto (legale e fisco restano al cliente a lavoro ultimato, `docs/architettura.md`
§19). Sostituiscono le domande aperte del §21 di `docs/architettura.md`; le scelte «decise per default» di quel
paragrafo restano valide. Le domande sui requisiti dell'hosting non ci sono più: le risolve lo script di verifica.

**Come rispondere:** una sola email con i numeri delle domande. «Non so» va benissimo: vale quello che c'è scritto in
«Nel frattempo».

## Prima: due cose da fare, non da rispondere

| Cosa | Dove | Quando serve |
|---|---|---|
| Lanciare lo script di verifica dell'hosting e mandare il riepilogo (domanda 1) | [`strumenti/verifica-hosting/README.md`](../strumenti/verifica-hosting/README.md) | Subito: è il primo blocco della Fase 1 |
| Raccogliere i contenuti nei modelli CSV: sale, speaker, sessioni, interventi, tipi di biglietto, fasce e prezzi | [`contenuti/modelli/README.md`](../contenuti/modelli/README.md) | Biglietti, fasce e prezzi per l'apertura delle vendite; programma e speaker per la Fase 2 |

---

## 1. Risultato della verifica dell'hosting

**Domanda.** Puoi lanciare `verifica-hosting.php` via SSH e mandarci il riepilogo, completato con le sette voci del
pannello che lo script non vede (versione PHP del sito, cron ogni minuto, cartella pubblica su una sottocartella,
HTTPS gratuito, paese del data center e fornitore, processi PHP in parallelo, secondo database)? Se lo script segnala
l'errore 1419 sui trigger, puoi girare all'assistenza dell'hosting il testo in inglese che stampa?

- **Perché serve:** prende il posto di tutte le domande sui requisiti (§14.1 e domanda 5 del §21): PHP e
  estensioni, funzioni disattivate, Composer, cron, disco, accessi a Stripe, versione del database, trigger, vincoli e
  lock, tutto provato davvero sul server. Senza, il primo blocco della stima (avvio, deploy, cron, backup: 1
  settimana-persona, §18.3) non parte.
- **Nel frattempo:** si sviluppa in locale sul caso peggiore: trigger facoltativi (`DB_TRIGGERS_OPTIONAL=true`), una
  riga di cron per comando, dipendenze di Composer preparate fuori dal server. Se il database è MariaDB 10.6 o più
  vecchio, va chiesto all'hosting MariaDB 10.11 o 11.4: con le versioni prima della 10.7 le migrazioni del progetto
  non passano (lo script lo segnala).

## 2. Prezzo e date del last minute

**Domanda.** Quanto costa il pass intero in last minute, per il pubblico e per i membri? Da che giorno e ora parte, e
fino a quando si vende (fino all'inizio dell'evento o oltre)?

- **Perché serve:** è l'unico prezzo del pass non ancora deciso; la fascia va creata con date e prezzi.
- **Nel frattempo:** nessuna fascia last minute. Finito il regular valgono i prezzi base, 695 € pubblico e 395 €
  membro. La fascia si aggiunge dal pannello quando decidete, senza toccare il codice.

## 3. Date dell'early bird e del regular

**Domanda.** In che giorni (e a che ora di Lisbona) cominciano e finiscono l'early bird e il regular?

- **Perché serve:** le fasce non possono sovrapporsi e il prezzo dipende dalla fascia attiva al momento dell'acquisto.
  L'early bird è solo per i membri: 276,50 € (395 − 30%); il pubblico paga 695 €.
- **Nel frattempo:** in prova si usano date finte. In produzione, senza date confermate, il pass si vende ai prezzi
  base (695 / 395 €) e l'early bird non parte; le date si cambiano dal pannello anche dopo.

## 4. Data realistica di apertura delle vendite

**Domanda.** Quale data di apertura scegliete, sapendo la stima qui sotto? Lavora uno sviluppatore o due? Va bene
aprire prima una pagina di preregistrazione?

- **Perché serve:** la Fase 1 è stimata in **12–16 settimane-persona** (§18.3). Con un inizio a metà novembre 2026
  l'apertura cade realisticamente **tra metà gennaio 2027 (due sviluppatori) e metà febbraio–marzo 2027 (uno)**. Un
  early bird che finisce a fine gennaio non è compatibile: le date della domanda 3 vanno scelte dopo questa.
- **Nel frattempo:** si pianifica con uno sviluppatore (apertura tra metà febbraio e marzo 2027) e una pagina di
  preregistrazione sul dominio nuovo (circa una settimana di lavoro, domande 11 e 12).

## 5. Dominio e DNS

**Domanda.** Chi compra `orthocongress.org` e entro quando? Chi gestisce i DNS (il registrar o l'hosting) e chi può
aggiungere i record che serviranno (sito, SPF, DKIM, DMARC per le email)? La casella `Website@orthocongress.org`
esiste già, su quale server?

- **Perché serve:** senza dominio niente HTTPS, niente email affidabili (SPF e DKIM), niente pagina di preregistrazione
  né link di Stripe e dei video legati al dominio. Il §18.3 presuppone il dominio entro la seconda settimana.
- **Nel frattempo:** si lavora su un indirizzo provvisorio dell'hosting, senza email vere; la preregistrazione non
  parte.

## 6. Accompagnatori ed espositori

**Domanda.** Quanto costano il biglietto accompagnatore e il pass espositore? Cosa comprende l'accompagnatore
(sessioni, area espositiva, eventi sociali)? Quanti accompagnatori per pass (proposta: 1)? Per gli espositori va bene
un codice d'invito per azienda, e quanti pass comprende il pacchetto sponsor? Servono già all'apertura delle vendite?

- **Perché serve:** sono tipi di biglietto con regole proprie (accompagnatore solo per chi ha un pass, espositore solo
  con il codice dell'azienda) e decidono se stanno nella Fase 1 o nella 2.
- **Nel frattempo:** Fase 2; all'apertura non sono in vendita (in `ticket_types.csv` sono già preparati e disattivati).

## 7. Omaggi all'apertura

**Domanda.** Speaker e staff devono ricevere il biglietto omaggio già all'apertura delle vendite? Circa quanti omaggi
in tutto?

- **Perché serve:** gli omaggi contano nei 6.000 posti e si emettono dal pannello; se servono subito entrano nella
  Fase 1.
- **Nel frattempo:** Fase 2: speaker e staff ricevono il biglietto quando arriva la funzione, comunque prima
  dell'evento.

## 8. Ruoli dei video

**Domanda.** Chi vede quali video? La proposta è: archivio dei congressi passati ai **membri**, video dell'EAS8 ai
**partecipanti** dell'EAS8, più eventuali video pubblici o riservati a speaker e staff. I vostri piani di Wistia e
Vimeo permettono di incorporare i video solo sul vostro dominio?

- **Perché serve:** ogni video si associa a uno o più ruoli dal pannello; la restrizione di dominio è l'unica
  protezione dei video senza un servizio nuovo.
- **Nel frattempo:** vale la proposta; i video sono in Fase 2, nessuna urgenza.

## 9. Formato del QR per il partner del check-in

**Domanda.** Chi è il partner del check-in e chi è il suo contatto tecnico? Il loro lettore accetta un QR qualsiasi
(il nostro, firmato) o vuole un formato suo? Che colonne vuole nel file dei partecipanti, e userà un collegamento
automatico (API) o gli basta il file?

- **Perché serve:** il biglietto con il QR parte per email al momento dell'acquisto, quindi il formato va fissato
  prima dell'apertura delle vendite.
- **Nel frattempo:** QR firmato nostro ed export CSV con biglietto, codice breve, nome, tipo, giorni ammessi e stato
  (§11.2). Se il partner chiede un altro formato, i QR si rigenerano e si rimandano prima dell'evento.

## 10. Regole di acquisto dei workshop

**Domanda.** Un workshop si compra solo con il pass intero (già comprato o nello stesso ordine)? Quanti workshop al
massimo per persona e per ordine (proposta: 2 per ordine)? Una persona può comprare due workshop alla stessa ora?

- **Perché serve:** sono 10 workshop da 20 posti a 100 €, che finiranno in pochi minuti: le regole vanno fissate prima,
  dopo è tardi.
- **Nel frattempo:** proposta: solo con il pass intero; il workshop non usa un altro posto del giorno (il pass lo ha
  già); massimo 2 per ordine; due workshop alla stessa ora per la stessa persona bloccati.

## 11. Mailchimp per la preregistrazione

**Domanda.** In quale audience di Mailchimp vanno i preregistrati? Puoi mandarci l'indirizzo del modulo incorporato
(*Audience → Signup forms → Embedded forms*: l'indirizzo dopo `action=`, del tipo
`https://….list-manage.com/subscribe/post?u=…&id=…`) e dirci se la doppia conferma è attiva e quali campi chiede?
**Non mandare la chiave API** per email: servirà più avanti e si inserisce direttamente sul server.

- **Perché serve:** con il modulo di Mailchimp la pagina di preregistrazione si fa in pochi giorni e i contatti non
  passano dal nostro server.
- **Nel frattempo:** modulo nostro con il consenso registrato nel database ed export CSV da importare in Mailchimp:
  più lento da preparare.

## 12. Contatti e testo della pagina di preregistrazione

**Domanda.** Puoi mandarci il testo breve in inglese (e in italiano, se lo volete) per la pagina: titolo, date e
luogo, cosa succede quando si aprono le vendite, il vantaggio dell'early bird per i membri? E inoltre: l'email di
contatto pubblica, il link alla pagina privacy che usate già, il logo in SVG o PNG ad alta risoluzione.

- **Perché serve:** è l'unica pagina che può andare online prima della Fase 1.
- **Nel frattempo:** prepariamo noi una bozza dai testi e dai colori di eas-aligners.com, da approvare;
  contatto `Website@orthocongress.org`.
