#!/usr/bin/env bash
#
# Verifica se há usuários autenticados com atividade recente (sessões Laravel)
# e jobs em execução, antes de rodar ./atualizar-producao.sh.
#
# Uso:
#   ./verificar-usuarios-ativos.sh
#   ./verificar-usuarios-ativos.sh --minutos 5
#   ./verificar-usuarios-ativos.sh --aguardar
#   ./verificar-usuarios-ativos.sh --atualizar
#   ./verificar-usuarios-ativos.sh --atualizar --force
#   ./verificar-usuarios-ativos.sh --docker
#
set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

MODE="native"
MINUTOS=10
AGUARDAR=false
ATUALIZAR=false
FORCE=false
INTERVALO_AGUARDAR=30
ATUALIZAR_ARGS=()

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
Uso: $(basename "$0") [opções] [-- args-do-atualizar...]

Consulta sessões autenticadas com atividade recente e jobs em execução,
para decidir com mais segurança se é hora de rodar a atualização.

Padrão: MySQL nativo (produção). Use --docker no desenvolvimento.

Opções:
  --minutos N       Janela de atividade (padrão: 10)
  --aguardar        Espera até não haver usuários/jobs ativos
  --intervalo N     Segundos entre checagens no --aguardar (padrão: 30)
  --atualizar       Se estiver livre, executa atualizar-producao.sh
  --force           Com --atualizar, segue mesmo com usuários ativos
  --docker          Banco via Docker Compose (dev)
  --native          Banco nativo (padrão)
  -h, --help        Esta ajuda

Códigos de saída (sem --aguardar / --atualizar):
  0  ambiente livre para atualizar
  1  há usuários ativos ou jobs em execução
  2  erro de configuração/consulta

Exemplos:
  $(basename "$0")
  $(basename "$0") --minutos 5 --aguardar
  $(basename "$0") --atualizar
  $(basename "$0") --atualizar -- --skip-npm
EOF
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --minutos)
                MINUTOS="$2"
                shift 2
                ;;
            --intervalo)
                INTERVALO_AGUARDAR="$2"
                shift 2
                ;;
            --aguardar)
                AGUARDAR=true
                shift
                ;;
            --atualizar)
                ATUALIZAR=true
                shift
                ;;
            --force)
                FORCE=true
                shift
                ;;
            --docker)
                MODE=docker
                shift
                ;;
            --native)
                MODE=native
                shift
                ;;
            --)
                shift
                ATUALIZAR_ARGS+=("$@")
                break
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                # Encaminha opções restantes para atualizar-producao quando --atualizar
                if [[ "$ATUALIZAR" == true ]]; then
                    ATUALIZAR_ARGS+=("$1")
                    shift
                else
                    err "Opção desconhecida: $1"
                    usage
                    exit 2
                fi
                ;;
        esac
    done

    if ! [[ "$MINUTOS" =~ ^[0-9]+$ ]] || [[ "$MINUTOS" -lt 1 ]]; then
        err "--minutos deve ser um inteiro >= 1"
        exit 2
    fi
    if ! [[ "$INTERVALO_AGUARDAR" =~ ^[0-9]+$ ]] || [[ "$INTERVALO_AGUARDAR" -lt 5 ]]; then
        err "--intervalo deve ser um inteiro >= 5"
        exit 2
    fi
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
        exit 2
    fi

    DB_NAME="$(read_env_value DB_DATABASE "$env_file" 2>/dev/null || true)"
    DB_USER="$(read_env_value DB_USERNAME "$env_file" 2>/dev/null || true)"
    DB_PASS="$(read_env_value DB_PASSWORD "$env_file" 2>/dev/null || true)"
    DB_HOST="$(read_env_value DB_HOST "$env_file" 2>/dev/null || echo 127.0.0.1)"
    DB_PORT="$(read_env_value DB_PORT "$env_file" 2>/dev/null || echo 3306)"
    SESSION_DRIVER="$(read_env_value SESSION_DRIVER "$env_file" 2>/dev/null || echo database)"
    SESSION_TABLE="$(read_env_value SESSION_TABLE "$env_file" 2>/dev/null || echo sessions)"

    if [[ -z "${DB_NAME:-}" || -z "${DB_USER:-}" ]]; then
        err "DB_DATABASE/DB_USERNAME ausentes no .env"
        exit 2
    fi

    if [[ "$SESSION_DRIVER" != "database" ]]; then
        warn "SESSION_DRIVER=$SESSION_DRIVER (este script consulta a tabela de sessões no MySQL)."
        warn "Com driver file/redis a detecção de usuários pode ficar incompleta."
    fi
}

