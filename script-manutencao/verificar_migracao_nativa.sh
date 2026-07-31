#!/usr/bin/env bash
#
# Verificação pós-migração Docker → nativo do Integrar.
# Somente leitura — não altera o servidor.
#
# Uso:
#   bash script-manutencao/verificar_migracao_nativa.sh
#   sudo bash script-manutencao/verificar_migracao_nativa.sh
#
# Relatório opcional: --output storage/app/verificacao_migracao_*.md

set -uo pipefail

readonly SCRIPT_NAME="$(basename "$0")"
readonly DEFAULT_DOMAIN="integraexpert.com.br"
readonly PROD_DEPLOY_DIR="/home/fabiano/Projetos/integrar"
readonly PROD_SOURCE_DIR="/home/fabiano/Projetos/integrar_dalongaro"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PROJECT_DIR=""
SOURCE_DIR=""
DOMAIN="$DEFAULT_DOMAIN"
OUTPUT_FILE=""
PRINT_STDOUT=true
CHECK_PUBLIC_URL=false

declare -i COUNT_OK=0 COUNT_WARN=0 COUNT_FAIL=0

usage() {
    cat <<EOF
Uso: bash $SCRIPT_NAME [opções]

Valida se a migração nativa concluiu corretamente (somente leitura).

Opções:
  --project-dir CAMINHO   Instalação nativa (padrão: $PROD_DEPLOY_DIR).
  --source-dir CAMINHO    Cópia Docker preservada (padrão: $PROD_SOURCE_DIR).
  --domain DOMÍNIO        Domínio da aplicação (padrão: $DEFAULT_DOMAIN).
  --output ARQUIVO        Salva relatório Markdown.
  --check-public-url      Testa também https://\$DOMAIN/ pela rede.
  --quiet                 Não imprime relatório no terminal.
  -h, --help              Mostra esta ajuda.

Recomendado executar com sudo para checagens de serviços systemd.
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
            --source-dir)
                (($# >= 2)) || { echo "Valor ausente para $1" >&2; exit 1; }
                SOURCE_DIR="$2"
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
            --check-public-url) CHECK_PUBLIC_URL=true ;;
            --quiet) PRINT_STDOUT=false ;;
            -h|--help) usage; exit 0 ;;
            *) echo "Opção desconhecida: $1" >&2; exit 1 ;;
        esac
        shift
    done
    apply_defaults
}

