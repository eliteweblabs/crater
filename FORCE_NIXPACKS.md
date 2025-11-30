# Force Railway to Use Nixpacks Instead of Docker

Railway is still trying to use Docker, which is causing the build error. Here's how to force Nixpacks:

## Quick Fix: Update railway.json in Your Fork

The `railway.json` in your repo might not be configured correctly. Update it to force Nixpacks:

1. **Go to your GitHub fork:** https://github.com/eliteweblabs/crater
2. **Click on `railway.json`** in the file list
3. **Click the pencil icon** to edit
4. **Replace the contents** with:

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

5. **Commit the change**
6. Railway will automatically rebuild with Nixpacks

## Alternative: Force in Railway Dashboard

If updating the file doesn't work:

1. **In Railway, click on your `crater` service**
2. **Go to Settings tab**
3. **Scroll to "Build & Deploy" section**
4. **Change "Builder" from "Docker" to "Nixpacks"**
5. **Click "Redeploy"**

## Why This Works

- **Nixpacks** automatically detects Laravel and installs PHP, Composer, dependencies
- **No Dockerfile needed** - Nixpacks handles everything
- **No build args** - avoids the `useradd` error you're seeing

## Verify It's Using Nixpacks

After redeploying, check the build logs:
- Should see "Detected Laravel" or "Using Nixpacks"
- Should NOT see "Building Docker image"
- Should see PHP/Composer installation steps


