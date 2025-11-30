# Fixing Crater Docker Build Error on Railway

## The Problem

Crater's default Dockerfile expects build arguments (`$uid` and `$user`) that Railway doesn't provide, causing this error:

```
useradd: invalid user ID '-d'
```

## Solution Options

### Option 1: Use Nixpacks (Recommended - Easiest)

Railway prefers Nixpacks for Laravel apps. To force Nixpacks instead of Docker:

1. **In Railway Dashboard:**
   - Go to your Crater service
   - Click "Settings"
   - Under "Build Command", select "Nixpacks" as builder
   - Or add `railway.json` to your repo (already created)

2. **The `railway.json` file** (already in this directory) will tell Railway to use Nixpacks

3. **Redeploy** - Railway will rebuild using Nixpacks instead of Docker

### Option 2: Use Custom Dockerfile

If you need to use Docker:

1. **Rename the Railway-compatible Dockerfile:**
   ```bash
   # In your forked Crater repo
   cp Dockerfile Dockerfile.original
   cp Dockerfile.railway Dockerfile
   ```

2. **Commit and push:**
   ```bash
   git add Dockerfile
   git commit -m "Fix Dockerfile for Railway deployment"
   git push
   ```

3. **Railway will rebuild** with the fixed Dockerfile

### Option 3: Add Build Arguments (Advanced)

If you want to keep the original Dockerfile:

1. **In Railway Dashboard:**
   - Go to your Crater service
   - Click "Variables"
   - Add build arguments:
     - `uid=1000`
     - `user=www`

2. **Update Railway service settings:**
   - In service settings, enable "Build Arguments"
   - Set the arguments above

## Recommended: Use Nixpacks

**Easiest solution:** Railway's Nixpacks automatically detects Laravel and handles everything. Just make sure `railway.json` is in your repo root.

The `railway.json` file in this directory will:
- Force Railway to use Nixpacks
- Set the correct start command
- Handle Laravel automatically

## After Fixing

Once the build succeeds:

1. **Set environment variables** (see DEPLOYMENT.md)
2. **Run migrations:**
   ```bash
   php artisan migrate --force
   ```
3. **Install Crater:**
   ```bash
   php artisan crater:install
   ```

## Still Having Issues?

If Nixpacks doesn't work:

1. Check Railway logs for specific errors
2. Verify PHP version compatibility (Crater needs PHP 8.1+)
3. Ensure all required PHP extensions are available
4. Check that Composer dependencies install correctly


