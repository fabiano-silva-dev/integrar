#!/usr/bin/env bash
# Prepara produção nativa para o módulo Documentos (WhatsApp, Drive, classificação/IA).
# Idempotente — seguro reexecutar.
#
# Não substitui ./atualizar-producao.sh (composer, npm, migrate, cache).
#
# Uso:
#   sudo ./preparar-documentos-producao.sh --yes
#   sudo ./script-manutencao/preparar-documentos-producao.sh --dry-run --yes
set -Eeuo pipefail

readonly SCRIPT_NAME="$(basename "$0")"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
APP_USER="${SUDO_USER:-}"
APP_GROUP="www-data"
QUEUE_UNIT="integrar-queue-automacoes"
YES=false
DRY_RUN=false
SKIP_APT=false
SKIP_SYSTEMD=false
SKIP_ENV=false
SKIP_DIRS=false
SKIP_EVOLUTION=false
EVOLUTION_URL_OVERRIDE=""
EVOLUTION_PORT_DEFAULT=8080

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

Prepara o servidor nativo (Apache + PHP-FPM) para receber e arquivar documentos:

  - poppler-utils (pdftotext — PDF com texto, antes da IA)
  - extensões PHP usadas nas APIs (curl, xml, mbstring, zip)
  - Evolution API em Docker isolado (docker-compose.evolution.yml), porta só em 127.0.0.1
    Não sobe app, MySQL nem phpMyAdmin — a aplicação nativa (Apache/PHP/MySQL) não é tocada
  - chaves no .env (só se ainda não existirem; não grava secrets)
  - unit systemd ${QUEUE_UNIT} escutando a fila documentos (timeout 900s)
  - pastas de storage do módulo

Não roda composer, npm nem migrate. Depois deste script:

  ./atualizar-producao.sh

Opções:
  --yes                      Confirma sem perguntas
  --dry-run                  Mostra ações sem alterar o sistema
  --project-dir CAMINHO      Raiz do Laravel (padrão: pai deste script)
  --app-user USUÁRIO         Dono do projeto (padrão: \$SUDO_USER ou fabiano)
  --evolution-url URL        EVOLUTION_URL_BASE se a chave ainda não existir
                             (padrão: http://127.0.0.1:${EVOLUTION_PORT_DEFAULT})
  --skip-apt                 Não instala pacotes apt
  --skip-systemd             Não cria/atualiza a unit da fila
  --skip-env                 Não altera o .env
  --skip-dirs                Não ajusta pastas de storage
  --skip-evolution           Não instala/sobe o Docker da Evolution
  -h, --help                 Esta ajuda

Exemplos:
  sudo ./preparar-documentos-producao.sh --yes
  sudo ./script-manutencao/preparar-documentos-producao.sh --dry-run --yes
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
    info "Grupo runtime: $APP_GROUP"
}

env_file() {
    printf '%s/.env' "$PROJECT_DIR"
}

env_get() {
    local key="$1" file
    file="$(env_file)"
    [[ -f "$file" ]] || return 0
    grep -m1 "^${key}=" "$file" 2>/dev/null | cut -d= -f2- | tr -d "\"'[:space:]" || true
}

env_has_key() {
    local key="$1" file
    file="$(env_file)"
    [[ -f "$file" ]] && grep -q "^${key}=" "$file"
}

env_ensure() {
    local key="$1" value="$2" file
    file="$(env_file)"

    if $SKIP_ENV; then
        return 0
    fi

    if [[ ! -f "$file" ]]; then
        warn ".env não encontrado em $PROJECT_DIR — não foi possível gravar $key"
        return 0
    fi

    if env_has_key "$key"; then
        return 0
    fi

    info "Incluindo $key no .env (ainda não existia)"
    if $DRY_RUN; then
        printf '[dry-run] append %s=… em %s\n' "$key" "$file"
        return 0
    fi
    printf '\n%s=%s\n' "$key" "$value" >>"$file"
}

looks_like_docker_host() {
    local value="$1"
    [[ "$value" == *evolution-api* || "$value" == *app:8000* || "$value" == *localhost:8081* ]]
}

php_minor() {
    php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "8.2"
}

php_has_ext() {
    php -m 2>/dev/null | grep -qi "^$1$"
}

