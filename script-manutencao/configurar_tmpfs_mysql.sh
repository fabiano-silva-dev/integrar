#!/bin/bash

# =============================================================================
# Script: Configurar tmpfs para MySQL
# Descrição: Configura tmpfs para /tmp do MySQL e caches temporários
# Autor: Sistema Integrar
# Data: $(date +%Y-%m-%d)
# =============================================================================

set -e

echo "🚀 Configurando tmpfs para otimização do MySQL..."

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

# Configurações
TMPFS_SIZE="1G"
MYSQL_TMP_DIR="/var/lib/mysql-tmp"
MYSQL_CACHE_DIR="/var/lib/mysql-cache"
FSTAB_BACKUP="/etc/fstab.backup.$(date +%Y%m%d_%H%M%S)"

# Verificar RAM disponível
log "Verificando RAM disponível..."
TOTAL_RAM=$(free -g | awk '/^Mem:/{print $2}')
log "RAM total disponível: ${TOTAL_RAM}GB"

if [ "$TOTAL_RAM" -lt 4 ]; then
    warning "RAM total é menor que 4GB. Considere reduzir o tamanho do tmpfs."
    TMPFS_SIZE="512M"
    log "Ajustando tmpfs para $TMPFS_SIZE"
fi

# Fazer backup do fstab
log "Fazendo backup do /etc/fstab..."
cp /etc/fstab "$FSTAB_BACKUP"
log "Backup salvo em: $FSTAB_BACKUP"

# Verificar se MySQL está rodando
if systemctl is-active --quiet mysql; then
    log "MySQL está rodando. Será necessário reiniciar após a configuração."
    MYSQL_RUNNING=true
else
    log "MySQL não está rodando."
    MYSQL_RUNNING=false
fi

# Parar MySQL se estiver rodando
if [ "$MYSQL_RUNNING" = true ]; then
    log "Parando MySQL..."
    systemctl stop mysql
fi

# Criar diretórios para tmpfs
log "Criando diretórios para tmpfs..."
mkdir -p "$MYSQL_TMP_DIR"
mkdir -p "$MYSQL_CACHE_DIR"

# Configurar permissões
log "Configurando permissões..."
chown mysql:mysql "$MYSQL_TMP_DIR"
chown mysql:mysql "$MYSQL_CACHE_DIR"
chmod 755 "$MYSQL_TMP_DIR"
chmod 755 "$MYSQL_CACHE_DIR"

# Verificar se já existem entradas tmpfs no fstab
if grep -q "tmpfs.*$MYSQL_TMP_DIR" /etc/fstab; then
    warning "Entrada tmpfs para $MYSQL_TMP_DIR já existe no fstab"
else
    log "Adicionando tmpfs para $MYSQL_TMP_DIR no fstab..."
    echo "tmpfs $MYSQL_TMP_DIR tmpfs defaults,size=$TMPFS_SIZE,uid=mysql,gid=mysql,mode=755,noatime 0 0" >> /etc/fstab
fi

if grep -q "tmpfs.*$MYSQL_CACHE_DIR" /etc/fstab; then
    warning "Entrada tmpfs para $MYSQL_CACHE_DIR já existe no fstab"
else
    log "Adicionando tmpfs para $MYSQL_CACHE_DIR no fstab..."
    echo "tmpfs $MYSQL_CACHE_DIR tmpfs defaults,size=512M,uid=mysql,gid=mysql,mode=755,noatime 0 0" >> /etc/fstab
fi

# Montar tmpfs imediatamente
log "Montando tmpfs..."
mount -a

# Verificar se foi montado corretamente
if mount | grep -q "$MYSQL_TMP_DIR"; then
    log "✅ tmpfs para $MYSQL_TMP_DIR montado com sucesso!"
else
    error "❌ Falha ao montar tmpfs para $MYSQL_TMP_DIR"
fi

if mount | grep -q "$MYSQL_CACHE_DIR"; then
    log "✅ tmpfs para $MYSQL_CACHE_DIR montado com sucesso!"
else
    error "❌ Falha ao montar tmpfs para $MYSQL_CACHE_DIR"
fi

# Configurar MySQL para usar tmpfs
log "Configurando MySQL para usar tmpfs..."

