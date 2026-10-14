#!/bin/sh
set -e

cd /var/www/html

if [ ! -f composer.json ]; then
    echo "composer.json is missing from /var/www/html" >&2
    exit 1
fi

export COMPOSER_ALLOW_SUPERUSER=1
mkdir -p var
if [ ! -f vendor/autoload.php ]; then
    flock var/composer-install.lock composer install --no-interaction --prefer-dist
fi

mkdir -p var/cache var/log var/share
chown -R www-data:www-data var

exec docker-php-entrypoint "$@"
