#!/bin/bash
#
# Recria containers Docker com segurança — corrige erro ContainerConfig do docker-compose 1.29
#
# Uso (no servidor, dentro do projeto):
#   ./script-manutencao/recriar_containers_seguro.sh              # interativo
#   ./script-manutencao/recriar_containers_seguro.sh --dry-run    # só simula
#   ./script-manutencao/recriar_containers_seguro.sh -y           # sem perguntar
#
# SEGURANÇA:
#   • NUNCA usa docker-compose down -v (preserva volume mysql_data)
#   • Remove apenas containers com "integrar" no nome
#   • Verifica volume MySQL antes e depois
#   • Confirma portas 127.0.0.1 após subir

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

DRY_RUN=false
AUTO_YES=false
COMPOSE_CMD=()

CONTAINER_FILTER="integrar"
SERVICOS=(app phpmyadmin db)

log_info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[OK]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[AVISO]${NC} $1"; }
log_error()   { echo -e "${RED}[ERRO]${NC} $1"; }
log_dry()     { echo -e "${MAGENTA}[DRY-RUN]${NC} $1"; }
log_skip()    { echo -e "${YELLOW}[PULADO]${NC} $1"; }

log_section() {
    echo ""
    echo -e "${CYAN}========================================${NC}"
    echo -e "${CYAN}$1${NC}"
    echo -e "${CYAN}========================================${NC}"
}

show_help() {
    cat <<EOF
Recriar containers Docker com segurança — IntegraExpert

Corrige o erro 'ContainerConfig' do docker-compose 1.29 ao mudar portas no yml.
Preserva o volume mysql_data (banco de dados).

Uso:
  $0 [opções]

Opções:
  --dry-run, -n    Simula sem alterar nada (recomendado na 1ª vez)
  --yes, -y        Executa sem pedir confirmação
  --help, -h       Esta ajuda

Fluxo:
  1. Verifica docker-compose.yml e volume MySQL
  2. Lista containers que serão removidos
  3. Pede confirmação (modo interativo)
  4. Remove containers integrar (parados ou quebrados)
  5. Sobe tudo de novo com docker-compose / docker compose
  6. Valida portas 127.0.0.1 e MySQL

NÃO FAZ:
  • docker-compose down -v  (nunca apaga volumes)
  • Remover containers de outros projetos

Depois valide no seu PC:
  ./script-manutencao/testar_seguranca_externo.sh integraexpert.com.br
EOF
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --dry-run|-n) DRY_RUN=true ;;
            --yes|-y)     AUTO_YES=true ;;
            --help|-h)    show_help; exit 0 ;;
            *)
                log_error "Opção desconhecida: $1"
                show_help
                exit 1
                ;;
        esac
        shift
    done
}

confirmar() {
    local mensagem="$1"

    if [[ "$AUTO_YES" == true ]]; then
        return 0
    fi

    echo ""
    echo -e "${YELLOW}?${NC} ${mensagem}"
    read -r -p "   Confirmar? [s/N] " resposta
    [[ "${resposta,,}" == "s" || "${resposta,,}" == "sim" ]]
}

detectar_compose() {
    if docker compose version >/dev/null 2>&1; then
        COMPOSE_CMD=(docker compose)
        log_info "Usando: docker compose (plugin V2)"
        return
    fi

    if command -v docker-compose >/dev/null 2>&1; then
        COMPOSE_CMD=(docker-compose)
        local versao
        versao=$(docker-compose --version 2>/dev/null || echo "desconhecida")
        log_info "Usando: docker-compose — $versao"
        if echo "$versao" | grep -q "1.29"; then
            log_warning "docker-compose 1.29 pode falhar com 'ContainerConfig' — este script contorna removendo containers antes"
            log_warning "Para solução definitiva: sudo apt install docker-compose-plugin"
        fi
        return
    fi

    log_error "Docker Compose não encontrado. Instale docker-compose ou docker-compose-plugin."
    exit 1
}

