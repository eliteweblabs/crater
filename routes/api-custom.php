<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Crater\Models\Company;
use Crater\Models\CompanySetting;
use Crater\Models\Customer;
use Crater\Models\Expense;
use Crater\Models\ExpenseCategory;
use Crater\Models\Estimate;
use Crater\Models\Invoice;
use Crater\Models\InvoiceItem;
use Crater\Models\Payment;
use Crater\Models\PaymentMethod;
use Crater\Models\RecurringInvoice;
use Crater\Services\SerialNumberFormatter;
use Vinkla\Hashids\Facades\Hashids;
// Simple invoice creation endpoint for custom API
// POST /api/custom/create-invoice
Route::post('/custom/create-invoice', function (Illuminate\Http\Request $request) {
    // Simple auth token check
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $validated = $request->validate([
        'customer_name' => 'required|string',
        'customer_email' => 'nullable|email',
        'items' => 'required|array',
        'items.*.name' => 'required|string',
        'items.*.description' => 'nullable|string',
        'items.*.quantity' => 'required|numeric|min:0',
        'items.*.price' => 'required|numeric|min:0',
        'notes' => 'nullable|string',
        'status' => 'nullable|in:DRAFT,SENT,VIEWED,OVERDUE,COMPLETED',
    ]);

    $companyId = 1;
    $uniqueHash = \Illuminate\Support\Str::random(32);

    // Resolve the company's configured currency so invoices are consistent
    // with the admin UI (prevents the blank totals we saw before).
    $companyCurrencyId = (int) (CompanySetting::getSetting('currency', $companyId) ?? 1);
    $taxPerItem = CompanySetting::getSetting('tax_per_item', $companyId) ?? 'NO';
    $discountPerItem = CompanySetting::getSetting('discount_per_item', $companyId) ?? 'NO';

    // Find or create customer — inherit the company's currency so exchange_rate = 1
    $customer = Customer::where('name', $validated['customer_name'])
        ->where('company_id', $companyId)
        ->first();
    if (!$customer) {
        $customer = Customer::create([
            'name' => $validated['customer_name'],
            'email' => $validated['customer_email'] ?? null,
            'company_id' => $companyId,
            'contact_name' => $validated['customer_name'],
            'currency_id' => $companyCurrencyId,
        ]);
    } elseif (!$customer->currency_id) {
        $customer->currency_id = $companyCurrencyId;
        $customer->save();
    }

    // Telegram / Reave integrations send prices in whole-dollar units; Crater stores
    // amounts as integer cents. Normalize once here and use everywhere below.
    $subTotal = 0;
    foreach ($validated['items'] as $item) {
        $subTotal += ((int) round($item['price'] * 100)) * $item['quantity'];
    }
    $subTotal = (int) round($subTotal);

    // If customer currency differs from company currency, exchange_rate should
    // come from the caller — we don't have that info in custom API payloads, so
    // we assume 1 (they send amounts in the customer's currency).
    $exchangeRate = 1;
    $currencyId = (int) ($customer->currency_id ?: $companyCurrencyId);

    // Atomically compute the next invoice number and persist the invoice.
    // We lock existing rows for this company so two concurrent requests
    // (e.g. Telegram + API) can't both read the same MAX() and collide.
    //
    // We also compute the "next sequence" as the max of:
    //   - MAX(sequence_number) — the canonical field
    //   - the highest trailing integer parsed out of invoice_number
    // …because older custom API-created invoices were saved with a NULL
    // sequence_number, which caused setNextSequenceNumber() to keep
    // handing out the same value.
    $created = DB::transaction(function () use (
        $validated, $customer, $subTotal, $uniqueHash, $companyId,
        $currencyId, $exchangeRate, $taxPerItem, $discountPerItem
    ) {
        $rows = DB::table('invoices')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->get(['sequence_number', 'invoice_number']);

        $maxSeq = 0;
        foreach ($rows as $row) {
            if ($row->sequence_number !== null && (int) $row->sequence_number > $maxSeq) {
                $maxSeq = (int) $row->sequence_number;
            }
            if ($row->invoice_number && preg_match('/(\d+)$/', $row->invoice_number, $m)) {
                $parsed = (int) $m[1];
                if ($parsed > $maxSeq) {
                    $maxSeq = $parsed;
                }
            }
        }
        $nextSeq = $maxSeq + 1;

        $maxCustSeq = (int) (DB::table('invoices')
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->lockForUpdate()
            ->max('customer_sequence_number') ?? 0);
        $nextCustSeq = $maxCustSeq + 1;

        $invoiceModel = new Invoice();
        $serial = (new SerialNumberFormatter())
            ->setModel($invoiceModel)
            ->setCompany($companyId);
        $serial->nextSequenceNumber = $nextSeq;
        $serial->nextCustomerSequenceNumber = $nextCustSeq;
        $invoiceNumber = $serial->getNextNumber();

        if (Invoice::where('company_id', $companyId)->where('invoice_number', $invoiceNumber)->exists()) {
            throw new \RuntimeException("Computed duplicate invoice_number {$invoiceNumber}; retrying.");
        }

        // Use the first admin user as the creator so the admin UI shows
        // a valid creator instead of "Unknown".
        $creatorId = (int) (DB::table('users')->orderBy('id')->value('id') ?? 1);

        $invoice = Invoice::create([
            'invoice_date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'invoice_number' => $invoiceNumber,
            'sequence_number' => $nextSeq,
            'customer_sequence_number' => $nextCustSeq,
            'customer_id' => $customer->id,
            'company_id' => $companyId,
            'creator_id' => $creatorId,
            'currency_id' => $currencyId,
            'exchange_rate' => $exchangeRate,
            'sub_total' => $subTotal,
            'total' => $subTotal,
            'due_amount' => $subTotal,
            'base_sub_total' => $subTotal * $exchangeRate,
            'base_total' => $subTotal * $exchangeRate,
            'base_due_amount' => $subTotal * $exchangeRate,
            'tax' => 0,
            'base_tax' => 0,
            'discount' => 0,
            'discount_type' => 'fixed',
            'discount_val' => 0,
            'base_discount_val' => 0,
            'tax_per_item' => $taxPerItem,
            'discount_per_item' => $discountPerItem,
            'paid_status' => Invoice::STATUS_UNPAID,
            'notes' => $validated['notes'] ?? '',
            // Default to DRAFT so invoices created via Telegram aren't
            // silently marked as "sent to the client." Callers can pass
            // status=SENT explicitly when they actually emailed it out.
            'status' => $validated['status'] ?? Invoice::STATUS_DRAFT,
            'template_name' => 'invoice1',
            'unique_hash' => $uniqueHash,
        ]);

        foreach ($validated['items'] as $itemData) {
            $price = (int) round($itemData['price'] * 100);
            $total = (int) round($price * $itemData['quantity']);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'name' => $itemData['name'],
                'description' => $itemData['description'] ?? '',
                'quantity' => $itemData['quantity'],
                'price' => $price,
                'total' => $total,
                'base_price' => $price * $exchangeRate,
                'base_total' => $total * $exchangeRate,
                'base_discount_val' => 0,
                'base_tax' => 0,
                'discount' => 0,
                'discount_type' => 'fixed',
                'discount_val' => 0,
                'tax' => 0,
                'exchange_rate' => $exchangeRate,
                'currency_id' => $currencyId,
                'company_id' => $companyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $invoice;
    });

    return response()->json([
        'success' => true,
        'invoice_id' => $created->id,
        'invoice_number' => $created->invoice_number,
        'customer' => $customer->name,
        'total' => $subTotal / 100,
        'admin_url' => url("/admin/invoices/{$created->id}/view"),
        'sms_link' => url("/invoices/{$uniqueHash}"),
        'public_url' => url("/invoices/{$uniqueHash}"),
        'pdf_url' => url("/invoices/pdf/{$uniqueHash}"),
        'payment_url' => url("/invoices/{$uniqueHash}/pay"),
    ]);
});

// Update invoice endpoint for custom API
// PUT /api/custom/invoice/{id}
Route::put('/custom/invoice/{id}', function (Illuminate\Http\Request $request, $id) {
    // Auth token check
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $invoice = Invoice::findOrFail($id);

    $validated = $request->validate([
        'status' => 'nullable|in:DRAFT,SENT,VIEWED,OVERDUE,COMPLETED',
        'due_date' => 'nullable|date',
        'notes' => 'nullable|string',
    ]);

    if (isset($validated['status'])) {
        $invoice->status = $validated['status'];
    }
    if (isset($validated['due_date'])) {
        $invoice->due_date = $validated['due_date'];
    }
    if (isset($validated['notes'])) {
        $invoice->notes = $validated['notes'];
    }
    $invoice->save();

    return response()->json([
        'success' => true,
        'invoice_id' => $invoice->id,
        'status' => $invoice->status,
    ]);
});

// Delete invoice endpoint for custom API
// DELETE /api/custom/invoice/{id}
Route::delete('/custom/invoice/{id}', function (Illuminate\Http\Request $request, $id) {
    // Auth token check
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $invoice = Invoice::findOrFail($id);
    $invoice->delete();

    return response()->json([
        'success' => true,
        'invoice_id' => $id,
        'deleted' => true,
    ]);
});

// List all invoices (auth required) — used for cleanup / visibility
// GET /api/custom/invoices?company_id=1
Route::get('/custom/invoices', function (Illuminate\Http\Request $request) {
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $companyId = (int) ($request->input('company_id') ?? 1);

    $invoices = Invoice::where('company_id', $companyId)
        ->with('customer:id,name')
        ->orderByDesc('invoice_date')
        ->orderByDesc('id')
        ->get([
            'id', 'invoice_number', 'sequence_number', 'customer_id',
            'invoice_date', 'status', 'paid_status',
            'total', 'due_amount', 'unique_hash',
        ])
        ->map(function ($inv) {
            return [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'sequence_number' => $inv->sequence_number,
                'invoice_date' => optional($inv->invoice_date)->format('Y-m-d'),
                'customer_id' => $inv->customer_id,
                'customer_name' => $inv->customer->name ?? null,
                'status' => $inv->status,
                'paid_status' => $inv->paid_status,
                'total_cents' => (int) $inv->total,
                'total' => round(((int) $inv->total) / 100, 2),
                'due_cents' => (int) $inv->due_amount,
                'due' => round(((int) $inv->due_amount) / 100, 2),
                'public_url' => $inv->unique_hash ? url('/invoices/'.$inv->unique_hash) : null,
            ];
        });

    return response()->json([
        'company_id' => $companyId,
        'count' => $invoices->count(),
        'invoices' => $invoices,
    ]);
});

// List payments (auth required) — client portal / billing history
// GET /api/custom/payments?company_id=1&customer_id=5
// GET /api/custom/payments?company_id=1&q=customer-name-or-email
Route::get('/custom/payments', function (Illuminate\Http\Request $request) {
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $companyId = (int) ($request->input('company_id') ?? 1);
    $customerId = $request->input('customer_id');
    $q = trim((string) $request->input('q', ''));

    $paymentModeLabel = function (?string $type, ?string $name): string {
        $labels = [
            'CASH' => 'Cash',
            'CHECK' => 'Check',
            'CREDIT_CARD' => 'Credit Card',
            'BANK_TRANSFER' => 'Bank Transfer',
            'OTHER' => 'Other',
        ];
        if ($type && isset($labels[$type])) {
            return $labels[$type];
        }
        if ($name && trim($name) !== '') {
            return trim($name);
        }
        return 'Other';
    };

    $query = Payment::where('company_id', $companyId)
        ->with([
            'customer:id,name',
            'invoice:id,invoice_number',
            'paymentMethod:id,name,type',
        ])
        ->orderByDesc('payment_date')
        ->orderByDesc('id');

    if ($customerId !== null && $customerId !== '') {
        $query->where('customer_id', (int) $customerId);
    } elseif ($q !== '') {
        $query->whereHas('customer', function ($w) use ($q) {
            $w->where('name', 'LIKE', "%{$q}%")
              ->orWhere('contact_name', 'LIKE', "%{$q}%")
              ->orWhere('email', 'LIKE', "%{$q}%")
              ->orWhere('phone', 'LIKE', "%{$q}%");
        });
    }

    $payments = $query->get([
        'id', 'payment_number', 'payment_date', 'amount',
        'customer_id', 'invoice_id', 'payment_method_id',
    ])->map(function ($pay) use ($paymentModeLabel) {
        $method = $pay->paymentMethod;

        return [
            'id' => $pay->id,
            'payment_number' => $pay->payment_number,
            'payment_date' => optional($pay->payment_date)->format('Y-m-d'),
            'amount_cents' => (int) $pay->amount,
            'amount' => round(((int) $pay->amount) / 100, 2),
            'customer_id' => $pay->customer_id,
            'customer_name' => $pay->customer->name ?? null,
            'invoice_id' => $pay->invoice_id,
            'invoice_number' => $pay->invoice->invoice_number ?? null,
            'payment_mode' => $method->type ?? null,
            'payment_method' => $paymentModeLabel(
                $method->type ?? null,
                $method->name ?? null
            ),
        ];
    });

    return response()->json([
        'company_id' => $companyId,
        'count' => $payments->count(),
        'payments' => $payments,
    ]);
});

// List all estimates (auth required) — billing hints / duplicate detection
// GET /api/custom/estimates?company_id=1
Route::get('/custom/estimates', function (Illuminate\Http\Request $request) {
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $companyId = (int) ($request->input('company_id') ?? 1);

    $estimates = Estimate::where('company_id', $companyId)
        ->with('customer:id,name')
        ->orderByDesc('estimate_date')
        ->orderByDesc('id')
        ->get([
            'id', 'estimate_number', 'sequence_number', 'customer_id',
            'estimate_date', 'expiry_date', 'status',
            'total', 'unique_hash',
        ])
        ->map(function ($est) {
            return [
                'id' => $est->id,
                'estimate_number' => $est->estimate_number,
                'sequence_number' => $est->sequence_number,
                'estimate_date' => optional($est->estimate_date)->format('Y-m-d'),
                'expiry_date' => optional($est->expiry_date)->format('Y-m-d'),
                'customer_id' => $est->customer_id,
                'customer_name' => $est->customer->name ?? null,
                'status' => $est->status,
                'total_cents' => (int) $est->total,
                'total' => round(((int) $est->total) / 100, 2),
                'public_url' => $est->unique_hash ? url('/estimates/pdf/'.$est->unique_hash) : null,
            ];
        });

    return response()->json([
        'company_id' => $companyId,
        'count' => $estimates->count(),
        'estimates' => $estimates,
    ]);
});

// Export customers with full profile data.
// GET /api/custom/customers?company_id=1&q=optional-search
Route::get('/custom/customers', function (Illuminate\Http\Request $request) {
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $companyId = (int) ($request->input('company_id') ?? 1);
    $q = trim((string) $request->input('q', ''));

    $query = Customer::with(['billingAddress.country', 'shippingAddress.country', 'currency'])
        ->where('company_id', $companyId)
        ->orderBy('name');

    if ($q !== '') {
        $query->where(function ($w) use ($q) {
            $w->where('name', 'LIKE', "%{$q}%")
              ->orWhere('contact_name', 'LIKE', "%{$q}%")
              ->orWhere('email', 'LIKE', "%{$q}%")
              ->orWhere('phone', 'LIKE', "%{$q}%");
        });
    }

    $customers = $query->get()->map(function ($c) {
        // Invoice + estimate summaries — aggregated in PHP to avoid N+1 per field
        $invoices     = DB::table('invoices')->where('customer_id', $c->id)->get(['total', 'due_amount', 'paid_status']);
        $estimates    = DB::table('estimates')->where('customer_id', $c->id)->get(['total', 'status']);
        $totalBilled  = $invoices->sum('total');
        $totalDue     = $invoices->sum('due_amount');
        $openEstimates = $estimates->whereIn('status', ['DRAFT', 'SENT', 'VIEWED'])->count();
        $acceptedEstimates = $estimates->where('status', 'ACCEPTED')->count();

        $formatAddr = function ($addr) {
            if (!$addr) return null;
            return [
                'name'             => $addr->name,
                'address_street_1' => $addr->address_street_1,
                'address_street_2' => $addr->address_street_2,
                'city'             => $addr->city,
                'state'            => $addr->state,
                'zip'              => $addr->zip,
                'country'          => $addr->country ? $addr->country->name : null,
                'country_code'     => $addr->country ? $addr->country->code : null,
                'phone'            => $addr->phone,
                'fax'              => $addr->fax,
            ];
        };

        return [
            'id'             => $c->id,
            'name'           => $c->name,
            'contact_name'   => $c->contact_name,
            'company_name'   => $c->company_name,
            'email'          => $c->email,
            'phone'          => $c->phone,
            'website'        => $c->website,
            'enable_portal'  => (bool) $c->enable_portal,
            'currency'       => $c->currency ? [
                'code'   => $c->currency->code,
                'name'   => $c->currency->name,
                'symbol' => $c->currency->symbol,
            ] : null,
            'billing_address'  => $formatAddr($c->billingAddress),
            'shipping_address' => $formatAddr($c->shippingAddress),
            'invoice_summary'  => [
                'count'        => $invoices->count(),
                'total_billed' => round($totalBilled / 100, 2),
                'total_paid'   => round(($totalBilled - $totalDue) / 100, 2),
                'total_due'    => round($totalDue / 100, 2),
            ],
            'estimate_summary' => [
                'count'    => $estimates->count(),
                'open'     => $openEstimates,
                'accepted' => $acceptedEstimates,
            ],
            'created_at'  => optional($c->created_at)->format('Y-m-d'),
            'admin_url'   => url("/admin/customers/{$c->id}/view"),
        ];
    });

    return response()->json([
        'company_id' => $companyId,
        'count'      => $customers->count(),
        'customers'  => $customers,
    ]);
});

// Update customer profile (sync from REΛVE contact edits).
// PUT /api/custom/customer/{id}
Route::put('/custom/customer/{id}', function (Illuminate\Http\Request $request, $id) {
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $customer = Customer::findOrFail($id);

    $validated = $request->validate([
        'name' => 'nullable|string',
        'contact_name' => 'nullable|string',
        'email' => 'nullable|string',
        'phone' => 'nullable|string',
    ]);

    if (array_key_exists('name', $validated) && $validated['name'] !== null && $validated['name'] !== '') {
        $customer->name = $validated['name'];
    }
    if (array_key_exists('contact_name', $validated)) {
        $customer->contact_name = $validated['contact_name'] ?: $customer->name;
    }
    if (array_key_exists('email', $validated)) {
        $customer->email = $validated['email'] ?: null;
    }
    if (array_key_exists('phone', $validated)) {
        $customer->phone = $validated['phone'] ?: null;
    }
    $customer->save();

    return response()->json([
        'success' => true,
        'customer_id' => $customer->id,
        'name' => $customer->name,
        'contact_name' => $customer->contact_name,
        'email' => $customer->email,
        'phone' => $customer->phone,
    ]);
});

// Fetch a single invoice (with items) by id.
// GET /api/custom/invoice/{id}
Route::get('/custom/invoice/{id}', function (Illuminate\Http\Request $request, $id) {
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $invoice = Invoice::with(['items', 'customer:id,name,email,phone'])->find($id);
    if (!$invoice) {
        return response()->json(['error' => 'Invoice not found'], 404);
    }

    $hash = $invoice->unique_hash;

    return response()->json([
        'id' => $invoice->id,
        'invoice_number' => $invoice->invoice_number,
        'sequence_number' => $invoice->sequence_number,
        'invoice_date' => optional($invoice->invoice_date)->format('Y-m-d'),
        'due_date' => optional($invoice->due_date)->format('Y-m-d'),
        'status' => $invoice->status,
        'paid_status' => $invoice->paid_status,
        'currency_id' => $invoice->currency_id,
        'sub_total_cents' => (int) $invoice->sub_total,
        'total_cents' => (int) $invoice->total,
        'due_cents' => (int) $invoice->due_amount,
        'sub_total' => round(((int) $invoice->sub_total) / 100, 2),
        'total' => round(((int) $invoice->total) / 100, 2),
        'due' => round(((int) $invoice->due_amount) / 100, 2),
        'notes' => $invoice->notes,
        'customer' => $invoice->customer ? [
            'id' => $invoice->customer->id,
            'name' => $invoice->customer->name,
            'email' => $invoice->customer->email,
            'phone' => $invoice->customer->phone,
        ] : null,
        'items' => $invoice->items->map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'price_cents' => (int) $item->price,
                'price' => round(((int) $item->price) / 100, 2),
                'total_cents' => (int) $item->total,
                'total' => round(((int) $item->total) / 100, 2),
            ];
        })->values(),
        'admin_url' => url("/admin/invoices/{$invoice->id}/view"),
        'public_url' => $hash ? url("/invoices/{$hash}") : null,
        'pdf_url' => $hash ? url("/invoices/pdf/{$hash}") : null,
        'payment_url' => $hash ? url("/invoices/{$hash}/pay") : null,
    ]);
});

