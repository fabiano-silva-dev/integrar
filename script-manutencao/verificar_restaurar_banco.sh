#!/bin/bash

# Script para verificar e restaurar o banco de dados integrar_dalongaro
# Uso: ./verificar_restaurar_banco.sh [arquivo_backup.sql]

BANCO="integrar_dalongaro"
USUARIO="laravel"
SENHA="secret"
CONTAINER="integrar-db"

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Verificar se o container está rodando
if ! docker ps | grep -q "$CONTAINER"; then
    log_error "Container $CONTAINER não está rodando!"
    exit 1
fi

log_info "Verificando se o banco de dados $BANCO existe..."

# Verificar se o banco existe
BANCO_EXISTE=$(docker exec $CONTAINER mysql -u root -proot -e "SHOW DATABASES LIKE '$BANCO';" 2>/dev/null | grep -c "$BANCO" || echo "0")

if [ "$BANCO_EXISTE" -gt 0 ]; then
    log_success "Banco de dados $BANCO existe!"
    
    # Verificar se há tabelas
    TABELAS=$(docker exec $CONTAINER mysql -u root -proot -e "USE $BANCO; SHOW TABLES;" 2>/dev/null | wc -l)
    
    if [ "$TABELAS" -le 1 ]; then
        log_warning "Banco existe mas está vazio (sem tabelas)"
        RESTAURAR="s"
    else
        log_info "Banco possui $((TABELAS-1)) tabela(s)"
        read -p "Deseja restaurar do backup mesmo assim? (s/N): " -n 1 -r
        echo
        RESTAURAR="$REPLY"
    fi
else
    log_warning "Banco de dados $BANCO não existe!"
    RESTAURAR="s"
fi

if [[ ! "$RESTAURAR" =~ ^[Ss]$ ]]; then
    log_info "Operação cancelada"
    exit 0
fi

# Determinar arquivo de backup
if [ -n "$1" ]; then
    ARQUIVO_BACKUP="$1"
else
    # Procurar o backup mais recente
    ARQUIVO_BACKUP=$(ls -t backup*.sql backups/backup*.sql 2>/dev/null | head -1)
fi

if [ -z "$ARQUIVO_BACKUP" ] || [ ! -f "$ARQUIVO_BACKUP" ]; then
    log_error "Nenhum arquivo de backup encontrado!"
    echo "Backups disponíveis:"
    ls -lh backup*.sql backups/backup*.sql 2>/dev/null || echo "Nenhum backup encontrado"
    exit 1
fi

log_info "Usando backup: $ARQUIVO_BACKUP"

# Criar banco de dados se não existir
log_info "Criando banco de dados $BANCO (se não existir)..."
docker exec -i $CONTAINER mysql -u root -proot -e "CREATE DATABASE IF NOT EXISTS $BANCO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON $BANCO.* TO '$USUARIO'@'%'; FLUSH PRIVILEGES;" 2>/dev/null

if [ $? -eq 0 ]; then
    log_success "Banco de dados criado/configurado"
else
    log_error "Erro ao criar banco de dados"
    exit 1
fi

# Restaurar backup
log_info "Restaurando backup..."
docker exec -i $CONTAINER mysql -u $USUARIO -p$SENHA $BANCO < "$ARQUIVO_BACKUP"

if [ $? -eq 0 ]; then
    log_success "Backup restaurado com sucesso!"
    
    # Verificar tabelas após restauração
    TABELAS=$(docker exec $CONTAINER mysql -u root -proot -e "USE $BANCO; SHOW TABLES;" 2>/dev/null | wc -l)
    log_info "Banco agora possui $((TABELAS-1)) tabela(s)"
else
    log_error "Erro ao restaurar backup"
    exit 1
fi

echo ""
log_success "Processo concluído!"

