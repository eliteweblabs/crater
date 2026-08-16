# Crater — Self-Hosted Invoicing

Agent playbook for the Crater invoicing service (Reave Business OS, Telegram bot, etc.).
Reave also ships a short copy as `plugins/billing/knowledge/crater-billing.md` — keep both in sync.

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

### Get / update / add items

```
GET  {CRATER_URL}/api/custom/invoice/{id}
PUT  {CRATER_URL}/api/custom/invoice/{id}
POST {CRATER_URL}/api/custom/invoice/{id}/items
PUT  {CRATER_URL}/api/custom/invoice/{invoiceId}/items/{itemId}
```

`GET` returns line items with stored `name` (including `(optional)` / `(required)` tags), `quantity`, and dollar `price` / `total`.

`PUT /invoice/{id}` updates status, due date, or notes only — **not** line item names.

`PUT /invoice/{invoiceId}/items/{itemId}` edits one row. Body fields are all optional:

```
{ "name": "Plausible Analytics - 1 Year - 10/2026 - 10/2027 (optional) (yearly)" }
```

`price` is whole dollars. Changing `price` or `quantity` recalculates that row and the invoice totals. A name-only edit does not change totals. Use this for typos on SENT invoices (do not delete + re-add).

## Public invoice add-on toggles

The public page (`/invoices/{unique_hash}`) is what clients open. Recent behavior:

- **Qty and rate are hidden.** The client sees name, description, amount, and (for add-ons) a toggle.
- **Optional rows get a switch.** Toggling live-updates subtotal / due and is sent to Stripe as `optional_item_ids` when they pay.
- **Paid invoices lock.** Toggles do not appear after `paid_status = PAID`. Declined add-ons (qty `0`) are hidden on the paid page and on the PDF.
- **PDF** (`/invoices/pdf/{hash}`) is the stored invoice, not the live toggles. On an unpaid proposal with add-ons the public page hides Download PDF until they pay (selection is written when the PaymentIntent is created). Invoices with no optional rows keep the button. The PDF omits qty-`0` add-ons and uses `publicDisplayName()` so `(optional)` tags do not print.
- **OG card** is `/invoices/{hash}/og.png` (REΛVE icon). Share previews use that, not a screenshot of the line items. The preview **title** is `{client name} / {company name} - Invoice for Service` (`Invoice::sharePreviewTitle()`).

### How a line becomes a toggle

Detection is **name-only** (`InvoiceItem::isOptional()`). There is no separate `optional` column.

| Stored name contains | Public behavior |
|---|---|
| `(optional)` or `[optional]` | Toggle. Client can include or leave off. |
| `can be added anytime` | Same as optional (converted estimates). |
| `(required)` | **Never** a toggle, even if the name also says optional. |
| no tag | Required. No switch. Amount is fixed. |

The public title is `publicDisplayName()` — it strips `(optional)`, `[optional]`, `(required)`, and `(…can be added anytime…)`. The client sees **Plausible Analytics - 1 Year - 10/2026 - 10/2027 (yearly)** plus an **Optional** badge. Keep the tag in the stored name so the switch still works.

### Quantity is the on/off bit

| Quantity | Meaning for an optional row |
|---|---|
| `0` | Toggle starts **off**. Amount due does not include it. |
| `> 0` (usually `1`) | Toggle starts **on**. |

When the client pays, checked add-ons are saved as qty `1` and unchecked as qty `0`, then totals and `due_amount` are recalculated. Required rows are never changed by the toggle.

**When creating a proposal-style invoice:** tag add-ons `(optional)` (or `(optional) (yearly)` / `(optional) (can be added anytime)`) and send **quantity `0`** so they start off. Tag must-haves `(required)` with quantity `1`. Untagged names are required and have no switch.

Examples:

```
Web Design (required)
Railway Web Hosting - 1 Year - 10/2026 - 10/2027 (required) (yearly)
Plausible Analytics - 1 Year - 10/2026 - 10/2027 (optional) (yearly)
Booxie White Label (optional) (can be added anytime)
```

Do not invent product names. **Plausible Analytics** is the analytics add-on — never "Phaseline Analytics".

## Environment Variables

| Var | Purpose |
|---|---|
| `CRATER_API_TOKEN` | Auth token for `/api/custom/*` endpoints (same value on Astro as `CRATER_API_TOKEN`) |
| `STRIPE_KEY` | Stripe publishable key (Stripe integration) |
| `STRIPE_SECRET` | Stripe secret key |

**Migration:** if upgrading an older Crater deploy, set `CRATER_API_TOKEN` to the same secret previously used for custom API auth.

## Common Tasks

- Create an invoice for a client (use `/api/custom/create-invoice`)
- Mark add-ons `(optional)` with quantity `0` so the public page shows toggles off
- Rename or fix a line on a SENT invoice (`PUT /api/custom/invoice/{id}/items/{itemId}`)
- Record cash/check/transfer payment (use `/api/custom/record-payment`)
- List upcoming recurring invoices (use `/api/v1/recurring-invoices` or `/api/custom/recurring-invoices`)
- View invoice PDF (`/api/v1/invoices/{id}` includes a `pdf_url`)
- Send the client `public_url` (`/invoices/{unique_hash}`), not the admin view

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
5. **`update_invoice` cannot rename a line.** Use `PUT .../items/{itemId}`.
   Do not delete and recreate a SENT invoice to fix a typo.
6. **Optional is a name tag, not a field.** If the switch is missing, the stored
   name has no `(optional)` / `can be added anytime` (or it also has `(required)`).
7. **Never replace `routes/api-custom.php`.** Append routes only. A stub file
   drops create-invoice, payments, and everything else.
8. **Never change `RouteServiceProvider` namespace.** It must stay
   `Crater\Providers`. `config/app.php` registers that class. `App\Providers`
   makes every URL 500 — including the client's public invoice link.
   Custom routes are already loaded when `routes/api-custom.php` exists.

## Related Files in This Repo

- `default-new-client-invoice-items.md` — standard line items for new client setup
- `default-website-invoice-items.md` — standard line items for website projects
- `mavsafe-invoice-items.md` — MAVSAFE-specific line items
- `STRIPE_SETUP.md`, `PUBLIC_PAYMENT_GUIDE.md`, `DATABASE_CLEAR_GUIDE.md`,
  `SETUP_OPTIONS.md`, `COMPANY_SLUG_FIX.md` — operational guides