apply_defaults() {
    [[ -n "$PROJECT_DIR" ]] || PROJECT_DIR="$PROD_DEPLOY_DIR"
    [[ -n "$SOURCE_DIR" ]] || SOURCE_DIR="$PROD_SOURCE_DIR"
    [[ "$PROJECT_DIR" = /* ]] || PROJECT_DIR="$(cd "$PROJECT_DIR" && pwd)"
    [[ "$SOURCE_DIR" = /* ]] || SOURCE_DIR="$(cd "$SOURCE_DIR" && pwd)"
}

report_init() {
    if [[ -z "$OUTPUT_FILE" ]]; then
        mkdir -p "$PROJECT_DIR/storage/app" 2>/dev/null || true
        OUTPUT_FILE="$PROJECT_DIR/storage/app/verificacao_migracao_$(date +%Y%m%d_%H%M%S).md"
    fi
    : >"$OUTPUT_FILE"
}

r() { printf '%s\n' "$*" >>"$OUTPUT_FILE"; }

status_ok()   { COUNT_OK+=1;   r "- ✅ $*"; [[ "$PRINT_STDOUT" == true ]] && printf '\033[32m✓\033[0m %s\n' "$*"; }
status_warn() { COUNT_WARN+=1; r "- ⚠️ $*"; [[ "$PRINT_STDOUT" == true ]] && printf '\033[33m⚠\033[0m %s\n' "$*"; }
status_fail() { COUNT_FAIL+=1; r "- ❌ $*"; [[ "$PRINT_STDOUT" == true ]] && printf '\033[31m✗\033[0m %s\n' "$*"; }

section() {
    r ""
    r "## $1"
    r ""
    [[ "$PRINT_STDOUT" == true ]] && printf '\n%s\n' "$1"
}

read_env_value() {
    local key="$1" file="$2" line value
    [[ -f "$file" ]] || return 1
    line="$(grep -E "^${key}=" "$file" | tail -1 || true)"
    [[ -n "$line" ]] || return 1
    value="${line#*=}"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"
    printf '%s' "$value"
}

run_check() {
    local description="$1"
    shift
    if "$@"; then
        status_ok "$description"
        return 0
    fi
    status_fail "$description"
    return 1
}

run_check_warn() {
    local description="$1"
    shift
    if "$@"; then
        status_ok "$description"
        return 0
    fi
    status_warn "$description"
    return 1
}

home_traverse_ok() {
    local path="$1" dir
    path="$(cd "$path" 2>/dev/null && pwd)" || return 1
    dir="$(dirname "$path")"
    while [[ "$dir" != "/" ]]; do
        [[ -x "$dir" ]] || return 1
        dir="$(dirname "$dir")"
    done
    command -v runuser >/dev/null 2>&1 || return 0
    runuser -u www-data -- test -r "$path/index.php" 2>/dev/null
}

ssl_vhost_path() {
    local candidate
    for candidate in \
        /etc/apache2/sites-enabled/000-default-le-ssl.conf \
        /etc/apache2/sites-enabled/integrar-le-ssl.conf \
        /etc/apache2/sites-enabled/*-le-ssl.conf; do
        [[ -f "$candidate" ]] || continue
        grep -q "ServerName[[:space:]]\+$DOMAIN" "$candidate" 2>/dev/null && {
            printf '%s' "$candidate"
            return 0
        }
    done
    return 1
}

http_code_local() {
    local scheme="${1:-https}"
    curl --silent --show-error --connect-timeout 8 --max-time 15 \
        -o /dev/null -w '%{http_code}' \
        -H "Host: $DOMAIN" \
        -k "${scheme}://127.0.0.1/" 2>/dev/null
}

check_project() {
    section "Projeto nativo"
    run_check "Diretório da instalação nativa existe" test -d "$PROJECT_DIR"
    run_check "artisan presente" test -f "$PROJECT_DIR/artisan"
    run_check "vendor/ instalado" test -d "$PROJECT_DIR/vendor"
    run_check "public/index.php presente" test -f "$PROJECT_DIR/public/index.php"
    run_check_warn "Frontend compilado (public/build/manifest.json)" \
        test -f "$PROJECT_DIR/public/build/manifest.json"
    run_check_warn "Cópia Docker preservada em $SOURCE_DIR" test -f "$SOURCE_DIR/artisan"
}

check_env() {
    local env_file="$PROJECT_DIR/.env" app_env app_debug app_url db_host db_name

    section "Configuração (.env)"
    run_check ".env existe" test -f "$env_file"

    app_env="$(read_env_value APP_ENV "$env_file" 2>/dev/null || true)"
    app_debug="$(read_env_value APP_DEBUG "$env_file" 2>/dev/null || true)"
    app_url="$(read_env_value APP_URL "$env_file" 2>/dev/null || true)"
    db_host="$(read_env_value DB_HOST "$env_file" 2>/dev/null || true)"
    db_name="$(read_env_value DB_DATABASE "$env_file" 2>/dev/null || true)"

    [[ "$app_env" == "production" ]] && status_ok "APP_ENV=production" || status_fail "APP_ENV=$app_env (esperado: production)"
    [[ "$app_debug" == "false" || "$app_debug" == "0" ]] && status_ok "APP_DEBUG desligado" || status_warn "APP_DEBUG=$app_debug (recomendado: false)"
    [[ "$app_url" == "https://${DOMAIN}"* ]] && status_ok "APP_URL=$app_url" || status_warn "APP_URL=$app_url (esperado: https://$DOMAIN)"
    [[ "$db_host" == "127.0.0.1" || "$db_host" == "localhost" ]] && status_ok "DB_HOST=$db_host" || status_warn "DB_HOST=$db_host (esperado: 127.0.0.1)"
    [[ -n "$db_name" ]] && status_ok "DB_DATABASE=$db_name" || status_fail "DB_DATABASE não definido"
}

check_permissions() {
    section "Permissões"
    run_check "storage gravável pelo app" test -w "$PROJECT_DIR/storage"
    run_check "bootstrap/cache gravável" test -w "$PROJECT_DIR/bootstrap/cache"
    if home_traverse_ok "$PROJECT_DIR/public"; then
        status_ok "www-data consegue ler public/index.php"
    else
        status_fail "www-data não acessa public/ (chmod o+x no \$HOME?)"
    fi
}

check_php() {
    section "PHP"
    run_check "PHP >= 8.2" php -r 'exit(version_compare(PHP_VERSION, "8.2", ">=") ? 0 : 1);'
    run_check "Extensão pdo_mysql" php -m | grep -qi '^pdo_mysql$'
    if command -v systemctl >/dev/null 2>&1; then
        local fpm="php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
        run_check "PHP-FPM ativo ($fpm)" systemctl is-active --quiet "$fpm"
    else
        status_warn "systemctl indisponível — PHP-FPM não verificado"
    fi
}

check_database() {
    local env_file="$PROJECT_DIR/.env" db_user db_pass db_name tables

    section "MySQL nativo"
    command -v mysql >/dev/null 2>&1 || {
        status_warn "Cliente mysql não encontrado"
        return
    }

    db_user="$(read_env_value DB_USERNAME "$env_file" 2>/dev/null || true)"
    db_pass="$(read_env_value DB_PASSWORD "$env_file" 2>/dev/null || true)"
    db_name="$(read_env_value DB_DATABASE "$env_file" 2>/dev/null || true)"

    [[ -n "$db_user" && -n "$db_name" ]] || {
        status_fail "Credenciais DB incompletas no .env"
        return
    }

    if [[ -n "$db_pass" ]]; then
        run_check "Conexão MySQL (usuário $db_user)" \
            mysql --protocol=TCP -h127.0.0.1 -u"$db_user" "-p$db_pass" -e "SELECT 1;" "$db_name"
        tables="$(mysql --protocol=TCP -h127.0.0.1 -u"$db_user" "-p$db_pass" -Nse \
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${db_name}';" 2>/dev/null || true)"
    else
        run_check_warn "Conexão MySQL sem senha no .env" \
            mysql --protocol=TCP -h127.0.0.1 -u"$db_user" -e "SELECT 1;" "$db_name"
        tables="$(mysql --protocol=TCP -h127.0.0.1 -u"$db_user" -Nse \
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${db_name}';" 2>/dev/null || true)"
    fi

    if [[ "${tables:-0}" -gt 0 ]]; then
        status_ok "Banco '$db_name' com ${tables} tabela(s)"
    else
        status_fail "Banco '$db_name' sem tabelas"
    fi

    if command -v systemctl >/dev/null 2>&1; then
        run_check "Serviço mysql ativo" systemctl is-active --quiet mysql
    fi
}

check_apache() {
    local ssl_vhost public_dir code

    section "Apache"
    command -v apache2ctl >/dev/null 2>&1 || {
        status_fail "apache2ctl não encontrado"
        return
    }

    run_check "apache2ctl configtest" apache2ctl configtest
    if command -v systemctl >/dev/null 2>&1; then
        run_check "Apache ativo" systemctl is-active --quiet apache2
    fi

    ssl_vhost="$(ssl_vhost_path || true)"
    if [[ -n "$ssl_vhost" ]]; then
        status_ok "Vhost SSL encontrado: $ssl_vhost"
        if grep -qE 'ProxyPass[[:space:]]+.*/[[:space:]]+http://(localhost|127\.0\.0\.1):8081' "$ssl_vhost" 2>/dev/null; then
            status_fail "Vhost SSL ainda faz proxy para Docker (:8081)"
        else
            status_ok "Vhost SSL sem proxy Docker (:8081)"
        fi
        public_dir="$(grep -E '^[[:space:]]*DocumentRoot[[:space:]]' "$ssl_vhost" | awk '{print $2}' | tail -1)"
        if [[ "$public_dir" == "$PROJECT_DIR/public" ]]; then
            status_ok "DocumentRoot SSL → $public_dir"
        elif [[ -n "$public_dir" ]]; then
            status_fail "DocumentRoot SSL=$public_dir (esperado: $PROJECT_DIR/public)"
        else
            status_fail "DocumentRoot ausente no vhost SSL"
        fi
    else
        status_warn "Vhost SSL para $DOMAIN não encontrado"
    fi

    if [[ -f /etc/apache2/conf-enabled/integrar-laravel.conf ]]; then
        status_ok "Conf integrar-laravel habilitada"
    else
        status_warn "Conf integrar-laravel não habilitada"
    fi

    code="$(http_code_local https || echo "000")"
    case "$code" in
        200|301|302|303|307|308) status_ok "HTTPS local responde HTTP $code" ;;
        403) status_fail "HTTPS local retorna 403 (permissões no \$HOME?)" ;;
        503) status_fail "HTTPS local retorna 503 (proxy Docker ou PHP-FPM?)" ;;
        *) status_fail "HTTPS local retorna HTTP $code" ;;
    esac

    code="$(http_code_local http || echo "000")"
    case "$code" in
        301|302|308) status_ok "HTTP local redireciona para HTTPS ($code)" ;;
        *) status_warn "HTTP local retorna $code (esperado redirect 301/302)" ;;
    esac

    if $CHECK_PUBLIC_URL; then
        code="$(curl --silent --connect-timeout 10 --max-time 20 -o /dev/null -w '%{http_code}' "https://${DOMAIN}/" 2>/dev/null || echo "000")"
        case "$code" in
            200|301|302|303|307|308) status_ok "URL pública https://$DOMAIN/ → HTTP $code" ;;
            *) status_fail "URL pública https://$DOMAIN/ → HTTP $code" ;;
        esac
    fi
}

