#!/bin/bash
# Apache startup script for Crater on Railway

set -e

PORT=${PORT:-8080}

echo "============================================"
echo "Crater Apache Startup Script"
echo "============================================"

# Create .env if it doesn't exist
if [ ! -f ".env" ]; then
    cp .env.example .env 2>/dev/null || touch .env
fi

# Remove existing settings
sed -i '/^DB_CONNECTION=/d' .env 2>/dev/null || true
sed -i '/^DB_HOST=/d' .env 2>/dev/null || true
sed -i '/^DB_PORT=/d' .env 2>/dev/null || true
sed -i '/^DB_DATABASE=/d' .env 2>/dev/null || true
sed -i '/^DB_USERNAME=/d' .env 2>/dev/null || true
sed -i '/^DB_PASSWORD=/d' .env 2>/dev/null || true
sed -i '/^APP_URL=/d' .env 2>/dev/null || true
sed -i '/^APP_NAME=/d' .env 2>/dev/null || true
sed -i '/^SESSION_DRIVER=/d' .env 2>/dev/null || true

# Calculate DB values from Railway env vars
DB_HOST_VAL="${DB_HOST:-${MYSQL_HOST:-${MYSQLHOST:-db.railway.internal}}}"
DB_PORT_VAL="${DB_PORT:-${MYSQL_PORT:-${MYSQLPORT:-3306}}}"
DB_NAME_VAL="${DB_DATABASE:-${MYSQL_DATABASE:-${MYSQLDATABASE:-crater}}}"
DB_USER_VAL="${DB_USERNAME:-${MYSQL_USER:-${MYSQLUSER:-crater}}}"
DB_PASS_VAL="${DB_PASSWORD:-${MYSQL_PASSWORD:-${MYSQLPASSWORD:-}}}"

# Write to .env
echo "" >> .env
echo "DB_CONNECTION=${DB_CONNECTION:-mysql}" >> .env
echo "DB_HOST=${DB_HOST_VAL}" >> .env
echo "DB_PORT=${DB_PORT_VAL}" >> .env
echo "DB_DATABASE=${DB_NAME_VAL}" >> .env
echo "DB_USERNAME=${DB_USER_VAL}" >> .env
echo "DB_PASSWORD=${DB_PASS_VAL}" >> .env

# APP_URL is required
if [ -z "$APP_URL" ]; then
    echo "ERROR: APP_URL environment variable is required!"
    exit 1
fi

echo "APP_NAME=\"${COMPANY_NAME:-My Company}\"" >> .env
echo "APP_URL=${APP_URL}" >> .env
echo "SESSION_DRIVER=cookie" >> .env
echo "SESSION_LIFETIME=10080" >> .env
echo "SESSION_SECURE_COOKIE=true" >> .env
echo "SESSION_SAME_SITE=lax" >> .env

# Stripe
echo "STRIPE_KEY=${STRIPE_KEY}" >> .env
echo "STRIPE_SECRET=${STRIPE_SECRET}" >> .env
echo "STRIPE_WEBHOOK_SECRET=${STRIPE_WEBHOOK_SECRET}" >> .env

# Mail
echo "MAIL_MAILER=${MAIL_MAILER:-smtp}" >> .env
echo "MAIL_DRIVER=${MAIL_MAILER:-smtp}" >> .env
echo "MAIL_HOST=${MAIL_HOST:-smtp.resend.com}" >> .env
echo "MAIL_PORT=${MAIL_PORT:-587}" >> .env
echo "MAIL_USERNAME=${MAIL_USERNAME:-resend}" >> .env
echo "MAIL_PASSWORD=${MAIL_PASSWORD}" >> .env
echo "MAIL_ENCRYPTION=${MAIL_ENCRYPTION:-tls}" >> .env
echo "MAIL_FROM_ADDRESS=${MAIL_FROM_ADDRESS:-noreply@example.com}" >> .env
echo "MAIL_FROM_NAME=\"${MAIL_FROM_NAME:-${COMPANY_NAME:-My Company}}\"" >> .env

# Filesystem
echo "FILESYSTEM_DRIVER=public" >> .env
echo "MEDIA_DISK=public" >> .env

echo "Config written:"
echo "APP_URL=${APP_URL}"
echo "DB_HOST=${DB_HOST_VAL}"
echo "PORT=${PORT}"

# Export for Apache
export DB_HOST="${DB_HOST_VAL}"
export DB_PORT="${DB_PORT_VAL}"
export DB_DATABASE="${DB_NAME_VAL}"
export DB_USERNAME="${DB_USER_VAL}"
export DB_PASSWORD="${DB_PASS_VAL}"
export APP_URL="${APP_URL}"
export PORT="${PORT}"

# FORCE_SETUP: Wipe database and force fresh setup
if [ "$FORCE_SETUP" = "true" ] || [ "$FORCE_SETUP" = "1" ]; then
    echo "FORCE_SETUP enabled - wiping database..."
    # Wait for database to be ready
    for i in {1..30}; do
        if php -r "
            \$host = '${DB_HOST_VAL}';
            \$port = '${DB_PORT_VAL}';
            \$db = '${DB_NAME_VAL}';
            \$user = '${DB_USER_VAL}';
            \$pass = '${DB_PASS_VAL}';
            try {
                \$pdo = new PDO(\"mysql:host=\$host;port=\$port;dbname=\$db\", \$user, \$pass);
                exit(0);
            } catch (Exception \$e) {
                exit(1);
            }
        " 2>/dev/null; then
            echo "Database connection successful"
            break
        fi
        echo "Waiting for database... ($i/30)"
        sleep 2
    done
    
    # Drop all tables
    php -r "
        \$host = '${DB_HOST_VAL}';
        \$port = '${DB_PORT_VAL}';
        \$db = '${DB_NAME_VAL}';
        \$user = '${DB_USER_VAL}';
        \$pass = '${DB_PASS_VAL}';
        try {
            \$pdo = new PDO(\"mysql:host=\$host;port=\$port;dbname=\$db\", \$user, \$pass);
            \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            \$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            \$tables = \$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            foreach (\$tables as \$table) {
                \$pdo->exec(\"DROP TABLE IF EXISTS \`\$table\`\");
            }
            \$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            echo \"Dropped all tables successfully.\n\";
        } catch (Exception \$e) {
            echo \"Error wiping database: \" . \$e->getMessage() . \"\n\";
        }
    " || echo "Database wipe completed/failed"
    rm -f storage/app/database_created 2>/dev/null || true
    echo "Database wiped, proceeding with fresh setup..."
fi

# Create storage link
php artisan storage:link 2>/dev/null || true

# Clear caches
php artisan config:clear 2>/dev/null || true
php artisan cache:clear 2>/dev/null || true

# Set permissions
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# Ensure .env is writable by www-data
if [ -f ".env" ]; then
    chown www-data:www-data .env 2>/dev/null || true
    chmod 664 .env 2>/dev/null || true
fi

# Ensure .env can be created if it doesn't exist
touch .env 2>/dev/null || true
chown www-data:www-data .env 2>/dev/null || true
chmod 664 .env 2>/dev/null || true

echo "============================================"
echo "Starting Apache on port ${PORT}"
echo "============================================"

# Start Apache in foreground
exec apache2-foreground

