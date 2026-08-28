#!/usr/bin/env bash
# Atalho — habilita Automação Fiscal em produção (passos com sudo).
exec "$(cd "$(dirname "$0")" && pwd)/script-manutencao/habilitar-automacao-fiscal-producao.sh" "$@"
