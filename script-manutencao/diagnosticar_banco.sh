#!/bin/bash

# Script de diagnóstico para investigar o desaparecimento dos bancos de dados
# Uso: ./diagnosticar_banco.sh

CONTAINER="integrar-db"

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

log_section "DIAGNÓSTICO DE BANCO DE DADOS"

# 1. Verificar se o container está rodando
log_section "1. Status do Container"
if docker ps | grep -q "$CONTAINER"; then
    log_success "Container $CONTAINER está rodando"
    docker ps | grep "$CONTAINER"
else
    log_error "Container $CONTAINER NÃO está rodando"
    echo "Containers MySQL encontrados:"
    docker ps -a | grep mysql || echo "Nenhum container MySQL encontrado"
fi

# 2. Verificar volumes do Docker
log_section "2. Volumes do Docker"
echo "Volumes relacionados ao projeto:"
docker volume ls | grep -E "(integrar|mysql)" || log_warning "Nenhum volume encontrado"

# 3. Verificar qual volume está sendo usado pelo container
log_section "3. Volume Montado no Container"
if docker ps | grep -q "$CONTAINER"; then
    echo "Volumes montados no container:"
    docker inspect $CONTAINER | grep -A 10 "Mounts" | grep -E "(Source|Destination|Name)" || log_warning "Não foi possível verificar volumes"
else
    log_warning "Container não está rodando, não é possível verificar volumes montados"
fi

# 4. Verificar bancos de dados existentes
log_section "4. Bancos de Dados no MySQL"
if docker ps | grep -q "$CONTAINER"; then
    echo "Bancos de dados existentes:"
    docker exec $CONTAINER mysql -u root -proot -e "SHOW DATABASES;" 2>/dev/null || log_error "Não foi possível conectar ao MySQL"
    
    echo ""
    echo "Verificando bancos específicos:"
    for BANCO in "integrar" "integrar_dalongaro"; do
        EXISTE=$(docker exec $CONTAINER mysql -u root -proot -e "SHOW DATABASES LIKE '$BANCO';" 2>/dev/null | grep -c "$BANCO" || echo "0")
        if [ "$EXISTE" -gt 0 ]; then
            log_success "Banco '$BANCO' existe"
            TABELAS=$(docker exec $CONTAINER mysql -u root -proot -e "USE $BANCO; SHOW TABLES;" 2>/dev/null | wc -l)
            echo "  - Tabelas: $((TABELAS-1))"
        else
            log_error "Banco '$BANCO' NÃO existe"
        fi
    done
else
    log_warning "Container não está rodando, não é possível verificar bancos"
fi

# 5. Verificar histórico de comandos docker-compose
log_section "5. Histórico de Comandos (últimos comandos docker)"
echo "Últimos comandos docker no histórico (se disponível):"
history | grep -E "docker-compose.*down|docker.*volume.*rm|docker.*rm.*volume" | tail -10 || log_info "Histórico não disponível ou sem comandos suspeitos"

# 6. Verificar tamanho do volume
log_section "6. Tamanho do Volume"
VOLUME_NAME=$(docker inspect $CONTAINER 2>/dev/null | grep -A 5 "Mounts" | grep "Name" | head -1 | cut -d'"' -f4)
if [ -n "$VOLUME_NAME" ]; then
    log_info "Volume: $VOLUME_NAME"
    VOLUME_PATH=$(docker volume inspect $VOLUME_NAME 2>/dev/null | grep "Mountpoint" | cut -d'"' -f4)
    if [ -n "$VOLUME_PATH" ]; then
        echo "Caminho do volume: $VOLUME_PATH"
        echo "Tamanho:"
        sudo du -sh $VOLUME_PATH 2>/dev/null || log_warning "Não foi possível verificar tamanho (precisa de sudo)"
        
        echo ""
        echo "Conteúdo do diretório do volume:"
        sudo ls -lah $VOLUME_PATH 2>/dev/null | head -20 || log_warning "Não foi possível listar conteúdo (precisa de sudo)"
    fi
else
    log_warning "Não foi possível identificar o volume"
fi

# 7. Verificar backups disponíveis
log_section "7. Backups Disponíveis"
echo "Backups no diretório atual:"
ls -lh backup*.sql 2>/dev/null | head -5 || log_warning "Nenhum backup no diretório atual"

echo ""
echo "Backups no diretório backups/:"
ls -lh backups/backup*.sql 2>/dev/null | head -5 || log_warning "Nenhum backup no diretório backups/"

# 8. Verificar data de criação do container
log_section "8. Informações do Container"
if docker ps -a | grep -q "$CONTAINER"; then
    echo "Data de criação do container:"
    docker inspect $CONTAINER | grep -E "(Created|StartedAt)" | head -2
    echo ""
    echo "Status:"
    docker inspect $CONTAINER | grep -A 5 "State" | head -6
fi

# 9. Verificar logs do MySQL
log_section "9. Últimas Linhas dos Logs do MySQL"
if docker ps | grep -q "$CONTAINER"; then
    echo "Últimas 20 linhas dos logs:"
    docker logs --tail 20 $CONTAINER 2>&1 | tail -20
else
    log_warning "Container não está rodando"
fi

# 10. Resumo e recomendações
log_section "10. RESUMO E RECOMENDAÇÕES"
echo ""
echo "Possíveis causas do problema:"
echo "  1. Container MySQL foi recriado sem o volume"
echo "  2. Volume foi removido com 'docker-compose down -v'"
echo "  3. Volume foi corrompido"
echo "  4. Banco foi deletado manualmente"
echo ""
echo "Próximos passos recomendados:"
echo "  1. Verificar se há backups válidos"
echo "  2. Restaurar do backup mais recente usando: ./verificar_restaurar_banco.sh"
echo "  3. Se não houver backup, verificar se o volume ainda contém dados"
echo "  4. Considerar implementar backup automático mais frequente"

echo ""
log_section "DIAGNÓSTICO CONCLUÍDO"

