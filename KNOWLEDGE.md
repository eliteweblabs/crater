# Crater — Self-Hosted Invoicing

Integration knowledge for the Crater invoicing service (Reave Business OS, Telegram bot, etc.).

The deployed instance lives at the URL configured per-deployment
(typically `ap.<your-domain>` or `crater.<your-domain>`). All examples below use
`{CRATER_URL}` as a placeholder.

## API Surface

Crater exposes two API surfaces:

- **Standard Crater API** (`/api/v1/...`) — Bearer-token auth, used for read access
  to customers, invoices, recurring invoices, etc.
- **Custom extensions** (`/api/custom/...`) — `X-Crater-Api-Token` header auth,
  task-oriented endpoints designed for Reave / Telegram integrations.

### Authentication

```
# Standard API
Authorization: Bearer <token>

# Custom extensions
X-Crater-Api-Token: <token>
```

The custom API token is provisioned per-deployment via `CRATER_API_TOKEN` (see Environment Variables below).

## Standard API Endpoints

### Customers

```
GET {CRATER_URL}/api/v1/customers?per_page=50
```

**Pagination is critical.** Crater paginates customers at **10 per page by
default**. If a search comes up empty or short, always fetch additional pages:

```
GET {CRATER_URL}/api/v1/customers?page=1&per_page=50
GET {CRATER_URL}/api/v1/customers?page=2&per_page=50
```

### Invoices

```
GET {CRATER_URL}/api/v1/invoices?per_page=100
```

Filterable: `status=SENT`, `status=PAID`, `status=DRAFT`, etc.

Fields returned include `customer.name`, `total` (in **cents**), `unique_hash`.

### Recurring Invoices

```
GET {CRATER_URL}/api/v1/recurring-invoices
```

Frequencies use cron format:

| Cron | Meaning |
|---|---|
| `0 0 1 5 *` | Yearly, May 1st |
| `0 0 1 */3 *` | Quarterly |
| `0 0 1 * *` | Monthly, 1st of month |

Always fetch current totals from the API at request time — never cache or hardcode
amounts in agent context, since rates and clients change.

## Custom Extension Endpoints

See `routes/api-custom.php` for the full list. Key routes:

### Create Invoice

```
POST {CRATER_URL}/api/custom/create-invoice
Headers:
  X-Crater-Api-Token: <token>
  Content-Type: application/json

{
  "customer_name": "Client Name",
  "customer_email": "client@email.com",
  "items": [
    { "name": "Service Description", "price": 1000, "quantity": 1 }
  ],
  "notes": "Optional notes",
  "status": "SENT"
}
```

`status` is `SENT` or `DRAFT`. `price` is in **dollars** (whole units), not cents
— the conversion happens server-side. `customer_email` is optional but
recommended (used for contact resolution if the customer doesn't already exist).

### Record Offline Payment

```
POST {CRATER_URL}/api/custom/record-payment
Headers:
  X-Crater-Api-Token: <token>
  Content-Type: application/json

{
  "customer_name": "Client Name",
  "amount": 150.00,
  "payment_mode": "CASH"
}
```

`payment_mode` is one of `CASH`, `CHECK`, `CREDIT_CARD`, `BANK_TRANSFER`.

### Customers (search / export)

```
GET {CRATER_URL}/api/custom/customers?q=
Headers:
  X-Crater-Api-Token: <token>
```

Returns customers with billing profile and invoice summary. Optional `q` filter.

## Environment Variables

| Var | Purpose |
|---|---|
| `CRATER_API_TOKEN` | Auth token for `/api/custom/*` endpoints (same value on Astro as `CRATER_API_TOKEN`) |
| `STRIPE_KEY` | Stripe publishable key (Stripe integration) |
| `STRIPE_SECRET` | Stripe secret key |

**Migration:** if upgrading an older Crater deploy, set `CRATER_API_TOKEN` to the same secret previously used for custom API auth.

## Common Tasks

- Create an invoice for a client (use `/api/custom/create-invoice`)
- Record cash/check/transfer payment (use `/api/custom/record-payment`)
- List upcoming recurring invoices (use `/api/v1/recurring-invoices` or `/api/custom/recurring-invoices`)
- View invoice PDF (`/api/v1/invoices/{id}` includes a `pdf_url`)

## Common Pitfalls

1. **Missing data → check pagination.** Default page size is 10 customers.
   Always pass `per_page=50` (or higher) for list operations, and check `page=2+`.
2. **Amounts are in cents on `/api/v1/*` responses.** Divide by 100 for dollar
   display. The custom extension endpoints (`/api/custom/*`) accept and
   return dollars to simplify integration code.
3. **Backend is MySQL** (Railway internal). The schema is large and joins
   are slow — prefer the API over direct DB queries for anything routine.
4. **Stripe is built-in.** Online payments hit `/api/v1/payments` automatically;
   offline payments must be recorded via `/api/custom/record-payment`.

## Related Files in This Repo

- `default-new-client-invoice-items.md` — standard line items for new client setup
- `default-website-invoice-items.md` — standard line items for website projects
- `mavsafe-invoice-items.md` — MAVSAFE-specific line items
- `STRIPE_SETUP.md`, `PUBLIC_PAYMENT_GUIDE.md`, `DATABASE_CLEAR_GUIDE.md`,
  `SETUP_OPTIONS.md`, `COMPANY_SLUG_FIX.md` — operational guides