mysql_query() {
    local sql="$1"
    local raw
    if [[ "$MODE" == "docker" ]]; then
        if ! command -v docker >/dev/null 2>&1; then
            err "docker não encontrado"
            exit 2
        fi
        if ! docker compose -f "$PROJECT_DIR/docker-compose.yml" ps --status running --services 2>/dev/null | grep -qx 'db'; then
            err "Serviço Docker 'db' não está em execução"
            exit 2
        fi
        # stderr do compose/mysql (warnings) descartado; só a última linha útil
        raw="$(
            docker compose -f "$PROJECT_DIR/docker-compose.yml" exec -T db \
                mysql -N -B -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$sql" 2>/dev/null
        )" || return 1
    else
        if ! command -v mysql >/dev/null 2>&1; then
            err "Cliente mysql não encontrado no PATH"
            exit 2
        fi
        # Em .env de Docker, DB_HOST=db — em produção nativa use 127.0.0.1
        local host="$DB_HOST"
        case "$host" in
            db|mysql|mariadb|"")
                host="127.0.0.1"
                ;;
            localhost)
                host="127.0.0.1"
                ;;
        esac
        raw="$(MYSQL_PWD="$DB_PASS" mysql -N -B \
            -h"$host" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -e "$sql" 2>/dev/null)" || return 1
    fi

    # Remove linhas vazias / ruído; mantém resultado tabular do mysql -N -B
    printf '%s\n' "$raw" | sed '/^$/d'
}

table_exists() {
    local table="$1"
    local count
    count="$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '${table}';" | tail -1 | tr -d '[:space:]' || echo 0)"
    [[ "$count" == "1" ]]
}

