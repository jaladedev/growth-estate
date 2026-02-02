FROM php:8.3-fpm

# Set working directory
WORKDIR /var/www/html

# Install only required system packages
RUN apt-get update && apt-get install -y --no-install-recommends \
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
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer globally (from official image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html

# Switch to non-root user (optional but cleaner)
USER www-data

# Start PHP-FPM
CMD ["php-fpm"]
