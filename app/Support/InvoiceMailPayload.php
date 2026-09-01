<?php

namespace Crater\Support;

use Crater\Models\Company;
use Crater\Models\Invoice;

/**
 * Normalized invoice email payload for REΛVE (or any external mailer).
 * Crater admin UI and recurring auto-send both funnel through Invoice::send().
 */
class InvoiceMailPayload
{
    public static function fromSendData(Invoice $invoice, array $sendData): array
    {
        $invoice->loadMissing(['customer', 'items']);
        $company = Company::find($invoice->company_id);
        $hash = $invoice->unique_hash;
        $origin = InvoiceOgIcons::reaveOrigin(config('crater.reave_app_url'));

        $customer = $invoice->customer;
        $totalCents = (int) $invoice->total;
        $dueCents = (int) $invoice->due_amount;

        return [
            'event' => 'invoice.send',
            'to' => (string) ($sendData['to'] ?? $customer?->email ?? ''),
            'from' => (string) ($sendData['from'] ?? config('mail.from.address')),
            'subject' => (string) ($sendData['subject'] ?? 'New Invoice'),
            'body' => (string) ($sendData['body'] ?? ''),
            'customer' => [
                'id' => $customer?->id,
                'name' => $customer?->name,
                'email' => $customer?->email,
                'phone' => $customer?->phone,
                'contact_uid' => $customer?->contact_uid,
            ],
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => optional($invoice->invoice_date)->format('Y-m-d'),
                'due_date' => optional($invoice->due_date)->format('Y-m-d'),
                'status' => $invoice->status,
                'paid_status' => $invoice->paid_status,
                'total_cents' => $totalCents,
                'due_cents' => $dueCents,
                'total' => round($totalCents / 100, 2),
                'due' => round($dueCents / 100, 2),
                'notes' => $invoice->notes,
                'items' => $invoice->items->map(fn ($item) => [
                    'name' => $item->name,
                    'description' => $item->description,
                    'quantity' => (float) $item->quantity,
                    'price' => round(((int) $item->price) / 100, 2),
                    'total' => round(((int) $item->total) / 100, 2),
                ])->values()->all(),
            ],
            'company' => [
                'id' => $company?->id,
                'name' => $company?->name ?? config('app.name'),
            ],
            'urls' => [
                'public' => $hash ? url('/invoices/'.$hash) : null,
                'pdf' => $hash ? url('/invoices/pdf/'.$hash) : null,
                'payment' => $hash ? url('/invoices/'.$hash.'/pay') : null,
                'admin' => url('/admin/invoices/'.$invoice->id.'/view'),
            ],
            'branding' => [
                'reave_origin' => $origin,
                'logo_url' => rtrim($origin, '/').'/api/branding/logo',
                'icon_url' => InvoiceOgIcons::companyBrandIconUrl($origin),
                'colors' => ReaveBrandColors::fetch(),
            ],
            'options' => [
                'attach_pdf' => ! empty($sendData['attach']['data']),
            ],
            'crater' => [
                'app_url' => config('app.url'),
                'invoice_id' => $invoice->id,
            ],
        ];
    }
}
