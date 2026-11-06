/* Autore: Giulio Occhipinti. Layout 2 «Clinico»: menu, giorni del programma, ricerca, barra d'acquisto, totale ordine. */
(function () {
  'use strict';
  document.documentElement.classList.add('js');

  /* Menu mobile */
  var toggle = document.querySelector('.menu-toggle');
  var nav = document.getElementById('site-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!open));
      nav.classList.toggle('is-open', !open);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        toggle.setAttribute('aria-expanded', 'false');
        nav.classList.remove('is-open');
        toggle.focus();
      }
    });
    nav.addEventListener('click', function (e) {
      if (e.target.closest('a')) { toggle.setAttribute('aria-expanded', 'false'); nav.classList.remove('is-open'); }
    });
  }

  /* Schede dei giorni (tasti freccia come da pattern ARIA) */
  var tabs = Array.prototype.slice.call(document.querySelectorAll('[role="tab"]'));
  var panels = tabs.map(function (t) { return document.getElementById(t.getAttribute('aria-controls')); });
  function select(i, focus) {
    tabs.forEach(function (t, j) {
      var on = i === j;
      t.setAttribute('aria-selected', String(on));
      t.tabIndex = on ? 0 : -1;
      panels[j].hidden = !on;
    });
    if (focus) tabs[i].focus();
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { clearSearch(); select(i); });
    t.addEventListener('keydown', function (e) {
      var n = tabs.length, k = null;
      if (e.key === 'ArrowRight') k = (i + 1) % n;
      else if (e.key === 'ArrowLeft') k = (i - 1 + n) % n;
      else if (e.key === 'Home') k = 0;
      else if (e.key === 'End') k = n - 1;
      if (k !== null) { e.preventDefault(); clearSearch(); select(k, true); }
    });
  });

  /* Ricerca nel programma (sulle sessioni dimostrative della pagina) */
  var form = document.querySelector('.search');
  var input = document.getElementById('q');
  var status = document.getElementById('filter-status');
  var days = document.querySelector('.days');
  function clearSearch() {
    if (!input || !input.value) return;
    input.value = '';
    filter('');
  }
  function filter(raw) {
    raw = raw.trim();
    var q = raw.toLowerCase();
    var hits = 0;
    panels.forEach(function (p) {
      var rows = p.querySelectorAll('tbody tr'), any = 0;
      Array.prototype.forEach.call(rows, function (r) {
        var ok = !q || r.textContent.toLowerCase().indexOf(q) !== -1;
        r.hidden = !ok;
        if (ok) { any++; }
      });
      hits += any;
      if (q) p.hidden = any === 0;
    });
    if (q) {
      days.classList.add('is-filtering');
      status.textContent = hits === 0
        ? 'No demo session matches “' + raw + '”.'
        : hits + (hits === 1 ? ' session matches' : ' sessions match') + ' “' + raw + '” across the three days.';
    } else {
      days.classList.remove('is-filtering');
      status.textContent = '';
      var cur = tabs.findIndex(function (t) { return t.getAttribute('aria-selected') === 'true'; });
      select(cur < 0 ? 0 : cur);
    }
  }
  if (form && input && panels.length) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      filter(input.value);
      document.getElementById('programme').scrollIntoView({ block: 'start' });
      status.setAttribute('tabindex', '-1');
      status.focus({ preventScroll: true });
    });
    input.addEventListener('search', function () { if (!input.value) filter(''); });
  }

  /* Barra d'acquisto in basso: compare quando il bottone della prima schermata non si vede più */
  var bar = document.getElementById('buybar');
  var cta = document.getElementById('hero-cta');
  if (bar && cta && 'IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      bar.hidden = entries[0].isIntersecting || entries[0].boundingClientRect.top > 0;
    }).observe(cta);
  }

  /* Ordine: righe e totale */
  var order = document.getElementById('order');
  if (order) {
    var lines = document.getElementById('sum-lines');
    var total = document.getElementById('sum-total');
    var barTotal = document.getElementById('bar-total');
    var err = document.getElementById('sum-error');
    var done = document.getElementById('sum-status');
    var eur = function (v) { return '€' + v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); };
    var update = function () {
      var sum = 0, html = '';
      var pass = order.querySelector('input[name="pass"]:checked');
      if (pass && Number(pass.dataset.price) > 0) {
        sum += Number(pass.dataset.price);
        html += '<li><span>' + pass.dataset.label + '</span><span class="num">' + eur(Number(pass.dataset.price)) + '</span></li>';
      }
      order.querySelectorAll('input[name="ws"]:checked').forEach(function (w) {
        sum += 100;
        html += '<li><span>' + w.dataset.label + '</span><span class="num">€100.00</span></li>';
      });
      lines.innerHTML = html || '<li class="muted"><span>Nothing selected yet</span></li>';
      total.textContent = eur(sum);
      if (barTotal) barTotal.textContent = eur(sum);
      err.hidden = true;
      done.textContent = '';
      return sum;
    };
    order.addEventListener('change', update);
    order.addEventListener('submit', function (e) {
      e.preventDefault();
      if (update() === 0) { err.hidden = false; return; }
      done.textContent = 'Demo: on the live site this opens the attendee details and then Stripe payment.';
    });
    update();
  }
})();
