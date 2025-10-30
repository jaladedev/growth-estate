# Use official PHP image with required extensions
FROM php:8.2-fpm

# Set working directory
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
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy existing project files
COPY . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Run migrations
RUN php artisan migrate --force

# Set permissions for Laravel
RUN chown -R www-data:www-data storage bootstrap/cache

# Expose Render port and run Laravel's built-in server
EXPOSE 10000
CMD php artisan serve --host=0.0.0.0 --port=$PORT
