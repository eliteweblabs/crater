# Public Invoice Payment Guide

## ✅ Payment is Already Public (No Auth Required)

Your payment system is configured to work **without any authentication**. Customers can pay invoices directly using public links.

## Public Routes (No Authentication Required)

### 1. Direct Payment Link
```
https://your-domain.com/invoices/{unique_hash}/pay
```
- **No authentication required**
- Redirects directly to Stripe Checkout
- Works for any invoice

### 2. Public Invoice View
```
https://your-domain.com/customer/invoices/view/{email_log_token}
```
- **No authentication required**
- Shows invoice details
- Has "Pay Invoice" button
- Uses token from email when invoice was sent

## How to Get Public Links

### Option 1: From Email (Recommended)
When you send an invoice via email, the customer receives a link like:
```
https://your-domain.com/customer/invoices/view/{token}
```
This link is public and doesn't require login.

### Option 2: Direct Payment Link
Get the invoice's `unique_hash` and create:
```
https://your-domain.com/invoices/{unique_hash}/pay
```

### Option 3: Via Tinker
```bash
php artisan tinker
```

```php
// Get invoice unique_hash
$invoice = \Crater\Models\Invoice::find(2);
echo $invoice->unique_hash;

// Or get email log token (if invoice was sent)
$emailLog = $invoice->emailLogs()->latest()->first();
echo $emailLog->token;
```

## ❌ Routes That Require Authentication

These routes **DO require authentication** and won't work without proper company slug and customer login:

- `/xyz/customer/invoices/2/view` - Requires auth
- `/{company-slug}/customer/invoices/{id}/view` - Requires auth

**Don't use these for public payments!**

## Payment Flow

1. **Customer receives invoice email** → Gets public link with token
2. **Customer clicks link** → Views invoice at `/customer/invoices/view/{token}`
3. **Customer clicks "Pay Invoice"** → Redirects to `/invoices/{hash}/pay`
4. **Server creates Stripe session** → Redirects to Stripe Checkout
5. **Customer pays** → Redirected back to invoice view with success message
6. **Invoice marked as paid** → Automatically updated

## Testing

To test public payment:

1. Create an invoice
2. Send it via email (or get the `unique_hash`)
3. Use the public link: `https://your-domain.com/invoices/{unique_hash}/pay`
4. You'll be redirected to Stripe Checkout (no login required)

## Important Notes

- ✅ Payment routes are **completely public**
- ✅ No customer account needed
- ✅ No company slug needed
- ✅ Works with any invoice
- ❌ Don't use customer portal routes (`/xyz/customer/...`) for public access

## Troubleshooting

**"401 Unauthorized" errors?**
- You're using the wrong route (customer portal route)
- Use public routes instead: `/invoices/{hash}/pay` or `/customer/invoices/view/{token}`

**Payment button not working?**
- Check that Stripe keys are configured: `STRIPE_SECRET` and `STRIPE_KEY`
- Verify invoice is not already paid
- Check application logs for Stripe errors