install_apt() {
    $SKIP_APT && { warn "Pulando apt (--skip-apt)"; return 0; }

    local ver pkgs
    ver="$(php_minor)"
    pkgs=(poppler-utils ca-certificates curl)
    for ext in curl xml mbstring zip; do
        if ! php_has_ext "$ext"; then
            pkgs+=("php${ver}-${ext}")
        fi
    done

    info "Instalando pacotes: ${pkgs[*]}"
    run apt-get update
    run env DEBIAN_FRONTEND=noninteractive apt-get install -y "${pkgs[@]}"

    if $DRY_RUN; then
        success "Pacotes apt (dry-run)"
        return 0
    fi

    command -v pdftotext >/dev/null 2>&1 || die "pdftotext não disponível após instalar poppler-utils."
    success "pdftotext: $(pdftotext -v 2>&1 | head -1)"
}

prepare_dirs() {
    $SKIP_DIRS && { warn "Pulando diretórios (--skip-dirs)"; return 0; }

    info "Ajustando storage do módulo Documentos..."
    run mkdir -p "$PROJECT_DIR/storage/app" "$PROJECT_DIR/storage/logs" "$PROJECT_DIR/bootstrap/cache"
    run chown -R "$APP_USER:$APP_GROUP" "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
    run chmod -R ug+rwX "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
    if ! $DRY_RUN; then
        find "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache" -type d -exec chmod g+s {} +
    else
        printf '[dry-run] find storage bootstrap/cache -type d -exec chmod g+s\n'
    fi
    success "Storage gravável por $APP_USER:$APP_GROUP"
}

