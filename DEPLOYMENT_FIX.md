# Critical Fix: PORT Type Error

## Problem Identified via MCP Monitoring

Using Railway MCP tools, we discovered the deployment was failing with:

```
TypeError: Unsupported operand types: string + int
at /var/www/vendor/laravel/framework/src/Illuminate/Foundation/Console/ServeCommand.php:164
```

## Root Cause

Laravel's `php artisan serve --port=$PORT` command receives `$PORT` as a string from Railway's environment, but Laravel's `ServeCommand` expects an integer when it tries to do `$port + $this->portOffset`.

## Solution

Created `start.sh` script that:
1. Reads PORT from environment variable
2. Casts it to integer using bash arithmetic: `PORT=$((PORT))`
3. Passes it to Laravel's serve command

## Files Changed

### 1. Created `start.sh`
```bash
#!/bin/bash
PORT=${PORT:-8000}
PORT=$((PORT))
exec php artisan serve --host=0.0.0.0 --port=$PORT
```

### 2. Updated `railway.json`
```json
{
  "deploy": {
    "startCommand": "bash start.sh"
  }
}
```

### 3. Updated `nixpacks.toml`
```toml
[phases.install]
cmds = [
  ...
  "chmod +x start.sh"
]

[start]
cmd = "bash start.sh"
```

## Next Steps

1. **Commit and push these changes:**
   ```bash
   git add start.sh railway.json nixpacks.toml
   git commit -m "Fix: PORT type error - use start.sh script"
   git push
   ```

2. **Monitor deployment with MCP:**
   ```bash
   railway logs --deploy --filter "error"
   ```

3. **Verify app starts successfully:**
   - Check Railway dashboard for "Running" status
   - Visit your Railway URL
   - Check logs show server starting on correct port

## Why This Works

- Bash arithmetic `$((PORT))` ensures PORT is treated as integer
- The script handles Railway's PORT environment variable correctly
- Falls back to 8000 if PORT not set (shouldn't happen on Railway)
- Uses `exec` to replace shell process with PHP process (cleaner)

## Alternative Solutions (if start.sh doesn't work)

If the script approach doesn't work, try:

**Option 1: Use PHP's built-in server directly**
```bash
php -S 0.0.0.0:$PORT -t public public/index.php
```

**Option 2: Use Railway's PORT directly in nixpacks**
```toml
[start]
cmd = "php artisan serve --host=0.0.0.0 --port=$(echo $PORT | tr -d '\n')"
```

But the `start.sh` approach is cleaner and more maintainable.

