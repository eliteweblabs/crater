#!/bin/bash
# Script to completely clear Crater database for fresh installation
# Run this via Railway CLI: railway run bash clear-database.sh

echo "Clearing database for fresh Crater installation..."

# Drop all tables
php artisan db:wipe --force

# Alternative: Drop database and recreate (if you have permissions)
# php artisan tinker --execute="DB::statement('DROP DATABASE IF EXISTS ' . env('DB_DATABASE')); DB::statement('CREATE DATABASE ' . env('DB_DATABASE'));"

echo "Database cleared! You can now proceed with installation."

