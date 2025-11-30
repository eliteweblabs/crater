# Quick Fix Guide - Crater Railway Deployment

## Most Common Issues & Immediate Fixes

### ❌ Issue 1: Build Fails with "Config cache" or "Route cache" Errors

**Error Message:**
```
No application encryption key has been specified
Route [login] not defined
```

**Root Cause:** `nixpacks.toml` tries to cache Laravel config/routes during build, but `APP_KEY` isn't set yet.

**✅ Fix:** Already fixed! The `nixpacks.toml` has been updated to remove cache commands from build phase.

**If you still see this:**
1. Verify `nixpacks.toml` doesn't have `[phases.build]` section with cache commands
2. Ensure `APP_KEY` is generated AFTER first deployment
3. Run cache commands manually after deployment if needed

---

### ❌ Issue 2: Railway Uses Docker Instead of Nixpacks

**Error Message:**
```
useradd: invalid user ID '-d'
Building Docker image...
```

**✅ Fix:**
1. Verify `railway.json` exists in repo root with:
   ```json
   {
     "build": {
       "builder": "NIXPACKS"
     }
   }
   ```
2. In Railway Dashboard: Service Settings → Builder → Select "Nixpacks"
3. Redeploy

---

### ❌ Issue 3: Database Connection Failed

**Error Message:**
```
SQLSTATE[HY000] [2002] Connection refused
```

**✅ Fix:**
1. Check MySQL service is running (not paused) in Railway
2. Verify database variables use service references:
   ```env
   DB_HOST=${{MySQL.MYSQLHOST}}
   DB_PORT=${{MySQL.MYSQLPORT}}
   DB_DATABASE=${{MySQL.MYSQLDATABASE}}
   DB_USERNAME=${{MySQL.MYSQLUSER}}
   DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
   ```
3. Ensure MySQL service name matches (if not "MySQL", update references)

---

### ❌ Issue 4: Storage/Filesystem Errors

**Error Message:**
```
Disk [s3] not found
Files not persisting
```

**✅ Fix:**
1. Set `FILESYSTEM_DISK=s3` in Railway variables
2. Configure AWS S3 or Cloudflare R2:
   ```env
   AWS_ACCESS_KEY_ID=your_key
   AWS_SECRET_ACCESS_KEY=your_secret
   AWS_DEFAULT_REGION=us-east-1
   AWS_BUCKET=your-bucket-name
   ```
3. For Cloudflare R2, also add:
   ```env
   AWS_ENDPOINT=https://your-account-id.r2.cloudflarestorage.com
   AWS_USE_PATH_STYLE_ENDPOINT=true
   ```

---

### ❌ Issue 5: 500 Internal Server Error

**✅ Fix:**
1. Generate `APP_KEY`:
   ```bash
   railway run php artisan key:generate
   ```
2. Add generated key to `APP_KEY` variable
3. Clear config cache:
   ```bash
   railway run php artisan config:clear
   ```

---

### ❌ Issue 6: Build Succeeds but App Won't Start

**Error Message:**
```
TypeError: Unsupported operand types: string + int
at ServeCommand.php:164
```

**Root Cause:** Laravel's `php artisan serve` receives `$PORT` as a string, but expects an integer.

**✅ Fix:** Use the `start.sh` script that properly casts PORT to integer:
```bash
# start.sh ensures PORT is treated as integer
PORT=$((PORT))
php artisan serve --host=0.0.0.0 --port=$PORT
```

**Verify:**
1. `start.sh` exists in repo root and is executable
2. `railway.json` uses: `"startCommand": "bash start.sh"`
3. Check deployment logs:
   ```bash
   railway logs --deploy --filter "error"
   ```

---

## Quick Diagnostic Commands

Use Railway CLI to diagnose issues:

```bash
# Check if Railway CLI is authenticated
railway status

# View build logs
railway logs --build --lines 200

# View deployment logs
railway logs --deploy --lines 200

# Filter for errors only
railway logs --filter "error|exception|failed"

# Check specific service logs
railway logs --service your-service-name

# Run Laravel commands
railway run php artisan migrate
railway run php artisan key:generate
railway run php artisan config:clear
```

---

## Deployment Checklist

Before deploying, ensure:

- [ ] `railway.json` is in repo root with `"builder": "NIXPACKS"`
- [ ] `nixpacks.toml` doesn't have cache commands in build phase
- [ ] MySQL service added to Railway project
- [ ] Database variables configured with `${{MySQL.*}}` references
- [ ] `APP_KEY` will be generated after first deployment
- [ ] Storage configured (S3/R2) - **REQUIRED**
- [ ] `APP_URL` set to your Railway domain

After deployment:

- [ ] Build succeeds (check logs)
- [ ] Generate `APP_KEY` and add to variables
- [ ] Run migrations: `php artisan migrate --force`
- [ ] Install Crater: `php artisan crater:install`
- [ ] Verify app is accessible
- [ ] Check deployment logs for errors

---

## Still Stuck?

1. **Check detailed guide:** [MCP_MONITORING_GUIDE.md](./MCP_MONITORING_GUIDE.md)
2. **View full deployment steps:** [DEPLOYMENT.md](./DEPLOYMENT.md)
3. **Railway Support:** https://discord.gg/railway
4. **Crater Issues:** https://github.com/crater-invoice/crater/issues

---

## Configuration Files Status

✅ **railway.json** - Correctly configured for NIXPACKS  
✅ **nixpacks.toml** - Fixed (cache commands removed from build)  
✅ **Dockerfile.railway** - Available as backup (not needed with NIXPACKS)

