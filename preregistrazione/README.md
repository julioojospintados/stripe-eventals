# Pagina di preregistrazione EAS8

Autore: Giulio Occhipinti. 06/11/2026.

Pagina singola in inglese da mettere online **subito**, mentre si costruisce la Fase 1 (`docs/architettura.md` §18).
Raccoglie in Mailchimp i contatti di chi vuole sapere quando aprono le vendite (e i membri interessati all'early bird
riservato). Non ha un backend nostro: il modulo fa un POST diretto all'**embedded form** di Mailchimp.

| File | Cosa contiene |
|---|---|
| `index.html` | hero EAS8 Lisbon, modulo, «What's included», «Become a member», contatti, privacy (segnaposto), footer |
| `grazie.html` | «Check your inbox to confirm», pagina a cui Mailchimp rimanda dopo l'invio (double opt-in) |
| `style.css` | identità del prototipo (`prototipo/styles.css`) ridotta a ciò che serve qui |
| `assets/eas-logo.png` | logo bianco, va su fondo scuro |

Niente cookie, niente script di tracciamento, niente risorse esterne: il browser chiede solo file di questa cartella
finché non si preme «Pre-register». Dopo l'invio si è sulle pagine di Mailchimp (loro dominio, loro cookie: da citare
nella privacy policy, a cura del cliente).

## 1. Collegarla a Mailchimp, passo per passo

I nomi dei menu di Mailchimp cambiano spesso: quelli qui sotto sono indicativi [uncertain]. Da questa sessione il sito
mailchimp.com non era raggiungibile, quindi la documentazione ufficiale non è stata letta direttamente; il supporto dei
tag negli embedded form risulta dalla pagina d'aiuto di Mailchimp «Add an Embedded Signup Form to Your Website»
(vista nei risultati di ricerca, non aperta) e da esempi pubblici del codice generato.

1. **Audience.** Usare l'audience che il cliente ha già (account attivo) e distinguere i preregistrati con un **tag**,
   invece di crearne una nuova: un contatto in due audience conta due volte nel piano di Mailchimp, e sul piano gratuito
   le audience sono limitate [uncertain sui limiti del piano del cliente]. Una nuova audience serve solo se il cliente
   vuole tenere il congresso del tutto separato (in quel caso: Audience → Create audience, con mittente e indirizzo
   dell'organizzatore).
2. **Campi** (Audience → Settings → *Audience fields and \*|MERGE|\* tags*). `EMAIL`, `FNAME`, `LNAME` ci sono già.
   Aggiungere, **non obbligatori** (se un campo è obbligatorio in Mailchimp e manca, l'invio si ferma sulla loro pagina):

   | Etichetta | Tipo in Mailchimp | Merge tag da impostare | `name` nel modulo | Valori inviati |
   |---|---|---|---|---|
   | Country | Text | `COUNTRY` | `COUNTRY` | nome inglese del paese, es. `Portugal` (vuoto se non scelto) |
   | Profession | Radio buttons | `PROF` | `PROF` | `Orthodontist`, `General dentist`, `Resident`, `Industry`, `Other`, scritti **identici** |
   | Membership interest | Text | `MEMBER` | `MEMBER` | `Yes` se la casella è spuntata, niente altrimenti |

   Mailchimp dà ai campi nuovi nomi come `MMERGE3`, `MMERGE4`, `MMERGE5`: o si rinomina il merge tag come in tabella, o
   si cambia l'attributo `name` in `index.html` con il nome assegnato. Alternativa con i **gruppi** (Audience → Manage
   contacts → Groups): Profession come gruppo a scelta singola (`name="group[ID]"`, valore = numero della scelta) e
   l'interesse per la membership come gruppo a caselle (`name="group[ID][N]"`, `value="N"`); ID e numeri si leggono
   nel codice dell'embedded form. I gruppi sono più comodi per i segmenti, i campi di testo più semplici da collegare.
3. **Consenso.** Attivare i campi GDPR dell'audience (Audience → Settings → *Audience name and defaults* → *Enable
   GDPR fields* [uncertain sul percorso]) con il permesso «Email». Nel codice dell'embedded form compare
   `name="gdpr[NNNN]" value="Y"`: copiare `NNNN` al posto di `REPLACE_GDPR` nella casella del consenso. Se il cliente
   non vuole i campi GDPR, togliere l'attributo `name` da quella casella: resta obbligatoria nel modulo ma non viene
   inviata (il double opt-in registra comunque data e IP della conferma). Il testo dei permessi e la privacy policy
   sono **a cura del cliente**.
