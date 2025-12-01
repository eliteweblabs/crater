<?php
// Update admin email
require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$user = \Crater\Models\User::where('role', 'super admin')->first();
if ($user) {
    $user->email = 'crater@eliteweblabs.com';
    $user->save();
    echo "✅ Admin email updated to: crater@eliteweblabs.com\n";
    echo "Use 'Forgot Password' to reset.\n";
} else {
    echo "❌ Admin user not found\n";
}


