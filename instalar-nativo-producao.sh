#!/usr/bin/env bash
set -Eeuo pipefail

readonly SCRIPT_NAME="$(basename "$0")"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_USER="${SUDO_USER:-www-data}"
APP_GROUP="www-data"
DOMAIN="_"
DB_NAME="integrar"
DB_USER="integrar"
DB_PASSWORD=""
BACKUP_FILE=""
PHP_FPM_SOCKET=""
YES=false
DRY_RUN=false
SKIP_PACKAGES=false
SKIP_DATABASE=false

declare -a COMPLETED_ACTIONS=()

color() {
    if [[ -t 1 ]]; then
        printf '\033[%sm' "$1"
    fi
    return 0
}
reset_color() { color 0; }
info() { color "1;34"; printf 'ℹ %s\n' "$*"; reset_color; }
success() { color "1;32"; printf '✓ %s\n' "$*"; reset_color; }
warn() { color "1;33"; printf '⚠ %s\n' "$*" >&2; reset_color >&2; }
die() { color "1;31"; printf '✗ %s\n' "$*" >&2; reset_color >&2; exit 1; }

usage() {
    cat <<EOF
Uso: sudo ./$SCRIPT_NAME [opções]

Migra a aplicação Integrar do Docker para serviços nativos.

Opções:
  --yes                 Confirma todas as etapas (uso em automação).
  --dry-run             Exibe os comandos sem alterar o servidor.
  --project-dir CAMINHO Diretório absoluto da aplicação.
  --domain DOMÍNIO      Domínio configurado no Apache (padrão: sem ServerName).
  --app-user USUÁRIO    Dono dos arquivos e processo da fila.
  --db-name NOME        Banco MySQL de destino.
  --db-user USUÁRIO     Usuário MySQL da aplicação.
  --db-password SENHA   Senha MySQL (omitida, será solicitada).
  --backup-file ARQUIVO Importa um dump existente em vez do Docker.
  --skip-packages       Não instala pacotes do sistema.
  --skip-database       Não configura nem importa o MySQL.
  -h, --help            Mostra esta ajuda.
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

confirm() {
    local prompt="$1" answer
    $YES && return 0
    [[ -t 0 ]] || die "Entrada interativa indisponível. Use --yes ou execute em um terminal."
    read -r -p "$prompt [s/N] " answer
    [[ "$answer" =~ ^[SsYy]$ ]]
}

action() {
    local title="$1" callback="$2"
    printf '\n'
    if confirm "$title?"; then
        "$callback"
        COMPLETED_ACTIONS+=("$title")
        success "$title"
    else
        warn "Etapa ignorada: $title"
    fi
}

require_root() {
    if [[ ${EUID:-$(id -u)} -ne 0 ]] && ! $DRY_RUN; then
        die "Execute como root (sudo ./$SCRIPT_NAME)."
    fi
}

validate_inputs() {
    [[ "$PROJECT_DIR" = /* ]] || die "--project-dir deve ser um caminho absoluto."
    [[ -f "$PROJECT_DIR/artisan" && -f "$PROJECT_DIR/composer.json" ]] ||
        die "Projeto Laravel não encontrado em $PROJECT_DIR."
    [[ "$DB_NAME" =~ ^[a-zA-Z0-9_]+$ ]] || die "Nome de banco inválido."
    [[ "$DB_USER" =~ ^[a-zA-Z0-9_]+$ ]] || die "Usuário de banco inválido."
    id "$APP_USER" >/dev/null 2>&1 || $DRY_RUN || die "Usuário do sistema inexistente: $APP_USER."
    getent group "$APP_GROUP" >/dev/null 2>&1 || $DRY_RUN || die "Grupo inexistente: $APP_GROUP."
    if [[ -n "$BACKUP_FILE" ]]; then
        BACKUP_FILE="$(realpath "$BACKUP_FILE")"
        [[ -r "$BACKUP_FILE" ]] || die "Backup não legível: $BACKUP_FILE."
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
    command -v systemctl >/dev/null || die "systemd é obrigatório."
    success "Plataforma validada: ${PRETTY_NAME:-$ID}"
}

collect_password() {
    $SKIP_DATABASE && return
    [[ -n "$DB_PASSWORD" ]] && return
    if $YES; then
        DB_PASSWORD="$(openssl rand -base64 32 | tr -d '/+=' | head -c 28)"
        info "Senha segura do banco gerada automaticamente."
    else
        local confirmation
        while [[ -z "$DB_PASSWORD" ]]; do
            read -r -s -p "Senha para o usuário MySQL '$DB_USER': " DB_PASSWORD
            printf '\n'
            read -r -s -p "Confirme a senha: " confirmation
            printf '\n'
            [[ "$DB_PASSWORD" == "$confirmation" ]] || {
                warn "As senhas não conferem."
                DB_PASSWORD=""
            }
        done
    fi
}

install_packages() {
    run apt-get update
    run env DEBIAN_FRONTEND=noninteractive apt-get install -y \
        apache2 mysql-server php-fpm php-cli php-mysql php-gd php-bcmath php-zip \
        php-curl php-mbstring php-xml php-intl unzip curl git composer \
        python3 python3-pip python3-venv python3-pandas python3-openpyxl \
        python3-xlrd python3-numpy

    if ! command -v node >/dev/null 2>&1 || [[ "$(node -p 'Number(process.versions.node.split(`.`)[0])' 2>/dev/null || echo 0)" -lt 20 ]]; then
        run_shell "curl -fsSL https://deb.nodesource.com/setup_20.x | bash -"
        run apt-get install -y nodejs
    fi
}

configure_php() {
    local fpm_ini cli_ini
    fpm_ini="$(find /etc/php -path '*/fpm/php.ini' -print 2>/dev/null | sort -V | tail -1 || true)"
    cli_ini="$(find /etc/php -path '*/cli/php.ini' -print 2>/dev/null | sort -V | tail -1 || true)"
    if $DRY_RUN && [[ -z "$fpm_ini" ]]; then
        fpm_ini="/etc/php/8.2/fpm/php.ini"
        cli_ini="/etc/php/8.2/cli/php.ini"
    fi
    [[ -n "$fpm_ini" ]] || die "php.ini do PHP-FPM não encontrado."

    for ini in "$fpm_ini" "$cli_ini"; do
        [[ -n "$ini" ]] || continue
        run sed -i \
            -e 's/^[;[:space:]]*max_execution_time[[:space:]]*=.*/max_execution_time = 300/' \
            -e 's/^[;[:space:]]*memory_limit[[:space:]]*=.*/memory_limit = 512M/' \
            -e 's/^[;[:space:]]*upload_max_filesize[[:space:]]*=.*/upload_max_filesize = 50M/' \
            -e 's/^[;[:space:]]*post_max_size[[:space:]]*=.*/post_max_size = 50M/' "$ini"
    done

    PHP_FPM_SOCKET="$(find /run/php -name 'php*-fpm.sock' -print 2>/dev/null | sort -V | tail -1 || true)"
    if [[ -z "$PHP_FPM_SOCKET" ]]; then
        local fpm_service
        fpm_service="$(find /lib/systemd/system -name 'php*-fpm.service' -printf '%f\n' 2>/dev/null | sort -V | tail -1 || true)"
        [[ -n "$fpm_service" ]] || $DRY_RUN || die "Serviço PHP-FPM não encontrado."
        [[ -n "$fpm_service" ]] && run systemctl restart "$fpm_service"
        PHP_FPM_SOCKET="$(find /run/php -name 'php*-fpm.sock' -print 2>/dev/null | sort -V | tail -1 || true)"
    fi
    [[ -n "$PHP_FPM_SOCKET" ]] || PHP_FPM_SOCKET="/run/php/php8.2-fpm.sock"
}

backup_docker_database() {
    [[ -n "$BACKUP_FILE" ]] && {
        info "Será usado o backup informado: $BACKUP_FILE"
        return
    }
    command -v docker >/dev/null 2>&1 || {
        warn "Docker não encontrado; a instalação seguirá sem importar dados."
        return
    }

    local container timestamp
    container="$(docker ps --format '{{.Names}}' | awk '/integrar.*db|db.*integrar/ {print; exit}')"
    [[ -n "$container" ]] || {
        warn "Container MySQL do Integrar não está em execução; informe --backup-file para importar dados."
        return
    }
    timestamp="$(date +%Y%m%d_%H%M%S)"
    BACKUP_FILE="$PROJECT_DIR/storage/app/backup_pre_migracao_${timestamp}.sql"
    run mkdir -p "$(dirname "$BACKUP_FILE")"
    run_shell "docker exec $(printf %q "$container") mysqldump --single-transaction --routines --triggers --all-databases -uroot -proot > $(printf %q "$BACKUP_FILE")"
    if ! $DRY_RUN; then
        [[ -s "$BACKUP_FILE" ]] || die "O dump do Docker foi criado vazio."
        run chmod 600 "$BACKUP_FILE"
    fi
}

stop_docker_stack() {
    command -v docker >/dev/null 2>&1 || return
    if docker compose version >/dev/null 2>&1; then
        run docker compose -f "$PROJECT_DIR/docker-compose.yml" down
    elif command -v docker-compose >/dev/null 2>&1; then
        run docker-compose -f "$PROJECT_DIR/docker-compose.yml" down
    else
        warn "Plugin Docker Compose não encontrado; pare os containers manualmente."
    fi
}

configure_database() {
    run systemctl enable --now mysql
    local sql
    sql="CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$(printf %s "$DB_PASSWORD" | sed "s/'/''/g")';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$(printf %s "$DB_PASSWORD" | sed "s/'/''/g")';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;"
    if $DRY_RUN; then
        printf '[dry-run] mysql -uroot (criar banco e usuário; senha ocultada)\n'
    else
        mysql -uroot <<<"$sql"
    fi

    if [[ -n "$BACKUP_FILE" ]]; then
        if head -n 80 "$BACKUP_FILE" | grep -qE 'CREATE DATABASE|Current Database:'; then
            warn "Dump de múltiplos bancos detectado; será importado pelo usuário root."
            run_shell "mysql -uroot < $(printf %q "$BACKUP_FILE")"
        else
            run_shell "mysql -uroot $(printf %q "$DB_NAME") < $(printf %q "$BACKUP_FILE")"
        fi
    fi
}

set_env_value() {
    local file="$1" key="$2" value="$3" escaped
    escaped="$(printf '%s' "$value" | sed -e 's/[\/&]/\\&/g')"
    if $DRY_RUN && [[ ! -f "$file" ]]; then
        printf '[dry-run] definir %s em %s\n' "$key" "$file"
        return
    fi
    if grep -q "^${key}=" "$file"; then
        run sed -i "s/^${key}=.*/${key}=${escaped}/" "$file"
    else
        run_shell "printf '%s\\n' $(printf %q "${key}=${value}") >> $(printf %q "$file")"
    fi
}

configure_application() {
    local env_file="$PROJECT_DIR/.env"
    [[ -f "$env_file" ]] || run cp "$PROJECT_DIR/.env.example" "$env_file"

    set_env_value "$env_file" APP_ENV production
    set_env_value "$env_file" APP_DEBUG false
    [[ "$DOMAIN" == "_" ]] || set_env_value "$env_file" APP_URL "https://$DOMAIN"
    if ! $SKIP_DATABASE; then
        set_env_value "$env_file" DB_CONNECTION mysql
        set_env_value "$env_file" DB_HOST 127.0.0.1
        set_env_value "$env_file" DB_PORT 3306
        set_env_value "$env_file" DB_DATABASE "$DB_NAME"
        set_env_value "$env_file" DB_USERNAME "$DB_USER"
        set_env_value "$env_file" DB_PASSWORD "$DB_PASSWORD"
    fi

    run chown -R "$APP_USER:$APP_GROUP" "$PROJECT_DIR"
    run chmod -R ug+rwX "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
    run chmod 640 "$env_file"
    run sudo -u "$APP_USER" composer install --working-dir="$PROJECT_DIR" \
        --no-dev --prefer-dist --optimize-autoloader --no-interaction
    run sudo -u "$APP_USER" npm --prefix "$PROJECT_DIR" ci
    run sudo -u "$APP_USER" npm --prefix "$PROJECT_DIR" run build

    if ! $DRY_RUN && ! grep -q '^APP_KEY=base64:' "$env_file"; then
        run sudo -u "$APP_USER" php "$PROJECT_DIR/artisan" key:generate --force
    fi
    run sudo -u "$APP_USER" python3 -m venv --system-site-packages "$PROJECT_DIR/.venv"
    run "$PROJECT_DIR/.venv/bin/pip" install -r "$PROJECT_DIR/scripts/requirements.txt"
    run sudo -u "$APP_USER" php "$PROJECT_DIR/artisan" storage:link
}

run_migrations() {
    run sudo -u "$APP_USER" php "$PROJECT_DIR/artisan" migrate --force
    run sudo -u "$APP_USER" php "$PROJECT_DIR/artisan" optimize
}

write_file() {
    local destination="$1" mode="$2" content="$3"
    if $DRY_RUN; then
        printf '[dry-run] instalar arquivo %s (modo %s)\n' "$destination" "$mode"
    else
        printf '%s' "$content" >"$destination"
        chmod "$mode" "$destination"
    fi
}

configure_apache() {
    [[ -n "$PHP_FPM_SOCKET" ]] || configure_php
    local config server_name=""
    [[ "$DOMAIN" == "_" ]] || server_name="    ServerName $DOMAIN
"
    config="<VirtualHost *:80>
${server_name}    DocumentRoot $PROJECT_DIR/public
    DirectoryIndex index.php

    <Directory $PROJECT_DIR/public>
        Options FollowSymLinks
        AllowOverride None
        Require all granted

        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteRule ^ index.php [L]
    </Directory>

    <FilesMatch \"\\.php$\">
        SetHandler \"proxy:unix:$PHP_FPM_SOCKET|fcgi://localhost/\"
    </FilesMatch>

    <FilesMatch \"^\\.\">
        Require all denied
    </FilesMatch>

    LimitRequestBody 52428800
    Header always set X-Content-Type-Options \"nosniff\"
    Header always set X-Frame-Options \"SAMEORIGIN\"

    ErrorLog \${APACHE_LOG_DIR}/integrar-error.log
    CustomLog \${APACHE_LOG_DIR}/integrar-access.log combined
</VirtualHost>
"
    write_file /etc/apache2/sites-available/integrar.conf 0644 "$config"
    run a2enmod proxy_fcgi setenvif rewrite headers
    run a2ensite integrar.conf
    run a2dissite 000-default.conf
    run apache2ctl configtest
    run systemctl enable --now apache2
    run systemctl reload apache2
}

configure_systemd() {
    local queue scheduler
    queue="[Unit]
Description=Integrar Laravel Queue Worker
After=network.target mysql.service

[Service]
Type=simple
User=$APP_USER
Group=$APP_GROUP
WorkingDirectory=$PROJECT_DIR
ExecStart=/usr/bin/php $PROJECT_DIR/artisan queue:work --sleep=3 --tries=3 --timeout=300
Restart=always
RestartSec=5
TimeoutStopSec=360

[Install]
WantedBy=multi-user.target
"
    scheduler="[Unit]
Description=Integrar Laravel Scheduler
After=network.target mysql.service

[Service]
Type=simple
User=$APP_USER
Group=$APP_GROUP
WorkingDirectory=$PROJECT_DIR
ExecStart=/usr/bin/php $PROJECT_DIR/artisan schedule:work
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
"
    write_file /etc/systemd/system/integrar-queue.service 0644 "$queue"
    write_file /etc/systemd/system/integrar-scheduler.service 0644 "$scheduler"
    run systemctl daemon-reload
    run systemctl enable --now integrar-queue integrar-scheduler
}

run_health_checks() {
    local failed=0
    check() {
        local description="$1"; shift
        if "$@" >/dev/null 2>&1; then
            success "Teste: $description"
        else
            warn "Falhou: $description"
            failed=1
        fi
    }

    if $DRY_RUN; then
        info "Dry-run: testes reais de serviços não foram executados."
        return
    fi
    check "PHP >= 8.2" bash -c 'php -r "exit(version_compare(PHP_VERSION, \"8.2\", \">=\") ? 0 : 1);"'
    check "Extensão PDO MySQL" php -m
    php -m | grep -qi '^pdo_mysql$' || { warn "Falhou: extensão pdo_mysql"; failed=1; }
    check "Configuração do Apache" apache2ctl configtest
    check "Apache ativo" systemctl is-active --quiet apache2
    check "PHP-FPM ativo" bash -c 'systemctl is-active --quiet "php$(php -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;")-fpm"'
    $SKIP_DATABASE || check "MySQL e credenciais da aplicação" \
        mysql --protocol=TCP -h127.0.0.1 -u"$DB_USER" "-p$DB_PASSWORD" -e "USE \`$DB_NAME\`; SELECT 1;"
    check "Laravel inicializa em produção" sudo -u "$APP_USER" php "$PROJECT_DIR/artisan" about --only=environment
    check "Worker da fila ativo" systemctl is-active --quiet integrar-queue
    check "Agendador ativo" systemctl is-active --quiet integrar-scheduler
    check "Resposta HTTP local" curl --fail --silent --show-error -H "Host: $DOMAIN" http://127.0.0.1/
    (( failed == 0 )) || die "Um ou mais testes falharam. Consulte: journalctl -u integrar-queue -u integrar-scheduler -u apache2"
}

parse_args() {
    while (($#)); do
        case "$1" in
            --yes) YES=true ;;
            --dry-run) DRY_RUN=true ;;
            --skip-packages) SKIP_PACKAGES=true ;;
            --skip-database) SKIP_DATABASE=true ;;
            --project-dir|--domain|--app-user|--db-name|--db-user|--db-password|--backup-file)
                (($# >= 2)) || die "Valor ausente para $1."
                case "$1" in
                    --project-dir) PROJECT_DIR="$2" ;;
                    --domain) DOMAIN="$2" ;;
                    --app-user) APP_USER="$2" ;;
                    --db-name) DB_NAME="$2" ;;
                    --db-user) DB_USER="$2" ;;
                    --db-password) DB_PASSWORD="$2" ;;
                    --backup-file) BACKUP_FILE="$2" ;;
                esac
                shift
                ;;
            -h|--help) usage; exit 0 ;;
            *) die "Opção desconhecida: $1." ;;
        esac
        shift
    done
}

