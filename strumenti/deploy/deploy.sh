#!/usr/bin/env bash
# Deploy di sito/ su hosting condiviso: releases/<timestamp>, shared/ (.env, storage) e link current.
# Uso: deploy.sh [archivio.tar.gz]   (senza archivio: git clone di REPO_URL al GIT_REF)
# Configurazione: deploy.conf accanto allo script, o DEPLOY_CONF=<file>. Autore: Giulio Occhipinti.
set -euo pipefail

source "${DEPLOY_CONF:-$(dirname "$0")/deploy.conf}"
: "${DEPLOY_PATH:?}" "${PHP:=php}" "${COMPOSER:=composer}" "${KEEP_RELEASES:=5}" "${APP_SUBDIR:=sito}" "${BACKUP_CMD:?}"

shared="$DEPLOY_PATH/shared"
ts=$(date +%Y%m%d-%H%M%S)
release="$DEPLOY_PATH/releases/$ts"

mkdir -p "$DEPLOY_PATH/releases" "$shared"/storage/{app/public,app/private,framework/{cache,sessions,views},logs}
[ -f "$shared/.env" ] || { echo "Manca $shared/.env (copia .env.example, compilalo, chmod 600)" >&2; exit 1; }
if [ -e "$release" ]; then echo "$release esiste già" >&2; exit 1; fi

# Un rilascio non attivato si cancella: current resta com'era.
activated=0
cleanup() { if [ "$activated" = 0 ]; then echo "Deploy non completato: tolgo $release" >&2; rm -rf "$release" "$release.git"; fi; }
trap cleanup EXIT

echo "== Codice in $release"
if [ $# -ge 1 ]; then
    mkdir "$release" && tar -xzf "$1" -C "$release"
else
    tmp="$release.git"
    git clone --quiet --depth 1 --branch "${GIT_REF:?}" "${REPO_URL:?}" "$tmp"
    mv "$tmp/$APP_SUBDIR" "$release" && rm -rf "$tmp"
fi
[ -f "$release/artisan" ] || { echo "Nel codice manca artisan: archivio o APP_SUBDIR sbagliati" >&2; exit 1; }

rm -rf "$release/storage" "$release/.env"
ln -s "$shared/storage" "$release/storage"
ln -s "$shared/.env" "$release/.env"

cd "$release"
echo "== Composer"
$COMPOSER install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "== Backup e migrazioni"
$BACKUP_CMD
$PHP artisan migrate --force

echo "== Cache e link"
$PHP artisan optimize          # config, eventi, route, viste e filament:optimize
$PHP artisan storage:link --force

# Switch atomico: rename(2) di un link nuovo sopra current.
ln -sfn "releases/$ts" "$DEPLOY_PATH/current.new"
mv -T "$DEPLOY_PATH/current.new" "$DEPLOY_PATH/current"
activated=1
$PHP artisan queue:restart

echo "== Pulizia: tengo $KEEP_RELEASES rilasci"
active=$(readlink "$DEPLOY_PATH/current")
ls -1 "$DEPLOY_PATH/releases" | grep -E '^[0-9]{8}-[0-9]{6}$' | sort | head -n "-$KEEP_RELEASES" | while read -r old; do
    [ "releases/$old" = "$active" ] || rm -rf "${DEPLOY_PATH:?}/releases/$old"
done

echo "Attivo: $active"
