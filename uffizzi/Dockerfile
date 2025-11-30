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
    npm \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install imagick extension
RUN pecl install imagick \
    && docker-php-ext-enable imagick

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath gd

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create system user to run Composer and Artisan Commands
RUN useradd -G www-data,root -u 1000 -d /home/crater-user crater-user \
    && mkdir -p /home/crater-user/.composer \
    && chown -R crater-user:crater-user /home/crater-user

# Set working directory
WORKDIR /var/www

# Copy application files
COPY ./ /var/www/

# Copy PHP configuration if it exists
RUN if [ -f ./docker-compose/php/uploads.ini ]; then \
        cp ./docker-compose/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini; \
    fi

# Copy .env.example to .env if .env doesn't exist
RUN if [ -f ./uffizzi/.env.example ] && [ ! -f .env ]; then \
        cp ./uffizzi/.env.example .env; \
    elif [ ! -f .env ] && [ -f .env.example ]; then \
        cp .env.example .env; \
    fi

# Fix permissions - ensure directories exist first
RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache \
    storage/logs bootstrap/cache \
    && chown -R crater-user:crater-user /var/www \
    && chmod -R 775 storage bootstrap/cache || true

# Cleanup manually generated build files
RUN rm -rf /var/www/public/build || true

# Configure npm for root user (needed during build)
RUN npm config set unsafe-perm true

# Frontend building - temporarily switch to sqlite for build
RUN if [ -f .env ]; then \
        sed -i 's/DB_CONNECTION=mysql/DB_CONNECTION=sqlite/g' .env || true; \
        sed -i 's/DB_DATABASE=crater/DB_DATABASE=\/tmp\/crater.sqlite/g' .env || true; \
        touch /tmp/crater.sqlite || true; \
    fi

# Install PHP dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts || \
    composer install --no-interaction --prefer-dist --optimize-autoloader

# Install Node dependencies and build
RUN npm ci --legacy-peer-deps 2>/dev/null || \
    npm install --legacy-peer-deps || \
    npm install

# Install sass if needed
RUN npm install --save-dev sass --legacy-peer-deps 2>/dev/null || true

# Build frontend assets
RUN NODE_OPTIONS="--max-old-space-size=4096" npx vite build --target=es2020 2>&1 || \
    (export NODE_OPTIONS="--max-old-space-size=4096" && /usr/bin/npx vite build --target=es2020) || \
    echo "Vite build failed, continuing..."

# Restore mysql settings if .env exists
RUN if [ -f .env ]; then \
        sed -i 's/DB_CONNECTION=sqlite/DB_CONNECTION=mysql/g' .env || true; \
        sed -i 's/DB_DATABASE=\/tmp\/crater.sqlite/DB_DATABASE=crater/g' .env || true; \
    fi

# Run composer scripts after build
RUN composer dump-autoload --optimize || true

# Switch to non-root user for runtime
USER crater-user

# Expose port
EXPOSE 8000

# Start command (will be overridden by Railway)
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
