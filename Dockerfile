FROM php:8.2-fpm

WORKDIR /var/www/html

# Install system dependencies
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
    supervisor \
    && docker-php-ext-install pdo_pgsql mbstring exif pcntl bcmath gd zip

# Install Composer globally
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set permissions 
RUN chown -R www-data:www-data /var/www/html 

# CMD for app container
CMD ["php-fpm"]
