FROM php:8.1-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev \
    zip unzip libzip-dev mariadb-client \
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
RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chmod +x start.sh

# Copy .env.example to .env
RUN cp .env.example .env || true

# Install PHP dependencies only (assets are pre-built)
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev --no-scripts

# Expose port
EXPOSE 8000

# Use our custom start script
CMD ["bash", "start.sh"]

