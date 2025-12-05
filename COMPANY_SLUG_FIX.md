# Fix: "xyz" Company Slug Issue

## The Problem

If you're seeing 401 errors with URLs like `/xyz/customer/...`, it's because "xyz" is the **demo company slug** from the initial seeder. Your actual company likely has a different slug.

## Quick Fix

### Option 1: Find Your Actual Company Slug

Run this command to see all your companies:

```bash
php check-company-slug.php
```

Or use tinker:

```bash
php artisan tinker
```

Then:

```php
\Crater\Models\Company::all()->pluck('name', 'slug')
```

This will show you all companies with their slugs.

### Option 2: Update the "xyz" Company Slug

If you want to keep using "xyz" but update it to your actual company:

```bash
php artisan tinker
```

```php
$company = \Crater\Models\Company::where('slug', 'xyz')->first();
$company->slug = 'your-actual-company-name'; // Use your real company name
$company->save();
```

### Option 3: Use Your Actual Company Slug in URLs

Once you know your actual company slug, use it in all customer portal URLs:

- Instead of: `https://your-domain.com/xyz/customer/invoices/1/view`
- Use: `https://your-domain.com/your-actual-slug/customer/invoices/1/view`

## How Company Slugs Work

- Company slugs are created from the company name (lowercase, spaces become hyphens)
- Example: "My Company" → slug: "my-company"
- The slug is used in customer portal URLs: `/{slug}/customer/...`
- Each company has a unique slug

## Payment Routes Don't Need Company Slug

**Important**: The payment route `/invoices/{hash}/pay` is **public** and doesn't require a company slug or authentication. This route works regardless of the company slug issue.

## Verify Your Setup

1. Check your company slug: `php check-company-slug.php`
2. Update if needed using tinker
3. Use the correct slug in customer portal URLs
4. Payment links (like `/invoices/{hash}/pay`) work independently

