#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

echo "EventMenu Server 1.0.0 — instalação limpa"

command -v php >/dev/null 2>&1 || { echo "ERRO: PHP não encontrado."; exit 1; }
php -r 'exit(version_compare(PHP_VERSION,"8.2.0",">=")?0:1);' || { echo "ERRO: PHP 8.2+ obrigatório."; exit 1; }
for ext in PDO pdo_sqlite mbstring curl openssl; do
  php -m | grep -qi "^${ext}$" || { echo "ERRO: extensão PHP ausente: ${ext}"; exit 1; }
done

mkdir -p storage/backups storage/private/whatsapp-sessions downloads
chmod 700 storage/private storage/private/whatsapp-sessions 2>/dev/null || true

if [[ ! -f vendor/autoload.php ]]; then
  if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
  else
    echo "ERRO: vendor/autoload.php ausente e Composer não está instalado."
    exit 1
  fi
fi

read_default() {
  local var_name="$1" prompt="$2" default_value="$3" value=""
  if [[ -n "${!var_name:-}" ]]; then return 0; fi
  read -r -p "${prompt} [${default_value}]: " value
  printf -v "$var_name" '%s' "${value:-$default_value}"
  export "$var_name"
}

read_default EVENTMENU_INSTALL_URL "URL completa do sistema" "https://go.gestao2.store/1"
read_default EVENTMENU_INSTALL_TENANT "Nome da empresa inicial" "Minha Empresa"
read_default EVENTMENU_INSTALL_ADMIN_NAME "Nome do Super ADM" "Super Administrador"
read_default EVENTMENU_INSTALL_ADMIN_EMAIL "E-mail do Super ADM" "admin@example.com"
if [[ -z "${EVENTMENU_INSTALL_ADMIN_PASSWORD:-}" ]]; then
  read -r -s -p "Senha do Super ADM (mínimo 8 caracteres): " EVENTMENU_INSTALL_ADMIN_PASSWORD
  echo
  export EVENTMENU_INSTALL_ADMIN_PASSWORD
fi

php scripts/install-cli.php
unset EVENTMENU_INSTALL_ADMIN_PASSWORD

if [[ "${INSTALL_WHATSAPP_BRIDGE:-0}" == "1" ]]; then
  command -v node >/dev/null 2>&1 || { echo "ERRO: Node.js 22+ não encontrado para WhatsApp Bridge."; exit 1; }
  command -v npm >/dev/null 2>&1 || { echo "ERRO: npm não encontrado para WhatsApp Bridge."; exit 1; }
  node -e 'const m=Number(process.versions.node.split(".")[0]); process.exit(m>=22?0:1)' || { echo "ERRO: Node.js 22+ obrigatório para WhatsApp Bridge."; exit 1; }
  (cd integrations/whatsapp-worker && npm install --omit=dev)
  echo "WhatsApp Bridge instalada. Configure WHATSAPP_BRIDGE_SECRET antes de ativá-la."
fi

echo
echo "Instalação concluída."
echo "Configure o cron a cada minuto:"
echo "* * * * * php $(pwd)/cron.php >/dev/null 2>&1"
echo "Acesse: ${EVENTMENU_INSTALL_URL}"
