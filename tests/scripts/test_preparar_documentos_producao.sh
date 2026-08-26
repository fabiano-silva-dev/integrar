#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/script-manutencao/preparar-documentos-producao.sh"
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

dry_run_covers_stack() {
    local output
    output="$("$SCRIPT" --yes --dry-run --project-dir "$ROOT" --skip-apt 2>&1)"
    grep -q 'pdftotext\|poppler\|documentos' <<<"$output" &&
        grep -q 'integrar-queue-automacoes\|systemd' <<<"$output" &&
        grep -q 'evolution' <<<"$output" &&
        grep -q '\[dry-run\]' <<<"$output"
}

symlink_exists() {
    [[ -f "$ROOT/preparar-documentos-producao.sh" ]]
}

script_guards() {
    grep -q 'set -Eeuo pipefail' "$SCRIPT" &&
        grep -q 'poppler-utils' "$SCRIPT" &&
        grep -q 'documentos' "$SCRIPT" &&
        grep -q 'integrar-queue-automacoes' "$SCRIPT" &&
        grep -q 'timeout=900' "$SCRIPT" &&
        grep -q 'EVOLUTION_URL_BASE' "$SCRIPT" &&
        grep -q -- '--skip-evolution' "$SCRIPT"
}

printf '1..5\n'
test_case 'help funciona' help_works
test_case 'opção desconhecida falha' unknown_option_fails
test_case 'dry-run cobre pdftotext e systemd' dry_run_covers_stack
test_case 'atalho na raiz existe' symlink_exists
test_case 'guards de produção presentes' script_guards

if (( failures > 0 )); then
    printf '\n%d falha(s)\n' "$failures"
    exit 1
fi

printf '\nTodos os testes passaram.\n'
