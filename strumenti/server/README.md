# Server pieno: nginx, coda e scheduler

Autore: Giulio Occhipinti. Versione 1.0 dell'11/11/2026.

File d'esempio per il server del cliente (nginx, PHP 8.4, MariaDB 11.4, SSH, accesso root). Utente `eas8`, sito in
`/home/eas8/eas8/current` (link al rilascio attivo o al clone, vedi [`../deploy/README.md`](../deploy/README.md)):
adatta utente, percorsi, dominio e socket di PHP-FPM prima di copiarli.

| File | A cosa serve |
|---|---|
| `nginx-eas8.conf` | sito su HTTPS, solo `index.php` a PHP-FPM, header di sicurezza, cache degli asset, niente file nascosti |
| `eas8-queue.service` | worker della coda sempre acceso con systemd (consigliato) |
| `supervisor-eas8-queue.conf` | lo stesso worker con supervisor, se il server lo usa già (uno solo dei due) |
| `eas8-scheduler.service` + `.timer` | `schedule:run` ogni minuto con systemd, al posto della riga di cron |

## Passi

1. **PHP-FPM 8.4** con le estensioni del [README del sito](../../sito/README.md#requisiti); il pool gira come `eas8`
   (o come l'utente di nginx con accesso in lettura al sito e in scrittura a `storage/`).
2. **nginx:** copia `nginx-eas8.conf` in `/etc/nginx/sites-available/eas8`, link in `sites-enabled`. Certificato prima
   di attivarlo: `sudo certbot certonly --nginx -d <dominio>` (pacchetto `certbot` e `python3-certbot-nginx` della
   distribuzione; il rinnovo automatico lo installa il pacchetto). Poi `sudo nginx -t && sudo systemctl reload nginx`.
3. **Coda:** nel `.env` `QUEUE_WORKER_MODE=daemon` (lo scheduler smette di lanciare `queue:work`), poi
   `eas8-queue.service` o supervisor. Stato: `systemctl status eas8-queue`, log: `journalctl -u eas8-queue`.
4. **Scheduler:** una riga nel crontab di `eas8`
   (`* * * * * cd /home/eas8/eas8/current && php artisan schedule:run >> /dev/null 2>&1`) oppure il timer:
   `sudo systemctl enable --now eas8-scheduler.timer`. Uno solo dei due.
5. **Deploy:** `deploy.sh` e `deploy-pull.sh` fanno già `php artisan queue:restart`: il worker finisce il job in corso,
   esce e systemd (o supervisor) lo riavvia sul codice nuovo.

## MariaDB e trigger

Le migrazioni creano trigger (`DB_TRIGGERS_OPTIONAL=false`, predefinito). Con il log binario acceso (repliche,
backup point-in-time) un utente senza privilegi riceve l'errore 1419. Una delle due:

- nel server, `log_bin_trust_function_creators = 1` in `/etc/mysql/mariadb.conf.d/` (sezione `[mariadb]`), poi
  riavvio di MariaDB;
- oppure le migrazioni girano con un utente che ha `SUPER` (`GRANT SUPER ON *.* TO …`; su MariaDB 10.11
  `BINLOG ADMIN` non basta). È un privilegio ampio: meglio la prima strada.

Con il log binario spento non serve niente. `DB_TRIGGERS_OPTIONAL=true` resta solo per un hosting condiviso che non
permette nessuna delle due.

## Staging

Stesso server o un altro, con un utente, una cartella, un database e un dominio suoi (per esempio
`staging.<dominio>`), configurazione da [`../../sito/.env.staging.example`](../../sito/.env.staging.example). Nel file
nginx della copia: `server_name` e `root` suoi e la riga `X-Robots-Tag` attiva; se si vuole una password davanti
(`auth_basic`), il webhook resta aperto con `location = /stripe/webhook { auth_basic off; try_files $uri /index.php?$query_string; }`.
Unità systemd con nomi propri (`eas8-staging-queue.service`).
