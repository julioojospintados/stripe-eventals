/*
  Autore: Giulio Occhipinti
  EAS8 Lisbon - codice condiviso del prototipo (testi visibili in inglese, lingua predefinita dal 06/11/2026).
  Contiene: dati (evento, fasce di prezzo, prezzi e capienze veri dal cliente; speaker, sessioni, video e ordini
  inventati, "placeholder"), stato "View as" (ruolo, pass, fascia simulata, lingua: salvato nel browser),
  componente prezzo per ruolo, intestazione, selettore EN/IT, footer, icone, funzioni di disegno riusate dalle pagine.
  JavaScript semplice, nessuna libreria, nessuna build. Nel sito vero (Laravel + Filament) questi dati vengono dal
  database, il ruolo dall'account, header e footer sono parti Blade.
  Le date dell'evento e delle fasce e i prezzi del listino si possono cambiare da admin/biglietti.html: la modifica
  (simulata) resta nel browser in "cv-config" e il sito pubblico la legge, cosi' si vede l'effetto.
*/
(function (w, d) {
  'use strict';

  /* ------------------------------------------------------------------ date (inglese) */
  var MESI = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var SETT = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  function dIso(iso) { var p = iso.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])); }
  function iso(dt) { return dt.toISOString().slice(0, 10); }
  function piuGiorni(s, n) { var x = dIso(s); x.setUTCDate(x.getUTCDate() + n); return iso(x); }
  function giorniTra(a, b) { return Math.round((dIso(b) - dIso(a)) / 864e5); }
  // 31 Jan 2027 (breve) o 31 January 2027
  function dataEn(s, lungo) { if (!s) return '—'; var x = dIso(s); return x.getUTCDate() + ' ' + (lungo ? MESI[x.getUTCMonth()] : MESI[x.getUTCMonth()].slice(0, 3)) + ' ' + x.getUTCFullYear(); }
  function intervallo(a, b) {
    var x = dIso(a), y = dIso(b);
    if (a === b) return dataEn(a, true);
    if (x.getUTCFullYear() === y.getUTCFullYear() && x.getUTCMonth() === y.getUTCMonth()) return x.getUTCDate() + '–' + y.getUTCDate() + ' ' + MESI[x.getUTCMonth()] + ' ' + x.getUTCFullYear();
    return x.getUTCDate() + ' ' + MESI[x.getUTCMonth()] + ' – ' + dataEn(b, true);
  }

  /* ------------------------------------------------------------------ dati */
  // VERI (cliente, risposte del 06/11/2026): nome, date 8-10 aprile 2027, citta', posti, fasce (nomi e regole),
  // pass 695 € / 395 €, early bird -30% solo membri (276,50 €), workshop 10 x 20 posti a 100 € per tutti, membership 200 €.
  // ESEMPIO: date di fine delle fasce. SEGNAPOSTO (prezzo da decidere): accompagnatore, espositore/sponsor, last minute.
  // INVENTATI: sede, speaker, sessioni, orari, sale, tracce, video, ordini.
  var DATA = {
    evento: {
      nome: 'EAS8 Lisbon', citta: 'Lisbon', dal: '2027-04-08', al: '2027-04-10', posti: 6000,
      sede: 'Venue to be confirmed', societa: 'European Aligner Society',
      tagline: 'Mastering Clinical Challenges in Aligner Orthodontics', tagline2: 'From Clinical Limitations to Predictable Outcomes'
    },
    oggi: '2026-11-06',   // data del prototipo; la fascia simulata dalla barra prototipo la sostituisce
    fasce: [
      { id: 'early', nome: 'Early bird', dal: '2026-11-06', al: '2027-01-31' },
      { id: 'regular', nome: 'Regular', dal: '2027-02-01', al: '2027-03-21' },
      { id: 'last', nome: 'Last minute', dal: '2027-03-22', al: '2027-04-10' }
    ],
    // listino[tipo][fascia][ruolo]: numero = prezzo; 'reg' = nessuno sconto in questa fascia, vale il prezzo Regular; 'tba' = da annunciare
    listino: {
      intero: { early: { pub: 'reg', mem: 276.5 }, regular: { pub: 695, mem: 395 }, last: { pub: 'tba', mem: 'tba' } },
      companion: { early: { pub: 150, mem: 150 }, regular: { pub: 150, mem: 150 }, last: { pub: 150, mem: 150 } },
      espositore: { early: { pub: 450, mem: 450 }, regular: { pub: 450, mem: 450 }, last: { pub: 450, mem: 450 } },
      workshop: { early: { pub: 100, mem: 100 }, regular: { pub: 100, mem: 100 }, last: { pub: 100, mem: 100 } }
    },
    segnaposto: { companion: true, espositore: true },   // prezzi segnaposto: l'interfaccia lo dichiara sempre
    tipiBiglietto: {
      intero: 'Full pass', companion: 'Companion', espositore: 'Exhibitor / Sponsor pass', workshop: 'Workshop', omaggio: 'Complimentary'
    },
    prezzi: { intero: { pub: 0, mem: 0 }, companion: { pub: 0, mem: 0 }, espositore: { pub: 0, mem: 0 }, workshop: { pub: 100, mem: 100 }, membership: 200 },
    vantaggiMembership: ['Early bird price (−30%), for members only', 'Member area with videos of past congresses'],
    sale: [
      { id: 'aud', nome: 'Auditorium', nota: 'plenary' },
      { id: 'ha', nome: 'Hall A', nota: 'panels' },
      { id: 'hb', nome: 'Hall B', nota: 'panels' },
      { id: 'r1', nome: 'Room 1', nota: 'talks' },
      { id: 'r2', nome: 'Room 2', nota: 'talks' },
      { id: 'r3', nome: 'Room 3', nota: 'talks' },
      { id: 'r4', nome: 'Room 4', nota: 'talks' },
      { id: 'r5', nome: 'Room 5', nota: 'talks' },
      { id: 'r6', nome: 'Room 6', nota: 'talks' },
      { id: 'r7', nome: 'Room 7', nota: 'talks' },
      { id: 'wa', nome: 'Workshop Room A', nota: 'workshops' },
      { id: 'wb', nome: 'Workshop Room B', nota: 'workshops' }
    ],
    tracce: [
      { id: 't1', nome: 'Digital planning' },
      { id: 't2', nome: 'Biomechanics' },
      { id: 't3', nome: 'Complex cases' },
      { id: 't4', nome: 'Technology and AI' }
    ],
    tipi: { keynote: 'Keynote', panel: 'Panel', talk: 'Talk', workshop: 'Workshop' },
    speakers: [],
    sessioni: [],
    // Member area. TUTTI segnaposto: titoli, edizioni, durate e ID sono inventati.
    // ruoli: utente = chiunque abbia un account; membro = membership attiva; pass = chi ha un biglietto EAS8; speaker = speaker e staff
    video: [
      { id: 'v1', titolo: 'Opening plenary', edizione: 'EAS7 · placeholder', durata: '48 min', provider: 'vimeo', vid: '000000001', ruoli: ['membro'] },
      { id: 'v2', titolo: 'Space management with aligners', edizione: 'EAS7 · placeholder', durata: '35 min', provider: 'wistia', vid: 'plh0000002', ruoli: ['membro'] },
      { id: 'v3', titolo: 'Difficult cases: round table', edizione: 'EAS6 · placeholder', durata: '62 min', provider: 'vimeo', vid: '000000003', ruoli: ['membro', 'pass'] },
      { id: 'v4', titolo: 'Digital planning: from workflow to plan', edizione: 'EAS6 · placeholder', durata: '41 min', provider: 'wistia', vid: 'plh0000004', ruoli: ['membro'] },
      { id: 'v5', titolo: 'Welcome to EAS8 Lisbon', edizione: 'EAS8 · placeholder', durata: '4 min', provider: 'vimeo', vid: '000000005', ruoli: ['utente'] },
      { id: 'v6', titolo: 'Speaker briefing: rooms and timing', edizione: 'EAS8 · placeholder', durata: '12 min', provider: 'wistia', vid: 'plh0000006', ruoli: ['speaker'] }
    ],
    videoRuoli: { utente: 'Anyone with an account', membro: 'Active members', pass: 'EAS8 ticket holders', speaker: 'Speakers and staff' },
    provider: { vimeo: 'Vimeo', wistia: 'Wistia' }
  };

  /* ---- speaker segnaposto: 30 nomi evidentemente fittizi ---- */
  (function () {
    var nomi = ['Anna', 'Bruno', 'Chiara', 'Dario', 'Elena', 'Franco', 'Gaia', 'Luca', 'Nadia', 'Paolo', 'Rita', 'Sami', 'Tomas', 'Ula', 'Victor',
      'Wanda', 'Xavier', 'Yara', 'Zoe', 'Hugo', 'Ines', 'Jonas', 'Klara', 'Leon', 'Mara', 'Niko', 'Olga', 'Pedro', 'Quinn', 'Rosa'];
    var cognomi = ['Example', 'Sample', 'Placeholder', 'Mockup', 'Dummy', 'Fictional', 'Template', 'Draft', 'Demo', 'Specimen', 'Prototype', 'Testcase',
      'Lorem', 'Ipsum', 'Default', 'Stub', 'Fixture', 'Variant', 'Pattern', 'Outline', 'Sketch', 'Proxy', 'Standin', 'Decoy', 'Model', 'Replica',
      'Trial', 'Pilot', 'Preview', 'Shadow'];
    var ruoli = ['Orthodontist', 'Professor of orthodontics', 'Orthodontist and trainer', 'Head of digital planning', 'Researcher in biomechanics',
      'Clinical engineer', 'Orthodontist, private practice', 'Specialist in complex cases'];
    var enti = ['Placeholder Clinic', 'University of Exampleton', 'Sample Training Centre', 'Demo Digital Lab', 'Institute of Examples', 'Placeholder Research Centre'];
    var temi = ['digital treatment planning with aligners', 'biomechanics and anchorage', 'attachments and clinical workflow', 'scanning and the digital workflow',
      'forces and predictability of tooth movement', 'AI models for case planning', 'retention and long-term stability', 'relapse and retreatment'];
    for (var i = 0; i < 30; i++) {
      DATA.speakers.push({ id: 'sp' + (i + 1), nome: nomi[i] + ' ' + cognomi[i], cognome: cognomi[i], ruolo: ruoli[i % ruoli.length], ente: enti[i % enti.length], tono: (i % 4) + 1,
        bio: ['Works on ' + temi[i % temi.length] + '.', 'Placeholder biography, invented for the prototype.'] });
    }
  }());

  /* ---- programma segnaposto: 12 sale, 3 giorni, 120 sessioni, 10 workshop da 20 posti ---- */
  (function () {
    var SLOT = [['09:00', '10:30'], ['11:00', '12:30'], ['14:00', '15:30'], ['16:00', '17:30']];
    var SOGG = [['Deep bite', 't2'], ['Open bite', 't2'], ['Class II correction', 't2'], ['Class III camouflage', 't3'], ['Molar distalisation', 't2'],
      ['Rotations', 't2'], ['Torque control', 't2'], ['Extraction cases', 't3'], ['Interproximal reduction', 't1'], ['Expansion', 't2'],
      ['Crossbite in adults', 't3'], ['Retention', 't3'], ['Relapse and retreatment', 't3'], ['Adolescent patients', 't3'], ['Periodontal patients', 't3'],
      ['Interdisciplinary cases', 't3'], ['TADs and aligners', 't2'], ['Digital set-ups', 't1'], ['AI-assisted planning', 't4'], ['Remote monitoring', 't4']];
    var ANG = ['what the evidence says', 'case-based review', 'planning step by step', 'common pitfalls', 'limits and workarounds', 'new approaches'];
    var WS = ['Hands-on: attachment placement and templates', 'Hands-on: designing a digital set-up', 'Hands-on: IPR technique on models', 'Hands-on: TADs combined with aligners',
      'Hands-on: elastics and cut-outs', 'Hands-on: refinements step by step', 'Hands-on: in-office aligner printing', 'Hands-on: scanning complex cases',
      'Hands-on: retainers and retention', 'Hands-on: finishing and detailing'];
    var WSRES = [9, 12, 0, 3, 8, 20, 15, 1, 6, 18];   // posti rimasti su 20 (inventati)
    var WST = ['t1', 't1', 't2', 't2', 't2', 't1', 't4', 't4', 't3', 't3'];
    var KEY = { g1: ['Opening keynote: from clinical limitations to predictable outcomes', 't1'], g2: ['Keynote: complex cases, predictable results', 't3'], g3: ['Keynote: where aligner orthodontics is heading', 't4'] };
    var nw = 0, ng = 0, np = 0, nb = 0, sp = 0;
    function prossimo() { sp = sp % 30; return 'sp' + (++sp); }
    function piu(h, m) { var t = h.split(':'); t = +t[0] * 60 + +t[1] + m; return (t < 600 ? '0' : '') + Math.floor(t / 60) + ':' + (t % 60 < 10 ? '0' : '') + t % 60; }
    [['g1', 4], ['g2', 4], ['g3', 2]].forEach(function (gg, gi) {
      for (var si = 0; si < gg[1]; si++) {
        sp = (gi * 4 + si) * 16;   // speaker in sequenza: nella stessa fascia oraria nessuno e' in due sale
        DATA.sale.forEach(function (r) {
          var da = SLOT[si][0], a = SLOT[si][1], s = { g: gg[0], da: da, a: a, sala: r.id, subs: [] };
          if (r.id === 'aud' && si === 0) {
            s.id = 'k' + (gi + 1); s.tipo = 'keynote'; s.titolo = KEY[gg[0]][0]; s.t = KEY[gg[0]][1]; s.sp = [prossimo()]; s.posti = { libero: true };
          } else if (r.id === 'aud' && gi === 2 && si === 1) {
            s.id = 'k4'; s.tipo = 'keynote'; s.titolo = 'Closing session and EAS9 announcement'; s.t = 't3'; s.sp = [prossimo(), prossimo()]; s.posti = { libero: true };
          } else if ((r.id === 'wa' || r.id === 'wb') && nw < 10) {
            s.id = 'w' + (nw < 9 ? '0' : '') + (nw + 1); s.tipo = 'workshop'; s.titolo = WS[nw]; s.t = WST[nw]; s.sp = [prossimo()];
            s.posti = { tot: 20, res: WSRES[nw] }; s.pagamento = { pub: 100, mem: 100 }; nw++;
          } else {
            var sub = SOGG[ng % 20], ang = ANG[Math.floor(ng / 20) % ANG.length];
            s.id = 's' + (ng < 9 ? '00' : (ng < 99 ? '0' : '')) + (ng + 1); s.titolo = sub[0] + ': ' + ang; s.t = sub[1]; ng++;
            if (r.id === 'ha' || r.id === 'hb') {
              s.tipo = 'panel'; s.sp = [prossimo(), prossimo(), prossimo()];
              s.posti = np % 5 === 4 ? { nd: true } : { tot: 300, res: [214, 38, 0, 150][np % 4] }; np++;
              s.subs = [{ ora: da, titolo: 'Introduction and cases', chi: s.sp[0] }, { ora: piu(da, 30), titolo: 'Comparing approaches', chi: s.sp[1] + ',' + s.sp[2] },
                { ora: piu(da, 60), titolo: 'Questions from the audience', chi: '*' }];
            } else if (r.id === 'wa' || r.id === 'wb') {
              s.tipo = 'talk'; s.sp = [prossimo()]; s.posti = { tot: 40, res: [40, 22, 9][nb % 3] }; nb++;
            } else { s.tipo = 'talk'; s.sp = [prossimo()]; s.posti = { libero: true }; }
          }
          s.desc = (s.tipo === 'workshop' ? 'Placeholder workshop, 20 seats. Bring a laptop. ' : 'Placeholder session. ') + 'The real abstract will come from the organiser.';
          DATA.sessioni.push(s);
        });
      }
    });
    // nomi degli speaker negli interventi
    DATA.sessioni.forEach(function (s) {
      s.subs.forEach(function (x) { x.chi = x.chi === '*' ? 'All speakers' : x.chi.split(',').map(function (i) { return DATA.speakers[+i.slice(2) - 1].nome; }).join(', '); });
    });
  }());

  /* ---- modifiche simulate dall'admin (date dell'evento, fasce, listino) ---- */
  try {
    var cfg = JSON.parse(w.localStorage.getItem('cv-config'));
    if (cfg && cfg.evento && cfg.evento.dal && cfg.evento.al) { DATA.evento.dal = cfg.evento.dal; DATA.evento.al = cfg.evento.al; }
    if (cfg && cfg.fasce) cfg.fasce.forEach(function (c) { DATA.fasce.forEach(function (f) { if (f.id === c.id && c.dal && c.al) { f.dal = c.dal; f.al = c.al; } }); });
    if (cfg && cfg.listino) Object.keys(cfg.listino).forEach(function (t) { if (DATA.listino[t]) DATA.listino[t] = cfg.listino[t]; });
  } catch (e) { /* storage non disponibile o dati rotti: valori di partenza */ }

  // giorni ricavati dalle date dell'evento (al massimo 5)
  DATA.giorni = [];
  for (var gi = 0; gi < 5 && piuGiorni(DATA.evento.dal, gi) <= DATA.evento.al; gi++) {
    var gx = dIso(piuGiorni(DATA.evento.dal, gi));
    DATA.giorni.push({ id: 'g' + (gi + 1), data: iso(gx), breve: SETT[gx.getUTCDay()].slice(0, 3) + ' ' + gx.getUTCDate(), lungo: SETT[gx.getUTCDay()] + ' ' + gx.getUTCDate() + ' ' + MESI[gx.getUTCMonth()], mese: MESI[gx.getUTCMonth()] });
  }
  DATA.evento.date = intervallo(DATA.evento.dal, DATA.evento.al);

  // percorso della cartella che contiene prototipo.js ('' nelle pagine pubbliche, '../' in admin/)
  var BASE = (function () {
    var s = d.querySelector('script[src$="prototipo.js"]');
    return (s ? s.getAttribute('src') : 'prototipo.js').replace('prototipo.js', '');
  }());

  /* ------------------------------------------------------------------ stato "View as" */
  var ETICHETTA = {
    visitatore: 'Visitor (not logged in)',
    pubblico: 'User (not a member)',
    membro: 'Member',
    'membro-scaduto': 'Former member (membership expired)'
  };
  var BREVE = { visitatore: 'Visitor', pubblico: 'User', membro: 'Member', 'membro-scaduto': 'User' };
  var CHIAVE = { visitatore: null, pubblico: 'pub', membro: 'mem', 'membro-scaduto': 'pub' };
  var NOMEPREZZO = { pub: 'Public', mem: 'Member' };
  var stato = { ruolo: 'visitatore', pass: false, fascia: 'auto', lingua: 'en' };
  try {
    var salvato = JSON.parse(w.localStorage.getItem('cv-proto'));
    if (salvato && ETICHETTA[salvato.ruolo]) {
      stato = { ruolo: salvato.ruolo, pass: !!salvato.pass && salvato.ruolo !== 'visitatore',
        fascia: ['auto', 'early', 'regular', 'last'].indexOf(salvato.fascia) > -1 ? salvato.fascia : 'auto', lingua: salvato.lingua === 'it' ? 'it' : 'en' };
    }
  } catch (e) { /* storage non disponibile: si resta sui valori di partenza */ }
  function salva() { try { w.localStorage.setItem('cv-proto', JSON.stringify(stato)); } catch (e) { /* ignora */ } }

  /* ------------------------------------------------------------------ fasce e prezzi */
  function fascia(id) { return DATA.fasce.filter(function (f) { return f.id === id; })[0] || null; }
  // data "di oggi" del prototipo: con una fascia simulata e' il suo primo giorno
  function oggi() { var f = stato.fascia !== 'auto' && fascia(stato.fascia); return f ? f.dal : DATA.oggi; }
  function fasciaAttiva() { var o = oggi(); return DATA.fasce.filter(function (f) { return f.dal <= o && o <= f.al; })[0] || null; }
  function fasciaDopo(f) { var i = DATA.fasce.indexOf(f); return i > -1 ? DATA.fasce[i + 1] || null : null; }
  // prezzo di un tipo in una fascia per un ruolo: { v: numero o null, tba, reg (vale il Regular) }
  function valore(tipo, fid, k) {
    var l = DATA.listino[tipo], x = l && l[fid] && l[fid][k];
    if (x === 'reg') { var r = l.regular[k]; return { v: typeof r === 'number' ? r : null, tba: r === 'tba', reg: true }; }
    if (x === 'tba' || x === undefined || x === null) return { v: null, tba: true };
    return { v: +x };
  }
  function ricalcola() {
    var f = fasciaAttiva();
    ['intero', 'companion', 'espositore', 'workshop'].forEach(function (t) {
      if (!f) { DATA.prezzi[t] = { pub: null, mem: null, chiuso: true }; return; }
      var p = valore(t, f.id, 'pub'), m = valore(t, f.id, 'mem');
      DATA.prezzi[t] = { pub: p.v, mem: m.v, regPub: !!p.reg, regMem: !!m.reg, fascia: f, segnaposto: !!DATA.segnaposto[t] };
    });
  }
  ricalcola();

  /* ------------------------------------------------------------------ utilita' */
  function qs(s, r) { return (r || d).querySelector(s); }
  function qa(s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); }
  function param(n) { try { return new URLSearchParams(w.location.search).get(n); } catch (e) { return null; } }
  // €276.50 ma €695: i decimali compaiono solo quando servono
  function euro(n) { if (n === null || n === undefined || isNaN(n)) return 'TBA'; var dec = Math.round(n) === n ? 0 : 2; return '€' + n.toLocaleString('en-IE', { minimumFractionDigits: dec, maximumFractionDigits: dec }); }
  function ic(n) { return '<svg class="ic" aria-hidden="true" focusable="false"><use href="#i-' + n + '"></use></svg>'; }
  function giorno(id) { return DATA.giorni.filter(function (x) { return x.id === id; })[0]; }
  function sala(id) { return DATA.sale.filter(function (x) { return x.id === id; })[0]; }
  function traccia(id) { return DATA.tracce.filter(function (x) { return x.id === id; })[0]; }
  function persona(id) { return DATA.speakers.filter(function (x) { return x.id === id; })[0]; }
  function sessione(id) { return DATA.sessioni.filter(function (x) { return x.id === id; })[0]; }
  function trova(g, da, s) { return DATA.sessioni.filter(function (x) { return x.g === g && x.da === da && x.sala === s; })[0]; }
  function nomiSpeaker(s) { return s.sp.map(function (i) { return persona(i).nome; }).join(', '); }
  function iniziali(n) { var p = n.replace(/-/g, ' ').split(' '); return (p[0][0] + p[p.length - 1][0]).toUpperCase(); }
  function min(h) { var p = h.split(':'); return +p[0] * 60 + +p[1]; }
  function sovrapposte(a, b) { return a.g === b.g && min(a.da) < min(b.a) && min(b.da) < min(a.a); }
  function prezzoMio(p) { var k = CHIAVE[stato.ruolo]; return p[k || 'pub']; }
  function setStato(n) {
    for (var k in n) { if (Object.prototype.hasOwnProperty.call(n, k)) stato[k] = n[k]; }
    if (stato.ruolo === 'visitatore') stato.pass = false;
    salva(); ricalcola(); applica();
    d.dispatchEvent(new CustomEvent('cv:stato'));
  }
  function ready(fn) { if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', fn); else fn(); }

  /* ------------------------------------------------------------------ componente prezzo per ruolo */
  // Visitatore: i due prezzi con chi ne ha diritto. Connesso: il suo prezzo in evidenza, l'altro in piccolo.
  // Prezzo unico (workshop, accompagnatore, espositore): dichiarato come tale, senza barrati finti. Prezzo non deciso: "to be announced".
  function prezzoHTML(p, o) {
    o = o || {};
    var k = CHIAVE[stato.ruolo], r = stato.ruolo, html, f = p.fascia;
    var segn = p.segnaposto ? ' <span class="badge badge--warn">' + ic('alert') + 'Placeholder price</span>' : '';
    if (p.chiuso) return o.inline ? '<span class="prezzi prezzi--inline t-muted">Not on sale today</span>' : '<div class="prezzi"><p class="prezzo-altri">Not on sale today: no price tier is open.</p></div>';
    if (p.pub === null && p.mem === null) {
      if (o.inline) return '<span class="prezzi prezzi--inline"><b class="prezzo-valore">Price to be announced</b></span>';
      return '<div class="prezzi"><p><span class="prezzo-valore prezzo-valore--tba">Price to be announced</span></p><p class="prezzo-altri">The ' + (f ? f.nome.toLowerCase() : '') + ' price has not been decided yet. It will appear here as soon as the organiser sets it.</p></div>';
    }
    if (p.pub === p.mem) {
      if (o.inline) return '<span class="prezzi prezzi--inline"><b class="prezzo-valore">' + euro(p.pub) + '</b> <span class="prezzo-barrato">same price for everyone</span>' + segn + '</span>';
      return '<div class="prezzi"><p><span class="prezzo-valore prezzo-valore--grande">' + euro(p.pub) + '</span>' + segn + '</p><p class="prezzo-altri">Same price for everyone: no member price.</p></div>';
    }
    var notaPub = p.regPub ? 'Regular price: early bird is for members only' : '';
    var notaMem = f && f.id === 'early' && !p.regMem ? 'Early bird −30%, with an active membership' : 'With an active membership, on your own ticket';
    if (!k) {
      if (o.inline) {
        return '<span class="prezzi prezzi--inline"><span>Public <b class="prezzo-valore">' + euro(p.pub) + '</b></span><span class="sep" aria-hidden="true">·</span>' +
          '<span>Member <b class="prezzo-valore">' + euro(p.mem) + '</b></span></span>';
      }
      return '<div class="prezzi"><dl>' +
        '<div class="prezzo-riga"><dt>Public' + (notaPub ? '<span class="prezzo-nota">' + notaPub + '</span>' : '') + '</dt><dd><span class="prezzo-valore">' + euro(p.pub) + '</span></dd></div>' +
        '<div class="prezzo-riga"><dt>Member<span class="prezzo-nota">' + notaMem + '</span></dt><dd><span class="prezzo-valore">' + euro(p.mem) + '</span></dd></div>' +
        '</dl></div>';
    }
    var tuo = p[k];
    var barrato = (k !== 'pub' && p.pub !== null) ? '<span class="prezzo-barrato"><span class="sr-only">Public price </span><s>' + euro(p.pub) + '</s></span>' : '';
    if (o.inline) {
      var extra = k === 'pub' ? ' <span class="prezzo-barrato">· member ' + euro(p.mem) + '</span>' : ' ' + barrato.replace('<s>', '<s aria-hidden="true">');
      return '<span class="prezzi prezzi--inline"><span class="badge badge--ruolo">' + (k === 'pub' ? 'Public price' : 'Your price · ' + BREVE[r]) + '</span> <b class="prezzo-valore prezzo-valore--tuo">' + euro(tuo) + '</b>' + extra + '</span>';
    }
    var etic = { pubblico: 'Your price · Public', membro: 'Your price · Member', 'membro-scaduto': 'Your price · Public' }[r];
    html = '<div class="prezzi prezzi--tuo"><p class="prezzo-tuo"><span class="badge badge--ruolo">' + etic + '</span>' +
      '<span class="prezzo-valore prezzo-valore--grande">' + euro(tuo) + '</span>' + barrato + '</p>' +
      '<p class="prezzo-altri">Other price: ' + NOMEPREZZO[k === 'pub' ? 'mem' : 'pub'] + ' ' + euro(p[k === 'pub' ? 'mem' : 'pub']) + (k === 'pub' && notaPub ? ' · ' + notaPub.toLowerCase() : '') + '</p>';
    if (r === 'pubblico') html += '<p class="prezzo-altri"><a href="' + BASE + 'biglietti.html#prezzi-ridotti">How to get the member price</a></p>';
    if (r === 'membro') html += '<p class="prezzo-altri">The member price applies to your own ticket. People you buy for pay the public price.</p>';
    if (r === 'membro-scaduto') html += '<p class="prezzo-altri">Your membership has expired: <a href="' + BASE + 'membership.html">renew</a> to pay ' + euro(p.mem) + ' again.</p>';
    return html + '</div>';
  }

  // Riga sulla fascia di prezzo attiva: nome, regola, quando finisce, che cosa viene dopo.
  function fasciaHTML() {
    var f = fasciaAttiva();
    if (!f) return '<span class="t-small fascia-nota"><b>Sales are closed:</b> no price tier is open today.</span>';
    var n = fasciaDopo(f), resto = giorniTra(oggi(), f.al), pr = DATA.prezzi.intero;
    var h = '<span class="t-small fascia-nota"><span class="badge badge--info">' + ic('clock') + f.nome + ' · now</span> ';
    if (f.id === 'early') h += '<b>Members only, −30%.</b> ';
    if (pr.pub === null && pr.mem === null) h += '<b>Price to be announced.</b> ';
    h += 'Ends ' + dataEn(f.al) + (resto >= 0 ? ' (' + (resto === 0 ? 'last day' : resto + (resto === 1 ? ' day left' : ' days left')) + ')' : '') + '.';
    if (n) {
      var np = valore('intero', n.id, 'pub'), nm = valore('intero', n.id, 'mem');
      h += ' Then ' + n.nome + ' from ' + dataEn(n.dal) + ': ' + (np.tba && nm.tba ? 'price to be announced' : 'public ' + euro(np.v) + ', member ' + euro(nm.v)) + '.';
    }
    return h + '</span>';
  }

  /* ------------------------------------------------------------------ pezzi di interfaccia riusati */
  function badgeAccesso(s) {
    return s.pagamento
      ? '<span class="badge badge--pagamento">' + ic('ticket') + 'Paid extra</span>'
      : '<span class="badge badge--inclusa">' + ic('check') + 'Included in pass</span>';
  }
  function chipTraccia(id) {
    return '<span class="chip-traccia" data-t="' + id + '"><span class="marker" aria-hidden="true"></span>' + traccia(id).nome + '</span>';
  }
  function postiHTML(s) {
    var p = s.posti;
    if (p.libero) return '<span class="posti posti--libero">' + ic('person') + '<span>Free entry with a pass</span></span>';
    if (p.nd) return '<span class="posti posti--nd">' + ic('info') + '<span>Seats: not available yet</span></span>';
    if (p.res === 0) return '<span class="posti posti--esauriti">' + ic('alert') + '<span>Sold out</span></span>';
    if (p.res <= 10) return '<span class="posti posti--pochi">' + ic('alert') + '<span>Last ' + p.res + ' of ' + p.tot + ' seats</span></span>';
    return '<span class="posti">' + ic('person') + '<span>' + p.res + ' of ' + p.tot + ' seats left</span></span>';
  }
  function sessioneRiga(s, o) {
    o = o || {};
    var g = giorno(s.g), H = o.h || 'h3', html;
    if (!g) return '';
    html = '<article class="sessione' + (o.giorno ? ' sessione--giorno' : '') + '" aria-labelledby="t-' + s.id + '">' +
      '<p class="sessione__ora">' + (o.giorno ? '<small>' + g.breve + '</small>' : '') + '<time>' + s.da + '</time><small>until ' + s.a + '</small></p>' +
      '<div class="sessione__corpo">' +
      '<' + H + ' class="sessione__titolo" id="t-' + s.id + '"><a href="' + BASE + 'sessione.html?id=' + s.id + '">' + s.titolo + '</a></' + H + '>' +
      '<p class="sessione__meta"><span>' + ic('pin') + ' ' + sala(s.sala).nome + '</span><span>' + DATA.tipi[s.tipo] + '</span><span>' + nomiSpeaker(s) + '</span></p>' +
      '<p class="sessione__tag">' + chipTraccia(s.t) + badgeAccesso(s) + postiHTML(s) + '</p>';
    if (s.pagamento) html += '<p>' + prezzoHTML(s.pagamento, { inline: true }) + '</p>';
    if (s.subs.length) {
      html += '<details class="acc"><summary>' + s.subs.length + ' talks in this session' + ic('chev') + '</summary><div class="acc__corpo"><ol class="sub">';
      s.subs.forEach(function (x) { html += '<li><time>' + x.ora + '</time><span><b>' + x.titolo + '</b>' + x.chi + '</span></li>'; });
      html += '</ol></div></details>';
    }
    return html + '</div></article>';
  }
  function speakerCard(p) {
    return '<a class="speaker-card" href="' + BASE + 'speaker-dettaglio.html?id=' + p.id + '">' +
      '<span class="ritratto" data-tono="' + p.tono + '" aria-hidden="true">' + iniziali(p.nome) + '</span>' +
      '<span><span class="speaker-card__nome">' + p.nome + '</span><span class="speaker-card__ruolo">' + p.ruolo + ', ' + p.ente + '</span><span class="speaker-card__ruolo"><i>Placeholder profile</i></span></span></a>';
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
    '<symbol id="i-back" viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6"/></symbol>' +
    '<symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="10.500" width="14" height="9.500" rx="1.500"/><path d="M8 10.500V8a4 4 0 0 1 8 0v2.500"/></symbol>' +
    '<symbol id="i-grid" viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7"/><rect x="13" y="4" width="7" height="7"/><rect x="4" y="13" width="7" height="7"/><rect x="13" y="13" width="7" height="7"/></symbol>' +
    '<symbol id="i-list" viewBox="0 0 24 24"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/></symbol>' +
    '<symbol id="i-grip" viewBox="0 0 24 24"><path d="M9 6h.01M15 6h.01M9 12h.01M15 12h.01M9 18h.01M15 18h.01" stroke-width="3"/></symbol>' +
    '<symbol id="i-up" viewBox="0 0 24 24"><path d="M12 19V5M6 11l6-6 6 6"/></symbol>' +
    '<symbol id="i-down" viewBox="0 0 24 24"><path d="M12 5v14M6 13l6 6 6-6"/></symbol>' +
    '<symbol id="i-download" viewBox="0 0 24 24"><path d="M12 4v11M7 11l5 5 5-5M5 20h14"/></symbol>' +
    '<symbol id="i-upload" viewBox="0 0 24 24"><path d="M12 16V5M7 9l5-5 5 5M5 20h14"/></symbol>' +
    '<symbol id="i-wallet" viewBox="0 0 24 24"><rect x="3.500" y="6" width="17" height="13" rx="1.500"/><path d="M3.500 10h17M16 14.500h.01"/></symbol>' +
    '<symbol id="i-camera" viewBox="0 0 24 24"><path d="M4 8h3l1.500-2h7L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.500"/></symbol>' +
    '<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.500"/><path d="M16 16l4.500 4.500"/></symbol>' +
    '<symbol id="i-refresh" viewBox="0 0 24 24"><path d="M19 12a7 7 0 1 1-2.200-5.100M19 5v4.500h-4.500"/></symbol>' +
    '<symbol id="i-external" viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-9 9M18 14v5H5V6h5"/></symbol>' +
    '<symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>' +
    '<symbol id="i-play" viewBox="0 0 24 24"><path d="M8 5.500v13l11-6.500z"/></symbol>' +
    '<symbol id="i-gift" viewBox="0 0 24 24"><rect x="4" y="9" width="16" height="11" rx="1"/><path d="M3 9h18M12 9v11M12 9c-2-4-6-4-6-1.500S9 9 12 9zM12 9c2-4 6-4 6-1.500S15 9 12 9z"/></symbol>' +
    '</defs></svg>';

  // QR SEGNAPOSTO: modello deterministico dal testo, NON e' leggibile da un lettore. Sempre nero su bianco.
  function qrSvg(seme, etichetta) {
    var n = 25, i, j, h = 2166136261;
    for (i = 0; i < seme.length; i++) { h ^= seme.charCodeAt(i); h = Math.imul(h, 16777619) >>> 0; }
    function rnd() { h ^= h << 13; h >>>= 0; h ^= h >>> 17; h ^= h << 5; h >>>= 0; return h / 4294967296; }
    function riservata(a, b) { return (a < 8 && b < 8) || (a > n - 9 && b < 8) || (a < 8 && b > n - 9); }
    function finder(a, b) {
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
    var area = d.body.getAttribute('data-area');   // 'admin' e 'checkin': niente selettori del sito pubblico
    var opz = Object.keys(ETICHETTA).map(function (k) { return '<option value="' + k + '">' + ETICHETTA[k] + '</option>'; }).join('');
    var opzF = '<option value="auto">Automatic (today, ' + dataEn(DATA.oggi) + ')</option>' + DATA.fasce.map(function (f) { return '<option value="' + f.id + '">' + f.nome + ' (from ' + dataEn(f.dal) + ')</option>'; }).join('');
    var ctl = area ? '' : '<div class="proto-bar__controlli"><span class="proto-bar__campo"><label for="proto-ruolo">View as</label>' +
      '<select id="proto-ruolo" class="select">' + opz + '</select></span>' +
      '<label class="scelta scelta--inline"><input type="checkbox" id="proto-pass"> <span>I already have a pass</span></label>' +
      '<span class="proto-bar__campo"><label for="proto-fascia">Active price tier</label><select id="proto-fascia" class="select">' + opzF + '</select></span></div>';
    var h = SPRITE + '<details class="proto-bar"><summary><span>Prototype' + (area ? '' : ' · <b data-ruolo-label>Visitor</b> · <b data-fascia-label>Early bird</b>') + '</span>' +
      '<svg class="ic" aria-hidden="true"><use href="#i-chev"></use></svg></summary><div class="proto-bar__corpo">' +
      '<p>Real data (client, 6 Nov 2026): name, dates, city, seats, price tiers and rules, pass, workshop and membership prices. Examples: end dates of the tiers. Placeholders: venue, speakers, sessions, rooms, videos, orders, companion and exhibitor prices.</p>' + ctl +
      '<nav class="proto-bar__nav" aria-label="Prototype areas"><a href="' + BASE + 'index.html">Public site</a><a href="' + BASE + 'accesso.html">Log in</a><a href="' + BASE + 'account.html">My account</a><a href="' + BASE + 'admin/index.html">Admin</a><a href="' + BASE + 'checkin.html">Check-in (reference)</a></nav></div></details>';
    var skip = qs('.skip-link');
    if (skip) skip.insertAdjacentHTML('afterend', h); else d.body.insertAdjacentHTML('afterbegin', h);
    if (!area) {
      qs('#proto-ruolo').addEventListener('change', function (e) { setStato({ ruolo: e.target.value }); });
      qs('#proto-pass').addEventListener('change', function (e) { setStato({ pass: e.target.checked }); });
      qs('#proto-fascia').addEventListener('change', function (e) { setStato({ fascia: e.target.value }); });
    }
  }

  // Intestazione pubblica scritta qui (nel sito vero e' una parte Blade). La pagina corrente viene da <body data-pagina>.
  function testata() {
    var h = qs('header.site-header');
    if (!h || h.children.length || d.body.getAttribute('data-area')) return;   // admin e check-in hanno la loro intestazione
    var pag = d.body.getAttribute('data-pagina') || '';
    var voci = [['agenda', 'calendar', 'Programme'], ['speaker', 'person', 'Speakers'], ['biglietti', 'ticket', 'Tickets'], ['membership', 'card', 'Membership']];
    h.innerHTML = '<div class="wrap site-header__in"><a class="brand" href="' + BASE + 'index.html" aria-label="EAS8 Lisbon, home"><img src="' + BASE + 'assets/eas-logo.png" width="84" height="47" alt="EAS, European Aligner Society"></a>' +
      '<nav class="nav-main" aria-label="Main">' + voci.map(function (v) {
        return '<a href="' + BASE + v[0] + '.html"' + (pag === v[0] ? ' aria-current="page"' : '') + '>' + ic(v[1]) + v[2] + '</a>';
      }).join('') + '</nav>' +
      '<span class="site-header__destra"><a class="header-link" href="' + BASE + 'accesso.html">Log in</a><span class="badge badge--ruolo"><span class="solo-desktop">View: </span><span data-ruolo-label>Visitor</span></span></span></div>';
  }

  // Selettore EN/IT. L'inglese e' la lingua predefinita. IT non traduce: mostra (in italiano, lang="it") che la traduzione arrivera'.
  // <html lang> resta "en" perche' i testi restano inglesi (un lang sbagliato confonde i lettori di schermo).
  function lingua() {
    var dx = qs('.site-header__destra'), hd = qs('header.site-header');
    if (!dx || !hd || qs('#lang')) return;
    dx.insertAdjacentHTML('afterbegin', '<label class="sr-only" for="lang">Language / Lingua</label>' +
      '<select id="lang" class="select select--lang"><option value="en" lang="en">EN</option><option value="it" lang="it">IT</option></select>');
    hd.insertAdjacentHTML('afterend', '<div class="wrap lang-avviso" id="lang-avviso" role="status" aria-live="polite"></div>');
    function mostra() {
      qs('#lang').value = stato.lingua;
      qs('#lang-avviso').innerHTML = stato.lingua === 'it'
        ? '<div class="avviso avviso--info" lang="it">' + ic('info') + '<div><b>La versione italiana arriverà presto.</b> Per ora il sito è solo in inglese: prezzi, date e acquisto funzionano allo stesso modo. ' +
          '<button type="button" class="btn btn--quiet btn--piccolo" id="lang-en" lang="en">Back to English</button></div></div>'
        : '';
      var b = qs('#lang-en'); if (b) b.addEventListener('click', function () { stato.lingua = 'en'; salva(); mostra(); qs('#lang').focus(); });
    }
    qs('#lang').addEventListener('change', function (e) { stato.lingua = e.target.value; salva(); mostra(); });
    mostra();
  }

  // Footer scuro come quello di eas-aligners.com: logo, link, contatti, newsletter (Mailchimp). Contatti pubblici del loro sito.
  // Social omessi: gli indirizzi dei profili vanno chiesti al cliente (niente link inventati).
  function piede() {
    var f = qs('.site-footer');
    if (!f) return;
    f.innerHTML = '<div class="wrap site-footer__griglia">' +
      '<div><a href="' + BASE + 'index.html" aria-label="EAS8 Lisbon, home"><img src="' + BASE + 'assets/eas-logo.png" width="84" height="47" alt="EAS, European Aligner Society"></a></div>' +
      '<div><h2>EAS8 Lisbon</h2><ul><li><a href="' + BASE + 'index.html">Home</a></li><li><a href="' + BASE + 'agenda.html">Programme</a></li><li><a href="' + BASE + 'speaker.html">Speakers</a></li>' +
      '<li><a href="' + BASE + 'biglietti.html">Tickets</a></li><li><a href="' + BASE + 'membership.html">Membership</a></li></ul></div>' +
      '<div><h2>Contact</h2><p><a href="mailto:office@eas-aligners.com">office@eas-aligners.com</a></p>' +
      '<p>European Aligner Society<br>La Réserve – 2525 Route de Bandol<br>83110 Sanary-sur-Mer, France</p></div>' +
      '<div><h2>Stay updated</h2><form id="news" novalidate><div class="campo"><label for="news-email">Your email</label>' +
      '<input class="input" id="news-email" type="email" autocomplete="email" aria-describedby="news-aiuto"><p class="campo__aiuto" id="news-aiuto">Newsletter sent with Mailchimp. Optional; unsubscribe from any email. In the prototype nothing is sent.</p></div>' +
      '<p style="margin-top:var(--s-2)"><button type="submit" class="btn btn--primary">Subscribe</button></p><div id="news-esito" role="status" aria-live="polite"></div></form></div></div>' +
      '<div class="wrap site-footer__sotto"><p>© 2026 EAS – European Aligner Society. All rights reserved.</p>' +
      '<p>Privacy and cookies: pages in preparation. Prototype: speakers, sessions and availability are placeholders.</p></div>';
    qs('#news').addEventListener('submit', function (e) {
      e.preventDefault();
      var v = qs('#news-email').value.trim();
      if (!/^\S+@\S+\.\S+$/.test(v)) { msg(qs('#news-esito'), 'err', 'Check the email address: the @ or the domain is missing.'); qs('#news-email').focus(); return; }
      msg(qs('#news-esito'), 'ok', 'Subscription simulated. On the real site Mailchimp sends a confirmation email first.');
    });
  }

  // Mostra/nasconde gli elementi con data-ruoli / data-pass e riempie i segnaposto di testo e prezzo.
  function applica() {
    var sel = qs('#proto-ruolo'), pas = qs('#proto-pass'), fs = qs('#proto-fascia'), fa = fasciaAttiva();
    if (sel) sel.value = stato.ruolo;
    if (fs) fs.value = stato.fascia;
    if (pas) { pas.checked = stato.pass; pas.disabled = stato.ruolo === 'visitatore'; }
    qa('[data-ruoli]').forEach(function (el) {
      var ok = el.getAttribute('data-ruoli').split(',').indexOf(stato.ruolo) > -1;
      var p = el.getAttribute('data-pass');
      if (p) ok = ok && ((p === 'si') === stato.pass);
      el.hidden = !ok;
    });
    qa('[data-solo-pass]').forEach(function (el) { el.hidden = (el.getAttribute('data-solo-pass') === 'si') !== stato.pass; });
    qa('[data-solo-fascia]').forEach(function (el) { el.hidden = !fa || el.getAttribute('data-solo-fascia').split(',').indexOf(fa.id) < 0; });
    qa('[data-ruolo-label]').forEach(function (el) { el.textContent = BREVE[stato.ruolo]; el.setAttribute('data-ruolo', stato.ruolo); });
    qa('[data-fascia-label]').forEach(function (el) { el.textContent = fa ? fa.nome : 'Sales closed'; });
    // voce Log in / Account nell'intestazione
    qa('.site-header__destra .header-link').forEach(function (a) {
      var v = stato.ruolo === 'visitatore', file = v ? 'accesso.html' : 'account.html';
      a.textContent = v ? 'Log in' : 'Account'; a.setAttribute('href', BASE + file);
      if ((w.location.pathname.split('/').pop() || 'index.html') === file) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    qa('[data-prezzo]').forEach(function (el) {
      var v = el.getAttribute('data-prezzo').split(',');
      el.innerHTML = prezzoHTML({ pub: +v[0], mem: +v[1] }, { inline: el.getAttribute('data-var') === 'inline' });
    });
    // prezzi presi dalla fascia attiva (mai scritti a mano nelle pagine)
    qa('[data-prezzo-tipo]').forEach(function (el) {
      el.innerHTML = prezzoHTML(DATA.prezzi[el.getAttribute('data-prezzo-tipo')], { inline: el.getAttribute('data-var') === 'inline' });
    });
    qa('[data-fascia]').forEach(function (el) { el.innerHTML = fasciaHTML(); });
    qa('[data-euro]').forEach(function (el) {
      var v = el.getAttribute('data-euro').split('.'), x = DATA.prezzi[v[0]];
      if (typeof x === 'object' && x) x = x[v[1]];
      el.textContent = typeof x === 'number' ? euro(x) : 'TBA';
    });
    qa('[data-colonna-tua]').forEach(function (el) { el.classList.toggle('is-tuo', CHIAVE[stato.ruolo] === el.getAttribute('data-colonna-tua')); });
    qa('[data-evento-date]').forEach(function (el) { el.textContent = DATA.evento.date; });
  }

  w.CV = {
    DATA: DATA, stato: stato, ETICHETTA: ETICHETTA, CHIAVE: CHIAVE, BREVE: BREVE, BASE: BASE,
    qs: qs, qa: qa, param: param, euro: euro, ic: ic, ready: ready, setStato: setStato, applica: applica, ricalcola: ricalcola,
    giorno: giorno, sala: sala, traccia: traccia, persona: persona, sessione: sessione, trova: trova,
    nomiSpeaker: nomiSpeaker, iniziali: iniziali, min: min, sovrapposte: sovrapposte, prezzoMio: prezzoMio,
    prezzoHTML: prezzoHTML, fasciaHTML: fasciaHTML, fascia: fascia, fasciaAttiva: fasciaAttiva, fasciaDopo: fasciaDopo, valore: valore, oggi: oggi,
    dataEn: dataEn, intervallo: intervallo, giorniTra: giorniTra, piuGiorni: piuGiorni,
    badgeAccesso: badgeAccesso, chipTraccia: chipTraccia, postiHTML: postiHTML,
    sessioneRiga: sessioneRiga, speakerCard: speakerCard, qrSvg: qrSvg, msg: msg
  };

  // La barra in basso (telefono) sta DOPO <main> nel DOM, cosi' l'ordine di tabulazione e' intestazione, contenuto, barra.
  // Da 64rem torna nell'intestazione, prima dei controlli di destra. Resta fissa in basso solo con CSS.
  function navPosizione() {
    var nav = qs('.nav-main'), dx = qs('.site-header__destra'), main = qs('main');
    if (!nav || !dx || !main) return;
    var mq = w.matchMedia('(min-width: 64rem)');
    function p() { if (mq.matches) dx.parentNode.insertBefore(nav, dx); else main.insertAdjacentElement('afterend', nav); }
    p(); if (mq.addEventListener) mq.addEventListener('change', p);
  }

  ready(function () { barra(); testata(); lingua(); piede(); navPosizione(); applica(); });
}(window, document));