prepare_env() {
    $SKIP_ENV && { warn "Pulando .env (--skip-env)"; return 0; }

    local file app_url evo_url webhook
    file="$(env_file)"
    [[ -f "$file" ]] || die "Arquivo .env não encontrado. Copie .env.example e configure o servidor antes."

    app_url="$(env_get APP_URL)"
    [[ -n "$app_url" ]] || app_url="https://localhost"

    evo_url="${EVOLUTION_URL_OVERRIDE:-http://127.0.0.1:${EVOLUTION_PORT_DEFAULT}}"
    webhook="${app_url%/}/webhooks/evolution"

    env_ensure DOCUMENTOS_QUEUE documentos
    env_ensure DOCUMENTOS_MAX_ANEXO_MB 80
    env_ensure EVOLUTION_PORT "$EVOLUTION_PORT_DEFAULT"
    env_ensure EVOLUTION_URL_BASE "$evo_url"
    env_ensure EVOLUTION_API_KEY ""
    env_ensure EVOLUTION_WEBHOOK_URL "$webhook"
    env_ensure GOOGLE_REDIRECT_URI "${app_url%/}/oauth/google/callback"
    env_ensure GEMINI_API_KEY ""
    env_ensure GROQ_API_KEY ""
    env_ensure LLAMA_CLOUD_API_KEY ""

    if ! $DRY_RUN; then
        local atual
        atual="$(env_get EVOLUTION_URL_BASE)"
        if looks_like_docker_host "$atual"; then
            warn "EVOLUTION_URL_BASE=$atual parece hostname de Docker. Em produção nativa use http://127.0.0.1:${EVOLUTION_PORT_DEFAULT}"
        fi
        atual="$(env_get EVOLUTION_WEBHOOK_URL)"
        if looks_like_docker_host "$atual"; then
            warn "EVOLUTION_WEBHOOK_URL=$atual não é a URL pública. Ajuste para ${app_url%/}/webhooks/evolution"
        fi
        atual="$(env_get APP_URL)"
        if [[ "$atual" == http://localhost* || "$atual" == http://127.0.0.1* ]]; then
            warn "APP_URL=$atual — OAuth Google e webhook da Evolution precisam do HTTPS público do escritório."
        fi
    fi

    run chmod 640 "$file" || true
    success "Chaves do módulo conferidas no .env (valores existentes preservados)"
}

unit_execstart() {
    local unit_path="/etc/systemd/system/${QUEUE_UNIT}.service"
    [[ -f "$unit_path" ]] || return 1
    grep -E '^ExecStart=' "$unit_path" | head -1
}

write_queue_unit() {
    local unit_path="/etc/systemd/system/${QUEUE_UNIT}.service"
    local php_bin queue_user queue_group existing="/etc/systemd/system/integrar-queue.service"
    php_bin="$(command -v php || echo /usr/bin/php)"
    queue_user="$APP_USER"
    queue_group="$APP_GROUP"
    if [[ -f "$existing" ]]; then
        queue_user="$(grep -m1 '^User=' "$existing" | cut -d= -f2- || true)"
        queue_group="$(grep -m1 '^Group=' "$existing" | cut -d= -f2- || true)"
        [[ -n "$queue_user" ]] || queue_user="$APP_USER"
        [[ -n "$queue_group" ]] || queue_group="$queue_user"
        info "Nova unit $QUEUE_UNIT usará o mesmo usuário do worker atual ($queue_user)"
    fi

    if $DRY_RUN; then
        printf '[dry-run] escrever %s (fila automacoes,documentos,default timeout 900 user=%s)\n' "$unit_path" "$queue_user"
        return 0
    fi

    cat >"$unit_path" <<EOF
[Unit]
Description=IntegraExpert — filas automacoes e documentos (timeout longo)
After=network.target mysql.service

[Service]
Type=simple
User=$queue_user
Group=$queue_group
WorkingDirectory=$PROJECT_DIR
EnvironmentFile=-/etc/integrar/automacao-fiscal.env
ExecStart=$php_bin $PROJECT_DIR/artisan queue:work database --queue=automacoes,documentos,default --sleep=3 --tries=3 --timeout=900 --max-time=3600
Restart=always
RestartSec=5
TimeoutStopSec=960
Nice=5
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF
    chmod 0644 "$unit_path"
}

patch_queue_unit() {
    local unit_path="/etc/systemd/system/${QUEUE_UNIT}.service"
    local line

    line="$(unit_execstart || true)"
    [[ -n "$line" ]] || return 1

    if grep -q -- '--queue=' <<<"$line" && grep -q 'documentos' <<<"$line"; then
        success "Unit $QUEUE_UNIT já escuta a fila documentos"
        return 0
    fi

    info "Incluindo fila documentos em $QUEUE_UNIT"
    if $DRY_RUN; then
        printf '[dry-run] ajustar ExecStart de %s para incluir documentos\n' "$unit_path"
        return 0
    fi

    if grep -q -- '--queue=automacoes,default' "$unit_path"; then
        sed -i 's/--queue=automacoes,default/--queue=automacoes,documentos,default/' "$unit_path"
    elif grep -q -- '--queue=default' "$unit_path"; then
        sed -i 's/--queue=default/--queue=documentos,default/' "$unit_path"
    elif grep -q -- '--queue=automacoes' "$unit_path"; then
        sed -i 's/--queue=automacoes/--queue=automacoes,documentos/' "$unit_path"
    else
        warn "ExecStart de $QUEUE_UNIT não foi reconhecido. Inclua --queue=automacoes,documentos,default --timeout=900 na unit."
    fi
}

prepare_systemd() {
    $SKIP_SYSTEMD && { warn "Pulando systemd (--skip-systemd)"; return 0; }

    local unit_path="/etc/systemd/system/${QUEUE_UNIT}.service"

    info "Configurando worker extra da fila documentos ($QUEUE_UNIT)..."
    info "Não altera integrar-queue.service nem integrar-scheduler.service."
    run mkdir -p /etc/integrar

    if [[ -f "$unit_path" ]]; then
        patch_queue_unit
    else
        info "Criando $unit_path"
        write_queue_unit
    fi

    if $DRY_RUN; then
        success "systemd (dry-run)"
        return 0
    fi

    systemctl daemon-reload
    systemctl enable "${QUEUE_UNIT}.service"
    if systemctl is-active --quiet "$QUEUE_UNIT" 2>/dev/null; then
        systemctl restart "$QUEUE_UNIT"
    else
        systemctl start "$QUEUE_UNIT" || warn "Não foi possível iniciar $QUEUE_UNIT agora (journalctl -u $QUEUE_UNIT)."
    fi
    success "Worker $QUEUE_UNIT ativo com fila documentos"
}

COMPOSE_CMD=()
EVOLUTION_COMPOSE_PROJECT="integrar-evolution"

evolution_compose_file() {
    printf '%s/docker-compose.evolution.yml' "$PROJECT_DIR"
}

compose_disponivel() {
    local out
    out="$(docker compose version 2>/dev/null || true)"
    if grep -qi compose <<<"$out"; then
        COMPOSE_CMD=(docker compose)
        return 0
    fi
    if command -v docker-compose >/dev/null 2>&1; then
        COMPOSE_CMD=(docker-compose)
        return 0
    fi
    COMPOSE_CMD=()
    return 1
}

ensure_docker() {
    if command -v docker >/dev/null 2>&1; then
        success "Docker já instalado (o motor não será reinstalado nem atualizado)"
        return 0
    fi

    info "Instalando Docker (Evolution roda em container, como no EfiConnect)..."
    if $DRY_RUN; then
        printf '[dry-run] curl -fsSL https://get.docker.com | sh\n'
        return 0
    fi
    run_shell "curl -fsSL https://get.docker.com | sh"
    run usermod -aG docker "$APP_USER" || true
    command -v docker >/dev/null 2>&1 || die "Docker não disponível após a instalação."
}

ensure_compose() {
    if compose_disponivel; then
        success "Compose: ${COMPOSE_CMD[*]} (sem --profile, arquivo isolado da Evolution)"
        return 0
    fi

    info "Instalando só o pacote Compose — não mexo no motor Docker nem em containers existentes."
    if $DRY_RUN; then
        COMPOSE_CMD=(docker-compose)
        printf '[dry-run] apt-get install -y docker-compose\n'
        return 0
    fi

    apt-get install -y docker-compose || true
    compose_disponivel || die "Compose ausente. Instale sem atualizar o Docker: sudo apt-get install docker-compose"
    success "Compose: ${COMPOSE_CMD[*]}"
}

assert_evolution_compose_seguro() {
    local file
    file="$(evolution_compose_file)"
    [[ -f "$file" ]] || die "Falta $file — recusado subir o docker-compose.yml da aplicação."

    grep -q 'integrar-evolution-api' "$file" || die "$file não define integrar-evolution-api"

    if grep -qE 'container_name:[[:space:]]*(integrar-app|integrar-db|integrar-phpmyadmin)' "$file"; then
        die "$file mistura a stack da aplicação. Abortado para não afetar a produção nativa."
    fi
    if grep -qE 'image:[[:space:]]*mysql:' "$file"; then
        die "$file contém MySQL. Abortado."
    fi
    if grep -qiE 'image:[[:space:]].*phpmyadmin|container_name:[[:space:]]*integrar-phpmyadmin' "$file"; then
        die "$file contém phpMyAdmin. Abortado."
    fi
    if grep -qE '^[[:space:]]+profiles:' "$file"; then
        die "$file usa profiles (Compose antigo falha com --profile). Use o arquivo isolado."
    fi
}

avisar_containers_intocados() {
    local nomes
    nomes="$(docker ps -a --format '{{.Names}}' 2>/dev/null | grep -E '^(integrar-app|integrar-db|integrar-phpmyadmin)$' || true)"
    if [[ -n "$nomes" ]]; then
        warn "Containers da stack Laravel/MySQL existem e NÃO serão alterados: ${nomes//$'\n'/ }"
    fi
}

porta_evolution_ok() {
    local port="$1"
    local ocupada=1

    if command -v ss >/dev/null 2>&1; then
        ss -ltn 2>/dev/null | grep -qE ":${port}[[:space:]]" && ocupada=0
    elif command -v netstat >/dev/null 2>&1; then
        netstat -ltn 2>/dev/null | grep -qE ":${port}[[:space:]]" && ocupada=0
    else
        return 0
    fi

    if (( ocupada == 1 )); then
        return 0
    fi

    if docker ps --format '{{.Names}} {{.Ports}}' 2>/dev/null | grep -q "integrar-evolution-api"; then
        success "Porta ${port} já é da Evolution — só confirmo o container"
        return 0
    fi

    die "Porta ${port} já está em uso. Não vou ocupá-la. Ajuste EVOLUTION_PORT no .env para uma porta livre só em 127.0.0.1."
}

run_evolution_compose() {
    [[ ${#COMPOSE_CMD[@]} -gt 0 ]] || die "Compose não detectado"
    (
        cd "$PROJECT_DIR"
        env -u COMPOSE_FILE -u COMPOSE_PROFILES -u COMPOSE_PROJECT_NAME \
            EVOLUTION_PORT="${EVOLUTION_PORT_RUNTIME:?}" \
            "${COMPOSE_CMD[@]}" \
            -p "$EVOLUTION_COMPOSE_PROJECT" \
            -f docker-compose.evolution.yml \
            "$@"
    )
}

ensure_evolution_env() {
    local evo_env="$PROJECT_DIR/docker/evolution.env"
    local example="$PROJECT_DIR/docker/evolution.env.example"
    local port chave

    port="$(env_get EVOLUTION_PORT)"
    [[ -n "$port" ]] || port="$EVOLUTION_PORT_DEFAULT"

    if [[ ! -f "$evo_env" ]]; then
        if [[ -f "$example" ]]; then
            info "Criando docker/evolution.env a partir do example"
            run cp "$example" "$evo_env"
        else
            die "Falta $example"
        fi
        if ! $DRY_RUN; then
            chave="$(head -c 24 /dev/urandom | base64 | tr -d '/+=\n' | head -c 32)"
            sed -i "s/^AUTHENTICATION_API_KEY=.*/AUTHENTICATION_API_KEY=${chave}/" "$evo_env"
        fi
    fi

    if $DRY_RUN; then
        printf '[dry-run] SERVER_URL=http://127.0.0.1:%s em docker/evolution.env\n' "$port"
        return 0
    fi

    if grep -q '^SERVER_URL=' "$evo_env"; then
        sed -i "s|^SERVER_URL=.*|SERVER_URL=http://127.0.0.1:${port}|" "$evo_env"
    else
        printf '\nSERVER_URL=http://127.0.0.1:%s\n' "$port" >>"$evo_env"
    fi
    chmod 640 "$evo_env" || true
    chown "$APP_USER:$APP_GROUP" "$evo_env" || true
}

sync_evolution_apikey() {
    local evo_env="$PROJECT_DIR/docker/evolution.env" chave atual
    [[ -f "$evo_env" ]] || return 0
    $SKIP_ENV && return 0

    chave="$(grep -m1 '^AUTHENTICATION_API_KEY=' "$evo_env" | cut -d= -f2- | tr -d "\"'[:space:]")"
    [[ -n "$chave" ]] || return 0

    atual="$(env_get EVOLUTION_API_KEY)"
    if [[ -z "$atual" ]]; then
        info "Copiando AUTHENTICATION_API_KEY para EVOLUTION_API_KEY no .env"
        if $DRY_RUN; then
            printf '[dry-run] EVOLUTION_API_KEY=…\n'
            return 0
        fi
        if env_has_key EVOLUTION_API_KEY; then
            sed -i "s|^EVOLUTION_API_KEY=.*|EVOLUTION_API_KEY=${chave}|" "$(env_file)"
        else
            printf '\nEVOLUTION_API_KEY=%s\n' "$chave" >>"$(env_file)"
        fi
    fi
}

install_evolution() {
    $SKIP_EVOLUTION && { warn "Pulando Evolution (--skip-evolution)"; return 0; }

    local port
    port="$(env_get EVOLUTION_PORT)"
    [[ -n "$port" ]] || port="$EVOLUTION_PORT_DEFAULT"
    EVOLUTION_PORT_RUNTIME="$port"
    export EVOLUTION_PORT_RUNTIME

    info "Subindo SÓ a Evolution (docker-compose.evolution.yml). Apache, MySQL nativo, PHP-FPM e workers atuais não são tocados."
    ensure_docker
    ensure_compose
    assert_evolution_compose_seguro
    ensure_evolution_env
    avisar_containers_intocados
    porta_evolution_ok "$port"

    if $DRY_RUN; then
        printf '[dry-run] %s -p %s -f docker-compose.evolution.yml up -d (porta 127.0.0.1:%s)\n' \
            "${COMPOSE_CMD[*]}" "$EVOLUTION_COMPOSE_PROJECT" "$port"
        success "Evolution (dry-run)"
        return 0
    fi

    if ! run_evolution_compose up -d; then
        die "Falha ao subir a Evolution isolada. A stack nativa não foi alterada. Confira: ${COMPOSE_CMD[*]} -p ${EVOLUTION_COMPOSE_PROJECT} -f docker-compose.evolution.yml ps"
    fi

    sync_evolution_apikey
    assert_evolution_apikey
    success "Evolution no ar em http://127.0.0.1:${port} (não exponha essa porta no UFW)"
}

assert_evolution_apikey() {
    $SKIP_ENV && return 0
    $DRY_RUN && return 0

    local chave
    chave="$(env_get EVOLUTION_API_KEY)"
    if [[ -z "$chave" ]]; then
        die "EVOLUTION_API_KEY está vazia. Configure AUTHENTICATION_API_KEY em docker/evolution.env e reexecute."
    fi
}

probe_https() {
    local url="$1" label="$2" code
    if $DRY_RUN; then
        info "Dry-run: pulando alcance $label"
        return 0
    fi
    code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 8 "$url" || true)"
    if [[ -n "$code" && "$code" != "000" ]]; then
        success "Alcance: $label"
        return 0
    fi
    warn "Sem resposta de $label ($url). Confira firewall/DNS de saída do PHP-FPM."
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

    info "Diagnóstico..."
    check "pdftotext disponível" command -v pdftotext
    check "PHP curl" php -r 'exit(extension_loaded("curl") ? 0 : 1);'
    check "artisan existe" test -f "$PROJECT_DIR/artisan"
    check ".env existe" test -f "$(env_file)"

    if ! $SKIP_ENV && ! $DRY_RUN; then
        check "DOCUMENTOS_QUEUE=documentos" bash -c "[[ $(printf %q "$(env_get DOCUMENTOS_QUEUE)") == documentos ]]"
    fi

    if ! $DRY_RUN && ! $SKIP_SYSTEMD; then
        check "Unit $QUEUE_UNIT habilitada" systemctl is-enabled --quiet "$QUEUE_UNIT"
        check "Unit $QUEUE_UNIT menciona documentos" bash -c "systemctl cat ${QUEUE_UNIT}.service | grep -q documentos"
    fi

    if ! $DRY_RUN && ! $SKIP_EVOLUTION; then
        check "Container integrar-evolution-api" docker ps --format '{{.Names}}' | grep -qx integrar-evolution-api
        check "Evolution responde em 127.0.0.1" bash -c "curl -sS -o /dev/null -w '%{http_code}' --max-time 5 http://127.0.0.1:${EVOLUTION_PORT_DEFAULT} | grep -vq '^000$'"
        if ! $SKIP_ENV; then
            check "EVOLUTION_API_KEY preenchida" bash -c "[[ -n $(printf %q "$(env_get EVOLUTION_API_KEY)") ]]"
        fi
    fi
    probe_https "https://generativelanguage.googleapis.com" "Gemini"
    probe_https "https://api.groq.com" "Groq"
    probe_https "https://api.cloud.llamaindex.ai" "LlamaParse"
    probe_https "https://www.googleapis.com" "Google APIs"

    (( failed == 0 )) || die "Diagnóstico encontrou falhas. Corrija e reexecute o script."
    success "Diagnóstico OK"
}

print_next_steps() {
    local app_url webhook port
    app_url="$(env_get APP_URL)"
    webhook="${app_url%/}/webhooks/evolution"
    port="$(env_get EVOLUTION_PORT)"
    [[ -n "$port" ]] || port="$EVOLUTION_PORT_DEFAULT"

    cat <<EOF

Próximos passos:
  1. Código e banco (se ainda não rodou neste pull):
       cd $PROJECT_DIR
       ./atualizar-producao.sh
  2. Evolution (Docker no localhost, PHP nativo — igual ao EfiConnect):
       EVOLUTION_URL_BASE=http://127.0.0.1:${port}
       EVOLUTION_API_KEY=<mesmo AUTHENTICATION_API_KEY de docker/evolution.env>
       EVOLUTION_WEBHOOK_URL=${webhook:-https://SEU_DOMINIO/webhooks/evolution}
       docker-compose -p integrar-evolution -f docker-compose.evolution.yml ps
  3. No Google Cloud, cadastre o redirect:
       ${app_url%/}/oauth/google/callback
  4. Status da fila:
       systemctl status $QUEUE_UNIT
       journalctl -u $QUEUE_UNIT -f
  5. Na tela do sistema (como admin):
       Documentos → WhatsApp  (conectar)
       Documentos → Grupos    (monitorar + empresa)
       Documentos → Google Drive
       Documentos → IA        (Testar em cada card e Salvar)
  6. Chaves Gemini / Groq / LlamaParse podem ficar só na tela.
     Groq e LlamaParse podem ficar em branco.

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
            --evolution-url) EVOLUTION_URL_OVERRIDE="$2"; shift 2 ;;
            --skip-apt) SKIP_APT=true; shift ;;
            --skip-systemd) SKIP_SYSTEMD=true; shift ;;
            --skip-env) SKIP_ENV=true; shift ;;
            --skip-dirs) SKIP_DIRS=true; shift ;;
            --skip-evolution) SKIP_EVOLUTION=true; shift ;;
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
    read -r -p "Preparar produção do módulo Documentos em $PROJECT_DIR? [s/N] " answer
    [[ "$answer" =~ ^[SsYy]$ ]] || die "Cancelado."
}

main() {
    parse_args "$@"
    require_root
    check_platform
    validate_inputs
    confirm_or_yes

    install_apt
    prepare_dirs
    prepare_env
    install_evolution
    prepare_systemd
    run_diagnostics
    print_next_steps
    success "Ambiente de produção do módulo Documentos preparado."
}

main "$@"
