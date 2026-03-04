#!/bin/bash

# Script de auditoria de segurança completa
# Uso: ./auditar_seguranca.sh

# Cores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

log_section() {
    echo ""
    echo -e "${CYAN}========================================${NC}"
    echo -e "${CYAN}$1${NC}"
    echo -e "${CYAN}========================================${NC}"
}

log_section "AUDITORIA DE SEGURANÇA - SERVIDOR INTEGRAR"

# 1. Verificar logs do Apache para o IP atacante
log_section "1. Análise de Logs do Apache"
echo "Buscando tentativas de acesso do IP atacante..."
if [ -d "/var/log/apache2" ]; then
    echo "Tentativas de acesso a backups:"
    grep "159.65.156.239" /var/log/apache2/*.log 2>/dev/null | grep -E "(backup|\.sql|\.zip|\.tar|\.gz)" | wc -l | xargs echo "Total de tentativas:"
    
    echo ""
    echo "Primeiras tentativas:"
    grep "159.65.156.239" /var/log/apache2/*.log 2>/dev/null | head -5
    
    echo ""
    echo "Últimas tentativas:"
    grep "159.65.156.239" /var/log/apache2/*.log 2>/dev/null | tail -5
else
    echo "Diretório de logs do Apache não encontrado"
fi

# 2. Verificar usuários MySQL
log_section "2. Usuários do MySQL"
if docker ps | grep -q "integrar-db"; then
    echo "Usuários MySQL:"
    docker exec integrar-db mysql -u root -proot -e "SELECT User, Host FROM mysql.user;" 2>/dev/null || echo "Erro ao conectar"
    
    echo ""
    echo "Verificando usuários com acesso remoto:"
    docker exec integrar-db mysql -u root -proot -e "SELECT User, Host FROM mysql.user WHERE Host != 'localhost' AND Host != '127.0.0.1';" 2>/dev/null
else
    echo "Container MySQL não está rodando"
fi

# 3. Verificar bancos de dados
log_section "3. Bancos de Dados"
if docker ps | grep -q "integrar-db"; then
    echo "Todos os bancos de dados:"
    docker exec integrar-db mysql -u root -proot -e "SHOW DATABASES;" 2>/dev/null
    
    echo ""
    echo "Verificando bancos suspeitos:"
    docker exec integrar-db mysql -u root -proot -e "SHOW DATABASES;" 2>/dev/null | grep -E "(test|temp|backup|hack)" || echo "Nenhum banco suspeito encontrado"
fi

# 4. Verificar arquivos sensíveis expostos
log_section "4. Arquivos Sensíveis Expostos"
echo "Buscando arquivos .env, .sql, backups no diretório public:"
find public/ -type f \( -name "*.env" -o -name "*.sql" -o -name "*.zip" -o -name "*.tar*" -o -name "*.bak" \) 2>/dev/null | while read arquivo; do
    echo -e "${RED}⚠️  ARQUIVO SENSÍVEL EXPOSTO: $arquivo${NC}"
done

# 5. Verificar permissões de arquivos críticos
log_section "5. Permissões de Arquivos Críticos"
echo "Verificando .env:"
if [ -f ".env" ]; then
    ls -la .env
    if [ "$(stat -c %a .env 2>/dev/null)" != "600" ] && [ "$(stat -c %a .env 2>/dev/null)" != "640" ]; then
        echo -e "${YELLOW}⚠️  Permissões do .env devem ser 600 ou 640${NC}"
    fi
else
    echo "Arquivo .env não encontrado"
fi

echo ""
echo "Verificando docker-compose.yml:"
ls -la docker-compose.yml 2>/dev/null || echo "Arquivo não encontrado"

# 6. Verificar processos suspeitos
log_section "6. Processos em Execução"
echo "Processos MySQL:"
ps aux | grep -E "[m]ysql|[m]ysqldump" | head -10

echo ""
echo "Processos Docker:"
ps aux | grep -E "[d]ocker" | head -5

# 7. Verificar conexões de rede
log_section "7. Conexões de Rede"
echo "Conexões na porta 3306 (MySQL):"
netstat -tulpn 2>/dev/null | grep 3306 || ss -tulpn 2>/dev/null | grep 3306 || echo "Comando não disponível"

echo ""
echo "Conexões na porta 3308 (MySQL exposto):"
netstat -tulpn 2>/dev/null | grep 3308 || ss -tulpn 2>/dev/null | grep 3308 || echo "Comando não disponível"

# 8. Verificar histórico de comandos suspeitos
log_section "8. Histórico de Comandos"
echo "Comandos MySQL no histórico:"
history | grep -iE "(mysql|mysqldump|drop|delete|truncate)" | tail -20 || echo "Histórico não disponível"

# 9. Verificar arquivos criados/modificados recentemente
log_section "9. Arquivos Modificados Recentemente"
echo "Arquivos modificados nas últimas 48 horas (exceto vendor/node_modules):"
find . -type f -mtime -2 ! -path "./vendor/*" ! -path "./node_modules/*" ! -path "./.git/*" ! -path "./storage/logs/*" 2>/dev/null | head -30

# 10. Verificar configuração do Docker
log_section "10. Configuração do Docker"
echo "Verificando se MySQL está exposto publicamente:"
if [ -f "docker-compose.yml" ]; then
    if grep -q "3308:3306" docker-compose.yml; then
        echo -e "${YELLOW}⚠️  MySQL está exposto na porta 3308 - considere remover se não for necessário${NC}"
    fi
fi

# 11. Resumo e recomendações
log_section "11. RESUMO E RECOMENDAÇÕES"
echo ""
echo -e "${RED}AÇÕES URGENTES:${NC}"
echo "1. Mudar todas as senhas do MySQL imediatamente"
echo "2. Bloquear o IP 159.65.156.239 no firewall"
echo "3. Verificar se há backdoors ou arquivos suspeitos"
echo "4. Remover exposição do MySQL na porta 3308 se não for necessário"
echo "5. Verificar se há acesso não autorizado aos bancos"
echo "6. Implementar fail2ban"
echo "7. Configurar rate limiting no Apache/Nginx"
echo "8. Verificar se há arquivos .env ou backups no diretório public"
echo "9. Revisar logs de acesso para outros IPs suspeitos"
echo "10. Considerar restaurar os bancos de dados do backup mais recente"
echo ""
echo -e "${YELLOW}PRÓXIMOS PASSOS:${NC}"
echo "1. Execute: ./seguranca_bloquear_ataque.sh"
echo "2. Mude as senhas no .env e docker-compose.yml"
echo "3. Restaure os bancos: ./verificar_restaurar_banco.sh"
echo "4. Monitore logs continuamente"
echo ""

log_section "AUDITORIA CONCLUÍDA"

