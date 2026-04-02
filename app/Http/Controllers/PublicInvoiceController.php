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

        return view('app.public.invoice-view', compact('invoice'));
    }
}
