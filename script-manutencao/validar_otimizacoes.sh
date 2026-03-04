#!/bin/bash

# =============================================================================
# Script: Validar Otimizações MySQL
# Descrição: Valida se todas as otimizações foram aplicadas corretamente
# Autor: Sistema Integrar
# Data: $(date +%Y-%m-%d)
# =============================================================================

set -e

echo "🔍 Validando Otimizações MySQL - Sistema Integrar"
echo "================================================"

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

success() {
    echo -e "${GREEN}✅ $1${NC}"
}

fail() {
    echo -e "${RED}❌ $1${NC}"
}

# Contadores
TOTAL_CHECKS=0
PASSED_CHECKS=0
FAILED_CHECKS=0

# Função para verificar configuração MySQL
check_mysql_config() {
    local config_name="$1"
    local expected_value="$2"
    local description="$3"
    
    TOTAL_CHECKS=$((TOTAL_CHECKS + 1))
    
    local actual_value=$(mysql -e "SHOW VARIABLES LIKE '$config_name';" 2>/dev/null | awk '{print $2}' | tail -1)
    
    if [ "$actual_value" = "$expected_value" ]; then
        success "$description: $actual_value"
        PASSED_CHECKS=$((PASSED_CHECKS + 1))
    else
        fail "$description: Esperado '$expected_value', encontrado '$actual_value'"
        FAILED_CHECKS=$((FAILED_CHECKS + 1))
    fi
}

# Função para verificar arquivo
check_file() {
    local file_path="$1"
    local description="$2"
    
    TOTAL_CHECKS=$((TOTAL_CHECKS + 1))
    
    if [ -f "$file_path" ]; then
        success "$description: Arquivo existe"
        PASSED_CHECKS=$((PASSED_CHECKS + 1))
    else
        fail "$description: Arquivo não encontrado"
        FAILED_CHECKS=$((FAILED_CHECKS + 1))
    fi
}

# Função para verificar comando
check_command() {
    local command="$1"
    local description="$2"
    
    TOTAL_CHECKS=$((TOTAL_CHECKS + 1))
    
    if command -v "$command" &> /dev/null; then
        success "$description: Comando disponível"
        PASSED_CHECKS=$((PASSED_CHECKS + 1))
    else
        fail "$description: Comando não encontrado"
        FAILED_CHECKS=$((FAILED_CHECKS + 1))
    fi
}

# Função para verificar serviço
check_service() {
    local service="$1"
    local description="$2"
    
    TOTAL_CHECKS=$((TOTAL_CHECKS + 1))
    
    if systemctl is-active --quiet "$service"; then
        success "$description: Serviço ativo"
        PASSED_CHECKS=$((PASSED_CHECKS + 1))
    else
        fail "$description: Serviço inativo"
        FAILED_CHECKS=$((FAILED_CHECKS + 1))
    fi
}

# Função para verificar montagem
check_mount() {
    local mount_point="$1"
    local description="$2"
    
    TOTAL_CHECKS=$((TOTAL_CHECKS + 1))
    
    if mount | grep -q "$mount_point"; then
        success "$description: Montado"
        PASSED_CHECKS=$((PASSED_CHECKS + 1))
    else
        fail "$description: Não montado"
        FAILED_CHECKS=$((FAILED_CHECKS + 1))
    fi
}

echo ""
log "1. Verificando Status do MySQL..."
echo "================================"

check_service "mysql" "MySQL"

if systemctl is-active --quiet mysql; then
    log "MySQL está rodando. Continuando validação..."
else
    error "MySQL não está rodando. Execute: systemctl start mysql"
    exit 1
fi

echo ""
log "2. Verificando Configurações do Buffer Pool..."
echo "============================================="

# Verificar buffer pool size (deve ser 4GB = 4294967296 bytes)
BUFFER_POOL_SIZE=$(mysql -e "SHOW VARIABLES LIKE 'innodb_buffer_pool_size';" 2>/dev/null | awk '{print $2}' | tail -1)
TOTAL_CHECKS=$((TOTAL_CHECKS + 1))

