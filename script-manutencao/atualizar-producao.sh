#!/usr/bin/env bash
# Atualização pós git pull — padrão: Apache nativo (www-data).
# Uso: ./atualizar-producao.sh
#      ./atualizar-producao.sh --docker   (ambiente de desenvolvimento)
set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

MODE="native"
SKIP_COMPOSER=false
SKIP_NPM=false
SKIP_MIGRATE=false
SKIP_CACHE=false
APP_USER="www-data"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log()  { echo -e "${BLUE}[$(date +'%H:%M:%S')]${NC} $*"; }
ok()   { echo -e "${GREEN}✅${NC} $*"; }
warn() { echo -e "${YELLOW}⚠️${NC}  $*"; }
err()  { echo -e "${RED}❌${NC} $*" >&2; }

usage() {
    cat <<EOF
Uso: $(basename "$0") [opções]

Atualiza a aplicação após git pull (composer, assets, migrate, cache).

Padrão: modo nativo (Apache/PHP-FPM) como usuário www-data.
O sudo é solicitado automaticamente quando necessário.

Opções:
  --docker          Usa Docker (desenvolvimento)
  --native          Modo nativo (padrão)
  --skip-composer   Não roda composer install
  --skip-npm        Não compila assets (npm)
  --skip-migrate    Não roda migrations
  --skip-cache      Não limpa/recria cache Laravel
  --app-user USER   Usuário da aplicação (padrão: www-data)
  -h, --help        Esta ajuda
EOF
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --docker) MODE=docker; shift ;;
            --native) MODE=native; shift ;;
            --skip-composer) SKIP_COMPOSER=true; shift ;;
            --skip-npm) SKIP_NPM=true; shift ;;
            --skip-migrate) SKIP_MIGRATE=true; shift ;;
            --skip-cache) SKIP_CACHE=true; shift ;;
            --app-user) APP_USER="$2"; shift 2 ;;
            -h|--help) usage; exit 0 ;;
            *) err "Opção desconhecida: $1"; usage; exit 1 ;;
        esac
    done
}

is_root() {
    [[ "$(id -u)" -eq 0 ]]
}

current_user() {
    id -un
}

# Executa como APP_USER; pede sudo se o usuário atual não for root nem APP_USER.
run_as_app_user() {
    if is_root; then
        sudo -u "$APP_USER" "$@"
    elif [[ "$(current_user)" == "$APP_USER" ]]; then
        "$@"
    else
        sudo -u "$APP_USER" "$@"
    fi
}

# Executa com privilégios de root; pede sudo se necessário.
run_as_root() {
    if is_root; then
        "$@"
    else
        sudo "$@"
    fi
}

load_app_env() {
    if [[ -f "$PROJECT_DIR/.env" ]]; then
        local value
        value="$(grep -m1 '^APP_ENV=' "$PROJECT_DIR/.env" | cut -d= -f2- | tr -d "\"'[:space:]")"
        if [[ -n "$value" ]]; then
            APP_ENV="$value"
        fi
    fi
}

run_artisan() {
    if [[ "$MODE" == docker ]]; then
        docker compose exec -T app php artisan "$@"
    else
        run_as_app_user php "$PROJECT_DIR/artisan" "$@"
    fi
}

run_composer() {
    if [[ "$MODE" == docker ]]; then
        docker compose exec -T app composer install \
            --no-interaction --prefer-dist --optimize-autoloader "$@"
    else
        run_as_app_user composer install --working-dir="$PROJECT_DIR" \
            --no-interaction --prefer-dist --optimize-autoloader "$@"
    fi
}

run_npm() {
    if [[ "$MODE" == docker ]]; then
        docker compose exec -T app npm ci
        docker compose exec -T app npm run build
    else
        run_as_app_user bash -lc "cd '$PROJECT_DIR' && npm ci && npm run build"
    fi
}

reload_services() {
    if [[ "$MODE" != native ]]; then
        return
    fi

    if run_as_root systemctl is-active --quiet php8.2-fpm 2>/dev/null; then
        log "Recarregando PHP-FPM..."
        run_as_root systemctl reload php8.2-fpm || warn "Falha ao recarregar php8.2-fpm"
    elif run_as_root systemctl is-active --quiet php8.3-fpm 2>/dev/null; then
        log "Recarregando PHP-FPM..."
        run_as_root systemctl reload php8.3-fpm || warn "Falha ao recarregar php8.3-fpm"
    fi

    for unit in integrar-queue integrar-schedule; do
        if run_as_root systemctl list-unit-files "${unit}.service" 2>/dev/null | grep -q "${unit}.service"; then
            if run_as_root systemctl is-active --quiet "$unit" 2>/dev/null; then
                log "Reiniciando $unit..."
                run_as_root systemctl restart "$unit" || warn "Falha ao reiniciar $unit"
            fi
        fi
    done
}

validate_environment() {
    cd "$PROJECT_DIR"

    if [[ ! -f artisan ]]; then
        err "artisan não encontrado em $PROJECT_DIR"
        exit 1
    fi

    if [[ "$MODE" == docker ]]; then
        if [[ ! -f docker-compose.yml ]]; then
            err "docker-compose.yml não encontrado. Use modo nativo ou verifique o diretório."
            exit 1
        fi
        if ! docker compose ps --status running app 2>/dev/null | grep -q .; then
            err "Container app não está rodando. Inicie com: docker compose up -d"
            exit 1
        fi
    elif ! id "$APP_USER" &>/dev/null; then
        err "Usuário '$APP_USER' não existe neste sistema."
        exit 1
    fi
}

main() {
    parse_args "$@"
    validate_environment
    load_app_env

    echo ""
    echo "🚀 Atualizar produção — IntegraExpert"
    echo "===================================="
    log "Diretório: $PROJECT_DIR"
    log "Modo: $MODE"
    if [[ "$MODE" == native ]]; then
        log "Usuário da aplicação: $APP_USER"
    fi
    echo ""

    if ! $SKIP_COMPOSER; then
        log "Composer install..."
        if [[ "$MODE" == native && "${APP_ENV:-production}" != "local" ]]; then
            run_composer --no-dev
        else
            run_composer
        fi
        ok "Dependências PHP atualizadas"
    fi

    if ! $SKIP_NPM && [[ -f package.json ]]; then
        log "Compilando assets (npm)..."
        run_npm
        ok "Assets compilados"
    fi

    log "Livewire discover..."
    run_artisan livewire:discover
    ok "Componentes Livewire atualizados"

    if ! $SKIP_MIGRATE; then
        log "Migrations..."
        run_artisan migrate --force
        ok "Banco de dados atualizado"
    fi

    if ! $SKIP_CACHE; then
        log "Limpando caches..."
        run_artisan optimize:clear
        ok "Caches limpos"

        if [[ "$MODE" == native && "${APP_ENV:-production}" == "production" ]]; then
            log "Recriando caches de produção..."
            run_artisan optimize
            ok "Caches de produção recriados"
        fi
    fi

    reload_services

    echo ""
    ok "Atualização concluída!"
    if [[ "$MODE" == docker ]]; then
        echo "   Acesse: http://localhost:8081"
    fi
    echo ""
}

main "$@"
