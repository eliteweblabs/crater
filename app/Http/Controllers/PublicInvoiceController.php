<?php

namespace Crater\Http\Controllers;

use Crater\Models\Invoice;
use Crater\Support\ReaveBrandColors;
use Illuminate\Http\RedirectResponse;

class PublicInvoiceController extends Controller
{
    public function show($uniqueHash)
    {
        $invoice = Invoice::with(['customer', 'company.owner', 'company.address', 'items'])
            ->where('unique_hash', $uniqueHash)
            ->firstOrFail();

        $owner = $invoice->company->owner;
        $brand = ReaveBrandColors::fetch();
        $fromContact = [
            'name' => $brand['contactName']
                ?? $owner?->contact_name
                ?? $owner?->name
                ?: config('mail.from.name'),
            'email' => $brand['contactEmail']
                ?? $owner?->email
                ?: config('mail.from.address'),
        ];

        $hasOptionalItems = $invoice->paid_status !== Invoice::STATUS_PAID
            && $invoice->hasCustomerSelectableItems();

        $ogImageUrl = $brand['ogUrl'] ?? ReaveBrandColors::ogUrl();

        // Get all non-draft invoices for the same customer
        $customerInvoices = Invoice::where('customer_id', $invoice->customer_id)
            ->where('company_id', $invoice->company_id)
            ->where('status', '<>', 'DRAFT')
            ->where('id', '<>', $invoice->id)  // Exclude current invoice
            ->orderBy('created_at', 'desc')
            ->get(['id', 'invoice_number', 'total', 'status', 'paid_status', 'due_date', 'unique_hash']);

        return view('app.public.invoice-view', compact(
            'invoice',
            'customerInvoices',
            'hasOptionalItems',
            'ogImageUrl',
            'brand',
            'fromContact'
        ));
    }

    /**
     * Legacy per-invoice OG URL — redirects to reΛVe admin branding share card.
     */
    public function ogImage($uniqueHash): RedirectResponse
    {
        Invoice::where('unique_hash', $uniqueHash)->firstOrFail();

        $url = ReaveBrandColors::ogUrl();
        if (!$url) {
            abort(404);
        }

        return redirect()->away($url, 302);
    }
}
