/* Variante A «Fedele» — JS minimo e solo locale. Autore: Giulio Occhipinti. */
(function () {
  "use strict";
  document.documentElement.classList.add("js");

  /* Menu su telefono */
  var toggle = document.querySelector(".nav-toggle");
  var nav = document.getElementById("site-nav");
  if (toggle && nav) {
    var setOpen = function (open) {
      toggle.setAttribute("aria-expanded", String(open));
      nav.classList.toggle("is-open", open);
    };
    toggle.addEventListener("click", function () {
      setOpen(toggle.getAttribute("aria-expanded") !== "true");
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && toggle.getAttribute("aria-expanded") === "true") {
        setOpen(false);
        toggle.focus();
      }
    });
  }

  /* Accordion FAQ: senza JS le risposte restano tutte visibili */
  document.querySelectorAll(".faq__btn").forEach(function (btn) {
    var panel = document.getElementById(btn.getAttribute("aria-controls"));
    if (!panel) return;
    panel.hidden = btn.getAttribute("aria-expanded") !== "true";
    btn.addEventListener("click", function () {
      var open = btn.getAttribute("aria-expanded") === "true";
      btn.setAttribute("aria-expanded", String(!open));
      panel.hidden = open;
    });
  });

  /* Moduli dimostrativi: non inviano nulla */
  document.querySelectorAll("form[data-demo]").forEach(function (form) {
    form.setAttribute("novalidate", "");
    var status = form.querySelector(".form-status");
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var firstBad = null;
      form.querySelectorAll("[required]").forEach(function (field) {
        var err = document.getElementById(field.id + "-error");
        var ok = field.value.trim() !== "" && (field.type !== "email" || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value.trim()));
        field.setAttribute("aria-invalid", String(!ok));
        if (err) {
          err.hidden = ok;
        }
        if (!ok && !firstBad) firstBad = field;
      });
      if (firstBad) {
        if (status) status.textContent = "";
        firstBad.focus();
        return;
      }
      if (status) status.textContent = form.getAttribute("data-demo");
      form.reset();
    });
  });
})();
