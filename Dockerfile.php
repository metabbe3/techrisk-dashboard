# 1. CHANGE THIS to PHP 8.4 to match your composer.lock requirements
FROM php:8.4-fpm

# 2. Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    libicu-dev  

# 3. Install PHP extensions
# Added 'intl' (required for Filament) and 'zip' (required for Excel exports)
RUN docker-php-ext-configure intl \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd intl zip

# 4. Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 5. Fix "Dubious Ownership" Git error
# This tells Git inside Docker to trust the mounted folder
RUN git config --global --add safe.directory /var/www/html

# 6. Install Node.js 20 (Required for Filament assets)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# 7. Set working directory
WORKDIR /var/www/html