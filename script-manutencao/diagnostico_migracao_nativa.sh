#!/usr/bin/env bash
#
# Diagnóstico read-only do servidor antes da migração Docker → nativo.
# Gera relatório em Markdown para análise e melhoria do instalador.
#
# Uso (no servidor de produção):
#   bash script-manutencao/diagnostico_migracao_nativa.sh
#   sudo bash script-manutencao/diagnostico_migracao_nativa.sh
#
# O relatório é salvo em storage/app/ (ou --output).

set -uo pipefail

readonly SCRIPT_NAME="$(basename "$0")"
readonly DEFAULT_DOMAIN="integraexpert.com.br"
readonly DEFAULT_DB_NAME="integrar"

readonly PROD_SOURCE_DIR="/home/fabiano/Projetos/integrar_dalongaro"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROJECT_DIR=""
DOMAIN="$DEFAULT_DOMAIN"
OUTPUT_FILE=""
PRINT_STDOUT=true

declare -i COUNT_OK=0 COUNT_WARN=0 COUNT_FAIL=0 COUNT_INFO=0

usage() {
    cat <<EOF
Uso: bash $SCRIPT_NAME [opções]

Coleta informações do servidor e gera relatório Markdown (somente leitura).

Opções:
  --project-dir CAMINHO   Diretório do projeto (padrão: $PROD_SOURCE_DIR se existir).
  --domain DOMÍNIO        Domínio esperado (padrão: $DEFAULT_DOMAIN).
  --output ARQUIVO        Caminho do relatório (padrão: storage/app/diagnostico_*.md).
  --quiet                 Não imprime o relatório no terminal.
  -h, --help              Mostra esta ajuda.

Recomendado executar com sudo para checagens completas de serviços e portas.
EOF
}

