#!/bin/sh
set -e

DB_HOST="${DB_HOST:-mysql}"
DB_PORT="${DB_PORT:-3306}"

echo "[entrypoint] waiting for ${DB_HOST}:${DB_PORT} ..."
i=0
until php -r 'exit(@fsockopen(getenv("DB_HOST") ?: "mysql", (int) (getenv("DB_PORT") ?: 3306)) ? 0 : 1);' 2>/dev/null; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        echo "[entrypoint] ${DB_HOST}:${DB_PORT} not reachable after ${i}s, continuing anyway" >&2
        break
    fi
    sleep 1
done

if [ -f /var/www/html/artisan ] && [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ is missing, run: docker compose exec app composer install" >&2
fi

if [ -d /var/www/html/storage ]; then
    for d in framework/cache framework/cache/data framework/sessions framework/views \
             framework/testing logs app/public; do
        mkdir -p "/var/www/html/storage/${d}"
    done
    rm -rf "/var/www/html/storage/framework/{cache,sessions,views}"
    mkdir -p /var/www/html/bootstrap/cache
    chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
fi

if [ -f /var/www/html/.env ] \
    && ! grep -q '^APP_KEY=base64:' /var/www/html/.env \
    && [ -f /var/www/html/vendor/autoload.php ]; then
    echo "[entrypoint] APP_KEY is empty, generating one"
    php /var/www/html/artisan key:generate --force || true
fi

exec "$@"
