#!/usr/bin/env bash
#
# Backup automático MySQL do IntegraExpert.
# Padrão: produção nativa (Apache + MySQL no SO).
# Desenvolvimento Docker: use --docker.
#
# Cron (produção):
#   0 2 * * * /caminho/integrar/script-manutencao/backup-automatico.sh >> /caminho/integrar/backups/backup.log 2>&1
#
set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

MODE="native"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log()  { echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $*"; }
ok()   { echo -e "${GREEN}✅${NC} $*"; }
warn() { echo -e "${YELLOW}⚠️${NC}  $*"; }
err()  { echo -e "${RED}❌${NC} $*" >&2; }

usage() {
    cat <<EOF
Uso: $(basename "$0") [opções]

Gera dump SQL do banco configurado no .env e remove backups antigos.

Padrão: MySQL nativo (127.0.0.1 / serviço mysql).
Use --docker apenas em desenvolvimento com containers.

Opções:
  --native    MySQL nativo (padrão)
  --docker    MySQL no container Docker Compose
  -h, --help  Esta ajuda
EOF
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --native) MODE=native; shift ;;
            --docker) MODE=docker; shift ;;
            -h|--help) usage; exit 0 ;;
            *) err "Opção desconhecida: $1"; usage; exit 1 ;;
        esac
    done
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

load_db_config() {
    local env_file="$PROJECT_DIR/.env"

    if [[ ! -f "$env_file" ]]; then
        err "Arquivo .env não encontrado em $PROJECT_DIR"
        exit 1
    fi

    DB_NAME="$(read_env_value DB_DATABASE "$env_file" 2>/dev/null || echo integrar_dalongaro)"
    DB_USER="$(read_env_value DB_USERNAME "$env_file" 2>/dev/null || echo laravel)"
    DB_PASS="$(read_env_value DB_PASSWORD "$env_file" 2>/dev/null || echo secret)"
    DB_HOST="$(read_env_value DB_HOST "$env_file" 2>/dev/null || echo 127.0.0.1)"
    MYSQL_ROOT_PASS="$(read_env_value MYSQL_ROOT_PASSWORD "$env_file" 2>/dev/null || echo root)"
    RETENTION_DAYS="$(read_env_value BACKUP_RETENTION_DAYS "$env_file" 2>/dev/null || echo 30)"
}

resolve_native_db_host() {
    case "$DB_HOST" in
        db|mysql|mariadb|"")
            warn "DB_HOST=$DB_HOST no .env — usando 127.0.0.1 (produção nativa)"
            DB_HOST="127.0.0.1"
            ;;
        localhost)
            DB_HOST="127.0.0.1"
            ;;
    esac
}

docker_db_running() {
    command -v docker >/dev/null 2>&1 || return 1
    [[ -f "$PROJECT_DIR/docker-compose.yml" ]] || return 1
    docker compose -f "$PROJECT_DIR/docker-compose.yml" ps --status running --services 2>/dev/null | grep -qx 'db'
}

purge_old_backups() {
    local diretorio="$1" cutoff_date="$2" removidos=0 arquivo backup_date

    cd "$diretorio"
    for arquivo in backup-${DB_NAME}-*.sql backup-${DB_NAME}-*.sql.gz; do
        [[ -f "$arquivo" ]] || continue

        backup_date="$(echo "$arquivo" | sed -n "s/^backup-${DB_NAME}-\([0-9]\{8\}\)_.*/\1/p")"
        if [[ -z "$backup_date" ]]; then
            warn "Ignorado (nome fora do padrão): $arquivo"
            continue
        fi

        if [[ "$backup_date" -lt "$cutoff_date" ]]; then
            rm -f "$arquivo"
            removidos=$((removidos + 1))
            warn "Removido: $arquivo (data $backup_date < limite $cutoff_date)"
        fi
    done

    if [[ "$removidos" -gt 0 ]]; then
        warn "$removidos backup(s) removido(s) (retenção: ${RETENTION_DAYS} dias, desde $cutoff_date)"
    else
        log "Nenhum backup antigo para remover (retenção: ${RETENTION_DAYS} dias)"
    fi
}