verificar_pre_requisitos() {
    log_section "1. Verificações iniciais"

    if ! command -v docker >/dev/null 2>&1; then
        log_error "Docker não instalado."
        exit 1
    fi

    if ! docker info >/dev/null 2>&1; then
        log_error "Docker não acessível. Execute com usuário no grupo docker ou use sudo."
        exit 1
    fi

    cd "$PROJECT_DIR"

    if [[ ! -f "docker-compose.yml" ]]; then
        log_error "docker-compose.yml não encontrado em $PROJECT_DIR"
        exit 1
    fi
    log_success "Projeto: $PROJECT_DIR"

    detectar_compose

    if grep -q "127.0.0.1:8081" docker-compose.yml && grep -q "127.0.0.1:8082" docker-compose.yml; then
        log_success "docker-compose.yml com portas em 127.0.0.1"
    else
        log_warning "docker-compose.yml ainda pode ter portas em 0.0.0.0 — atualize com git pull"
    fi
}

listar_volumes_mysql() {
  docker volume ls --format '{{.Name}}' 2>/dev/null | grep -iE 'mysql|integrar' || true
}

verificar_volume_mysql() {
    log_section "2. Volume MySQL (dados do banco)"

    local volumes
    volumes=$(listar_volumes_mysql)

    if [[ -z "$volumes" ]]; then
        log_warning "Nenhum volume com 'mysql' ou 'integrar' encontrado."
        log_warning "Se o banco nunca foi criado, um volume novo será criado no próximo up."
        return
    fi

    log_info "Volumes encontrados (serão PRESERVADOS):"
    echo "$volumes" | while read -r vol; do
        [[ -n "$vol" ]] && echo "  • $vol"
    done
    log_success "Este script NÃO usa 'down -v' — volumes não serão apagados"
}

listar_containers_integrar() {
    docker ps -a --filter "name=${CONTAINER_FILTER}" --format '{{.ID}}\t{{.Names}}\t{{.Status}}\t{{.Ports}}' 2>/dev/null || true
}

mostrar_containers_atuais() {
    log_section "3. Containers atuais"

    local lista
    lista=$(listar_containers_integrar)

    if [[ -z "$lista" ]]; then
        log_info "Nenhum container 'integrar' encontrado — será criação limpa"
        return
    fi

    echo -e "${YELLOW}Serão removidos e recriados:${NC}"
    echo -e "ID\tNOME\tSTATUS\tPORTAS"
    echo "$lista"

    if echo "$lista" | grep -qE '_integrar-|^[a-f0-9]{12}_'; then
        log_warning "Containers com nome quebrado (hash_integrar-*) detectados — típico do erro ContainerConfig"
    fi

    if echo "$lista" | grep -q "0.0.0.0:808"; then
        log_warning "Portas ainda em 0.0.0.0 — após recriar devem ficar 127.0.0.1"
    fi
}

remover_containers_integrar() {
    log_section "4. Remover containers integrar"

    local ids
    ids=$(docker ps -a --filter "name=${CONTAINER_FILTER}" -q 2>/dev/null || true)

    if [[ -z "$ids" ]]; then
        log_info "Nenhum container para remover"
        return
    fi

    if [[ "$DRY_RUN" == true ]]; then
        log_dry "Executaria: docker rm -f $ids"
        return
    fi

    log_info "Removendo containers (volume mysql_data permanece)..."
    # shellcheck disable=SC2086
    docker rm -f $ids
    log_success "Containers removidos"

    local restantes
    restantes=$(docker ps -a --filter "name=${CONTAINER_FILTER}" -q 2>/dev/null || true)
    if [[ -n "$restantes" ]]; then
        log_error "Ainda existem containers integrar — remoção incompleta"
        exit 1
    fi
    log_success "Nenhum container integrar restante"
}

subir_containers() {
    log_section "5. Subir containers"

    cd "$PROJECT_DIR"

    if [[ "$DRY_RUN" == true ]]; then
        log_dry "Executaria: ${COMPOSE_CMD[*]} up -d"
        return
    fi

    log_info "Executando: ${COMPOSE_CMD[*]} up -d"
    if ! "${COMPOSE_CMD[@]}" up -d; then
        log_error "Falha ao subir containers."
        echo ""
        log_warning "Tente instalar Compose V2 e rodar de novo:"
        echo "  sudo apt install docker-compose-plugin"
        echo "  docker compose up -d"
        exit 1
    fi

    log_success "Containers iniciados"
    sleep 3
}

