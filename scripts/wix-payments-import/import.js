/**
 * Wix payments export → Crater invoices + payments (v1 API).
 *
 *   cp .env.example .env
 *   npm install
 *   node import.js [path/to/payments.json]
 */
import 'dotenv/config';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const BASE_URL = process.env.CRATER_API_BASE_URL?.trim()?.replace(/\/+$/, '');
const TOKEN = process.env.CRATER_API_TOKEN?.trim();
const COMPANY_ID = String(process.env.CRATER_COMPANY_ID || '1').trim();
const DELAY_MS = 150;
const PROGRESS_EVERY = 50;
const LOG_PATH = path.join(__dirname, 'import_log.json');
const PAYMENTS_PATH = process.argv[2] || path.join(__dirname, 'payments.json');

/** Wix method label → Crater payment method name */
const METHOD_MAP = {
  'credit card': 'Credit Card',
  'apple pay': 'Apple Pay',
  'cash app': 'Cash App',
  venmo: 'Venmo',
  cash: 'Cash',
  check: 'Check',
  'bank transfer': 'Bank Transfer',
  'referral credit': 'Referral Credit',
  trade: 'Trade',
  other: 'Other',
};

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function normalizeEmail(email) {
  return String(email || '')
    .trim()
    .toLowerCase();
}

function mapPaymentMethod(raw) {
  const key = String(raw || '')
    .trim()
    .toLowerCase();
  return METHOD_MAP[key] || raw;
}

function formatUsd(dollars) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(dollars);
}

function dollarsToCents(amount) {
  return Math.round(Number(amount) * 100);
}

function invoiceNumberLabel(record) {
  return String(record.invoiceNumber ?? '').trim();
}

function craterInvoiceNumber(record) {
  const n = invoiceNumberLabel(record);
  return n.startsWith('INV-') ? n : `INV-${n}`;
}