parse_args() {
    while (($#)); do
        case "$1" in
            --project-dir)
                (($# >= 2)) || { echo "Valor ausente para $1" >&2; exit 1; }
                PROJECT_DIR="$2"
                shift
                ;;
            --domain)
                (($# >= 2)) || { echo "Valor ausente para $1" >&2; exit 1; }
                DOMAIN="$2"
                shift
                ;;
            --output)
                (($# >= 2)) || { echo "Valor ausente para $1" >&2; exit 1; }
                OUTPUT_FILE="$2"
                shift
                ;;
            --quiet) PRINT_STDOUT=false ;;
            -h|--help) usage; exit 0 ;;
            *) echo "Opção desconhecida: $1" >&2; exit 1 ;;
        esac
        shift
    done
    apply_default_project_dir
}

apply_default_project_dir() {
    [[ -n "$PROJECT_DIR" ]] && return
    if [[ -f "$PROD_SOURCE_DIR/artisan" ]]; then
        PROJECT_DIR="$PROD_SOURCE_DIR"
    elif [[ -f "$(dirname "${BASH_SOURCE[0]}")/artisan" ]]; then
        PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    else
        PROJECT_DIR="$ROOT"
    fi
    [[ "$PROJECT_DIR" = /* ]] || PROJECT_DIR="$(cd "$PROJECT_DIR" && pwd)"
}

report_init() {
    if [[ -z "$OUTPUT_FILE" ]]; then
        mkdir -p "$PROJECT_DIR/storage/app" 2>/dev/null || true
        OUTPUT_FILE="$PROJECT_DIR/storage/app/diagnostico_migracao_$(date +%Y%m%d_%H%M%S).md"
    fi
    : >"$OUTPUT_FILE"
}

r() {
    printf '%s\n' "$*" >>"$OUTPUT_FILE"
}

status_ok()   { COUNT_OK+=1;   r "- ✅ **OK** — $*"; }
status_warn() { COUNT_WARN+=1; r "- ⚠️ **AVISO** — $*"; }
status_fail() { COUNT_FAIL+=1; r "- ❌ **BLOQUEIO** — $*"; }
status_info() { COUNT_INFO+=1; r "- ℹ️ $*"; }

section() {
    r ""
    r "## $1"
    r ""
}

subsection() {
    r ""
    r "### $1"
    r ""
}

code() {
    r '```'
    while (($#)); do r "$1"; shift; done
    r '```'
}

run_capture() {
    local label="$1"
    shift
    local output exit_code=0
    output="$("$@" 2>&1)" || exit_code=$?
    subsection "$label"
    if [[ -n "$output" ]]; then
        code "$output"
    else
        r "_Sem saída (exit $exit_code)_"
    fi
    return 0
}

mask_env_value() {
    local key="$1" value="$2"
    case "$key" in
        *PASSWORD*|*SECRET*|*KEY*|*TOKEN*) printf '%s' '[OCULTO]' ;;
        *) printf '%s' "$value" ;;
    esac
}

read_env_keys() {
    local env_file="$1"
    [[ -f "$env_file" ]] || return 0
    subsection ".env (valores sensíveis ocultos)"
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" =~ ^[[:space:]]*# ]] && continue
        [[ "$line" =~ ^[A-Z0-9_]+= ]] || continue
        local key="${line%%=*}"
        local value="${line#*=}"
        r "- \`$key\` = \`$(mask_env_value "$key" "$value")\`"
    done <"$env_file"
}

read_compose_mysql_var() {
    local key="$1" compose_file="$PROJECT_DIR/docker-compose.yml"
    [[ -f "$compose_file" ]] || return 1
    grep -E "^[[:space:]]*${key}:[[:space:]]*" "$compose_file" 2>/dev/null |
        head -1 | sed -E 's/^[^:]*:[[:space:]]*//' | tr -d '\r"' |
        sed -E "s/^['\"]//; s/['\"]$//"
}

list_compose_container_names() {
    local compose_file="$PROJECT_DIR/docker-compose.yml"
    [[ -f "$compose_file" ]] || return 0
    grep -E '^[[:space:]]{4}container_name:[[:space:]]*' "$compose_file" 2>/dev/null |
        sed -E 's/^[[:space:]]*container_name:[[:space:]]*//' | tr -d '\r"' |
        sed -E "s/^['\"]//; s/['\"]$//"
}

detect_compose_mysql_service() {
    local compose_file="$PROJECT_DIR/docker-compose.yml"
    [[ -f "$compose_file" ]] || return 1
    awk '
        /^  [a-zA-Z0-9_.-]+:[[:space:]]*$/ { svc = $1; gsub(/:/, "", svc); next }
        /^    image:[[:space:]]/ && (index($0, "mysql") || index($0, "mariadb")) { mysql_svc = svc }
        END { if (mysql_svc != "") print mysql_svc }
    ' "$compose_file"
}

read_env_value() {
    local key="$1" env_file="$PROJECT_DIR/.env"
    [[ -f "$env_file" ]] || return 1
    grep -E "^${key}=" "$env_file" 2>/dev/null | head -1 | cut -d= -f2- |
        tr -d '\r"' | sed -E "s/^['\"]//; s/['\"]$//"
}

docker_compose_cmd() {
    if docker compose version >/dev/null 2>&1; then
        docker compose -f "$PROJECT_DIR/docker-compose.yml" "$@"
    elif command -v docker-compose >/dev/null 2>&1; then
        docker-compose -f "$PROJECT_DIR/docker-compose.yml" "$@"
    else
        return 127
    fi
}

resolve_docker_db_container() {
    local service="${1:-db}" container_id
    command -v docker >/dev/null 2>&1 || return 1
    container_id="$(docker_compose_cmd ps -q "$service" 2>/dev/null | head -1 || true)"
    [[ -n "$container_id" ]] || return 1
    docker inspect -f '{{.Name}}' "$container_id" 2>/dev/null | sed 's/^\///'
}

port_listening() {
    local port="$1"
    if command -v ss >/dev/null 2>&1; then
        ss -tlnH "sport = :$port" 2>/dev/null | head -3
    elif command -v netstat >/dev/null 2>&1; then
        netstat -tln 2>/dev/null | grep -E ":${port}[[:space:]]" | head -3
    else
        return 1
    fi
}

check_php_extensions() {
    local required=(pdo_mysql mbstring xml curl zip bcmath gd intl)
    local ext missing=()
    command -v php >/dev/null 2>&1 || return 1
    for ext in "${required[@]}"; do
        php -m 2>/dev/null | grep -qi "^${ext}$" || missing+=("$ext")
    done
    if ((${#missing[@]} == 0)); then
        status_ok "Extensões PHP CLI necessárias instaladas: ${required[*]}"
    else
        status_warn "Extensões PHP CLI ausentes no host: ${missing[*]} (serão instaladas com php-fpm pelo instalador)"
    fi
}

report_header() {
    r "# Diagnóstico — migração Integrar (Docker → nativo)"
    r ""
    r "| Campo | Valor |"
    r "|-------|-------|"
    r "| Gerado em | $(date -Iseconds 2>/dev/null || date) |"
    r "| Hostname | $(hostname 2>/dev/null || echo '?') |"
    r "| Usuário | $(whoami 2>/dev/null || echo '?') |"
    r "| EUID | ${EUID:-$(id -u 2>/dev/null || echo '?')} |"
    r "| Projeto | \`$PROJECT_DIR\` |"
    r "| Domínio alvo | \`$DOMAIN\` |"
    r "| Banco alvo | \`$DEFAULT_DB_NAME\` |"
    r "| Script | \`$SCRIPT_NAME\` |"
    r ""
    r "> Relatório somente leitura. Envie este arquivo para análise do instalador \`instalar-nativo-producao.sh\`."
}

report_system() {
    section "1. Sistema operacional"
    if [[ -r /etc/os-release ]]; then
        # shellcheck disable=SC1091
        source /etc/os-release
        r "- **Distribuição:** ${PRETTY_NAME:-$ID}"
        case "${ID:-}" in
            debian|ubuntu) status_ok "Distribuição suportada pelo instalador ($ID)" ;;
            *) status_fail "Distribuição não suportada pelo instalador (${ID:-desconhecida})" ;;
        esac
    else
        status_fail "Arquivo /etc/os-release não encontrado"
    fi

    command -v systemctl >/dev/null 2>&1 && status_ok "systemd disponível" || status_fail "systemd não encontrado (obrigatório)"

    subsection "Kernel / uptime / recursos"
    code \
        "$(uname -a 2>/dev/null || true)" \
        "$(uptime 2>/dev/null || true)" \
        "Memória:" \
        "$(free -h 2>/dev/null | head -3 || true)" \
        "Disco (/):" \
        "$(df -h / 2>/dev/null | tail -1 || true)" \
        "Disco (projeto):" \
        "$(df -h "$PROJECT_DIR" 2>/dev/null | tail -1 || true)"
}

report_project() {
    section "2. Projeto Laravel"
    if [[ -f "$PROJECT_DIR/artisan" && -f "$PROJECT_DIR/composer.json" ]]; then
        status_ok "Projeto Laravel encontrado"
    else
        status_fail "artisan ou composer.json ausente em $PROJECT_DIR"
        return
    fi

    read_env_keys "$PROJECT_DIR/.env"

    subsection "Versões de ferramentas"
    code \
        "PHP: $(php -v 2>/dev/null | head -1 || echo 'não instalado')" \
        "Composer: $(composer --version 2>/dev/null || echo 'não instalado')" \
        "Node: $(node -v 2>/dev/null || echo 'não instalado')" \
        "npm: $(npm -v 2>/dev/null || echo 'não instalado')" \
        "Python: $(python3 --version 2>/dev/null || echo 'não instalado')" \
        "pdftotext: $(pdftotext -v 2>&1 | head -1 || echo 'não instalado')"

    if command -v php >/dev/null 2>&1; then
        if php -r 'exit(version_compare(PHP_VERSION, "8.2", ">=") ? 0 : 1);' 2>/dev/null; then
            status_ok "PHP >= 8.2"
        else
            status_fail "PHP < 8.2 ($(php -r 'echo PHP_VERSION;' 2>/dev/null))"
        fi
        check_php_extensions
    else
        status_warn "PHP CLI não instalado no host (normal se tudo ainda está no Docker)"
    fi

    if command -v pdftotext >/dev/null 2>&1; then
        status_ok "pdftotext disponível (importação PDF do plano de contas Domínio)"
    else
        status_warn "pdftotext ausente — instale poppler-utils (necessário para importar plano de contas em PDF)"
    fi

    subsection "Permissões storage/ e bootstrap/cache/"
    local path owner perm writable=1
    for path in "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"; do
        if [[ -d "$path" ]]; then
            owner="$(stat -c '%U:%G %a' "$path" 2>/dev/null || stat -f '%Su:%Sg %Lp' "$path" 2>/dev/null || echo '?')"
            r "- \`$path\` — $owner"
            [[ -w "$path" ]] || writable=0
        else
            status_fail "Diretório ausente: $path"
            writable=0
        fi
    done
    (( writable )) && status_ok "Diretórios graváveis pelo usuário atual" || status_warn "Diretórios não graváveis pelo usuário atual (ajustar antes da migração)"

    if [[ -f "$PROJECT_DIR/.env" ]]; then
        if grep -q '^APP_KEY=base64:' "$PROJECT_DIR/.env"; then
            status_ok "APP_KEY configurada"
        else
            status_warn "APP_KEY ausente ou inválida"
        fi
    fi
}

report_docker() {
    section "3. Docker e docker-compose.yml"
    if ! command -v docker >/dev/null 2>&1; then
        status_warn "Docker não instalado no host"
        return
    fi
    status_ok "Docker instalado"
    run_capture "docker --version" docker --version
    run_capture "docker compose version" docker compose version 2>/dev/null || true
    if command -v docker-compose >/dev/null 2>&1; then
        run_capture "docker-compose --version" docker-compose --version
    else
        status_warn "Comando docker-compose não encontrado (instalador para containers pelo nome)"
    fi

    if [[ -f "$PROJECT_DIR/docker-compose.yml" ]]; then
        status_ok "docker-compose.yml encontrado"
    else
        status_fail "docker-compose.yml ausente"
        return
    fi

    subsection "Containers definidos no Compose"
    local name
    while IFS= read -r name; do
        [[ -n "$name" ]] && r "- \`$name\`"
    done < <(list_compose_container_names)

    local mysql_service db_container db_name env_db root_pw candidate tables_found=""
    mysql_service="$(detect_compose_mysql_service 2>/dev/null | head -1 | tr -d '\r\n' || echo db)"
    db_container="$(resolve_docker_db_container "$mysql_service" 2>/dev/null || true)"
    db_name="$(read_compose_mysql_var MYSQL_DATABASE 2>/dev/null || echo "$DEFAULT_DB_NAME")"
    env_db="$(read_env_value DB_DATABASE 2>/dev/null || true)"
    root_pw="$(read_compose_mysql_var MYSQL_ROOT_PASSWORD 2>/dev/null || true)"

    r ""
    r "- Serviço MySQL no Compose: \`$mysql_service\`"
    r "- MYSQL_DATABASE (compose): \`$db_name\`"
    r "- DB_DATABASE (.env): \`${env_db:-[AUSENTE]}\`"
    r "- MYSQL_ROOT_PASSWORD: \`$([ -n "$root_pw" ] && echo '[DEFINIDA]' || echo '[AUSENTE]')\`"

    if [[ -n "$db_container" ]]; then
        status_ok "Container MySQL em execução: $db_container"
        run_capture "docker inspect (MySQL)" docker inspect "$db_container" --format \
            'Status={{.State.Status}} Image={{.Config.Image}} IP={{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}'

        if [[ -n "$root_pw" ]]; then
            subsection "MySQL no Docker (metadados)"
            local db_size tables dump_db=""
            for candidate in "$env_db" "$db_name" "$DEFAULT_DB_NAME"; do
                [[ -n "$candidate" ]] || continue
                tables="$(docker exec "$db_container" mysql -uroot -p"$root_pw" -Nse \
                    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${candidate}';" \
                    2>/dev/null || true)"
                r "- Banco \`$candidate\`: ${tables:-?} tabela(s)"
                if [[ "${tables:-0}" -gt 0 ]]; then
                    tables_found="$candidate"
                    dump_db="$candidate"
                    db_size="$(docker exec "$db_container" mysql -uroot -p"$root_pw" -Nse \
                        "SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) FROM information_schema.tables WHERE table_schema='${candidate}';" \
                        2>/dev/null || true)"
                    r "  - Tamanho (MB): ${db_size:-?}"
                fi
            done
            [[ -n "$dump_db" ]] || dump_db="${env_db:-$db_name}"
            dump_header="$(docker exec "$db_container" mysqldump --single-transaction "$dump_db" \
                -uroot -p"$root_pw" 2>/dev/null | head -1 || true)"
            if [[ "$dump_header" == *"MySQL dump"* ]]; then
                status_ok "mysqldump acessível no container (banco: $dump_db)"
            elif [[ -n "$tables_found" ]]; then
                status_ok "Banco $tables_found com dados (${tables} tabelas); mysqldump manual confirmado"
            else
                status_fail "mysqldump falhou no container (banco testado: $dump_db)"
            fi
        else
            status_warn "Não foi possível testar MySQL no Docker (senha root não encontrada no compose)"
        fi
    else
        status_warn "Container MySQL não está em execução"
    fi

    run_capture "docker ps (integrar)" docker ps --filter "name=integrar" --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
    run_capture "docker compose ps" docker_compose_cmd ps 2>/dev/null || true
}

report_native_stack() {
    section "4. Stack nativa (Apache / PHP-FPM / MySQL)"
    local svc
    for svc in apache2 nginx mysql mariadb; do
        if command -v "$svc" >/dev/null 2>&1 || dpkg -l "$svc" 2>/dev/null | grep -q '^ii'; then
            status_info "Pacote/serviço detectado: $svc"
        fi
    done

    if command -v apache2 >/dev/null 2>&1; then
        if systemctl is-active --quiet apache2 2>/dev/null; then
            status_info "Apache2 ativo"
        else
            status_info "Apache2 instalado mas inativo"
        fi
        run_capture "apache2 -v" apache2 -v
        run_capture "apache2ctl -S" apache2ctl -S 2>/dev/null || true
        [[ -f /etc/apache2/sites-enabled/integrar.conf ]] && status_info "VirtualHost integrar.conf já existe" || status_info "VirtualHost integrar.conf ainda não existe"
        local ssl_vhost
        ssl_vhost="$(grep -l "ServerName[[:space:]]*$DOMAIN" /etc/apache2/sites-enabled/*-le-ssl.conf 2>/dev/null | head -1 || true)"
        [[ -n "$ssl_vhost" ]] && status_ok "HTTPS/Let's Encrypt já configurado: $ssl_vhost" || status_info "Certificado SSL dedicado não detectado para $DOMAIN"
    else
        status_info "Apache2 não instalado (será instalado pelo script)"
    fi

    if command -v nginx >/dev/null 2>&1 && systemctl is-active --quiet nginx 2>/dev/null; then
        status_warn "Nginx ativo — pode conflitar com Apache na porta 80"
    fi

    local fpm_socket
    fpm_socket="$(find /run/php -name 'php*-fpm.sock' 2>/dev/null | sort -V | tail -1 || true)"
    if [[ -n "$fpm_socket" ]]; then
        status_info "Socket PHP-FPM: $fpm_socket"
        systemctl is-active --quiet "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)-fpm" 2>/dev/null &&
            status_ok "PHP-FPM ativo" || status_warn "PHP-FPM instalado mas inativo"
    else
        status_info "PHP-FPM socket não encontrado (será configurado na instalação)"
    fi

    if command -v mysql >/dev/null 2>&1; then
        run_capture "mysql --version" mysql --version
        if mysql -uroot -e "SELECT 1" >/dev/null 2>&1 || sudo -n mysql -uroot -e "SELECT 1" >/dev/null 2>&1; then
            status_ok "MySQL nativo acessível como root (auth_socket)"
            run_capture "Bancos existentes" bash -c 'mysql -uroot -e "SHOW DATABASES;" 2>/dev/null || sudo mysql -uroot -e "SHOW DATABASES;"'
        else
            status_warn "MySQL nativo instalado mas root inacessível sem senha (comum em Ubuntu — instalador usa sudo mysql)"
        fi
    else
        status_info "MySQL nativo não instalado (será instalado pelo script)"
    fi
}

report_systemd() {
    section "5. systemd (fila e agendador Laravel)"
    local unit
    for unit in integrar-queue integrar-scheduler; do
        if systemctl list-unit-files "$unit.service" 2>/dev/null | grep -q "$unit.service"; then
            run_capture "systemctl status $unit" systemctl status "$unit" --no-pager 2>/dev/null || true
        else
            status_info "Unit $unit.service ainda não configurada"
        fi
    done
}

report_network() {
    section "6. Rede, DNS e portas"
    subsection "Resolução DNS"
    local resolved=""
    if command -v getent >/dev/null 2>&1; then
        resolved="$(getent ahosts "$DOMAIN" 2>/dev/null | awk '{print $1}' | sort -u | paste -sd, - || true)"
    elif command -v dig >/dev/null 2>&1; then
        resolved="$(dig +short A "$DOMAIN" 2>/dev/null | paste -sd, - || true)"
    fi
    if [[ -n "$resolved" ]]; then
        r "- \`$DOMAIN\` → $resolved"
        status_ok "DNS resolve para $DOMAIN"
    else
        status_fail "DNS não resolve $DOMAIN"
    fi

    local public_ip local_ips
    public_ip="$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || curl -fsS --max-time 5 ifconfig.me 2>/dev/null || true)"
    local_ips="$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -v '^$' | paste -sd, - || true)"
    r "- IP público (approx): ${public_ip:-desconhecido}"
    r "- IPs locais: ${local_ips:-desconhecido}"

    subsection "Portas relevantes"
    local port desc line
    local ports=(80 443 3306 8081 8082)
    local labels=("HTTP" "HTTPS" "MySQL" "Docker app (integrar)" "Docker phpMyAdmin")
    local i
    for i in "${!ports[@]}"; do
        port="${ports[$i]}"
        desc="${labels[$i]}"
        line="$(port_listening "$port" || true)"
        if [[ -n "$line" ]]; then
            r "- **:$port ($desc)** — em uso:"
            code "$line"
        else
            r "- **:$port ($desc)** — livre ou não detectada"
        fi
    done

    subsection "HTTP local"
    local http_code
    http_code="$(curl -o /dev/null -s -w '%{http_code}' --max-time 5 -H "Host: $DOMAIN" http://127.0.0.1/ 2>/dev/null || echo '000')"
    r "- \`curl -H Host:$DOMAIN http://127.0.0.1/\` → HTTP $http_code"
    if [[ "$http_code" =~ ^[23] ]]; then
        status_ok "Resposta HTTP local OK ($http_code)"
    elif [[ "$http_code" == "000" ]]; then
        status_info "Sem resposta HTTP local (normal se Apache ainda não configurado)"
    else
        status_warn "Resposta HTTP local: $http_code"
    fi

    http_code="$(curl -o /dev/null -s -w '%{http_code}' --max-time 5 "https://$DOMAIN/" 2>/dev/null || echo '000')"
    r "- \`curl https://$DOMAIN/\` → HTTP $http_code"
}

report_installer_readiness() {
    section "7. Prontidão para instalar-nativo-producao.sh"
    r ""
    r "| Resultado | Quantidade |"
    r "|-----------|------------|"
    r "| ✅ OK | $COUNT_OK |"
    r "| ⚠️ Avisos | $COUNT_WARN |"
    r "| ❌ Bloqueios | $COUNT_FAIL |"
    r ""

    subsection "Recomendações automáticas"
    if (( COUNT_FAIL > 0 )); then
        status_fail "Corrija os bloqueios acima antes de executar a migração"
    else
        status_ok "Nenhum bloqueio crítico detectado pelo diagnóstico"
    fi

    if [[ ${EUID:-0} -ne 0 ]]; then
        status_warn "Execute novamente com sudo para checagens completas de serviços"
    fi

    r ""
    r "### Próximos passos sugeridos"
    r ""
    r "1. Revisar este relatório e enviar para análise."
    r "2. Simular instalação:"
    r '   ```bash'
    r "   sudo ./instalar-nativo-producao.sh --dry-run --yes"
    r '   ```'
    r "3. Executar migração interativa:"
    r '   ```bash'
    r "   sudo ./instalar-nativo-producao.sh"
    r '   ```'
}

main() {
    parse_args "$@"
    report_init

    report_header
    report_system
    report_project
    report_docker
    report_native_stack
    report_systemd
    report_network
    report_installer_readiness

    r ""
    r "---"
    r "_Fim do relatório — $(date -Iseconds 2>/dev/null || date)_"

    echo "Relatório salvo em: $OUTPUT_FILE" >&2
    echo "Resumo: OK=$COUNT_OK  Avisos=$COUNT_WARN  Bloqueios=$COUNT_FAIL" >&2

    if $PRINT_STDOUT; then
        echo "" >&2
        cat "$OUTPUT_FILE"
    fi

    (( COUNT_FAIL > 0 )) && exit 2
    (( COUNT_WARN > 0 )) && exit 1
    exit 0
}

main "$@"
