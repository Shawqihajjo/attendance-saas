FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
        libsqlite3-dev \
        librabbitmq-dev \
    && docker-php-ext-install -j"$(nproc)" intl opcache pdo_mysql pdo_sqlite zip \
    && pecl install redis amqp \
    && docker-php-ext-enable redis amqp \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/zz-attendance.ini
COPY entrypoint.sh /usr/local/bin/attendance-entrypoint
RUN chmod +x /usr/local/bin/attendance-entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["attendance-entrypoint"]
CMD ["php-fpm"]
