# Crater Railway Deployment with MCP Monitoring

This guide helps you deploy Crater to Railway and use Railway MCP tools to monitor and debug the deployment.

## Quick Start: Using Railway MCP Tools

Since Railway CLI is authenticated, you can use MCP tools to monitor your deployment:

### 1. Check Deployment Status

Use Railway MCP to check logs and deployment status:

```bash
# Get build logs
railway logs --build

# Get deployment logs  
railway logs --deploy

# Filter logs for errors
railway logs --filter "error"
```

### 2. Common Issues and Solutions

#### Issue 1: Build Fails During Cache Commands

**Problem:** The `nixpacks.toml` tries to cache Laravel config/routes/views during build, but this requires environment variables that may not be set yet.

**Solution:** Remove cache commands from build phase (they'll run at runtime instead).

#### Issue 2: Database Connection Errors

**Problem:** Database variables not properly configured or MySQL service not running.

**Solution:** 
- Verify MySQL service exists in Railway project
- Check that `DB_*` variables use correct service reference format: `${{MySQL.MYSQLHOST}}`
- Ensure MySQL service is running (not paused)

#### Issue 3: Storage Errors

**Problem:** Filesystem is ephemeral on Railway, but S3 storage not configured.

**Solution:**
- Set `FILESYSTEM_DISK=s3`
- Configure all `AWS_*` variables
- Test S3 connection

#### Issue 4: App Key Not Generated

**Problem:** `APP_KEY` is missing or invalid.

**Solution:**
- Generate key: `php artisan key:generate`
- Copy the generated key to Railway variables

## Step-by-Step Deployment with MCP Monitoring

### Step 1: Verify Railway Project Setup

1. Ensure your Crater repository is connected to Railway
2. Verify `railway.json` is in the repo root
3. Check that MySQL service is added to the project

### Step 2: Fix nixpacks.toml Configuration

The current `nixpacks.toml` has cache commands that may fail during build. Update it to:

```toml
[phases.setup]
nixPkgs = ["php82", "composer", "nodejs-18_x"]

[phases.install]
cmds = [
  "composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist",
  "npm ci --production",
  "npm run build"
]

[start]
cmd = "php artisan serve --host=0.0.0.0 --port=$PORT"
```

**Why:** Cache commands (`config:cache`, `route:cache`, `view:cache`) require environment variables that may not be available during build. Run them at runtime instead.

### Step 3: Set Environment Variables

Before deploying, set these critical variables in Railway:

#### Required Variables (Minimum to Start)

```env
APP_NAME=Crater
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-app.railway.app

# Database (use Railway service references)
DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
```

**Note:** Generate `APP_KEY` after first deployment (see Step 5).

### Step 4: Deploy and Monitor with MCP

1. **Trigger Deployment:**
   - Push changes to your GitHub repo, OR
   - Click "Redeploy" in Railway dashboard

2. **Monitor Build Logs:**
   ```bash
   # Using Railway CLI
   railway logs --build
   
   # Or filter for errors
   railway logs --build --filter "error|fail|exception"
   ```

3. **Watch for Common Build Errors:**
   - ❌ "Config cache failed" → Remove cache commands from build
   - ❌ "Composer install failed" → Check PHP version compatibility
   - ❌ "npm build failed" → Check Node.js version
   - ❌ "Docker build" → Ensure NIXPACKS builder is selected

### Step 5: Post-Deployment Setup

After successful deployment:

1. **Generate App Key:**
   ```bash
   # In Railway shell or via CLI
   railway run php artisan key:generate
   ```
   Copy the generated key and add to `APP_KEY` variable.

2. **Run Migrations:**
   ```bash
   railway run php artisan migrate --force
   ```

3. **Install Crater:**
   ```bash
   railway run php artisan crater:install
   ```

4. **Check Deployment Logs:**
   ```bash
   railway logs --deploy
   railway logs --filter "@level:error"
   ```

### Step 6: Configure Storage (REQUIRED)

Railway's filesystem is ephemeral. You MUST configure external storage:

**Option 1: AWS S3**
```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your_key
AWS_SECRET_ACCESS_KEY=your_secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
AWS_USE_PATH_STYLE_ENDPOINT=false
```

**Option 2: Cloudflare R2** (Cheaper alternative)
```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your_r2_key
AWS_SECRET_ACCESS_KEY=your_r2_secret
AWS_DEFAULT_REGION=auto
AWS_BUCKET=your-bucket-name
AWS_ENDPOINT=https://your-account-id.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```

### Step 7: Monitor Runtime with MCP

Once deployed, monitor the application:

```bash
# View real-time logs
railway logs --deploy

# Filter for specific issues
railway logs --filter "database|connection|error|exception"

# Check recent errors only
railway logs --lines 100 --filter "@level:error"

# View JSON formatted logs (more details)
railway logs --json --filter "error"
```

## Troubleshooting with MCP Tools

### Check Build Status

```bash
# Get latest build logs
railway logs --build --lines 200

# Search for specific errors
railway logs --build --filter "composer|npm|php"
```

### Check Deployment Status

```bash
# Get deployment logs
railway logs --deploy --lines 200

# Check for Laravel errors
railway logs --deploy --filter "Laravel|artisan|exception"
```

### Common Error Patterns

1. **Database Connection Failed:**
   ```
   SQLSTATE[HY000] [2002] Connection refused
   ```
   **Fix:** Verify MySQL service is running and `DB_*` variables are correct.

2. **Storage Error:**
   ```
   Disk [s3] not found
   ```
   **Fix:** Set `FILESYSTEM_DISK=s3` and configure AWS variables.

3. **Config Cache Error:**
   ```
   No application encryption key has been specified
   ```
   **Fix:** Generate `APP_KEY` first, then cache config.

4. **Route Cache Error:**
   ```
   Route [login] not defined
   ```
   **Fix:** Don't cache routes during build - run at runtime.

## Updated Configuration Files

### Fixed nixpacks.toml

```toml
[phases.setup]
nixPkgs = ["php82", "composer", "nodejs-18_x"]

[phases.install]
cmds = [
  "composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist",
  "npm ci --production",
  "npm run build"
]

[start]
cmd = "php artisan serve --host=0.0.0.0 --port=$PORT"
```

**Key Change:** Removed cache commands from build phase. These will run at runtime after environment variables are available.

### railway.json (Already Correct)

```json
{
  "$schema": "https://railway.app/railway.schema.json",
  "build": {
    "builder": "NIXPACKS"
  },
  "deploy": {
    "startCommand": "php artisan serve --host=0.0.0.0 --port=$PORT",
    "restartPolicyType": "ON_FAILURE",
    "restartPolicyMaxRetries": 10
  }
}
```

## Deployment Checklist

- [ ] `railway.json` is in repo root
- [ ] `nixpacks.toml` updated (cache commands removed from build)
- [ ] MySQL service added to Railway project
- [ ] Database variables configured with service references
- [ ] Build succeeds (check logs with MCP)
- [ ] `APP_KEY` generated and set
- [ ] Migrations run successfully
- [ ] Crater installation completed
- [ ] Storage configured (S3/R2)
- [ ] Application accessible via Railway URL
- [ ] No errors in deployment logs

## Next Steps

1. **Monitor logs continuously:**
   ```bash
   railway logs --deploy --filter "error|exception|failed"
   ```

2. **Set up custom domain** (optional):
   - Railway Settings > Domains
   - Add your domain
   - Update DNS records

3. **Configure Stripe** (if needed):
   - Add Stripe API keys
   - Set up webhook endpoint
   - Test payment flow

4. **Set up queue worker** (recommended):
   - Create new service
   - Start command: `php artisan queue:work --tries=3 --timeout=90`

## Getting Help

If deployment still fails:

1. **Check Railway logs:**
   ```bash
   railway logs --build --lines 500
   railway logs --deploy --lines 500
   ```

2. **Verify configuration:**
   - Builder is set to NIXPACKS (not Docker)
   - Environment variables are set correctly
   - MySQL service is running

3. **Test locally:**
   - Clone your fork
   - Run `composer install`
   - Check for dependency issues

4. **Railway Support:**
   - Railway Discord: https://discord.gg/railway
   - Railway Docs: https://docs.railway.app

