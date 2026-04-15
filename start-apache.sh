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

# Ensure .env file is writable by the web server
# On Railway, the web server runs as www-data (Apache) or a non-root user
# We need to make it writable and ensure correct ownership
if [ -f ".env" ]; then
    # Try to change ownership to www-data (Apache user) if it exists
    if id www-data >/dev/null 2>&1; then
        chown www-data:www-data .env 2>/dev/null || true
    fi
    # Make it writable by owner and group (664) or everyone (666)
    chmod 666 .env 2>/dev/null || chmod 664 .env 2>/dev/null || chmod 644 .env 2>/dev/null || true
    # Also ensure the directory is writable
    chmod 755 . 2>/dev/null || true
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

# Admin reset token (used by /api/v1/reset-admin-pw route)
if [ -n "$ADMIN_RESET_TOKEN" ]; then
    sed -i '/^ADMIN_RESET_TOKEN=/d' .env 2>/dev/null || true
    echo "ADMIN_RESET_TOKEN=${ADMIN_RESET_TOKEN}" >> .env
fi
if [ -n "$ADMIN_PASSWORD" ]; then
    sed -i '/^ADMIN_PASSWORD=/d' .env 2>/dev/null || true
    echo "ADMIN_PASSWORD=${ADMIN_PASSWORD}" >> .env
fi
if [ -n "$ADMIN_EMAIL" ]; then
    sed -i '/^ADMIN_EMAIL=/d' .env 2>/dev/null || true
    echo "ADMIN_EMAIL=${ADMIN_EMAIL}" >> .env
fi

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

# Ensure .env file is writable by web server after all writes
# This is critical for the installation wizard to be able to update .env
if [ -f ".env" ]; then
    # Try to change ownership to www-data (Apache user) if it exists
    if id www-data >/dev/null 2>&1; then
        chown www-data:www-data .env 2>/dev/null || true
    fi
    # Make it writable by owner and group (664) or everyone (666)
    chmod 666 .env 2>/dev/null || chmod 664 .env 2>/dev/null || chmod 644 .env 2>/dev/null || true
    # Also ensure the directory is writable
    chmod 755 . 2>/dev/null || true
    # Verify permissions
    ls -la .env 2>/dev/null || true
    echo ".env file permissions set (writable by web server)"
fi

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
php artisan view:clear 2>/dev/null || true
php artisan cache:clear 2>/dev/null || true

# Sync ADMIN_PASSWORD env var → database user record on every deploy
if [ -n "$ADMIN_PASSWORD" ]; then
    echo "Syncing admin password (len=${#ADMIN_PASSWORD})..."
    # Unset the shell-exported DB vars so the PHP bootstrap reads from .env,
    # which holds the real connection (written by the Crater installation wizard).
    # Read DB settings directly from .env (first occurrence of each key)
    # so we always hit the real application database, regardless of shell env vars.
    php -r '
$env = file_get_contents("/var/www/html/.env");
function envVal($key, $default = "") {
    global $env;
    if (preg_match("/^" . preg_quote($key, "/") . "=(.+)/m", $env, $m)) {
        return trim($m[1]);
    }
    return $default;
}
$host  = envVal("DB_HOST",     "mysql");
$port  = envVal("DB_PORT",     "3306");
$db    = envVal("DB_DATABASE", "railway");
$user  = envVal("DB_USERNAME", "root");
$pass  = envVal("DB_PASSWORD", "");
echo "Connecting to $host/$db" . PHP_EOL;
try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $password = getenv("ADMIN_PASSWORD");
    $hash = password_hash($password, PASSWORD_BCRYPT);
    // Find admin user: prefer ADMIN_EMAIL, fall back to first user
    $targetEmail = getenv("ADMIN_EMAIL");
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$targetEmail]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $row = $pdo->query("SELECT id, email FROM users ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }
    if ($row) {
        $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $upd->execute([$hash, $row["id"]]);
        $verify = password_verify($password, $hash);
        echo "Password synced for " . $row["email"] . " (verify=" . ($verify ? "ok" : "FAIL") . ")" . PHP_EOL;
    } else {
        echo "No users found in $db." . PHP_EOL;
    }
} catch (Exception $e) {
    echo "Password sync error: " . $e->getMessage() . PHP_EOL;
}
' 2>&1 || echo "Password sync skipped"
fi

# Auto-migrate if database is empty
if [ "$AUTO_MIGRATE" = "true" ]; then
    echo "AUTO_MIGRATE enabled - running migrations and seeds..."
    php artisan migrate --seed --force 2>&1 || echo "Migration/seed failed"
    echo "Migrations complete."
fi

# Set permissions for storage and cache directories
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# Final check: Ensure .env is still writable
if [ -f ".env" ]; then
    if id www-data >/dev/null 2>&1; then
        chown www-data:www-data .env 2>/dev/null || true
    fi
    chmod 666 .env 2>/dev/null || chmod 664 .env 2>/dev/null || true
    echo "Final .env permissions check complete"
fi

echo "============================================"
echo "Starting Apache on port ${PORT}"
echo "============================================"

# Ensure only one MPM is loaded (php:8.1-apache defaults to prefork)
a2dismod mpm_event mpm_worker 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true

# Pass Railway env vars explicitly into Apache/PHP workers
if [ -f /etc/apache2/sites-enabled/000-default.conf ]; then
    sed -i '/<\/VirtualHost>/i \    Alias /assets /var/www/html/public/build/assets\n    Alias /fonts /var/www/html/public/build/fonts\n    Alias /img /var/www/html/public/build/img\n    <Directory /var/www/html/public/build>\n        Require all granted\n    </Directory>' /etc/apache2/sites-enabled/000-default.conf
    # PassEnv makes Railway env vars available to mod_php scripts
    for VAR in ADMIN_RESET_TOKEN ADMIN_PASSWORD ADMIN_EMAIL STRIPE_KEY STRIPE_SECRET STRIPE_WEBHOOK_SECRET; do
        if [ -n "$(eval echo \$$VAR)" ]; then
            sed -i "/<\/VirtualHost>/i \\    PassEnv $VAR" /etc/apache2/sites-enabled/000-default.conf
        fi
    done
fi

# Match Listen and VirtualHost to Railway's PORT (image defaults to 8080)
printf 'Listen %s\n' "${PORT}" > /etc/apache2/ports.conf
if [ -f /etc/apache2/sites-enabled/000-default.conf ]; then
    sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf
fi

# Start Apache in foreground
exec apache2-foreground

