# Railway MySQL Port Configuration

## The Issue

Railway MySQL services expose their port via environment variables. The port might not be `3306` - it depends on Railway's configuration.

## How Railway MySQL Works

Railway MySQL services provide these environment variables:
- `MYSQLHOST` - Database host
- `MYSQLPORT` - Database port (might not be 3306!)
- `MYSQLDATABASE` - Database name
- `MYSQLUSER` - Database username
- `MYSQLPASSWORD` - Database password

## Check Your Actual MySQL Port

### Option 1: Check Railway Variables

1. Go to Railway Dashboard
2. Click on your **MySQL service** (not Crater service)
3. Click **Variables** tab
4. Look for `MYSQLPORT` - this is your actual port!

### Option 2: Check from Crater Service

1. Railway Dashboard → **Crater service** → **Variables** tab
2. Look for variables starting with `MYSQL` or `DB_`
3. Find `MYSQLPORT` or `DB_PORT`

## Common Railway MySQL Ports

- **Internal connection** (`db.railway.internal`): Usually `3306` but can vary
- **External connection**: Different port (if exposed)

## Fix in Installation Form

When filling out the installation form:

1. **Database Host**: `db.railway.internal` ✅ (correct for internal)
2. **Database Port**: Check your Railway MySQL service variables for `MYSQLPORT`
   - If `MYSQLPORT` exists, use that value
   - If not found, try `3306` (default MySQL port)
   - If connection fails, try checking Railway MySQL service logs

## How to Find the Correct Port

### Via Railway Dashboard:

1. **MySQL Service** → **Variables** → Look for `MYSQLPORT`
2. Or **MySQL Service** → **Settings** → Check connection details

### Via Railway Shell:

```bash
# In Crater service shell
echo $MYSQLPORT
# or
env | grep MYSQL
```

### Via Railway CLI:

```bash
railway variables --service mysql
# or check Crater service variables
railway variables
```

## Quick Fix

If port `3306` doesn't work:

1. **Check Railway MySQL Variables** for actual port
2. **Update the installation form** with the correct port
3. **Try connection again**

## Alternative: Use Railway's Auto-Injected Variables

Instead of manually entering, you can use Railway's service references in your `.env`:

```env
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
```

But for the installation wizard, you need to enter the actual values.

