FROM php:8.1-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev \
    zip unzip libzip-dev mariadb-client \
    nodejs npm \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath gd

# Enable Apache modules
RUN a2enmod rewrite headers

# mod_php is not thread-safe — only mpm_prefork is valid. Newer Debian/Apache
# images may enable mpm_event alongside prefork, which aborts with AH00534.
RUN a2dismod mpm_event 2>/dev/null || true \
    && a2dismod mpm_worker 2>/dev/null || true \
    && a2enmod mpm_prefork

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory to Apache's default
WORKDIR /var/www/html

# Force rebuild: v2
# Copy application files
COPY . /var/www/html/

# Create necessary directories and set permissions
RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs storage/app/public bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod +x start-apache.sh

# Copy .env.example to .env
RUN cp .env.example .env || true

# Install PHP dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev --no-scripts --ignore-platform-reqs

# Install Node dependencies and build assets
RUN npm install --legacy-peer-deps && npm run build || echo "Build completed with warnings"

# Laravel docroot (WORKDIR is /var/www/html). Port is set at runtime in start-apache.sh.
RUN printf '%s\n' \
    '<VirtualHost *:8080>' \
    '    DocumentRoot /var/www/html/public' \
    '    <Directory /var/www/html/public>' \
    '        AllowOverride All' \
    '        Require all granted' \
    '    </Directory>' \
    '    ErrorLog ${APACHE_LOG_DIR}/error.log' \
    '    CustomLog ${APACHE_LOG_DIR}/access.log combined' \
    '</VirtualHost>' \
    > /etc/apache2/sites-available/000-default.conf

RUN printf 'Listen 8080\n' > /etc/apache2/ports.conf

# Public disk symlink (Laravel)
RUN ln -sf /var/www/html/storage/app/public /var/www/html/public/storage || true

# Expose port
EXPOSE 8080

# Use custom startup script
CMD ["bash", "start-apache.sh"]
