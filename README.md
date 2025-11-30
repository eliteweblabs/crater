# Crater Invoicing - Railway Deployment

Self-hosted invoicing solution with Stripe integration, deployed on Railway.

## Overview

[Crater](https://github.com/crater-invoice/crater) is an open-source invoicing application built with Laravel. This setup is configured for Railway deployment with Stripe payment integration.

## Features

- ✅ Invoicing & Estimates
- ✅ Time Tracking
- ✅ Expense Management
- ✅ Stripe Payment Integration
- ✅ Multi-currency Support
- ✅ Client Portal
- ✅ Reports & Analytics

## Railway Deployment

> **🚨 Having deployment issues?** Check out **[MCP_MONITORING_GUIDE.md](./MCP_MONITORING_GUIDE.md)** for step-by-step troubleshooting using Railway MCP tools and common fixes.

### Prerequisites

1. Railway account
2. GitHub account (to fork Crater repo)
3. Stripe account (for payment processing)
4. Railway CLI installed (for MCP monitoring) - `npm i -g @railway/cli`

### Quick Setup

1. **Fork Crater Repository**
   ```bash
   # Fork: https://github.com/crater-invoice/crater
   ```

2. **Create New Railway Project**
   - Go to Railway dashboard
   - Click "New Project"
   - Select "Deploy from GitHub repo"
   - Choose your forked Crater repository

3. **Add MySQL Database**
   - In Railway project, click "+ New"
   - Select "MySQL" template
   - Railway will auto-generate connection variables

4. **Configure Environment Variables**
   - See `.env.example` for required variables
   - Set `DB_*` variables from MySQL service
   - Add Stripe keys from your Stripe dashboard

5. **Deploy**
   - Railway will auto-detect Laravel and deploy
   - Run migrations: `php artisan migrate`
   - Create admin user: `php artisan crater:install`

### Environment Variables

Required variables (set in Railway):

```env
APP_NAME=Crater
APP_ENV=production
APP_KEY=base64:... (generate with: php artisan key:generate)
APP_DEBUG=false
APP_URL=https://your-domain.railway.app

# Database (from Railway MySQL service)
DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

# Stripe
STRIPE_KEY=sk_live_... (or pk_live_... for public key)
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...

# Storage (required - Railway filesystem is ephemeral)
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
AWS_USE_PATH_STYLE_ENDPOINT=false

# Mail (for invoices)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"
```

### Storage Setup

**Important**: Railway's filesystem is ephemeral. You need external storage:

**Option 1: AWS S3** (Recommended)
- Create S3 bucket
- Set up IAM user with S3 access
- Configure `AWS_*` variables above

**Option 2: DigitalOcean Spaces**
- Similar to S3, compatible with Laravel

**Option 3: Cloudflare R2**
- S3-compatible, cheaper than S3

### Post-Deployment Steps

1. **Run Migrations**
   ```bash
   php artisan migrate --force
   ```

2. **Install Crater**
   ```bash
   php artisan crater:install
   ```
   This will create your admin account.

3. **Configure Stripe**
   - Go to Settings > Payment Gateways
   - Enable Stripe
   - Add your Stripe API keys
   - Set up webhook endpoint: `https://your-domain.railway.app/webhooks/stripe`

4. **Set Up Custom Domain** (Optional)
   - In Railway, go to Settings > Domains
   - Add your custom domain
   - Railway will auto-provision SSL

## Railway Configuration Files

This project includes:
- `railway.json` - Railway service configuration (forces NIXPACKS builder)
- `nixpacks.toml` - Build configuration (optimized for Railway)
- `MCP_MONITORING_GUIDE.md` - Complete troubleshooting guide with MCP tools
- `DEPLOYMENT.md` - Detailed deployment steps
- `.env.example` - Environment variable template

## Troubleshooting

> **📖 For detailed troubleshooting with MCP monitoring, see [MCP_MONITORING_GUIDE.md](./MCP_MONITORING_GUIDE.md)**

### Quick Fixes

**Build Fails:**
- ✅ Ensure `railway.json` specifies `"builder": "NIXPACKS"`
- ✅ Check `nixpacks.toml` doesn't have cache commands in build phase
- ✅ Verify PHP 8.2+ and Node.js are available

**Database Connection Issues:**
- Ensure MySQL service is running (not paused)
- Check `DB_*` variables use correct service reference: `${{MySQL.MYSQLHOST}}`
- Verify database exists

**Storage Issues:**
- Files won't persist without S3/external storage
- Check `FILESYSTEM_DISK` is set to `s3`
- Verify AWS credentials are correct

**App Key Errors:**
- Generate key: `php artisan key:generate`
- Add to `APP_KEY` variable before caching config

**Monitor with Railway CLI:**
```bash
# Check build logs
railway logs --build --filter "error"

# Check deployment logs
railway logs --deploy --filter "error|exception"

# View recent errors
railway logs --lines 100 --filter "@level:error"
```

### Queue Jobs Not Running
- Add a separate service for queue worker:
  ```bash
  php artisan queue:work --tries=3
  ```
- Or use Railway's background worker service

### Stripe Webhooks
- Webhook URL: `https://your-domain.railway.app/webhooks/stripe`
- Add in Stripe Dashboard > Webhooks
- Use webhook secret in `STRIPE_WEBHOOK_SECRET`

## Resources

- [Crater GitHub](https://github.com/crater-invoice/crater)
- [Crater Documentation](https://docs.craterapp.com/)
- [Railway Docs](https://docs.railway.app/)
- [Laravel on Railway](https://docs.railway.app/guides/laravel)

## Support

For issues:
- Crater: [GitHub Issues](https://github.com/crater-invoice/crater/issues)
- Railway: [Railway Discord](https://discord.gg/railway)


