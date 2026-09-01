FROM php:8.3 as php

RUN apt-get update && apt-get install -y \
    unzip libzip-dev zip \
    && docker-php-ext-install pcntl pdo pdo_mysql bcmath zip

COPY --from=composer:2.3.5 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . /var/www

RUN composer install --no-dev --optimize-autoloader

EXPOSE 8000

# CMD wait-for-it database:3306 -- php artisan migrate && php artisan serve --host=0.0.0.0 --port=8000

CMD php artisan serve --host=0.0.0.0 --port=8000 & php artisan reverb:start