# PDX Paws Wix → Crater import

Imports **2,313** Wix payment rows as Crater **invoices + payments** via the **v1 admin API**.

## Setup

1. Copy `PDXPaws_Payments.json` into this folder (not committed — large / PII).
2. Create a **Sanctum personal access token** in Crater (admin user → API / account token).  
   `/api/v1/*` expects `Authorization: Bearer <sanctum_token>` and header `company: 1`.  
   It does **not** accept `CRATER_API_TOKEN` (that is for `/api/custom/*` only).
3. `cp .env.example .env` and set:
   - `CRATER_API_TOKEN` — Sanctum Bearer token
   - `CRATER_API_BASE_URL` — e.g. `https://crater-production-8a0e.up.railway.app`
   - `CRATER_COMPANY_ID` — usually `1`

```bash
npm install
node import.js
# or: node import.js /path/to/PDXPaws_Payments.json
```

## Behaviour

- Maps customer by **email** (case-insensitive).
- Maps Wix payment methods to Crater payment mode names (see `METHOD_MAP` in `import.js`).
- Amounts in JSON are **dollars**; v1 API uses **integer cents**.
- **150ms** delay between invoice + payment steps.
- **`import_log.json`** — resume by skipping rows with `"status": "ok"`.
- Progress every **50** newly processed rows.

After import, run Crater **`POST /api/custom/repair-invoice-numbers`** if sequence display needs cleanup.
