# Wix payments → Crater import

Bulk-import Wix payment export JSON as Crater **invoices + payments** via the **v1 admin API**.

## Input JSON

Array of objects:

```json
{
  "invoiceNumber": "0000001",
  "paymentDate": "2024-01-15",
  "customerEmail": "client@example.com",
  "customerName": "Jane Client",
  "amount": 45.0,
  "method": "Credit card",
  "note": null
}
```

Extra fields (`dateSource`, `inWixPayments`, etc.) are ignored.

Default file name: **`payments.json`** in this folder (or pass a path as the first CLI argument).

## Setup

1. `cp .env.example .env` — set `CRATER_API_BASE_URL` and `CRATER_API_TOKEN` (Bearer from `/api/v1/auth/login`).
2. Place your export as `payments.json` (or pass a path).
3. `npm install && node import.js`

Auth: `/api/v1/*` uses `Authorization: Bearer …` and header `company: <CRATER_COMPANY_ID>`.  
This is separate from `CRATER_API_TOKEN` used for `/api/custom/*`.

## Behaviour

- Customer lookup by **email** (case-insensitive).
- Wix → Crater payment method names (`METHOD_MAP` in `import.js`).
- Amounts in JSON are **USD dollars**; API uses **integer cents**.
- **150ms** delay between steps; progress every **50** rows.
- **`import_log.json`** — re-run skips rows with `"status": "ok"`.

After a large import, consider **`POST /api/custom/repair-invoice-numbers`** on Crater.