if [ "$BUFFER_POOL_SIZE" -ge 4000000000 ]; then
    success "Buffer Pool Size: $BUFFER_POOL_SIZE bytes (>= 4GB)"
    PASSED_CHECKS=$((PASSED_CHECKS + 1))
else
    fail "Buffer Pool Size: $BUFFER_POOL_SIZE bytes (< 4GB)"
    FAILED_CHECKS=$((FAILED_CHECKS + 1))
fi

check_mysql_config "innodb_buffer_pool_instances" "4" "Buffer Pool Instances"

echo ""
log "3. Verificando Configurações tmpfs..."
echo "===================================="

check_mount "/var/lib/mysql-tmp" "tmpfs MySQL Temp"
check_mount "/var/lib/mysql-cache" "tmpfs MySQL Cache"

# Verificar se MySQL está usando tmpfs
TMPDIR=$(mysql -e "SHOW VARIABLES LIKE 'tmpdir';" 2>/dev/null | awk '{print $2}' | tail -1)
TOTAL_CHECKS=$((TOTAL_CHECKS + 1))

if [ "$TMPDIR" = "/var/lib/mysql-tmp" ]; then
    success "MySQL tmpdir: $TMPDIR"
    PASSED_CHECKS=$((PASSED_CHECKS + 1))
else
    fail "MySQL tmpdir: $TMPDIR (esperado: /var/lib/mysql-tmp)"
    FAILED_CHECKS=$((FAILED_CHECKS + 1))
fi

echo ""
log "4. Verificando Swapfile..."
echo "========================="

check_file "/swapfile" "Swapfile"

# Verificar se swap está ativo
SWAP_ACTIVE=$(swapon --show | grep -c "/swapfile" || echo "0")
TOTAL_CHECKS=$((TOTAL_CHECKS + 1))

if [ "$SWAP_ACTIVE" -gt 0 ]; then
    success "Swapfile ativo"
    PASSED_CHECKS=$((PASSED_CHECKS + 1))
else
    fail "Swapfile não ativo"
    FAILED_CHECKS=$((FAILED_CHECKS + 1))
fi

# Verificar tamanho do swap
SWAP_SIZE=$(free | grep Swap | awk '{print $2}')
TOTAL_CHECKS=$((TOTAL_CHECKS + 1))

if [ "$SWAP_SIZE" -ge 2000000 ]; then  # 2GB em KB
    success "Tamanho do swap: $((SWAP_SIZE/1024))MB (>= 2GB)"
    PASSED_CHECKS=$((PASSED_CHECKS + 1))
else
    fail "Tamanho do swap: $((SWAP_SIZE/1024))MB (< 2GB)"
    FAILED_CHECKS=$((FAILED_CHECKS + 1))
fi

echo ""
log "5. Verificando MySQLTuner..."
echo "==========================="

check_command "mysqltuner" "MySQLTuner"
check_command "mysql-status" "MySQL Status"
check_file "/opt/mysqltuner/mysqltuner.pl" "Script MySQLTuner"

echo ""
log "6. Verificando Diretórios e Arquivos..."
echo "======================================"

check_file "/opt/mysql_reports" "Diretório de Relatórios"
check_file "/opt/mysql_backups" "Diretório de Backups"
check_file "/etc/cron.d/mysql-monitor" "Cron Job de Monitoramento"

echo ""
log "7. Verificando Configurações de Sistema..."
echo "========================================="

# Verificar swappiness
SWAPPINESS=$(sysctl vm.swappiness | awk '{print $3}')
TOTAL_CHECKS=$((TOTAL_CHECKS + 1))

if [ "$SWAPPINESS" = "10" ]; then
    success "Swappiness: $SWAPPINESS"
    PASSED_CHECKS=$((PASSED_CHECKS + 1))
else
    fail "Swappiness: $SWAPPINESS (esperado: 10)"
    FAILED_CHECKS=$((FAILED_CHECKS + 1))
fi

