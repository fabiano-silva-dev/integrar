#!/bin/bash

# =============================================================================
# Script: Monitoramento e Teste de Performance MySQL
# Descrição: Monitora performance e executa testes de otimização
# Autor: Sistema Integrar
# Data: $(date +%Y-%m-%d)
# =============================================================================

set -e

echo "🔍 Monitoramento e Teste de Performance MySQL - Sistema Integrar"

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

# Configurações
REPORTS_DIR="/opt/mysql_reports"
LOG_FILE="/var/log/mysql_performance.log"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# Criar diretórios se não existirem
mkdir -p "$REPORTS_DIR"

# Função para executar comando MySQL
mysql_exec() {
    mysql -e "$1" 2>/dev/null || echo "Erro ao executar: $1"
}

# Função para salvar relatório
save_report() {
    local content="$1"
    local filename="$2"
    echo "$content" > "$REPORTS_DIR/$filename"
    log "Relatório salvo em: $REPORTS_DIR/$filename"
}

# 1. Verificar Status do MySQL
log "1. Verificando status do MySQL..."
echo "=================================="

if systemctl is-active --quiet mysql; then
    log "✅ MySQL está rodando"
else
    error "❌ MySQL não está rodando"
    exit 1
fi

# 2. Verificar Configurações Atuais
log "2. Verificando configurações atuais..."
echo "====================================="

info "Buffer Pool Size:"
mysql_exec "SHOW VARIABLES LIKE 'innodb_buffer_pool_size';"

info "Buffer Pool Instances:"
mysql_exec "SHOW VARIABLES LIKE 'innodb_buffer_pool_instances';"

info "Tmpdir:"
mysql_exec "SHOW VARIABLES LIKE 'tmpdir';"

info "InnoDB Tmpdir:"
mysql_exec "SHOW VARIABLES LIKE 'innodb_tmpdir';"

# 3. Verificar Status InnoDB
log "3. Verificando status InnoDB..."
echo "=============================="

info "InnoDB Status:"
INNODB_STATUS=$(mysql_exec "SHOW ENGINE INNODB STATUS\G")
echo "$INNODB_STATUS"
save_report "$INNODB_STATUS" "innodb_status_$TIMESTAMP.txt"

# 4. Verificar Conexões
log "4. Verificando conexões..."
echo "========================="

info "Conexões ativas:"
mysql_exec "SHOW STATUS LIKE 'Threads_connected';"

info "Conexões máximas:"
mysql_exec "SHOW VARIABLES LIKE 'max_connections';"

info "Conexões históricas:"
mysql_exec "SHOW STATUS LIKE 'Connections';"

# 5. Verificar Queries
log "5. Verificando queries..."
echo "======================="

info "Queries por segundo:"
mysql_exec "SHOW STATUS LIKE 'Queries';"

info "Slow queries:"
mysql_exec "SHOW STATUS LIKE 'Slow_queries';"

info "Queries com cache hit:"
mysql_exec "SHOW STATUS LIKE 'Qcache_hits';"

# 6. Verificar Uso de Memória
log "6. Verificando uso de memória..."
echo "==============================="

info "Memória do sistema:"
free -h

info "Memória MySQL:"
mysql_exec "SHOW STATUS LIKE 'Innodb_buffer_pool_pages_data';"
mysql_exec "SHOW STATUS LIKE 'Innodb_buffer_pool_pages_total';"

# 7. Verificar I/O
log "7. Verificando I/O..."
echo "===================="

info "I/O de leitura:"
mysql_exec "SHOW STATUS LIKE 'Innodb_data_reads';"

info "I/O de escrita:"
mysql_exec "SHOW STATUS LIKE 'Innodb_data_writes';"

info "I/O de arquivos:"
mysql_exec "SHOW STATUS LIKE 'Innodb_data_fsyncs';"

# 8. Verificar Locks
log "8. Verificando locks..."
echo "====================="

info "Locks de linha:"
mysql_exec "SHOW STATUS LIKE 'Innodb_row_lock_waits';"

info "Locks de tabela:"
mysql_exec "SHOW STATUS LIKE 'Table_locks_waited';"

info "Deadlocks:"
mysql_exec "SHOW STATUS LIKE 'Innodb_deadlocks';"

# 9. Verificar tmpfs
log "9. Verificando tmpfs..."
echo "======================"

info "Tmpfs montados:"
df -h | grep tmpfs

info "Uso do diretório tmp do MySQL:"
if [ -d "/var/lib/mysql-tmp" ]; then
    du -sh /var/lib/mysql-tmp
else
    warning "Diretório tmpfs do MySQL não encontrado"
fi

# 10. Verificar Swap
log "10. Verificando swap..."
echo "======================"

info "Status do swap:"
swapon --show

info "Uso do swap:"
free -h | grep Swap

# 11. Executar MySQLTuner se disponível
log "11. Executando MySQLTuner..."
echo "============================"

if command -v mysqltuner &> /dev/null; then
    info "Executando MySQLTuner..."
    mysqltuner > "$REPORTS_DIR/mysqltuner_$TIMESTAMP.txt" 2>&1
    log "✅ MySQLTuner executado com sucesso"
else
    warning "MySQLTuner não encontrado. Execute: $(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/instalar_mysqltuner.sh"
fi

# 12. Gerar Relatório de Performance
log "12. Gerando relatório de performance..."
echo "====================================="

REPORT_FILE="$REPORTS_DIR/performance_report_$TIMESTAMP.txt"

cat > "$REPORT_FILE" << EOF
# Relatório de Performance MySQL - Sistema Integrar
# Data: $(date)
# Servidor: $(hostname)

## Status do Serviço
$(systemctl status mysql --no-pager)

