/*
  Autore: Giulio Occhipinti
  Pannello admin del prototipo (EAS8 Lisbon): menu comune con selettore «Vedi come ruolo», dati d'esempio inventati
  (ordini, membri, codici), elenco riordinabile con trascinamento simulato e alternativa da tastiera,
  esportazione simulata dei biglietti validi per il partner.
  Ruoli del backend (Laravel + Filament, risposta del cliente del 06/11/2026): admin, commerciale (pagamenti e fatture),
  organizzatore (biglietti, tier, date). Nel sito vero i permessi sono policy di Laravel, non un selettore.
  Ogni scrittura vera richiede un accesso con secondo fattore e finisce nel registro audit.
*/
(function (w, d) {
  'use strict';
  var AD = {};
  var $ = function (s, r) { return (r || d).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); };

  /* ---------------- ruoli e voci di menu ---------------- */
  AD.RUOLI = {
    admin: { nome: 'Admin', nota: 'Vede e fa tutto: programma, biglietti, pagamenti, membri, codici sconto.' },
    commerciale: { nome: 'Commerciale', nota: 'Pagamenti e fatture: ordini, rimborsi, membri, codici sconto. Non modifica programma, biglietti, tier o date.' },
    organizzatore: { nome: 'Organizzatore', nota: 'Biglietti, tier e date: programma, speaker, prezzi e fasce, capienze, esportazione per il partner. Non vede pagamenti e fatture.' }
  };
  // [file, titolo, ruoli che la vedono]. Assegnazione dei codici sconto al commerciale: ipotesi da confermare con il cliente.
  AD.PAGINE = [
    ['index.html', 'Cruscotto', ['admin', 'commerciale', 'organizzatore']],
    ['agenda.html', 'Programma', ['admin', 'organizzatore']],
    ['speaker.html', 'Speaker', ['admin', 'organizzatore']],
    ['biglietti.html', 'Biglietti e prezzi', ['admin', 'organizzatore']],
    ['sconti.html', 'Codici sconto', ['admin', 'commerciale']],
    ['ordini.html', 'Ordini e fatture', ['admin', 'commerciale']],
    ['membri.html', 'Membri', ['admin', 'commerciale']]
  ];
  AD.ruolo = function () {
    try { var r = w.localStorage.getItem('cv-admin-ruolo'); if (AD.RUOLI[r]) return r; } catch (e) { /* storage non disponibile */ }
    return 'admin';
  };

  /* ---------------- dati d'esempio ---------------- */
  // Prezzi veri del cliente (fascia early bird aperta il 06/11/2026: pass 486,50 € pubblico / 276,50 € membro; workshop 100 €).
  // Nomi, ordini, date degli ordini, vendite: inventati.
  AD.STATO_ORD = {
    pending: ['info', 'clock', 'In attesa di pagamento'], processing: ['info', 'clock', 'In elaborazione'], paid: ['ok', 'check', 'Pagato'],
    failed: ['err', 'alert', 'Non riuscito'], expired: ['warn', 'clock', 'Scaduto'], refunded: ['err', 'x', 'Rimborsato'],
    partial: ['warn', 'x', 'Rimborso parziale'], review: ['warn', 'alert', 'Da rivedere']
  };
  AD.ordini = [
    { id: 'CV-1048', data: '05/11/2026 18:02', nome: 'Giada Segnaposto', email: 'giada.segnaposto@example.test', stato: 'review', ruolo: 'membro', pi: 'pi_…c91d',
      righe: [{ t: 'Pass intero · Giada Segnaposto', v: 276.5, tk: 'valido' }], sconto: 0, totale: 276.5, nota: 'Importo incassato su Stripe: 267,50 €. Non coincide con l’ordine (276,50 €): da riconciliare.',
      eventi: [['05/11 18:02', 'checkout.session.completed', 'importo diverso, ordine segnato da rivedere']] },
    { id: 'CV-1047', data: '04/11/2026 09:40', nome: 'Fabio Inventato', email: 'fabio.inventato@example.test', stato: 'refunded', ruolo: 'pubblico', pi: 'pi_…2b8e',
      righe: [{ t: 'Pass intero · Fabio Inventato', v: 486.5, tk: 'rimborsato' }], sconto: 0, totale: 486.5,
      eventi: [['04/11 09:41', 'checkout.session.completed', 'elaborato'], ['05/11 11:20', 'charge.refunded', 'rimborso totale registrato']] },
    { id: 'CV-1046', data: '04/11/2026 08:15', nome: 'Elena Fittizia', email: 'elena.fittizia@example.test', stato: 'expired', ruolo: 'pubblico', pi: '—',
      righe: [{ t: 'Workshop · Pianificare un caso difficile con il software · Elena Fittizia', v: 100, tk: '—' }], sconto: 0, totale: 100,
      eventi: [['04/11 08:45', 'checkout.session.expired', 'posti rilasciati']] },
    { id: 'CV-1045', data: '03/11/2026 21:30', nome: 'Dario Finto', email: 'dario.finto@example.test', stato: 'failed', ruolo: 'pubblico', pi: 'pi_…55aa',
      righe: [{ t: 'Pass intero · Dario Finto', v: 486.5, tk: '—' }], sconto: 0, totale: 486.5,
      eventi: [['03/11 21:31', 'checkout.session.async_payment_failed', 'ordine non riuscito, posti rilasciati, email mandata']] },
    { id: 'CV-1044', data: '03/11/2026 10:05', nome: 'Carla Prova', email: 'carla.prova@example.test', stato: 'paid', ruolo: 'pubblico', pi: 'pi_…9e10',
      righe: [{ t: 'Pass intero · Carla Prova', v: 486.5, tk: 'valido' }], sconto: 48.65, codice: 'BENVENUTO10', totale: 437.85,
      eventi: [['03/11 10:06', 'checkout.session.completed', 'elaborato, codice contato']] },
    { id: 'CV-1043', data: '05/11/2026 10:20', nome: 'Bruno Esempio', email: 'bruno.esempio@example.test', stato: 'processing', ruolo: 'pubblico', pi: 'pi_…3c42',
      righe: [{ t: 'Pass intero · Bruno Esempio', v: 486.5, tk: '—' }, { t: 'Pass intero · Anna Ospite', v: 486.5, tk: '—' }], sconto: 0, totale: 973,
      eventi: [['05/11 10:21', 'checkout.session.completed', 'pagamento non ancora confermato (metodo a notifica differita)']] },
    { id: 'CV-1042', data: '05/11/2026 10:14', nome: 'Alessia Demo', email: 'alessia.demo@example.test', stato: 'paid', ruolo: 'membro', pi: 'pi_…7f3a',
      righe: [{ t: 'Pass intero · Alessia Demo', v: 276.5, tk: 'valido' }, { t: 'Workshop · Attachments e biomeccanica: pianificare in due ore · Alessia Demo', v: 100, tk: 'valido' }], sconto: 0, totale: 376.5,
      eventi: [['05/11 10:15', 'checkout.session.completed', 'elaborato'], ['05/11 10:15', 'Email di conferma', 'in coda, poi inviata']] }
  ];
  AD.membri = [
    { id: 'm1', nome: 'Alessia Demo', email: 'alessia.demo@example.test', stato: 'active', fine: '30/09/2027', rinnovo: 'automatico' },
    { id: 'm2', nome: 'Tiziano Campione', email: 'tiziano.campione@example.test', stato: 'active', fine: '14/06/2027', rinnovo: 'automatico' },
    { id: 'm3', nome: 'Ornella Saggio', email: 'ornella.saggio@example.test', stato: 'canceling', fine: '02/05/2027', rinnovo: 'disdetto, non si rinnova' },
    { id: 'm4', nome: 'Pietro Lento', email: 'pietro.lento@example.test', stato: 'past_due', fine: '03/11/2026', rinnovo: 'pagamento del rinnovo non riuscito' },
    { id: 'm5', nome: 'Rosa Antica', email: 'rosa.antica@example.test', stato: 'expired', fine: '30/09/2026', rinnovo: 'scaduta' },
    { id: 'm6', nome: 'Vito Nuovo', email: 'vito.nuovo@example.test', stato: 'trialing', fine: '20/11/2026', rinnovo: 'in prova, poi automatico' }
  ];
  AD.STATO_MEM = { active: ['ok', 'check', 'Attiva'], trialing: ['info', 'clock', 'In prova'], canceling: ['warn', 'clock', 'Disdetta a fine periodo'], past_due: ['err', 'alert', 'Pagamento non riuscito'], expired: ['err', 'x', 'Scaduta'] };
  AD.sconti = [
    { codice: 'BENVENUTO10', tipo: '%', valore: 10, ruoli: 'Pubblico', biglietti: 'Tutti', dal: '01/11/2026', al: '31/03/2027', usi: 37, limite: 200, cumulabile: false, stato: 'attivo' },
    { codice: 'STAFF100', tipo: '%', valore: 100, ruoli: 'Tutti', biglietti: 'Pass intero', dal: '01/11/2026', al: '08/04/2027', usi: 4, limite: 15, cumulabile: true, stato: 'attivo' },
    { codice: 'PARTNER2027', tipo: '€', valore: 20, ruoli: 'Pubblico, Membro', biglietti: 'Pass intero', dal: '01/11/2026', al: '30/11/2026', usi: 100, limite: 100, cumulabile: false, stato: 'esaurito' },
    { codice: 'SCADUTO24', tipo: '%', valore: 15, ruoli: 'Tutti', biglietti: 'Tutti', dal: '01/10/2026', al: '31/10/2026', usi: 12, limite: 50, cumulabile: false, stato: 'scaduto' }
  ];

  /* ---------------- pezzi comuni ---------------- */
  AD.icona = function (n) { return '<svg class="ic" aria-hidden="true" focusable="false"><use href="#i-' + n + '"></use></svg>'; };
  AD.badgeOrd = function (s) { var x = AD.STATO_ORD[s]; return '<span class="badge badge--' + x[0] + '">' + AD.icona(x[1]) + x[2] + '</span>'; };
  // 486,50 € ma 695 €: i decimali compaiono solo quando servono
  AD.euro = function (n) { var dec = Math.round(n) === n ? 0 : 2; return n.toLocaleString('it-IT', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + ' €'; };
  AD.param = function (n) { try { return new URLSearchParams(w.location.search).get(n); } catch (e) { return null; } };

  // Messaggio per lettori di schermo (la modifica e' comunque visibile a schermo).
  AD.annuncio = function (t) {
    var a = $('#annuncio');
    if (!a) { a = d.createElement('div'); a.id = 'annuncio'; a.className = 'sr-only'; a.setAttribute('role', 'status'); a.setAttribute('aria-live', 'polite'); d.body.appendChild(a); }
    a.textContent = ''; setTimeout(function () { a.textContent = t; }, 30);
  };

  // Esportazione SIMULATA dei biglietti validi per il partner che fa il check-in sul posto.
  // Solo biglietti di ordini pagati e non rimborsati. Colonne minime: niente email, niente importi.
  AD.bigliettiValidi = function () {
    function codice(seme) { var h = 2166136261, i, c = ''; for (i = 0; i < seme.length; i++) { h ^= seme.charCodeAt(i); h = Math.imul(h, 16777619) >>> 0; } var A = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; for (i = 0; i < 8; i++) { c += A[h % A.length]; h = Math.imul(h ^ (h >>> 7), 2654435761) >>> 0; } return c; }
    var righe = [];
    AD.ordini.forEach(function (o) {
      if (o.stato !== 'paid') return;
      o.righe.forEach(function (r, i) {
        if (r.tk !== 'valido') return;
        var p = r.t.split(' · ');
        righe.push([codice(o.id + i), p[p.length - 1], p[0], p[0] === 'Workshop' ? p[1] : '']);
      });
    });
    return { colonne: ['codice_breve', 'nominativo', 'tipo', 'sessione'], righe: righe };
  };

  AD.menu = function () {
    var corrente = (w.location.pathname.split('/').pop() || 'index.html');
    var n = $('#admin-nav'); if (!n) return;
    var hd = $('.site-header');

    // fascia «Vedi come ruolo» sotto l'intestazione: scelta salvata nel browser, nessun ricaricamento della pagina
    if (hd && !$('#ruolo-admin')) {
      hd.insertAdjacentHTML('afterend', '<div class="admin-ruolo"><div class="wrap admin-ruolo__in"><label for="ruolo-admin">Vedi come ruolo</label>' +
        '<select class="select" id="ruolo-admin" aria-describedby="ruolo-nota">' + Object.keys(AD.RUOLI).map(function (k) { return '<option value="' + k + '">' + AD.RUOLI[k].nome + '</option>'; }).join('') + '</select>' +
        '<p id="ruolo-nota"></p></div></div>');
      $('#ruolo-admin').value = AD.ruolo();
      $('#ruolo-admin').addEventListener('change', function (e) {
        try { w.localStorage.setItem('cv-admin-ruolo', e.target.value); } catch (x) { /* ignora */ }
        AD.applicaRuolo();
        AD.annuncio('Ora vedi il pannello come ' + AD.RUOLI[e.target.value].nome + '. Le voci non permesse sono nascoste.');
      });
    }
    AD.applicaRuolo();

    var det = $('#admin-menu'), mq = w.matchMedia('(min-width: 64rem)');
    function a() { if (mq.matches) det.open = true; }
    a(); if (mq.addEventListener) mq.addEventListener('change', a);
  };

  // Nasconde voci di menu, sezioni e pagine che il ruolo scelto non puo' usare.
  AD.applicaRuolo = function () {
    var r = AD.ruolo(), corrente = (w.location.pathname.split('/').pop() || 'index.html');
    var n = $('#admin-nav'), main = $('#contenuto');
    var pag = AD.PAGINE.filter(function (p) { return p[0] === corrente; })[0], ok = !pag || pag[2].indexOf(r) > -1;
    $('#ruolo-nota').textContent = AD.RUOLI[r].nota;
    n.innerHTML = '<ul>' + AD.PAGINE.filter(function (p) { return p[2].indexOf(r) > -1; }).map(function (p) {
      return '<li><a href="' + p[0] + '"' + (p[0] === corrente ? ' aria-current="page"' : '') + '>' + p[1] + '</a></li>';
    }).join('') + (r === 'commerciale' ? '' : '<li><a href="../checkin.html">Check-in (riferimento)' + AD.icona('external') + '</a></li>') + '<li><a href="../index.html">Sito pubblico' + AD.icona('external') + '</a></li></ul>';
    $$('[data-admin]').forEach(function (el) { el.hidden = el.getAttribute('data-admin').split(',').indexOf(r) < 0; });
    var avviso = $('#no-accesso');
    if (!ok) {
      if (!avviso) {
        main.insertAdjacentHTML('afterbegin', '<div class="no-accesso" id="no-accesso" role="status"></div>');
        avviso = $('#no-accesso');
      }
      avviso.innerHTML = '<h1 class="t-1">' + pag[1] + '</h1><div class="avviso avviso--warn" style="margin-top:var(--s-4)">' + AD.icona('lock') + '<div><p><b>Sezione non permessa al ruolo «' + AD.RUOLI[r].nome + '».</b></p><p>' +
        'Nel sito vero la voce non compare nemmeno nel menu e l’indirizzo risponde «accesso negato».</p><p><a class="btn btn--secondary" href="index.html">Torna al cruscotto</a></p></div></div>';
      main.classList.add('is-vietato');
    } else {
      if (avviso) avviso.remove();
      main.classList.remove('is-vietato');
    }
  };

  /* Elenco riordinabile: trascinamento (mouse) e pulsanti Su/Giu (tastiera e telefono). */
  AD.riordina = function (ul, dopo) {
    var trascinato = null;
    function stato() {
      var li = $$(':scope > li', ul);
      li.forEach(function (x, i) {
        var su = $('[data-su]', x), giu = $('[data-giu]', x);
        if (su) su.setAttribute('aria-disabled', i === 0); if (giu) giu.setAttribute('aria-disabled', i === li.length - 1);
      });
    }
    function sposta(li, dir, bottone) {
      var altro = dir < 0 ? li.previousElementSibling : li.nextElementSibling; if (!altro) return;
      if (dir < 0) ul.insertBefore(li, altro); else ul.insertBefore(altro, li);
      stato();
      var pos = $$(':scope > li', ul).indexOf(li) + 1, tot = $$(':scope > li', ul).length;
      AD.annuncio('«' + li.getAttribute('data-nome') + '» spostato in posizione ' + pos + ' di ' + tot + '.');
      if (bottone.getAttribute('aria-disabled') === 'true') { var o = $(dir < 0 ? '[data-giu]' : '[data-su]', li); if (o) o.focus(); } else bottone.focus();
      if (dopo) dopo(li, pos);
    }
    ul.addEventListener('click', function (e) {
      var b = e.target.closest('[data-su],[data-giu]'); if (!b || b.getAttribute('aria-disabled') === 'true') return;
      sposta(b.closest('li'), b.hasAttribute('data-su') ? -1 : 1, b);
    });
    ul.addEventListener('mousedown', function (e) { var m = e.target.closest('.maniglia'); if (m) m.closest('li').draggable = true; });
    ul.addEventListener('dragstart', function (e) {
      var li = e.target.closest('li'); if (!li || !li.draggable) return;
      trascinato = li; li.classList.add('is-trascinato'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', li.getAttribute('data-nome')); } catch (x) { /* ignora */ }
    });
    ul.addEventListener('dragover', function (e) {
      if (!trascinato) return; e.preventDefault();
      $$(':scope > li', ul).forEach(function (x) { x.classList.remove('is-sopra'); });
      var t = e.target.closest('li'); if (t && t !== trascinato) t.classList.add('is-sopra');
    });
    ul.addEventListener('drop', function (e) {
      if (!trascinato) return; e.preventDefault();
      var t = e.target.closest('li'); if (t && t !== trascinato) ul.insertBefore(trascinato, t);
      var pos = $$(':scope > li', ul).indexOf(trascinato) + 1;
      AD.annuncio('«' + trascinato.getAttribute('data-nome') + '» spostato in posizione ' + pos + '.');
      if (dopo) dopo(trascinato, pos);
    });
    ul.addEventListener('dragend', function () {
      $$(':scope > li', ul).forEach(function (x) { x.classList.remove('is-sopra', 'is-trascinato'); x.draggable = false; });
      trascinato = null; stato();
    });
    stato();
  };
  // Riga per un elenco riordinabile
  AD.rigaRiordina = function (nome, corpo) {
    return '<li data-nome="' + nome + '"><button type="button" class="maniglia" tabindex="-1" aria-hidden="true" title="Trascina">' + AD.icona('grip') + '</button>' +
      '<div>' + corpo + '</div><div class="riordina__azioni"><button type="button" class="btn btn--secondary btn--icona" data-su aria-label="Sposta su: ' + nome + '">' + AD.icona('up') + '</button>' +
      '<button type="button" class="btn btn--secondary btn--icona" data-giu aria-label="Sposta giù: ' + nome + '">' + AD.icona('down') + '</button></div></li>';
  };

  w.AD = AD;
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', AD.menu); else AD.menu();
}(window, document));