check_laravel() {
    section "Laravel"
    local app_user run_artisan
    app_user="$(stat -c '%U' "$PROJECT_DIR/artisan" 2>/dev/null || echo "${SUDO_USER:-fabiano}")"
    if [[ "$(id -un 2>/dev/null || echo root)" == "$app_user" ]]; then
        run_artisan=(php "$PROJECT_DIR/artisan")
    else
        run_artisan=(sudo -u "$app_user" php "$PROJECT_DIR/artisan")
    fi
    run_check "artisan about (ambiente)" "${run_artisan[@]}" about --only=environment 2>/dev/null
    run_check_warn "APP_KEY definida" \
        grep -qE '^APP_KEY=base64:' "$PROJECT_DIR/.env" 2>/dev/null
}

check_python() {
    section "Python (conversores)"
    run_check "python3 disponível" command -v python3
    run_check "pandas, openpyxl, pdfplumber importáveis" \
        python3 -c 'import pandas, openpyxl, pdfplumber'
    run_check "PyPDF2 importável (Caixa Federal / Grafeno PDF)" \
        python3 -c 'from PyPDF2 import PdfReader'
    if [[ -L /var/www/html ]]; then
        local target
        target="$(readlink -f /var/www/html 2>/dev/null || true)"
        [[ "$target" == "$PROJECT_DIR" ]] && status_ok "/var/www/html → $target" || status_warn "/var/www/html → $target (esperado: $PROJECT_DIR)"
    elif [[ "$PROJECT_DIR" == "/var/www/html" ]]; then
        status_ok "Projeto em /var/www/html"
    else
        status_warn "/var/www/html não é symlink para o projeto"
    fi
}

