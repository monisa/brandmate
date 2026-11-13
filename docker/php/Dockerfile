FROM php:8.4-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev \
    && docker-php-ext-install pdo_mysql zip intl bcmath pcntl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
