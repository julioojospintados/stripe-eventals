# ADR 0008: Code con cron, senza processi sempre attivi

Autore: Giulio Occhipinti. Stato: proposta, da confermare. Data: 06/11/2026. Fonte: `docs/decisioni.md` (righe «Code e job», «Code senza processi sempre attivi»), `docs/architettura.md` §8.

## Contesto

Sull'hosting condiviso non ci sono Supervisor né systemd: un `queue:work` sempre acceso non è garantito.

## Decisione

Una sola riga di cron ogni minuto (`schedule:run`). Lo scheduler (`routes/console.php`) lancia
`queue:work --queue=critical,default --stop-when-empty --max-time=50` con `withoutOverlapping`, `eas:expire-orders`
ogni minuto, `eas:reconcile` alle 3:30 (Lisbona) e le pulizie giornaliere. Coda `database`. Il codice di accesso
parte con `afterResponse`; la pagina di successo conferma il pagamento senza aspettare la coda.

## Alternative

- Worker sotto Supervisor: non disponibile.
- Redis o un servizio di code esterno: un fornitore in più.
- Tutto sincrono: lavori pesanti nella richiesta (regola 3 del ruolo).

## Conseguenze

- Latenza fino a un minuto per email e webhook.
- Al deploy serve `php artisan queue:restart`.
- Se l'hosting vieta `proc_open`, servono righe di cron separate per `queue:work` ed `eas:expire-orders`.

## Variante server pieno (11/11/2026)

Sul server del cliente si può tenere acceso un processo: `QUEUE_WORKER_MODE=daemon` e un worker
`queue:work --queue=critical,default --sleep=3 --tries=5 --max-time=3600` sotto systemd (`strumenti/server/eas8-queue.service`)
o supervisor; lo scheduler non lancia più `queue:work` ma resta per scadenze, riconciliazione e pulizie
(`schedule:run` ogni minuto con cron o `eas8-scheduler.timer`). Latenza di email e webhook: qualche secondo invece di
un minuto. `queue:restart` nel deploy fa ripartire il worker sul codice nuovo. La modalità `cron` resta il
predefinito del codice, per un hosting condiviso e in locale.
