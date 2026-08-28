#!/usr/bin/env bash
# Atalho — deps do runner Automação Fiscal (Node/Playwright) em produção nativa.
exec "$(cd "$(dirname "$0")" && pwd)/script-manutencao/instalar-deps-automacao-fiscal.sh" "$@"
