#!/bin/bash
#
# Hardening de segurança — executar NO SERVIDOR DE PRODUÇÃO como root
#
# Uso:
#   sudo ./hardening_seguranca_servidor.sh              # interativo (pede confirmação)
#   sudo ./hardening_seguranca_servidor.sh --dry-run    # só mostra o que faria
#   sudo ./hardening_seguranca_servidor.sh -y           # aplica tudo sem perguntar
#   ./hardening_seguranca_servidor.sh --help
#
# O script separa:
#   • ALTERAÇÕES  → firewall, bloqueio de IP, permissão do .env (pede confirmação)
#   • AUDITORIAS  → Docker, MySQL, arquivos (somente leitura, sem risco)

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

IPS_ATACANTES=("159.65.156.239")
PORTAS_BLOQUEAR=(8081 8082 3306 3308 8000)
PORTAS_PERMITIR=(22 80 443)

DRY_RUN=false
AUTO_YES=false

AVISOS=0
ERROS=0
PULADOS=0
APLICADOS=0

log_info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[OK]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[AVISO]${NC} $1"; AVISOS=$((AVISOS + 1)); }
log_error()   { echo -e "${RED}[ERRO]${NC} $1"; ERROS=$((ERROS + 1)); }
log_dry()     { echo -e "${MAGENTA}[DRY-RUN]${NC} $1"; }
log_skip()    { echo -e "${YELLOW}[PULADO]${NC} $1"; PULADOS=$((PULADOS + 1)); }

log_section() {
    echo ""
    echo -e "${CYAN}========================================${NC}"
    echo -e "${CYAN}$1${NC}"
    echo -e "${CYAN}========================================${NC}"
}

