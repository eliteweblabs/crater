# Clear Database for Crater Installation

## Problem

Crater's installer checks if the `users` table exists. If it does, you'll see:
```
Error: Database should be empty
```

## Solution: Clear Database Properly

### Option 1: Using Railway CLI (Recommended)

```bash
# Connect to your Railway project
railway link

# Run the database wipe command
railway run php artisan db:wipe --force
```

### Option 2: Using Railway Shell

1. Go to Railway Dashboard
2. Click on your Crater service
3. Click "Deployments" → Latest deployment → "Shell"
4. Run:
   ```bash
   php artisan db:wipe --force
   ```

### Option 3: Drop and Recreate Database (Nuclear Option)

If `db:wipe` doesn't work, drop and recreate the database:

**Via Railway MySQL Service:**
1. Go to Railway Dashboard
2. Click on your MySQL service
3. Click "Data" tab
4. Click "Delete Database" or use the MySQL shell:
   ```sql
   DROP DATABASE IF EXISTS crater;
   CREATE DATABASE crater;
   ```

**Via Railway CLI:**
```bash
railway run php artisan tinker --execute="DB::statement('DROP DATABASE IF EXISTS ' . env('DB_DATABASE')); DB::statement('CREATE DATABASE ' . env('DB_DATABASE'));"
```

### Option 4: Manual SQL (If you have MySQL access)

Connect to your MySQL database and run:
```sql
DROP DATABASE IF EXISTS crater;
CREATE DATABASE crater;
```

## After Clearing

1. Refresh the installation page
2. Proceed with the installation wizard
3. The database check should pass

## Why This Happens

Crater's installer checks for the `users` table in `app/Space/EnvironmentManager.php`:
```php
if (\Schema::hasTable('users')) {
    return ['error' => 'database_should_be_empty'];
}
```

So you need to drop **all tables**, not just clear data.

