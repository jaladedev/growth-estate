FROM php:8.2-fpm

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    && docker-php-ext-install pdo_pgsql mbstring exif pcntl bcmath gd zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# DO NOT copy files here when using volumes
# DO NOT run composer or migrations here

RUN chown -R www-data:www-data /var/www/html

CMD ["php-fpm"]
