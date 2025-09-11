#!/bin/bash

echo "🧹 Limpando caches e reconstruindo aplicação..."

# Limpar todos os caches primeiro
echo "📦 Limpando caches PHP..."
docker exec integrar-app php artisan config:clear
docker exec integrar-app php artisan route:clear 
docker exec integrar-app php artisan view:clear 
docker exec integrar-app php artisan cache:clear
docker exec integrar-app php artisan optimize:clear

# Redescobrir componentes Livewire
echo "🔍 Redescobrindo componentes Livewire..."
docker exec integrar-app php artisan livewire:discover

# Instalar dependências e compilar assets
echo "📦 Instalando dependências NPM..."
docker exec integrar-app npm install

echo "🏗️ Compilando assets frontend..."
docker exec integrar-app npm run build

# Recriar caches de produção
echo "⚡ Recriando caches de produção..."
docker exec integrar-app php artisan config:cache
docker exec integrar-app php artisan view:cache

echo "✅ Limpeza e reconstrução concluídas!"




