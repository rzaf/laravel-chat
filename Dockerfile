FROM php:8.3-fpm AS php

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

# FFmpeg and FFprobe
RUN apt-get update && apt-get install -y \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# PHP configuration
RUN cat > /usr/local/etc/php/conf.d/uploads.ini <<'EOF'
upload_max_filesize = 100M
post_max_size = 110M
memory_limit = 512M
max_file_uploads = 10
max_input_time = 300
max_execution_time = 300
EOF

# Composer
COPY --from=composer:2.3.5 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . /var/www

# RUN composer install

# Launch PHP-FPM directly and keep Reverb in the foreground
CMD php-fpm -D && php artisan reverb:start