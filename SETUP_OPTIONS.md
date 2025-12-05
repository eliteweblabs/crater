# Crater Setup Options

## Option 1: Try the Web Installer Again

The following fixes have been applied:
- Service providers handle database connection failures gracefully
- LoginController handles missing users and tries to run seeders
- FinishController runs migrations when called
- Better error handling throughout

To try the installer:
1. Go to your Railway app URL
2. Follow the installation wizard
3. If Step 4 (Domain) fails, try refreshing and entering your domain again

---

## Option 2: Automatic Setup (Bypass Installer)

Set these environment variables in Railway Dashboard → Variables:

```
AUTO_SETUP=true
ADMIN_EMAIL=your@email.com
ADMIN_PASSWORD=your_secure_password
ADMIN_NAME=Your Name
COMPANY_NAME=Your Company Name
```

Then redeploy. The app will automatically:
1. Run migrations
2. Create your admin user
3. Create your company
4. Mark installation as complete

---

## Option 3: Manual CLI Setup

If you have Railway CLI access or can run commands in the Railway shell:

```bash
php artisan crater:setup \
  --email=your@email.com \
  --password=your_password \
  --name="Your Name" \
  --company="Your Company" \
  --force
```

---

## Debugging

### Health Check Endpoint

Visit: `https://your-app.up.railway.app/api/v1/health`

This shows:
- Database connection status
- Whether tables exist
- User count
- Installation progress

### Check Logs

```bash
railway logs --deploy --lines 100
```

---

## Required Environment Variables

Make sure these are set in Railway:

| Variable | Description | Example |
|----------|-------------|---------|
| `DB_HOST` | MySQL host | `mysql.railway.internal` |
| `DB_PORT` | MySQL port | `3306` |
| `DB_DATABASE` | Database name | `railway` |
| `DB_USERNAME` | Database user | `root` |
| `DB_PASSWORD` | Database password | (from Railway MySQL) |
| `APP_KEY` | Laravel app key | `base64:...` (auto-generated) |
| `APP_URL` | Your app URL | `https://crater-production.up.railway.app` |

### Optional for Auto-Setup

| Variable | Description | Default |
|----------|-------------|---------|
| `AUTO_SETUP` | Enable auto-setup | `false` |
| `ADMIN_EMAIL` | Admin email | `admin@crater.app` |
| `ADMIN_PASSWORD` | Admin password | `password123` |
| `ADMIN_NAME` | Admin name | `Admin` |
| `COMPANY_NAME` | Company name | `My Company` |

---

## If All Else Fails

The nuclear option - wipe everything and start fresh:

1. In Railway MySQL, drop and recreate the database
2. Set `AUTO_SETUP=true` with your credentials
3. Redeploy

This will do a clean installation without the web wizard.

