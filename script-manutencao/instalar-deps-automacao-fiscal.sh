#!/usr/bin/env bash
# Instala dependências de SO e do runner Node/Playwright para Automação Fiscal
# em produção nativa (Ubuntu/Debian). Idempotente — seguro reexecutar.
#
# Uso (na raiz do projeto ou via atalho):
#   sudo ./instalar-deps-automacao-fiscal.sh
#   sudo ./script-manutencao/instalar-deps-automacao-fiscal.sh --yes
#   sudo ./script-manutencao/instalar-deps-automacao-fiscal.sh --dry-run --yes
#
# Não substitui ./atualizar-producao.sh (composer/npm da app Laravel).
# Este script é o instalador separado de pacotes do runner (Chromium, Node 24+, etc.).
set -Eeuo pipefail

readonly SCRIPT_NAME="$(basename "$0")"
# Resolver caminho real (atalho na raiz / symlink) para não apontar PROJECT_DIR à pasta pai.
SCRIPT_PATH="$(readlink -f "${BASH_SOURCE[0]}" 2>/dev/null || realpath "${BASH_SOURCE[0]}")"
SCRIPT_DIR="$(cd "$(dirname "$SCRIPT_PATH")" && pwd)"
if [[ -f "$SCRIPT_DIR/../artisan" ]]; then
    PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
elif [[ -f "$SCRIPT_DIR/artisan" ]]; then
    PROJECT_DIR="$SCRIPT_DIR"
else
    PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
fi
APP_USER="${SUDO_USER:-}"
APP_GROUP="www-data"
NODE_MAJOR_MIN=24
PLAYWRIGHT_BROWSERS_PATH_DEFAULT="/var/cache/integrar-playwright"
PLAYWRIGHT_BROWSERS_PATH="$PLAYWRIGHT_BROWSERS_PATH_DEFAULT"
YES=false
DRY_RUN=false
SKIP_APT=false
SKIP_NODE=false
SKIP_RUNNER=false
SKIP_SYSTEMD=false
SKIP_DIRS=false
INSTALL_QUEUE_UNIT=true

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

Instala requisitos da Automação Fiscal em produção nativa:
  - Node.js ${NODE_MAJOR_MIN}+ (NodeSource)
  - OpenSSL (PKCS#12 / -legacy)
  - dependências de SO do Chromium/Playwright
  - npm ci + Playwright Chromium no runner
  - diretórios de storage/cache com permissões
  - unit systemd opcional integrar-queue-automacoes

Opções:
  --yes                      Confirma sem perguntas
  --dry-run                  Mostra ações sem alterar o sistema
  --project-dir CAMINHO      Raiz do Laravel (padrão: pai deste script)
  --app-user USUÁRIO         Dono do projeto / quem roda npm (padrão: \$SUDO_USER)
  --browsers-path CAMINHO    PLAYWRIGHT_BROWSERS_PATH (padrão: $PLAYWRIGHT_BROWSERS_PATH_DEFAULT)
  --skip-apt                 Não instala pacotes apt
  --skip-node                Não instala/atualiza Node.js
  --skip-runner              Não executa npm ci / playwright install
  --skip-systemd             Não cria/atualiza unit da fila de automações
  --skip-dirs                Não cria diretórios de storage/cache
  --no-queue-unit            Alias de --skip-systemd
  -h, --help                 Esta ajuda

Exemplos:
  sudo ./instalar-deps-automacao-fiscal.sh --yes
  sudo ./script-manutencao/instalar-deps-automacao-fiscal.sh --dry-run --yes
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

run_shell() {
    if $DRY_RUN; then
        printf '[dry-run] %s\n' "$1"
    else
        bash -o pipefail -c "$1"
    fi
}

run_as_app_user() {
    if $DRY_RUN; then
        printf '[dry-run] sudo -u %q env' "$APP_USER"
        printf ' %q' "$@"
        printf '\n'
        return 0
    fi
    # shellcheck disable=SC2086
    sudo -u "$APP_USER" env "$@"
}

require_root() {
    if [[ ${EUID:-$(id -u)} -ne 0 ]] && ! $DRY_RUN; then
        die "Execute como root: sudo ./$SCRIPT_NAME"
    fi
}

check_platform() {
    [[ -r /etc/os-release ]] || die "Sistema sem /etc/os-release."
    # shellcheck disable=SC1091
    source /etc/os-release
    case "${ID:-}" in
        debian|ubuntu) ;;
        *) die "Distribuição não suportada: ${ID:-desconhecida}. Use Debian ou Ubuntu." ;;
    esac
    success "Plataforma: ${PRETTY_NAME:-$ID}"
}

