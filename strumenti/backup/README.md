# Backup e prova di ripristino

Autore: Giulio Occhipinti. Versione 1.0 del 07/11/2026.

`backup.sh` salva il database (`mariadb-dump` o `mysqldump` con `--single-transaction --routines --triggers`) e i file
caricati (`storage/app`, con `app/private`), compressi e **cifrati** (contengono dati personali), con rotazione: tutte
le copie per `KEEP_DAYS` giorni in `daily/`, poi una per settimana per `KEEP_WEEKS` settimane in `weekly/`.
`restore-test.sh` carica l'ultimo dump in un database di prova e controlla che le tabelle principali abbiano righe,
il numero di trigger (devono essere 9) e che l'archivio dei file si legga. Dettagli in
[`docs/tecnico/esercizio.md`](../../docs/tecnico/esercizio.md) §8.

## Prima volta

1. `cp backup.conf.example backup.conf` e adattalo.
2. Credenziali del database in `~/.my.cnf`, mai sulla riga di comando (gli script rifiutano permessi diversi da 600):
   ```
   [client]
   user=<utente>
   password=<password>
   host=localhost
   ```
   `chmod 600 ~/.my.cnf`
3. Passphrase di cifratura: `openssl rand -base64 32 > ~/.backup-pass && chmod 600 ~/.backup-pass`. Copiala nel gestore
   di password: senza, i backup non si leggono.
4. Database di prova vuoto dal pannello (per esempio `<utente>_eas8_restore`), con lo stesso utente: è `RESTORE_DB`.
   **Le sue tabelle vengono cancellate** a ogni prova; lo script rifiuta il database del sito.
5. Cron: ogni ora durante le vendite (altrimenti `30 2 * * *`), prova di ripristino ogni mese.
   ```
   0 * * * * /home/<utente>/eas8/strumenti/backup/backup.sh >> /home/<utente>/backup/backup.log 2>&1
   0 4 1 * * /home/<utente>/eas8/strumenti/backup/restore-test.sh >> /home/<utente>/backup/backup.log 2>&1
   ```

Le copie restano sull'hosting: portale anche fuori, in UE (esercizio.md §8). Ripristino vero:
`openssl enc -d -aes-256-cbc -pbkdf2 -pass file:$HOME/.backup-pass -in <file> | gunzip | mysql <db>`, poi
`php artisan eas:reconcile` come in esercizio.md §8.
