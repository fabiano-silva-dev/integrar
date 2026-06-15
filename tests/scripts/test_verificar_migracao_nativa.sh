#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/script-manutencao/verificar_migracao_nativa.sh"
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
    "$SCRIPT" --help | grep -q 'migração nativa'
}

generates_report() {
    local output report_dir
    report_dir="$(mktemp -d)"
    output="$report_dir/relatorio.md"
    "$SCRIPT" --project-dir "$ROOT" --output "$output" --quiet || true
    [[ -s "$output" ]] &&
        grep -q 'Verificação pós-migração' "$output" &&
        grep -q 'Resumo' "$output" &&
        grep -q 'integraexpert.com.br' "$output"
}

is_read_only() {
    ! grep -qE '(^|[[:space:]])(apt-get|chmod |chown |sed -i|systemctl (enable|restart|start))' "$SCRIPT"
}

has_migration_checks() {
    grep -q 'check_apache' "$SCRIPT" &&
        grep -q 'ProxyPass' "$SCRIPT" &&
        grep -q 'integrar-queue' "$SCRIPT" &&
        grep -q 'home_traverse_ok' "$SCRIPT"
}

test_case "exibe ajuda" help_works
test_case "gera relatório markdown" generates_report
test_case "não altera o sistema" is_read_only
test_case "cobre checagens da migração" has_migration_checks

if ((failures > 0)); then
    printf '%d teste(s) falharam\n' "$failures" >&2
    exit 1
fi

printf 'Todos os testes da verificação passaram.\n'
