# Deploy

Autore: Giulio Occhipinti. Versione 1.1 dell'11/11/2026.

Due modalità, con la stessa configurazione (`deploy.conf`) e lo stesso link `current`, quindi gli stessi file di
nginx, systemd e cron ([`../server/`](../server/README.md)). Passi manuali e motivi:
[`docs/tecnico/esercizio.md`](../../docs/tecnico/esercizio.md).

| | `deploy-pull.sh` (cartella unica) | `deploy.sh` (rilasci) |
|---|---|---|
| Come | `git pull --ff-only` in un clone sul server | `git clone` (o archivio) in `releases/<timestamp>`, poi switch di `current` |
| Sito fermo | sì, pochi secondi (`artisan down` da prima del pull a dopo le cache) | no: lo switch è atomico |
| Se un passo fallisce | si ferma, sito in manutenzione, stampa i comandi per tornare al commit di prima | il rilascio nuovo si cancella, il sito resta com'era |
| Ritorno indietro | `git reset --hard <commit>` + Composer + cache (li stampa lo script) | `rollback.sh` |
| Quando | server del cliente oggi: semplice, come lavora già Simone | quando servono zero fermi e un ritorno indietro in un comando (vendite aperte, molte versioni) |

In entrambe: backup prima delle migrazioni (si ferma se fallisce), `composer install --no-dev`, `migrate --force`,
`optimize`, `storage:link`, `queue:restart`. Il database non torna indietro da solo: per questo c'è il backup.

## Modalità git pull

```
~/eas8/
  repo/                       clone del repository sul branch GIT_REF
  repo/sito/.env              .env del sito (permessi 600), ignorato da git
  current -> repo/sito        creato dallo script al primo giro
  strumenti/deploy, strumenti/backup   copia di queste cartelle
```

Prima volta: `git clone --branch main <repository> ~/eas8/repo`, `deploy.conf` come sotto (`GIT_REF` = il branch del
clone; `PULL_DIR` se il clone sta altrove), `.env` in `repo/sito/`, backup pronto, poi `~/eas8/strumenti/deploy/deploy-pull.sh`
e dal sito (`cd ~/eas8/current`) i seeder e `php artisan eventals:create-admin` (esercizio.md §3, punto 4). Ogni versione:
lo stesso comando. Si ferma anche se il clone ha modifiche locali o è su un altro branch: niente fusioni sul server.

## Modalità a rilasci

`deploy.sh` installa una versione di `sito/` in `releases/<timestamp>` e la attiva con uno switch atomico del link
`current`; `rollback.sh` torna al rilascio precedente. Basta anche un hosting condiviso con SSH, PHP da riga di
comando, Composer, git (o un archivio caricato via SFTP) e il cron.

```
~/eas8/
  releases/20261107-153000/   un rilascio (contenuto di sito/), ne restano 5
  shared/.env                 unico .env, permessi 600
  shared/storage/             storage condiviso tra i rilasci
  current -> releases/…       rilascio attivo
  strumenti/deploy, strumenti/backup   copia di queste cartelle del repository
```

### Prima volta

1. Copia `strumenti/deploy` e `strumenti/backup` in `~/eas8/strumenti/`; in ciascuna `cp *.conf.example` nel `.conf`
   e adattalo (percorsi, `PHP`, `COMPOSER`, `REPO_URL`/`GIT_REF`). I `.conf` non contengono segreti.
2. Prepara il backup ([`../backup/README.md`](../backup/README.md)): `deploy.sh` lo lancia prima di ogni migrazione e si
   ferma se fallisce.
3. Crea `~/eas8/shared/.env` da `sito/.env.example` (`chmod 600`) e compilalo (esercizio.md §3). `APP_KEY`, una volta
   sola e mai più rigenerata: `php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"`.
4. `~/eas8/strumenti/deploy/deploy.sh`, poi dal rilascio attivo (`cd ~/eas8/current`) i seeder e
   `php artisan eventals:create-admin` (esercizio.md §3, punto 4).
5. **Document root** del dominio: `/home/<utente>/eas8/current/public` (se il pannello impone `public_html`:
   `ln -s ~/eas8/current/public ~/public_html` dopo averne salvato il contenuto).
6. **Coda e cron:** sul server pieno worker e scheduler da [`../server/`](../server/README.md); su un hosting condiviso
   una riga nel crontab dell'utente (più quella del backup, vedi il suo README):
   ```
   * * * * * cd /home/<utente>/eas8/current && php artisan schedule:run >> /dev/null 2>&1
   ```

### Ogni versione

```
~/eas8/strumenti/deploy/deploy.sh                 # git clone di GIT_REF
~/eas8/strumenti/deploy/deploy.sh sito.tar.gz     # oppure un archivio di sito/ caricato via SFTP
```

L'archivio si crea dal repository con
`git archive --format=tar.gz -o sito.tar.gz HEAD:sito`. Passi: codice → link a `shared/.env` e `shared/storage` →
`composer install --no-dev --optimize-autoloader` → backup → `migrate --force` → `optimize` (cache di config, route,
viste, eventi e Filament) → `storage:link` → switch di `current` → `queue:restart` → pulizia (restano 5 rilasci).
Se un passo fallisce prima dello switch, il rilascio nuovo si cancella e il sito resta com'era; il database invece
resta migrato: per questo c'è il backup.

Leggi esercizio.md §9 prima di una versione con migrazioni. Con PHP-FPM il cambio di `current` può vedersi dopo
qualche secondo (cache dei percorsi di PHP): è normale.

### Ritorno indietro

```
~/eas8/strumenti/deploy/rollback.sh
```

Riattiva il rilascio precedente e fa `queue:restart`. Il database non torna indietro: se il rilascio attivo ha
migrazioni che il precedente non ha, lo script le elenca e si ferma; `rollback.sh --yes` procede lo stesso. Se quelle
migrazioni non sono compatibili con il codice vecchio: ripristino dal backup fatto dal deploy (esercizio.md §8).
