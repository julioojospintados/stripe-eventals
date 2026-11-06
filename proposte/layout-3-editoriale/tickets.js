/* Autore: Giulio Occhipinti. Pagina Tickets: quantità, membership che sblocca il prezzo membro, riepilogo e totale.
   Prototipo: il pagamento non è collegato. */
(function () {
  var form = document.getElementById('order');
  if (!form) return;
  var member = document.getElementById('member');
  var memberLine = form.querySelector('[data-needs-member]');
  var hint = document.getElementById('member-hint');
  var hintLocked = hint.innerHTML;
  var lines = document.getElementById('lines');
  var total = document.getElementById('total');
  var totalBar = document.getElementById('total-bar');
  var msg = document.getElementById('pay-msg');

  function eur(n) {
    var whole = Math.round(n * 100) % 100 === 0;
    return new Intl.NumberFormat('en-IE', {
      style: 'currency', currency: 'EUR',
      minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: 2
    }).format(n);
  }
  function qtyOf(line) { return parseInt(line.querySelector('output').textContent, 10) || 0; }
  function setQty(line, q) {
    var plus = line.querySelector('[data-step="1"]');
    var minus = line.querySelector('[data-step="-1"]');
    var max = parseInt(plus.getAttribute('data-max'), 10);
    q = Math.max(0, Math.min(max, q));
    line.querySelector('output').textContent = q;
    minus.disabled = q === 0;
    plus.disabled = q >= max || (line.hasAttribute('data-needs-member') && !member.checked);
  }

  function render() {
    var items = [];
    form.querySelectorAll('.line[data-item]').forEach(function (line) {
      var q = qtyOf(line);
      if (q) items.push({ label: (q > 1 ? q + ' × ' : '') + line.getAttribute('data-label'), amt: q * parseFloat(line.getAttribute('data-price')) });
    });
    form.querySelectorAll('[data-ws]:checked').forEach(function (cb) {
      items.push({ label: cb.getAttribute('data-label'), amt: parseFloat(cb.getAttribute('data-price')) });
    });
    if (member.checked) items.push({ label: member.getAttribute('data-label'), amt: 200 });

    var sum = items.reduce(function (s, i) { return s + i.amt; }, 0);
    lines.innerHTML = items.length
      ? items.map(function (i) { return '<li><span>' + i.label + '</span><span class="amt">' + eur(i.amt) + '</span></li>'; }).join('')
      : '<li class="empty">Nothing selected yet.</li>';
    total.textContent = eur(sum);
    totalBar.textContent = eur(sum);
    msg.textContent = '';
    return sum;
  }

  form.addEventListener('click', function (e) {
    var b = e.target.closest('[data-step]');
    if (!b) return;
    var line = b.closest('.line');
    setQty(line, qtyOf(line) + parseInt(b.getAttribute('data-step'), 10));
    render();
  });
  form.addEventListener('change', function (e) {
    if (e.target === member) {
      memberLine.classList.toggle('line--locked', !member.checked);
      if (!member.checked) setQty(memberLine, 0); else setQty(memberLine, qtyOf(memberLine));
      hint.innerHTML = member.checked ? 'Unlocked: the membership is in this order.' : hintLocked;
    }
    render();
  });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var sum = render();
    if (!sum) {
      msg.textContent = 'Choose at least one item to continue.';
      document.getElementById('h-pass').scrollIntoView({ block: 'start' });
      form.querySelector('[data-step="1"]').focus({ preventScroll: true });
      return;
    }
    msg.textContent = 'Prototype: payment is not connected. Order total ' + eur(sum) + '.';
    document.getElementById('h-summary').scrollIntoView({ block: 'start' });
  });

  form.querySelectorAll('.line[data-item]').forEach(function (l) { setQty(l, 0); });
  render();
})();
