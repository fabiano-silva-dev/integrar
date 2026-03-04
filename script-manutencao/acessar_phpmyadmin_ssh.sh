#!/bin/bash

# Script para acessar phpMyAdmin do servidor de produção via SSH reverso
# Autor: Fabiano
# Data: $(date +%Y-%m-%d)

# Configurações
HOST="integrar"
PORTA_LOCAL="9082"
PORTA_REMOTA="8082"

echo "=========================================="
echo "  Acesso ao phpMyAdmin - Servidor Produção"
echo "=========================================="
echo ""
echo "Configuração:"
echo "  Host: $HOST"
echo "  Porta local: $PORTA_LOCAL"
echo "  Porta remota: $PORTA_REMOTA"
echo ""
echo "Criando túnel SSH reverso..."
echo ""

# Verifica se a porta local já está em uso
if lsof -Pi :$PORTA_LOCAL -sTCP:LISTEN -t >/dev/null ; then
    echo "❌ ERRO: A porta $PORTA_LOCAL já está em uso!"
    echo "   Tente uma porta diferente editando a variável PORTA_LOCAL no script."
    exit 1
fi

echo "🔗 Estabelecendo conexão com $HOST..."
echo "   Túnel: localhost:$PORTA_LOCAL -> $HOST:$PORTA_REMOTA"
echo ""
echo "✅ Túnel SSH ativo!"
echo ""
echo "📱 Acesse o phpMyAdmin em:"
echo "   http://localhost:$PORTA_LOCAL"
echo ""
echo "⚠️  Mantenha este terminal aberto enquanto usar o phpMyAdmin"
echo "   Para encerrar o túnel, pressione Ctrl+C"
echo ""
echo "=========================================="

# Cria o túnel SSH
ssh -L $PORTA_LOCAL:localhost:$PORTA_REMOTA $HOST

echo ""
echo "🔌 Túnel SSH encerrado."

