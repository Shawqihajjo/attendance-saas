#!/bin/sh
set -eu

cd /var/www/html

if [ "$(id -u)" = "0" ]; then
    mkdir -p var/cache var/log var/share var/photos
    chown -R www-data:www-data var
fi

php bin/console cache:warmup --env=prod --no-debug

exec docker-php-entrypoint "$@"
