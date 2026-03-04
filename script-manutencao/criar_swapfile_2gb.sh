#!/bin/bash

# =============================================================================
# Script: Criar Swapfile de 2GB
# Descrição: Cria swapfile de 2GB para resolver picos de OOM
# Autor: Sistema Integrar
# Data: $(date +%Y-%m-%d)
# =============================================================================

set -e

echo "🚀 Criando swapfile de 2GB para otimização do MySQL..."

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
SWAPFILE_SIZE="2G"
SWAPFILE_PATH="/swapfile"
SWAPFILE_SIZE_MB=2048

# Verificar swap atual
log "Verificando swap atual..."
CURRENT_SWAP=$(free -h | grep Swap | awk '{print $2}')
log "Swap atual: $CURRENT_SWAP"

# Verificar se já existe swapfile
if [ -f "$SWAPFILE_PATH" ]; then
    warning "Swapfile já existe em $SWAPFILE_PATH"
    read -p "Deseja substituir? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        log "Operação cancelada pelo usuário"
        exit 0
    fi
    log "Removendo swapfile existente..."
    swapoff "$SWAPFILE_PATH" 2>/dev/null || true
    rm -f "$SWAPFILE_PATH"
fi

# Verificar espaço em disco disponível
log "Verificando espaço em disco disponível..."
AVAILABLE_SPACE=$(df / | awk 'NR==2 {print $4}')
REQUIRED_SPACE=$((SWAPFILE_SIZE_MB * 1024)) # Convertendo para KB

if [ "$AVAILABLE_SPACE" -lt "$REQUIRED_SPACE" ]; then
    error "Espaço insuficiente em disco. Necessário: ${SWAPFILE_SIZE_MB}MB, Disponível: $((AVAILABLE_SPACE/1024))MB"
    exit 1
fi

log "Espaço disponível: $((AVAILABLE_SPACE/1024))MB ✅"

# Criar swapfile
log "Criando swapfile de $SWAPFILE_SIZE..."
fallocate -l "$SWAPFILE_SIZE" "$SWAPFILE_PATH"

# Verificar se foi criado corretamente
if [ ! -f "$SWAPFILE_PATH" ]; then
    error "Falha ao criar swapfile"
    exit 1
fi

# Configurar permissões
log "Configurando permissões..."
chmod 600 "$SWAPFILE_PATH"

# Formatar como swap
log "Formatando como swap..."
mkswap "$SWAPFILE_PATH"

# Ativar swap
log "Ativando swap..."
swapon "$SWAPFILE_PATH"

# Verificar se foi ativado
if swapon --show | grep -q "$SWAPFILE_PATH"; then
    log "✅ Swapfile ativado com sucesso!"
else
    error "❌ Falha ao ativar swapfile"
    exit 1
fi

# Configurar para ativar automaticamente no boot
log "Configurando ativação automática no boot..."

# Verificar se já existe entrada no fstab
if ! grep -q "$SWAPFILE_PATH" /etc/fstab; then
    echo "$SWAPFILE_PATH none swap sw 0 0" >> /etc/fstab
    log "✅ Entrada adicionada ao /etc/fstab"
else
    log "ℹ️  Entrada já existe no /etc/fstab"
fi

# Configurar swappiness para MySQL
log "Configurando swappiness otimizada para MySQL..."
echo "vm.swappiness = 10" >> /etc/sysctl.conf
echo "vm.vfs_cache_pressure = 50" >> /etc/sysctl.conf

# Aplicar configurações imediatamente
sysctl vm.swappiness=10
sysctl vm.vfs_cache_pressure=50

# Verificar configuração final
log "Verificando configuração final..."
echo ""
echo "=== STATUS DO SWAP ==="
free -h
echo ""
echo "=== SWAPFILES ATIVOS ==="
swapon --show
echo ""
echo "=== CONFIGURAÇÕES DE SWAPPINESS ==="
sysctl vm.swappiness vm.vfs_cache_pressure

# Mostrar informações de uso
log "📊 Informações do swapfile:"
ls -lh "$SWAPFILE_PATH"

log "✅ Swapfile de 2GB criado e configurado com sucesso!"
log "💡 O sistema agora tem proteção contra OOM (Out of Memory)"
log "🔧 Swappiness configurada para 10 (otimizada para MySQL)"

echo ""
log "📋 Próximos passos recomendados:"
echo "1. Reinicie o MySQL: systemctl restart mysql"
echo "2. Monitore o uso de swap: watch -n 1 'free -h'"
echo "3. Verifique logs do sistema: journalctl -f"

