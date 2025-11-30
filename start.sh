#!/bin/bash
# Railway start script for Crater

set -e

PORT=${PORT:-8000}
PORT=$((PORT))

echo "============================================"
echo "Crater Startup Script"
echo "============================================"

# Quick database fix - directly mark as installed if tables exist
if [ "$AUTO_SETUP" = "true" ] || [ "$AUTO_SETUP" = "1" ]; then
    echo "AUTO_SETUP enabled..."
    
    # Try to complete setup via tinker (more reliable than artisan command)
    php artisan tinker --execute="
        try {
            // Run migrations if needed
            if (!Schema::hasTable('users')) {
                Artisan::call('migrate', ['--seed' => true, '--force' => true]);
                echo 'Migrations completed\n';
            }
            
            // Check/create user
            \$user = \Crater\Models\User::where('role', 'super admin')->first();
            if (!\$user) {
                \$user = \Crater\Models\User::create([
                    'email' => env('ADMIN_EMAIL', 'admin@crater.app'),
                    'name' => env('ADMIN_NAME', 'Admin'),
                    'role' => 'super admin',
                    'password' => env('ADMIN_PASSWORD', 'password123'),
                ]);
                echo 'User created\n';
            }
            
            // Check/create company
            \$company = \Crater\Models\Company::first();
            if (!\$company) {
                \$company = \Crater\Models\Company::create([
                    'name' => env('COMPANY_NAME', 'My Company'),
                    'owner_id' => \$user->id,
                    'slug' => Str::slug(env('COMPANY_NAME', 'My Company')),
                ]);
                \$company->unique_hash = \Vinkla\Hashids\Facades\Hashids::connection(\Crater\Models\Company::class)->encode(\$company->id);
                \$company->save();
                \$company->setupDefaultData();
                \$user->companies()->attach(\$company->id);
                Bouncer::scope()->to(\$company->id);
                \$user->assign('super admin');
                echo 'Company created\n';
            }
            
            // Mark as complete
            \Crater\Models\Setting::setSetting('profile_complete', 'COMPLETED');
            Storage::disk('local')->put('database_created', 'database_created');
            echo 'Installation marked complete\n';
        } catch (Exception \$e) {
            echo 'Setup error: ' . \$e->getMessage() . '\n';
        }
    " 2>/dev/null || echo "Tinker setup completed with warnings"
fi

# Clear caches
php artisan config:clear 2>/dev/null || true
php artisan cache:clear 2>/dev/null || true

echo "Starting Laravel server on port: $PORT"
echo "============================================"

exec php artisan serve --host=0.0.0.0 --port=$PORT

