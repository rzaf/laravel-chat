FROM php:8.3 as php

RUN apt-get update && apt-get install -y \
    unzip libzip-dev zip \
    && docker-php-ext-install pcntl pdo pdo_mysql bcmath zip


## install GD
# RUN apt-get update && apt-get install -y libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev libavif-dev \
#     && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif \
#     && docker-php-ext-install gd

# install imagick
RUN apt-get update && apt-get install -y libmagickwand-dev \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && rm -rf /var/lib/apt/lists/*

RUN cat > /usr/local/etc/php/conf.d/uploads.ini <<'EOF'
upload_max_filesize = 100M
post_max_size = 110M
memory_limit = 512M

max_file_uploads = 10
max_input_time = 300
max_execution_time = 300
EOF

COPY --from=composer:2.3.5 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . /var/www

# RUN composer install --no-dev --optimize-autoloader
RUN composer install

EXPOSE 8000

# CMD wait-for-it database:3306 -- php artisan migrate && php artisan serve --host=0.0.0.0 --port=8000

CMD php artisan serve --host=0.0.0.0 --port=8000 & php artisan reverb:start