#!/bin/bash
# Railway start script for Crater

set -e

PORT=${PORT:-8000}
PORT=$((PORT))

echo "============================================"
echo "Crater Startup Script"
echo "============================================"

# Fix: Write Railway env vars directly to .env file
# PHP/Laravel doesn't properly inherit system env vars on Railway
echo "Writing database config to .env file..."

# Create .env if it doesn't exist
if [ ! -f ".env" ]; then
    cp .env.example .env 2>/dev/null || touch .env
fi

# Remove existing DB and app settings
sed -i '/^DB_CONNECTION=/d' .env 2>/dev/null || true
sed -i '/^DB_HOST=/d' .env 2>/dev/null || true
sed -i '/^DB_PORT=/d' .env 2>/dev/null || true
sed -i '/^DB_DATABASE=/d' .env 2>/dev/null || true
sed -i '/^DB_USERNAME=/d' .env 2>/dev/null || true
sed -i '/^DB_PASSWORD=/d' .env 2>/dev/null || true
sed -i '/^APP_URL=/d' .env 2>/dev/null || true
sed -i '/^SESSION_DOMAIN=/d' .env 2>/dev/null || true
sed -i '/^SESSION_DRIVER=/d' .env 2>/dev/null || true
sed -i '/^SANCTUM_STATEFUL_DOMAINS=/d' .env 2>/dev/null || true

# Write Railway vars to .env
# Check multiple possible variable names (Railway uses different formats)
DB_HOST_VAL="${DB_HOST:-${MYSQL_HOST:-${MYSQLHOST:-db.railway.internal}}}"
DB_PORT_VAL="${DB_PORT:-${MYSQL_PORT:-${MYSQLPORT:-3306}}}"
DB_NAME_VAL="${DB_DATABASE:-${MYSQL_DATABASE:-${MYSQLDATABASE:-crater}}}"
DB_USER_VAL="${DB_USERNAME:-${MYSQL_USER:-${MYSQLUSER:-crater}}}"
DB_PASS_VAL="${DB_PASSWORD:-${MYSQL_PASSWORD:-${MYSQLPASSWORD:-}}}"

echo "" >> .env
echo "DB_CONNECTION=${DB_CONNECTION:-mysql}" >> .env
echo "DB_HOST=${DB_HOST_VAL}" >> .env
echo "DB_PORT=${DB_PORT_VAL}" >> .env
echo "DB_DATABASE=${DB_NAME_VAL}" >> .env
echo "DB_USERNAME=${DB_USER_VAL}" >> .env
echo "DB_PASSWORD=${DB_PASS_VAL}" >> .env

# Write APP_URL, SESSION_DOMAIN, SESSION_DRIVER, and SANCTUM_STATEFUL_DOMAINS
# Force SESSION_DRIVER=cookie for Railway (file sessions don't persist in containers)
echo "APP_URL=${APP_URL:-https://crater-production.up.railway.app}" >> .env
echo "SESSION_DOMAIN=${SESSION_DOMAIN:-.railway.app}" >> .env
echo "SESSION_DRIVER=cookie" >> .env
echo "SESSION_LIFETIME=10080" >> .env
echo "SESSION_SECURE_COOKIE=true" >> .env
echo "SESSION_SAME_SITE=lax" >> .env
echo "SANCTUM_STATEFUL_DOMAINS=${SANCTUM_STATEFUL_DOMAINS:-crater-production.up.railway.app}" >> .env

# Write Stripe configuration
echo "STRIPE_KEY=${STRIPE_KEY}" >> .env
echo "STRIPE_SECRET=${STRIPE_SECRET}" >> .env
echo "STRIPE_WEBHOOK_SECRET=${STRIPE_WEBHOOK_SECRET}" >> .env

# Write filesystem configuration (use local storage)
echo "FILESYSTEM_DRIVER=public" >> .env
echo "MEDIA_DISK=public" >> .env

echo "Database config written to .env:"
grep "^DB_" .env
echo "App config:"
grep "^APP_URL" .env
grep "^SESSION_DOMAIN" .env
grep "^SESSION_DRIVER" .env
grep "^SANCTUM_STATEFUL_DOMAINS" .env

