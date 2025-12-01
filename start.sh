#!/bin/bash
# Railway start script for Crater

set -e

PORT=${PORT:-8000}
PORT=$((PORT))

echo "============================================"
echo "Crater Startup Script"
echo "============================================"

# Fix: Remove database settings from .env if they exist
# Railway env vars should take precedence
if [ -f ".env" ]; then
    echo "Cleaning .env database settings to use Railway env vars..."
    sed -i '/^DB_HOST=/d' .env 2>/dev/null || true
    sed -i '/^DB_PORT=/d' .env 2>/dev/null || true
    sed -i '/^DB_DATABASE=/d' .env 2>/dev/null || true
    sed -i '/^DB_USERNAME=/d' .env 2>/dev/null || true
    sed -i '/^DB_PASSWORD=/d' .env 2>/dev/null || true
    sed -i '/^DB_CONNECTION=/d' .env 2>/dev/null || true
    echo "Done - using Railway environment variables for database"
fi

# Function to wait for database
wait_for_db() {
    echo "Waiting for database connection..."
    for i in {1..30}; do
        if php -r "
            try {
                \$pdo = new PDO(
                    'mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . getenv('DB_DATABASE'),
                    getenv('DB_USERNAME'),
                    getenv('DB_PASSWORD'),
                    [PDO::ATTR_TIMEOUT => 5]
                );
                echo 'connected';
                exit(0);
            } catch (Exception \$e) {
                exit(1);
            }
        " 2>/dev/null | grep -q "connected"; then
            echo "Database connected!"
            return 0
        fi
        echo "Attempt $i/30 - waiting for database..."
        sleep 2
    done
    echo "WARNING: Could not connect to database after 30 attempts"
    return 1
}

# Quick database fix - directly mark as installed if tables exist
if [ "$AUTO_SETUP" = "true" ] || [ "$AUTO_SETUP" = "1" ]; then
    echo "AUTO_SETUP enabled..."
    
    # Wait for database first
    if wait_for_db; then
        echo "Running AUTO_SETUP..."
        
        # Try to complete setup via tinker (more reliable than artisan command)
        php artisan tinker --execute="
            try {
                echo 'Checking database tables...\n';
                
                // Run migrations if needed
                if (!\Schema::hasTable('users')) {
                    echo 'Running migrations...\n';
                    \Artisan::call('migrate', ['--seed' => true, '--force' => true]);
                    echo 'Migrations completed!\n';
                } else {
                    echo 'Tables already exist\n';
                }
                
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

# Clear caches
php artisan config:clear 2>/dev/null || true
php artisan cache:clear 2>/dev/null || true

echo "Starting Laravel server on port: $PORT"
echo "============================================"

exec php artisan serve --host=0.0.0.0 --port=$PORT
