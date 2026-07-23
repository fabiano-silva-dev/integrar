#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/script-manutencao/instalar-deps-automacao-fiscal.sh"
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
    "$SCRIPT" --help | grep -q -- '--dry-run'
}

unknown_option_fails() {
    ! "$SCRIPT" --opcao-inexistente >/dev/null 2>&1
}

dry_run_mentions_runner() {
    local output
    output="$("$SCRIPT" --yes --dry-run --project-dir "$ROOT" 2>&1)"
    grep -q 'Node.js' <<<"$output" &&
        grep -q 'playwright\|Playwright\|Chromium\|runner' <<<"$output" &&
        grep -q 'integrar-queue-automacoes\|systemd' <<<"$output" &&
        grep -q '\[dry-run\]' <<<"$output"
}

symlink_exists() {
    [[ -L "$ROOT/instalar-deps-automacao-fiscal.sh" ]] || [[ -f "$ROOT/instalar-deps-automacao-fiscal.sh" ]]
}

script_is_idempotent_guards() {
    grep -q 'set -Eeuo pipefail' "$SCRIPT" &&
        grep -q 'NODE_MAJOR_MIN=24' "$SCRIPT" &&
        grep -q 'PLAYWRIGHT_BROWSERS_PATH' "$SCRIPT" &&
        grep -q 'integrar-queue-automacoes' "$SCRIPT" &&
        grep -q 'npm --prefix' "$SCRIPT"
}

printf '1..5\n'
test_case 'help funciona' help_works
test_case 'opção desconhecida falha' unknown_option_fails
test_case 'dry-run cobre runner e systemd' dry_run_mentions_runner
test_case 'atalho na raiz existe' symlink_exists
test_case 'guards de produção presentes' script_is_idempotent_guards

if (( failures > 0 )); then
    printf '\n%d falha(s)\n' "$failures"
    exit 1
fi

printf '\nTodos os testes passaram.\n'
