#!/usr/bin/env bash
# Deploy semplice in una cartella unica: un clone del repository aggiornato con git pull.
# Uso: deploy-pull.sh   (configurazione: deploy.conf accanto allo script, o DEPLOY_CONF=<file>)
# Si ferma al primo errore lasciando il sito in manutenzione e stampa come tornare indietro. Autore: Giulio Occhipinti.
set -euo pipefail

source "${DEPLOY_CONF:-$(dirname "$0")/deploy.conf}"
: "${DEPLOY_PATH:?}" "${PHP:=php}" "${COMPOSER:=composer}" "${APP_SUBDIR:=sito}" "${BACKUP_CMD:?}" "${GIT_REF:?}"

repo="${PULL_DIR:-$DEPLOY_PATH/repo}"
app="$repo/$APP_SUBDIR"

[ -d "$repo/.git" ] || { echo "Manca il clone: git clone --branch $GIT_REF ${REPO_URL:-<repository>} $repo" >&2; exit 1; }
[ -f "$app/.env" ] || { echo "Manca $app/.env (copia .env.example, compilalo, chmod 600)" >&2; exit 1; }

# current punta alla cartella dell'app: nginx, systemd e cron sono gli stessi della modalità a rilasci.
if [ ! -e "$DEPLOY_PATH/current" ]; then
    ln -s "$app" "$DEPLOY_PATH/current"
elif [ "$(readlink -f "$DEPLOY_PATH/current")" != "$(readlink -f "$app")" ]; then
    echo "$DEPLOY_PATH/current non punta a $app (è attiva la modalità a rilasci?)" >&2; exit 1
fi

cd "$repo"
branch=$(git rev-parse --abbrev-ref HEAD)
[ "$branch" = "$GIT_REF" ] || { echo "Il clone è su $branch, GIT_REF è $GIT_REF" >&2; exit 1; }
[ -z "$(git status --porcelain --untracked-files=no)" ] || { echo "Modifiche locali nel clone: git pull si ferma" >&2; git status --short >&2; exit 1; }
before=$(git rev-parse HEAD)

failed() {
    echo >&2
    echo "DEPLOY FERMO (riga $1): il sito è in manutenzione. Per tornare alla versione di prima:" >&2
    echo "  cd $repo && git reset --hard $before && cd $APP_SUBDIR && $COMPOSER install --no-dev --optimize-autoloader" >&2
    echo "  $PHP artisan optimize && $PHP artisan up && $PHP artisan queue:restart" >&2
    echo "Se le migrazioni sono già passate, il database resta migrato: ripristino dal backup (esercizio.md §8)." >&2
}

echo "== Backup"
$BACKUP_CMD

cd "$app"
echo "== Manutenzione e codice"
# Alla prima installazione non c'è ancora vendor/: niente manutenzione da attivare.
if [ -f vendor/autoload.php ]; then $PHP artisan down --retry=15; fi
trap 'failed $LINENO' ERR
git -C "$repo" pull --ff-only

echo "== Composer"
$COMPOSER install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "== Migrazioni, cache e link"
$PHP artisan migrate --force
$PHP artisan optimize          # config, eventi, route, viste e filament:optimize
$PHP artisan storage:link --force

$PHP artisan up
$PHP artisan queue:restart
trap - ERR
echo "Attivo: $(git -C "$repo" log -1 --format='%h %s') (prima: ${before:0:7})"