MYSQL_CONFIG_FILE="/etc/mysql/mysql.conf.d/mysqld.cnf"
BACKUP_DIR="/opt/mysql_backups"

# Criar diretório de backup
mkdir -p "$BACKUP_DIR"

# Fazer backup da configuração
cp "$MYSQL_CONFIG_FILE" "${BACKUP_DIR}/mysqld.cnf.backup.tmpfs.$(date +%Y%m%d_%H%M%S)"

# Adicionar configurações tmpfs
log "Adicionando configurações tmpfs ao MySQL..."

# Remover configurações existentes se houver
sed -i '/^tmpdir/d' "$MYSQL_CONFIG_FILE"
sed -i '/^innodb_tmpdir/d' "$MYSQL_CONFIG_FILE"

# Adicionar novas configurações
if grep -q "^\[mysqld\]" "$MYSQL_CONFIG_FILE"; then
    # Adicionar após [mysqld]
    sed -i '/^\[mysqld\]/a tmpdir = '"$MYSQL_TMP_DIR"'' "$MYSQL_CONFIG_FILE"
    sed -i '/^\[mysqld\]/a innodb_tmpdir = '"$MYSQL_TMP_DIR"'' "$MYSQL_CONFIG_FILE"
else
    # Adicionar no final do arquivo
    echo "" >> "$MYSQL_CONFIG_FILE"
    echo "[mysqld]" >> "$MYSQL_CONFIG_FILE"
    echo "tmpdir = $MYSQL_TMP_DIR" >> "$MYSQL_CONFIG_FILE"
    echo "innodb_tmpdir = $MYSQL_TMP_DIR" >> "$MYSQL_CONFIG_FILE"
fi

# Adicionar otimizações relacionadas
log "Adicionando otimizações relacionadas..."

if ! grep -q "innodb_use_native_aio" "$MYSQL_CONFIG_FILE"; then
    echo "innodb_use_native_aio = 1" >> "$MYSQL_CONFIG_FILE"
fi

if ! grep -q "innodb_read_io_threads" "$MYSQL_CONFIG_FILE"; then
    echo "innodb_read_io_threads = 4" >> "$MYSQL_CONFIG_FILE"
fi

if ! grep -q "innodb_write_io_threads" "$MYSQL_CONFIG_FILE"; then
    echo "innodb_write_io_threads = 4" >> "$MYSQL_CONFIG_FILE"
fi

# Validar configuração
log "Validando configuração..."
if grep -q "tmpdir = $MYSQL_TMP_DIR" "$MYSQL_CONFIG_FILE"; then
    log "✅ Configuração tmpfs aplicada com sucesso!"
else
    error "❌ Falha ao aplicar configuração tmpfs"
fi

# Mostrar configuração aplicada
log "Configuração tmpfs aplicada:"
grep -A 5 "tmpdir" "$MYSQL_CONFIG_FILE"

# Verificar status dos tmpfs
log "Status dos tmpfs:"
echo ""
echo "=== TMPFS MONTADOS ==="
df -h | grep tmpfs
echo ""
echo "=== PERMISSÕES DOS DIRETÓRIOS ==="
ls -la "$MYSQL_TMP_DIR" "$MYSQL_CACHE_DIR"

# Iniciar MySQL se estava rodando
if [ "$MYSQL_RUNNING" = true ]; then
    log "Iniciando MySQL..."
    systemctl start mysql
    
    if systemctl is-active --quiet mysql; then
        log "✅ MySQL iniciado com sucesso!"
    else
        error "❌ Falha ao iniciar MySQL. Verifique os logs: journalctl -u mysql"
    fi
fi

log "✅ Configuração tmpfs concluída com sucesso!"
log "💾 Backup do fstab salvo em: $FSTAB_BACKUP"
log "💾 Backup da configuração MySQL salvo em: $BACKUP_DIR"

echo ""
log "📋 Próximos passos:"
echo "1. Verifique se MySQL está rodando: systemctl status mysql"
echo "2. Teste a configuração: mysql -e \"SHOW VARIABLES LIKE 'tmpdir';\""
echo "3. Monitore performance: watch -n 1 'df -h | grep tmpfs'"
echo "4. Verifique logs: journalctl -f -u mysql"





