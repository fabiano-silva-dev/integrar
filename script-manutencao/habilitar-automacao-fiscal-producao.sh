#!/usr/bin/env bash
# Habilita Automação Fiscal em produção nativa — passos que exigem sudo.
# Idempotente — seguro reexecutar.
#
# Complementa (não substitui):
#   ./atualizar-producao.sh
#   sudo ./instalar-deps-automacao-fiscal.sh --yes
#
# Uso (na raiz do projeto ou via atalho):
#   sudo ./habilitar-automacao-fiscal-producao.sh --yes
#   sudo ./script-manutencao/habilitar-automacao-fiscal-producao.sh --dry-run --yes
set -Eeuo pipefail

readonly SCRIPT_NAME="$(basename "$0")"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
APP_USER="${SUDO_USER:-}"
APP_GROUP="www-data"
QUEUE_UNIT="integrar-queue-automacoes"
SCHEDULER_UNIT="integrar-scheduler"
PLAYWRIGHT_BROWSERS_PATH_DEFAULT="/var/cache/integrar-playwright"
PLAYWRIGHT_BROWSERS_PATH="$PLAYWRIGHT_BROWSERS_PATH_DEFAULT"
SYSTEMD_ENV_FILE="/etc/integrar/automacao-fiscal.env"
YES=false
DRY_RUN=false
SKIP_DEPS=false
SKIP_SEED=false
SKIP_ENV=false
SKIP_CACHE=false
SKIP_RESTART=false
WITH_DEPS=false

color() {
    if [[ -t 1 ]]; then
        printf '\033[%sm' "$1"
    fi
}
reset_color() { color 0; }
info() { color "1;34"; printf 'ℹ %s\n' "$*"; reset_color; }
success() { color "1;32"; printf '✓ %s\n' "$*"; reset_color; }
warn() { color "1;33"; printf '⚠ %s\n' "$*" >&2; reset_color >&2; }
die() { color "1;31"; printf '✗ %s\n' "$*" >&2; reset_color >&2; exit 1; }

usage() {
    cat <<EOF
Uso: sudo ./$SCRIPT_NAME [opções]

Finaliza a habilitação da Automação Fiscal quando o agente remoto
não consegue digitar a senha do sudo. Faz, nesta ordem:

  1. (opcional) deps Node/Playwright via instalar-deps-automacao-fiscal.sh
  2. variáveis no .env (fake_mode=false, token, PLAYWRIGHT_BROWSERS_PATH)
  3. /etc/integrar/automacao-fiscal.env para o systemd
  4. migra Chromium de ~/.cache para /var/cache/integrar-playwright (se existir)
  5. seed idempotente PortaisIntegracaoSeeder (e-CAC RS + NFS-e)
  6. optimize:clear + config/route/view cache
  7. restart de ${QUEUE_UNIT} (e ${SCHEDULER_UNIT} se existir)

Opções:
  --yes                      Confirma sem perguntas
  --dry-run                  Mostra ações sem alterar o sistema
  --project-dir CAMINHO      Raiz do Laravel (padrão: pai deste script)
  --app-user USUÁRIO         Dono do projeto (padrão: \$SUDO_USER ou fabiano)
  --browsers-path CAMINHO    PLAYWRIGHT_BROWSERS_PATH (padrão: ${PLAYWRIGHT_BROWSERS_PATH_DEFAULT})
  --with-deps                Também roda instalar-deps-automacao-fiscal.sh --yes
  --skip-deps                Não chama o instalador de deps (padrão)
  --skip-seed                Não roda PortaisIntegracaoSeeder
  --skip-env                 Não altera .env nem ${SYSTEMD_ENV_FILE}
  --skip-cache               Não limpa/regera caches Artisan
  --skip-restart             Não reinicia units systemd
  -h, --help                 Esta ajuda

Exemplos:
  # Caso típico (deps já instaladas ou instaladas em ~/.cache):
  sudo ./habilitar-automacao-fiscal-producao.sh --yes

  # Primeira vez no servidor (instala Node/Playwright + habilita):
  sudo ./habilitar-automacao-fiscal-producao.sh --yes --with-deps

  sudo ./script-manutencao/habilitar-automacao-fiscal-producao.sh --dry-run --yes
EOF
}

run() {
    if $DRY_RUN; then
        printf '[dry-run]'
        printf ' %q' "$@"
        printf '\n'
    else
        "$@"
    fi
}

