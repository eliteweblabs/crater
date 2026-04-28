# Crater — Self-Hosted Invoicing

Deployed on Railway: ap.reave.app

## API Endpoints (OpenClaw)

### Create Invoice
```
POST /api/openclaw/create-invoice
Header: X-OpenClaw-Token: <token>
```
```json
{
  "customer_name": "Client Name",
  "items": [{"name": "Service", "price": 150, "quantity": 1}]
}
```

### Record Offline Payment
```
POST /api/openclaw/record-payment
Header: X-OpenClaw-Token: <token>
```
```json
{
  "customer_name": "Client Name",
  "amount": 150.00,
  "payment_mode": "CASH|CHECK|CREDIT_CARD|BANK_TRANSFER"
}
```

## Environment Variables

| Var | Purpose |
|-----|---------|
| `OPENCLAW_API_TOKEN` | Auth token for OpenClaw endpoints |
| `STRIPE_KEY` | Stripe publishable key |
| `STRIPE_SECRET` | Stripe secret key |

## Common Tasks

- Create invoice for client
- Record cash/check payment
- View invoice PDF

## Notes

- Uses MySQL on Railway (internal)
- 18 clients, 13 recurring invoices
- Stripe integration built-in