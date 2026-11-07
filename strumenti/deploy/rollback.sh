#!/usr/bin/env bash
# Torna al rilascio precedente a quello attivo. Il database NON torna indietro: se il rilascio attivo aveva
# migrazioni nuove, lo script le elenca e si ferma; con --yes procede lo stesso (poi ripristino dal backup se serve).
# Configurazione: la stessa di deploy.sh. Autore: Giulio Occhipinti.
set -euo pipefail

source "${DEPLOY_CONF:-$(dirname "$0")/deploy.conf}"
: "${DEPLOY_PATH:?}" "${PHP:=php}"

active=$(basename "$(readlink "$DEPLOY_PATH/current")")
previous=$(ls -1 "$DEPLOY_PATH/releases" | grep -E '^[0-9]{8}-[0-9]{6}$' | sort | awk -v a="$active" '$0 < a' | tail -n 1)
[ -n "$previous" ] || { echo "Nessun rilascio precedente a $active" >&2; exit 1; }

new_migrations=$(comm -23 <(ls -1 "$DEPLOY_PATH/releases/$active/database/migrations" | sort) \
                          <(ls -1 "$DEPLOY_PATH/releases/$previous/database/migrations" | sort))
if [ -n "$new_migrations" ]; then
    echo "ATTENZIONE: $active ha migrazioni che $previous non conosce (restano applicate nel database):"
    echo "$new_migrations" | sed 's/^/  /'
    echo "Se non sono compatibili all'indietro serve il ripristino dal backup (docs/tecnico/esercizio.md §8)."
    [ "${1:-}" = "--yes" ] || { echo "Rilancia con --yes per procedere." >&2; exit 2; }
fi

ln -sfn "releases/$previous" "$DEPLOY_PATH/current.new"
mv -T "$DEPLOY_PATH/current.new" "$DEPLOY_PATH/current"
cd "$DEPLOY_PATH/current" && $PHP artisan queue:restart
echo "Attivo: releases/$previous (era $active)"
