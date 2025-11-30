<?php
// Nuclear option: Drop all tables manually
// Run via: railway run php force-clear-db.php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Force clearing database...\n\n";

try {
    // Get all tables
    $tables = DB::select('SHOW TABLES');
    $tableName = 'Tables_in_' . config('database.connections.mysql.database');
    
    if (empty($tables)) {
        echo "✅ Database is already empty!\n";
        exit(0);
    }
    
    // Disable foreign key checks
    DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    
    // Drop each table
    foreach ($tables as $table) {
        $name = $table->$tableName;
        echo "Dropping table: $name\n";
        DB::statement("DROP TABLE IF EXISTS `$name`");
    }
    
    // Re-enable foreign key checks
    DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    
    echo "\n✅ All tables dropped successfully!\n";
    echo "You can now proceed with installation.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