## Configurações Principais
$(mysql_exec "SHOW VARIABLES LIKE 'innodb_buffer_pool_size';")
$(mysql_exec "SHOW VARIABLES LIKE 'innodb_buffer_pool_instances';")
$(mysql_exec "SHOW VARIABLES LIKE 'tmpdir';")
$(mysql_exec "SHOW VARIABLES LIKE 'max_connections';")

## Status InnoDB
$(mysql_exec "SHOW ENGINE INNODB STATUS\G")

## Conexões
$(mysql_exec "SHOW STATUS LIKE 'Threads_connected';")
$(mysql_exec "SHOW STATUS LIKE 'Connections';")

## Queries
$(mysql_exec "SHOW STATUS LIKE 'Queries';")
$(mysql_exec "SHOW STATUS LIKE 'Slow_queries';")

## Memória
$(free -h)

## I/O
$(mysql_exec "SHOW STATUS LIKE 'Innodb_data_reads';")
$(mysql_exec "SHOW STATUS LIKE 'Innodb_data_writes';")

## Locks
$(mysql_exec "SHOW STATUS LIKE 'Innodb_row_lock_waits';")
$(mysql_exec "SHOW STATUS LIKE 'Innodb_deadlocks';")

## Tmpfs
$(df -h | grep tmpfs)

## Swap
$(swapon --show)
$(free -h | grep Swap)
EOF

log "✅ Relatório de performance salvo em: $REPORT_FILE"

# 13. Análise de Performance
log "13. Análise de performance..."
echo "============================"

# Verificar se há problemas críticos
CRITICAL_ISSUES=0

# Verificar buffer pool
BUFFER_POOL_SIZE=$(mysql_exec "SHOW VARIABLES LIKE 'innodb_buffer_pool_size';" | awk '{print $2}' | grep -o '[0-9]*')
if [ "$BUFFER_POOL_SIZE" -lt 4000000000 ]; then
    warning "⚠️  Buffer pool size menor que 4GB: $BUFFER_POOL_SIZE"
    CRITICAL_ISSUES=$((CRITICAL_ISSUES + 1))
fi

# Verificar conexões
THREADS_CONNECTED=$(mysql_exec "SHOW STATUS LIKE 'Threads_connected';" | awk '{print $2}')
MAX_CONNECTIONS=$(mysql_exec "SHOW VARIABLES LIKE 'max_connections';" | awk '{print $2}')
if [ "$THREADS_CONNECTED" -gt $((MAX_CONNECTIONS * 80 / 100)) ]; then
    warning "⚠️  Uso de conexões alto: $THREADS_CONNECTED/$MAX_CONNECTIONS"
    CRITICAL_ISSUES=$((CRITICAL_ISSUES + 1))
fi

# Verificar slow queries
SLOW_QUERIES=$(mysql_exec "SHOW STATUS LIKE 'Slow_queries';" | awk '{print $2}')
if [ "$SLOW_QUERIES" -gt 100 ]; then
    warning "⚠️  Muitas slow queries: $SLOW_QUERIES"
    CRITICAL_ISSUES=$((CRITICAL_ISSUES + 1))
fi

# Verificar deadlocks
DEADLOCKS=$(mysql_exec "SHOW STATUS LIKE 'Innodb_deadlocks';" | awk '{print $2}')
if [ "$DEADLOCKS" -gt 0 ]; then
    warning "⚠️  Deadlocks detectados: $DEADLOCKS"
    CRITICAL_ISSUES=$((CRITICAL_ISSUES + 1))
fi

# Verificar uso de swap
SWAP_USED=$(free | grep Swap | awk '{print $3}')
if [ "$SWAP_USED" -gt 0 ]; then
    warning "⚠️  Swap sendo utilizado: ${SWAP_USED}KB"
    CRITICAL_ISSUES=$((CRITICAL_ISSUES + 1))
fi

# Resultado final
echo ""
if [ $CRITICAL_ISSUES -eq 0 ]; then
    log "✅ Nenhum problema crítico detectado!"
else
    warning "⚠️  $CRITICAL_ISSUES problema(s) crítico(s) detectado(s)"
fi

# 14. Recomendações
log "14. Recomendações..."
echo "==================="

echo ""
info "📋 Recomendações baseadas na análise:"
echo ""

if [ "$BUFFER_POOL_SIZE" -lt 4000000000 ]; then
    echo "1. 🔧 Ajustar innodb_buffer_pool_size para 4GB"
    echo "   Execute: $(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/otimizar_mysql_buffer_pool.sh"
fi

if [ "$THREADS_CONNECTED" -gt $((MAX_CONNECTIONS * 80 / 100)) ]; then
    echo "2. 🔧 Aumentar max_connections ou otimizar queries"
    echo "   Considere ajustar max_connections no MySQL"
fi

if [ "$SLOW_QUERIES" -gt 100 ]; then
    echo "3. 🔧 Otimizar slow queries"
    echo "   Verifique slow_query_log e otimize queries lentas"
fi

if [ "$DEADLOCKS" -gt 0 ]; then
    echo "4. 🔧 Investigar deadlocks"
    echo "   Analise logs e otimize transações"
fi

if [ "$SWAP_USED" -gt 0 ]; then
    echo "5. 🔧 Verificar uso de swap"
    echo "   Considere aumentar RAM ou otimizar configurações"
fi

echo ""
log "✅ Monitoramento concluído!"
log "📁 Relatórios salvos em: $REPORTS_DIR"
log "📝 Log detalhado: $LOG_FILE"

echo ""
info "🔄 Para monitoramento contínuo, execute:"
echo "   watch -n 30 '$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/monitorar_mysql_performance.sh'"





