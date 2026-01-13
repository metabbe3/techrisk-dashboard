# PHP 8.4 Dockerfile for Laravel 12 + Filament 3.x
FROM php:8.4-fpm

# Set environment variables
ENV DEBIAN_FRONTEND=noninteractive

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    libicu-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libsodium-dev \
    libgmp-dev \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions with GD
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd intl zip \
    && pecl install redis \
    && docker-php-ext-enable redis

# Add custom PHP configuration for uploads
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configure Git to avoid ownership issues
RUN git config --global --add safe.directory /var/www/html

# Install Node.js 20.x (Required for Vite/Filament 3.x)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g npm@latest

# Install supervisor for queue workers
RUN mkdir -p /var/log/supervisor

# Copy supervisor configuration
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Create bootstrap/cache directory before composer install
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views \
    && chmod -R 777 bootstrap/cache storage

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && npm install \
    && npm run build

# Create storage link and fix permissions
RUN php artisan storage:link \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache public/storage

# Publish Livewire assets
RUN php artisan livewire:publish --assets

# Clear and cache configs
RUN php artisan config:clear \
    && php artisan cache:clear \
    && php artisan route:clear \
    && php artisan view:clear

EXPOSE 9000

CMD ["php-fpm"]
