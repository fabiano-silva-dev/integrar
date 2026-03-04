#!/bin/bash

# =============================================================================
# Script: Otimizar MySQL Buffer Pool Size
# Descrição: Configura innodb_buffer_pool_size para 4GB
# Autor: Sistema Integrar
# Data: $(date +%Y-%m-%d)
# =============================================================================

set -e

echo "🚀 Iniciando otimização do MySQL Buffer Pool Size..."

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Função para log
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

error() {
    echo -e "${RED}[ERRO]${NC} $1" >&2
}

warning() {
    echo -e "${YELLOW}[AVISO]${NC} $1"
}

# Verificar se está rodando como root
if [[ $EUID -ne 0 ]]; then
   error "Este script deve ser executado como root (use sudo)"
   exit 1
fi

# Verificar RAM disponível
log "Verificando RAM disponível..."
TOTAL_RAM=$(free -g | awk '/^Mem:/{print $2}')
log "RAM total disponível: ${TOTAL_RAM}GB"

if [ "$TOTAL_RAM" -lt 8 ]; then
    warning "RAM total é menor que 8GB. Considere ajustar o buffer pool size."
    read -p "Deseja continuar mesmo assim? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Configurações
MYSQL_CONFIG_FILE="/etc/mysql/mysql.conf.d/mysqld.cnf"
BUFFER_POOL_SIZE="4G"
BACKUP_DIR="/opt/mysql_backups"

# Criar diretório de backup
mkdir -p "$BACKUP_DIR"

# Fazer backup da configuração atual
log "Fazendo backup da configuração atual..."
cp "$MYSQL_CONFIG_FILE" "${BACKUP_DIR}/mysqld.cnf.backup.$(date +%Y%m%d_%H%M%S)"

# Verificar se o arquivo de configuração existe
if [ ! -f "$MYSQL_CONFIG_FILE" ]; then
    error "Arquivo de configuração MySQL não encontrado: $MYSQL_CONFIG_FILE"
    error "Verifique se o MySQL está instalado corretamente"
    exit 1
fi

# Verificar configuração atual
log "Verificando configuração atual do buffer pool..."
CURRENT_BUFFER_POOL=$(grep -i "innodb_buffer_pool_size" "$MYSQL_CONFIG_FILE" | grep -v "^#" | awk '{print $3}' || echo "não configurado")
log "Buffer pool atual: $CURRENT_BUFFER_POOL"

# Adicionar ou atualizar configuração
log "Configurando innodb_buffer_pool_size para $BUFFER_POOL_SIZE..."

# Remover configuração existente se houver
sed -i '/^innodb_buffer_pool_size/d' "$MYSQL_CONFIG_FILE"

# Adicionar nova configuração na seção [mysqld]
if grep -q "^\[mysqld\]" "$MYSQL_CONFIG_FILE"; then
    # Adicionar após [mysqld]
    sed -i '/^\[mysqld\]/a innodb_buffer_pool_size = '"$BUFFER_POOL_SIZE"'' "$MYSQL_CONFIG_FILE"
else
    # Adicionar no final do arquivo
    echo "" >> "$MYSQL_CONFIG_FILE"
    echo "[mysqld]" >> "$MYSQL_CONFIG_FILE"
    echo "innodb_buffer_pool_size = $BUFFER_POOL_SIZE" >> "$MYSQL_CONFIG_FILE"
fi

# Adicionar outras otimizações relacionadas
log "Adicionando otimizações complementares..."

# Verificar se já existem essas configurações
if ! grep -q "innodb_buffer_pool_instances" "$MYSQL_CONFIG_FILE"; then
    echo "innodb_buffer_pool_instances = 4" >> "$MYSQL_CONFIG_FILE"
fi

if ! grep -q "innodb_log_file_size" "$MYSQL_CONFIG_FILE"; then
    echo "innodb_log_file_size = 256M" >> "$MYSQL_CONFIG_FILE"
fi

if ! grep -q "innodb_log_buffer_size" "$MYSQL_CONFIG_FILE"; then
    echo "innodb_log_buffer_size = 16M" >> "$MYSQL_CONFIG_FILE"
fi

if ! grep -q "innodb_flush_log_at_trx_commit" "$MYSQL_CONFIG_FILE"; then
    echo "innodb_flush_log_at_trx_commit = 2" >> "$MYSQL_CONFIG_FILE"
fi

# Validar configuração
log "Validando configuração..."
if grep -q "innodb_buffer_pool_size = $BUFFER_POOL_SIZE" "$MYSQL_CONFIG_FILE"; then
    log "✅ Configuração aplicada com sucesso!"
else
    error "❌ Falha ao aplicar configuração"
    exit 1
fi

# Mostrar configuração aplicada
log "Configuração aplicada:"
grep -A 10 "innodb_buffer_pool_size" "$MYSQL_CONFIG_FILE"

# Instruções para reiniciar
echo ""
log "📋 Próximos passos:"
echo "1. Reinicie o MySQL: systemctl restart mysql"
echo "2. Verifique se iniciou corretamente: systemctl status mysql"
echo "3. Teste a configuração: mysql -e \"SHOW VARIABLES LIKE 'innodb_buffer_pool_size';\""
echo ""
log "💾 Backup salvo em: $BACKUP_DIR"

log "✅ Script concluído com sucesso!"





