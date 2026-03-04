#!/bin/bash

# =============================================================================
# Script Master: Otimização Completa MySQL - Sistema Integrar
# Descrição: Executa todas as otimizações de performance do MySQL
# Autor: Sistema Integrar
# Data: $(date +%Y-%m-%d)
# =============================================================================

set -e

echo "🚀 Otimização Completa MySQL - Sistema Integrar"
echo "=============================================="

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
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

info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

# Verificar se está rodando como root
if [[ $EUID -ne 0 ]]; then
   error "Este script deve ser executado como root (use sudo)"
   exit 1
fi

# Diretório dos scripts (onde este script está)
SCRIPTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Verificar se os scripts existem
REQUIRED_SCRIPTS=(
    "otimizar_mysql_buffer_pool.sh"
    "criar_swapfile_2gb.sh"
    "configurar_tmpfs_mysql.sh"
    "instalar_mysqltuner.sh"
    "monitorar_mysql_performance.sh"
)

log "Verificando scripts necessários..."
for script in "${REQUIRED_SCRIPTS[@]}"; do
    if [ ! -f "$SCRIPTS_DIR/$script" ]; then
        error "Script não encontrado: $SCRIPTS_DIR/$script"
        exit 1
    fi
    log "✅ $script encontrado"
done

# Menu de opções
echo ""
info "Escolha uma opção:"
echo "1. 🚀 Executar TODAS as otimizações (recomendado)"
echo "2. 🔧 Apenas Buffer Pool (4GB)"
echo "3. 💾 Apenas Swapfile (2GB)"
echo "4. 🚀 Apenas tmpfs"
echo "5. 📊 Apenas MySQLTuner"
echo "6. 🔍 Apenas Monitoramento"
echo "7. ❌ Cancelar"
echo ""

read -p "Digite sua opção (1-7): " choice

case $choice in
    1)
        log "Executando TODAS as otimizações..."
        echo ""
        
        # 1. Buffer Pool
        log "1/5 - Configurando Buffer Pool..."
        bash "$SCRIPTS_DIR/otimizar_mysql_buffer_pool.sh"
        echo ""
        
        # 2. Swapfile
        log "2/5 - Criando Swapfile..."
        bash "$SCRIPTS_DIR/criar_swapfile_2gb.sh"
        echo ""
        
        # 3. tmpfs
        log "3/5 - Configurando tmpfs..."
        bash "$SCRIPTS_DIR/configurar_tmpfs_mysql.sh"
        echo ""
        
        # 4. MySQLTuner
        log "4/5 - Instalando MySQLTuner..."
        bash "$SCRIPTS_DIR/instalar_mysqltuner.sh"
        echo ""
        
        # 5. Monitoramento
        log "5/5 - Executando monitoramento..."
        bash "$SCRIPTS_DIR/monitorar_mysql_performance.sh"
        ;;
        
    2)
        log "Executando otimização do Buffer Pool..."
        bash "$SCRIPTS_DIR/otimizar_mysql_buffer_pool.sh"
        ;;
        
    3)
        log "Criando Swapfile..."
        bash "$SCRIPTS_DIR/criar_swapfile_2gb.sh"
        ;;
        
    4)
        log "Configurando tmpfs..."
        bash "$SCRIPTS_DIR/configurar_tmpfs_mysql.sh"
        ;;
        
    5)
        log "Instalando MySQLTuner..."
        bash "$SCRIPTS_DIR/instalar_mysqltuner.sh"
        ;;
        
    6)
        log "Executando monitoramento..."
        bash "$SCRIPTS_DIR/monitorar_mysql_performance.sh"
        ;;
        
    7)
        log "Operação cancelada pelo usuário"
        exit 0
        ;;
        
    *)
        error "Opção inválida"
        exit 1
        ;;
esac

# Verificar se MySQL está rodando após as otimizações
log "Verificando status do MySQL..."
if systemctl is-active --quiet mysql; then
    log "✅ MySQL está rodando corretamente"
else
    error "❌ MySQL não está rodando. Verifique os logs: journalctl -u mysql"
    exit 1
fi

# Mostrar resumo final
echo ""
log "📋 Resumo das Otimizações Aplicadas:"
echo "=================================="

info "🔧 Buffer Pool Size:"
mysql -e "SHOW VARIABLES LIKE 'innodb_buffer_pool_size';" 2>/dev/null || echo "Erro ao conectar"

info "💾 Swap:"
free -h | grep Swap

info "🚀 tmpfs:"
df -h | grep tmpfs

info "📊 MySQLTuner:"
if command -v mysqltuner &> /dev/null; then
    echo "✅ Instalado"
else
    echo "❌ Não instalado"
fi

echo ""
log "✅ Otimização concluída com sucesso!"
log "📁 Relatórios salvos em: /opt/mysql_reports"
log "📝 Logs salvos em: /var/log/"

echo ""
info "🔄 Próximos passos recomendados:"
echo "1. Monitore a performance: mysqltuner"
echo "2. Verifique logs: journalctl -f -u mysql"
echo "3. Execute monitoramento: $SCRIPTS_DIR/monitorar_mysql_performance.sh"
echo "4. Configure alertas se necessário"

echo ""
warning "⚠️  IMPORTANTE: Reinicie o servidor após todas as otimizações para garantir que todas as configurações sejam aplicadas corretamente."



