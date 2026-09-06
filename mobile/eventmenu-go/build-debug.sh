#!/usr/bin/env bash
set -euo pipefail

GRADLE_VERSION="9.3.1"
ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
CACHE_DIR="${HOME}/.eventmenu-go"
GRADLE_HOME_DIR="${CACHE_DIR}/gradle-${GRADLE_VERSION}"
ZIP_FILE="${CACHE_DIR}/gradle-${GRADLE_VERSION}-bin.zip"
DIST_URL="https://services.gradle.org/distributions/gradle-${GRADLE_VERSION}-bin.zip"

mkdir -p "${CACHE_DIR}"

if [[ ! -x "${GRADLE_HOME_DIR}/bin/gradle" ]]; then
  echo "Baixando Gradle ${GRADLE_VERSION}..."
  if command -v curl >/dev/null 2>&1; then
    curl -fL "${DIST_URL}" -o "${ZIP_FILE}"
  elif command -v wget >/dev/null 2>&1; then
    wget -O "${ZIP_FILE}" "${DIST_URL}"
  else
    echo "Erro: instale curl ou wget para baixar o Gradle." >&2
    exit 1
  fi
  command -v unzip >/dev/null 2>&1 || { echo "Erro: unzip não encontrado." >&2; exit 1; }
  rm -rf "${GRADLE_HOME_DIR}"
  unzip -q "${ZIP_FILE}" -d "${CACHE_DIR}"
fi

cd "${ROOT_DIR}"

if [[ ! -f local.properties ]]; then
  SDK_PATH="${ANDROID_SDK_ROOT:-${ANDROID_HOME:-}}"
  if [[ -n "${SDK_PATH}" ]]; then
    printf 'sdk.dir=%s\n' "${SDK_PATH}" > local.properties
  fi
fi

if [[ ! -f local.properties && -z "${ANDROID_SDK_ROOT:-${ANDROID_HOME:-}}" ]]; then
  echo "Aviso: configure ANDROID_SDK_ROOT/ANDROID_HOME ou abra o projeto no Android Studio para localizar o SDK." >&2
fi

exec "${GRADLE_HOME_DIR}/bin/gradle" :app:assembleDebug "$@"
