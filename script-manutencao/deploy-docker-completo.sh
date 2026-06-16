#!/usr/bin/env bash
# DEPRECADO para atualizações rotineiras — use atualizar-producao.sh após git pull.
# Este script faz rebuild completo da imagem Docker (mais lento, mais arriscado).
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_DIR"

echo "⚠️  Para atualização após git pull, prefira:"
echo "    ./atualizar-producao.sh"
echo ""
echo "🚀 Deploy Docker completo (rebuild de imagem)"
echo "=============================================="

if [[ ! -f docker-compose.yml ]]; then
    echo "❌ Execute no diretório do projeto (docker-compose.yml não encontrado)."
    exit 1
fi

read -p "Continuar com rebuild completo? (s/N): " -n 1 -r
echo
[[ $REPLY =~ ^[Ss]$ ]] || exit 0

echo "📦 Backup automático..."
"$SCRIPT_DIR/backup-automatico.sh" --docker

echo "🛑 Parando containers (preservando volumes)..."
docker compose down

echo "🔨 Reconstruindo imagem..."
docker compose build --no-cache

echo "▶️ Iniciando containers..."
docker compose up -d
sleep 10

echo "🐍 Verificando dependências Python..."
docker compose exec -T app bash -c "
    if ! python3 -c 'import pandas, openpyxl, xlrd, numpy' 2>/dev/null; then
        apt-get update && apt-get install -y python3-pandas python3-openpyxl python3-xlrd python3-numpy
    fi
"

echo "🔄 Atualizando aplicação (migrate + cache)..."
"$SCRIPT_DIR/atualizar-producao.sh" --docker

docker compose ps
echo "✅ Deploy completo concluído — http://localhost:8081"
