#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/script-manutencao/atualizar-producao.sh"

"$SCRIPT" --help | grep -q -- 'www-data'
"$SCRIPT" --help | grep -q -- 'sudo'

echo "ok - atualizar-producao.sh --help"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/projeto/script-manutencao" "$TMP/bin"
cp "$SCRIPT" "$TMP/projeto/script-manutencao/atualizar-producao.sh"
touch "$TMP/projeto/artisan" "$TMP/projeto/docker-compose.yml"
export NFSE_TEST_LOG="$TMP/comandos.log"
cat > "$TMP/bin/docker" <<'MOCK'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$NFSE_TEST_LOG"
if [[ "$*" == *'ps --status running app'* ]]; then
    echo app
fi
if [[ "$*" == *'nfse:instalar-recursos'* && "${NFSE_TEST_FAIL_INSTALL:-0}" == 1 ]]; then
    exit 1
fi
if [[ "$*" == *'runner run build'* && "${NFSE_TEST_FAIL_RUNNER_BUILD:-0}" == 1 ]]; then
    exit 1
fi
MOCK
chmod +x "$TMP/bin/docker"
export PATH="$TMP/bin:$PATH"
"$TMP/projeto/script-manutencao/atualizar-producao.sh" --docker --skip-composer --skip-npm --skip-cache > "$TMP/saida"
[[ "$(grep -n 'php artisan migrate --force' "$NFSE_TEST_LOG" | cut -d: -f1)" -lt "$(grep -n 'php artisan nfse:instalar-recursos' "$NFSE_TEST_LOG" | cut -d: -f1)" ]]
echo "ok - instalação NFS-e ocorre depois das migrations"

if NFSE_TEST_FAIL_INSTALL=1 "$TMP/projeto/script-manutencao/atualizar-producao.sh" --docker --skip-composer --skip-npm --skip-cache > "$TMP/falha"; then
    echo "erro - atualização ignorou falha na instalação NFS-e" >&2
    exit 1
fi
! grep -q 'Atualização concluída' "$TMP/falha"
echo "ok - instalação NFS-e com falha interrompe atualização"

: > "$NFSE_TEST_LOG"
"$TMP/projeto/script-manutencao/atualizar-producao.sh" --docker --skip-composer --skip-npm --skip-cache --skip-migrate > "$TMP/sem-migrations"
! grep -q 'nfse:instalar-recursos' "$NFSE_TEST_LOG"
echo "ok - skip-migrate preserva a opção de não alterar o banco"

mkdir -p "$TMP/projeto/scripts/automacao-fiscal/runner"
touch "$TMP/projeto/package.json" "$TMP/projeto/scripts/automacao-fiscal/runner/package-lock.json"
: > "$NFSE_TEST_LOG"
"$TMP/projeto/script-manutencao/atualizar-producao.sh" --docker --skip-composer --skip-cache > "$TMP/com-runner"
grep -q 'runner ci' "$NFSE_TEST_LOG"
grep -q 'runner run build' "$NFSE_TEST_LOG"
grep -q 'playwright install --with-deps chromium' "$NFSE_TEST_LOG"
[[ "$(grep -n 'runner run build' "$NFSE_TEST_LOG" | cut -d: -f1)" -lt "$(grep -n 'php artisan migrate --force' "$NFSE_TEST_LOG" | cut -d: -f1)" ]]
echo "ok - atualizador prepara runner e Chromium antes das migrations"

: > "$NFSE_TEST_LOG"
if NFSE_TEST_FAIL_RUNNER_BUILD=1 "$TMP/projeto/script-manutencao/atualizar-producao.sh" --docker --skip-composer --skip-cache > "$TMP/runner-falha"; then
    echo "erro - atualização ignorou falha do runner" >&2
    exit 1
fi
! grep -q 'php artisan migrate' "$NFSE_TEST_LOG"
echo "ok - falha do runner interrompe atualização antes de alterar o banco"
