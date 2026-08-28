#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/script-manutencao/habilitar-automacao-fiscal-producao.sh"
failures=0

test_case() {
    local description="$1"
    shift
    if "$@"; then
        printf 'ok - %s\n' "$description"
    else
        printf 'not ok - %s\n' "$description"
        failures=$((failures + 1))
    fi
}

help_works() {
    "$SCRIPT" --help | grep -q -- '--with-deps'
}

unknown_option_fails() {
    ! "$SCRIPT" --opcao-inexistente >/dev/null 2>&1
}

dry_run_covers_steps() {
    local output
    output="$("$SCRIPT" --yes --dry-run --project-dir "$ROOT" 2>&1)"
    grep -q 'PortaisIntegracaoSeeder\|db:seed' <<<"$output" &&
        grep -q 'automacao-fiscal.env\|PLAYWRIGHT_BROWSERS_PATH' <<<"$output" &&
        grep -q '\[dry-run\]' <<<"$output"
}

symlink_exists() {
    [[ -f "$ROOT/habilitar-automacao-fiscal-producao.sh" ]]
}

guards_present() {
    grep -q 'set -Eeuo pipefail' "$SCRIPT" &&
        grep -q 'PortaisIntegracaoSeeder' "$SCRIPT" &&
        grep -q 'integrar-queue-automacoes' "$SCRIPT" &&
        grep -q 'AUTOMACAO_FISCAL_FAKE_MODE' "$SCRIPT" &&
        grep -q 'require_root' "$SCRIPT"
}

printf '1..5\n'
test_case 'help funciona' help_works
test_case 'opção desconhecida falha' unknown_option_fails
test_case 'dry-run cobre seed e env' dry_run_covers_steps
test_case 'atalho na raiz existe' symlink_exists
test_case 'guards de produção presentes' guards_present

if (( failures > 0 )); then
    printf '\n%d falha(s)\n' "$failures"
    exit 1
fi

printf '\nTodos os testes passaram.\n'