run_as_app_user() {
    if $DRY_RUN; then
        printf '[dry-run] sudo -u %q' "$APP_USER"
        printf ' %q' "$@"
        printf '\n'
        return 0
    fi
    sudo -u "$APP_USER" "$@"
}

require_root() {
    if [[ ${EUID:-$(id -u)} -ne 0 ]] && ! $DRY_RUN; then
        die "Execute como root: sudo ./$SCRIPT_NAME --yes"
    fi
}

validate_inputs() {
    [[ "$PROJECT_DIR" = /* ]] || die "--project-dir deve ser absoluto."
    [[ -f "$PROJECT_DIR/artisan" ]] || die "Laravel não encontrado em $PROJECT_DIR"
    [[ -f "$PROJECT_DIR/.env" ]] || die ".env não encontrado em $PROJECT_DIR"

    if [[ -z "$APP_USER" ]]; then
        if id fabiano >/dev/null 2>&1; then
            APP_USER="fabiano"
        else
            APP_USER="$(stat -c '%U' "$PROJECT_DIR" 2>/dev/null || echo www-data)"
        fi
    fi

    if ! $DRY_RUN; then
        id "$APP_USER" >/dev/null 2>&1 || die "Usuário inexistente: $APP_USER"
        getent group "$APP_GROUP" >/dev/null 2>&1 || die "Grupo inexistente: $APP_GROUP"
    fi

    info "Projeto: $PROJECT_DIR"
    info "Usuário deploy: $APP_USER"
    info "Browsers Playwright: $PLAYWRIGHT_BROWSERS_PATH"
}

upsert_env_key() {
    local file="$1" key="$2" value="$3"
    if $DRY_RUN; then
        printf '[dry-run] upsert %s=%s em %s\n' "$key" "$value" "$file"
        return 0
    fi
    if grep -q "^${key}=" "$file"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$file"
    else
        printf '\n%s=%s\n' "$key" "$value" >>"$file"
    fi
}

ensure_laravel_env() {
    $SKIP_ENV && { warn "Pulando .env (--skip-env)"; return 0; }

    local env_file="$PROJECT_DIR/.env"
    info "Ajustando variáveis no .env ..."

    upsert_env_key "$env_file" "AUTOMACAO_FISCAL_FAKE_MODE" "false"
    upsert_env_key "$env_file" "PLAYWRIGHT_BROWSERS_PATH" "$PLAYWRIGHT_BROWSERS_PATH"
    upsert_env_key "$env_file" "AUTOMACAO_FISCAL_TIMEOUT_MS" "300000"

    if ! grep -q "^AUTOMACAO_FISCAL_RUNNER_TOKEN=" "$env_file" 2>/dev/null; then
        local token
        token="$(openssl rand -hex 16)"
        upsert_env_key "$env_file" "AUTOMACAO_FISCAL_RUNNER_TOKEN" "$token"
        success "AUTOMACAO_FISCAL_RUNNER_TOKEN gerado"
    else
        local current
        current="$(grep "^AUTOMACAO_FISCAL_RUNNER_TOKEN=" "$env_file" | head -1 | cut -d= -f2-)"
        if [[ ${#current} -lt 16 ]]; then
            token="$(openssl rand -hex 16)"
            upsert_env_key "$env_file" "AUTOMACAO_FISCAL_RUNNER_TOKEN" "$token"
            warn "Token curto substituído por um novo (>= 16 chars)"
        else
            success "AUTOMACAO_FISCAL_RUNNER_TOKEN já presente"
        fi
    fi

    run chown "$APP_USER:$APP_GROUP" "$env_file"
    success ".env da Automação Fiscal atualizado"
}

write_systemd_env() {
    $SKIP_ENV && return 0

    info "Escrevendo $SYSTEMD_ENV_FILE ..."
    run mkdir -p "$(dirname "$SYSTEMD_ENV_FILE")"
    if $DRY_RUN; then
        printf '[dry-run] escrever %s\n' "$SYSTEMD_ENV_FILE"
        return 0
    fi

    local token=""
    if grep -q "^AUTOMACAO_FISCAL_RUNNER_TOKEN=" "$PROJECT_DIR/.env"; then
        token="$(grep "^AUTOMACAO_FISCAL_RUNNER_TOKEN=" "$PROJECT_DIR/.env" | head -1 | cut -d= -f2-)"
    fi

    cat >"$SYSTEMD_ENV_FILE" <<EOF
# Gerado por $SCRIPT_NAME — $(date -Iseconds)
PLAYWRIGHT_BROWSERS_PATH=$PLAYWRIGHT_BROWSERS_PATH
AUTOMACAO_FISCAL_FAKE_MODE=false
AUTOMACAO_FISCAL_TIMEOUT_MS=300000
EOF
    if [[ -n "$token" ]]; then
        printf 'AUTOMACAO_FISCAL_RUNNER_TOKEN=%s\n' "$token" >>"$SYSTEMD_ENV_FILE"
    fi

    chmod 0640 "$SYSTEMD_ENV_FILE"
    chown "root:$APP_GROUP" "$SYSTEMD_ENV_FILE"
    success "Environment systemd: $SYSTEMD_ENV_FILE"
}

migrate_playwright_cache() {
    local home_cache="/home/${APP_USER}/.cache/integrar-playwright"
    run mkdir -p "$PLAYWRIGHT_BROWSERS_PATH"
    run chown -R "$APP_USER:$APP_GROUP" "$PLAYWRIGHT_BROWSERS_PATH"
    run chmod -R ug+rwX "$PLAYWRIGHT_BROWSERS_PATH"

    if [[ -d "$home_cache" ]] && [[ "$(find "$home_cache" -mindepth 1 -maxdepth 1 2>/dev/null | wc -l)" -gt 0 ]]; then
        if compgen -G "$PLAYWRIGHT_BROWSERS_PATH/chromium-*" >/dev/null 2>&1; then
            info "PLAYWRIGHT_BROWSERS_PATH já tem browsers — migração pulada"
        else
            info "Migrando browsers de $home_cache → $PLAYWRIGHT_BROWSERS_PATH ..."
            if $DRY_RUN; then
                printf '[dry-run] rsync -a %q/ %q/\n' "$home_cache" "$PLAYWRIGHT_BROWSERS_PATH"
            else
                rsync -a "$home_cache/" "$PLAYWRIGHT_BROWSERS_PATH/"
                chown -R "$APP_USER:$APP_GROUP" "$PLAYWRIGHT_BROWSERS_PATH"
                chmod -R ug+rwX "$PLAYWRIGHT_BROWSERS_PATH"
            fi
            success "Cache Playwright migrado"
        fi
    fi
}

run_deps_installer() {
    if ! $WITH_DEPS || $SKIP_DEPS; then
        return 0
    fi

    local deps_script="$SCRIPT_DIR/instalar-deps-automacao-fiscal.sh"
    [[ -x "$deps_script" ]] || die "Não encontrado: $deps_script"

    info "Rodando instalar-deps-automacao-fiscal.sh ..."
    local args=(--yes --project-dir "$PROJECT_DIR" --app-user "$APP_USER" --browsers-path "$PLAYWRIGHT_BROWSERS_PATH")
    $DRY_RUN && args+=(--dry-run)
    run "$deps_script" "${args[@]}"
}

seed_portais() {
    $SKIP_SEED && { warn "Pulando seed (--skip-seed)"; return 0; }

    info "Seed PortaisIntegracaoSeeder ..."
    # Preferir usuário da unit (fabiano ou www-data); artisan como APP_USER é ok em nativo split.
    if $DRY_RUN; then
        printf '[dry-run] sudo -u %q php artisan db:seed --class=PortaisIntegracaoSeeder --force\n' "$APP_USER"
        return 0
    fi
    (
        cd "$PROJECT_DIR"
        sudo -u "$APP_USER" php artisan db:seed --class=PortaisIntegracaoSeeder --force
    )
    success "Portais seedados (idempotente)"
}

refresh_caches() {
    $SKIP_CACHE && { warn "Pulando caches Artisan (--skip-cache)"; return 0; }

    info "Limpando e regenerando caches Laravel ..."
    if $DRY_RUN; then
        printf '[dry-run] artisan optimize:clear + config/route/view cache\n'
        return 0
    fi
    (
        cd "$PROJECT_DIR"
        sudo -u "$APP_USER" php artisan optimize:clear
        sudo -u "$APP_USER" php artisan config:cache
        sudo -u "$APP_USER" php artisan route:cache
        sudo -u "$APP_USER" php artisan view:cache
    )
    success "Caches regenerados"
}

restart_services() {
    $SKIP_RESTART && { warn "Pulando restart (--skip-restart)"; return 0; }

    if systemctl list-unit-files "${QUEUE_UNIT}.service" >/dev/null 2>&1 ||
        [[ -f "/etc/systemd/system/${QUEUE_UNIT}.service" ]]; then
        info "Reiniciando ${QUEUE_UNIT} ..."
        run systemctl daemon-reload
        run systemctl enable "${QUEUE_UNIT}.service" >/dev/null 2>&1 || true
        if systemctl is-active --quiet "${QUEUE_UNIT}" 2>/dev/null; then
            run systemctl restart "${QUEUE_UNIT}"
        else
            run systemctl start "${QUEUE_UNIT}" || warn "Não foi possível iniciar ${QUEUE_UNIT}"
        fi
        success "${QUEUE_UNIT}: $(systemctl is-active "${QUEUE_UNIT}" 2>/dev/null || echo '?')"
    else
        warn "Unit ${QUEUE_UNIT} não encontrada — rode: sudo ./instalar-deps-automacao-fiscal.sh --yes"
    fi

    if systemctl list-unit-files "${SCHEDULER_UNIT}.service" >/dev/null 2>&1 ||
        [[ -f "/etc/systemd/system/${SCHEDULER_UNIT}.service" ]]; then
        info "Reiniciando ${SCHEDULER_UNIT} ..."
        if systemctl is-active --quiet "${SCHEDULER_UNIT}" 2>/dev/null; then
            run systemctl restart "${SCHEDULER_UNIT}"
        fi
        success "${SCHEDULER_UNIT}: $(systemctl is-active "${SCHEDULER_UNIT}" 2>/dev/null || echo '?')"
    fi
}

print_status() {
    info "Status final"
    if $DRY_RUN; then
        info "Dry-run — status real pulado"
        return 0
    fi

    (
        cd "$PROJECT_DIR"
        sudo -u "$APP_USER" php artisan tinker --execute="
echo 'fake_mode=' . (config('automacao_fiscal.fake_mode') ? 'true' : 'false') . PHP_EOL;
echo 'playwright=' . (string) env('PLAYWRIGHT_BROWSERS_PATH') . PHP_EOL;
echo 'portais_ativos=' . App\Models\PortalIntegracao::query()->where('ativo', true)->count() . PHP_EOL;
echo 'runner_nm=' . (is_dir(base_path('scripts/automacao-fiscal/runner/node_modules')) ? 'ok' : 'MISSING') . PHP_EOL;
"
    ) || warn "Não foi possível ler status via tinker"

    echo
    echo "Fila:      $(systemctl is-active "${QUEUE_UNIT}" 2>/dev/null || echo ausente)"
    echo "Scheduler: $(systemctl is-active "${SCHEDULER_UNIT}" 2>/dev/null || echo ausente)"
    echo "Env unit:  $SYSTEMD_ENV_FILE"
    echo "Browsers:  $PLAYWRIGHT_BROWSERS_PATH"
    echo
    cat <<EOF
Próximo passo na UI:
  1. Configurações → Automação Fiscal → Portais (catálogo deve mostrar Ativo)
  2. Cadastros → Empresas → [empresa] → Integrações → marcar portal + certificado + recursos → Salvar
  3. Automação Fiscal → Executar consulta (validar acesso)
EOF
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --yes) YES=true; shift ;;
            --dry-run) DRY_RUN=true; shift ;;
            --project-dir)
                PROJECT_DIR="$(realpath "$2")"
                shift 2
                ;;
            --app-user) APP_USER="$2"; shift 2 ;;
            --browsers-path) PLAYWRIGHT_BROWSERS_PATH="$2"; shift 2 ;;
            --with-deps) WITH_DEPS=true; SKIP_DEPS=false; shift ;;
            --skip-deps) SKIP_DEPS=true; WITH_DEPS=false; shift ;;
            --skip-seed) SKIP_SEED=true; shift ;;
            --skip-env) SKIP_ENV=true; shift ;;
            --skip-cache) SKIP_CACHE=true; shift ;;
            --skip-restart) SKIP_RESTART=true; shift ;;
            -h|--help) usage; exit 0 ;;
            *) die "Opção desconhecida: $1 (use --help)" ;;
        esac
    done
}

confirm_or_yes() {
    $YES && return 0
    $DRY_RUN && return 0
    [[ -t 0 ]] || die "Use --yes em ambientes não interativos."
    local answer
    read -r -p "Habilitar Automação Fiscal em $PROJECT_DIR? [s/N] " answer
    [[ "$answer" =~ ^[SsYy]$ ]] || die "Cancelado."
}

main() {
    parse_args "$@"
    require_root
    validate_inputs
    confirm_or_yes

    run_deps_installer
    ensure_laravel_env
    write_systemd_env
    migrate_playwright_cache
    seed_portais
    refresh_caches
    restart_services
    print_status
    success "Habilitação da Automação Fiscal concluída."
}

main "$@"