validar_resultado() {
    log_section "6. Validação"

    if [[ "$DRY_RUN" == true ]]; then
        log_dry "Pularia validação de portas e MySQL"
        return
    fi

    log_info "Status dos containers:"
    docker ps --filter "name=${CONTAINER_FILTER}" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

    local falhas=0

    if docker ps --format '{{.Ports}}' 2>/dev/null | grep -q "0.0.0.0:8081\|0.0.0.0:8082"; then
        log_error "Portas 8081/8082 ainda em 0.0.0.0 — correção incompleta"
        falhas=$((falhas + 1))
    elif docker ps --filter "name=integrar-app" --format '{{.Ports}}' 2>/dev/null | grep -q "127.0.0.1:8081"; then
        log_success "App em 127.0.0.1:8081"
    fi

    if docker ps --filter "name=integrar-phpmyadmin" --format '{{.Ports}}' 2>/dev/null | grep -q "127.0.0.1:8082"; then
        log_success "phpMyAdmin em 127.0.0.1:8082"
    elif docker ps --filter "name=integrar-phpmyadmin" --format '{{.Ports}}' 2>/dev/null | grep -q "8082"; then
        log_warning "phpMyAdmin rodando mas verifique binding da porta 8082"
    fi

    if docker ps --filter "name=integrar-db" --format '{{.Names}}' 2>/dev/null | grep -q "integrar-db"; then
        log_success "Container integrar-db em execução"
        local root_pass="${MYSQL_ROOT_PASSWORD:-root}"
        if docker exec integrar-db mysqladmin ping -u root -p"${root_pass}" --silent 2>/dev/null; then
            log_success "MySQL respondendo"
        else
            log_warning "MySQL ainda iniciando ou senha root diferente de 'root'"
        fi
    else
        log_error "integrar-db não está rodando"
        falhas=$((falhas + 1))
    fi

    local volumes
    volumes=$(listar_volumes_mysql)
    if [[ -n "$volumes" ]]; then
        log_success "Volumes MySQL preservados"
    fi

    echo ""
    if [[ "$falhas" -eq 0 ]]; then
        log_success "Recriação concluída com sucesso!"
        echo ""
        echo "Próximos passos:"
        echo "  • phpMyAdmin: ./script-manutencao/acessar_phpmyadmin_ssh.sh → http://localhost:9082"
        echo "  • Teste externo (no seu PC): ./script-manutencao/testar_seguranca_externo.sh integraexpert.com.br"
    else
        log_error "$falhas problema(s) na validação — revise a saída acima"
        exit 1
    fi
}

mostrar_resumo_inicial() {
    log_section "RECRIAR CONTAINERS COM SEGURANÇA"

    if [[ "$DRY_RUN" == true ]]; then
        echo -e "${MAGENTA}Modo: DRY-RUN — nada será alterado${NC}"
    elif [[ "$AUTO_YES" == true ]]; then
        echo -e "${YELLOW}Modo: automático (-y)${NC}"
    else
        echo -e "${GREEN}Modo: interativo — pede confirmação antes de remover e subir${NC}"
    fi

    echo ""
    echo "Este script vai:"
    echo "  1. Listar containers integrar (incluindo nomes quebrados tipo hash_integrar-app)"
    echo "  2. Remover APENAS esses containers com docker rm -f"
    echo "  3. Subir de novo com docker-compose up -d"
    echo ""
    echo -e "${GREEN}PRESERVA:${NC} volume mysql_data e todos os dados do banco"
    echo -e "${RED}NUNCA USA:${NC} docker-compose down -v"
}

main() {
    parse_args "$@"

    mostrar_resumo_inicial

    if [[ "$DRY_RUN" != true && "$AUTO_YES" != true ]]; then
        if ! confirmar "Deseja continuar com a verificação e recriação dos containers?"; then
            log_skip "Cancelado pelo usuário."
            exit 0
        fi
    fi

    verificar_pre_requisitos
    verificar_volume_mysql
    mostrar_containers_atuais

    if [[ "$DRY_RUN" != true && "$AUTO_YES" != true ]]; then
        echo ""
        log_warning "Os containers listados acima serão REMOVIDOS e RECRIADOS."
        log_warning "O site ficará indisponível por alguns segundos durante o processo."
        if ! confirmar "Confirmar remoção dos containers integrar?"; then
            log_skip "Remoção cancelada."
            exit 0
        fi
    fi

    remover_containers_integrar

    if [[ "$DRY_RUN" != true && "$AUTO_YES" != true ]]; then
        if ! confirmar "Confirmar subida dos containers (docker-compose up -d)?"; then
            log_skip "Subida cancelada. Containers foram removidos — rode manualmente: docker-compose up -d"
            exit 1
        fi
    fi

    subir_containers
    validar_resultado
}

main "$@"
