/*
  Autore: Giulio Occhipinti
  EAS8 Lisbon - codice condiviso del prototipo.
  Contiene: dati (evento, prezzi e capienza veri dal modulo del cliente del 06/11/2026; speaker e sessioni
  inventati, "segnaposto"), stato "vedi come" (ruolo e pass, salvato nel browser), componente prezzo per ruolo,
  intestazione con selettore IT/EN finto, footer, icone, funzioni di disegno riusate dalle pagine.
  JavaScript semplice, nessuna libreria, nessuna build. Nel sito vero (Laravel + Filament) questi dati vengono dal
  database, il ruolo dall'account, header e footer sono parti Blade.
*/
(function (w, d) {
  'use strict';

  /* ------------------------------------------------------------------ dati */
  // VERI (modulo del cliente, risposte del 06/11/2026): nome, date pubbliche, citta', posti, prezzi, early bird, membership.
  // INVENTATI (segnaposto): sede, speaker, sessioni, orari, capienze delle sale, tracce.
  function ws() { return { pub: 100, mem: 100 }; }   // workshop 100 €: stesso prezzo per il membro finche' il cliente non ne dice uno diverso
  var DATA = {
    evento: {
      nome: 'EAS8 Lisbon', citta: 'Lisbona', date: '8–10 aprile 2027', posti: 6000,
      sede: 'Sede da confermare', societa: 'European Aligner Society',
      tagline: 'Mastering Clinical Challenges in Aligner Orthodontics', tagline2: 'From Clinical Limitations to Predictable Outcomes'
    },
    giorni: [
      { id: 'g1', breve: 'Gio 8', lungo: 'Giovedì 8 aprile' },
      { id: 'g2', breve: 'Ven 9', lungo: 'Venerdì 9 aprile' },
      { id: 'g3', breve: 'Sab 10', lungo: 'Sabato 10 aprile' }
    ],
    sale: [
      { id: 'grande', nome: 'Sala Plenaria', nota: 'plenaria' },
      { id: 'lanterna', nome: 'Sala Clinica', nota: 'panel e talk' },
      { id: 'officina', nome: 'Sala Workshop', nota: 'workshop' },
      { id: 'studio', nome: 'Sala Digital', nota: 'workshop' }
    ],
    tracce: [
      { id: 't1', nome: 'Pianificazione digitale' },
      { id: 't2', nome: 'Biomeccanica e movimenti' },
      { id: 't3', nome: 'Casi clinici complessi' },
      { id: 't4', nome: 'Tecnologia e IA' }
    ],
    tipi: { keynote: 'Keynote', panel: 'Panel', talk: 'Talk', workshop: 'Workshop' },
    oggi: '2026-11-06',   // data simulata del prototipo (oggi): decide quale fascia di vendita e' aperta
    // Fasce di vendita del pass intero: unica fonte, usata dal sito e dall'admin. Il pass di un solo giorno NON e' offerto (cliente: «non indicato»).
    // Early bird -30%: 695 x 0,70 = 486,50 · 395 x 0,70 = 276,50. Date della fascia: ESEMPIO (fine 31/01/2027), da confermare.
    fasce: [
      { id: 'f1', nome: 'Early bird', tipo: 'intero', dal: '2026-11-01', al: '2027-01-31', pub: 486.5, mem: 276.5, sconto: 30, esempio: true },
      { id: 'f2', nome: 'Standard', tipo: 'intero', dal: '2027-02-01', al: '2027-04-10', pub: 695, mem: 395 }
    ],
    prezzi: {
      intero: { pub: 0, mem: 0 },        // prezzi della fascia aperta oggi: calcolati dai dati subito sotto
      workshop: { pub: 0, mem: 0 },      // "da": calcolato dai dati subito sotto
      membership: 200                    // 200 €/anno
    },
    vantaggiMembership: ['Accesso early bird', 'Member area con i video dei congressi passati'],
    speakers: [
      { id: 'sp1', nome: 'Anna Esempio', cognome: 'Esempio', ruolo: 'Ortodontista', ente: 'Studio Segnaposto', tono: 1,
        bio: ['Si occupa di pianificazione digitale dei trattamenti con allineatori e di casi con movimenti complessi.', 'Biografia segnaposto, inventata per il prototipo.'] },
      { id: 'sp2', nome: 'Bruno Modello', cognome: 'Modello', ruolo: 'Docente di ortodonzia', ente: 'Università di Esempia', tono: 2,
        bio: ['Insegna biomeccanica e ancoraggio. Lavora su casi con estrazioni e su movimenti difficili con allineatori.', 'Biografia segnaposto, inventata per il prototipo.'] },
      { id: 'sp3', nome: 'Chiara Prova', cognome: 'Prova', ruolo: 'Ortodontista e formatrice', ente: 'Centro Formazione Segnaposto', tono: 3,
        bio: ['Tiene corsi pratici su attachments, pianificazione e gestione del flusso clinico.', 'Biografia segnaposto, inventata per il prototipo.'] },
      { id: 'sp4', nome: 'Dario Bozza', cognome: 'Bozza', ruolo: 'Responsabile pianificazione digitale', ente: 'Laboratorio Digitale Segnaposto', tono: 1,
        bio: ['Segue il flusso digitale dalla scansione al piano di trattamento e alla verifica dei risultati.', 'Biografia segnaposto, inventata per il prototipo.'] },
      { id: 'sp5', nome: 'Elena Campione', cognome: 'Campione', ruolo: 'Ricercatrice in biomeccanica', ente: 'Istituto di Esempia', tono: 4,
        bio: ['Studia le forze applicate dagli allineatori e la loro prevedibilità clinica.', 'Biografia segnaposto, inventata per il prototipo.'] },
      { id: 'sp6', nome: 'Franco Segnale', cognome: 'Segnale', ruolo: 'Ingegnere clinico', ente: 'Centro Ricerche Segnaposto', tono: 4,
        bio: ['Lavora su modelli di intelligenza artificiale per la pianificazione e il controllo dei casi.', 'Biografia segnaposto, inventata per il prototipo.'] },
      { id: 'sp7', nome: 'Gaia Inesistente', cognome: 'Inesistente', ruolo: 'Ortodontista', ente: 'Clinica Segnaposto', tono: 2,
        bio: ['Integra la scansione intraorale e il flusso digitale nella pratica quotidiana dello studio.', 'Biografia segnaposto, inventata per il prototipo.'] },
      { id: 'sp8', nome: 'Luca Ipotetico', cognome: 'Ipotetico', ruolo: 'Ortodontista, libera professione', ente: 'Studio Ipotetico', tono: 3,
        bio: ['Si occupa di contenzione e stabilità a lungo termine dopo il trattamento con allineatori.', 'Biografia segnaposto, inventata per il prototipo.'] },
      { id: 'sp9', nome: 'Nadia Fantasia', cognome: 'Fantasia', ruolo: 'Presidente di una società scientifica', ente: 'Associazione di Esempia', tono: 3,
        bio: ['Coordina gruppi di lavoro sulla formazione e sulle linee guida cliniche.', 'Biografia segnaposto, inventata per il prototipo.'] },
      { id: 'sp10', nome: 'Paolo Sintetico', cognome: 'Sintetico', ruolo: 'Specialista in casi complessi', ente: 'Ospedale di Esempia', tono: 4,
        bio: ['Segue casi di recidiva, ritrattamento e trattamenti multidisciplinari.', 'Biografia segnaposto, inventata per il prototipo.'] }
    ],
    sessioni: [
      { id: 's01', g: 'g1', da: '09:30', a: '10:30', sala: 'grande', t: 't1', tipo: 'keynote', titolo: 'Dal piano digitale al risultato prevedibile', sp: ['sp1'],
        desc: 'Apertura del congresso (sessione segnaposto). Un percorso dal piano digitale al risultato clinico: che cosa si può prevedere con gli allineatori e che cosa no.',
        posti: { libero: true }, subs: [] },
      { id: 's02', g: 'g1', da: '11:00', a: '12:30', sala: 'lanterna', t: 't2', tipo: 'panel', titolo: 'Movimenti difficili: quando l’allineatore non basta', sp: ['sp2', 'sp5', 'sp4'],
        desc: 'Panel segnaposto su rotazioni, estrusioni e movimenti radicolari: limiti clinici, ausiliari e strategie. Con discussione finale.',
        posti: { tot: 600, res: 214 },
        subs: [ { ora: '11:00', titolo: 'Apertura e presentazione dei casi', chi: 'Bruno Modello' },
                { ora: '11:15', titolo: 'Forze e prevedibilità dei movimenti', chi: 'Elena Campione' },
                { ora: '11:40', titolo: 'Dal limite clinico alla strategia', chi: 'Dario Bozza' },
                { ora: '12:05', titolo: 'Discussione con il pubblico', chi: 'Tutti i relatori' } ] },
      { id: 's03', g: 'g1', da: '11:00', a: '13:00', sala: 'officina', t: 't3', tipo: 'workshop', titolo: 'Attachments e biomeccanica: pianificare in due ore', sp: ['sp3'], pagamento: ws(),
        desc: 'Laboratorio pratico segnaposto: si parte da un caso reale anonimizzato e si arriva a un piano con attachments. Portare un portatile.',
        posti: { tot: 40, res: 9 },
        subs: [ { ora: '11:00', titolo: 'Leggere il caso', chi: 'Chiara Prova' },
                { ora: '11:45', titolo: 'Pianificare in gruppo', chi: 'Chiara Prova' },
                { ora: '12:30', titolo: 'Restituzione e confronto', chi: 'Chiara Prova' } ] },
      { id: 's04', g: 'g1', da: '14:30', a: '15:30', sala: 'grande', t: 't4', tipo: 'talk', titolo: 'Che cosa sa davvero l’IA della pianificazione dei casi', sp: ['sp6'],
        desc: 'Esempi segnaposto di errori, limiti e punti di forza dell’intelligenza artificiale nella pianificazione con allineatori.',
        posti: { libero: true }, subs: [] },
      { id: 's05', g: 'g1', da: '15:00', a: '17:00', sala: 'studio', t: 't2', tipo: 'workshop', titolo: 'Ancoraggio e mini-impianti: laboratorio pratico', sp: ['sp2'], pagamento: ws(),
        desc: 'Workshop segnaposto su ancoraggio scheletrico abbinato agli allineatori: indicazioni, pianificazione, gestione.',
        posti: { tot: 30, res: 0 }, subs: [] },
      { id: 's06', g: 'g1', da: '16:00', a: '17:30', sala: 'lanterna', t: 't1', tipo: 'panel', titolo: 'Pianificazione digitale: chi decide il trattamento', sp: ['sp4', 'sp9'],
        desc: 'Panel segnaposto sul rapporto tra clinico, software e laboratorio nelle decisioni di trattamento.',
        posti: { nd: true }, subs: [] },
      { id: 's07', g: 'g2', da: '09:30', a: '10:30', sala: 'grande', t: 't3', tipo: 'keynote', titolo: 'Casi complessi: dai limiti clinici agli esiti prevedibili', sp: ['sp9'],
        desc: 'Keynote segnaposto sul percorso dai limiti clinici di un caso a un piano con esiti prevedibili.',
        posti: { libero: true }, subs: [] },
      { id: 's08', g: 'g2', da: '11:00', a: '12:30', sala: 'lanterna', t: 't4', tipo: 'talk', titolo: 'Tre approcci alla gestione dello spazio', sp: ['sp5', 'sp10', 'sp1'],
        desc: 'Tre colleghi (segnaposto) mostrano come gestiscono lo spazio: stripping, espansione, distalizzazione.',
        posti: { tot: 600, res: 38 },
        subs: [ { ora: '11:00', titolo: 'Stripping: quando e quanto', chi: 'Elena Campione' },
                { ora: '11:30', titolo: 'Distalizzazione con allineatori', chi: 'Paolo Sintetico' },
                { ora: '12:00', titolo: 'Spazio e piano digitale', chi: 'Anna Esempio' } ] },
      { id: 's09', g: 'g2', da: '11:00', a: '13:00', sala: 'officina', t: 't4', tipo: 'workshop', titolo: 'Flusso digitale in studio: dalla scansione al piano', sp: ['sp7'], pagamento: ws(),
        desc: 'Workshop segnaposto: dalla scansione intraorale al piano di trattamento. Servono un portatile e un browser aggiornato.',
        posti: { tot: 40, res: 18 }, subs: [] },
      { id: 's10', g: 'g2', da: '14:30', a: '16:30', sala: 'officina', t: 't3', tipo: 'workshop', titolo: 'Recidiva e ritrattamento: casi dal vivo', sp: ['sp10'], pagamento: ws(),
        desc: 'Workshop segnaposto su casi di recidiva e ritrattamento con allineatori, a partire da casi anonimizzati.',
        posti: { tot: 40, res: 22 }, subs: [] },
      { id: 's11', g: 'g2', da: '14:30', a: '16:00', sala: 'grande', t: 't4', tipo: 'panel', titolo: 'Intelligenza artificiale e responsabilità clinica', sp: ['sp6', 'sp7'],
        desc: 'Panel segnaposto su chi risponde delle decisioni prese con il supporto di software e modelli di IA.',
        posti: { libero: true }, subs: [] },
      { id: 's12', g: 'g2', da: '16:30', a: '17:30', sala: 'studio', t: 't2', tipo: 'talk', titolo: 'Contenzione e stabilità a lungo termine', sp: ['sp8'],
        desc: 'Talk segnaposto sulla stabilità dei risultati dopo gli allineatori: protocolli di contenzione e controlli.',
        posti: { tot: 80, res: 80 }, subs: [] },
      { id: 's13', g: 'g3', da: '10:00', a: '11:00', sala: 'grande', t: 't1', tipo: 'keynote', titolo: 'Il futuro dell’ortodonzia con allineatori', sp: ['sp4'],
        desc: 'Keynote segnaposto: tecnologie e metodi che cambieranno la pianificazione nei prossimi anni.',
        posti: { libero: true }, subs: [] },
      { id: 's14', g: 'g3', da: '11:30', a: '13:00', sala: 'lanterna', t: 't2', tipo: 'panel', titolo: 'Evidenze cliniche: che cosa dice la letteratura', sp: ['sp2', 'sp6', 'sp8'],
        desc: 'Panel segnaposto sulle evidenze disponibili sull’efficacia e sui limiti degli allineatori.',
        posti: { tot: 600, res: 301 }, subs: [] },
      { id: 's15', g: 'g3', da: '11:30', a: '13:30', sala: 'officina', t: 't1', tipo: 'workshop', titolo: 'Pianificare un caso difficile con il software', sp: ['sp3', 'sp4'], pagamento: ws(),
        desc: 'Workshop segnaposto: si porta un caso difficile e si costruisce il piano con il software, passo per passo.',
        posti: { tot: 40, res: 5 },
        subs: [ { ora: '11:30', titolo: 'Preparazione e consegne', chi: 'Chiara Prova' },
                { ora: '12:00', titolo: 'Costruire il piano', chi: 'Dario Bozza' },
                { ora: '13:00', titolo: 'Confronto sui piani', chi: 'Chiara Prova, Dario Bozza' } ] },
      { id: 's16', g: 'g3', da: '15:00', a: '16:30', sala: 'grande', t: 't3', tipo: 'keynote', titolo: 'Chiusura e appuntamento al prossimo congresso', sp: ['sp1', 'sp9'],
        desc: 'Sintesi dei tre giorni e annuncio della prossima edizione (segnaposto).',
        posti: { libero: true }, subs: [] }
    ],
    // Member area: video dei congressi passati. TUTTI segnaposto: titoli, anni e durate sono inventati.
    video: [
      { id: 'v1', titolo: 'Plenaria di apertura', edizione: 'Congresso precedente · segnaposto', durata: '48 min' },
      { id: 'v2', titolo: 'Gestione dello spazio con gli allineatori', edizione: 'Congresso precedente · segnaposto', durata: '35 min' },
      { id: 'v3', titolo: 'Casi clinici difficili: tavola rotonda', edizione: 'Congresso precedente · segnaposto', durata: '62 min' },
      { id: 'v4', titolo: 'Pianificazione digitale: dal flusso al piano', edizione: 'Congresso precedente · segnaposto', durata: '41 min' }
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
  ['intero'].forEach(function (t) {
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
  // 486,50 € ma 695 €: i decimali compaiono solo quando servono
  function euro(n) { var dec = Math.round(n) === n ? 0 : 2; return n.toLocaleString('it-IT', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + ' €'; }
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
  // Se pubblico e membro costano uguale (workshop per ora) si dichiara un prezzo unico, senza barrati finti.
  function prezzoHTML(p, o) {
    o = o || {};
    var k = CHIAVE[stato.ruolo], r = stato.ruolo, altri, html;
    if (p.pub === p.mem) {
      if (o.inline) return '<span class="prezzi prezzi--inline"><b class="prezzo-valore">' + euro(p.pub) + '</b> <span class="prezzo-barrato">prezzo unico: oggi uguale per pubblico e membro</span></span>';
      return '<div class="prezzi"><p><span class="prezzo-valore prezzo-valore--grande">' + euro(p.pub) + '</span></p>' +
        '<p class="prezzo-altri">Prezzo unico: oggi è uguale per pubblico e membro. Un prezzo membro diverso è da definire con l’organizzatore.</p></div>';
    }
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
    var f = DATA.prezzi[tipo].fascia, n = f && fasciaDopo(f), nome = conNome ? { intero: 'Pass intero' }[tipo] + ': ' : '';
    if (!f) return '<span class="t-small t-muted fascia-nota">' + nome + (conNome ? 'o' : 'O') + 'ggi nessuna fascia di vendita è aperta: il pass non si può comprare.</span>';
    return '<span class="t-small t-muted fascia-nota">' + nome + '<b>' + (conNome ? 'fascia' : 'Fascia') + ' «' + f.nome + (f.sconto ? ' −' + f.sconto + '%' : '') + '» fino al ' + dataIt(f.al) + (f.esempio ? ' (data di esempio)' : '') + '</b>' +
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
      '<span><span class="speaker-card__nome">' + p.nome + '</span><span class="speaker-card__ruolo">' + p.ruolo + ', ' + p.ente + '</span><span class="speaker-card__ruolo"><i>Profilo segnaposto</i></span></span></a>';
  }

  /* ------------------------------------------------------------------ icone, barra prototipo, intestazione, footer */
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
    '<symbol id="i-play" viewBox="0 0 24 24"><path d="M8 5.500v13l11-6.500z"/></symbol>' +
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
    // la nota sulle date sta SOLO qui: il sito pubblico dice 8–10 aprile, il modulo del cliente 10-14 aprile
    var h = SPRITE + '<details class="proto-bar"><summary><span>Prototipo · date da confermare' + (area ? '' : ' · <b data-ruolo-label>Visitatore</b>') + '</span>' +
      '<svg class="ic" aria-hidden="true"><use href="#i-chev"></use></svg></summary><div class="proto-bar__corpo">' +
      '<p><b>Date da confermare:</b> il modulo del cliente dice 10-14 aprile 2027, il sito pubblico eas-aligners.com dice 8–10 aprile 2027 (usate qui).</p>' +
      '<p>Dati veri (06/11/2026): nome, città, posti, prezzi, membership. Segnaposto: sede, speaker, sessioni, orari, video, vendite e ordini.</p>' + ruolo +
      '<nav class="proto-bar__nav" aria-label="Aree del prototipo"><a href="' + BASE + 'index.html">Sito pubblico</a><a href="' + BASE + 'accesso.html">Accesso</a><a href="' + BASE + 'account.html">Area personale</a><a href="' + BASE + 'admin/index.html">Admin</a><a href="' + BASE + 'checkin.html">Check-in (riferimento)</a></nav></div></details>';
    var skip = qs('.skip-link');
    if (skip) skip.insertAdjacentHTML('afterend', h); else d.body.insertAdjacentHTML('afterbegin', h);
    if (!area) {
      qs('#proto-ruolo').value = stato.ruolo;
      qs('#proto-pass').checked = stato.pass;
      qs('#proto-ruolo').addEventListener('change', function (e) { setStato({ ruolo: e.target.value }); });
      qs('#proto-pass').addEventListener('change', function (e) { setStato({ pass: e.target.checked }); });
    }
  }

  // Selettore IT/EN FINTO nell'intestazione pubblica: cambia solo l'attributo lang e mostra un avviso.
  // (lang="en" su testo italiano e' scorretto per i lettori di schermo: vale solo per il prototipo.)
  function lingua() {
    var dx = qs('.site-header__destra'), hd = qs('.site-header');
    if (!dx || !hd || qs('#lang')) return;
    dx.insertAdjacentHTML('afterbegin', '<label class="sr-only" for="lang">Lingua / Language</label>' +
      '<select id="lang" class="select select--lang"><option value="it" selected>IT</option><option value="en">EN</option></select>');
    hd.insertAdjacentHTML('afterend', '<div class="wrap lang-avviso" id="lang-avviso" role="status" aria-live="polite"></div>');
    qs('#lang').addEventListener('change', function (e) {
      d.documentElement.lang = e.target.value;
      var box = qs('#lang-avviso');
      if (e.target.value === 'en') {
        box.innerHTML = '<div class="avviso avviso--info">' + ic('info') + '<div><b>Versione inglese simulata.</b> Nel prototipo cambia solo l’attributo <code>lang</code> della pagina: i testi restano in italiano. ' +
          '<span lang="en">English version is simulated: only the page <code>lang</code> attribute changes, the text stays in Italian.</span></div></div>';
      } else box.innerHTML = '';
    });
  }

  // Footer scuro come quello di eas-aligners.com: logo, link, contatti, newsletter. Contatti e indirizzo sono quelli pubblici del loro sito.
  // Social omessi: gli indirizzi dei profili vanno chiesti al cliente (niente link inventati).
  function piede() {
    var f = qs('.site-footer');
    if (!f) return;
    f.innerHTML = '<div class="wrap site-footer__griglia">' +
      '<div><a href="' + BASE + 'index.html" aria-label="EAS8 Lisbon, pagina iniziale"><img src="' + BASE + 'assets/eas-logo.png" width="84" height="47" alt="EAS, European Aligner Society"></a></div>' +
      '<div><h2>EAS8 Lisbon</h2><ul><li><a href="' + BASE + 'index.html">Home</a></li><li><a href="' + BASE + 'agenda.html">Programma</a></li><li><a href="' + BASE + 'speaker.html">Speaker</a></li>' +
      '<li><a href="' + BASE + 'biglietti.html">Biglietti</a></li><li><a href="' + BASE + 'membership.html">Membership</a></li></ul></div>' +
      '<div><h2>Contatti</h2><p><a href="mailto:office@eas-aligners.com">office@eas-aligners.com</a></p>' +
      '<p>European Aligner Society<br>La Réserve – 2525 Route de Bandol<br>83110 Sanary-sur-Mer, France</p></div>' +
      '<div><h2>Resta aggiornato</h2><form id="news" novalidate><div class="campo"><label for="news-email">La tua email</label>' +
      '<input class="input" id="news-email" type="email" autocomplete="email" aria-describedby="news-aiuto"><p class="campo__aiuto" id="news-aiuto">Newsletter: iscrizione simulata, nessun dato viene inviato.</p></div>' +
      '<p style="margin-top:var(--s-2)"><button type="submit" class="btn btn--primary">Iscriviti</button></p><div id="news-esito" role="status" aria-live="polite"></div></form></div></div>' +
      '<div class="wrap site-footer__sotto"><p>© 2026 EAS – European Aligner Society. Tutti i diritti riservati.</p>' +
      '<p>Privacy e cookie: pagine in preparazione. Prototipo: speaker, sessioni e disponibilità sono segnaposto.</p></div>';
    qs('#news').addEventListener('submit', function (e) {
      e.preventDefault();
      var v = qs('#news-email').value.trim();
      if (!/^\S+@\S+\.\S+$/.test(v)) { msg(qs('#news-esito'), 'err', 'Controlla l’email: manca la chiocciola o il dominio.'); qs('#news-email').focus(); return; }
      msg(qs('#news-esito'), 'ok', 'Iscrizione simulata. Nel sito vero si collega al servizio di newsletter.');
    });
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
    // voce Login / Account nell'intestazione
    qa('.site-header__destra .header-link').forEach(function (a) {
      var v = stato.ruolo === 'visitatore', file = v ? 'accesso.html' : 'account.html';
      a.textContent = v ? 'Login' : 'Account'; a.setAttribute('href', BASE + file);
      if ((w.location.pathname.split('/').pop() || 'index.html') === file) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
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
      var p = DATA.prezzi[el.getAttribute('data-risparmio')], r = p.pub - p.mem;
      el.textContent = r > 0 ? euro(r) : 'nessuno (prezzo unico)';
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

  ready(function () { barra(); lingua(); piede(); applica(); });
}(window, document));