// Nuke-and-pave reset: wipes all invoices, items, taxes, payments,
// transactions, and recurring invoices for a company. Preserves users,
// customers, companies, and settings. Safe ONLY because no real payments
// have been collected yet. Requires explicit confirmation in the body.
//
// POST /api/custom/reset-invoices
// Body: { "company_id": 1, "confirm": "YES_DELETE_EVERYTHING", "dry_run": true }
Route::post('/custom/reset-invoices', function (Illuminate\Http\Request $request) {
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    if ($request->input('confirm') !== 'YES_DELETE_EVERYTHING') {
        return response()->json([
            'error' => 'Refused: missing confirm=YES_DELETE_EVERYTHING in body',
        ], 400);
    }

    $companyId = (int) ($request->input('company_id') ?? 1);
    $dryRun = filter_var($request->input('dry_run', false), FILTER_VALIDATE_BOOLEAN);

    $report = DB::transaction(function () use ($companyId, $dryRun) {
        $counts = [
            'invoices' => DB::table('invoices')->where('company_id', $companyId)->count(),
            'invoice_items' => DB::table('invoice_items')->where('company_id', $companyId)->count(),
            'payments' => DB::table('payments')->where('company_id', $companyId)->count(),
            'transactions' => DB::table('transactions')->where('company_id', $companyId)->count(),
            'recurring_invoices' => DB::table('recurring_invoices')->where('company_id', $companyId)->count(),
            'estimates' => DB::table('estimates')->where('company_id', $companyId)->count(),
            'estimate_items' => DB::table('estimate_items')->where('company_id', $companyId)->count(),
        ];

        if (! $dryRun) {
            // Order matters: delete children first.
            DB::table('taxes')
                ->whereIn('invoice_id', function ($q) use ($companyId) {
                    $q->select('id')->from('invoices')->where('company_id', $companyId);
                })->delete();
            DB::table('taxes')
                ->whereIn('estimate_id', function ($q) use ($companyId) {
                    $q->select('id')->from('estimates')->where('company_id', $companyId);
                })->delete();
            DB::table('invoice_items')->where('company_id', $companyId)->delete();
            DB::table('estimate_items')->where('company_id', $companyId)->delete();
            DB::table('transactions')->where('company_id', $companyId)->delete();
            DB::table('payments')->where('company_id', $companyId)->delete();
            DB::table('recurring_invoices')->where('company_id', $companyId)->delete();
            DB::table('invoices')->where('company_id', $companyId)->delete();
            DB::table('estimates')->where('company_id', $companyId)->delete();
        }

        return [
            'company_id' => $companyId,
            'dry_run' => $dryRun,
            'deleted_counts' => $counts,
        ];
    });

    return response()->json(['success' => true] + $report);
});

