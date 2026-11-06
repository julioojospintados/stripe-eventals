/* Autore: Giulio Occhipinti. Variante C «Trasparenze»: menu, accordion, elenco speaker e moduli dimostrativi. Solo locale. */
(function () {
  'use strict';

  /* Menu: su telefono è un pannello sopra il dock in basso */
  var btn = document.querySelector('.menu-btn');
  var nav = document.getElementById('site-nav');
  if (btn && nav) {
    var label = btn.querySelector('.menu-btn__label');
    var setOpen = function (open, returnFocus) {
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      nav.classList.toggle('is-open', open);
      if (label) label.textContent = open ? 'Close' : 'Menu';
      if (open) {
        var first = nav.querySelector('a');
        if (first) first.focus();
      } else if (returnFocus) {
        btn.focus();
      }
    };
    btn.addEventListener('click', function () {
      setOpen(btn.getAttribute('aria-expanded') !== 'true', false);
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && nav.classList.contains('is-open')) setOpen(false, true);
    });
    document.addEventListener('click', function (ev) {
      if (nav.classList.contains('is-open') && !nav.contains(ev.target) && !btn.contains(ev.target)) setOpen(false, false);
    });
    nav.addEventListener('focusout', function (ev) {
      if (nav.classList.contains('is-open') && ev.relatedTarget && !nav.contains(ev.relatedTarget) && ev.relatedTarget !== btn) setOpen(false, false);
    });
    window.matchMedia('(min-width: 60em)').addEventListener('change', function (mq) {
      if (mq.matches) setOpen(false, false);
    });
  }

  /* Accordion delle FAQ */
  document.querySelectorAll('[data-accordion] .acc__btn').forEach(function (b) {
    var panel = document.getElementById(b.getAttribute('aria-controls'));
    if (!panel) return;
    b.setAttribute('aria-expanded', 'false');
    panel.classList.add('is-closed');
    b.addEventListener('click', function () {
      var open = b.getAttribute('aria-expanded') === 'true';
      b.setAttribute('aria-expanded', open ? 'false' : 'true');
      panel.classList.toggle('is-closed', open);
    });
  });

  /* Speaker in home: su telefono i primi 6, poi «Show all» */
  document.querySelectorAll('.speakers[data-collapsible]').forEach(function (list) {
    var more = list.parentNode.querySelector('.speakers__more');
    if (!more) return;
    var total = list.children.length;
    more.hidden = false;
    more.addEventListener('click', function () {
      var expanded = list.classList.toggle('is-expanded');
      more.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      more.textContent = expanded ? 'Show fewer speakers' : 'Show all ' + total + ' speakers';
      if (expanded) {
        var firstExtra = list.querySelector('.is-extra .speaker__name');
        if (firstExtra) { firstExtra.setAttribute('tabindex', '-1'); firstExtra.focus(); }
      }
    });
  });

  /* Moduli dimostrativi: controllo dei campi, errori accanto al campo, nessun invio */
  var messages = {
    email: 'Error: enter an email address, for example name@clinic.com.',
    name: 'Error: enter your full name.',
    message: 'Error: write your message.',
    consent: 'Error: tick the box so EAS can reply to you.'
  };
  document.querySelectorAll('form[data-demo]').forEach(function (form) {
    var status = form.querySelector('.form__status');
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var firstBad = null;
      form.querySelectorAll('[required]').forEach(function (field) {
        var err = document.getElementById(field.id + '-err');
        var ok = field.type === 'checkbox' ? field.checked : field.value.trim() !== '' && field.checkValidity();
        field.setAttribute('aria-invalid', ok ? 'false' : 'true');
        if (err) {
          err.hidden = ok;
          err.textContent = ok ? '' : (messages[field.name] || 'Error: check this field.');
        }
        if (!ok && !firstBad) firstBad = field;
      });
      if (firstBad) {
        if (status) status.textContent = '';
        firstBad.focus();
        return;
      }
      if (status) {
        status.textContent = form.classList.contains('form--inline')
          ? 'Thank you. This is a demo: the subscription will be connected to the EAS newsletter.'
          : 'Thank you. This is a demo: your message has not been sent.';
      }
      form.reset();
      form.querySelectorAll('[aria-invalid]').forEach(function (f) { f.removeAttribute('aria-invalid'); });
    });
  });
})();
