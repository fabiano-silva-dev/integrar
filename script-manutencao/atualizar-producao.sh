#!/usr/bin/env bash
# Atualização pós git pull — padrão: Apache nativo.
# composer/npm como dono do projeto; artisan como www-data (PHP-FPM).
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
APP_GROUP="www-data"
DEPLOY_USER=""
NPM_BIN=""
NPM_SKIPPED=false

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

Padrão: modo nativo (Apache/PHP-FPM).
Composer e npm rodam como dono do projeto (quem fez git pull).
Artisan e permissões de runtime usam www-data quando necessário.
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

detect_deploy_user() {
    DEPLOY_USER="$(stat -c '%U' "$PROJECT_DIR" 2>/dev/null || echo "")"
    if [[ -z "$DEPLOY_USER" || "$DEPLOY_USER" == "UNKNOWN" ]]; then
        if [[ -n "${SUDO_USER:-}" ]]; then
            DEPLOY_USER="$SUDO_USER"
        else
            DEPLOY_USER="$(current_user)"
        fi
    fi
}

# PATH mínimo para evitar .bashrc/.profile do deploy user (ex.: nvm quebrado).
readonly SYSTEM_PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

resolve_npm() {
    NPM_BIN=""

    if command -v npm >/dev/null 2>&1; then
        NPM_BIN="$(command -v npm)"
    fi

    for candidate in /usr/bin/npm /usr/local/bin/npm; do
        if [[ -z "$NPM_BIN" && -x "$candidate" ]]; then
            NPM_BIN="$candidate"
        fi
    done

    if [[ -z "$NPM_BIN" && -n "$DEPLOY_USER" ]]; then
        local nvm_npm
        shopt -s nullglob
        local -a nvm_bins=(/home/"$DEPLOY_USER"/.nvm/versions/node/*/bin/npm)
        shopt -u nullglob
        if ((${#nvm_bins[@]} > 0)); then
            nvm_npm="$(printf '%s\n' "${nvm_bins[@]}" | sort -V | tail -n1)"
            [[ -x "$nvm_npm" ]] && NPM_BIN="$nvm_npm"
        fi
    fi

    [[ -n "$NPM_BIN" ]]
}

has_built_assets() {
    [[ -f "$PROJECT_DIR/public/build/manifest.json" ]]
}

warn_missing_npm() {
    warn "npm não encontrado no PATH do sistema."
    warn "Instale Node.js 20+ (como no instalador nativo):"
    warn "  curl -fsSL https://deb.nodesource.com/setup_20.x | sudo bash -"
    warn "  sudo apt-get install -y nodejs"
    warn "Ou use --skip-npm se os assets em public/build/ já estiverem atualizados."
}
run_as_deploy_user() {
    if is_root; then
        sudo -u "$DEPLOY_USER" "$@"
    elif [[ "$(current_user)" == "$DEPLOY_USER" ]]; then
        "$@"
    else
        sudo -u "$DEPLOY_USER" "$@"
    fi
}

# Artisan em runtime: www-data (mesmo usuário do PHP-FPM).
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
        run_as_deploy_user composer install --working-dir="$PROJECT_DIR" \
            --no-interaction --prefer-dist --optimize-autoloader "$@"
    fi
}

run_npm() {
    if [[ "$MODE" == docker ]]; then
        docker compose exec -T app npm ci
        docker compose exec -T app npm run build
    else
        if ! resolve_npm; then
            if has_built_assets; then
                warn_missing_npm
                warn "Mantendo assets existentes em public/build/"
                NPM_SKIPPED=true
                return 0
            fi
            err "npm não encontrado e public/build/manifest.json não existe."
            warn_missing_npm
            exit 1
        fi

        local npm_dir
        npm_dir="$(dirname "$NPM_BIN")"
        run_as_deploy_user env PATH="$npm_dir:$SYSTEM_PATH" bash -c \
            "cd '$PROJECT_DIR' && '$NPM_BIN' ci && '$NPM_BIN' run build"
    fi
}

fix_storage_permissions() {
    if [[ "$MODE" != native ]]; then
        return
    fi

    log "Ajustando permissões de storage e bootstrap/cache..."
    run_as_root mkdir -p \
        "$PROJECT_DIR/storage/logs" \
        "$PROJECT_DIR/storage/framework/cache" \
        "$PROJECT_DIR/storage/framework/sessions" \
        "$PROJECT_DIR/storage/framework/views" \
        "$PROJECT_DIR/bootstrap/cache"
    # Dono = deploy (composer/npm); grupo = www-data (PHP-FPM/artisan)
    run_as_root chown -R "$DEPLOY_USER:$APP_GROUP" \
        "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
    run_as_root chmod -R ug+rwX \
        "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
    run_as_root find "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache" \
        -type d -exec chmod g+s {} +
    ok "Permissões ajustadas ($DEPLOY_USER:$APP_GROUP)"

    if ! id -nG "$DEPLOY_USER" 2>/dev/null | grep -qw "$APP_GROUP"; then
        warn "$DEPLOY_USER não está no grupo $APP_GROUP."
        warn "Recomendado: sudo usermod -aG $APP_GROUP $DEPLOY_USER (depois faça logout/login)"
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
    if [[ "$MODE" == native ]]; then
        detect_deploy_user
    fi

    echo ""
    echo "🚀 Atualizar produção — IntegraExpert"
    echo "===================================="
    log "Diretório: $PROJECT_DIR"
    log "Modo: $MODE"
    if [[ "$MODE" == native ]]; then
        log "Deploy (composer/npm): $DEPLOY_USER"
        log "Runtime (artisan/web): $APP_USER"
    fi
    echo ""

    if [[ "$MODE" == native ]]; then
        fix_storage_permissions
    fi

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
        if [[ "$MODE" == native ]] && ! resolve_npm && ! has_built_assets; then
            err "Node.js/npm não instalado e não há assets compilados."
            warn_missing_npm
            exit 1
        fi

        log "Compilando assets (npm)..."
        NPM_SKIPPED=false
        run_npm
        if $NPM_SKIPPED; then
            warn "Assets não recompilados — usando public/build/ existente"
        else
            ok "Assets compilados"
        fi
    fi

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

    fix_storage_permissions

    reload_services

    echo ""
    ok "Atualização concluída!"
    if [[ "$MODE" == docker ]]; then
        echo "   Acesse: http://localhost:8081"
    fi
    echo ""
}

main "$@"
