#!/usr/bin/env bash
# Backup cifrato del database (dump coerente con trigger e routine) e dei file caricati, con rotazione
# giornaliera e settimanale. Credenziali solo dal file MYCNF (permessi 600), mai sulla riga di comando.
# Uso: backup.sh   (configurazione: backup.conf accanto allo script, o BACKUP_CONF=<file>). Autore: Giulio Occhipinti.
set -euo pipefail
umask 077

source "${BACKUP_CONF:-$(dirname "$0")/backup.conf}"
: "${DB_NAME:?}" "${MYCNF:?}" "${PASS_FILE:?}" "${BACKUP_DIR:?}" "${KEEP_DAYS:=7}" "${KEEP_WEEKS:=8}"

for f in "$MYCNF" "$PASS_FILE"; do
    perm=$(stat -c %a "$f")
    [ "$perm" = 600 ] || [ "$perm" = 400 ] || { echo "$f deve avere permessi 600 (ora $perm)" >&2; exit 1; }
done
dump=$(command -v mariadb-dump || command -v mysqldump) || { echo "Né mariadb-dump né mysqldump trovati" >&2; exit 1; }

mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/weekly"
exec 9>"$BACKUP_DIR/.lock"
flock -n 9 || { echo "Un altro backup è in corso" >&2; exit 1; }

ts=$(date +%Y%m%d-%H%M%S)
encrypt() { openssl enc -aes-256-cbc -pbkdf2 -salt -pass "file:$PASS_FILE"; }

# Scrive su .tmp e rinomina solo a fine pipeline riuscita: niente file a metà con il nome giusto.
db="$BACKUP_DIR/daily/$DB_NAME-$ts.sql.gz.enc"
"$dump" --defaults-extra-file="$MYCNF" --single-transaction --routines --triggers --hex-blob \
    --no-tablespaces --default-character-set=utf8mb4 "$DB_NAME" | gzip | encrypt > "$db.tmp"
mv "$db.tmp" "$db"
echo "Database: $db"

if [ -n "${FILES_DIR:-}" ]; then
    files="$BACKUP_DIR/daily/files-$ts.tar.gz.enc"
    tar -czf - -C "$(dirname "$FILES_DIR")" "$(basename "$FILES_DIR")" | encrypt > "$files.tmp"
    mv "$files.tmp" "$files"
    echo "File: $files"
fi

# Settimanali: l'ultima copia di ogni settimana ISO (sovrascritta durante la settimana).
week=$(date +%G-W%V)
cp "$db" "$BACKUP_DIR/weekly/$DB_NAME-$week.sql.gz.enc"
[ -z "${files:-}" ] || cp "$files" "$BACKUP_DIR/weekly/files-$week.tar.gz.enc"

find "$BACKUP_DIR/daily" -type f -name '*.enc' -mtime "+$((KEEP_DAYS - 1))" -delete
for prefix in "$DB_NAME" files; do
    ls -1 "$BACKUP_DIR/weekly/$prefix"-*.enc 2>/dev/null | sort | head -n "-$KEEP_WEEKS" | xargs -r rm -f
done