verify_backup_file() {
    local arquivo="$1"

    if [[ -f "$arquivo" && -s "$arquivo" ]]; then
        ok "Backup criado: $arquivo ($(du -h "$arquivo" | cut -f1))"
        return 0
    fi

    err "Arquivo de backup vazio ou não criado: $arquivo"
    [[ -f "$arquivo" ]] && rm -f "$arquivo"
    return 1
}

backup_native() {
    resolve_native_db_host

    command -v mysqldump >/dev/null 2>&1 || {
        err "mysqldump não encontrado. Instale o cliente MySQL (pacote mysql-client)."
        exit 1
    }

    if command -v systemctl >/dev/null 2>&1; then
        systemctl is-active --quiet mysql 2>/dev/null || systemctl is-active --quiet mariadb 2>/dev/null || {
            err "Serviço mysql/mariadb não está ativo."
            exit 1
        }
    fi

    log "Modo: nativo"
    log "Banco: $DB_NAME @ $DB_HOST (usuário $DB_USER)"

    local dump_err
    dump_err="$(mktemp)"
    if [[ -n "$DB_PASS" ]]; then
        MYSQL_PWD="$DB_PASS" mysqldump \
            --protocol=TCP -h"$DB_HOST" -u"$DB_USER" \
            --single-transaction --routines --triggers \
            "$DB_NAME" > "$ARQUIVO_BACKUP" 2>"$dump_err"
    else
        mysqldump \
            --protocol=TCP -h"$DB_HOST" -u"$DB_USER" \
            --single-transaction --routines --triggers \
            "$DB_NAME" > "$ARQUIVO_BACKUP" 2>"$dump_err"
    fi

    if [[ ! -s "$ARQUIVO_BACKUP" ]]; then
        err "Falha no mysqldump nativo."
        [[ -s "$dump_err" ]] && sed 's/^/  /' "$dump_err" >&2
        rm -f "$ARQUIVO_BACKUP" "$dump_err"
        exit 1
    fi
    rm -f "$dump_err"
}

backup_docker() {
    command -v docker >/dev/null 2>&1 || {
        err "Docker não encontrado. Use modo nativo ou instale Docker."
        exit 1
    }

    docker_db_running || {
        err "Serviço db do Docker Compose não está em execução."
        exit 1
    }

    local dump_err
    dump_err="$(mktemp)"

    log "Modo: Docker Compose"
    log "Banco: $DB_NAME (container db)"

    if docker compose -f "$PROJECT_DIR/docker-compose.yml" exec -T db \
        mysqldump -u root -p"$MYSQL_ROOT_PASS" \
        --single-transaction --routines --triggers \
        "$DB_NAME" > "$ARQUIVO_BACKUP" 2>"$dump_err"; then
        :
    else
        err "Falha no mysqldump via Docker."
        [[ -s "$dump_err" ]] && sed 's/^/  /' "$dump_err" >&2
        rm -f "$ARQUIVO_BACKUP" "$dump_err"
        exit 1
    fi
    rm -f "$dump_err"
}

main() {
    parse_args "$@"
    cd "$PROJECT_DIR"
    load_db_config

    local data cutoff_date
    data="$(date +%Y%m%d_%H%M%S)"
    cutoff_date="$(date -d "${RETENTION_DAYS} days ago" +%Y%m%d)"
    DIRETORIO_BACKUP="$PROJECT_DIR/backups"
    ARQUIVO_BACKUP="$DIRETORIO_BACKUP/backup-${DB_NAME}-${data}.sql"

    mkdir -p "$DIRETORIO_BACKUP"

    log "Iniciando backup automático"
    log "Destino: $ARQUIVO_BACKUP"

    case "$MODE" in
        native) backup_native ;;
        docker) backup_docker ;;
        *) err "Modo inválido: $MODE"; exit 1 ;;
    esac

    verify_backup_file "$ARQUIVO_BACKUP"
    purge_old_backups "$DIRETORIO_BACKUP" "$cutoff_date"
    ok "Backup concluído em $(date +'%Y-%m-%d %H:%M:%S')"
}

main "$@"
