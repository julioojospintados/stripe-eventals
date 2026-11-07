#!/usr/bin/env bash
# Prova di ripristino: carica l'ultimo backup del database in RESTORE_DB (database di prova) e controlla che le
# tabelle principali abbiano righe e che l'archivio dei file si legga. Esce con 1 se qualcosa non va.
# Uso: restore-test.sh [file.sql.gz.enc]   (configurazione: la stessa di backup.sh). Autore: Giulio Occhipinti.
set -euo pipefail

source "${BACKUP_CONF:-$(dirname "$0")/backup.conf}"
: "${DB_NAME:?}" "${MYCNF:?}" "${PASS_FILE:?}" "${BACKUP_DIR:?}" "${RESTORE_DB:?}" "${CHECK_TABLES:?}"
[ "$RESTORE_DB" != "$DB_NAME" ] || { echo "RESTORE_DB non può essere il database del sito" >&2; exit 1; }
client=$(command -v mariadb || command -v mysql)
sql() { "$client" --defaults-extra-file="$MYCNF" --batch --skip-column-names "$@"; }
decrypt() { openssl enc -d -aes-256-cbc -pbkdf2 -pass "file:$PASS_FILE" -in "$1"; }

dump=${1:-$(ls -1 "$BACKUP_DIR"/daily/"$DB_NAME"-*.sql.gz.enc | sort | tail -n 1)}
echo "Ripristino $dump in $RESTORE_DB"

# Svuota il database di prova (sull'hosting di solito non si può fare DROP/CREATE DATABASE).
drops=$(sql "$RESTORE_DB" -e "SELECT CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`;') FROM information_schema.tables WHERE table_schema = DATABASE();")
sql "$RESTORE_DB" -e "SET FOREIGN_KEY_CHECKS = 0; $drops"
decrypt "$dump" | gunzip | sql "$RESTORE_DB"

fail=0
for t in $CHECK_TABLES; do
    n=$(sql "$RESTORE_DB" -e "SELECT COUNT(*) FROM \`$t\`") || n=0
    if [ "$n" -gt 0 ]; then echo "OK    $t: $n righe"; else echo "ERRORE $t: vuota o mancante"; fail=1; fi
done
echo "Trigger: $(sql "$RESTORE_DB" -e "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()")"

files=$(ls -1 "$BACKUP_DIR"/daily/files-*.tar.gz.enc 2>/dev/null | sort | tail -n 1 || true)
if [ -n "$files" ]; then
    if n=$(decrypt "$files" | tar -tzf - | wc -l); then echo "OK    file: $n voci in $(basename "$files")"; else echo "ERRORE file: $files"; fail=1; fi
fi

[ "$fail" = 0 ] && echo "Prova di ripristino riuscita." || { echo "Prova di ripristino FALLITA." >&2; exit 1; }
