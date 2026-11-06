/*
  Autore: Giulio Occhipinti
  Convegno 2027 (nome segnaposto) - codice condiviso del prototipo.
  Contiene: dati inventati (sessioni, persone, prezzi), stato "vedi come" (ruolo e pass, salvato
  nel browser), componente prezzo per ruolo, icone, funzioni di disegno riusate dalle pagine.
  JavaScript semplice, nessuna libreria, nessuna build. Nel sito vero questi dati vengono dal database
  e il ruolo dall'account: qui servono solo a mostrare come cambia l'interfaccia.
*/
(function (w, d) {
  'use strict';

  /* ------------------------------------------------------------------ dati (tutto inventato) */
  var DATA = {
    evento: { nome: 'Convegno 2027', luogo: 'Centro Congressi dei Navigli (segnaposto), Milano' },
    giorni: [
      { id: 'g1', breve: 'Gio 14', lungo: 'Giovedì 14 ottobre' },
      { id: 'g2', breve: 'Ven 15', lungo: 'Venerdì 15 ottobre' },
      { id: 'g3', breve: 'Sab 16', lungo: 'Sabato 16 ottobre' }
    ],
    sale: [
      { id: 'grande', nome: 'Sala Grande', nota: 'plenaria' },
      { id: 'lanterna', nome: 'Sala Lanterna', nota: 'panel e talk' },
      { id: 'officina', nome: 'Sala Officina', nota: 'workshop' },
      { id: 'studio', nome: 'Aula Studio', nota: 'masterclass' }
    ],
    tracce: [
      { id: 't1', nome: 'Città e paesaggio' },
      { id: 't2', nome: 'Archivi e memoria' },
      { id: 't3', nome: 'Mestieri della cultura' },
      { id: 't4', nome: 'Tecnologia e società' }
    ],
    tipi: { keynote: 'Keynote', panel: 'Panel', talk: 'Talk', workshop: 'Workshop', masterclass: 'Masterclass' },
    oggi: '2027-03-10',   // data simulata del prototipo: decide quale fascia di vendita e' aperta
    // Fasce di vendita dei pass (early bird e standard): unica fonte, usata dal sito e dall'admin.
    fasce: [
      { id: 'f1', nome: 'Early bird', tipo: 'intero', dal: '2027-02-01', al: '2027-04-30', pub: 150, mem: 100 },
      { id: 'f2', nome: 'Standard', tipo: 'intero', dal: '2027-05-01', al: '2027-10-14', pub: 180, mem: 120 },
      { id: 'f3', nome: 'Early bird', tipo: 'giornaliero', dal: '2027-02-01', al: '2027-04-30', pub: 65, mem: 45 },
      { id: 'f4', nome: 'Standard', tipo: 'giornaliero', dal: '2027-05-01', al: '2027-10-14', pub: 80, mem: 55 }
    ],
    prezzi: {
      intero: { pub: 0, mem: 0 },        // prezzi della fascia aperta oggi: calcolati dai dati subito sotto
      giornaliero: { pub: 0, mem: 0 },
      workshop: { pub: 0, mem: 0 },      // "da": calcolato dai dati subito sotto
      membership: 40
    },
    speakers: [
      { id: 'sp1', nome: 'Marta Valsecchi', cognome: 'Valsecchi', ruolo: 'Storica dell’architettura', ente: 'Università di Esempia', tono: 1,
        bio: ['Studia da vent’anni il rapporto tra edifici pubblici e vita di quartiere. Ha curato mostre e guide urbane in sei città.', 'Biografia inventata per il prototipo.'] },
      { id: 'sp2', nome: 'Davide Ferrante', cognome: 'Ferrante', ruolo: 'Curatore di archivi sonori', ente: 'Fonoteca Civica di Esempia', tono: 2,
        bio: ['Recupera e cataloga registrazioni su nastro: interviste, concerti di quartiere, radio locali.', 'Biografia inventata per il prototipo.'] },
      { id: 'sp3', nome: 'Ilaria Bonomi', cognome: 'Bonomi', ruolo: 'Designer di servizi culturali', ente: 'Studio Bonomi Lazzari', tono: 3,
        bio: ['Progetta percorsi di accesso per teatri, biblioteche e festival. Conduce workshop con équipe di piccoli enti.', 'Biografia inventata per il prototipo.'] },
      { id: 'sp4', nome: 'Tommaso Rinaldi', cognome: 'Rinaldi', ruolo: 'Urbanista', ente: 'Laboratorio Città Lente', tono: 1,
        bio: ['Lavora su mobilità dolce e spazi pubblici. Ha ideato un format di passeggiate collettive per leggere i quartieri.', 'Biografia inventata per il prototipo.'] },
      { id: 'sp5', nome: 'Chiara Dell’Orto', cognome: 'Dell’Orto', ruolo: 'Editrice', ente: 'Edizioni del Ponte', tono: 4,
        bio: ['Dirige una casa editrice indipendente di saggistica. Scrive di filiera del libro e di diritti d’autore.', 'Biografia inventata per il prototipo.'] },
      { id: 'sp6', nome: 'Samuele Okafor-Bianchi', cognome: 'Okafor-Bianchi', ruolo: 'Ricercatore in linguistica computazionale', ente: 'Istituto di Esempia', tono: 4,
        bio: ['Studia come i modelli linguistici trattano l’italiano e le sue varietà regionali.', 'Biografia inventata per il prototipo.'] },
      { id: 'sp7', nome: 'Luca Zanetti', cognome: 'Zanetti', ruolo: 'Giornalista dei dati', ente: 'Redazione Dati Aperti', tono: 2,
        bio: ['Racconta il territorio con dati pubblici. Tiene corsi di inchiesta e visualizzazione per redazioni locali.', 'Biografia inventata per il prototipo.'] },
      { id: 'sp8', nome: 'Giovanna Pastore', cognome: 'Pastore', ruolo: 'Restauratrice di materiale cartaceo', ente: 'Archivio di Stato di Esempia', tono: 3,
        bio: ['Si occupa di conservazione di carte fragili: mappe, registri, manifesti.', 'Biografia inventata per il prototipo.'] },
      { id: 'sp9', nome: 'Noemi Carbone', cognome: 'Carbone', ruolo: 'Mediatrice culturale e sindacalista', ente: 'Rete Lavoratori della Cultura', tono: 3,
        bio: ['Segue contratti, compensi e tutele di chi lavora nel settore culturale.', 'Biografia inventata per il prototipo.'] },
      { id: 'sp10', nome: 'Paolo Gentilini', cognome: 'Gentilini', ruolo: 'Consulente per bandi e fondazioni', ente: 'Gentilini & Associati', tono: 4,
        bio: ['Aiuta enti culturali a scrivere progetti finanziabili e a rendicontarli.', 'Biografia inventata per il prototipo.'] }
    ],
    sessioni: [
      { id: 's01', g: 'g1', da: '09:30', a: '10:30', sala: 'grande', t: 't1', tipo: 'keynote', titolo: 'Costruire il paesaggio che verrà', sp: ['sp1'],
        desc: 'Apertura del convegno. Un percorso tra città, edifici e memoria collettiva per capire che cosa vale la pena custodire e che cosa si può lasciare cambiare.',
        posti: { libero: true }, subs: [] },
      { id: 's02', g: 'g1', da: '11:00', a: '12:30', sala: 'lanterna', t: 't2', tipo: 'panel', titolo: 'Archivi vivi: chi custodisce la memoria collettiva', sp: ['sp2', 'sp5', 'sp4'],
        desc: 'Quattro esperienze di archivi nati fuori dalle istituzioni: nastri di quartiere, fondi d’impresa, edizioni indipendenti. Con discussione finale.',
        posti: { tot: 120, res: 34 },
        subs: [ { ora: '11:00', titolo: 'Apertura e presentazione dei casi', chi: 'Davide Ferrante' },
                { ora: '11:15', titolo: 'Il fondo sonoro del quartiere', chi: 'Davide Ferrante' },
                { ora: '11:40', titolo: 'Edizioni e archivi d’impresa', chi: 'Chiara Dell’Orto' },
                { ora: '12:05', titolo: 'Discussione con il pubblico', chi: 'Tutti i relatori' } ] },
      { id: 's03', g: 'g1', da: '11:00', a: '13:00', sala: 'officina', t: 't3', tipo: 'workshop', titolo: 'Progettare un servizio culturale in due ore', sp: ['sp3'], pagamento: { pub: 45, mem: 35 },
        desc: 'Laboratorio pratico: si parte da un problema reale di accesso e si arriva a un prototipo di servizio. Portare un portatile o carta e pennarelli.',
        posti: { tot: 24, res: 6 },
        subs: [ { ora: '11:00', titolo: 'Mappare il pubblico', chi: 'Ilaria Bonomi' },
                { ora: '11:45', titolo: 'Prototipare in gruppo', chi: 'Ilaria Bonomi' },
                { ora: '12:30', titolo: 'Restituzione e confronto', chi: 'Ilaria Bonomi' } ] },
      { id: 's04', g: 'g1', da: '14:30', a: '15:30', sala: 'grande', t: 't4', tipo: 'talk', titolo: 'Che cosa sa davvero un modello linguistico dell’italiano', sp: ['sp6'],
        desc: 'Esempi concreti di errori, pregiudizi e punti di forza dei modelli linguistici sull’italiano, con le varietà regionali.',
        posti: { libero: true }, subs: [] },
      { id: 's05', g: 'g1', da: '15:00', a: '17:00', sala: 'studio', t: 't2', tipo: 'masterclass', titolo: 'Restauro del suono: dal nastro al file', sp: ['sp2'], pagamento: { pub: 60, mem: 50 },
        desc: 'Masterclass sul riversamento di nastri magnetici deteriorati: pulizia, lettura, digitalizzazione e catalogazione.',
        posti: { tot: 16, res: 0 }, subs: [] },
      { id: 's06', g: 'g1', da: '16:00', a: '17:30', sala: 'lanterna', t: 't1', tipo: 'panel', titolo: 'Quartieri e musei: chi abita la città culturale', sp: ['sp4', 'sp9'],
        desc: 'Come i luoghi della cultura entrano, o non entrano, nella vita delle persone che abitano intorno a loro.',
        posti: { nd: true }, subs: [] },
      { id: 's07', g: 'g2', da: '09:30', a: '10:30', sala: 'grande', t: 't3', tipo: 'keynote', titolo: 'Il lavoro culturale è lavoro', sp: ['sp9'],
        desc: 'Compensi, contratti e tutele: che cosa è cambiato negli ultimi anni e che cosa manca ancora.',
        posti: { libero: true }, subs: [] },
      { id: 's08', g: 'g2', da: '11:00', a: '12:30', sala: 'lanterna', t: 't4', tipo: 'talk', titolo: 'Tre storie di editoria indipendente', sp: ['sp5', 'sp10', 'sp1'],
        desc: 'Tre case editrici raccontano come sostengono i propri progetti tra bandi, vendite dirette e comunità di lettori.',
        posti: { tot: 120, res: 8 },
        subs: [ { ora: '11:00', titolo: 'Una collana che regge da dieci anni', chi: 'Chiara Dell’Orto' },
                { ora: '11:30', titolo: 'Vivere di bandi senza dipenderne', chi: 'Paolo Gentilini' },
                { ora: '12:00', titolo: 'Libri e architettura: un catalogo di nicchia', chi: 'Marta Valsecchi' } ] },
      { id: 's09', g: 'g2', da: '11:00', a: '13:00', sala: 'officina', t: 't4', tipo: 'workshop', titolo: 'Dati aperti per il giornalismo locale', sp: ['sp7'], pagamento: { pub: 45, mem: 35 },
        desc: 'Dalla ricerca del dato alla pubblicazione di una piccola inchiesta territoriale. Servono un portatile e un browser aggiornato.',
        posti: { tot: 24, res: 11 }, subs: [] },
      { id: 's10', g: 'g2', da: '14:30', a: '16:30', sala: 'officina', t: 't3', tipo: 'masterclass', titolo: 'Scrivere un bando culturale che si possa finanziare', sp: ['sp10'], pagamento: { pub: 70, mem: 55 },
        desc: 'Struttura di un progetto, budget credibile, indicatori di risultato: si lavora su bandi reali anonimizzati.',
        posti: { tot: 18, res: 9 }, subs: [] },
      { id: 's11', g: 'g2', da: '14:30', a: '16:00', sala: 'grande', t: 't4', tipo: 'panel', titolo: 'Intelligenze artificiali e diritto d’autore: stato dell’arte', sp: ['sp6', 'sp7'],
        desc: 'Che cosa dicono, e che cosa non dicono ancora, le regole su opere, dati di addestramento e uso dei contenuti.',
        posti: { libero: true }, subs: [] },
      { id: 's12', g: 'g2', da: '16:30', a: '17:30', sala: 'studio', t: 't2', tipo: 'talk', titolo: 'La carta che resiste: conservare i fondi fragili', sp: ['sp8'],
        desc: 'Un viaggio nei laboratori di restauro: umidità, inchiostri, interventi minimi.',
        posti: { tot: 40, res: 40 }, subs: [] },
      { id: 's13', g: 'g3', da: '10:00', a: '11:00', sala: 'grande', t: 't1', tipo: 'keynote', titolo: 'Camminare la città', sp: ['sp4'],
        desc: 'Il cammino come metodo di ricerca: che cosa si vede a piedi e non da un mezzo.',
        posti: { libero: true }, subs: [] },
      { id: 's14', g: 'g3', da: '11:30', a: '13:00', sala: 'lanterna', t: 't2', tipo: 'panel', titolo: 'Lingue minoritarie e archivi digitali', sp: ['sp2', 'sp6', 'sp8'],
        desc: 'Come documentare e rendere accessibili lingue parlate da poche persone, tra audio, testo e tecnologie del linguaggio.',
        posti: { tot: 120, res: 57 }, subs: [] },
      { id: 's15', g: 'g3', da: '11:30', a: '13:30', sala: 'officina', t: 't1', tipo: 'workshop', titolo: 'Mappe partecipate: leggere il quartiere con chi ci vive', sp: ['sp3', 'sp4'], pagamento: { pub: 40, mem: 30 },
        desc: 'Si esce, si cammina, si disegna: un workshop sul campo per costruire una mappa condivisa.',
        posti: { tot: 20, res: 3 },
        subs: [ { ora: '11:30', titolo: 'Preparazione e consegne', chi: 'Ilaria Bonomi' },
                { ora: '12:00', titolo: 'Uscita sul campo', chi: 'Tommaso Rinaldi' },
                { ora: '13:00', titolo: 'Mappa comune', chi: 'Ilaria Bonomi, Tommaso Rinaldi' } ] },
      { id: 's16', g: 'g3', da: '15:00', a: '16:30', sala: 'grande', t: 't3', tipo: 'keynote', titolo: 'Chiusura e passaggio di testimone', sp: ['sp1', 'sp9'],
        desc: 'Sintesi dei tre giorni e annuncio della prossima edizione.',
        posti: { libero: true }, subs: [] }
    ]
  };

  // "da" dei workshop: il minimo reale tra le sessioni a pagamento, mai un numero scritto a mano
  ['pub', 'mem'].forEach(function (k) {
    DATA.prezzi.workshop[k] = Math.min.apply(null, DATA.sessioni.filter(function (s) { return s.pagamento; }).map(function (s) { return s.pagamento[k]; }));
  });

  // Fascia aperta oggi per un pass (null se nessuna) e prezzi dei pass presi da quella fascia
  function fasciaAperta(tipo, oggi) {
    return DATA.fasce.filter(function (f) { return f.tipo === tipo && f.dal <= (oggi || DATA.oggi) && (oggi || DATA.oggi) <= f.al; })[0] || null;
  }
  function fasciaDopo(f) {   // la fascia che segue quella aperta, per dire "poi X"
    return DATA.fasce.filter(function (x) { return x.tipo === f.tipo && x.dal > f.al; }).sort(function (a, b) { return a.dal < b.dal ? -1 : 1; })[0] || null;
  }
  ['intero', 'giornaliero'].forEach(function (t) {
    var f = fasciaAperta(t);
    if (f) { DATA.prezzi[t].pub = f.pub; DATA.prezzi[t].mem = f.mem; DATA.prezzi[t].fascia = f; }
  });
  function dataIt(iso) { var p = iso.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }

  // percorso della cartella che contiene prototipo.js ('' nelle pagine pubbliche, '../' in admin/)
  var BASE = (function () {
    var s = d.querySelector('script[src$="prototipo.js"]');
    return (s ? s.getAttribute('src') : 'prototipo.js').replace('prototipo.js', '');
  }());

  /* ------------------------------------------------------------------ stato "vedi come" */
  var ETICHETTA = {
    visitatore: 'Visitatore (non connesso)',
    pubblico: 'Utente (non membro)',
    membro: 'Membro',
    'membro-scaduto': 'Ex membro (membership scaduta)'
  };
  var BREVE = { visitatore: 'Visitatore', pubblico: 'Utente', membro: 'Membro', 'membro-scaduto': 'Utente' };
  var CHIAVE = { visitatore: null, pubblico: 'pub', membro: 'mem', 'membro-scaduto': 'pub' };
  var NOMEPREZZO = { pub: 'Pubblico', mem: 'Membro' };
  var stato = { ruolo: 'visitatore', pass: false };
  try {
    var salvato = JSON.parse(w.localStorage.getItem('cv-proto'));
    if (salvato && ETICHETTA[salvato.ruolo]) stato = { ruolo: salvato.ruolo, pass: !!salvato.pass && salvato.ruolo !== 'visitatore' };
  } catch (e) { /* storage non disponibile: si resta sui valori di partenza */ }

  function salva() { try { w.localStorage.setItem('cv-proto', JSON.stringify(stato)); } catch (e) { /* ignora */ } }

  /* ------------------------------------------------------------------ utilita' */
  function qs(s, r) { return (r || d).querySelector(s); }
  function qa(s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); }
  function param(n) { try { return new URLSearchParams(w.location.search).get(n); } catch (e) { return null; } }
  function euro(n) { return n.toLocaleString('it-IT') + ' €'; }
  function ic(n) { return '<svg class="ic" aria-hidden="true" focusable="false"><use href="#i-' + n + '"></use></svg>'; }
  function giorno(id) { return DATA.giorni.filter(function (x) { return x.id === id; })[0]; }
  function sala(id) { return DATA.sale.filter(function (x) { return x.id === id; })[0]; }
  function traccia(id) { return DATA.tracce.filter(function (x) { return x.id === id; })[0]; }
  function persona(id) { return DATA.speakers.filter(function (x) { return x.id === id; })[0]; }
  function sessione(id) { return DATA.sessioni.filter(function (x) { return x.id === id; })[0]; }
  function nomiSpeaker(s) { return s.sp.map(function (i) { return persona(i).nome; }).join(', '); }
  function iniziali(n) { var p = n.replace(/-/g, ' ').split(' '); return (p[0][0] + p[p.length - 1][0]).toUpperCase(); }
  function min(h) { var p = h.split(':'); return +p[0] * 60 + +p[1]; }
  function sovrapposte(a, b) { return a.g === b.g && min(a.da) < min(b.a) && min(b.da) < min(a.a); }
  function prezzoMio(p) { var k = CHIAVE[stato.ruolo]; return p[k || 'pub']; }
  function setStato(n) {
    for (var k in n) { if (Object.prototype.hasOwnProperty.call(n, k)) stato[k] = n[k]; }
    if (stato.ruolo === 'visitatore') stato.pass = false;
    salva(); applica();
    d.dispatchEvent(new CustomEvent('cv:stato'));
  }
  function ready(fn) { if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', fn); else fn(); }

  /* ------------------------------------------------------------------ componente prezzo per ruolo */
  // Visitatore: tutti i prezzi con chi ne ha diritto. Connesso: il suo prezzo in evidenza, gli altri in piccolo.
  function prezzoHTML(p, o) {
    o = o || {};
    var k = CHIAVE[stato.ruolo], r = stato.ruolo, altri, html;
    if (!k) {
      if (o.inline) {
        return '<span class="prezzi prezzi--inline"><span>Pubblico <b class="prezzo-valore">' + euro(p.pub) + '</b></span><span class="sep" aria-hidden="true">·</span>' +
          '<span>Membro <b class="prezzo-valore">' + euro(p.mem) + '</b></span></span>';
      }
      return '<div class="prezzi"><dl>' +
        '<div class="prezzo-riga"><dt>Pubblico</dt><dd><span class="prezzo-valore">' + euro(p.pub) + '</span></dd></div>' +
        '<div class="prezzo-riga"><dt>Membro<span class="prezzo-nota">con la membership, sul tuo biglietto</span></dt><dd><span class="prezzo-valore">' + euro(p.mem) + '</span></dd></div>' +
        '</dl></div>';
    }
    var tuo = p[k];
    var barrato = (k !== 'pub') ? '<span class="prezzo-barrato"><span class="sr-only">Prezzo pubblico </span><s>' + euro(p.pub) + '</s></span>' : '';
    if (o.inline) {
      var extra = '';
      if (k === 'pub') extra = ' <span class="prezzo-barrato">· da membro ' + euro(p.mem) + '</span>';
      else extra = ' ' + barrato.replace('<s>', '<s aria-hidden="true">');
      return '<span class="prezzi prezzi--inline"><span class="badge badge--ruolo">' + (k === 'pub' ? 'Prezzo pubblico' : 'Tuo prezzo · ' + BREVE[r]) + '</span> <b class="prezzo-valore prezzo-valore--tuo">' + euro(tuo) + '</b>' + extra + '</span>';
    }
    var etic = { pubblico: 'Il tuo prezzo · Pubblico', membro: 'Il tuo prezzo · Membro', 'membro-scaduto': 'Il tuo prezzo · Pubblico' }[r];
    altri = [];
    ['pub', 'mem'].forEach(function (x) { if (x !== k) altri.push(NOMEPREZZO[x] + ' ' + euro(p[x])); });
    html = '<div class="prezzi prezzi--tuo"><p class="prezzo-tuo"><span class="badge badge--ruolo">' + etic + '</span>' +
      '<span class="prezzo-valore prezzo-valore--grande">' + euro(tuo) + '</span>' + barrato + '</p>' +
      '<p class="prezzo-altri">Altro prezzo: ' + altri.join(' · ') + '</p>';
    if (r === 'pubblico') html += '<p class="prezzo-altri"><a href="biglietti.html#prezzi-ridotti">Come ottenere il prezzo membro</a></p>';
    if (r === 'membro') html += '<p class="prezzo-altri">Il prezzo membro vale sul tuo biglietto. Chi viene con te paga il prezzo pubblico.</p>';
    if (r === 'membro-scaduto') html += '<p class="prezzo-altri">Membership scaduta: <a href="membership.html">rinnova</a> per tornare a ' + euro(p.mem) + '.</p>';
    return html + '</div>';
  }

  // Nota sulla fascia di vendita aperta (early bird) per un pass; vuota se la fascia aperta e' l'ultima.
  function fasciaHTML(tipo, conNome) {
    var f = DATA.prezzi[tipo].fascia, n = f && fasciaDopo(f), nome = conNome ? { intero: 'Pass intero', giornaliero: 'Pass giornaliero' }[tipo] + ': ' : '';
    if (!f) return '<span class="t-small t-muted fascia-nota">' + nome + (conNome ? 'o' : 'O') + 'ggi nessuna fascia di vendita è aperta: il pass non si può comprare.</span>';
    return '<span class="t-small t-muted fascia-nota">' + nome + '<b>' + (conNome ? 'fascia' : 'Fascia') + ' «' + f.nome + '» fino al ' + dataIt(f.al) + '</b>' +
      (n ? ' · poi pubblico ' + euro(n.pub) + ', membro ' + euro(n.mem) : '') + '</span>';
  }

  /* ------------------------------------------------------------------ pezzi di interfaccia riusati */
  function badgeAccesso(s) {
    return s.pagamento
      ? '<span class="badge badge--pagamento">' + ic('ticket') + 'A pagamento</span>'
      : '<span class="badge badge--inclusa">' + ic('check') + 'Inclusa nel pass</span>';
  }
  function chipTraccia(id) {
    return '<span class="chip-traccia" data-t="' + id + '"><span class="marker" aria-hidden="true"></span>' + traccia(id).nome + '</span>';
  }
  function postiHTML(s) {
    var p = s.posti;
    if (p.libero) return '<span class="posti posti--libero">' + ic('person') + '<span>Ingresso libero con il pass</span></span>';
    if (p.nd) return '<span class="posti posti--nd">' + ic('info') + '<span>Posti: dato non ancora disponibile</span></span>';
    if (p.res === 0) return '<span class="posti posti--esauriti">' + ic('alert') + '<span>Posti esauriti</span></span>';
    if (p.res <= 10) return '<span class="posti posti--pochi">' + ic('alert') + '<span>Ultimi ' + p.res + ' posti su ' + p.tot + '</span></span>';
    return '<span class="posti">' + ic('person') + '<span>Restano ' + p.res + ' posti su ' + p.tot + '</span></span>';
  }
  function sessioneRiga(s, o) {
    o = o || {};
    var g = giorno(s.g), H = o.h || 'h3', html;
    html = '<article class="sessione' + (o.giorno ? ' sessione--giorno' : '') + '" aria-labelledby="t-' + s.id + '">' +
      '<p class="sessione__ora">' + (o.giorno ? '<small>' + g.breve + '</small>' : '') + '<time>' + s.da + '</time><small>fino alle ' + s.a + '</small></p>' +
      '<div class="sessione__corpo">' +
      '<' + H + ' class="sessione__titolo" id="t-' + s.id + '"><a href="sessione.html?id=' + s.id + '">' + s.titolo + '</a></' + H + '>' +
      '<p class="sessione__meta"><span>' + ic('pin') + ' ' + sala(s.sala).nome + '</span><span>' + DATA.tipi[s.tipo] + '</span><span>' + nomiSpeaker(s) + '</span></p>' +
      '<p class="sessione__tag">' + chipTraccia(s.t) + badgeAccesso(s) + postiHTML(s) + '</p>';
    if (s.pagamento) html += '<p>' + prezzoHTML(s.pagamento, { inline: true }) + '</p>';
    if (s.subs.length) {
      html += '<details class="acc"><summary>' + s.subs.length + ' interventi in questa sessione' + ic('chev') + '</summary><div class="acc__corpo"><ol class="sub">';
      s.subs.forEach(function (x) { html += '<li><time>' + x.ora + '</time><span><b>' + x.titolo + '</b>' + x.chi + '</span></li>'; });
      html += '</ol></div></details>';
    }
    return html + '</div></article>';
  }
  function speakerCard(p) {
    return '<a class="speaker-card" href="speaker-dettaglio.html?id=' + p.id + '">' +
      '<span class="ritratto" data-tono="' + p.tono + '" aria-hidden="true">' + iniziali(p.nome) + '</span>' +
      '<span><span class="speaker-card__nome">' + p.nome + '</span><span class="speaker-card__ruolo">' + p.ruolo + ', ' + p.ente + '</span></span></a>';
  }

  /* ------------------------------------------------------------------ icone, barra prototipo, ruolo */
  var SPRITE = '<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>' +
    '<symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15" rx="1.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></symbol>' +
    '<symbol id="i-person" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c.8-4 3.600-6 7-6s6.200 2 7 6"/></symbol>' +
    '<symbol id="i-ticket" viewBox="0 0 24 24"><path d="M3.500 7.500h17v3a1.500 1.500 0 0 0 0 3v3h-17v-3a1.500 1.500 0 0 0 0-3z"/><path d="M14.500 7.500v9" stroke-dasharray="1.500 2"/></symbol>' +
    '<symbol id="i-card" viewBox="0 0 24 24"><rect x="3" y="5.500" width="18" height="13" rx="1.500"/><circle cx="9" cy="11" r="2"/><path d="M6.500 16c.5-1.600 1.500-2.300 2.500-2.300s2 .7 2.500 2.300M14 10h4M14 13h3"/></symbol>' +
    '<symbol id="i-check" viewBox="0 0 24 24"><path d="M5 12.500l4.500 4.500L19 7.500"/></symbol>' +
    '<symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>' +
    '<symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.500"/><path d="M12 7.500V12l3 2"/></symbol>' +
    '<symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 21s6.500-5.700 6.500-11a6.500 6.500 0 0 0-13 0c0 5.300 6.500 11 6.500 11z"/><circle cx="12" cy="10" r="2.300"/></symbol>' +
    '<symbol id="i-chev" viewBox="0 0 24 24"><path d="M6 9.500l6 6 6-6"/></symbol>' +
    '<symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.500"/><path d="M12 11v5.500M12 7.800v.2"/></symbol>' +
    '<symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 4l9 15.500H3z"/><path d="M12 10v4.500M12 17v.2"/></symbol>' +
    '<symbol id="i-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>' +
    '<symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="10.500" width="14" height="9.500" rx="1.500"/><path d="M8 10.500V8a4 4 0 0 1 8 0v2.500"/></symbol>' +
    '<symbol id="i-grid" viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7"/><rect x="13" y="4" width="7" height="7"/><rect x="4" y="13" width="7" height="7"/><rect x="13" y="13" width="7" height="7"/></symbol>' +
    '<symbol id="i-list" viewBox="0 0 24 24"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/></symbol>' +
    '<symbol id="i-grip" viewBox="0 0 24 24"><path d="M9 6h.01M15 6h.01M9 12h.01M15 12h.01M9 18h.01M15 18h.01" stroke-width="3"/></symbol>' +
    '<symbol id="i-up" viewBox="0 0 24 24"><path d="M12 19V5M6 11l6-6 6 6"/></symbol>' +
    '<symbol id="i-down" viewBox="0 0 24 24"><path d="M12 5v14M6 13l6 6 6-6"/></symbol>' +
    '<symbol id="i-download" viewBox="0 0 24 24"><path d="M12 4v11M7 11l5 5 5-5M5 20h14"/></symbol>' +
    '<symbol id="i-wallet" viewBox="0 0 24 24"><rect x="3.500" y="6" width="17" height="13" rx="1.500"/><path d="M3.500 10h17M16 14.500h.01"/></symbol>' +
    '<symbol id="i-camera" viewBox="0 0 24 24"><path d="M4 8h3l1.500-2h7L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.500"/></symbol>' +
    '<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.500"/><path d="M16 16l4.500 4.500"/></symbol>' +
    '<symbol id="i-refresh" viewBox="0 0 24 24"><path d="M19 12a7 7 0 1 1-2.200-5.100M19 5v4.500h-4.500"/></symbol>' +
    '<symbol id="i-external" viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-9 9M18 14v5H5V6h5"/></symbol>' +
    '<symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>' +
    '</defs></svg>';

  // QR SEGNAPOSTO: modello deterministico dal testo, NON e' leggibile da un lettore. Sempre nero su bianco.
  function qrSvg(seme, etichetta) {
    var n = 25, x = 0, i, j, h = 2166136261;
    for (i = 0; i < seme.length; i++) { h ^= seme.charCodeAt(i); h = Math.imul(h, 16777619) >>> 0; }
    function rnd() { h ^= h << 13; h >>>= 0; h ^= h >>> 17; h ^= h << 5; h >>>= 0; return h / 4294967296; }
    function riservata(a, b) { return (a < 8 && b < 8) || (a > n - 9 && b < 8) || (a < 8 && b > n - 9); }
    function finder(a, b) { // 7x7: bordo nero, anello bianco, centro nero
      var oa = a > n - 9 ? a - (n - 7) : a, ob = b > n - 9 ? b - (n - 7) : b;
      if (oa > 6 || ob > 6) return false;
      var m = Math.max(Math.abs(oa - 3), Math.abs(ob - 3));
      return m === 3 || m <= 1;
    }
    var p = '';
    for (i = 0; i < n; i++) for (j = 0; j < n; j++) {
      var nero = riservata(i, j) ? finder(i, j) : rnd() > 0.52;
      if (nero) p += 'M' + (j + 2) + ' ' + (i + 2) + 'h1v1h-1z';
    }
    return '<svg viewBox="0 0 ' + (n + 4) + ' ' + (n + 4) + '" role="img" aria-label="' + etichetta + '"><rect width="' + (n + 4) + '" height="' + (n + 4) + '" fill="#fff"/><path d="' + p + '" fill="#000"/></svg>';
  }

  // Scrive un avviso in un contenitore con aria-live (stato o errore) senza spostare il fuoco.
  function msg(el, tipo, testo) {
    el.setAttribute('aria-live', tipo === 'err' ? 'assertive' : 'polite');
    el.innerHTML = '<div class="avviso avviso--' + tipo + '">' + ic(tipo === 'ok' ? 'check' : (tipo === 'info' ? 'info' : 'alert')) + '<div>' + testo + '</div></div>';
  }

  function barra() {
    var area = d.body.getAttribute('data-area');   // 'admin' e 'checkin': niente selettore del ruolo
    var opz = Object.keys(ETICHETTA).map(function (k) { return '<option value="' + k + '">' + ETICHETTA[k] + '</option>'; }).join('');
    var ruolo = area ? '' : '<div class="proto-bar__controlli"><label for="proto-ruolo">Vedi come</label>' +
      '<select id="proto-ruolo" class="select">' + opz + '</select>' +
      '<label class="scelta scelta--inline"><input type="checkbox" id="proto-pass"> <span>Ho già un pass</span></label></div>';
    var h = SPRITE + '<details class="proto-bar"><summary>Prototipo' + (area ? '' : ' · Vedi come: <b data-ruolo-label>Visitatore</b>') +
      '<svg class="ic" aria-hidden="true"><use href="#i-chev"></use></svg></summary><div class="proto-bar__corpo">' +
      '<p>Persone, luoghi, prezzi e date sono inventati.</p>' + ruolo +
      '<nav class="proto-bar__nav" aria-label="Aree del prototipo"><a href="' + BASE + 'index.html">Sito pubblico</a><a href="' + BASE + 'accesso.html">Accesso</a><a href="' + BASE + 'account.html">Area personale</a><a href="' + BASE + 'admin/index.html">Admin</a><a href="' + BASE + 'checkin.html">Check-in</a></nav></div></details>';
    var skip = qs('.skip-link');
    if (skip) skip.insertAdjacentHTML('afterend', h); else d.body.insertAdjacentHTML('afterbegin', h);
    if (!area) {
      qs('#proto-ruolo').value = stato.ruolo;
      qs('#proto-pass').checked = stato.pass;
      qs('#proto-ruolo').addEventListener('change', function (e) { setStato({ ruolo: e.target.value }); });
      qs('#proto-pass').addEventListener('change', function (e) { setStato({ pass: e.target.checked }); });
    }
  }

  // Mostra/nasconde gli elementi con data-ruoli / data-pass e riempie i segnaposto di testo e prezzo.
  function applica() {
    var sel = qs('#proto-ruolo'), pas = qs('#proto-pass');
    if (sel) sel.value = stato.ruolo;
    if (pas) { pas.checked = stato.pass; pas.disabled = stato.ruolo === 'visitatore'; }
    qa('[data-ruoli]').forEach(function (el) {
      var ok = el.getAttribute('data-ruoli').split(',').indexOf(stato.ruolo) > -1;
      var p = el.getAttribute('data-pass');
      if (p) ok = ok && ((p === 'si') === stato.pass);
      el.hidden = !ok;
    });
    qa('[data-solo-pass]').forEach(function (el) { el.hidden = (el.getAttribute('data-solo-pass') === 'si') !== stato.pass; });
    qa('[data-ruolo-label]').forEach(function (el) { el.textContent = BREVE[stato.ruolo]; el.setAttribute('data-ruolo', stato.ruolo); });
    qa('[data-prezzo]').forEach(function (el) {
      var v = el.getAttribute('data-prezzo').split(',');
      el.innerHTML = prezzoHTML({ pub: +v[0], mem: +v[1] }, { inline: el.getAttribute('data-var') === 'inline' });
    });
    // prezzi dei pass presi dalla fascia aperta oggi (mai scritti a mano nelle pagine)
    qa('[data-prezzo-tipo]').forEach(function (el) {
      el.innerHTML = prezzoHTML(DATA.prezzi[el.getAttribute('data-prezzo-tipo')], { inline: el.getAttribute('data-var') === 'inline' });
    });
    qa('[data-fascia]').forEach(function (el) { el.innerHTML = fasciaHTML(el.getAttribute('data-fascia'), el.hasAttribute('data-con-nome')); });
    qa('[data-euro]').forEach(function (el) {
      var v = el.getAttribute('data-euro').split('.'), x = DATA.prezzi[v[0]];
      if (typeof x === 'object') x = x[v[1]];
      if (typeof x === 'number') el.textContent = euro(x);
    });
    qa('[data-risparmio]').forEach(function (el) {
      var p = DATA.prezzi[el.getAttribute('data-risparmio')];
      el.textContent = euro(p.pub - p.mem);
    });
    qa('[data-colonna-tua]').forEach(function (el) { el.classList.toggle('is-tuo', CHIAVE[stato.ruolo] === el.getAttribute('data-colonna-tua')); });
  }

  w.CV = {
    DATA: DATA, stato: stato, ETICHETTA: ETICHETTA, CHIAVE: CHIAVE,
    qs: qs, qa: qa, param: param, euro: euro, ic: ic, ready: ready, setStato: setStato, applica: applica,
    giorno: giorno, sala: sala, traccia: traccia, persona: persona, sessione: sessione,
    nomiSpeaker: nomiSpeaker, iniziali: iniziali, min: min, sovrapposte: sovrapposte, prezzoMio: prezzoMio,
    prezzoHTML: prezzoHTML, fasciaHTML: fasciaHTML, fasciaAperta: fasciaAperta, dataIt: dataIt, badgeAccesso: badgeAccesso, chipTraccia: chipTraccia, postiHTML: postiHTML,
    sessioneRiga: sessioneRiga, speakerCard: speakerCard, qrSvg: qrSvg, msg: msg, BASE: BASE, BREVE: BREVE
  };

  ready(function () { barra(); applica(); });
}(window, document));
