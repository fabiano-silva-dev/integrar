#!/usr/bin/env bash
# Importa dump SQL no MySQL do Docker.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_DIR"

ARQUIVO_BACKUP="${1:-backup-integrar.sql}"
BANCO="${2:-integrar_dalongaro}"
USUARIO="${DB_USERNAME:-laravel}"
SENHA="${DB_PASSWORD:-secret}"

if [[ ! -f "$ARQUIVO_BACKUP" ]]; then
    echo "❌ Arquivo não encontrado: $ARQUIVO_BACKUP"
    exit 1
fi

echo "Criando banco $BANCO (se não existir)..."
docker compose exec -T db mysql -u root -proot -e \
    "CREATE DATABASE IF NOT EXISTS \`$BANCO\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     GRANT ALL PRIVILEGES ON \`$BANCO\`.* TO '$USUARIO'@'%';
     FLUSH PRIVILEGES;"

echo "Importando $ARQUIVO_BACKUP → $BANCO..."
docker compose exec -T db mysql -u "$USUARIO" -p"$SENHA" "$BANCO" < "$ARQUIVO_BACKUP"

echo "✅ Importação concluída."