4. **Tag.** Creare il tag `EAS8 pre-registration` (Audience → Tags). Nel builder dell'embedded form (Audience → Signup
   forms → Embedded forms) sceglierlo nel menu **Tags**: il codice generato contiene
   `<input type="hidden" name="tags" value="NNNN">` con l'ID numerico del tag. Copiare `NNNN` al posto di `REPLACE_TAG`.
   Verificare con un invio di prova che il contatto riceva il tag; se non succede, togliere la riga e assegnare il tag
   con un segmento sul campo `MEMBER` o sulla data d'iscrizione.
5. **Double opt-in attivo** (Audience → Settings → *Audience name and defaults* → *Enable double opt-in*). Personalizzare
   l'email di conferma (Signup forms → Form builder → *Opt-in confirmation email*): mittente, oggetto e testo
   in inglese li decide il cliente.
6. **URL dell'embedded form e valori u, id, f_id.** In Audience → Signup forms → Embedded forms → *Continue* / copia del
   codice, cercare la riga
   `<form action="https://ACCOUNT.usNN.list-manage.com/subscribe/post?u=AAAA&amp;id=BBBB&amp;f_id=CCCC" ...>`.
   In `index.html`:
   - sostituire l'host intero `REPLACE_DC.list-manage.com` con quello del codice (es. `ACCOUNT.usNN.list-manage.com`);
   - `REPLACE_U` → `AAAA`, `REPLACE_ID` → `BBBB`, `REPLACE_F` → `CCCC`;
   - nel campo honeypot `b_REPLACE_U_REPLACE_ID` mettere il nome che compare nel codice (`b_AAAA_BBBB`). Deve restare
     vuoto, fuori schermo e fuori dal tab: non toglierlo.

   Non copiare lo script `mc-validate.js` né i CSS di Mailchimp: la validazione accessibile è già nella pagina.
7. **Redirect alla pagina di ringraziamento.** Signup forms → Form builder → *Signup thank you page* → «Instead of
   showing this thank you page, send subscribers to another URL» → `https://<indirizzo della pagina>/grazie.html`.
   Facoltativo: anche *Confirmation thank you page* può rimandare alla pagina di preregistrazione.
8. **Prova.** Inviare il modulo con un indirizzo vero: si arriva a `grazie.html`, arriva l'email di conferma, dopo il
   clic il contatto è *Subscribed* con paese, professione, interesse per la membership, consenso e tag. Errori come
   «già iscritto», email rifiutata o un controllo anti-bot (captcha) li mostra Mailchimp sulla sua pagina [uncertain:
   dipende dalle impostazioni anti-spam dell'audience].

## 2. Pubblicarla sull'hosting condiviso (SSH/SFTP)

La pagina è statica: basta copiare la cartella. Serve **HTTPS** (il modulo raccoglie dati personali).

**Prima di avere orthocongress.org** (dominio ancora da comprare), una di queste:
- un **sottodominio** di un dominio che il cliente ha già sull'hosting (es. `eas8.<dominio-esistente>`), creato dal
  pannello dell'hosting con il certificato gratuito (Let's Encrypt / AutoSSL);
- una **cartella temporanea** di un sito già in HTTPS sullo stesso hosting (es. `https://<dominio-esistente>/eas8/`).

Copia via SSH (dalla radice del repository; utente, host e cartella li dà Simone):

```
rsync -av --delete preregistrazione/ UTENTE@HOST:~/public_html/eas8/
# senza rsync:
scp -r preregistrazione/* UTENTE@HOST:~/public_html/eas8/
```

Con un client SFTP (FileZilla, Cyberduck) si caricano gli stessi file nella stessa cartella. Permessi: file 644,
cartelle 755. `README.md` si può non caricare.

Se l'hosting usa Apache, un `.htaccess` nella cartella forza HTTPS:

