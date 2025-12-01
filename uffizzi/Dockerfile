# Crater Railway Dockerfile - v4
ARG CACHEBUST=1733018400
FROM php:8.1-cli

# Install dependencies
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev \
    zip unzip libzip-dev mariadb-client \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath gd

# Get Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY . /var/www/

# Setup directories
RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Copy env
RUN cp .env.example .env || true

# Install PHP dependencies only
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev --no-scripts --ignore-platform-reqs || true
RUN composer dump-autoload --optimize || true

# Make start script executable
RUN chmod +x start.sh

# Assets are pre-built - skip npm entirely

CMD ["bash", "start.sh"]
