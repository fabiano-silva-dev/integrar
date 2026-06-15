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

production_paths_default() {
    grep -q 'PROD_SOURCE_DIR="/home/fabiano/Projetos/integrar_dalongaro"' "$SCRIPT" &&
        grep -q 'PROD_DEPLOY_DIR="/home/fabiano/Projetos/integrar"' "$SCRIPT" &&
        grep -q 'apply_default_paths' "$SCRIPT"
}

clone_dry_run_from_dalongaro() {
    local output tmp
    tmp="$(mktemp -d)"
    mkdir -p "$tmp/integrar_dalongaro"
    cp "$ROOT/artisan" "$tmp/integrar_dalongaro/"
    cp "$ROOT/composer.json" "$tmp/integrar_dalongaro/"
    output="$("$SCRIPT" --yes --dry-run --skip-packages --skip-database \
        --source-dir "$tmp/integrar_dalongaro" 2>&1)"
    grep -q 'Destino:  '"$tmp"'/integrar' <<<"$output" &&
        grep -q 'cópia (origem preservada)' <<<"$output" &&
        grep -q 'Copiar projeto para instalação nativa' <<<"$output"
}

detects_compose_containers() {
    grep -q 'resolve_docker_compose_service_container' "$SCRIPT" &&
        grep -q 'detect_compose_mysql_service' "$SCRIPT" &&
        grep -q 'list_compose_container_names' "$SCRIPT" &&
        grep -q 'show_docker_compose_info' "$SCRIPT"
}

contains_required_production_guards() {
    grep -q 'set -Eeuo pipefail' "$SCRIPT" &&
        grep -q 'apache2 mysql-server php-fpm' "$SCRIPT" &&
        grep -q 'apache2ctl configtest' "$SCRIPT" &&
        grep -q 'a2enmod proxy_fcgi setenvif rewrite headers' "$SCRIPT" &&
        ! grep -qi 'nginx' "$SCRIPT" &&
        grep -q 'migrate --force' "$SCRIPT" &&
        grep -q 'mysqldump' "$SCRIPT" &&
        grep -q 'single-transaction' "$SCRIPT" &&
        grep -q -e '--databases' "$SCRIPT" &&
        grep -q 'mysql_as_root' "$SCRIPT" &&
        grep -q 'fix_app_permissions' "$SCRIPT" &&
        grep -q 'APP_URL_SCHEME' "$SCRIPT" &&
        ! grep -q 'chown -R "$APP_USER:$APP_GROUP" "$PROJECT_DIR"' "$SCRIPT"
}

reads_docker_compose_credentials() {
    grep -q 'read_compose_mysql_var' "$SCRIPT" &&
        grep -q 'MYSQL_ROOT_PASSWORD' "$SCRIPT" &&
        grep -q 'read_compose_service_field' "$SCRIPT"
}

test_case "exibe ajuda" help_works
test_case "rejeita opção desconhecida" unknown_option_fails
test_case "rejeita caminho relativo" relative_project_fails
test_case "dry-run percorre a instalação sem alterações" dry_run_is_safe_and_complete
test_case "usa integraexpert.com.br e banco integrar por padrão" default_domain_and_db
test_case "detecta containers do docker-compose.yml" detects_compose_containers
test_case "modo cópia integrar_dalongaro → integrar" production_paths_default
test_case "dry-run cópia aponta destino ../integrar" clone_dry_run_from_dalongaro
test_case "mantém proteções essenciais de produção" contains_required_production_guards
test_case "lê credenciais do docker-compose.yml" reads_docker_compose_credentials

if ((failures > 0)); then
    printf '%d teste(s) falharam\n' "$failures" >&2
    exit 1
fi

printf 'Todos os testes do instalador passaram.\n'
