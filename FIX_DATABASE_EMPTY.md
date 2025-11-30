# Fix "Database should be empty" Error

## The Problem

Crater checks for the `users` table. If it exists, installation fails with:
```
Error: Database should be empty
```

## Step-by-Step Fix

### Step 1: Check What's Actually in the Database

Run this in Railway Shell or via CLI:

```bash
railway run php check-database.php
```

This will show you what tables exist.

### Step 2: Clear Config Cache

The database check might be using cached config. Clear it:

```bash
railway run php artisan config:clear
railway run php artisan cache:clear
railway run php artisan optimize:clear
```

### Step 3: Wipe Database (Try Multiple Methods)

**Method 1: Laravel's db:wipe**
```bash
railway run php artisan db:wipe --force
```

**Method 2: Force Clear Script**
```bash
railway run php force-clear-db.php
```

**Method 3: Manual SQL (via Railway MySQL)**
1. Go to Railway Dashboard → MySQL Service
2. Click "Data" tab → "Connect" or use MySQL shell
3. Run:
```sql
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS migrations;
DROP TABLE IF EXISTS settings;
-- Drop any other tables that exist
SET FOREIGN_KEY_CHECKS=1;
```

**Method 4: Nuclear Option - Drop Entire Database**
1. Railway Dashboard → MySQL Service → "Data" tab
2. Delete the database
3. Railway will auto-recreate it, or create manually

### Step 4: Verify Database is Empty

```bash
railway run php check-database.php
```

Should show: `✅ Database is EMPTY - no tables found!`

### Step 5: Clear All Caches Again

```bash
railway run php artisan config:clear
railway run php artisan cache:clear
railway run php artisan route:clear
railway run php artisan view:clear
```

### Step 6: Try Installation Again

Refresh the installation page and try again.

## Why This Happens

The check is in `app/Space/EnvironmentManager.php`:
```php
if (\Schema::hasTable('users')) {
    return ['error' => 'database_should_be_empty'];
}
```

Even if you "wiped" the database, if:
- Config is cached with old connection info
- The `users` table still exists
- Migrations ran automatically

The check will fail.

## Alternative: Bypass Check (Not Recommended)

If you absolutely can't clear the database, you could modify the check, but this is **NOT recommended** as it might cause installation issues.

