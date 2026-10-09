<?php

use Crater\Models\Invoice;
use Crater\Support\InvoiceMailPayload;
use Crater\Support\InvoiceOgIcons;

test('invoice mail payload includes reave branding urls and public links', function () {
    $invoice = Invoice::factory()->create([
        'total' => 50000,
        'due_amount' => 50000,
        'sub_total' => 50000,
    ]);

    $payload = InvoiceMailPayload::fromSendData($invoice, [
        'to' => 'client@example.com',
        'from' => 'billing@rekko.studio',
        'subject' => 'Invoice from rekko.studio',
        'body' => 'Hello {CUSTOMER_NAME}',
    ]);

    expect($payload['event'])->toBe('invoice.send')
        ->and($payload['to'])->toBe('client@example.com')
        ->and($payload['invoice']['total'])->toBe(500.0)
        ->and($payload['urls']['public'])->toContain('/invoices/')
        ->and($payload['branding']['logo_url'])->toBe('https://rekko.studio/api/branding/logo')
        ->and($payload['branding']['icon_url'])->toBe(InvoiceOgIcons::companyBrandIconUrl('https://rekko.studio'))
        ->and($payload['branding']['og_url'])->toContain('https://rekko.studio/api/branding/og.png');
});
