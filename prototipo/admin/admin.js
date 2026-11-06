/*
  Autore: Giulio Occhipinti
  Pannello admin del prototipo (EAS8 Lisbon), testi in inglese: menu comune con selettore «View as role», dati d'esempio
  inventati (ordini, membri, codici, omaggi), elenco riordinabile con trascinamento simulato e alternativa da tastiera,
  esportazione simulata dei biglietti validi per il partner.
  Ruoli del backend (Laravel + Filament, risposte del cliente del 06/11/2026): admin, commerciale (pagamenti e fatture),
  organizzatore (biglietti, fasce, date). I codici sconto li creano sia il commerciale sia l'organizzatore.
  Nel sito vero i permessi sono policy di Laravel, non un selettore. Ogni scrittura vera richiede un accesso con
  secondo fattore e finisce nel registro audit.
*/
(function (w, d) {
  'use strict';
  var AD = {};
  var $ = function (s, r) { return (r || d).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); };

  /* ---------------- ruoli e voci di menu ---------------- */
  AD.RUOLI = {
    admin: { nome: 'Admin', nota: 'Sees and does everything: programme, tickets, tiers, payments, members, codes, videos.' },
    commerciale: { nome: 'Commercial', nota: 'Payments and invoices: orders, refunds, members, discount codes, complimentary tickets. Does not edit programme, tiers or dates.' },
    organizzatore: { nome: 'Organizer', nota: 'Tickets, tiers and dates: programme, speakers, prices, capacities, discount codes, complimentary tickets, videos, partner export. Does not see payments and invoices.' }
  };
  // [file, titolo, ruoli che la vedono]
  AD.PAGINE = [
    ['index.html', 'Dashboard', ['admin', 'commerciale', 'organizzatore']],
    ['agenda.html', 'Programme', ['admin', 'organizzatore']],
    ['speaker.html', 'Speakers', ['admin', 'organizzatore']],
    ['biglietti.html', 'Tickets, tiers and dates', ['admin', 'organizzatore']],
    ['omaggi.html', 'Complimentary tickets', ['admin', 'commerciale', 'organizzatore']],
    ['sconti.html', 'Discount codes', ['admin', 'commerciale', 'organizzatore']],
    ['video.html', 'Videos', ['admin', 'organizzatore']],
    ['ordini.html', 'Orders and invoices', ['admin', 'commerciale']],
    ['membri.html', 'Members', ['admin', 'commerciale']]
  ];
  AD.ruolo = function () {
    try { var r = w.localStorage.getItem('cv-admin-ruolo'); if (AD.RUOLI[r]) return r; } catch (e) { /* storage non disponibile */ }
    return 'admin';
  };

  /* ---------------- dati d'esempio ---------------- */
  // Prezzi veri del cliente (fascia early bird aperta il 06/11/2026: il pubblico paga 695 €, il membro 276,50 €; workshop 100 €).
  // Accompagnatore 150 € ed espositore 450 €: prezzi segnaposto. Nomi, ordini, date degli ordini, vendite: inventati.
  AD.STATO_ORD = {
    pending: ['info', 'clock', 'Waiting for payment'], paid: ['ok', 'check', 'Paid'], comp: ['info', 'gift', 'Complimentary'],
    failed: ['err', 'alert', 'Failed'], expired: ['warn', 'clock', 'Expired'], refunded: ['err', 'x', 'Refunded'],
    partial: ['warn', 'x', 'Partly refunded'], review: ['warn', 'alert', 'To review']
  };
  AD.ordini = [
    { id: 'CV-1052', data: '06/11/2026 11:40', nome: 'Rita Lorem', email: 'rita.lorem@example.test', stato: 'comp', ruolo: 'omaggio', pi: '—',
      righe: [{ t: 'Complimentary · Full pass · Rita Lorem (speaker)', v: 0, tk: 'valido' }], sconto: 0, totale: 0,
      eventi: [['06/11 11:40', 'Issued by Organizer', 'complimentary ticket for a speaker, email sent']] },
    { id: 'CV-1051', data: '06/11/2026 10:05', nome: 'Hugo Sketch', email: 'hugo.sketch@example.test', stato: 'paid', ruolo: 'pubblico', pi: 'pi_…a71c', azienda: 'Placeholder Aligners Ltd',
      righe: [{ t: 'Exhibitor / Sponsor pass · Hugo Sketch', v: 450, tk: 'valido' }], sconto: 0, totale: 450, invito: 'EXPO2027',
      eventi: [['06/11 10:06', 'checkout.session.completed', 'processed, invitation EXPO2027 linked to stand B12']] },
    { id: 'CV-1050', data: '06/11/2026 09:12', nome: 'Alessia Demo', email: 'alessia.demo@example.test', stato: 'paid', ruolo: 'membro', pi: 'pi_…0d2f',
      righe: [{ t: 'Companion · Sara Guest (with the pass of Alessia Demo)', v: 150, tk: 'valido' }], sconto: 0, totale: 150,
      eventi: [['06/11 09:13', 'checkout.session.completed', 'processed, companion linked to pass K7M2QX4P']] },
    { id: 'CV-1048', data: '05/11/2026 18:02', nome: 'Giada Segnaposto', email: 'giada.segnaposto@example.test', stato: 'review', ruolo: 'membro', pi: 'pi_…c91d',
      righe: [{ t: 'Full pass · Giada Segnaposto', v: 276.5, tk: 'valido' }], sconto: 0, totale: 276.5, nota: 'Amount collected on Stripe: €267.50. It does not match the order (€276.50): to reconcile.',
      eventi: [['05/11 18:02', 'checkout.session.completed', 'different amount, order marked to review']] },
    { id: 'CV-1047', data: '04/11/2026 09:40', nome: 'Fabio Inventato', email: 'fabio.inventato@example.test', stato: 'refunded', ruolo: 'pubblico', pi: 'pi_…2b8e',
      righe: [{ t: 'Full pass · Fabio Inventato', v: 695, tk: 'rimborsato' }], sconto: 0, totale: 695,
      eventi: [['04/11 09:41', 'checkout.session.completed', 'processed'], ['05/11 11:20', 'charge.refunded', 'full refund recorded (paid when seats had sold out)']] },
    { id: 'CV-1046', data: '04/11/2026 08:15', nome: 'Elena Fittizia', email: 'elena.fittizia@example.test', stato: 'expired', ruolo: 'pubblico', pi: '—',
      righe: [{ t: 'Workshop · Hands-on: finishing and detailing · Elena Fittizia', v: 100, tk: '—' }], sconto: 0, totale: 100,
      eventi: [['04/11 08:45', 'checkout.session.expired', 'seats released']] },
    { id: 'CV-1045', data: '03/11/2026 21:30', nome: 'Dario Finto', email: 'dario.finto@example.test', stato: 'failed', ruolo: 'pubblico', pi: 'pi_…55aa',
      righe: [{ t: 'Full pass · Dario Finto', v: 695, tk: '—' }], sconto: 0, totale: 695,
      eventi: [['03/11 21:31', 'payment_intent.payment_failed', 'card declined, seats released, email sent']] },
    { id: 'CV-1044', data: '03/11/2026 10:05', nome: 'Carla Prova', email: 'carla.prova@example.test', stato: 'paid', ruolo: 'pubblico', pi: 'pi_…9e10',
      righe: [{ t: 'Full pass · Carla Prova', v: 695, tk: 'valido' }], sconto: 69.5, codice: 'WELCOME10', totale: 625.5,
      eventi: [['03/11 10:06', 'checkout.session.completed', 'processed, code counted']] },
    { id: 'CV-1043', data: '05/11/2026 10:20', nome: 'Bruno Esempio', email: 'bruno.esempio@example.test', stato: 'pending', ruolo: 'pubblico', pi: '—',
      righe: [{ t: 'Full pass · Bruno Esempio', v: 695, tk: '—' }, { t: 'Full pass · Anna Ospite', v: 695, tk: '—' }], sconto: 0, totale: 1390,
      eventi: [['05/11 10:20', 'checkout.session.created', 'customer still on the Stripe page; seats held for a short time (to be defined)']] },
    { id: 'CV-1042', data: '05/11/2026 10:14', nome: 'Alessia Demo', email: 'alessia.demo@example.test', stato: 'paid', ruolo: 'membro', pi: 'pi_…7f3a',
      righe: [{ t: 'Full pass · Alessia Demo', v: 276.5, tk: 'valido' }, { t: 'Workshop · Hands-on: attachment placement and templates · Alessia Demo', v: 100, tk: 'valido' }], sconto: 0, totale: 376.5,
      eventi: [['05/11 10:15', 'checkout.session.completed', 'processed'], ['05/11 10:15', 'Confirmation email', 'queued, then sent']] }
  ];
  AD.membri = [
    { id: 'm1', nome: 'Alessia Demo', email: 'alessia.demo@example.test', stato: 'active', fine: '30/09/2027', rinnovo: 'automatic' },
    { id: 'm2', nome: 'Tiziano Campione', email: 'tiziano.campione@example.test', stato: 'active', fine: '14/06/2027', rinnovo: 'automatic' },
    { id: 'm3', nome: 'Ornella Saggio', email: 'ornella.saggio@example.test', stato: 'canceling', fine: '02/05/2027', rinnovo: 'stopped, will not renew' },
    { id: 'm4', nome: 'Pietro Lento', email: 'pietro.lento@example.test', stato: 'past_due', fine: '03/11/2026', rinnovo: 'renewal payment failed' },
    { id: 'm5', nome: 'Rosa Antica', email: 'rosa.antica@example.test', stato: 'expired', fine: '30/09/2026', rinnovo: 'expired' },
    { id: 'm6', nome: 'Vito Nuovo', email: 'vito.nuovo@example.test', stato: 'active', fine: '06/11/2027', rinnovo: 'automatic · joined today, active right after payment' }
  ];
  AD.STATO_MEM = { active: ['ok', 'check', 'Active'], canceling: ['warn', 'clock', 'Ends at period end'], past_due: ['err', 'alert', 'Payment failed'], expired: ['err', 'x', 'Expired'] };
  AD.sconti = [
    { codice: 'WELCOME10', genere: 'sconto', tipo: '%', valore: 10, ruoli: 'Public', biglietti: 'All', dal: '06/11/2026', al: '31/03/2027', usi: 37, limite: 200, cumulabile: false, stato: 'attivo', da: 'Commercial' },
    { codice: 'PARTNER2027', genere: 'sconto', tipo: '€', valore: 20, ruoli: 'Public, Member', biglietti: 'Full pass', dal: '06/11/2026', al: '30/11/2026', usi: 100, limite: 100, cumulabile: false, stato: 'esaurito', da: 'Organizer' },
    { codice: 'EXPO2027', genere: 'invito', tipo: '%', valore: 0, ruoli: 'Public, Member', biglietti: 'Exhibitor / Sponsor pass', dal: '06/11/2026', al: '08/04/2027', usi: 6, limite: 40, cumulabile: false, stato: 'attivo', da: 'Organizer' },
    { codice: 'EXPIRED24', genere: 'sconto', tipo: '%', valore: 15, ruoli: 'All', biglietti: 'All', dal: '01/10/2026', al: '31/10/2026', usi: 12, limite: 50, cumulabile: false, stato: 'scaduto', da: 'Commercial' }
  ];
  AD.omaggi = [
    { id: 'o1', nome: 'Rita Lorem', email: 'rita.lorem@example.test', cat: 'Speaker', tipo: 'Full pass', da: 'Organizer', data: '06/11/2026', stato: 'inviato', ordine: 'CV-1052' },
    { id: 'o2', nome: 'Sami Specimen', email: 'sami.specimen@example.test', cat: 'Speaker', tipo: 'Full pass', da: 'Admin', data: '05/11/2026', stato: 'inviato', ordine: 'CV-1049' },
    { id: 'o3', nome: 'Wanda Variant', email: 'wanda.variant@example.test', cat: 'Staff', tipo: 'Full pass', da: 'Organizer', data: '05/11/2026', stato: 'revocato', ordine: 'CV-1041' }
  ];

  /* ---------------- pezzi comuni ---------------- */
  AD.icona = function (n) { return '<svg class="ic" aria-hidden="true" focusable="false"><use href="#i-' + n + '"></use></svg>'; };
  AD.badgeOrd = function (s) { var x = AD.STATO_ORD[s]; return '<span class="badge badge--' + x[0] + '">' + AD.icona(x[1]) + x[2] + '</span>'; };
  AD.euro = function (n) { return w.CV ? w.CV.euro(n) : '€' + n; };
  AD.param = function (n) { try { return new URLSearchParams(w.location.search).get(n); } catch (e) { return null; } };

  // Messaggio per lettori di schermo (la modifica e' comunque visibile a schermo).
  AD.annuncio = function (t) {
    var a = $('#annuncio');
    if (!a) { a = d.createElement('div'); a.id = 'annuncio'; a.className = 'sr-only'; a.setAttribute('role', 'status'); a.setAttribute('aria-live', 'polite'); d.body.appendChild(a); }
    a.textContent = ''; setTimeout(function () { a.textContent = t; }, 30);
  };

  // Esportazione SIMULATA dei biglietti validi per il partner che fa il check-in sul posto.
  // Biglietti di ordini pagati e non rimborsati, piu' gli omaggi emessi. Colonne minime: niente email, niente importi.
  AD.bigliettiValidi = function () {
    function codice(seme) { var h = 2166136261, i, c = ''; for (i = 0; i < seme.length; i++) { h ^= seme.charCodeAt(i); h = Math.imul(h, 16777619) >>> 0; } var A = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; for (i = 0; i < 8; i++) { c += A[h % A.length]; h = Math.imul(h ^ (h >>> 7), 2654435761) >>> 0; } return c; }
    var righe = [];
    AD.ordini.forEach(function (o) {
      if (o.stato !== 'paid' && o.stato !== 'comp') return;
      o.righe.forEach(function (r, i) {
        if (r.tk !== 'valido') return;
        var p = r.t.split(' · ');
        righe.push([codice(o.id + i), p[p.length - 1], p[0], p[0] === 'Workshop' ? p[1] : '']);
      });
    });
    return { colonne: ['short_code', 'name', 'type', 'session'], righe: righe };
  };

  AD.menu = function () {
    var n = $('#admin-nav'); if (!n) return;
    var hd = $('.site-header');
    // fascia «View as role» sotto l'intestazione: scelta salvata nel browser, nessun ricaricamento della pagina
    if (hd && !$('#ruolo-admin')) {
      hd.insertAdjacentHTML('afterend', '<div class="admin-ruolo"><div class="wrap admin-ruolo__in"><label for="ruolo-admin">View as role</label>' +
        '<select class="select" id="ruolo-admin" aria-describedby="ruolo-nota">' + Object.keys(AD.RUOLI).map(function (k) { return '<option value="' + k + '">' + AD.RUOLI[k].nome + '</option>'; }).join('') + '</select>' +
        '<p id="ruolo-nota"></p></div></div>');
      $('#ruolo-admin').value = AD.ruolo();
      $('#ruolo-admin').addEventListener('change', function (e) {
        try { w.localStorage.setItem('cv-admin-ruolo', e.target.value); } catch (x) { /* ignora */ }
        AD.applicaRuolo();
        AD.annuncio('You now see the panel as ' + AD.RUOLI[e.target.value].nome + '. Sections not allowed are hidden.');
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
    }).join('') + (r === 'commerciale' ? '' : '<li><a href="../checkin.html">Check-in (reference)' + AD.icona('external') + '</a></li>') + '<li><a href="../index.html">Public site' + AD.icona('external') + '</a></li></ul>';
    $$('[data-admin]').forEach(function (el) { el.hidden = el.getAttribute('data-admin').split(',').indexOf(r) < 0; });
    var avviso = $('#no-accesso');
    if (!ok) {
      if (!avviso) {
        main.insertAdjacentHTML('afterbegin', '<div class="no-accesso" id="no-accesso" role="status"></div>');
        avviso = $('#no-accesso');
      }
      avviso.innerHTML = '<h1 class="t-1">' + pag[1] + '</h1><div class="avviso avviso--warn" style="margin-top:var(--s-4)">' + AD.icona('lock') + '<div><p><b>This section is not available to the «' + AD.RUOLI[r].nome + '» role.</b></p><p>' +
        'On the real site the item does not even appear in the menu, and the address answers «access denied».</p><p><a class="btn btn--secondary" href="index.html">Back to the dashboard</a></p></div></div>';
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
      AD.annuncio('«' + li.getAttribute('data-nome') + '» moved to position ' + pos + ' of ' + tot + '.');
      if (bottone.getAttribute('aria-disabled') === 'true') { var o = $(dir < 0 ? '[data-giu]' : '[data-su]', li); if (o) o.focus(); } else bottone.focus();
      if (dopo) dopo(li, pos);
    }
    if (ul.getAttribute('data-riordina')) { stato(); return; }   // gia' collegato: aggiorna solo lo stato dei pulsanti
    ul.setAttribute('data-riordina', '1');
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
      AD.annuncio('«' + trascinato.getAttribute('data-nome') + '» moved to position ' + pos + '.');
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
    return '<li data-nome="' + nome + '"><button type="button" class="maniglia" tabindex="-1" aria-hidden="true" title="Drag">' + AD.icona('grip') + '</button>' +
      '<div>' + corpo + '</div><div class="riordina__azioni"><button type="button" class="btn btn--secondary btn--icona" data-su aria-label="Move up: ' + nome + '">' + AD.icona('up') + '</button>' +
      '<button type="button" class="btn btn--secondary btn--icona" data-giu aria-label="Move down: ' + nome + '">' + AD.icona('down') + '</button></div></li>';
  };

  // Intestazione comune del pannello (scritta qui per non ripeterla in ogni pagina)
  AD.testata = function () {
    var h = $('header.site-header');
    if (!h || h.children.length) return;
    h.innerHTML = '<div class="wrap site-header__in"><a class="brand" href="index.html" aria-label="EAS8 Lisbon, admin, dashboard"><img src="../assets/eas-logo.png" width="84" height="47" alt="EAS, European Aligner Society"><span class="brand__testo">Admin</span></a>' +
      '<span class="badge badge--ok">' + AD.icona('lock') + 'Session with MFA verified</span></div>';
  };

  w.AD = AD;
  function avvio() { AD.testata(); AD.menu(); }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', avvio); else avvio();
}(window, document));
