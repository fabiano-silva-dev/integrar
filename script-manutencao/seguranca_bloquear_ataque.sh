#!/bin/bash

# Script de segurança para bloquear ataque e proteger o servidor
# Uso: ./seguranca_bloquear_ataque.sh

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
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

log_section() {
    echo ""
    echo -e "${CYAN}========================================${NC}"
    echo -e "${CYAN}$1${NC}"
    echo -e "${CYAN}========================================${NC}"
}

log_section "PROTEÇÃO CONTRA ATAQUE DETECTADO"

# IPs suspeitos encontrados nos logs
IPS_ATACANTES=("159.65.156.239")

log_section "1. Bloqueando IPs Atacantes"

for IP in "${IPS_ATACANTES[@]}"; do
    log_info "Bloqueando IP: $IP"
    
    # Bloquear com iptables (se disponível)
    if command -v iptables &> /dev/null; then
        # Verificar se já está bloqueado
        if iptables -C INPUT -s $IP -j DROP 2>/dev/null; then
            log_warning "IP $IP já está bloqueado no iptables"
        else
            iptables -A INPUT -s $IP -j DROP
            log_success "IP $IP bloqueado no iptables"
        fi
    fi
    
    # Bloquear com fail2ban (se disponível)
    if command -v fail2ban-client &> /dev/null; then
        fail2ban-client set apache banip $IP 2>/dev/null && log_success "IP $IP bloqueado no fail2ban" || log_warning "Falha ao bloquear no fail2ban"
    fi
done

log_section "2. Verificando Acesso ao MySQL"

log_info "Verificando conexões MySQL suspeitas..."
if command -v netstat &> /dev/null; then
    netstat -an | grep :3306 | grep -v "127.0.0.1" | while read line; do
        log_warning "Conexão MySQL externa detectada: $line"
    done
fi

log_section "3. Verificando Arquivos Sensíveis Expostos"

# Verificar se há arquivos de backup acessíveis via web
log_info "Verificando arquivos .sql, .zip, .tar, .gz no diretório web..."
find . -maxdepth 2 -type f \( -name "*.sql" -o -name "*.zip" -o -name "*.tar" -o -name "*.gz" -o -name "*.rar" -o -name "*.7z" \) 2>/dev/null | while read arquivo; do
    # Verificar se está no diretório public
    if [[ "$arquivo" == *"public"* ]] || [[ "$arquivo" == *"storage/app/public"* ]]; then
        log_error "ARQUIVO SENSÍVEL EXPOSTO: $arquivo"
    fi
done

log_section "4. Recomendações de Segurança"

echo ""
echo "⚠️  AÇÕES URGENTES RECOMENDADAS:"
echo ""
echo "1. Verificar logs do Apache/Nginx para mais evidências:"
echo "   grep '159.65.156.239' /var/log/apache2/*.log"
echo ""
echo "2. Verificar se há acesso não autorizado ao MySQL:"
echo "   docker exec integrar-db mysql -u root -proot -e \"SELECT * FROM mysql.user WHERE Host != 'localhost';\""
echo ""
echo "3. Verificar se há processos suspeitos:"
echo "   ps aux | grep -E '(mysql|mysqldump|tar|zip)'"
echo ""
echo "4. Verificar histórico de comandos:"
echo "   history | grep -E '(DROP|DELETE|TRUNCATE|mysql)'"
echo ""
echo "5. Verificar se há arquivos .env ou backups no diretório public:"
echo "   find public/ -name '*.env' -o -name '*.sql' -o -name '*.zip'"
echo ""
echo "6. Mudar senhas do MySQL imediatamente:"
echo "   - MYSQL_ROOT_PASSWORD"
echo "   - MYSQL_PASSWORD do usuário laravel"
echo ""
echo "7. Verificar permissões de arquivos:"
echo "   ls -la .env docker-compose.yml"
echo ""
echo "8. Implementar fail2ban para proteção automática"
echo "9. Configurar firewall (ufw/iptables) para bloquear acesso direto ao MySQL"
echo "10. Verificar se há backdoors ou arquivos suspeitos criados recentemente"

log_section "5. Verificando Arquivos Modificados Recentemente"

log_info "Arquivos modificados nas últimas 24 horas:"
find . -type f -mtime -1 ! -path "./vendor/*" ! -path "./node_modules/*" ! -path "./.git/*" 2>/dev/null | head -20

log_section "6. Verificando Conexões de Rede Ativas"

log_info "Conexões de rede ativas:"
if command -v netstat &> /dev/null; then
    netstat -tulpn 2>/dev/null | grep -E "(3306|3308|8081|8082)" | head -10
else
    log_warning "netstat não disponível"
fi

log_section "PROTEÇÃO CONCLUÍDA"

echo ""
log_warning "IMPORTANTE: Este script é apenas uma primeira linha de defesa."
log_warning "Execute uma auditoria completa de segurança imediatamente!"
echo ""