check_plano_contas_deps() {
    section "Plano de contas (PDF Domínio)"
    run_check "pdftotext (poppler-utils) disponível" command -v pdftotext
}

check_systemd() {
    section "Fila e agendador"
    command -v systemctl >/dev/null 2>&1 || {
        status_warn "systemctl indisponível"
        return
    }
    run_check "integrar-queue ativo" systemctl is-active --quiet integrar-queue
    run_check "integrar-scheduler ativo" systemctl is-active --quiet integrar-scheduler
    run_check_warn "integrar-queue habilitado no boot" systemctl is-enabled --quiet integrar-queue
    run_check_warn "integrar-scheduler habilitado no boot" systemctl is-enabled --quiet integrar-scheduler
}

check_docker() {
    section "Docker (pós-migração)"
    command -v docker >/dev/null 2>&1 || {
        status_ok "Docker não instalado ou indisponível"
        return
    }
    if docker ps --format '{{.Names}}' 2>/dev/null | grep -qE '^integrar-app$'; then
        status_warn "Container integrar-app ainda em execução (stack Docker ativa?)"
    else
        status_ok "Container integrar-app parado"
    fi
    if docker ps --format '{{.Names}}' 2>/dev/null | grep -qE '^integrar-db$'; then
        status_warn "Container integrar-db ainda em execução"
    else
        status_ok "Container integrar-db parado"
    fi
}

write_summary() {
    section "Resumo"
    r "- OK: **$COUNT_OK** | Avisos: **$COUNT_WARN** | Falhas: **$COUNT_FAIL**"
    r ""
    if (( COUNT_FAIL == 0 && COUNT_WARN == 0 )); then
        r "**Migração verificada com sucesso.**"
    elif (( COUNT_FAIL == 0 )); then
        r "**Migração funcional** com avisos — revise os itens ⚠️."
    else
        r "**Migração incompleta ou com problemas** — corrija os itens ❌."
    fi

    [[ "$PRINT_STDOUT" == true ]] && printf '\nResumo: OK=%d  Avisos=%d  Falhas=%d\n' "$COUNT_OK" "$COUNT_WARN" "$COUNT_FAIL"
}

main() {
    parse_args "$@"
    report_init

    r "# Verificação pós-migração — Integrar"
    r ""
    r "- **Data:** $(date -Iseconds 2>/dev/null || date)"
    r "- **Instalação nativa:** \`$PROJECT_DIR\`"
    r "- **Origem Docker:** \`$SOURCE_DIR\`"
    r "- **Domínio:** \`$DOMAIN\`"
    r ""

    check_project
    check_env
    check_permissions
    check_php
    check_database
    check_apache
    check_laravel
    check_python
    check_plano_contas_deps
    check_systemd
    check_docker
    write_summary

    [[ "$PRINT_STDOUT" == true ]] && printf '\nRelatório: %s\n' "$OUTPUT_FILE"
    (( COUNT_FAIL == 0 ))
}

main "$@"
