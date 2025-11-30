<?php

namespace Crater\Console\Commands;

use Crater\Models\Company;
use Crater\Models\Setting;
use Crater\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Silber\Bouncer\BouncerFacade;
use Vinkla\Hashids\Facades\Hashids;

class SetupCrater extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crater:setup 
                            {--email=admin@crater.app : Admin email address}
                            {--password=password123 : Admin password}
                            {--name=Admin : Admin name}
                            {--company=My Company : Company name}
                            {--force : Force setup even if already installed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup Crater without the web installer';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting Crater setup...');

        // Check if already installed
        if (!$this->option('force') && \Storage::disk('local')->has('database_created')) {
            if (\Schema::hasTable('users') && User::count() > 0) {
                $this->error('Crater is already installed. Use --force to reinstall.');
                return 1;
            }
        }

        // Run migrations
        $this->info('Running migrations...');
        try {
            Artisan::call('migrate', ['--force' => true]);
            $this->info(Artisan::output());
        } catch (\Exception $e) {
            $this->error('Migration failed: ' . $e->getMessage());
            return 1;
        }

        // Run seeders (currencies, countries)
        $this->info('Running base seeders...');
        try {
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\CurrenciesTableSeeder',
                '--force' => true
            ]);
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\CountriesTableSeeder',
                '--force' => true
            ]);
        } catch (\Exception $e) {
            $this->warn('Seeder warning: ' . $e->getMessage());
        }

        // Create admin user
        $this->info('Creating admin user...');
        $email = $this->option('email');
        $password = $this->option('password');
        $name = $this->option('name');
        $companyName = $this->option('company');

        try {
            // Check if user already exists
            $user = User::where('email', $email)->first();
            
            if (!$user) {
                $user = User::create([
                    'email' => $email,
                    'name' => $name,
                    'role' => 'super admin',
                    'password' => $password,
                ]);
                $this->info("Created user: {$email}");
            } else {
                // Update existing user
                $user->update([
                    'name' => $name,
                    'role' => 'super admin',
                    'password' => Hash::make($password),
                ]);
                $this->info("Updated existing user: {$email}");
            }

            // Create or get company
            $company = Company::where('owner_id', $user->id)->first();
            
            if (!$company) {
                $company = Company::create([
                    'name' => $companyName,
                    'owner_id' => $user->id,
                    'slug' => \Str::slug($companyName)
                ]);

                $company->unique_hash = Hashids::connection(Company::class)->encode($company->id);
                $company->save();
                $company->setupDefaultData();
                $user->companies()->attach($company->id);
                $this->info("Created company: {$companyName}");
            } else {
                $this->info("Company already exists: {$company->name}");
            }

            // Assign super admin role
            BouncerFacade::scope()->to($company->id);
            $user->assign('super admin');

        } catch (\Exception $e) {
            $this->error('User/Company creation failed: ' . $e->getMessage());
            return 1;
        }

        // Mark installation as complete
        $this->info('Marking installation as complete...');
        try {
            Setting::setSetting('profile_complete', 'COMPLETED');
            \Storage::disk('local')->put('database_created', 'database_created');
        } catch (\Exception $e) {
            $this->error('Failed to mark installation complete: ' . $e->getMessage());
            return 1;
        }

        // Clear caches
        $this->info('Clearing caches...');
        Artisan::call('config:clear');
        Artisan::call('cache:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');

        // Create storage link
        try {
            Artisan::call('storage:link');
        } catch (\Exception $e) {
            $this->warn('Storage link warning: ' . $e->getMessage());
        }

        $this->newLine();
        $this->info('========================================');
        $this->info('Crater setup completed successfully!');
        $this->info('========================================');
        $this->newLine();
        $this->info('Login credentials:');
        $this->info("  Email:    {$email}");
        $this->info("  Password: {$password}");
        $this->newLine();
        $this->warn('Please change your password after first login!');

        return 0;
    }
}

