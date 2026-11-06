/*
  Autore: Giulio Occhipinti
  Pannello admin del prototipo: menu comune, dati inventati (ordini, membri, codici),
  elenco riordinabile con trascinamento simulato e alternativa da tastiera.
  Ogni scrittura vera, nel sito, richiede sessione con MFA (aal2) e finisce nel registro audit.
*/
(function (w, d) {
  'use strict';
  var AD = {};
  var $ = function (s, r) { return (r || d).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); };

  AD.PAGINE = [
    ['index.html', 'Cruscotto'], ['agenda.html', 'Programma'], ['speaker.html', 'Speaker'], ['biglietti.html', 'Biglietti e prezzi'],
    ['sconti.html', 'Codici sconto'], ['ordini.html', 'Ordini'], ['membri.html', 'Membri']
  ];

  /* ---------------- dati d'esempio ---------------- */
  AD.STATO_ORD = {
    pending: ['info', 'clock', 'In attesa di pagamento'], processing: ['info', 'clock', 'In elaborazione'], paid: ['ok', 'check', 'Pagato'],
    failed: ['err', 'alert', 'Non riuscito'], expired: ['warn', 'clock', 'Scaduto'], refunded: ['err', 'x', 'Rimborsato'],
    partial: ['warn', 'x', 'Rimborso parziale'], review: ['warn', 'alert', 'Da rivedere']
  };
  AD.ordini = [
    { id: 'CV-1048', data: '15/03/2027 18:02', nome: 'Giada Segnaposto', email: 'giada.segnaposto@example.test', stato: 'review', ruolo: 'membro', pi: 'pi_…c91d',
      righe: [{ t: 'Pass intero · Giada Segnaposto', v: 100, tk: 'valido' }], sconto: 0, totale: 100, nota: 'Importo incassato su Stripe: 98 €. Non coincide con l’ordine: da riconciliare.',
      eventi: [['15/03 18:02', 'checkout.session.completed', 'importo diverso, ordine segnato da rivedere']] },
    { id: 'CV-1047', data: '14/03/2027 09:40', nome: 'Fabio Inventato', email: 'fabio.inventato@example.test', stato: 'refunded', ruolo: 'pubblico', pi: 'pi_…2b8e',
      righe: [{ t: 'Pass giornaliero · Venerdì 15 · Fabio Inventato', v: 65, tk: 'rimborsato' }], sconto: 0, totale: 65,
      eventi: [['14/03 09:41', 'checkout.session.completed', 'elaborato'], ['16/03 11:20', 'charge.refunded', 'rimborso totale registrato']] },
    { id: 'CV-1046', data: '14/03/2027 08:15', nome: 'Elena Fittizia', email: 'elena.fittizia@example.test', stato: 'expired', ruolo: 'pubblico', pi: '—',
      righe: [{ t: 'Workshop · Mappe partecipate · Elena Fittizia', v: 40, tk: '—' }], sconto: 0, totale: 40,
      eventi: [['14/03 08:45', 'checkout.session.expired', 'posti rilasciati']] },
    { id: 'CV-1045', data: '13/03/2027 21:30', nome: 'Dario Finto', email: 'dario.finto@example.test', stato: 'failed', ruolo: 'pubblico', pi: 'pi_…55aa',
      righe: [{ t: 'Pass intero · Dario Finto', v: 150, tk: '—' }], sconto: 0, totale: 150,
      eventi: [['13/03 21:31', 'checkout.session.async_payment_failed', 'ordine non riuscito, posti rilasciati, email mandata']] },
    { id: 'CV-1044', data: '13/03/2027 10:05', nome: 'Carla Prova', email: 'carla.prova@example.test', stato: 'paid', ruolo: 'pubblico', pi: 'pi_…9e10',
      righe: [{ t: 'Pass intero · Carla Prova', v: 150, tk: 'valido' }], sconto: 15, codice: 'BENVENUTO10', totale: 135,
      eventi: [['13/03 10:06', 'checkout.session.completed', 'elaborato, codice contato']] },
    { id: 'CV-1043', data: '12/03/2027 10:20', nome: 'Bruno Esempio', email: 'bruno.esempio@example.test', stato: 'processing', ruolo: 'pubblico', pi: 'pi_…3c42',
      righe: [{ t: 'Pass giornaliero · Venerdì 15 · Bruno Esempio', v: 65, tk: '—' }, { t: 'Pass giornaliero · Venerdì 15 · Anna Ospite', v: 65, tk: '—' }], sconto: 0, totale: 130,
      eventi: [['12/03 10:21', 'checkout.session.completed', 'pagamento non ancora confermato (metodo a notifica differita)']] },
    { id: 'CV-1042', data: '12/03/2027 10:14', nome: 'Alessia Demo', email: 'alessia.demo@example.test', stato: 'paid', ruolo: 'membro', pi: 'pi_…7f3a',
      righe: [{ t: 'Pass intero · Alessia Demo', v: 100, tk: 'valido' }, { t: 'Workshop · Progettare un servizio culturale · Alessia Demo', v: 35, tk: 'valido' }], sconto: 0, totale: 135,
      eventi: [['12/03 10:15', 'checkout.session.completed', 'elaborato'], ['12/03 10:15', 'Email di conferma', 'in coda, poi inviata']] }
  ];
  AD.membri = [
    { id: 'm1', nome: 'Alessia Demo', email: 'alessia.demo@example.test', stato: 'active', fine: '30/09/2027', rinnovo: 'automatico' },
    { id: 'm2', nome: 'Tiziano Campione', email: 'tiziano.campione@example.test', stato: 'active', fine: '14/06/2027', rinnovo: 'automatico' },
    { id: 'm3', nome: 'Ornella Saggio', email: 'ornella.saggio@example.test', stato: 'canceling', fine: '02/05/2027', rinnovo: 'disdetto, non si rinnova' },
    { id: 'm4', nome: 'Pietro Lento', email: 'pietro.lento@example.test', stato: 'past_due', fine: '10/03/2027', rinnovo: 'pagamento del rinnovo non riuscito' },
    { id: 'm5', nome: 'Rosa Antica', email: 'rosa.antica@example.test', stato: 'expired', fine: '30/09/2026', rinnovo: 'scaduta' },
    { id: 'm6', nome: 'Vito Nuovo', email: 'vito.nuovo@example.test', stato: 'trialing', fine: '28/03/2027', rinnovo: 'in prova, poi automatico' }
  ];
  AD.STATO_MEM = { active: ['ok', 'check', 'Attiva'], trialing: ['info', 'clock', 'In prova'], canceling: ['warn', 'clock', 'Disdetta a fine periodo'], past_due: ['err', 'alert', 'Pagamento non riuscito'], expired: ['err', 'x', 'Scaduta'] };
  AD.sconti = [
    { codice: 'BENVENUTO10', tipo: '%', valore: 10, ruoli: 'Pubblico', biglietti: 'Tutti', dal: '01/02/2027', al: '31/12/2027', usi: 37, limite: 200, cumulabile: false, stato: 'attivo' },
    { codice: 'STAFF100', tipo: '%', valore: 100, ruoli: 'Tutti', biglietti: 'Pass intero', dal: '01/02/2027', al: '14/10/2027', usi: 4, limite: 15, cumulabile: true, stato: 'attivo' },
    { codice: 'EARLY2027', tipo: '€', valore: 20, ruoli: 'Pubblico, Membro', biglietti: 'Pass intero', dal: '01/02/2027', al: '30/04/2027', usi: 100, limite: 100, cumulabile: false, stato: 'esaurito' },
    { codice: 'SCADUTO24', tipo: '%', valore: 15, ruoli: 'Tutti', biglietti: 'Tutti', dal: '01/10/2026', al: '31/12/2026', usi: 12, limite: 50, cumulabile: false, stato: 'scaduto' }
  ];

  /* ---------------- pezzi comuni ---------------- */
  AD.icona = function (n) { return '<svg class="ic" aria-hidden="true" focusable="false"><use href="#i-' + n + '"></use></svg>'; };
  AD.badgeOrd = function (s) { var x = AD.STATO_ORD[s]; return '<span class="badge badge--' + x[0] + '">' + AD.icona(x[1]) + x[2] + '</span>'; };
  AD.euro = function (n) { return n.toLocaleString('it-IT') + ' €'; };
  AD.param = function (n) { try { return new URLSearchParams(w.location.search).get(n); } catch (e) { return null; } };

  // Messaggio per lettori di schermo (la modifica e' comunque visibile a schermo).
  AD.annuncio = function (t) {
    var a = $('#annuncio');
    if (!a) { a = d.createElement('div'); a.id = 'annuncio'; a.className = 'sr-only'; a.setAttribute('role', 'status'); a.setAttribute('aria-live', 'polite'); d.body.appendChild(a); }
    a.textContent = ''; setTimeout(function () { a.textContent = t; }, 30);
  };

  AD.menu = function () {
    var corrente = (w.location.pathname.split('/').pop() || 'index.html');
    var n = $('#admin-nav'); if (!n) return;
    n.innerHTML = '<ul>' + AD.PAGINE.map(function (p) {
      return '<li><a href="' + p[0] + '"' + (p[0] === corrente ? ' aria-current="page"' : '') + '>' + p[1] + '</a></li>';
    }).join('') + '<li><a href="../checkin.html">Check-in (staff)' + AD.icona('external') + '</a></li><li><a href="../index.html">Sito pubblico' + AD.icona('external') + '</a></li></ul>';
    var det = $('#admin-menu'), mq = w.matchMedia('(min-width: 64rem)');
    function a() { if (mq.matches) det.open = true; }
    a(); if (mq.addEventListener) mq.addEventListener('change', a);
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
