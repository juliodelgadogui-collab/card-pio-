#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

echo "EventMenu Server 1.0.0 — atualização segura"
[[ -f .env ]] || { echo "ERRO: .env ausente."; exit 1; }
[[ -f storage/installed.lock ]] || { echo "ERRO: instalação não identificada; use install.sh."; exit 1; }

mkdir -p storage/backups
STAMP="$(date +%Y%m%d-%H%M%S)"
if [[ -f storage/eventmenu.sqlite ]]; then
  cp -p storage/eventmenu.sqlite "storage/backups/pre-update-${STAMP}.sqlite"
  echo "Backup criado: storage/backups/pre-update-${STAMP}.sqlite"
fi

if command -v composer >/dev/null 2>&1; then
  composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
elif [[ ! -f vendor/autoload.php ]]; then
  echo "ERRO: dependências ausentes e Composer indisponível."
  exit 1
fi

php scripts/update-cli.php

if [[ -d integrations/whatsapp-worker && "${UPDATE_WHATSAPP_BRIDGE:-1}" == "1" ]]; then
  if command -v node >/dev/null 2>&1 && command -v npm >/dev/null 2>&1; then
    (cd integrations/whatsapp-worker && npm install --omit=dev)
  else
    echo "AVISO: Node/npm indisponível; dependências da WhatsApp Bridge não foram atualizadas."
  fi
fi

echo "Atualização concluída preservando .env, storage, banco e sessões."
