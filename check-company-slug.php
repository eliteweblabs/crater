<?php
/**
 * Quick script to check and update company slug
 * Run: php check-company-slug.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Crater\Models\Company;

echo "=== Company Slug Checker ===\n\n";

$companies = Company::all();

if ($companies->isEmpty()) {
    echo "No companies found in database.\n";
    exit(1);
}

echo "Found " . $companies->count() . " company(ies):\n\n";

foreach ($companies as $company) {
    echo "ID: {$company->id}\n";
    echo "Name: {$company->name}\n";
    echo "Slug: {$company->slug}\n";
    echo "Owner ID: {$company->owner_id}\n";
    echo "---\n";
}

$defaultCompany = $companies->first();

echo "\nDefault/First Company:\n";
echo "  Name: {$defaultCompany->name}\n";
echo "  Slug: {$defaultCompany->slug}\n";
echo "\nYour customer portal URL should be:\n";
echo "  https://your-domain.com/{$defaultCompany->slug}/customer\n";
echo "\nIf you want to update the slug, you can run:\n";
echo "  php artisan tinker\n";
echo "  \$company = \\Crater\\Models\\Company::find({$defaultCompany->id});\n";
echo "  \$company->slug = 'your-new-slug';\n";
echo "  \$company->save();\n";

