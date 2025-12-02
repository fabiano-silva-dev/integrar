#!/bin/bash

# Script de backup automático para o projeto Integrar
# Executar diariamente via cron
# Lê configurações do arquivo .env

set -e  # Parar em caso de erro

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Diretório do script
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# Verificar se o arquivo .env existe
if [ ! -f ".env" ]; then
    echo -e "${RED}❌ Erro: Arquivo .env não encontrado em $SCRIPT_DIR${NC}" >&2
    exit 1
fi

# Carregar variáveis do .env
# Remove comentários e linhas vazias, depois exporta as variáveis
export $(grep -v '^#' .env | grep -v '^$' | xargs)

# Variáveis do banco de dados (com valores padrão)
DB_NAME="${DB_DATABASE:-integrar_dalongaro}"
DB_USER="${DB_USERNAME:-laravel}"
DB_PASS="${DB_PASSWORD:-secret}"
MYSQL_ROOT_PASS="${MYSQL_ROOT_PASSWORD:-root}"
CONTAINER_DB="${DB_HOST:-db}"

# Se DB_HOST for um IP ou hostname, usar o container name
if [ "$CONTAINER_DB" != "db" ] && [ "$CONTAINER_DB" != "localhost" ] && [ "$CONTAINER_DB" != "127.0.0.1" ]; then
    CONTAINER_DB="integrar-db"
fi

# Verificar se o container está rodando
if ! docker ps | grep -q "$CONTAINER_DB"; then
    echo -e "${RED}❌ Erro: Container $CONTAINER_DB não está rodando!${NC}" >&2
    exit 1
fi

# Configurações de backup
DATA=$(date +%Y%m%d_%H%M%S)
DIRETORIO_BACKUP="$SCRIPT_DIR/backups"
ARQUIVO_BACKUP="$DIRETORIO_BACKUP/backup-${DB_NAME}-${DATA}.sql"

# Criar diretório de backup se não existir
mkdir -p "$DIRETORIO_BACKUP"

# Log
echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} Iniciando backup automático"
echo -e "${BLUE}Banco de dados:${NC} $DB_NAME"
echo -e "${BLUE}Container:${NC} $CONTAINER_DB"
echo -e "${BLUE}Arquivo:${NC} $ARQUIVO_BACKUP"

# Fazer backup do banco
if docker-compose exec -T "$CONTAINER_DB" mysqldump -u root -p"$MYSQL_ROOT_PASS" "$DB_NAME" > "$ARQUIVO_BACKUP" 2>/dev/null; then
    # Verificar se o arquivo foi criado e não está vazio
    if [ -f "$ARQUIVO_BACKUP" ] && [ -s "$ARQUIVO_BACKUP" ]; then
        TAMANHO=$(du -h "$ARQUIVO_BACKUP" | cut -f1)
        echo -e "${GREEN}✅ Backup criado com sucesso: $ARQUIVO_BACKUP ($TAMANHO)${NC}"
        
        # Manter apenas os últimos 7 backups
        cd "$DIRETORIO_BACKUP"
        BACKUPS_REMOVIDOS=$(ls -t backup-${DB_NAME}-*.sql 2>/dev/null | tail -n +8 | wc -l)
        if [ "$BACKUPS_REMOVIDOS" -gt 0 ]; then
            ls -t backup-${DB_NAME}-*.sql 2>/dev/null | tail -n +8 | xargs -r rm
            echo -e "${YELLOW}🗑️  $BACKUPS_REMOVIDOS backup(s) antigo(s) removido(s) (mantidos últimos 7)${NC}"
        fi
        
        echo -e "${GREEN}Backup concluído em $(date +'%Y-%m-%d %H:%M:%S')${NC}"
        exit 0
    else
        echo -e "${RED}❌ Erro: Arquivo de backup está vazio ou não foi criado!${NC}" >&2
        [ -f "$ARQUIVO_BACKUP" ] && rm -f "$ARQUIVO_BACKUP"
        exit 1
    fi
else
    echo -e "${RED}❌ Erro ao criar backup!${NC}" >&2
    echo -e "${YELLOW}Verifique se o banco de dados '$DB_NAME' existe e se as credenciais estão corretas.${NC}" >&2
    [ -f "$ARQUIVO_BACKUP" ] && rm -f "$ARQUIVO_BACKUP"
    exit 1
fi 