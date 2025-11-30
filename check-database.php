<?php
// Quick script to check what's in the database
// Run via: railway run php check-database.php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Checking database: " . config('database.connections.mysql.database') . "\n";
echo "Host: " . config('database.connections.mysql.host') . "\n\n";

try {
    $tables = DB::select('SHOW TABLES');
    $tableName = 'Tables_in_' . config('database.connections.mysql.database');
    
    if (empty($tables)) {
        echo "✅ Database is EMPTY - no tables found!\n";
    } else {
        echo "❌ Database has " . count($tables) . " table(s):\n";
        foreach ($tables as $table) {
            echo "  - " . $table->$tableName . "\n";
        }
        
        if (\Schema::hasTable('users')) {
            echo "\n⚠️  'users' table exists - this will cause installation to fail!\n";
            echo "Run: php artisan db:wipe --force\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

