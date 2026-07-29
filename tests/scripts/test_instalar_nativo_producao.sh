#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/script-manutencao/instalar-nativo-producao.sh"
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

relative_project_fails() {
    ! "$SCRIPT" --yes --dry-run --project-dir relativo >/dev/null 2>&1
}

dry_run_is_safe_and_complete() {
    local output
    output="$("$SCRIPT" --yes --dry-run --skip-packages --skip-database \
        --project-dir "$ROOT" 2>&1)"
    grep -q 'Configurar PHP-FPM para produção' <<<"$output" &&
        grep -q '\[dry-run\]' <<<"$output" &&
        grep -q 'Configurar o Apache' <<<"$output" &&
        grep -q 'Configurar fila e agendador no systemd' <<<"$output" &&
        grep -q 'Dry-run: testes reais' <<<"$output" &&
        grep -q 'integraexpert.com.br' <<<"$output"
}

default_domain_and_db() {
    grep -q 'DEFAULT_DOMAIN="integraexpert.com.br"' "$SCRIPT" &&
        grep -q 'DEFAULT_DB_NAME="integrar"' "$SCRIPT" &&
        grep -q 'DOMAIN="$DEFAULT_DOMAIN"' "$SCRIPT" &&
        grep -q 'DB_NAME="$DEFAULT_DB_NAME"' "$SCRIPT"
}

default_project_dir_is_repo_root() {
    grep -q 'SCRIPT_DIR/..' "$SCRIPT"
}

contains_required_production_guards() {
    grep -q 'set -Eeuo pipefail' "$SCRIPT" &&
        grep -q 'apache2 mysql-server php-fpm' "$SCRIPT" &&
        grep -q 'poppler-utils' "$SCRIPT" &&
        grep -q 'pdftotext' "$SCRIPT" &&
        grep -q 'ensure_www_html_symlink' "$SCRIPT" &&
        grep -q 'apache2ctl configtest' "$SCRIPT" &&
        grep -q 'a2enmod proxy_fcgi setenvif rewrite headers' "$SCRIPT" &&
        grep -q 'migrate --force' "$SCRIPT" &&
        grep -q 'mysqldump' "$SCRIPT" &&
        grep -q 'single-transaction' "$SCRIPT" &&
        grep -q 'User=www-data' "$SCRIPT" &&
        grep -q 'Deploy user dono' "$SCRIPT"
}

includes_poppler_and_health_check() {
    grep -q 'poppler-utils' "$SCRIPT" &&
        grep -q 'pdftotext (plano de contas PDF Domínio)' "$SCRIPT"
}

test_case "exibe ajuda" help_works
test_case "rejeita opção desconhecida" unknown_option_fails
test_case "rejeita caminho relativo" relative_project_fails
test_case "dry-run percorre a instalação sem alterações" dry_run_is_safe_and_complete
test_case "usa integraexpert.com.br e banco integrar por padrão" default_domain_and_db
test_case "PROJECT_DIR padrão é a raiz do repositório" default_project_dir_is_repo_root
test_case "mantém proteções essenciais de produção" contains_required_production_guards
test_case "inclui poppler-utils e health check pdftotext" includes_poppler_and_health_check

if ((failures > 0)); then
    printf '%d teste(s) falharam\n' "$failures" >&2
    exit 1
fi

printf 'Todos os testes do instalador passaram.\n'