# Function to wait for database
wait_for_db() {
    echo "Waiting for database connection..."
    echo "Testing connection to: $DB_HOST:$DB_PORT"
    
    # First test if we can resolve the hostname
    echo "Testing DNS resolution..."
    getent hosts $DB_HOST || echo "DNS lookup failed for $DB_HOST"
    
    # Test with nc if available
    echo "Testing TCP connection..."
    nc -zv $DB_HOST $DB_PORT 2>&1 || echo "NC test failed"
    
    for i in {1..30}; do
        RESULT=$(php -r "
            \$host = getenv('DB_HOST') ?: '$DB_HOST';
            \$port = getenv('DB_PORT') ?: '$DB_PORT';
            \$db = getenv('DB_DATABASE') ?: '$DB_DATABASE';
            \$user = getenv('DB_USERNAME') ?: '$DB_USERNAME';
            \$pass = getenv('DB_PASSWORD') ?: '$DB_PASSWORD';
            
            echo \"Trying: mysql:host=\$host;port=\$port;dbname=\$db with user \$user\n\";
            
            try {
                \$pdo = new PDO(
                    \"mysql:host=\$host;port=\$port;dbname=\$db\",
                    \$user,
                    \$pass,
                    [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                echo 'SUCCESS: connected';
            } catch (Exception \$e) {
                echo 'FAILED: ' . \$e->getMessage();
            }
        " 2>&1)
        
        echo "Attempt $i/30: $RESULT"
        
        if echo "$RESULT" | grep -q "SUCCESS"; then
            echo "Database connected!"
            return 0
        fi
        sleep 2
    done
    echo "WARNING: Could not connect to database after 30 attempts"
    return 1
}

# FORCE_SETUP: Wipe database and force fresh setup
if [ "$FORCE_SETUP" = "true" ] || [ "$FORCE_SETUP" = "1" ]; then
    echo "FORCE_SETUP enabled - wiping database..."
    if wait_for_db; then
        # Drop all tables using raw SQL (db:wipe doesn't exist in this Laravel version)
        php -r "
            \$host = getenv('DB_HOST') ?: '$DB_HOST_VAL';
            \$port = getenv('DB_PORT') ?: '$DB_PORT_VAL';
            \$db = getenv('DB_DATABASE') ?: '$DB_NAME_VAL';
            \$user = getenv('DB_USERNAME') ?: '$DB_USER_VAL';
            \$pass = getenv('DB_PASSWORD') ?: '$DB_PASS_VAL';
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
fi

# Quick database fix - directly mark as installed if tables exist
if [ "$AUTO_SETUP" = "true" ] || [ "$AUTO_SETUP" = "1" ]; then
    echo "AUTO_SETUP enabled..."
    
    # Wait for database first
    if wait_for_db; then
        echo "Running AUTO_SETUP..."
        
        # Run migrations directly (not through tinker)
        echo "Running migrations..."
        php artisan migrate --seed --force 2>&1 || echo "Migration failed but continuing..."
        
        # Try to complete setup via tinker (more reliable for user/company creation)
        php artisan tinker --execute="
            try {
                echo 'Checking database tables...\n';
                
                // Check/create user
                echo 'Checking for admin user...\n';
                \$user = \Crater\Models\User::where('role', 'super admin')->first();
                if (!\$user) {
                    echo 'Creating admin user...\n';
                    \$user = \Crater\Models\User::create([
                        'email' => env('ADMIN_EMAIL', 'admin@crater.app'),
                        'name' => env('ADMIN_NAME', 'Admin'),
                        'role' => 'super admin',
                        'password' => env('ADMIN_PASSWORD', 'password123'),
                    ]);
                    echo 'User created: ' . \$user->email . '\n';
                } else {
                    echo 'Admin user exists: ' . \$user->email . '\n';
                }
                
                // Check/create company
                echo 'Checking for company...\n';
                \$company = \Crater\Models\Company::first();
                if (!\$company) {
                    echo 'Creating company...\n';
                    \$company = \Crater\Models\Company::create([
                        'name' => env('COMPANY_NAME', 'My Company'),
                        'owner_id' => \$user->id,
                        'slug' => \Illuminate\Support\Str::slug(env('COMPANY_NAME', 'My Company')),
                    ]);
                    \$company->unique_hash = \Vinkla\Hashids\Facades\Hashids::connection(\Crater\Models\Company::class)->encode(\$company->id);
                    \$company->save();
                    \$company->setupDefaultData();
                    \$user->companies()->attach(\$company->id);
                    \Silber\Bouncer\BouncerFacade::scope()->to(\$company->id);
                    \$user->assign('super admin');
                    echo 'Company created: ' . \$company->name . '\n';
                } else {
                    echo 'Company exists: ' . \$company->name . '\n';
                }
                
                // Mark as complete
                echo 'Marking installation complete...\n';
                \Crater\Models\Setting::setSetting('profile_complete', 'COMPLETED');
                \Illuminate\Support\Facades\Storage::disk('local')->put('database_created', 'database_created');
                echo '=== AUTO_SETUP COMPLETED SUCCESSFULLY ===\n';
            } catch (\Exception \$e) {
                echo 'SETUP ERROR: ' . \$e->getMessage() . '\n';
                echo 'File: ' . \$e->getFile() . ':' . \$e->getLine() . '\n';
            }
        " || echo "Tinker completed with exit code $?"
    else
        echo "Skipping AUTO_SETUP - database not available"
    fi
fi

# Update admin email if requested
if [ "$UPDATE_ADMIN_EMAIL" != "" ]; then
    echo "Updating admin email to: $UPDATE_ADMIN_EMAIL"
    php artisan tinker --execute="
        try {
            \$user = \Crater\Models\User::where('role', 'super admin')->first();
            if (\$user) {
                \$user->email = '$UPDATE_ADMIN_EMAIL';
                \$user->save();
                echo 'Admin email updated to: ' . \$user->email . '\n';
            } else {
                echo 'Admin user not found\n';
            }
        } catch (\Exception \$e) {
            echo 'Error: ' . \$e->getMessage() . '\n';
        }
    " || echo "Email update completed/failed"
fi

# Update admin password if requested
if [ "$UPDATE_ADMIN_PASSWORD" != "" ]; then
    echo "Updating admin password..."
    php artisan tinker --execute="
        try {
            \$user = \Crater\Models\User::where('role', 'super admin')->first();
            if (\$user) {
                \$hashedPassword = \Illuminate\Support\Facades\Hash::make(\"$UPDATE_ADMIN_PASSWORD\");
                
                // Use DB update to bypass model mutators (avoid double-hashing)
                \Illuminate\Support\Facades\DB::table('users')
                    ->where('id', \$user->id)
                    ->update(['password' => \$hashedPassword]);
                
                // Verify it was saved
                \$user->refresh();
                echo 'Admin password updated successfully\n';
                echo 'Password check: ' . (\Illuminate\Support\Facades\Hash::check(\"$UPDATE_ADMIN_PASSWORD\", \$user->password) ? 'PASS' : 'FAIL') . '\n';
            } else {
                echo 'Admin user not found\n';
            }
        } catch (\Exception \$e) {
            echo 'Error: ' . \$e->getMessage() . '\n';
        }
    " || echo "Password update completed/failed"
fi

# Update company name and slug if COMPANY_NAME is set
if [ ! -z "$COMPANY_NAME" ]; then
    echo "Updating company name to: $COMPANY_NAME"
    php artisan tinker --execute="
        \$company = \Crater\Models\Company::first();
        if (\$company) {
            \$company->name = '$COMPANY_NAME';
            \$company->slug = \Illuminate\Support\Str::slug('$COMPANY_NAME');
            \$company->save();
            echo 'Company updated: ' . \$company->name . ' (slug: ' . \$company->slug . ')\n';
        }
    " || echo "Company update completed/failed"
fi

# Update company currency (defaults to USD)
COMPANY_CURRENCY="${COMPANY_CURRENCY:-USD}"
echo "Updating company currency to: $COMPANY_CURRENCY"
php artisan tinker --execute="
    try {
        // Get the currency by code
        \$currency = \Crater\Models\Currency::where('code', '$COMPANY_CURRENCY')->first();
            
            if (!\$currency) {
                echo 'Currency $COMPANY_CURRENCY not found in database\n';
                exit;
            }
            
            // Get the first company
            \$company = \Crater\Models\Company::first();
            
            if (!\$company) {
                echo 'No company found\n';
                exit;
            }
            
            // Update company setting
            \Crater\Models\CompanySetting::setSettings(['currency' => \$currency->id], \$company->id);
            
            echo 'Company currency updated to: ' . \$currency->name . ' (' . \$currency->code . ')\n';
        } catch (\Exception \$e) {
            echo 'Error updating currency: ' . \$e->getMessage() . '\n';
        }
    " || echo "Currency update completed/failed"

# Export database and app vars explicitly for PHP BEFORE building config cache
export DB_CONNECTION="${DB_CONNECTION:-mysql}"
export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_PORT="${DB_PORT:-3306}"
export DB_DATABASE="${DB_DATABASE:-crater}"
export DB_USERNAME="${DB_USERNAME:-crater}"
export DB_PASSWORD="${DB_PASSWORD:-}"
export APP_URL="${APP_URL:-https://crater-production.up.railway.app}"
export SESSION_DOMAIN="${SESSION_DOMAIN:-.railway.app}"
export SESSION_DRIVER="${SESSION_DRIVER:-cookie}"
export SESSION_LIFETIME=10080
export SESSION_SECURE_COOKIE=true
export SESSION_SAME_SITE=lax
export SANCTUM_STATEFUL_DOMAINS="${SANCTUM_STATEFUL_DOMAINS:-crater-production.up.railway.app,*.railway.app,localhost}"
export STRIPE_KEY="${STRIPE_KEY:-}"
export STRIPE_SECRET="${STRIPE_SECRET:-}"
export STRIPE_WEBHOOK_SECRET="${STRIPE_WEBHOOK_SECRET:-}"
export FILESYSTEM_DRIVER="${FILESYSTEM_DRIVER:-public}"
export MEDIA_DISK="${MEDIA_DISK:-public}"

# Create storage symlink for public file access
php artisan storage:link 2>/dev/null || true

# Clear any existing config cache so runtime config changes can take effect
php artisan config:clear 2>/dev/null || true
php artisan cache:clear 2>/dev/null || true

echo "Starting Laravel server on port: $PORT"
echo "============================================"

echo "DB_HOST=$DB_HOST"
echo "DB_DATABASE=$DB_DATABASE"
echo "SANCTUM_STATEFUL_DOMAINS=$SANCTUM_STATEFUL_DOMAINS"

exec php artisan serve --host=0.0.0.0 --port=$PORT