// One-shot repair endpoint for invoices created by older custom API/Telegram
// runs. Heals the following known issues caused by the previous endpoint's
// missing fields:
//   - NULL sequence_number / customer_sequence_number (root cause of duplicate
//     invoice_number values — e.g. two "INV-000013" entries).
//   - Colliding invoice_numbers (renumbered to MAX+1).
//   - due_amount == 0 on UNPAID invoices where total > 0 (shows "$0.00" in the
//     admin "Amount Due" column).
//   - NULL / 0 base_total, base_sub_total, base_due_amount, base_tax,
//     base_discount_val, exchange_rate, currency_id, creator_id, paid_status.
//   - Invoice items with 0 base_price / base_total / exchange_rate /
//     currency_id (which prevent the admin item totals from rendering).
//
// POST /api/custom/repair-invoice-numbers
// Body: { "company_id": 1, "dry_run": true, "only": "numbers|totals|all" }
Route::post('/custom/repair-invoice-numbers', function (Illuminate\Http\Request $request) {
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $companyId = (int) ($request->input('company_id') ?? 1);
    $dryRun = filter_var($request->input('dry_run', false), FILTER_VALIDATE_BOOLEAN);
    $only = $request->input('only', 'all');

    $report = DB::transaction(function () use ($companyId, $dryRun, $only) {
        $companyCurrencyId = (int) (CompanySetting::getSetting('currency', $companyId) ?? 1);
        $defaultCreatorId = (int) (DB::table('users')->orderBy('id')->value('id') ?? 1);

        $invoices = Invoice::where('company_id', $companyId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // ---- Pass 1: compute max sequence number from all sources ----
        $maxSeq = 0;
        foreach ($invoices as $inv) {
            if ($inv->sequence_number !== null && (int) $inv->sequence_number > $maxSeq) {
                $maxSeq = (int) $inv->sequence_number;
            }
            if ($inv->invoice_number && preg_match('/(\d+)$/', $inv->invoice_number, $m)) {
                $parsed = (int) $m[1];
                if ($parsed > $maxSeq) {
                    $maxSeq = $parsed;
                }
            }
        }

        $seenInvoiceNumbers = [];
        $customerCounters = [];
        $fixed = [];
        $itemFixedCount = 0;

        foreach ($invoices as $inv) {
            $change = [];

            // ---- Number/sequence repairs ----
            if ($only === 'all' || $only === 'numbers') {
                if (! empty($inv->invoice_number) && isset($seenInvoiceNumbers[$inv->invoice_number])) {
                    $maxSeq += 1;
                    $newInvoiceNumber = 'INV-'.str_pad($maxSeq, 6, '0', STR_PAD_LEFT);
                    $change['old_invoice_number'] = $inv->invoice_number;
                    $change['new_invoice_number'] = $newInvoiceNumber;
                    $inv->invoice_number = $newInvoiceNumber;
                    $inv->sequence_number = $maxSeq;
                } elseif ($inv->sequence_number === null) {
                    if ($inv->invoice_number && preg_match('/(\d+)$/', $inv->invoice_number, $m)) {
                        $inv->sequence_number = (int) $m[1];
                    } else {
                        $maxSeq += 1;
                        $inv->sequence_number = $maxSeq;
                    }
                    $change['new_sequence_number'] = $inv->sequence_number;
                }

                if ($inv->customer_sequence_number === null) {
                    $cid = (int) $inv->customer_id;
                    if (! isset($customerCounters[$cid])) {
                        $customerCounters[$cid] = (int) (Invoice::where('company_id', $companyId)
                            ->where('customer_id', $cid)
                            ->max('customer_sequence_number') ?? 0);
                    }
                    $customerCounters[$cid] += 1;
                    $inv->customer_sequence_number = $customerCounters[$cid];
                    $change['new_customer_sequence_number'] = $inv->customer_sequence_number;
                }
            }

            // ---- Totals / currency / base_* repairs ----
            if ($only === 'all' || $only === 'totals') {
                $exchangeRate = (float) ($inv->exchange_rate ?: 1);
                if ($exchangeRate <= 0) {
                    $exchangeRate = 1.0;
                    $inv->exchange_rate = 1;
                    $change['new_exchange_rate'] = 1;
                }

                if (empty($inv->currency_id)) {
                    $inv->currency_id = $companyCurrencyId;
                    $change['new_currency_id'] = $companyCurrencyId;
                }

                if (empty($inv->creator_id)) {
                    $inv->creator_id = $defaultCreatorId;
                    $change['new_creator_id'] = $defaultCreatorId;
                }

                // paid_status must be one of UNPAID/PARTIALLY_PAID/PAID.
                // If due_amount is 0 OR equals total, and there are no payments,
                // derive from total vs. paid to avoid mislabeling.
                if (empty($inv->paid_status)) {
                    $inv->paid_status = Invoice::STATUS_UNPAID;
                    $change['new_paid_status'] = Invoice::STATUS_UNPAID;
                }

                // Repair due_amount when it looks corrupt. Safe rule: if the
                // invoice is marked UNPAID AND no payment records exist for
                // it, due_amount MUST equal total. This catches both the
                // "due = 0" case (Levines, Sullivan) and the "due < total"
                // case caused by the duplicate-ID bug (Paradigm Landscape
                // showing $110 due / $150 total).
                if ((int) $inv->total > 0
                    && $inv->paid_status === Invoice::STATUS_UNPAID
                    && (int) $inv->due_amount !== (int) $inv->total
                ) {
                    $hasPayments = DB::table('payments')
                        ->where('invoice_id', $inv->id)
                        ->exists();
                    if (! $hasPayments) {
                        $change['old_due_amount'] = (int) $inv->due_amount;
                        $inv->due_amount = $inv->total;
                        $change['new_due_amount'] = (int) $inv->due_amount;
                    }
                }

                // Backfill base_* fields using exchange_rate
                $baseMap = [
                    'base_total' => (int) $inv->total * $exchangeRate,
                    'base_sub_total' => (int) $inv->sub_total * $exchangeRate,
                    'base_due_amount' => (int) $inv->due_amount * $exchangeRate,
                    'base_tax' => (int) $inv->tax * $exchangeRate,
                    'base_discount_val' => (int) $inv->discount_val * $exchangeRate,
                ];
                foreach ($baseMap as $field => $shouldBe) {
                    if ((int) $inv->{$field} === 0 && (int) $shouldBe !== 0) {
                        $inv->{$field} = $shouldBe;
                        $change['new_'.$field] = $shouldBe;
                    }
                }

                // ---- Item repairs: base_price, base_total, exchange_rate, currency_id ----
                $items = $inv->items()->get();
                foreach ($items as $item) {
                    $itemChange = [];
                    $itemRate = (float) ($item->exchange_rate ?: $exchangeRate);
                    if ($itemRate <= 0) {
                        $itemRate = 1.0;
                    }
                    if ((float) ($item->exchange_rate ?? 0) <= 0) {
                        $item->exchange_rate = $itemRate;
                        $itemChange['exchange_rate'] = $itemRate;
                    }
                    if (empty($item->currency_id)) {
                        $item->currency_id = $inv->currency_id;
                        $itemChange['currency_id'] = $inv->currency_id;
                    }
                    if ((int) $item->base_price === 0 && (int) $item->price !== 0) {
                        $item->base_price = (int) $item->price * $itemRate;
                        $itemChange['base_price'] = $item->base_price;
                    }
                    if ((int) $item->base_total === 0 && (int) $item->total !== 0) {
                        $item->base_total = (int) $item->total * $itemRate;
                        $itemChange['base_total'] = $item->base_total;
                    }
                    if ((int) $item->base_discount_val === 0 && (int) $item->discount_val !== 0) {
                        $item->base_discount_val = (int) $item->discount_val * $itemRate;
                        $itemChange['base_discount_val'] = $item->base_discount_val;
                    }
                    if ((int) $item->base_tax === 0 && (int) $item->tax !== 0) {
                        $item->base_tax = (int) $item->tax * $itemRate;
                        $itemChange['base_tax'] = $item->base_tax;
                    }
                    if (empty($item->discount_type)) {
                        $item->discount_type = 'fixed';
                        $itemChange['discount_type'] = 'fixed';
                    }
                    if (! empty($itemChange)) {
                        $itemFixedCount += 1;
                        if (! $dryRun) {
                            $item->save();
                        }
                    }
                }
            }

            if (! empty($change)) {
                $change['id'] = $inv->id;
                $change['invoice_number'] = $inv->invoice_number;
                $fixed[] = $change;
                if (! $dryRun) {
                    $inv->save();
                }
            }

            $seenInvoiceNumbers[$inv->invoice_number] = true;
        }

        return [
            'company_id' => $companyId,
            'dry_run' => $dryRun,
            'scope' => $only,
            'total_invoices' => $invoices->count(),
            'fixed_invoice_count' => count($fixed),
            'fixed_item_count' => $itemFixedCount,
            'max_sequence_number' => $maxSeq,
            'changes' => $fixed,
        ];
    });

    return response()->json(['success' => true] + $report);
});

// Record an offline payment for custom API
// POST /api/custom/record-payment
//
// Flow:
//   1. Fuzzy-search customer by name (same LIKE matching used everywhere).
//      • No match  → create customer + auto-draft invoice, then record payment.
//      • 2+ matches → return needs_selection:customer so caller can be more specific.
//   2. Resolve invoice (skip if invoice_id supplied directly).
//      • 0 open invoices → create a DRAFT invoice for the amount, then record payment.
//      • 1 open invoice  → apply payment to it.
//      • 2+ open invoices → return needs_selection:invoice with the list; caller
//                           re-sends with invoice_id chosen.
Route::post('/custom/record-payment', function (Illuminate\Http\Request $request) {
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $validated = $request->validate([
        'customer_name' => 'required|string',
        'amount'        => 'required|numeric|min:0.01',
        'payment_mode'  => 'nullable|in:CASH,CHECK,CREDIT_CARD,BANK_TRANSFER,OTHER',
        'payment_date'  => 'nullable|date',
        'notes'         => 'nullable|string',
        'invoice_id'    => 'nullable|integer',
    ]);

    $companyId   = 1;
    $amountCents = (int) round($validated['amount'] * 100);
    $paymentDate = $validated['payment_date'] ?? now()->format('Y-m-d');
    $q           = trim($validated['customer_name']);

    // If payment_mode was not supplied, ask before proceeding
    if (empty($validated['payment_mode'])) {
        return response()->json([
            'needs_selection' => true,
            'selection_type'  => 'payment_mode',
            'message'         => 'How was this payment received? Re-send with payment_mode set to one of the options.',
            'options'         => [
                ['value' => 'CASH',          'label' => 'Cash'],
                ['value' => 'CHECK',         'label' => 'Check'],
                ['value' => 'CREDIT_CARD',   'label' => 'Credit Card'],
                ['value' => 'BANK_TRANSFER', 'label' => 'Bank Transfer'],
                ['value' => 'OTHER',         'label' => 'Other'],
            ],
        ], 300);
    }

    $paymentMode = $validated['payment_mode'];

    // ── 1. Resolve customer ───────────────────────────────────────────────────
    $customers = Customer::where('company_id', $companyId)
        ->where(function ($w) use ($q) {
            $w->where('name', 'LIKE', "%{$q}%")
              ->orWhere('contact_name', 'LIKE', "%{$q}%")
              ->orWhere('email', 'LIKE', "%{$q}%")
              ->orWhere('phone', 'LIKE', "%{$q}%");
        })
        ->get();

    $customerCreated = false;
    if ($customers->count() === 0) {
        $companyCurrencyId = (int) (CompanySetting::getSetting('currency', $companyId) ?? 1);
        $customer = Customer::create([
            'name'         => $q,
            'contact_name' => $q,
            'company_id'   => $companyId,
            'currency_id'  => $companyCurrencyId,
        ]);
        $customerCreated = true;
    } elseif ($customers->count() === 1) {
        $customer = $customers->first();
    } else {
        return response()->json([
            'needs_selection' => true,
            'selection_type'  => 'customer',
            'message'         => "Multiple customers matched \"{$q}\". Re-send with a more specific customer_name.",
            'customers'       => $customers->map(fn ($c) => [
                'id'           => $c->id,
                'name'         => $c->name,
                'contact_name' => $c->contact_name,
                'email'        => $c->email,
                'phone'        => $c->phone,
                'admin_url'    => url("/admin/customers/{$c->id}/view"),
            ])->values(),
        ], 300);
    }

    // ── 2. Resolve payment method (optional — null if not configured) ─────────
    $paymentMethodId = optional(
        PaymentMethod::where('company_id', $companyId)->where('type', $paymentMode)->first()
    )->id;

    // ── 3. Resolve invoice ────────────────────────────────────────────────────
    $invoiceCreated = false;

    if (!empty($validated['invoice_id'])) {
        $invoice = Invoice::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->find($validated['invoice_id']);
        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found for this customer'], 404);
        }
    } else {
        $openInvoices = Invoice::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->whereIn('paid_status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIALLY_PAID])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get(['id', 'invoice_number', 'invoice_date', 'total', 'due_amount', 'status', 'paid_status']);

        if ($openInvoices->count() > 1) {
            return response()->json([
                'needs_selection' => true,
                'selection_type'  => 'invoice',
                'message'         => 'Multiple open invoices found. Re-send with invoice_id to specify which one to apply the payment to.',
                'customer'        => ['id' => $customer->id, 'name' => $customer->name],
                'open_invoices'   => $openInvoices->map(fn ($inv) => [
                    'id'             => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'invoice_date'   => optional($inv->invoice_date)->format('Y-m-d'),
                    'total'          => round(((int) $inv->total) / 100, 2),
                    'due'            => round(((int) $inv->due_amount) / 100, 2),
                    'status'         => $inv->status,
                    'paid_status'    => $inv->paid_status,
                    'admin_url'      => url("/admin/invoices/{$inv->id}/view"),
                ])->values(),
            ], 300);
        }

        if ($openInvoices->count() === 1) {
            $invoice = Invoice::find($openInvoices->first()->id);
        } else {
            // 0 open invoices — create a DRAFT invoice as a placeholder
            $invoice = DB::transaction(function () use (
                $customer, $amountCents, $companyId, $paymentDate, $validated
            ) {
                $companyCurrencyId = (int) (CompanySetting::getSetting('currency', $companyId) ?? 1);
                $taxPerItem        = CompanySetting::getSetting('tax_per_item', $companyId) ?? 'NO';
                $discountPerItem   = CompanySetting::getSetting('discount_per_item', $companyId) ?? 'NO';
                $currencyId        = (int) ($customer->currency_id ?: $companyCurrencyId);
                $exchangeRate      = 1;
                $uniqueHash        = \Illuminate\Support\Str::random(32);
                $creatorId         = (int) (DB::table('users')->orderBy('id')->value('id') ?? 1);

                // Sequence numbers — same locking pattern as create-invoice
                $rows = DB::table('invoices')
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->get(['sequence_number', 'invoice_number']);

                $maxSeq = 0;
                foreach ($rows as $row) {
                    if ($row->sequence_number !== null && (int) $row->sequence_number > $maxSeq) {
                        $maxSeq = (int) $row->sequence_number;
                    }
                    if ($row->invoice_number && preg_match('/(\d+)$/', $row->invoice_number, $m)) {
                        if ((int) $m[1] > $maxSeq) {
                            $maxSeq = (int) $m[1];
                        }
                    }
                }
                $nextSeq = $maxSeq + 1;

                $maxCustSeq = (int) (DB::table('invoices')
                    ->where('company_id', $companyId)
                    ->where('customer_id', $customer->id)
                    ->lockForUpdate()
                    ->max('customer_sequence_number') ?? 0);
                $nextCustSeq = $maxCustSeq + 1;

                $invoiceModel = new Invoice();
                $serial = (new SerialNumberFormatter())
                    ->setModel($invoiceModel)
                    ->setCompany($companyId);
                $serial->nextSequenceNumber         = $nextSeq;
                $serial->nextCustomerSequenceNumber = $nextCustSeq;
                $invoiceNumber = $serial->getNextNumber();

                $inv = Invoice::create([
                    'invoice_date'             => $paymentDate,
                    'due_date'                 => $paymentDate,
                    'invoice_number'           => $invoiceNumber,
                    'sequence_number'          => $nextSeq,
                    'customer_sequence_number' => $nextCustSeq,
                    'customer_id'              => $customer->id,
                    'company_id'               => $companyId,
                    'creator_id'               => $creatorId,
                    'currency_id'              => $currencyId,
                    'exchange_rate'            => $exchangeRate,
                    'sub_total'                => $amountCents,
                    'total'                    => $amountCents,
                    'due_amount'               => $amountCents,
                    'base_sub_total'           => $amountCents,
                    'base_total'               => $amountCents,
                    'base_due_amount'          => $amountCents,
                    'tax'                      => 0,
                    'base_tax'                 => 0,
                    'discount'                 => 0,
                    'discount_type'            => 'fixed',
                    'discount_val'             => 0,
                    'base_discount_val'        => 0,
                    'tax_per_item'             => $taxPerItem,
                    'discount_per_item'        => $discountPerItem,
                    'paid_status'              => Invoice::STATUS_UNPAID,
                    'notes'                    => $validated['notes'] ?? '',
                    'status'                   => Invoice::STATUS_DRAFT,
                    'template_name'            => 'invoice1',
                    'unique_hash'              => $uniqueHash,
                ]);

                InvoiceItem::create([
                    'invoice_id'       => $inv->id,
                    'name'             => 'Offline Payment',
                    'description'      => '',
                    'quantity'         => 1,
                    'price'            => $amountCents,
                    'total'            => $amountCents,
                    'base_price'       => $amountCents,
                    'base_total'       => $amountCents,
                    'base_discount_val'=> 0,
                    'base_tax'         => 0,
                    'discount'         => 0,
                    'discount_type'    => 'fixed',
                    'discount_val'     => 0,
                    'tax'              => 0,
                    'exchange_rate'    => $exchangeRate,
                    'currency_id'      => $currencyId,
                    'company_id'       => $companyId,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);

                return $inv;
            });
            $invoiceCreated = true;
        }
    }

    // ── 4. Record the payment ─────────────────────────────────────────────────
    $payment = DB::transaction(function () use (
        $customer, $invoice, $amountCents, $paymentDate,
        $paymentMethodId, $companyId, $validated
    ) {
        // Sequence numbers for payment — same locking pattern as invoices
        $payRows = DB::table('payments')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->get(['sequence_number', 'payment_number']);

        $maxPaySeq = 0;
        foreach ($payRows as $row) {
            if ($row->sequence_number !== null && (int) $row->sequence_number > $maxPaySeq) {
                $maxPaySeq = (int) $row->sequence_number;
            }
            if ($row->payment_number && preg_match('/(\d+)$/', $row->payment_number, $m)) {
                if ((int) $m[1] > $maxPaySeq) {
                    $maxPaySeq = (int) $m[1];
                }
            }
        }
        $nextPaySeq = $maxPaySeq + 1;

        $maxCustPaySeq = (int) (DB::table('payments')
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->lockForUpdate()
            ->max('customer_sequence_number') ?? 0);
        $nextCustPaySeq = $maxCustPaySeq + 1;

        $paymentModel = new Payment();
        $serial = (new SerialNumberFormatter())
            ->setModel($paymentModel)
            ->setCompany($companyId);
        $serial->nextSequenceNumber         = $nextPaySeq;
        $serial->nextCustomerSequenceNumber = $nextCustPaySeq;
        $paymentNumber = $serial->getNextNumber();

        $creatorId    = (int) (DB::table('users')->orderBy('id')->value('id') ?? 1);
        $exchangeRate = (float) ($invoice->exchange_rate ?: 1);
        $currencyId   = (int) ($invoice->currency_id ?: (CompanySetting::getSetting('currency', $companyId) ?? 1));

        $pay = Payment::create([
            'payment_number'           => $paymentNumber,
            'payment_date'             => $paymentDate,
            'amount'                   => $amountCents,
            'base_amount'              => (int) round($amountCents * $exchangeRate),
            'notes'                    => $validated['notes'] ?? '',
            'invoice_id'               => $invoice->id,
            'customer_id'              => $customer->id,
            'company_id'               => $companyId,
            'creator_id'               => $creatorId,
            'user_id'                  => $creatorId,
            'payment_method_id'        => $paymentMethodId,
            'currency_id'              => $currencyId,
            'exchange_rate'            => $exchangeRate,
            'sequence_number'          => $nextPaySeq,
            'customer_sequence_number' => $nextCustPaySeq,
        ]);

        $pay->unique_hash = Hashids::connection(Payment::class)->encode($pay->id);
        $pay->save();

        $invoice->subtractInvoicePayment($amountCents);

        return $pay;
    });

    return response()->json([
        'success'           => true,
        'payment_id'        => $payment->id,
        'payment_number'    => $payment->payment_number,
        'amount'            => $validated['amount'],
        'payment_mode'      => $paymentMode,
        'invoice_id'        => $invoice->id,
        'invoice_number'    => $invoice->invoice_number,
        'invoice_created'   => $invoiceCreated,
        'customer_created'  => $customerCreated,
        'customer'          => ['id' => $customer->id, 'name' => $customer->name],
        'admin_payment_url' => url("/admin/payments/{$payment->id}/view"),
        'admin_invoice_url' => url("/admin/invoices/{$invoice->id}/view"),
    ]);
});

// Log expense from REΛVE receipt email review.
// POST /api/custom/create-expense
Route::post('/custom/create-expense', function (Illuminate\Http\Request $request) {
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $validated = $request->validate([
        'amount' => 'required|numeric|min:0.01',
        'expense_date' => 'nullable|date',
        'category_name' => 'nullable|string|max:120',
        'notes' => 'nullable|string|max:65000',
    ]);

    $company = Company::query()->orderBy('id')->first();
    if (!$company) {
        return response()->json(['error' => 'No company configured in Crater'], 422);
    }

    $companyId = $company->id;
    $currencyId = CompanySetting::getSetting('currency', $companyId);
    if (!$currencyId) {
        return response()->json(['error' => 'Company currency is not configured'], 422);
    }

    $categoryName = trim((string) ($validated['category_name'] ?? 'Business Expense'));
    if ($categoryName === '') {
        $categoryName = 'Business Expense';
    }

    $category = ExpenseCategory::query()
        ->where('company_id', $companyId)
        ->whereRaw('LOWER(name) = ?', [strtolower($categoryName)])
        ->first();

    if (!$category) {
        $category = ExpenseCategory::create([
            'name' => $categoryName,
            'company_id' => $companyId,
        ]);
    }

    $amountDollars = (float) $validated['amount'];
    $amountCents = (int) round($amountDollars * 100);
    $expenseDate = $validated['expense_date'] ?? now()->format('Y-m-d');
    $creatorId = (int) (DB::table('users')->orderBy('id')->value('id') ?? 1);

    $expense = Expense::create([
        'expense_date' => $expenseDate,
        'amount' => $amountCents,
        'base_amount' => $amountCents,
        'expense_category_id' => $category->id,
        'company_id' => $companyId,
        'currency_id' => $currencyId,
        'notes' => $validated['notes'] ?? null,
        'creator_id' => $creatorId,
        'exchange_rate' => 1,
    ]);

    $adminUrl = url('/admin/expenses/' . $expense->id . '/edit');

    return response()->json([
        'success' => true,
        'expense_id' => $expense->id,
        'amount' => $amountDollars,
        'expense_date' => $expenseDate,
        'category' => $category->name,
        'admin_url' => $adminUrl,
    ]);
});

// List line item templates for custom API
// GET /api/custom/line-items?company_id=1&q=optional-search
Route::get('/custom/line-items', function (Illuminate\Http\Request $request) {
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $companyId = (int) ($request->input('company_id') ?? 1);
    $q         = trim((string) $request->input('q', ''));

    $query = \Crater\Models\Item::with(['unit', 'currency'])
        ->where('company_id', $companyId)
        ->orderBy('name');

    if ($q !== '') {
        $query->where('name', 'LIKE', "%{$q}%");
    }

    $items = $query->get()->map(function ($item) {
        return [
            'id'           => $item->id,
            'name'         => $item->name,
            'description'  => $item->description,
            'price'        => round(((int) $item->price) / 100, 2),
            'price_cents'  => (int) $item->price,
            'unit'         => $item->unit ? $item->unit->name : null,
            'currency'     => $item->currency ? [
                'code'   => $item->currency->code,
                'symbol' => $item->currency->symbol,
            ] : null,
            'tax_per_item' => (bool) $item->tax_per_item,
        ];
    });

    return response()->json([
        'company_id' => $companyId,
        'count'      => $items->count(),
        'line_items' => $items,
    ]);
});

// Add line items to an existing invoice
// POST /api/custom/invoice/{id}/items
// Body: { "items": [{ "name": "...", "quantity": 1, "price": 50.00, "description": "..." }] }
Route::post('/custom/invoice/{id}/items', function (Illuminate\Http\Request $request, $id) {
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $invoice = Invoice::find($id);
    if (!$invoice) {
        return response()->json(['error' => 'Invoice not found'], 404);
    }

    $validated = $request->validate([
        'items'               => 'required|array|min:1',
        'items.*.name'        => 'required|string',
        'items.*.quantity'    => 'required|numeric|min:0.01',
        'items.*.price'       => 'required|numeric|min:0',
        'items.*.description' => 'nullable|string',
    ]);

    $exchangeRate = (float) ($invoice->exchange_rate ?: 1);
    $currencyId   = (int) $invoice->currency_id;
    $companyId    = (int) $invoice->company_id;
    $addedCents   = 0;

    $newItems = DB::transaction(function () use (
        $validated, $invoice, $exchangeRate, $currencyId, $companyId, &$addedCents
    ) {
        $created = [];
        foreach ($validated['items'] as $itemData) {
            $priceCents = (int) round($itemData['price'] * 100);
            $totalCents = (int) round($priceCents * $itemData['quantity']);
            $addedCents += $totalCents;

            $row = InvoiceItem::create([
                'invoice_id'        => $invoice->id,
                'name'              => $itemData['name'],
                'description'       => $itemData['description'] ?? '',
                'quantity'          => $itemData['quantity'],
                'price'             => $priceCents,
                'total'             => $totalCents,
                'base_price'        => (int) round($priceCents * $exchangeRate),
                'base_total'        => (int) round($totalCents * $exchangeRate),
                'discount'          => 0,
                'discount_type'     => 'fixed',
                'discount_val'      => 0,
                'base_discount_val' => 0,
                'tax'               => 0,
                'base_tax'          => 0,
                'exchange_rate'     => $exchangeRate,
                'currency_id'       => $currencyId,
                'company_id'        => $companyId,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
            $created[] = $row;
        }

        // Update the invoice totals — due_amount grows by the same delta
        // so any partial payments already recorded are preserved.
        $invoice->sub_total      = (int) $invoice->sub_total + $addedCents;
        $invoice->total          = (int) $invoice->total + $addedCents;
        $invoice->due_amount     = (int) $invoice->due_amount + $addedCents;
        $invoice->base_sub_total = (int) round($invoice->sub_total * $exchangeRate);
        $invoice->base_total     = (int) round($invoice->total * $exchangeRate);
        $invoice->base_due_amount= (int) round($invoice->due_amount * $exchangeRate);
        $invoice->save();

        return $created;
    });

    return response()->json([
        'success'         => true,
        'invoice_id'      => $invoice->id,
        'invoice_number'  => $invoice->invoice_number,
        'items_added'     => count($newItems),
        'amount_added'    => round($addedCents / 100, 2),
        'new_total'       => round(((int) $invoice->total) / 100, 2),
        'new_due'         => round(((int) $invoice->due_amount) / 100, 2),
        'items'           => array_map(fn ($i) => [
            'id'          => $i->id,
            'name'        => $i->name,
            'quantity'    => (float) $i->quantity,
            'price'       => round(((int) $i->price) / 100, 2),
            'total'       => round(((int) $i->total) / 100, 2),
        ], $newItems),
        'admin_url'       => url("/admin/invoices/{$invoice->id}/view"),
    ]);
});

// List recurring invoices with schedule info
// GET /api/custom/recurring-invoices?company_id=1&status=ACTIVE
Route::get('/custom/recurring-invoices', function (Illuminate\Http\Request $request) {
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $companyId = (int) ($request->input('company_id') ?? 1);
    $status    = strtoupper((string) $request->input('status', ''));

    $query = RecurringInvoice::with(['customer:id,name,email,phone', 'items', 'currency'])
        ->where('company_id', $companyId)
        ->orderBy('starts_at');

    if (in_array($status, ['ACTIVE', 'ON_HOLD', 'COMPLETED'])) {
        $query->where('status', $status);
    }

    // Human-readable label for common cron patterns
    $humanFrequency = function (string $cron): string {
        $map = [
            '* * * * *'     => 'Every minute',
            '0 * * * *'     => 'Hourly',
            '0 0 * * *'     => 'Daily',
            '0 0 * * 1'     => 'Weekly (Monday)',
            '0 0 * * 0'     => 'Weekly (Sunday)',
            '0 0 1 * *'     => 'Monthly (1st)',
            '0 0 15 * *'    => 'Monthly (15th)',
            '0 0 1 1 *'     => 'Annually (Jan 1)',
            '0 0 1 4 *'     => 'Annually (Apr 1)',
        ];
        if (isset($map[$cron])) {
            return $map[$cron];
        }
        // Detect simple monthly patterns: "0 0 D * *"
        if (preg_match('/^0 0 (\d+) \* \*$/', $cron, $m)) {
            return "Monthly ({$m[1]}th)";
        }
        // Detect simple weekly: "0 0 * * D"
        $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        if (preg_match('/^0 0 \* \* (\d)$/', $cron, $m) && isset($days[(int) $m[1]])) {
            return "Weekly ({$days[(int) $m[1]]})";
        }
        return $cron;
    };

    $records = $query->get()->map(function ($ri) use ($humanFrequency) {
        return [
            'id'               => $ri->id,
            'status'           => $ri->status,
            'send_automatically' => (bool) $ri->send_automatically,
            'starts_at'        => optional($ri->starts_at)->format('Y-m-d'),
            'next_invoice_at'  => $ri->next_invoice_at
                                    ? \Carbon\Carbon::parse($ri->next_invoice_at)->format('Y-m-d')
                                    : null,
            'frequency'        => $ri->frequency,
            'frequency_human'  => $humanFrequency((string) $ri->frequency),
            'limit_by'         => $ri->limit_by,
            'limit_count'      => $ri->limit_count,
            'limit_date'       => $ri->limit_date
                                    ? \Carbon\Carbon::parse($ri->limit_date)->format('Y-m-d')
                                    : null,
            'currency'         => $ri->currency ? [
                'code'   => $ri->currency->code,
                'symbol' => $ri->currency->symbol,
            ] : null,
            'sub_total'        => round(((int) $ri->sub_total) / 100, 2),
            'total'            => round(((int) $ri->total) / 100, 2),
            'notes'            => $ri->notes,
            'customer'         => $ri->customer ? [
                'id'    => $ri->customer->id,
                'name'  => $ri->customer->name,
                'email' => $ri->customer->email,
                'phone' => $ri->customer->phone,
            ] : null,
            'items'            => $ri->items->map(fn ($item) => [
                'id'          => $item->id,
                'name'        => $item->name,
                'description' => $item->description,
                'quantity'    => (float) $item->quantity,
                'price'       => round(((int) $item->price) / 100, 2),
                'total'       => round(((int) $item->total) / 100, 2),
            ])->values(),
            'invoice_count'    => DB::table('invoices')
                                    ->where('recurring_invoice_id', $ri->id)
                                    ->count(),
            'admin_url'        => url("/admin/recurring-invoices/{$ri->id}/view"),
        ];
    });

    return response()->json([
        'company_id'        => $companyId,
        'count'             => $records->count(),
        'recurring_invoices'=> $records,
    ]);
});

// Create recurring invoice endpoint for custom API
// POST /api/custom/create-recurring-invoice
Route::post('/custom/create-recurring-invoice', function (Illuminate\Http\Request $request) {
    // Auth token check
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $validated = $request->validate([
        'customer_name' => 'required|string',
        'starts_at' => 'nullable|date',
        'frequency' => 'nullable|string',
        'send_automatically' => 'nullable|boolean',
    ]);

    // Find customer
    $customer = Customer::where('name', $validated['customer_name'])->firstOrFail();

    // Default recurring invoice items
    $hostingItem = [
        'name' => 'Website, domain, database and/or email hosting',
        'description' => 'Annual hosting fee',
        'price' => 42500,
        'quantity' => 1,
        'discount' => 0,
        'discount_type' => 'fixed',
        'discount_val' => 0,
        'tax' => 0,
        'total' => 42500,
        'company_id' => 1,
    ];

    $recurringInvoice = \Crater\Models\RecurringInvoice::create([
        'customer_id' => $customer->id,
        'company_id' => 1,
        'creator_id' => 1,
        'starts_at' => $validated['starts_at'] ?? now()->format('Y-m-d'),
        'frequency' => $validated['frequency'] ?? '0 0 1 4 *', // Monthly on the 1st
        'send_automatically' => $validated['send_automatically'] ?? false,
        'status' => 'ACTIVE',
        'template_name' => 'invoice1',
        'discount_type' => 'fixed',
        'discount' => '0.00',
        'discount_val' => 0,
        'sub_total' => 42500,
        'total' => 42500,
        'tax' => 0,
        'due_amount' => 42500,
    ]);

    // Add the hosting line item
    \Crater\Models\InvoiceItem::create(array_merge($hostingItem, [
        'invoice_id' => null,
        'item_id' => null,
        'recurring_invoice_id' => $recurringInvoice->id,
    ]));

    return response()->json([
        'success' => true,
        'recurring_invoice_id' => $recurringInvoice->id,
        'customer' => $customer->name,
        'starts_at' => $recurringInvoice->starts_at,
        'frequency' => $recurringInvoice->frequency,
    ]);
});
// Repair payment sequence numbers and format for a company.
// Fixes NULL sequence_number rows and gaps caused by the migration backfill
// bug (if ($estimates) instead of if ($payments)) or stale UI numbers.
//
// POST /api/custom/repair-payment-numbers
// Body: { "company_id": 1, "dry_run": true }
Route::post('/custom/repair-payment-numbers', function (Illuminate\Http\Request $request) {
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $companyId = (int) ($request->input('company_id') ?? 1);
    $dryRun = filter_var($request->input('dry_run', false), FILTER_VALIDATE_BOOLEAN);

    $report = DB::transaction(function () use ($companyId, $dryRun) {
        $payments = \Crater\Models\Payment::where('company_id', $companyId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // Compute the true max sequence from both the integer field and the
        // trailing digits of payment_number (mirrors api-custom invoice logic).
        $maxSeq = 0;
        foreach ($payments as $p) {
            if ($p->sequence_number !== null && (int) $p->sequence_number > $maxSeq) {
                $maxSeq = (int) $p->sequence_number;
            }
            if ($p->payment_number && preg_match('/(\d+)$/', $p->payment_number, $m)) {
                $parsed = (int) $m[1];
                if ($parsed > $maxSeq) {
                    $maxSeq = $parsed;
                }
            }
        }

        $customerCounters = [];
        $fixed = [];

        foreach ($payments as $p) {
            $change = [];

            if ($p->sequence_number === null) {
                if ($p->payment_number && preg_match('/(\d+)$/', $p->payment_number, $m)) {
                    $p->sequence_number = (int) $m[1];
                } else {
                    $maxSeq += 1;
                    $p->sequence_number = $maxSeq;
                }
                $change['new_sequence_number'] = $p->sequence_number;
            }

            if ($p->customer_sequence_number === null) {
                $cid = (int) $p->customer_id;
                if (! isset($customerCounters[$cid])) {
                    $customerCounters[$cid] = (int) (\Crater\Models\Payment::where('company_id', $companyId)
                        ->where('customer_id', $cid)
                        ->max('customer_sequence_number') ?? 0);
                }
                $customerCounters[$cid] += 1;
                $p->customer_sequence_number = $customerCounters[$cid];
                $change['new_customer_sequence_number'] = $p->customer_sequence_number;
            }

            if (! empty($change)) {
                $change['id'] = $p->id;
                $change['payment_number'] = $p->payment_number;
                $fixed[] = $change;
                if (! $dryRun) {
                    $p->save();
                }
            }
        }

        return [
            'company_id' => $companyId,
            'dry_run' => $dryRun,
            'total_payments' => $payments->count(),
            'fixed_count' => count($fixed),
            'max_sequence_number' => $maxSeq,
            'changes' => $fixed,
        ];
    });

    return response()->json(['success' => true] + $report);
});

// NOTE: A trailing block of duplicate routes used to live here
// (GET /custom/customers, GET /custom/line-items, POST /custom/invoice/{id}/items,
// GET /custom/recurring-invoices). They were simplified copies that returned a
// `{ data: [...] }` shape and, in the line-items case, referenced a non-existent
// `Crater\Models\LineItem` (→ 500). Because Laravel lets a later same-method+URI
// route win, these shadowed the canonical handlers defined earlier in this file
// (which return `{ count, ... }` and use the real `Item` / `InvoiceItem` models).
// Removed so the canonical implementations are the active ones.
