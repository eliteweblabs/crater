# Crater Railway Deployment Guide

Step-by-step guide to deploy Crater to Railway.

## Step 1: Fork Crater Repository

1. Go to https://github.com/crater-invoice/crater
2. Click "Fork" button
3. Fork to your GitHub account

## Step 2: Create Railway Project

1. Go to https://railway.app
2. Click "New Project"
3. Select "Deploy from GitHub repo"
4. Choose your forked Crater repository
5. **IMPORTANT:** Before Railway builds, add `railway.json` to your repo root (see below)
6. Railway will start detecting and building

### Add railway.json to Your Fork

Copy the `railway.json` file from this directory to your forked Crater repository root. This tells Railway to use Nixpacks (which works better for Laravel) instead of Docker.

**Quick fix if build fails:**

- Railway may try to use Docker first
- If you see Docker build errors, add `railway.json` to force Nixpacks
- Or go to Service Settings > Builder and select "Nixpacks"

## Step 3: Add MySQL Database

1. In your Railway project, click "+ New"
2. Select "MySQL" template
3. Railway will create MySQL service
4. Note the service name (usually "MySQL")

## Step 4: Configure Environment Variables

1. Click on your Crater service
2. Go to "Variables" tab
3. Add these variables:

### Required Variables

```env
APP_NAME=Crater
APP_ENV=production
APP_KEY=base64:YOUR_GENERATED_KEY
APP_DEBUG=false
APP_URL=https://your-app.railway.app
```

### Database Variables (from MySQL service)

Railway will auto-inject these, but verify they exist:

- `DB_CONNECTION=mysql`
- `DB_HOST=${{MySQL.MYSQLHOST}}`
- `DB_PORT=${{MySQL.MYSQLPORT}}`
- `DB_DATABASE=${{MySQL.MYSQLDATABASE}}`
- `DB_USERNAME=${{MySQL.MYSQLUSER}}`
- `DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}`

### Stripe Variables

Get these from https://dashboard.stripe.com/apikeys

```env
STRIPE_KEY=pk_live_... (or pk_test_... for testing)
STRIPE_SECRET=sk_live_... (or sk_test_... for testing)
STRIPE_WEBHOOK_SECRET=whsec_... (create webhook first)
```

### Storage Variables (REQUIRED)

Railway filesystem is ephemeral. You MUST use external storage:

**Option 1: AWS S3**

```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
AWS_USE_PATH_STYLE_ENDPOINT=false
```

**Option 2: DigitalOcean Spaces**

```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your_spaces_key
AWS_SECRET_ACCESS_KEY=your_spaces_secret
AWS_DEFAULT_REGION=nyc3
AWS_BUCKET=your-space-name
AWS_ENDPOINT=https://nyc3.digitaloceanspaces.com
AWS_USE_PATH_STYLE_ENDPOINT=false
```

**Option 3: Cloudflare R2**

```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your_r2_access_key
AWS_SECRET_ACCESS_KEY=your_r2_secret_key
AWS_DEFAULT_REGION=auto
AWS_BUCKET=your-bucket-name
AWS_ENDPOINT=https://your-account-id.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```

### Mail Variables

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="Crater"
```

## Step 5: Generate App Key

1. In Railway, go to your Crater service
2. Click "Deployments" tab
3. Click on latest deployment
4. Click "View Logs"
5. Open "Shell" tab
6. Run:
   ```bash
   php artisan key:generate
   ```
7. Copy the generated key
8. Add to `APP_KEY` variable

## Step 6: Run Migrations

In the Railway shell:

```bash
php artisan migrate --force
```

## Step 7: Install Crater

In the Railway shell:

```bash
php artisan crater:install
```

This will prompt you to create an admin account.

## Step 8: Set Up Stripe Webhook

1. Go to https://dashboard.stripe.com/webhooks
2. Click "Add endpoint"
3. URL: `https://your-app.railway.app/webhooks/stripe`
4. Select events:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `charge.refunded`
5. Copy webhook signing secret
6. Add to `STRIPE_WEBHOOK_SECRET` variable

## Step 9: Configure Custom Domain (Optional)

1. In Railway, go to Settings > Domains
2. Click "Custom Domain"
3. Add your domain
4. Update DNS records as instructed
5. Railway will auto-provision SSL

## Step 10: Set Up Queue Worker (Optional but Recommended)

For background jobs (email sending, etc.):

1. In Railway project, click "+ New"
2. Select "Empty Service"
3. Connect to same GitHub repo
4. Set start command:
   ```bash
   php artisan queue:work --tries=3 --timeout=90
   ```
5. Add same environment variables

## Troubleshooting

### Database Connection Failed

- Check MySQL service is running
- Verify `DB_*` variables use correct service reference
- Test connection in Railway shell: `php artisan tinker`

### Storage Errors

- Verify `FILESYSTEM_DISK=s3`
- Check AWS credentials are correct
- Test S3 connection

### 500 Errors

- Check logs in Railway
- Verify `APP_KEY` is set
- Run `php artisan config:clear`

### Stripe Payments Not Working

- Verify Stripe keys are correct
- Check webhook is configured
- Test webhook endpoint is accessible

## Post-Deployment Checklist

- [ ] App key generated
- [ ] Migrations run successfully
- [ ] Admin account created
- [ ] Stripe keys configured
- [ ] Stripe webhook set up
- [ ] Storage configured (S3/Spaces/R2)
- [ ] Mail configured
- [ ] Custom domain set up (optional)
- [ ] Queue worker running (optional)
- [ ] Test invoice creation
- [ ] Test Stripe payment

## Next Steps

1. Log in to your Crater instance
2. Go to Settings > Company Settings
3. Configure your company details
4. Go to Settings > Payment Gateways
5. Enable and configure Stripe
6. Create your first invoice!
