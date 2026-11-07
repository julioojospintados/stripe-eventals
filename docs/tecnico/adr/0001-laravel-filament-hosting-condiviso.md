# ADR 0001: Laravel + Filament su hosting condiviso

Autore: Giulio Occhipinti. Stato: accettata. Data: 06/11/2026. Fonte: `docs/decisioni.md` (righe «Stack», «Hosting condiviso», «Versioni»), `docs/architettura.md` §1, §14.

## Contesto

Il cliente chiede un backend sul suo server, dati in UE, gestione di un solo sviluppatore PHP (Simone). Il server è
un hosting condiviso con SSH e MySQL o MariaDB, senza Docker né processi sempre attivi. La prima versione pensata
(Next.js + Supabase su Vercel) non ci sta.

## Decisione

Laravel 13 + Filament 5 su PHP 8.4 (minimo 8.3), sito pubblico in Blade + Livewire, pannello `/admin` in Filament.
Deploy via SSH; requisiti verificati con `strumenti/verifica-hosting/`.

## Alternative

- Next.js + Supabase su Vercel: superata, il cliente vuole il suo server e il backend.
- Laravel + Inertia/Vue: un framework JavaScript in più da mantenere senza tecnici.
- pretix o un prodotto pronto: scartato da Giulio (tutto su misura).

## Conseguenze

- Un solo linguaggio e un solo processo (PHP-FPM): lo mantiene uno sviluppatore PHP.
- Niente Supervisor, systemd, Redis: code e scheduler devono girare con il cron (ADR 0008).
- I requisiti dell'hosting (estensioni, `proc_open`, privilegio `TRIGGER`) vanno controllati prima di ogni cambio di
  server.