```
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

**Quando arriva orthocongress.org:** copiare la cartella nella radice del dominio, aggiornare in Mailchimp l'URL della
pagina di ringraziamento (passo 7) e reindirizzare con un 301 l'indirizzo temporaneo. All'apertura delle vendite la
pagina viene sostituita dal sito della Fase 1.

**Font Inter:** oggi si usa lo stack di sistema. Per avere Inter, ospitare i file WOFF2 (licenza SIL OFL) in
`assets/fonts/` sul server del cliente e togliere il commento dal blocco `@font-face` in `style.css`. Niente Google
Fonts in produzione.

## 3. Cosa resta al cliente

- **Privacy policy**: testo e indirizzo (oggi c'è una sezione segnaposto `#privacy-policy`), compresa la menzione di
  Mailchimp; verifica del testo del consenso. Testi legali: non scritti da noi.
- **Contatti**: email pubblica del congresso (oggi segnaposto in pagina e nel footer).
- **«What's included»**: i tre punti definitivi (oggi segnaposto marcati «Placeholder»).
- **Foto** dell'evento o di Lisbona, se le vuole (la pagina funziona senza).
- **Dominio** orthocongress.org: acquisto, DNS, certificato.
- **Mailchimp**: campi, tag, double opt-in, testo dell'email di conferma, redirect (sezione 1).
- Versione italiana, se serve prima della Fase 1.

## 4. Lista di controllo prima di andare online

- [ ] Nessun `REPLACE_` rimasto: `grep -n REPLACE_ index.html` non trova niente.
- [ ] `action` del modulo con host, `u`, `id`, `f_id` dell'embedded form; honeypot `b_<u>_<id>` corretto.
- [ ] Campi `COUNTRY`, `PROF`, `MEMBER` presenti in Mailchimp con gli stessi merge tag (o `name` aggiornati), non obbligatori; scelte di Profession identiche.
- [ ] Consenso: `gdpr[...]` corretto, oppure `name` tolto se i campi GDPR non si usano.
- [ ] Tag `EAS8 pre-registration`: ID in `tags`, verificato con un invio di prova (oppure riga tolta).
- [ ] Double opt-in attivo; email di conferma rivista dal cliente.
- [ ] Redirect di Mailchimp verso `grazie.html` sull'indirizzo definitivo.
- [ ] Privacy policy: link vero al posto di `#privacy-policy` (pagina e footer) e sezione segnaposto tolta o riempita.
- [ ] Contatti e «What's included» sostituiti; nessun «Placeholder» visibile.
- [ ] HTTPS attivo e forzato; pagina aperta da telefono.
- [ ] Invio di prova completo: `grazie.html` → email di conferma → contatto *Subscribed* con campi, consenso e tag.

## 5. Verifica fatta (06/11/2026)

Playwright (Chromium) sulla cartella servita in locale, senza inviare niente a Mailchimp (richiesta intercettata):

| Misura | 360×740 | 390×844 | 1280×800 |
|---|---|---|---|
| Scorrimento orizzontale (index e grazie) | nessuno | nessuno | nessuno |
| Errori o avvisi in console, richieste esterne, cookie | 0 | 0 | 0 |
| Modulo dalla prima schermata | ancora «Pre-register» in vista (fondo a 504px) | ancora in vista (484px) | modulo accanto all'hero (titolo a 169px) |
| Bersagli sotto 44px | 0 su 18 | 0 su 18 | 0 su 17 |
| Contrasto minimo del testo sul fondo vero | 5,46:1 | 5,46:1 | 5,46:1 |

Validazione con tastiera: Tab fino al bottone e Invio a vuoto → fuoco sul riepilogo «There are 4 problems» con 4 link,
titolo della pagina «Error: …», i 4 campi con `aria-invalid` e messaggio collegato da `aria-describedby`; Invio sul
primo link porta il fuoco al campo. Invio valido: POST con `FNAME`, `LNAME`, `EMAIL` (spazi tolti), `COUNTRY`, `PROF`,
`MEMBER`, `gdpr[...]`, `tags`, honeypot vuoto. Senza JavaScript l'invio a vuoto è bloccato dal browser (fuoco sul primo
campo, nessuna richiesta a Mailchimp). Screenshot: `docs/ux/screenshot/preregistrazione-390.png` e `-1280.png`.
