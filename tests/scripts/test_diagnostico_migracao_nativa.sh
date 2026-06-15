#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/script-manutencao/diagnostico_migracao_nativa.sh"
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
    "$SCRIPT" --help | grep -q 'diagnostico_migracao'
}

generates_report() {
    local output report_dir
    report_dir="$(mktemp -d)"
    output="$report_dir/relatorio.md"
    "$SCRIPT" --project-dir "$ROOT" --output "$output" --quiet
    [[ -s "$output" ]] &&
        grep -q 'Diagnóstico — migração Integrar' "$output" &&
        grep -q 'integraexpert.com.br' "$output" &&
        grep -q 'Prontidão para instalar-nativo-producao.sh' "$output"
}

is_read_only() {
    ! grep -qE 'apt-get|systemctl (enable|restart|start)|sed -i|chmod|chown' "$SCRIPT"
}

test_case "exibe ajuda" help_works
test_case "gera relatório markdown" generates_report
test_case "não altera o sistema" is_read_only

if ((failures > 0)); then
    printf '%d teste(s) falharam\n' "$failures" >&2
    exit 1
fi

printf 'Todos os testes do diagnóstico passaram.\n'
