#!/bin/bash

# Script para atualizar codigo_filial_matriz dos lançamentos
# Atualiza todos os lançamentos da importação ID 102 para codigo_filial_matriz = '0000522'

echo "Iniciando atualização do codigo_filial_matriz..."

# Executar o comando SQL via docker-compose
docker-compose exec -T db mysql -u root -proot integrar_dalongaro -e "UPDATE lancamentos SET codigo_filial_matriz = '0000522' WHERE importacao_id = 102;"

# Verificar se a atualização foi bem-sucedida
if [ $? -eq 0 ]; then
    echo "✅ Atualização realizada com sucesso!"
    
    # Mostrar quantos registros foram afetados
    docker-compose exec -T db mysql -u root -proot integrar_dalongaro -e "SELECT COUNT(*) as 'Registros atualizados' FROM lancamentos WHERE importacao_id = 102 AND codigo_filial_matriz = '0000522';"
else
    echo "❌ Erro ao executar a atualização!"
    exit 1
fi

echo "Atualização concluída em $(date)"
