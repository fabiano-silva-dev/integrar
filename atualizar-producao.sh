#!/usr/bin/env bash
exec "$(cd "$(dirname "$0")" && pwd)/script-manutencao/atualizar-producao.sh" "$@"
