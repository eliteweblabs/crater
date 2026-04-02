<?php

use Illuminate\Support\Facades\Route;
use Crater\Models\Customer;
use Crater\Models\Invoice;
use Crater\Models\InvoiceItem;

// Debug endpoint to check env var
Route::get('/openclaw/debug', function () {
    $token = env('OPENCLAW_API_TOKEN');
    return response()->json([
        'token_exists' => !empty($token),
        'token_length' => strlen($token ?? ''),
        'token_first_8' => substr($token ?? '', 0, 8),
    ]);
});

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
            'email' => $validated['customer_email'] ?? 'noreply@eliteweblabs.com',
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
        'status' => $validated['status'] ?? 'DRAFT',
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
        ]);
    }

    return response()->json([
        'success' => true,
        'invoice_id' => $invoice->id,
        'invoice_number' => $invoice->invoice_number,
        'customer' => $customer->name,
        'total' => $subTotal / 100,
        'admin_url' => url("/admin/invoices/{$invoice->id}/view"),
        'public_url' => url("/invoices/{$invoice->unique_hash}"),
        'pdf_url' => url("/invoices/pdf/{$invoice->unique_hash}"),
    ]);
});
