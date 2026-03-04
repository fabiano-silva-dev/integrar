#!/bin/bash

# =============================================================================
# Script: Instalar e Configurar MySQLTuner
# Descrição: Instala MySQLTuner para monitoramento de performance
# Autor: Sistema Integrar
# Data: $(date +%Y-%m-%d)
# =============================================================================

set -e

echo "🚀 Instalando e configurando MySQLTuner..."

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
MYSQLTUNER_DIR="/opt/mysqltuner"
MYSQLTUNER_URL="https://raw.githubusercontent.com/major/MySQLTuner-perl/master/mysqltuner.pl"
MYSQLTUNER_SCRIPT="$MYSQLTUNER_DIR/mysqltuner.pl"
MYSQLTUNER_CONFIG="$MYSQLTUNER_DIR/mysqltuner.conf"
REPORTS_DIR="/opt/mysql_reports"

# Atualizar sistema
log "Atualizando sistema..."
apt update

# Instalar dependências
log "Instalando dependências..."
apt install -y perl libdbi-perl libdbd-mysql-perl libterm-readkey-perl

# Criar diretórios
log "Criando diretórios..."
mkdir -p "$MYSQLTUNER_DIR"
mkdir -p "$REPORTS_DIR"

# Baixar MySQLTuner
log "Baixando MySQLTuner..."
if [ -f "$MYSQLTUNER_SCRIPT" ]; then
    log "MySQLTuner já existe. Fazendo backup..."
    cp "$MYSQLTUNER_SCRIPT" "${MYSQLTUNER_SCRIPT}.backup.$(date +%Y%m%d_%H%M%S)"
fi

wget -O "$MYSQLTUNER_SCRIPT" "$MYSQLTUNER_URL"

# Configurar permissões
log "Configurando permissões..."
chmod +x "$MYSQLTUNER_SCRIPT"
chown root:root "$MYSQLTUNER_SCRIPT"

# Criar arquivo de configuração
log "Criando arquivo de configuração..."
cat > "$MYSQLTUNER_CONFIG" << 'EOF'
# MySQLTuner Configuration
# Configurações personalizadas para o Sistema Integrar

# Configurações de conexão
mysql_user="root"
mysql_pass=""
mysql_host="localhost"
mysql_port="3306"

# Configurações de relatório
output_format="text"
save_report="yes"
report_dir="/opt/mysql_reports"

# Configurações de análise
analyze_tables="yes"
analyze_queries="yes"
analyze_connections="yes"
analyze_performance="yes"

# Configurações de alertas
alert_buffer_pool="80"
alert_connections="80"
alert_queries="1000"
alert_slow_queries="10"
EOF

# Criar script wrapper para facilitar uso
log "Criando script wrapper..."
cat > "/usr/local/bin/mysqltuner" << 'EOF'
#!/bin/bash

# MySQLTuner Wrapper para Sistema Integrar
# Facilita o uso do MySQLTuner com configurações padrão

MYSQLTUNER_SCRIPT="/opt/mysqltuner/mysqltuner.pl"
REPORTS_DIR="/opt/mysql_reports"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# Cores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${GREEN}🔍 MySQLTuner - Sistema Integrar${NC}"
echo "=================================="

# Verificar se MySQL está rodando
if ! systemctl is-active --quiet mysql; then
    echo -e "${YELLOW}⚠️  MySQL não está rodando. Iniciando...${NC}"
    systemctl start mysql
    sleep 5
fi

# Executar MySQLTuner
echo "Executando análise..."
perl "$MYSQLTUNER_SCRIPT" --config /opt/mysqltuner/mysqltuner.conf

# Salvar relatório
if [ -d "$REPORTS_DIR" ]; then
    echo ""
    echo "Salvando relatório em: $REPORTS_DIR/mysqltuner_$TIMESTAMP.txt"
    perl "$MYSQLTUNER_SCRIPT" --config /opt/mysqltuner/mysqltuner.conf > "$REPORTS_DIR/mysqltuner_$TIMESTAMP.txt" 2>&1
fi

echo ""
echo -e "${GREEN}✅ Análise concluída!${NC}"
EOF

chmod +x "/usr/local/bin/mysqltuner"

# Criar script de monitoramento contínuo
log "Criando script de monitoramento contínuo..."
cat > "/opt/mysqltuner/monitor_mysql.sh" << 'EOF'
#!/bin/bash

