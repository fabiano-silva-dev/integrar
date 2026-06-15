#!/bin/bash
#
# Testes de segurança externos — executar NO SEU COMPUTADOR (fora do servidor)
# Uso: ./testar_seguranca_externo.sh [host]
# Exemplo: ./testar_seguranca_externo.sh integraexpert.com.br
#
# Verifica se portas sensíveis estão bloqueadas e se o site responde corretamente.

set -euo pipefail

HOST="${1:-integraexpert.com.br}"
TIMEOUT=5

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

PASS=0
FAIL=0
WARN=0

log_section() {
    echo ""
    echo -e "${CYAN}========================================${NC}"
    echo -e "${CYAN}$1${NC}"
    echo -e "${CYAN}========================================${NC}"
}

pass() { echo -e "${GREEN}[PASS]${NC} $1"; PASS=$((PASS + 1)); }
fail() { echo -e "${RED}[FAIL]${NC} $1"; FAIL=$((FAIL + 1)); }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; WARN=$((WARN + 1)); }
info() { echo -e "${BLUE}[INFO]${NC} $1"; }

# Retorna 0 se a porta parece FECHADA (bom para portas sensíveis)
porta_fechada() {
    local host="$1"
    local porta="$2"

    if command -v nc >/dev/null 2>&1; then
        if nc -z -w "$TIMEOUT" "$host" "$porta" 2>/dev/null; then
            return 1
        fi
        return 0
    fi

    if timeout "$TIMEOUT" bash -c "echo >/dev/tcp/${host}/${porta}" 2>/dev/null; then
        return 1
    fi
    return 0
}

# Retorna 0 se a porta parece ABERTA (bom para 80/443)
porta_aberta() {
    local host="$1"
    local porta="$2"
    ! porta_fechada "$host" "$porta"
}

# Retorna 0 se HTTP não responde ou falha (bom para serviços que devem estar bloqueados)
http_inacessivel() {
    local url="$1"
    local code

    # curl com timeout imprime "000" e sai com erro — evitar "|| echo 000" que duplica o código
    code=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout "$TIMEOUT" --max-time "$((TIMEOUT * 2))" "$url" 2>/dev/null) || true
    code="${code:-000}"

    [[ "$code" == "000" ]]
}

http_acessivel() {
    local url="$1"
    local code

    code=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout "$TIMEOUT" --max-time "$((TIMEOUT * 2))" -k "$url" 2>/dev/null) || true
    code="${code:-000}"

    [[ "$code" =~ ^[23] ]]
}

testar_portas_sensiveis() {
    log_section "1. Portas que devem estar FECHADAS"

    local portas=(8081 8082 3306 3308 8000)
    for porta in "${portas[@]}"; do
        if porta_fechada "$HOST" "$porta"; then
            pass "Porta $porta fechada ou filtrada"
        else
            fail "Porta $porta ABERTA — risco crítico de exposição"
        fi
    done
}

testar_portas_web() {
    log_section "2. Portas do site (devem estar abertas)"

    if porta_aberta "$HOST" 80; then
        pass "Porta 80 (HTTP) acessível"
    else
        warn "Porta 80 não respondeu (pode redirecionar só para HTTPS)"
    fi

    if porta_aberta "$HOST" 443; then
        pass "Porta 443 (HTTPS) acessível"
    else
        fail "Porta 443 (HTTPS) não acessível"
    fi
}

testar_servicos_http() {
    log_section "3. Serviços HTTP sensíveis (devem falhar)"

    if http_inacessivel "http://${HOST}:8082/"; then
        pass "phpMyAdmin (:8082) inacessível pela internet"
    else
        fail "phpMyAdmin (:8082) RESPONDE pela internet — bloqueie no UFW"
    fi

    if http_inacessivel "http://${HOST}:8081/"; then
        pass "App dev (:8081) inacessível pela internet"
    else
        fail "App dev (:8081) RESPONDE pela internet — bloqueie no UFW"
    fi

    if http_acessivel "https://${HOST}/"; then
        pass "Site principal HTTPS responde"
    else
        fail "Site principal HTTPS não responde"
    fi
}

testar_arquivos_sensiveis() {
    log_section "4. Arquivos sensíveis (não devem vazar)"

    local arquivos=(".env" "backup-integrar.sql" "backup.sql" "database.sql" ".git/config")
    for arquivo in "${arquivos[@]}"; do
        local code
        code=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout "$TIMEOUT" "https://${HOST}/${arquivo}" 2>/dev/null || echo "000")

        if [[ "$code" == "200" ]]; then
            fail "Arquivo exposto: https://${HOST}/${arquivo} (HTTP $code)"
        elif [[ "$code" == "403" ]]; then
            pass "Arquivo bloqueado: /${arquivo} (HTTP 403)"
        else
            pass "Arquivo não acessível: /${arquivo} (HTTP $code)"
        fi
    done
}

testar_registro_publico() {
    log_section "5. Registro de usuários (informativo)"

    local code body
    code=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout "$TIMEOUT" "https://${HOST}/register" 2>/dev/null || echo "000")

    if [[ "$code" == "200" ]]; then
        warn "Página /register acessível (HTTP 200) — desabilite em produção"
    elif [[ "$code" == "404" ]]; then
        pass "Página /register não encontrada (HTTP 404)"
    else
        info "Página /register retornou HTTP $code"
    fi
}

testar_nmap() {
    log_section "6. Scan nmap (opcional)"

    if ! command -v nmap >/dev/null 2>&1; then
        info "nmap não instalado — pulando (apt install nmap)"
        return
    fi

    info "Executando nmap nas portas críticas..."
    nmap -Pn -p 22,80,443,3306,8000,8081,8082 "$HOST" 2>/dev/null | grep -E "^[0-9]+/|Nmap scan|Host is"
}

gerar_relatorio() {
    log_section "RELATÓRIO FINAL"
    echo ""
    echo -e "  Host testado: ${CYAN}${HOST}${NC}"
    echo -e "  ${GREEN}PASS: ${PASS}${NC}  ${RED}FAIL: ${FAIL}${NC}  ${YELLOW}WARN: ${WARN}${NC}"
    echo ""

    if [[ "$FAIL" -eq 0 && "$WARN" -eq 0 ]]; then
        echo -e "${GREEN}✅ Todos os testes críticos passaram.${NC}"
        return 0
    fi

    if [[ "$FAIL" -eq 0 ]]; then
        echo -e "${YELLOW}⚠️  Sem falhas críticas, mas há avisos para revisar.${NC}"
        return 1
    fi

    echo -e "${RED}❌ Falhas críticas detectadas. Execute no servidor:${NC}"
    echo "   sudo ./script-manutencao/hardening_seguranca_servidor.sh"
    return 2
}

main() {
    log_section "TESTE DE SEGURANÇA EXTERNO — ${HOST}"
    info "Executado em: $(date '+%Y-%m-%d %H:%M:%S')"
    info "Timeout por teste: ${TIMEOUT}s"
    echo ""

    testar_portas_sensiveis
    testar_portas_web
    testar_servicos_http
    testar_arquivos_sensiveis
    testar_registro_publico
    testar_nmap
    gerar_relatorio
}

main "$@"
