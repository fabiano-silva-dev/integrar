#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/script-manutencao/atualizar-producao.sh"

"$SCRIPT" --help | grep -q -- 'www-data'
"$SCRIPT" --help | grep -q -- 'sudo'

echo "ok - atualizar-producao.sh --help"
