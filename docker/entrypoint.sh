#!/bin/sh
set -e

mkdir -p /app/runtime /app/web/assets /app/assets/COTAHIST/ZIP
chmod -R 777 /app/runtime /app/web/assets /app/assets/COTAHIST

if [ ! -f /app/vendor/autoload.php ]; then
    composer install --no-scripts --prefer-dist
    composer dump-autoload --optimize
fi

exec "$@"
