/* Proposta 1 · «Notte di Lisbona». Autore: Giulio Occhipinti. Menu mobile e riepilogo dei biglietti. */
(function () {
  'use strict';

  var toggle = document.querySelector('.menu-toggle');
  var nav = document.getElementById('site-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!open));
      nav.classList.toggle('is-open', !open);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('is-open')) {
        toggle.setAttribute('aria-expanded', 'false');
        nav.classList.remove('is-open');
        toggle.focus();
      }
    });
  }

  var form = document.getElementById('order');
  if (!form) return;

  /* Segnaposto: nomi e posti rimasti fittizi. */
  var workshops = [
    { id: 'w01', day: 'Day 1', left: 14 }, { id: 'w02', day: 'Day 1', left: 3 },
    { id: 'w03', day: 'Day 1', left: 20 }, { id: 'w04', day: 'Day 1', left: 0 },
    { id: 'w05', day: 'Day 2', left: 9 },  { id: 'w06', day: 'Day 2', left: 17 },
    { id: 'w07', day: 'Day 2', left: 5 },  { id: 'w08', day: 'Day 2', left: 12 },
    { id: 'w09', day: 'Day 3', left: 20 }, { id: 'w10', day: 'Day 3', left: 2 }
  ];
  var letters = 'ABCDEFGHIJ';
  var list = document.getElementById('ws-list');
  workshops.forEach(function (w, i) {
    var n = String(i + 1).padStart(2, '0');
    var label = document.createElement('label');
    var none = w.left === 0;
    label.className = 'option' + (none ? ' option--disabled' : '');
    var seats = none ? 'Sold out' : (w.left <= 5 ? 'Only ' + w.left + ' of 20 seats left' : w.left + ' of 20 seats left');
    var cls = none ? 'seats seats--none' : (w.left <= 5 ? 'seats seats--low' : 'seats');
    label.innerHTML =
      '<input type="checkbox" name="ws" value="' + w.id + '" data-price="100" data-label="Workshop ' + n + '"' + (none ? ' disabled' : '') + '>' +
      '<span class="option__label">Workshop ' + n +
      '<small>Placeholder ' + letters[i] + ' · ' + w.day + ' · <span class="' + cls + '">' + seats + '</span></small></span>' +
      '<span class="option__price">' + (none ? '—' : '€100') + '</span>';
    list.appendChild(label);
  });

  var euro = function (v) {
    return '€' + (Number.isInteger(v) ? v.toLocaleString('en-GB') : v.toLocaleString('en-GB', { minimumFractionDigits: 2 }));
  };
  var lines = document.getElementById('sum-lines');
  var total = document.getElementById('sum-total');
  var barTotal = document.getElementById('bar-total');
  var msg = document.getElementById('sum-msg');

  function render() {
    var items = [];
    var pass = form.querySelector('input[name="pass"]:checked');
    if (pass) {
      if (pass.dataset.member) items.push(['EAS membership · 1 year', Number(pass.dataset.member)]);
      items.push([pass.dataset.label, Number(pass.dataset.price)]);
    }
    form.querySelectorAll('input[name="ws"]:checked').forEach(function (c) {
      items.push([c.dataset.label, Number(c.dataset.price)]);
    });
    lines.innerHTML = '';
    var sum = 0;
    items.forEach(function (it) {
      sum += it[1];
      var li = document.createElement('li');
      var a = document.createElement('span'); a.textContent = it[0];
      var b = document.createElement('span'); b.textContent = euro(it[1]);
      li.appendChild(a); li.appendChild(b); lines.appendChild(li);
    });
    total.textContent = euro(sum);
    if (barTotal) barTotal.textContent = euro(sum);
    msg.textContent = '';
  }

  form.addEventListener('change', render);
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    msg.textContent = 'Next step: card payment on Stripe Checkout (not active in this proposal).';
    if (window.matchMedia('(max-width: 59.99rem)').matches) {
      document.getElementById('summary').scrollIntoView({ block: 'center' });
    }
  });
  render();
})();