# Script de Monitoramento Contínuo MySQL
# Executa MySQLTuner periodicamente e gera alertas

MYSQLTUNER_SCRIPT="/opt/mysqltuner/mysqltuner.pl"
REPORTS_DIR="/opt/mysql_reports"
LOG_FILE="/var/log/mysql_monitor.log"
ALERT_EMAIL="admin@integrar.local"

# Função para log
log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

# Verificar se MySQL está rodando
if ! systemctl is-active --quiet mysql; then
    log "ERRO: MySQL não está rodando!"
    exit 1
fi

# Executar análise
log "Iniciando análise MySQLTuner..."
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
REPORT_FILE="$REPORTS_DIR/mysqltuner_$TIMESTAMP.txt"

perl "$MYSQLTUNER_SCRIPT" > "$REPORT_FILE" 2>&1

# Verificar se há problemas críticos
if grep -q "CRITICAL" "$REPORT_FILE"; then
    log "ALERTA: Problemas críticos detectados!"
    # Aqui você pode adicionar envio de email ou notificação
fi

log "Análise concluída. Relatório salvo em: $REPORT_FILE"
EOF

chmod +x "/opt/mysqltuner/monitor_mysql.sh"

# Configurar cron para monitoramento automático
log "Configurando monitoramento automático..."
cat > "/etc/cron.d/mysql-monitor" << 'EOF'
# Monitoramento MySQL - Sistema Integrar
# Executa análise diária às 2:00 AM
0 2 * * * root /opt/mysqltuner/monitor_mysql.sh

# Executa análise semanal completa aos domingos às 3:00 AM
0 3 * * 0 root /usr/local/bin/mysqltuner
EOF

# Criar script para análise rápida
log "Criando script de análise rápida..."
cat > "/usr/local/bin/mysql-status" << 'EOF'
#!/bin/bash

# Análise Rápida MySQL - Sistema Integrar
# Mostra status básico do MySQL

echo "🔍 Status MySQL - Sistema Integrar"
echo "=================================="

# Status do serviço
echo "📊 Status do Serviço:"
systemctl status mysql --no-pager -l

echo ""
echo "💾 Uso de Memória:"
free -h

echo ""
echo "💿 Uso de Disco:"
df -h | grep -E "(mysql|tmpfs)"

echo ""
echo "🔌 Conexões MySQL:"
mysql -e "SHOW STATUS LIKE 'Threads_connected';" 2>/dev/null || echo "Erro ao conectar no MySQL"

echo ""
echo "📈 Buffer Pool Status:"
mysql -e "SHOW ENGINE INNODB STATUS\G" 2>/dev/null | grep -A 10 "BUFFER POOL" || echo "Erro ao obter status InnoDB"

echo ""
echo "⚡ Queries por Segundo:"
mysql -e "SHOW GLOBAL STATUS LIKE 'Queries';" 2>/dev/null || echo "Erro ao obter estatísticas de queries"
EOF

chmod +x "/usr/local/bin/mysql-status"

# Testar instalação
log "Testando instalação..."
if [ -f "$MYSQLTUNER_SCRIPT" ] && [ -x "$MYSQLTUNER_SCRIPT" ]; then
    log "✅ MySQLTuner instalado com sucesso!"
else
    error "❌ Falha na instalação do MySQLTuner"
    exit 1
fi

# Executar teste inicial
log "Executando teste inicial..."
if systemctl is-active --quiet mysql; then
    log "Executando análise inicial..."
    /usr/local/bin/mysqltuner
else
    warning "MySQL não está rodando. Execute 'systemctl start mysql' e depois '/usr/local/bin/mysqltuner'"
fi

log "✅ Instalação do MySQLTuner concluída com sucesso!"

echo ""
log "📋 Comandos disponíveis:"
echo "  mysqltuner          - Análise completa do MySQL"
echo "  mysql-status        - Status rápido do MySQL"
echo "  /opt/mysqltuner/monitor_mysql.sh - Monitoramento contínuo"
echo ""
log "📁 Arquivos importantes:"
echo "  MySQLTuner: $MYSQLTUNER_SCRIPT"
echo "  Configuração: $MYSQLTUNER_CONFIG"
echo "  Relatórios: $REPORTS_DIR"
echo "  Logs: /var/log/mysql_monitor.log"
echo ""
log "🕐 Monitoramento automático configurado:"
echo "  - Análise diária: 2:00 AM"
echo "  - Análise semanal: Domingo 3:00 AM"