validate_inputs() {
    [[ "$PROJECT_DIR" = /* ]] || die "--project-dir deve ser absoluto."
    [[ -f "$PROJECT_DIR/artisan" ]] || die "Laravel não encontrado em $PROJECT_DIR"
    [[ -f "$PROJECT_DIR/scripts/automacao-fiscal/runner/package.json" ]] ||
        die "Runner não encontrado em $PROJECT_DIR/scripts/automacao-fiscal/runner"

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
    info "Usuário deploy (npm): $APP_USER"
    info "Grupo runtime: $APP_GROUP"
    info "Browsers Playwright: $PLAYWRIGHT_BROWSERS_PATH"
}

node_major() {
    if ! command -v node >/dev/null 2>&1; then
        echo 0
        return
    fi
    node -p 'Number(process.versions.node.split(".")[0])' 2>/dev/null || echo 0
}

# Ubuntu 24.04 (noble): vários pacotes viraram *t64 ou virtuais sem candidato
# (ex.: libasound2). apt-cache show ainda retorna 0 para virtual — usar policy.
apt_pkg_has_candidate() {
    local pkg="$1"
    local candidate
    candidate="$(apt-cache policy "$pkg" 2>/dev/null | awk -F': ' '/^\s*Candidato:|^\s*Candidate:/{print $2; exit}')"
    [[ -n "$candidate" && "$candidate" != "(nenhum)" && "$candidate" != "(none)" ]]
}

resolve_apt_pkg() {
    local pkg="$1"
    if apt_pkg_has_candidate "$pkg"; then
        printf '%s\n' "$pkg"
        return 0
    fi
    if apt_pkg_has_candidate "${pkg}t64"; then
        printf '%s\n' "${pkg}t64"
        return 0
    fi
    return 1
}

install_apt_base() {
    $SKIP_APT && { warn "Pulando apt (--skip-apt)"; return 0; }

    info "Atualizando índices apt e pacotes base..."
    run apt-get update

    local base_pkgs=(
        ca-certificates curl gnupg unzip git openssl
        libnss3 libnspr4 libdrm2 libdbus-1-3 libxkbcommon0
        libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libgbm1
        libpango-1.0-0 libcairo2 libx11-6 libx11-xcb1 libxcb1
        libxext6 libxshmfence1 fonts-liberation fonts-noto-color-emoji
    )
    # Pacotes que no noble viraram *t64 (ou virtuais sem candidato).
    local maybe_t64=(
        libatk1.0-0 libatk-bridge2.0-0 libcups2 libasound2 libatspi2.0-0
    )
    local resolved=()
    local pkg resolved_pkg
    for pkg in "${base_pkgs[@]}"; do
        resolved+=("$pkg")
    done
    for pkg in "${maybe_t64[@]}"; do
        if resolved_pkg="$(resolve_apt_pkg "$pkg")"; then
            resolved+=("$resolved_pkg")
        else
            warn "Pacote apt não encontrado: $pkg (nem ${pkg}t64)"
        fi
    done

    run env DEBIAN_FRONTEND=noninteractive apt-get install -y "${resolved[@]}"

    if $DRY_RUN; then
        success "Pacotes apt base (dry-run)"
        return 0
    fi

    if command -v openssl >/dev/null 2>&1; then
        success "OpenSSL: $(openssl version | head -1)"
    else
        die "OpenSSL não disponível após instalação."
    fi
}

install_nodejs() {
    $SKIP_NODE && { warn "Pulando Node.js (--skip-node)"; return 0; }

    local major
    major="$(node_major)"
    if [[ "$major" -ge "$NODE_MAJOR_MIN" ]]; then
        success "Node.js já adequado: $(node -v) (npm $(npm -v 2>/dev/null || echo '?'))"
        return 0
    fi

    info "Instalando Node.js ${NODE_MAJOR_MIN}.x (NodeSource)..."
    run_shell "curl -fsSL https://deb.nodesource.com/setup_${NODE_MAJOR_MIN}.x | bash -"
    run env DEBIAN_FRONTEND=noninteractive apt-get install -y nodejs

    if $DRY_RUN; then
        success "Node.js ${NODE_MAJOR_MIN}.x (dry-run — instalação não aplicada)"
        return 0
    fi

    major="$(node_major)"
    [[ "$major" -ge "$NODE_MAJOR_MIN" ]] || die "Node.js ${NODE_MAJOR_MIN}+ não disponível após instalação (encontrado major=$major)."
    success "Node.js instalado: $(node -v)"
}

prepare_directories() {
    $SKIP_DIRS && { warn "Pulando diretórios (--skip-dirs)"; return 0; }

    info "Criando diretórios de cache e storage da automação..."
    run mkdir -p "$PLAYWRIGHT_BROWSERS_PATH"
    run mkdir -p "$PROJECT_DIR/storage/app/automacao-fiscal-runner"
    run mkdir -p "$PROJECT_DIR/storage/app"

    run chown -R "$APP_USER:$APP_GROUP" "$PLAYWRIGHT_BROWSERS_PATH"
    run chmod -R ug+rwX "$PLAYWRIGHT_BROWSERS_PATH"
    run find "$PLAYWRIGHT_BROWSERS_PATH" -type d -exec chmod g+s {} +

    run chown -R "$APP_USER:$APP_GROUP" "$PROJECT_DIR/storage/app/automacao-fiscal-runner"
    run chmod -R ug+rwX "$PROJECT_DIR/storage/app/automacao-fiscal-runner"

    # Certificados e artefatos ficam sob storage/app/{operadora_id}/automacao-fiscal/
    # Garantir que o grupo www-data consiga ler/escrever storage da app.
    if [[ -d "$PROJECT_DIR/storage" ]]; then
        run chown -R "$APP_USER:$APP_GROUP" "$PROJECT_DIR/storage"
        run chmod -R ug+rwX "$PROJECT_DIR/storage"
        run find "$PROJECT_DIR/storage" -type d -exec chmod g+s {} +
    fi

    success "Diretórios preparados"
}

install_runner() {
    $SKIP_RUNNER && { warn "Pulando runner npm/playwright (--skip-runner)"; return 0; }

    local runner_dir="$PROJECT_DIR/scripts/automacao-fiscal/runner"
    info "Instalando dependências npm do runner em $runner_dir ..."

    run chown -R "$APP_USER:$APP_GROUP" "$runner_dir"

    run_as_app_user \
        PLAYWRIGHT_BROWSERS_PATH="$PLAYWRIGHT_BROWSERS_PATH" \
        npm --prefix "$runner_dir" ci

    info "Instalando Chromium do Playwright (browsers path: $PLAYWRIGHT_BROWSERS_PATH)..."
    # install-deps precisa de root; browsers como usuário deploy
    if ! $SKIP_APT; then
        if $DRY_RUN; then
            printf '[dry-run] npx playwright install-deps chromium (como root)\n'
        else
            (
                cd "$runner_dir"
                # shellcheck disable=SC2030
                export PLAYWRIGHT_BROWSERS_PATH
                npx --prefix "$runner_dir" playwright install-deps chromium
            )
        fi
    fi

    run_as_app_user \
        PLAYWRIGHT_BROWSERS_PATH="$PLAYWRIGHT_BROWSERS_PATH" \
        npm --prefix "$runner_dir" exec -- playwright install chromium

    info "Compilando TypeScript do runner (npm run build)..."
    run_as_app_user \
        PLAYWRIGHT_BROWSERS_PATH="$PLAYWRIGHT_BROWSERS_PATH" \
        npm --prefix "$runner_dir" run build

    run chown -R "$APP_USER:$APP_GROUP" "$runner_dir" "$PLAYWRIGHT_BROWSERS_PATH"
    run chmod -R ug+rwX "$PLAYWRIGHT_BROWSERS_PATH"

    success "Runner instalado e compilado"
}

write_systemd_env() {
    local env_file="/etc/integrar/automacao-fiscal.env"
    run mkdir -p /etc/integrar
    if $DRY_RUN; then
        printf '[dry-run] escrever %s\n' "$env_file"
        return 0
    fi
    cat >"$env_file" <<EOF
# Gerado por $SCRIPT_NAME — $(date -Iseconds)
PLAYWRIGHT_BROWSERS_PATH=$PLAYWRIGHT_BROWSERS_PATH
AUTOMACAO_FISCAL_FAKE_MODE=false
EOF
    chmod 0640 "$env_file"
    chown "root:$APP_GROUP" "$env_file"
    success "Environment systemd: $env_file"
}

install_systemd_unit() {
    $SKIP_SYSTEMD && { warn "Pulando systemd (--skip-systemd)"; return 0; }
    $INSTALL_QUEUE_UNIT || return 0

    local unit_path="/etc/systemd/system/integrar-queue-automacoes.service"
    local php_bin
    php_bin="$(command -v php || echo /usr/bin/php)"

    info "Configurando $unit_path ..."
    write_systemd_env

    if $DRY_RUN; then
        printf '[dry-run] escrever unit %s\n' "$unit_path"
        return 0
    fi

    cat >"$unit_path" <<EOF
[Unit]
Description=IntegraExpert — fila de Automação Fiscal (timeout longo)
After=network.target mysql.service
Wants=integrar-queue.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$PROJECT_DIR
EnvironmentFile=-/etc/integrar/automacao-fiscal.env
Environment=PLAYWRIGHT_BROWSERS_PATH=$PLAYWRIGHT_BROWSERS_PATH
ExecStart=$php_bin $PROJECT_DIR/artisan queue:work database --queue=automacoes,documentos,default --sleep=3 --tries=3 --timeout=900 --max-time=3600
Restart=always
RestartSec=5
TimeoutStopSec=960
Nice=5

# Isolamento básico — sem acesso a rede desnecessária no unit; o Chromium precisa de rede.
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF

    chmod 0644 "$unit_path"
    run systemctl daemon-reload
    run systemctl enable integrar-queue-automacoes.service
    if systemctl is-active --quiet integrar-queue-automacoes 2>/dev/null; then
        run systemctl restart integrar-queue-automacoes
    else
        run systemctl start integrar-queue-automacoes || warn "Não foi possível iniciar integrar-queue-automacoes agora (verifique journalctl -u integrar-queue-automacoes)."
    fi

    success "Unit systemd integrar-queue-automacoes configurada"
}

run_diagnostics() {
    local failed=0
    check() {
        local desc="$1"; shift
        if $DRY_RUN; then
            info "Dry-run: pulando teste real — $desc"
            return 0
        fi
        if "$@" >/dev/null 2>&1; then
            success "Teste: $desc"
        else
            warn "Falhou: $desc"
            failed=1
        fi
    }

    info "Diagnóstico final..."
    check "Node.js >= $NODE_MAJOR_MIN" bash -c "[[ \$(node -p 'Number(process.versions.node.split(\".\")[0])') -ge $NODE_MAJOR_MIN ]]"
    check "npm disponível" command -v npm
    check "OpenSSL disponível" command -v openssl
    check "package-lock do runner" test -f "$PROJECT_DIR/scripts/automacao-fiscal/runner/package-lock.json"
    check "node_modules do runner" test -d "$PROJECT_DIR/scripts/automacao-fiscal/runner/node_modules"
    check "dist/cli.js do runner" test -f "$PROJECT_DIR/scripts/automacao-fiscal/runner/dist/cli.js"
    check "PLAYWRIGHT_BROWSERS_PATH existe" test -d "$PLAYWRIGHT_BROWSERS_PATH"

    if ! $DRY_RUN && ! $SKIP_SYSTEMD; then
        check "Unit integrar-queue-automacoes habilitada" systemctl is-enabled --quiet integrar-queue-automacoes
    fi

    if ! $DRY_RUN && [[ -f "$PROJECT_DIR/scripts/automacao-fiscal/runner/dist/cli.js" ]]; then
        # Smoke: CLI com mode fake (não acessa portal)
        local tmp_in
        tmp_in="$(mktemp)"
        cat >"$tmp_in" <<'JSON'
{"runId":"diag-smoke-001","portal":"ecac-rs","operation":"validate-access","mode":"fake","params":{}}
JSON
        if sudo -u "$APP_USER" env \
            PLAYWRIGHT_BROWSERS_PATH="$PLAYWRIGHT_BROWSERS_PATH" \
            RUNNER_INTERNAL_TOKEN="integrar-diag-token-16" \
            PLATFORM_BASE_URL="http://127.0.0.1" \
            AUTOMATION_FAKE_MODE=true \
            ECAC_RS_MODE=fake \
            ECAC_RS_ENTRY_URL="https://atendimento.receita.rs.gov.br/pessoa-juridica-portal-e-cac" \
            ECAC_RS_ALLOWED_HOST_SUFFIXES=rs.gov.br \
            NFSE_EMISSOR_MODE=fake \
            NFSE_EMISSOR_ENTRY_URL="https://www.nfse.gov.br/EmissorNacional/Login" \
            NFSE_EMISSOR_ALLOWED_HOST_SUFFIXES=nfse.gov.br \
            ECAC_A1_PFX_FILE=/tmp/missing.pfx \
            ECAC_A1_PASSWORD_FILE=/tmp/missing-password.txt \
            node "$PROJECT_DIR/scripts/automacao-fiscal/runner/dist/cli.js" --input "$tmp_in" >/tmp/integrar-runner-smoke.out 2>/tmp/integrar-runner-smoke.err; then
            success "Teste: CLI runner (fake mode)"
        else
            warn "Falhou: CLI runner (fake mode). Veja /tmp/integrar-runner-smoke.err"
            failed=1
        fi
        rm -f "$tmp_in"
    fi

    (( failed == 0 )) || die "Diagnóstico encontrou falhas. Corrija e reexecute o script."
    success "Diagnóstico OK"
}

print_next_steps() {
    cat <<EOF

Próximos passos:
  1. Confira .env de produção:
       AUTOMACAO_FISCAL_FAKE_MODE=false
       AUTOMACAO_FISCAL_RUNNER_TOKEN=<token >= 16 chars>
       ECAC_RS_CERT_ORIGINS=<origens HTTPS do certificado>
  2. Deploy de código (se ainda não rodou):
       ./atualizar-producao.sh
  3. Seed dos portais (idempotente):
       sudo -u www-data php artisan db:seed --class=PortaisIntegracaoSeeder --force
  4. Status da fila de automações:
       systemctl status integrar-queue-automacoes
       journalctl -u integrar-queue-automacoes -f
  5. Scheduler (já existente) deve despachar:
       php artisan automacoes:despachar

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
            --skip-apt) SKIP_APT=true; shift ;;
            --skip-node) SKIP_NODE=true; shift ;;
            --skip-runner) SKIP_RUNNER=true; shift ;;
            --skip-systemd|--no-queue-unit) SKIP_SYSTEMD=true; INSTALL_QUEUE_UNIT=false; shift ;;
            --skip-dirs) SKIP_DIRS=true; shift ;;
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
    read -r -p "Instalar dependências da Automação Fiscal em $PROJECT_DIR? [s/N] " answer
    [[ "$answer" =~ ^[SsYy]$ ]] || die "Cancelado."
}

main() {
    parse_args "$@"
    require_root
    check_platform
    validate_inputs
    confirm_or_yes

    install_apt_base
    install_nodejs
    prepare_directories
    install_runner
    install_systemd_unit
    run_diagnostics
    print_next_steps
    success "Instalação de dependências da Automação Fiscal concluída."
}

main "$@"
