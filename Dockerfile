FROM php:8.1-cli

# Install system dependencies including Node.js 18
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev \
    zip unzip libzip-dev mariadb-client \
    && curl -fsSL https://deb.nodesource.com/setup_18.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath gd

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy application files
COPY . /var/www/

# Create necessary directories and set permissions
RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs storage/app/public bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chmod +x start.sh

# Copy .env.example to .env
RUN cp .env.example .env || true

# Install PHP dependencies (with Stripe SDK from updated composer.lock)
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev --no-scripts --ignore-platform-reqs

# Install and build frontend assets (with fixed pinia version)
RUN npm install --legacy-peer-deps && npm run build

# Expose port
EXPOSE 8000

# Use our custom start script
CMD ["bash", "start.sh"]

