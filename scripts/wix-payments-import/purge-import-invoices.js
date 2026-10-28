/**
 * Delete Crater invoices created by a broken Wix import (headers only, no payments).
 *
 *   node purge-import-invoices.js              # INV-* only (default)
 *   node purge-import-invoices.js --all       # every invoice in company (dangerous)
 *   node purge-import-invoices.js --dry-run
 */
import 'dotenv/config';

const BASE_URL = process.env.CRATER_API_BASE_URL?.trim()?.replace(/\/+$/, '');
const TOKEN = process.env.CRATER_API_TOKEN?.trim();
const COMPANY_ID = String(process.env.CRATER_COMPANY_ID || '1').trim();
const BATCH = 40;

const dryRun = process.argv.includes('--dry-run');
const deleteAll = process.argv.includes('--all');

async function api(method, apiPath, body) {
  const url = `${BASE_URL}/api/v1${apiPath}`;
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
    data = { raw: text.slice(0, 400) };
  }
  if (!res.ok) {
    throw new Error(data?.message || data?.error || text.slice(0, 300) || `HTTP ${res.status}`);
  }
  return data;
}

function shouldDelete(inv) {
  if (deleteAll) return true;
  const num = String(inv.invoice_number || '');
  return /^INV-\d/.test(num);
}

/** Fetch one page (always page 1 when deleting — list shrinks). */
async function fetchInvoicePage(page = 1) {
  const json = await api('GET', `/invoices?page=${page}&limit=${BATCH}`);
  return Array.isArray(json?.data) ? json.data : [];
}

async function deleteIds(ids) {
  if (!ids.length) return;
  try {
    await api('POST', '/invoices/delete', { ids });
  } catch {
    for (const id of ids) {
      try {
        await api('POST', '/invoices/delete', { ids: [id] });
      } catch (e) {
        console.error(`  skip id ${id}: ${e.message}`);
      }
    }
  }
}

async function purgeLoop() {
  let deleted = 0;
  let passes = 0;

  while (true) {
    passes += 1;
    const page = await fetchInvoicePage(1);
    const targets = page.filter(shouldDelete);
    if (!targets.length) {
      if (page.length && !deleteAll) {
        console.log('No more matching INV-* on first page; stopping.');
        break;
      }
      if (!page.length) break;
    }

    const ids = (deleteAll ? page : targets).map((inv) => inv.id);
    if (dryRun) {
      console.log(`Dry run — would delete ${ids.length}:`, page.slice(0, 5).map((i) => i.invoice_number));
      return deleted;
    }

    await deleteIds(ids);
    deleted += ids.length;
    console.log(`Deleted ${deleted} so far (pass ${passes})…`);

    if (passes > 5000) {
      console.error('Safety stop: too many passes.');
      break;
    }
  }

  return deleted;
}

async function main() {
  if (!BASE_URL || !TOKEN) {
    console.error('Set CRATER_API_BASE_URL and CRATER_API_TOKEN in .env');
    process.exit(1);
  }

  console.log(
    deleteAll ? 'Deleting ALL invoices (page-by-page)…' : 'Deleting INV-* invoices (page-by-page)…',
  );

  const deleted = await purgeLoop();
  console.log(`Done. Deleted: ${deleted}.`);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