# Saídas em variáveis globais:
# ACTIVE_USERS_COUNT, RESERVED_JOBS_COUNT, PENDING_JOBS_COUNT, REPORT_TEXT
consultar_atividade() {
    ACTIVE_USERS_COUNT=0
    RESERVED_JOBS_COUNT=0
    PENDING_JOBS_COUNT=0
    REPORT_TEXT=""

    local since_ts
    since_ts="$(( $(date +%s) - (MINUTOS * 60) ))"

    if ! table_exists "$SESSION_TABLE"; then
        err "Tabela de sessões '${SESSION_TABLE}' não encontrada no banco ${DB_NAME}"
        exit 2
    fi

    local sess_sql
    sess_sql="
SELECT
  CONCAT(
    COALESCE(u.name, '(sem nome)'),
    ' | ',
    COALESCE(u.email, '-'),
    ' | id=',
    s.user_id,
    ' | IP ',
    COALESCE(s.ip_address, '-'),
    ' | ',
    DATE_FORMAT(FROM_UNIXTIME(s.last_activity), '%d/%m/%Y %H:%i:%s'),
    ' (há ',
    TIMESTAMPDIFF(SECOND, FROM_UNIXTIME(s.last_activity), NOW()),
    's)'
  )
FROM \`${SESSION_TABLE}\` s
LEFT JOIN users u ON u.id = s.user_id
WHERE s.user_id IS NOT NULL
  AND s.last_activity >= ${since_ts}
ORDER BY s.last_activity DESC;
"

    local sess_rows
    sess_rows="$(mysql_query "$sess_sql" || true)"
    if [[ -n "${sess_rows// }" ]]; then
        ACTIVE_USERS_COUNT="$(printf '%s\n' "$sess_rows" | sed '/^$/d' | wc -l | tr -d ' ')"
    fi

    REPORT_TEXT+="Janela: últimos ${MINUTOS} minuto(s) | banco: ${DB_NAME} | modo: ${MODE}"$'\n'
    REPORT_TEXT+="Sessões autenticadas ativas: ${ACTIVE_USERS_COUNT}"$'\n'

    if [[ "$ACTIVE_USERS_COUNT" -gt 0 ]]; then
        while IFS= read -r line; do
            [[ -z "$line" ]] && continue
            REPORT_TEXT+="  - ${line}"$'\n'
        done <<< "$sess_rows"
    fi

    if table_exists "jobs"; then
        RESERVED_JOBS_COUNT="$(mysql_query "SELECT COUNT(*) FROM jobs WHERE reserved_at IS NOT NULL;" | tr -d '[:space:]' || echo 0)"
        PENDING_JOBS_COUNT="$(mysql_query "SELECT COUNT(*) FROM jobs WHERE reserved_at IS NULL;" | tr -d '[:space:]' || echo 0)"
        REPORT_TEXT+="Jobs em execução (reserved): ${RESERVED_JOBS_COUNT}"$'\n'
        REPORT_TEXT+="Jobs na fila (pendentes): ${PENDING_JOBS_COUNT}"$'\n'

        if [[ "${RESERVED_JOBS_COUNT:-0}" -gt 0 ]]; then
            local job_rows
            job_rows="$(mysql_query "
SELECT CONCAT('#', id, ' | fila=', queue, ' | tentativas=', attempts, ' | reserved=', FROM_UNIXTIME(reserved_at))
FROM jobs
WHERE reserved_at IS NOT NULL
ORDER BY reserved_at DESC
LIMIT 20;
" || true)"
            while IFS= read -r line; do
                [[ -z "$line" ]] && continue
                REPORT_TEXT+="  - ${line}"$'\n'
            done <<< "$job_rows"
        fi
    else
        REPORT_TEXT+="Tabela jobs não encontrada (ignorado)."$'\n'
    fi
}

ambiente_livre() {
    [[ "${ACTIVE_USERS_COUNT:-0}" -eq 0 && "${RESERVED_JOBS_COUNT:-0}" -eq 0 ]]
}

imprimir_relatorio() {
    echo
    echo "========================================"
    echo " Verificação de usuários / jobs ativos"
    echo "========================================"
    printf '%s' "$REPORT_TEXT"
    echo "========================================"

    if ambiente_livre; then
        ok "Nenhum usuário autenticado ativo e nenhum job em execução."
        ok "Ambiente aparentemente livre para ./atualizar-producao.sh"
        return 0
    fi

    warn "Há atividade no sistema — atualizar agora pode interromper quem está trabalhando."
    if [[ "${ACTIVE_USERS_COUNT:-0}" -gt 0 ]]; then
        warn "${ACTIVE_USERS_COUNT} sessão(ões) autenticada(s) nos últimos ${MINUTOS} min."
    fi
    if [[ "${RESERVED_JOBS_COUNT:-0}" -gt 0 ]]; then
        warn "${RESERVED_JOBS_COUNT} job(s) em execução."
    fi
    if [[ "${PENDING_JOBS_COUNT:-0}" -gt 0 ]]; then
        log "${PENDING_JOBS_COUNT} job(s) pendente(s) na fila (não bloqueiam por padrão)."
    fi
    return 1
}

aguardar_livre() {
    log "Aguardando ambiente livre (checagem a cada ${INTERVALO_AGUARDAR}s)..."
    while true; do
        consultar_atividade
        if ambiente_livre; then
            imprimir_relatorio
            return 0
        fi
        imprimir_relatorio || true
        log "Ainda ocupado. Nova checagem em ${INTERVALO_AGUARDAR}s... (Ctrl+C para sair)"
        sleep "$INTERVALO_AGUARDAR"
    done
}

confirmar_forcar() {
    if [[ ! -t 0 ]]; then
        err "Ambiente ocupado e --force não informado (stdin não interativo)."
        return 1
    fi
    echo
    read -r -p "Mesmo assim deseja rodar atualizar-producao.sh? [s/N] " resp
    case "${resp,,}" in
        s|sim|y|yes) return 0 ;;
        *) return 1 ;;
    esac
}

rodar_atualizar() {
    local script="$PROJECT_DIR/script-manutencao/atualizar-producao.sh"
    if [[ ! -x "$script" ]]; then
        err "Script não encontrado/executável: $script"
        exit 2
    fi

    if [[ "$MODE" == "docker" ]]; then
        # Evita duplicar --docker se já veio nos args
        local has_docker=false
        local arg
        for arg in "${ATUALIZAR_ARGS[@]+"${ATUALIZAR_ARGS[@]}"}"; do
            [[ "$arg" == "--docker" ]] && has_docker=true
        done
        if [[ "$has_docker" == false ]]; then
            ATUALIZAR_ARGS=(--docker "${ATUALIZAR_ARGS[@]+"${ATUALIZAR_ARGS[@]}"}")
        fi
    fi

    log "Executando: $script ${ATUALIZAR_ARGS[*]-}"
    exec "$script" "${ATUALIZAR_ARGS[@]+"${ATUALIZAR_ARGS[@]}"}"
}

main() {
    parse_args "$@"
    load_db_config

    if [[ "$AGUARDAR" == true ]]; then
        aguardar_livre
        if [[ "$ATUALIZAR" == true ]]; then
            rodar_atualizar
        fi
        exit 0
    fi

    consultar_atividade
    if imprimir_relatorio; then
        if [[ "$ATUALIZAR" == true ]]; then
            rodar_atualizar
        fi
        exit 0
    fi

    if [[ "$ATUALIZAR" == true ]]; then
        if [[ "$FORCE" == true ]] || confirmar_forcar; then
            warn "Prosseguindo com atualização apesar da atividade."
            rodar_atualizar
        fi
        err "Atualização cancelada."
        exit 1
    fi

    exit 1
}

main "$@"
