#!/usr/bin/env bash
# Restaura banco de dados a partir de um backup SQL (ambiente Docker).
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_DIR"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
log_error()   { echo -e "${RED}[ERROR]${NC} $1"; }

if [[ -z "${1:-}" ]]; then
    log_error "Uso: $0 <arquivo_backup.sql> [nome_banco]"
    echo "Exemplo: $0 backups/backup-integrar_dalongaro-20250723_120000.sql"
    echo ""
    echo "Backups disponíveis:"
    ls -la backups/backup-*.sql 2>/dev/null || echo "Nenhum backup encontrado"
    exit 1
fi

BACKUP_FILE="$1"
DB_NAME="${2:-integrar_dalongaro}"

if [[ ! -f "$BACKUP_FILE" ]]; then
    log_error "Arquivo não encontrado: $BACKUP_FILE"
    exit 1
fi

log_info "Backup: $BACKUP_FILE"
log_info "Banco: $DB_NAME"

echo ""
echo "⚠️  Isso irá sobrescrever o banco $DB_NAME."
read -p "Continuar? (s/N): " -n 1 -r
echo
[[ $REPLY =~ ^[Ss]$ ]] || exit 0

mkdir -p backups
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
CURRENT_BACKUP="backups/backup-pre-rollback-${TIMESTAMP}.sql"

log_info "Backup do estado atual..."
docker compose exec -T db mysqldump -u root -proot "$DB_NAME" > "$CURRENT_BACKUP"
log_success "Salvo em $CURRENT_BACKUP"

log_info "Restaurando banco..."
docker compose exec -T db mysql -u root -proot "$DB_NAME" < "$BACKUP_FILE"
log_success "Banco restaurado"

log_info "Limpando caches..."
docker compose exec -T app php artisan optimize:clear
log_success "Caches limpos"

echo ""
log_success "Rollback concluído."
echo "   Restaurado de: $BACKUP_FILE"
echo "   Backup anterior: $CURRENT_BACKUP"
