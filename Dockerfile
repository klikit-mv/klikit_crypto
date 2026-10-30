# Production image: PHP-FPM application (web, Horizon workers and scheduler share it).
FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

FROM php:8.3-fpm-alpine
RUN apk add --no-cache icu-libs libzip mysql-client \
    && apk add --no-cache --virtual .build $PHPIZE_DEPS icu-dev libzip-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip pcntl opcache bcmath \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del .build
COPY deploy/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
RUN rm -rf services tools tests .github && mkdir -p storage/backups && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm"]
