#!/usr/bin/env bash
# Corrige vhost SSL que ainda faz proxy para Docker (8081) após migração nativa.
set -euo pipefail

DEPLOY_DIR="${1:-/home/fabiano/Projetos/integrar}"
SSL_VHOST="${2:-/etc/apache2/sites-enabled/000-default-le-ssl.conf}"
PUBLIC_DIR="$DEPLOY_DIR/public"

[[ -f "$SSL_VHOST" ]] || { echo "Vhost SSL não encontrado: $SSL_VHOST" >&2; exit 1; }
[[ -d "$PUBLIC_DIR" ]] || { echo "Diretório public não encontrado: $PUBLIC_DIR" >&2; exit 1; }

cp "$SSL_VHOST" "${SSL_VHOST}.bak.$(date +%Y%m%d%H%M%S)"

sed -i \
    -e '/ProxyPreserveHost/d' \
    -e '/ProxyPassReverseCookie/d' \
    -e '/ProxyPassReverse[[:space:]]/d' \
    -e '/ProxyPass[[:space:]]/d' \
    "$SSL_VHOST"

if grep -qE '^[[:space:]]*DocumentRoot[[:space:]]' "$SSL_VHOST"; then
    sed -i "s|^[[:space:]]*DocumentRoot[[:space:]].*|    DocumentRoot $PUBLIC_DIR|" "$SSL_VHOST"
else
    sed -i "/^[[:space:]]*ServerAdmin/a\\
    DocumentRoot $PUBLIC_DIR\\
    DirectoryIndex index.php" "$SSL_VHOST"
fi

# www-data precisa atravessar /home/fabiano (750) até o public/
parent="$(dirname "$PUBLIC_DIR")"
while [[ "$parent" != "/" ]]; do
    chmod o+x "$parent" 2>/dev/null || true
    parent="$(dirname "$parent")"
done

apache2ctl configtest
systemctl reload apache2

echo "OK: $SSL_VHOST → DocumentRoot $PUBLIC_DIR"
curl -sI -k -H "Host: integraexpert.com.br" https://127.0.0.1/ | head -3