async function api(method, apiPath, body) {
  const url = `${BASE_URL}/api/v1${apiPath.startsWith('/') ? apiPath : `/${apiPath}`}`;
  const res = await fetch(url, {
    method,
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${TOKEN}`,
      company: COMPANY_ID,
      ...(body != null ? { 'Content-Type': 'application/json' } : {}),
    },
    body: body != null ? JSON.stringify(body) : undefined,
  });

  const text = await res.text();
  let data;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = { raw: text.slice(0, 500) };
  }

  if (!res.ok) {
    const msg =
      data?.message ||
      data?.error ||
      (typeof data?.errors === 'object' ? JSON.stringify(data.errors) : null) ||
      text.slice(0, 300) ||
      `HTTP ${res.status}`;
    const err = new Error(msg);
    err.status = res.status;
    err.body = data;
    throw err;
  }

  return data;
}

async function fetchAllPages(buildPath) {
  const items = [];
  let page = 1;
  let lastPage = 1;

  do {
    const json = await api('GET', buildPath(page));
    const chunk = Array.isArray(json?.data) ? json.data : [];
    items.push(...chunk);
    lastPage = json?.meta?.last_page ?? json?.meta?.pagination?.total_pages ?? page;
    page += 1;
  } while (page <= lastPage);

  return items;
}

async function loadCustomerMap() {
  const customers = await fetchAllPages((page) => `/customers?page=${page}&limit=200`);
  const map = new Map();
  for (const c of customers) {
    const email = normalizeEmail(c.email);
    if (email) map.set(email, c.id);
  }
  return map;
}

async function loadPaymentMethodMap() {
  const methods = await fetchAllPages((page) => `/payment-methods?page=${page}&limit=100`);
  const map = new Map();
  for (const m of methods) {
    if (m?.name) map.set(String(m.name).trim(), m.id);
  }
  return map;
}

function loadResumeLog() {
  if (!fs.existsSync(LOG_PATH)) {
    return { entries: [], okNumbers: new Set() };
  }
  try {
    const entries = JSON.parse(fs.readFileSync(LOG_PATH, 'utf8'));
    if (!Array.isArray(entries)) return { entries: [], okNumbers: new Set() };
    const okNumbers = new Set(
      entries.filter((e) => e.status === 'ok').map((e) => String(e.invoiceNumber)),
    );
    return { entries, okNumbers };
  } catch {
    return { entries: [], okNumbers: new Set() };
  }
}

function saveLog(entries) {
  fs.writeFileSync(LOG_PATH, `${JSON.stringify(entries, null, 2)}\n`, 'utf8');
}

function buildInvoiceBody(record, customerId, amountCents) {
  const invNum = craterInvoiceNumber(record);
  return {
    customer_id: customerId,
    invoice_date: record.paymentDate,
    due_date: record.paymentDate,
    invoice_number: invNum,
    template_name: 'invoice1',
    discount: 0,
    discount_val: 0,
    discount_type: 'fixed',
    sub_total: amountCents,
    total: amountCents,
    tax: 0,
    items: [
      {
        name: 'Services rendered',
        description: `Wix invoice ${invoiceNumberLabel(record)}`,
        price: amountCents,
        quantity: 1,
      },
    ],
  };
}

function buildPaymentBody(record, customerId, invoiceId, amountCents, paymentMethodId) {
  const invKey = invoiceNumberLabel(record).replace(/\D/g, '') || '0';
  return {
    customer_id: customerId,
    invoice_id: invoiceId,
    amount: amountCents,
    payment_date: record.paymentDate,
    payment_method_id: paymentMethodId ?? null,
    payment_number: `WIX-PAY-${invKey.padStart(7, '0')}`,
    notes: record.note ?? null,
  };
}

async function main() {
  if (!BASE_URL) {
    console.error('Missing CRATER_API_BASE_URL in .env');
    process.exit(1);
  }
  if (!TOKEN) {
    console.error('Missing CRATER_API_TOKEN in .env (Sanctum Bearer token for /api/v1).');
    process.exit(1);
  }
  if (!fs.existsSync(PAYMENTS_PATH)) {
    console.error(`Payments file not found: ${PAYMENTS_PATH}`);
    process.exit(1);
  }

  const parsed = JSON.parse(fs.readFileSync(PAYMENTS_PATH, 'utf8'));
  let payments;
  if (Array.isArray(parsed)) {
    payments = parsed;
  } else if (parsed && Array.isArray(parsed.payments)) {
    payments = parsed.payments;
    if (parsed.summary?.payments != null) {
      console.log(
        `File metadata: ${parsed.summary.payments} payments, total ${parsed.summary.total ?? '?'}`,
      );
    }
  } else {
    console.error('Payments JSON must be a top-level array or { "payments": [ … ] }.');
    process.exit(1);
  }

  console.log(`Loading customers and payment methods from ${BASE_URL}…`);
  const [customerByEmail, methodByName] = await Promise.all([
    loadCustomerMap(),
    loadPaymentMethodMap(),
  ]);
  console.log(`Customers with email: ${customerByEmail.size}, payment methods: ${methodByName.size}`);

  const { entries: priorLog, okNumbers } = loadResumeLog();
  const logByNumber = new Map(priorLog.map((e) => [String(e.invoiceNumber), e]));

  let success = 0;
  let errors = 0;
  let processedAmount = 0;
  let skipped = 0;
  const total = payments.length;

  for (let i = 0; i < payments.length; i++) {
    const record = payments[i];
    const invoiceNumber = invoiceNumberLabel(record);

    if (!invoiceNumber) {
      errors += 1;
      const entry = {
        invoiceNumber: '(missing)',
        status: 'error',
        error: 'invoiceNumber is required',
      };
      logByNumber.set('(missing)', entry);
      saveLog([...logByNumber.values()]);
      continue;
    }

    if (okNumbers.has(invoiceNumber)) {
      skipped += 1;
      continue;
    }

    const entry = {
      invoiceNumber,
      status: 'error',
      invoiceId: null,
      paymentId: null,
      error: null,
    };

    try {
      const email = normalizeEmail(record.customerEmail);
      const customerId = customerByEmail.get(email);
      if (!customerId) {
        throw new Error(`No Crater customer for email ${record.customerEmail || '(empty)'}`);
      }

      const amountCents = dollarsToCents(record.amount);
      if (!Number.isFinite(amountCents) || amountCents <= 0) {
        throw new Error(`Invalid amount: ${record.amount}`);
      }

      const methodName = mapPaymentMethod(record.method);
      const paymentMethodId = methodByName.get(methodName);
      if (record.method && paymentMethodId == null) {
        throw new Error(`Unknown payment method "${record.method}" → "${methodName}"`);
      }

      const invoiceRes = await api('POST', '/invoices', buildInvoiceBody(record, customerId, amountCents));
      const invoiceId = invoiceRes?.data?.id ?? invoiceRes?.id;
      if (!invoiceId) {
        throw new Error('Invoice created but response had no id');
      }

      await sleep(DELAY_MS);

      const paymentRes = await api(
        'POST',
        '/payments',
        buildPaymentBody(record, customerId, invoiceId, amountCents, paymentMethodId),
      );
      const paymentId = paymentRes?.data?.id ?? paymentRes?.id;
      if (!paymentId) {
        throw new Error('Payment created but response had no id');
      }

      entry.status = 'ok';
      entry.invoiceId = invoiceId;
      entry.paymentId = paymentId;
      entry.error = null;
      success += 1;
      processedAmount += Number(record.amount) || 0;
      okNumbers.add(invoiceNumber);
    } catch (err) {
      entry.status = 'error';
      entry.error = err.message || String(err);
      errors += 1;
    }

    logByNumber.set(invoiceNumber, entry);
    saveLog(
      [...logByNumber.values()].sort((a, b) =>
        String(a.invoiceNumber).localeCompare(String(b.invoiceNumber), undefined, { numeric: true }),
      ),
    );

    if ((i + 1) % PROGRESS_EVERY === 0) {
      console.log(`Progress: ${i + 1}/${total} (${formatUsd(processedAmount)} processed)`);
    }

    await sleep(DELAY_MS);
  }

  console.log('');
  console.log('✅ Import complete');
  console.log(`Total: ${total}`);
  console.log(`Skipped (already ok): ${skipped}`);
  console.log(`Success: ${success}`);
  console.log(`Errors: ${errors}`);
  console.log(`Total amount: ${formatUsd(processedAmount)}`);
  console.log(`Log: ${LOG_PATH}`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