main() {
    parse_args "$@"
    require_root
    validate_inputs
    check_platform
    collect_password

    cat <<EOF

Migração nativa do Integrar
  Projeto:  $PROJECT_DIR
  Domínio:  $DOMAIN
  Usuário:  $APP_USER:$APP_GROUP
  Banco:    $DB_NAME / $DB_USER
  Dry-run:  $DRY_RUN

Cada etapa solicitará confirmação antes de alterar o servidor.
EOF

    $SKIP_PACKAGES || action "Instalar dependências do sistema" install_packages
    action "Configurar PHP-FPM para produção" configure_php
    $SKIP_DATABASE || {
        action "Criar backup do banco executado no Docker" backup_docker_database
        action "Parar a stack Docker preservando os volumes" stop_docker_stack
        action "Configurar o MySQL nativo e importar o backup" configure_database
    }
    action "Instalar e configurar a aplicação Laravel" configure_application
    action "Executar as migrações do banco e otimizar o Laravel" run_migrations
    action "Configurar o Apache" configure_apache
    action "Configurar fila e agendador no systemd" configure_systemd
    action "Executar testes de saúde" run_health_checks

    printf '\n'
    success "Processo finalizado. Etapas concluídas: ${#COMPLETED_ACTIONS[@]}"
    [[ "$DOMAIN" == "_" ]] || info "Aplicação disponível em http://$DOMAIN (configure TLS antes de liberar ao público)."
    [[ -z "$BACKUP_FILE" ]] || info "Backup preservado em: $BACKUP_FILE"
}

main "$@"
