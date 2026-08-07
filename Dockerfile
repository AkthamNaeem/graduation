FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath gd

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/app/private \
    storage/app/public \
    storage/logs \
    bootstrap/cache

RUN composer install --no-dev --optimize-autoloader

RUN if [ -e public/storage ] && [ ! -L public/storage ]; then \
        echo "public/storage exists and is not a symbolic link" >&2; \
        exit 1; \
    fi \
    && php artisan storage:link --force \
    && test -L public/storage \
    && test "$(readlink -f public/storage)" = "$(readlink -f storage/app/public)"

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

RUN a2enmod rewrite

COPY ./docker/apache.conf /etc/apache2/sites-available/000-default.conf

EXPOSE 80