show_help() {
    cat <<EOF
Hardening de segurança — IntegraExpert

Uso:
  sudo $0 [opções]

Opções:
  --dry-run, -n    Simula tudo sem alterar nada (recomendado na 1ª execução)
  --yes, -y        Aplica todas as alterações sem pedir confirmação
  --help, -h       Exibe esta ajuda

Modo padrão (interativo):
  1. Mostra resumo do que será alterado
  2. Pede confirmação geral para iniciar
  3. Pede confirmação antes de CADA alteração (firewall, IPs, .env)
  4. Executa auditorias (somente leitura) automaticamente
  5. Lista ações manuais pendentes

Exemplos:
  sudo $0 --dry-run          # ver plano sem mudar nada
  sudo $0                    # interativo
  sudo $0 -y                 # aplicar tudo (cuidado)

Valide depois no seu PC:
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

require_root() {
    if [[ "$DRY_RUN" == true ]]; then
        return
    fi
    if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
        log_error "Execute como root: sudo $0"
        exit 1
    fi
}

# Pede confirmação. Retorna 0 = sim, 1 = não
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

confirmar_etapa() {
    local titulo="$1"
    local descricao="$2"

    if [[ "$DRY_RUN" == true ]]; then
        log_dry "Etapa \"$titulo\" seria executada: $descricao"
        return 0
    fi

    confirmar "$titulo — $descricao"
}

ufw_listar_regras_para_remover() {
    ufw status numbered 2>/dev/null | grep -E '8081|8082|8000/tcp' || true
}

ufw_rule_exists() {
    local pattern="$1"
    ufw status 2>/dev/null | grep -qE "$pattern"
}

ufw_delete_rules_matching() {
    local pattern="$1"
    local deleted=0

    while true; do
        local line num
        line=$(ufw status numbered 2>/dev/null | grep -E "$pattern" | tail -1 || true)
        [[ -z "$line" ]] && break

        num=$(echo "$line" | sed -n 's/^\[[[:space:]]*\([0-9]*\)\].*/\1/p')
        [[ -z "$num" ]] && break

        if [[ "$DRY_RUN" == true ]]; then
            log_dry "Removeria regra UFW [$num]: $line"
            deleted=$((deleted + 1))
            # Simular remoção para não loop infinito — em dry-run contamos uma vez por chamada grep
            break
        fi

        ufw --force delete "$num" >/dev/null
        deleted=$((deleted + 1))
    done

    # Em dry-run, contar todas as regras que seriam removidas
    if [[ "$DRY_RUN" == true ]]; then
        ufw status numbered 2>/dev/null | grep -cE "$pattern" || echo "0"
        return
    fi

    echo "$deleted"
}

porta_exposta_publicamente() {
    local porta="$1"
    if command -v ss >/dev/null 2>&1; then
        ss -tlnp 2>/dev/null | grep -qE "0\.0\.0\.0:${porta}|\[::\]:${porta}|\*:${porta}"
        return $?
    fi
    if command -v netstat >/dev/null 2>&1; then
        netstat -tlnp 2>/dev/null | grep -qE ":${porta} "
        return $?
    fi
    return 1
}

mostrar_resumo_inicial() {
    log_section "RESUMO — O QUE ESTE SCRIPT FAZ"

    if [[ "$DRY_RUN" == true ]]; then
        echo -e "${MAGENTA}Modo: DRY-RUN (nenhuma alteração será aplicada)${NC}"
    elif [[ "$AUTO_YES" == true ]]; then
        echo -e "${YELLOW}Modo: automático (-y) — alterações sem confirmação individual${NC}"
    else
        echo -e "${GREEN}Modo: interativo — pede confirmação antes de cada alteração${NC}"
    fi

    echo ""
    echo -e "${RED}ALTERAÇÕES (pedem confirmação no modo interativo):${NC}"
    echo "  1. Firewall UFW"
    echo "     • Remover regras ALLOW que liberam 8081, 8082 ou 8000"
    echo "     • Garantir ALLOW em 22, 80 e 443"
    echo "     • Adicionar DENY em: ${PORTAS_BLOQUEAR[*]}"
    echo "     • Ativar o UFW (persiste após reinício)"
    echo ""
    echo "  2. Bloqueio de IPs atacantes"
    echo "     • IPs: ${IPS_ATACANTES[*]}"
    echo ""
    echo "  3. Permissões do .env"
    echo "     • Ajustar para 600 se estiver mais aberto"

    echo ""
    echo -e "${BLUE}AUDITORIAS (somente leitura — sem alterar dados):${NC}"
    echo "  4. Docker — containers e portas expostas"
    echo "  5. MySQL — bancos, ransomware, usuários remotos"
    echo "  6. Laravel — APP_DEBUG, APP_ENV, senhas fracas"
    echo "  7. Arquivos sensíveis na raiz e em public/"

    echo ""
    echo -e "${YELLOW}NÃO FAZ (você precisa fazer manualmente):${NC}"
    echo "  • Trocar senhas MySQL / .env"
    echo "  • Restaurar banco de dados"
    echo "  • Alterar docker-compose.yml (portas 127.0.0.1 — Docker ignora UFW)"
    echo "  • Desabilitar /register"

    echo ""
    echo -e "${YELLOW}IMPORTANTE:${NC} UFW sozinho NÃO bloqueia portas do Docker em 0.0.0.0."
    echo "  Use 127.0.0.1:8081 e 127.0.0.1:8082 no docker-compose.yml + docker compose up -d"

  if command -v ufw >/dev/null 2>&1; then
        echo ""
        echo -e "${CYAN}Estado atual do UFW:${NC}"
        ufw status numbered 2>/dev/null || ufw status 2>/dev/null || true

        local regras_conflito
        regras_conflito=$(ufw_listar_regras_para_remover)
        if [[ -n "$regras_conflito" ]]; then
            echo ""
            echo -e "${YELLOW}Regras conflitantes detectadas (seriam removidas):${NC}"
            echo "$regras_conflito"
        fi
    fi

    local env_file="$PROJECT_DIR/.env"
    if [[ -f "$env_file" ]]; then
        local permissoes
        permissoes=$(stat -c '%a' "$env_file" 2>/dev/null || echo "?")
        if [[ "$permissoes" != "600" && "$permissoes" != "640" ]]; then
            echo ""
            echo -e "${YELLOW}.env com permissão $permissoes → seria ajustado para 600${NC}"
        fi
    fi
}

configurar_firewall() {
    log_section "1. Firewall UFW"

    if ! command -v ufw >/dev/null 2>&1; then
        log_error "UFW não instalado. Instale com: apt install ufw"
        return 1
    fi

    if [[ "$DRY_RUN" == true ]]; then
        log_dry "Removeria regras ALLOW conflitantes (8081/8082/8000):"
        ufw_listar_regras_para_remover | while read -r linha; do
            [[ -n "$linha" ]] && log_dry "  $linha"
        done
    else
        local removidas=0
        while ufw status numbered 2>/dev/null | grep -qE '8081|8082|8000/tcp'; do
            local line num
            line=$(ufw status numbered 2>/dev/null | grep -E '8081|8082|8000/tcp' | tail -1)
            num=$(echo "$line" | sed -n 's/^\[[[:space:]]*\([0-9]*\)\].*/\1/p')
            ufw --force delete "$num" >/dev/null
            removidas=$((removidas + 1))
        done
        if [[ "$removidas" -gt 0 ]]; then
            log_success "Removidas $removidas regra(s) UFW conflitantes"
            APLICADOS=$((APLICADOS + removidas))
        else
            log_info "Nenhuma regra ALLOW conflitante para 8081/8082/8000"
        fi
    fi

    for porta in "${PORTAS_PERMITIR[@]}"; do
        if ! ufw_rule_exists "ALLOW.*${porta}"; then
            if [[ "$DRY_RUN" == true ]]; then
                log_dry "Adicionaria: ufw allow ${porta}/tcp"
            else
                ufw allow "${porta}/tcp" >/dev/null
                log_success "Liberada porta $porta/tcp"
                APLICADOS=$((APLICADOS + 1))
            fi
        else
            log_info "Porta $porta/tcp já liberada"
        fi
    done

    for porta in "${PORTAS_BLOQUEAR[@]}"; do
        if ! ufw_rule_exists "DENY.*${porta}"; then
            if [[ "$DRY_RUN" == true ]]; then
                log_dry "Adicionaria: ufw deny ${porta}/tcp"
            else
                ufw deny "${porta}/tcp" >/dev/null
                log_success "Bloqueada porta $porta/tcp"
                APLICADOS=$((APLICADOS + 1))
            fi
        else
            log_info "Porta $porta/tcp já bloqueada"
        fi
    done

    if [[ "$DRY_RUN" == true ]]; then
        log_dry "Ativaria o UFW (ufw --force enable)"
    else
        ufw --force enable >/dev/null 2>&1 || true
        log_success "UFW ativo e persistirá após reinício"
    fi

    echo ""
    log_info "Regras UFW ${DRY_RUN:+que ficariam }após esta etapa:"
    ufw status numbered 2>/dev/null || true
}

bloquear_ips_atacantes() {
    log_section "2. Bloqueio de IPs atacantes"

    for ip in "${IPS_ATACANTES[@]}"; do
        if ufw status 2>/dev/null | grep -q "$ip"; then
            log_info "IP $ip já bloqueado no UFW"
        elif [[ "$DRY_RUN" == true ]]; then
            log_dry "Adicionaria: ufw deny from $ip"
        else
            ufw deny from "$ip" >/dev/null
            log_success "IP $ip bloqueado no UFW"
            APLICADOS=$((APLICADOS + 1))
        fi
    done
}

corrigir_permissoes_env() {
    log_section "3. Permissões do .env"

    local env_file="$PROJECT_DIR/.env"
    if [[ ! -f "$env_file" ]]; then
        log_warning "Arquivo .env não encontrado em $PROJECT_DIR"
        return
    fi

    local permissoes
    permissoes=$(stat -c '%a' "$env_file" 2>/dev/null || echo "?")

    if [[ "$permissoes" == "600" || "$permissoes" == "640" ]]; then
        log_success "Permissões do .env já adequadas: $permissoes"
        return
    fi

    log_warning "Permissões atuais do .env: $permissoes (recomendado: 600)"

    if [[ "$DRY_RUN" == true ]]; then
        log_dry "Executaria: chmod 600 $env_file"
        return
    fi

    chmod 600 "$env_file"
    log_success "Permissões do .env corrigidas para 600"
    APLICADOS=$((APLICADOS + 1))
}

auditar_docker() {
    log_section "4. Auditoria Docker (somente leitura)"

    if ! command -v docker >/dev/null 2>&1; then
        log_warning "Docker não encontrado"
        return
    fi

    if ! docker ps >/dev/null 2>&1; then
        log_warning "Docker não acessível"
        return
    fi

    log_info "Containers em execução:"
    docker ps --format "table {{.Names}}\t{{.Ports}}" 2>/dev/null || docker ps

    for porta in "${PORTAS_BLOQUEAR[@]}"; do
        if porta_exposta_publicamente "$porta"; then
            log_error "Porta $porta em 0.0.0.0 — Docker IGNORA o UFW! Corrija o docker-compose.yml:"
            log_error "  troque \"808X:...\" por \"127.0.0.1:808X:...\" e rode: docker compose up -d"
        else
            log_success "Porta $porta não exposta publicamente no host"
        fi
    done

    if [[ -f "$PROJECT_DIR/docker-compose.yml" ]]; then
        grep -qE 'MYSQL_ROOT_PASSWORD:\s*root' "$PROJECT_DIR/docker-compose.yml" \
            && log_warning "docker-compose.yml: MYSQL_ROOT_PASSWORD=root"
        grep -qE 'MYSQL_PASSWORD:\s*secret' "$PROJECT_DIR/docker-compose.yml" \
            && log_warning "docker-compose.yml: MYSQL_PASSWORD=secret"
        grep -qE '"8082:80"' "$PROJECT_DIR/docker-compose.yml" \
            && log_warning "phpMyAdmin na 8082 — use túnel SSH (acessar_phpmyadmin_ssh.sh)"
        grep -qE '"8081:8000"' "$PROJECT_DIR/docker-compose.yml" \
            && log_warning "artisan serve na 8081 — use Apache/Nginx em produção"
        grep -qE '3308:3306|0\.0\.0\.0:3306' "$PROJECT_DIR/docker-compose.yml" \
            && log_warning "MySQL mapeado para o host"
    fi
}

auditar_mysql() {
    log_section "5. Auditoria MySQL (somente leitura)"

    if ! docker ps 2>/dev/null | grep -q "integrar-db"; then
        log_warning "Container integrar-db não está rodando"
        return
    fi

    local root_pass="${MYSQL_ROOT_PASSWORD:-root}"

    log_info "Bancos de dados:"
    docker exec integrar-db mysql -u root -p"${root_pass}" -e "SHOW DATABASES;" 2>/dev/null \
        || log_warning "Não foi possível conectar ao MySQL (verifique senha root)"

    local ransomware
    ransomware=$(docker exec integrar-db mysql -u root -p"${root_pass}" -N -e \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_name='readme_to_recover';" 2>/dev/null || echo "0")

    if [[ "$ransomware" -gt 0 ]]; then
        log_error "Tabela readme_to_recover detectada — ransomware! Restaure do backup."
    else
        log_success "Tabela readme_to_recover não encontrada"
    fi

    log_info "Usuários MySQL com acesso remoto:"
    docker exec integrar-db mysql -u root -p"${root_pass}" -e \
        "SELECT User, Host FROM mysql.user WHERE Host NOT IN ('localhost','127.0.0.1','::1');" 2>/dev/null \
        || true
}

auditar_laravel() {
    log_section "6. Auditoria Laravel (somente leitura)"

    local env_file="$PROJECT_DIR/.env"
    if [[ ! -f "$env_file" ]]; then
        log_warning "Arquivo .env não encontrado"
        return
    fi

    grep -qE '^APP_DEBUG=true' "$env_file" \
        && log_warning "APP_DEBUG=true — altere para false" \
        || log_success "APP_DEBUG não está true"

    grep -qE '^APP_ENV=local' "$env_file" \
        && log_warning "APP_ENV=local — altere para production"

    grep -qE '^DB_PASSWORD=secret' "$env_file" \
        && log_warning "DB_PASSWORD=secret — troque a senha"
}

auditar_arquivos_sensiveis() {
    log_section "7. Arquivos sensíveis (somente leitura)"

    find "$PROJECT_DIR" -maxdepth 1 -type f \( -name "*.sql" -o -name "backup*.sql" \) 2>/dev/null | while read -r arquivo; do
        log_warning "Backup SQL na raiz: $(basename "$arquivo")"
    done

    if find "$PROJECT_DIR/public" -type f \( -name "*.sql" -o -name ".env" \) 2>/dev/null | grep -q .; then
        log_error "Arquivos .sql ou .env expostos em public/"
    else
        log_success "Nenhum .sql/.env em public/"
    fi
}

mostrar_acoes_manuais() {
    log_section "8. Ações manuais pendentes"

    cat <<'EOF'
  [ ] Trocar senhas MySQL no .env e docker-compose.yml
  [ ] docker compose down && docker compose up -d
  [ ] Restaurar banco: ./script-manutencao/verificar_restaurar_banco.sh
  [ ] Trocar senha do admin@admin.com
  [ ] Desabilitar /register em produção
  [ ] Se 8081/8082 ainda abertas: docker-compose com 127.0.0.1 e docker compose up -d
  [ ] Validar de fora: ./script-manutencao/testar_seguranca_externo.sh

  phpMyAdmin seguro (no seu PC):
    ./script-manutencao/acessar_phpmyadmin_ssh.sh → http://localhost:9082
EOF
}

main() {
    parse_args "$@"

    log_section "HARDENING DE SEGURANÇA — INTEGRAEXPERT"
    log_info "Projeto: $PROJECT_DIR"
    log_info "Data: $(date '+%Y-%m-%d %H:%M:%S')"

    require_root
    mostrar_resumo_inicial

    if [[ "$DRY_RUN" != true && "$AUTO_YES" != true ]]; then
        echo ""
        if ! confirmar "Deseja iniciar o hardening?"; then
            log_skip "Execução cancelada pelo usuário."
            exit 0
        fi
    fi

    # --- ALTERAÇÕES (com confirmação) ---
    if confirmar_etapa "Etapa 1/3" "Configurar firewall UFW (remove ALLOW 8081/8082, bloqueia portas sensíveis)"; then
        configurar_firewall
    else
        log_skip "Firewall UFW não alterado."
    fi

    if confirmar_etapa "Etapa 2/3" "Bloquear IPs atacantes (${IPS_ATACANTES[*]})"; then
        bloquear_ips_atacantes
    else
        log_skip "Bloqueio de IPs não aplicado."
    fi

    if confirmar_etapa "Etapa 3/3" "Corrigir permissões do .env (chmod 600)"; then
        corrigir_permissoes_env
    else
        log_skip "Permissões do .env não alteradas."
    fi

    # --- AUDITORIAS (somente leitura) ---
    if [[ "$DRY_RUN" == true ]]; then
        log_dry "Executaria auditorias 4–7 (somente leitura)"
    fi
    auditar_docker
    auditar_mysql
    auditar_laravel
    auditar_arquivos_sensiveis
    mostrar_acoes_manuais

    log_section "RESUMO FINAL"
    if [[ "$DRY_RUN" == true ]]; then
        echo -e "${MAGENTA}Modo DRY-RUN — nenhuma alteração foi aplicada.${NC}"
        echo "Execute sem --dry-run para aplicar: sudo $0"
    else
        echo -e "Alterações aplicadas: ${GREEN}${APLICADOS}${NC} | Puladas: ${YELLOW}${PULADOS}${NC}"
        echo -e "Avisos: ${YELLOW}${AVISOS}${NC} | Erros: ${RED}${ERROS}${NC}"
        log_info "Regras UFW persistem após reinício."
    fi
    log_info "Valide de fora: ./script-manutencao/testar_seguranca_externo.sh integraexpert.com.br"

    [[ "$ERROS" -gt 0 ]] && exit 2
    [[ "$AVISOS" -gt 0 ]] && exit 1
    exit 0
}

main "$@"
