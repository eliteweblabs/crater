<?php

namespace Crater\Http\Controllers;

use Crater\Models\Invoice;

class PublicInvoiceController extends Controller
{
    public function show($uniqueHash)
    {
        $invoice = Invoice::with(['customer', 'company', 'items'])
            ->where('unique_hash', $uniqueHash)
            ->firstOrFail();

        // Get all non-draft invoices for the same customer
        $customerInvoices = Invoice::where('customer_id', $invoice->customer_id)
            ->where('status', '<>', 'DRAFT')
            ->orderBy('created_at', 'desc')
            ->get(['id', 'invoice_number', 'total', 'status', 'paid_status', 'due_date', 'unique_hash']);

        return view('app.public.invoice-view', compact('invoice', 'customerInvoices'));
    }
}
