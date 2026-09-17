FROM php:8.4-fpm

WORKDIR /var/www

RUN apt-get update && apt-get install -y \
    git unzip zip curl \
    && docker-php-ext-install pdo pdo_mysql

# Phase K2-E: production-oriented overrides (display_errors off,
# error logging to container stderr, PHP version header removed).
# Dropped into conf.d so it layers on top of the image's own
# php.ini-production rather than replacing it. See docker/php/php.ini's
# own header comment for the full rationale.
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-k2e-production.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

CMD ["php-fpm"]