<?php

use Illuminate\Support\Facades\Route;
use Crater\Models\Customer;
use Crater\Models\Invoice;
use Crater\Models\InvoiceItem;
use Crater\Models\RecurringInvoice;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Checkout\Session as StripeSession;

// Simple invoice creation endpoint for OpenClaw
// POST /api/openclaw/create-invoice
Route::post('/openclaw/create-invoice', function (Illuminate\Http\Request $request) {
    // Simple auth token check
    if ($request->header('X-OpenClaw-Token') !== env('OPENCLAW_API_TOKEN')) {
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

    // Find or create customer
    $customer = Customer::where('name', $validated['customer_name'])->first();
    if (!$customer) {
        $customer = Customer::create([
            'name' => $validated['customer_name'],
            'email' => $validated['customer_email'] ?? null,
            'company_id' => 1,
            'contact_name' => $validated['customer_name'],
            'currency_id' => 1,
        ]);
    }

    // Calculate totals
    $subTotal = 0;
    foreach ($validated['items'] as $item) {
        $subTotal += ($item['price'] * 100) * $item['quantity'];
    }

    // Create invoice with unique hash for public link
    $uniqueHash = \Illuminate\Support\Str::random(32);
    
    $invoice = Invoice::create([
        'invoice_date' => now()->format('Y-m-d'),
        'due_date' => now()->addDays(30)->format('Y-m-d'),
        'invoice_number' => 'INV-' . strtoupper(substr(md5(time()), 0, 8)),
        'customer_id' => $customer->id,
        'company_id' => 1,
        'sub_total' => $subTotal,
        'total' => $subTotal,
        'tax' => 0,
        'discount' => 0,
        'discount_type' => 'fixed',
        'discount_val' => 0,
        'notes' => $validated['notes'] ?? '',
        'status' => $validated['status'] ?? 'SENT',
        'template_name' => 'invoice1',
        'unique_hash' => $uniqueHash,
    ]);

    // Add line items
    foreach ($validated['items'] as $itemData) {
        $price = $itemData['price'] * 100;
        $total = $price * $itemData['quantity'];
        
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'name' => $itemData['name'],
            'description' => $itemData['description'] ?? '',
            'quantity' => $itemData['quantity'],
            'price' => $price,
            'total' => $total,
            'discount' => 0,
            'discount_type' => 'fixed',
            'discount_val' => 0,
            'tax' => 0,
            'company_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return response()->json([
        'success' => true,
        'invoice_id' => $invoice->id,
        'invoice_number' => $invoice->invoice_number,
        'customer' => $customer->name,
        'total' => $subTotal / 100,
        'admin_url' => url("/admin/invoices/{$invoice->id}/view"),
        'sms_link' => url("/invoices/{$uniqueHash}"),
        'public_url' => url("/invoices/{$uniqueHash}"),
        'pdf_url' => url("/invoices/pdf/{$uniqueHash}"),
        'payment_url' => url("/invoices/{$uniqueHash}/pay"),
    ]);
});

// Update invoice endpoint for OpenClaw
// PUT /api/openclaw/invoice/{id}
Route::put('/openclaw/invoice/{id}', function (Illuminate\Http\Request $request, $id) {
    // Auth token check
    if ($request->header('X-OpenClaw-Token') !== env('OPENCLAW_API_TOKEN')) {
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

// Delete invoice endpoint for OpenClaw
// DELETE /api/openclaw/invoice/{id}
Route::delete('/openclaw/invoice/{id}', function (Illuminate\Http\Request $request, $id) {
    // Auth token check
    if ($request->header('X-OpenClaw-Token') !== env('OPENCLAW_API_TOKEN')) {
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

// Debug endpoint to check env var (public - no auth needed)
Route::get('/openclaw/debug', function () {
    $token = env('OPENCLAW_API_TOKEN');
    return response()->json([
        'token_exists' => !empty($token),
        'token_length' => strlen($token ?? ''),
        'token_first_8' => substr($token ?? '', 0, 8),
        'env_check' => env('APP_ENV'),
    ]);
});

// Create embedded checkout session for invoice
Route::post('/invoices/{uniqueHash}/checkout-session', function ($uniqueHash) {
    try {
        $invoice = Invoice::with(['customer', 'company', 'currency'])
            ->where('unique_hash', $uniqueHash)
            ->firstOrFail();

        if ($invoice->paid_status === 'PAID') {
            return response()->json(['error' => 'Invoice already paid'], 400);
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        $session = StripeSession::create([
            'ui_mode' => 'embedded',
            'payment_method_types' => ['card', 'link', 'cashapp', 'us_bank_account'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($invoice->currency->code ?? 'usd'),
                    'product_data' => [
                        'name' => 'Invoice #' . $invoice->invoice_number,
                        'description' => 'Payment for ' . $invoice->company->name,
                    ],
                    'unit_amount' => $invoice->total,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'return_url' => url("/invoices/{$uniqueHash}?payment=success"),
            'metadata' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ],
        ]);

        return response()->json(['clientSecret' => $session->client_secret]);
    } catch (\Exception $e) {
        \Log::error('Checkout session error: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 400);
    }
});

// Create recurring invoice endpoint for OpenClaw
// POST /api/openclaw/create-recurring-invoice
Route::post('/openclaw/create-recurring-invoice', function (Illuminate\Http\Request $request) {
    // Auth token check
    if ($request->header('X-OpenClaw-Token') !== env('OPENCLAW_API_TOKEN')) {
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