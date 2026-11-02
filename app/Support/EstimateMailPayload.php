<?php

namespace Crater\Support;

use Crater\Models\Company;
use Crater\Models\Estimate;

/**
 * Normalized estimate payload for REΛVE signing and external mailers.
 */
class EstimateMailPayload
{
    public static function fromEstimate(Estimate $estimate, array $sendData = []): array
    {
        $estimate->loadMissing(['customer', 'items']);
        $company = Company::find($estimate->company_id);
        $hash = $estimate->unique_hash;
        $origin = InvoiceOgIcons::reaveOrigin(config('crater.reave_app_url'));

        $customer = $estimate->customer;
        $contactUid = trim((string) ($customer?->contact_uid ?? ''));
        $totalCents = (int) $estimate->total;

        $signUrl = ($contactUid !== '' && $hash)
            ? rtrim($origin, '/').'/estimate/'.rawurlencode($contactUid).'/'.rawurlencode($hash)
            : null;

        return [
            'event' => 'estimate.sign',
            'to' => (string) ($sendData['to'] ?? $customer?->email ?? ''),
            'from' => (string) ($sendData['from'] ?? config('mail.from.address')),
            'subject' => (string) ($sendData['subject'] ?? 'Estimate '.$estimate->estimate_number),
            'body' => (string) ($sendData['body'] ?? ''),
            'customer' => [
                'id' => $customer?->id,
                'name' => $customer?->name,
                'email' => $customer?->email,
                'phone' => $customer?->phone,
                'contact_uid' => $contactUid !== '' ? $contactUid : null,
            ],
            'estimate' => [
                'id' => $estimate->id,
                'estimate_number' => $estimate->estimate_number,
                'estimate_date' => optional($estimate->estimate_date)->format('Y-m-d'),
                'expiry_date' => optional($estimate->expiry_date)->format('Y-m-d'),
                'status' => $estimate->status,
                'total_cents' => $totalCents,
                'total' => round($totalCents / 100, 2),
                'notes' => $estimate->notes,
                'signed_at' => optional($estimate->signed_at)->toIso8601String(),
                'signer_name' => $estimate->signer_name,
                'signature_doc_id' => $estimate->signature_doc_id,
                'signature_view_url' => $estimate->signature_view_url,
                'items' => $estimate->items->map(fn ($item) => [
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
                'pdf' => $hash ? url('/estimates/pdf/'.$hash) : null,
                'admin' => url('/admin/estimates/'.$estimate->id.'/view'),
                'sign' => $signUrl,
            ],
            'branding' => [
                'reave_origin' => $origin,
                'logo_url' => rtrim($origin, '/').'/api/branding/logo',
                'icon_url' => InvoiceOgIcons::companyBrandIconUrl($origin),
                'colors' => ReaveBrandColors::fetch(),
            ],
            'crater' => [
                'app_url' => config('app.url'),
                'estimate_id' => $estimate->id,
                'unique_hash' => $hash,
            ],
        ];
    }
}
