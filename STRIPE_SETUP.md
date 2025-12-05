# Stripe Payment Setup Guide

## Important: Stripe is Already Built-In!

**You do NOT need modules to accept payments.** Stripe payment functionality is already integrated into Crater and works without any modules.

## Configuration Steps

### 1. Get Your Stripe API Keys

1. Sign up or log in to [Stripe Dashboard](https://dashboard.stripe.com/)
2. Go to **Developers** → **API keys**
3. Copy your **Publishable key** and **Secret key**
   - For testing: Use **Test mode** keys
   - For production: Use **Live mode** keys

### 2. Set Environment Variables

Add these to your `.env` file or Railway environment variables:

```env
STRIPE_KEY=pk_test_...your_publishable_key
STRIPE_SECRET=sk_test_...your_secret_key
STRIPE_WEBHOOK_SECRET=whsec_...your_webhook_secret  # Optional, for webhook verification
```

### 3. Configure Webhook (Recommended for Production)

1. In Stripe Dashboard, go to **Developers** → **Webhooks**
2. Click **Add endpoint**
3. Set the endpoint URL to: `https://your-domain.com/api/v1/webhook/stripe`
4. Select events to listen for:
   - `checkout.session.completed`
5. Copy the **Signing secret** and add it to `STRIPE_WEBHOOK_SECRET` in your environment

### 4. Test the Integration

1. Create an invoice in Crater
2. View the invoice as a customer
3. Click the **Pay Invoice** button
4. You should be redirected to Stripe Checkout

## Payment Features

- ✅ Credit/Debit Cards
- ✅ Apple Pay / Google Pay (via Stripe)
- ✅ Stripe Link (1-click checkout)
- ✅ Cash App
- ✅ ACH Bank Transfers (US)

## Troubleshooting

### "Can't get modules with API access"

This error is about the module marketplace, NOT payment functionality. You can ignore this error - Stripe payments work independently of the module system.

### Payments not working?

1. Check that `STRIPE_SECRET` and `STRIPE_KEY` are set correctly
2. Verify you're using the correct mode (test vs live keys)
3. Check your application logs for Stripe errors
4. Ensure your Stripe account is activated

### Module Marketplace Access

If you want to access the module marketplace (for other modules, not payments):
- You need an API token from Crater's marketplace
- This is separate from payment functionality
- Contact Crater support if you need marketplace access

## Routes Available

- **Customer Portal**: `/api/v1/{company}/customer/invoices/{id}/stripe/checkout` (POST)
- **Public Invoice**: `/invoices/{hash}/pay` (GET)
- **Webhook**: `/api/v1/webhook/stripe` (POST)

## Notes

- Stripe amounts are automatically converted to cents (smallest currency unit)
- Zero-decimal currencies (JPY, KRW, etc.) are handled correctly
- Payments automatically update invoice status when completed
- Transaction records are created for tracking