# Verificar vfs_cache_pressure
CACHE_PRESSURE=$(sysctl vm.vfs_cache_pressure | awk '{print $3}')
TOTAL_CHECKS=$((TOTAL_CHECKS + 1))

if [ "$CACHE_PRESSURE" = "50" ]; then
    success "VFS Cache Pressure: $CACHE_PRESSURE"
    PASSED_CHECKS=$((PASSED_CHECKS + 1))
else
    fail "VFS Cache Pressure: $CACHE_PRESSURE (esperado: 50)"
    FAILED_CHECKS=$((FAILED_CHECKS + 1))
fi

echo ""
log "8. Verificando Performance Básica..."
echo "==================================="

# Verificar conexões
THREADS_CONNECTED=$(mysql -e "SHOW STATUS LIKE 'Threads_connected';" 2>/dev/null | awk '{print $2}' | tail -1)
MAX_CONNECTIONS=$(mysql -e "SHOW VARIABLES LIKE 'max_connections';" 2>/dev/null | awk '{print $2}' | tail -1)

TOTAL_CHECKS=$((TOTAL_CHECKS + 1))
CONNECTION_PERCENT=$((THREADS_CONNECTED * 100 / MAX_CONNECTIONS))

if [ "$CONNECTION_PERCENT" -lt 80 ]; then
    success "Conexões: $THREADS_CONNECTED/$MAX_CONNECTIONS ($CONNECTION_PERCENT%)"
    PASSED_CHECKS=$((PASSED_CHECKS + 1))
else
    warning "Conexões: $THREADS_CONNECTED/$MAX_CONNECTIONS ($CONNECTION_PERCENT%) - Alto uso"
    PASSED_CHECKS=$((PASSED_CHECKS + 1))
fi

# Verificar slow queries
SLOW_QUERIES=$(mysql -e "SHOW STATUS LIKE 'Slow_queries';" 2>/dev/null | awk '{print $2}' | tail -1)
TOTAL_CHECKS=$((TOTAL_CHECKS + 1))

if [ "$SLOW_QUERIES" -lt 100 ]; then
    success "Slow Queries: $SLOW_QUERIES (< 100)"
    PASSED_CHECKS=$((PASSED_CHECKS + 1))
else
    warning "Slow Queries: $SLOW_QUERIES (>= 100) - Muitas queries lentas"
    PASSED_CHECKS=$((PASSED_CHECKS + 1))
fi

echo ""
log "9. Resumo da Validação..."
echo "========================"

echo ""
info "📊 Estatísticas:"
echo "   Total de verificações: $TOTAL_CHECKS"
echo "   ✅ Aprovadas: $PASSED_CHECKS"
echo "   ❌ Falharam: $FAILED_CHECKS"

PERCENTAGE=$((PASSED_CHECKS * 100 / TOTAL_CHECKS))
echo "   📈 Taxa de sucesso: $PERCENTAGE%"

echo ""
if [ "$FAILED_CHECKS" -eq 0 ]; then
    success "🎉 Todas as otimizações foram aplicadas com sucesso!"
    echo ""
    info "📋 Próximos passos:"
    echo "   1. Monitore a performance: mysqltuner"
    echo "   2. Execute monitoramento: $(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/monitorar_mysql_performance.sh"
    echo "   3. Configure alertas se necessário"
    echo "   4. Reinicie o servidor para garantir todas as configurações"
else
    warning "⚠️  $FAILED_CHECKS verificação(ões) falharam"
    echo ""
    info "🔧 Ações recomendadas:"
    echo "   1. Execute os scripts de otimização novamente"
    echo "   2. Verifique os logs: journalctl -u mysql"
    echo "   3. Consulte a documentação: $(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/README_OTIMIZACAO_MYSQL.md"
fi

echo ""
log "📁 Arquivos importantes:"
echo "   Relatórios: /opt/mysql_reports/"
echo "   Backups: /opt/mysql_backups/"
echo "   Logs: /var/log/mysql*"
echo "   Configuração: /etc/mysql/mysql.conf.d/mysqld.cnf"

echo ""
log "✅ Validação concluída!"


