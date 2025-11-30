FROM php:8.1-fpm

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
    libmagickwand-dev \
    mariadb-client \
    npm

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

RUN pecl install imagick \
    && docker-php-ext-enable imagick

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath gd

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create system user to run Composer and Artisan Commands
RUN useradd -G www-data,root -u 1000 -d /home/crater-user crater-user
RUN mkdir -p /home/crater-user/.composer && \
    chown -R crater-user:crater-user /home/crater-user

# Mounted volumes
COPY ./ /var/www
COPY ./docker-compose/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY ./uffizzi/.env.example /var/www/.env

# Set working directory
WORKDIR /var/www

# Fix permissions - run as root first
RUN chown -R crater-user:crater-user /var/www
RUN chmod -R 775 /var/www/composer.json /var/www/composer.lock \
    /var/www/storage/framework/ \
    /var/www/storage/logs/ \
    /var/www/bootstrap/cache/ \
    /home/crater-user/.composer
RUN chmod -R 775 /var/log || true

# Cleanup manually generated build files
RUN rm -rf /var/www/public/build

# Configure npm for root user (needed during build)
RUN npm config set unsafe-perm true

# Frontend building - temporarily switch to sqlite for build
RUN sed -i 's/DB_CONNECTION=mysql/DB_CONNECTION=sqlite/g' /var/www/.env || true
RUN sed -i 's/DB_DATABASE=crater/DB_DATABASE=\/tmp\/crater.sqlite/g' /var/www/.env || true
RUN touch /tmp/crater.sqlite || true

# Install dependencies and build
RUN composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
RUN npm ci --legacy-peer-deps || npm install --legacy-peer-deps
RUN npm install --save-dev sass --legacy-peer-deps || true
RUN NODE_OPTIONS="--max-old-space-size=4096" npx vite build --target=es2020 || \
    NODE_OPTIONS="--max-old-space-size=4096" /usr/bin/npx vite build --target=es2020

# Restore mysql settings
RUN sed -i 's/DB_CONNECTION=sqlite/DB_CONNECTION=mysql/g' /var/www/.env || true
RUN sed -i 's/DB_DATABASE=\/tmp\/crater.sqlite/DB_DATABASE=crater/g' /var/www/.env || true

# Switch to non-root user for runtime
USER crater-user

# Expose port
EXPOSE 8000

# Start command (will be overridden by Railway)
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
