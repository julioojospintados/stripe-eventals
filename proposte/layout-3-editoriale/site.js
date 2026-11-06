/* Autore: Giulio Occhipinti. Menu su telefono: apre e chiude la navigazione, Esc la chiude e riporta il fuoco. */
(function () {
  var btn = document.querySelector('.menu-btn');
  var nav = document.getElementById('nav');
  if (!btn || !nav) return;
  function set(open) {
    btn.setAttribute('aria-expanded', String(open));
    nav.setAttribute('data-open', String(open));
  }
  btn.addEventListener('click', function () { set(btn.getAttribute('aria-expanded') !== 'true'); });
  nav.addEventListener('click', function (e) { if (e.target.closest('a')) set(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && btn.getAttribute('aria-expanded') === 'true') { set(false); btn.focus(); }
  });
})();
