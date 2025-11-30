# How to Use Artisan Commands in Railway

## What is Artisan?

`artisan` is Laravel's command-line interface (CLI) tool. It's a PHP file (`artisan`) in your Laravel project root that lets you run commands like:
- Database migrations
- Cache clearing
- Key generation
- And many other Laravel tasks

## How to Run Artisan Commands in Railway

### Option 1: Railway Shell (Easiest - Visual Interface)

1. **Go to Railway Dashboard**
2. **Click on your Crater service**
3. **Click "Deployments" tab**
4. **Click on the latest deployment**
5. **Click "Shell" button** (opens a terminal)
6. **Run artisan commands:**
   ```bash
   php artisan db:wipe --force
   php artisan config:clear
   php artisan cache:clear
   ```

### Option 2: Railway CLI (Command Line)

If you have Railway CLI installed:

```bash
# Link to your project (if not already linked)
railway link

# Run artisan commands
railway run php artisan db:wipe --force
railway run php artisan config:clear
railway run php artisan cache:clear
```

### Option 3: Via Railway Dashboard → Variables

Some commands can be run automatically, but for database operations, you need Shell or CLI.

## Common Artisan Commands for Crater Installation

```bash
# Clear database (drops all tables)
php artisan db:wipe --force

# Clear all caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan optimize:clear

# Generate application key
php artisan key:generate

# Run migrations
php artisan migrate --force

# Install Crater
php artisan crater:install
```

## Where is the Artisan File?

The `artisan` file is in your project root:
- In GitHub: `https://github.com/eliteweblabs/crater/blob/master/artisan`
- In your local project: `/Users/4rgd/Astro/crater-invoicing/artisan`
- In Railway: It's deployed with your code, accessible via Shell

## Quick Fix for Your Database Issue

**Using Railway Shell:**

1. Railway Dashboard → Your Crater Service → Deployments → Latest → **Shell**
2. Run:
   ```bash
   php artisan db:wipe --force
   php artisan config:clear
   php artisan cache:clear
   ```
3. Refresh installation page

**Using Railway CLI:**

```bash
railway run php artisan db:wipe --force
railway run php artisan config:clear
railway run php artisan cache:clear
```

## Why You Don't See It in Railway Workspace

Railway's web interface doesn't show files - it's a deployment platform. To access files and run commands, you need to use:
- **Shell** (web-based terminal in Railway dashboard)
- **Railway CLI** (command-line tool)

The `artisan` file is there in your deployed code, you just need to access it via Shell or CLI!

