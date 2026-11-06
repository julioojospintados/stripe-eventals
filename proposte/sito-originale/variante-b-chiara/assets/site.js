/* Autore: Giulio Occhipinti. Variante B «Chiara»: JS minimo e solo locale (menu, accordion, elenco relatori, moduli finti). */
(function () {
  'use strict';

  /* Menu mobile */
  var toggle = document.querySelector('.nav-toggle');
  var nav = document.getElementById('site-nav');
  if (toggle && nav) {
    var setOpen = function (open) {
      toggle.setAttribute('aria-expanded', String(open));
      nav.classList.toggle('is-open', open);
    };
    toggle.addEventListener('click', function () {
      setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        setOpen(false);
        toggle.focus();
      }
    });
  }

  /* Accordion FAQ (pattern APG: bottone nel titolo, aria-expanded, aria-controls) */
  document.querySelectorAll('.acc-btn').forEach(function (btn) {
    var panel = document.getElementById(btn.getAttribute('aria-controls'));
    if (!panel) return;
    btn.setAttribute('aria-expanded', 'false');
    panel.hidden = true;
    btn.addEventListener('click', function () {
      var open = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', String(!open));
      panel.hidden = open;
    });
  });
  /* Apri la domanda indicata nell'indirizzo, se c'è (es. faq.html#q-cancel) */
  if (location.hash) {
    var target = document.getElementById(location.hash.slice(1));
    var b = target && target.querySelector('.acc-btn');
    if (b) b.click();
  }

  /* Relatori: i primi 9 visibili, gli altri con un tocco */
  var list = document.querySelector('.speakers[data-collapsible]');
  var more = document.querySelector('.speakers-more');
  if (list && more) {
    var total = list.children.length;
    list.classList.add('is-collapsed');
    more.hidden = false;
    var label = more.querySelector('span');
    more.addEventListener('click', function () {
      var collapsed = list.classList.toggle('is-collapsed');
      more.setAttribute('aria-expanded', String(!collapsed));
      label.textContent = collapsed ? 'Show all ' + total + ' speakers' : 'Show fewer speakers';
      if (!collapsed) {
        var first = list.querySelector('.speaker.extra h3');
        if (first) { first.setAttribute('tabindex', '-1'); first.focus(); }
      }
    });
  }

  /* Moduli finti: controllo dei campi obbligatori, nessun invio */
  document.querySelectorAll('form[data-demo]').forEach(function (form) {
    var status = form.querySelector('.form-status');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var firstBad = null;
      form.querySelectorAll('[required]').forEach(function (field) {
        var err = document.getElementById(field.id + '-err');
        var msg = '';
        if (field.type === 'checkbox' && !field.checked) msg = 'Please tick this box to continue.';
        else if (field.type !== 'checkbox' && !field.value.trim()) msg = 'Please fill in this field.';
        else if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value.trim())) msg = 'Enter an email address like name@example.com.';
        field.setAttribute('aria-invalid', msg ? 'true' : 'false');
        if (err) err.textContent = msg;
        if (msg && !firstBad) firstBad = field;
      });
      if (firstBad) { if (status) status.textContent = ''; firstBad.focus(); return; }
      if (status) status.textContent = form.getAttribute('data-demo');
      form.reset();
    });
  });
})();
