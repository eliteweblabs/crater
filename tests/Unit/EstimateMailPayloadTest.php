<?php

use Crater\Models\Customer;
use Crater\Models\Estimate;
use Crater\Support\EstimateMailPayload;
use Crater\Support\InvoiceOgIcons;

test('estimate mail payload includes sign url when contact uid is linked', function () {
    config(['crater.reave_app_url' => 'https://rekko.studio']);

    $customer = Customer::factory()->create([
        'contact_uid' => 'contact-abc-123',
    ]);

    $estimate = Estimate::factory()->create([
        'customer_id' => $customer->id,
        'company_id' => $customer->company_id,
        'total' => 125000,
        'unique_hash' => 'est-hash-xyz',
        'status' => Estimate::STATUS_SENT,
    ]);

    $payload = EstimateMailPayload::fromEstimate($estimate);

    expect($payload['event'])->toBe('estimate.sign')
        ->and($payload['estimate']['total'])->toBe(1250.0)
        ->and($payload['customer']['contact_uid'])->toBe('contact-abc-123')
        ->and($payload['urls']['pdf'])->toContain('/estimates/pdf/est-hash-xyz')
        ->and($payload['urls']['sign'])->toBe(
            'https://rekko.studio/estimate/contact-abc-123/est-hash-xyz'
        )
        ->and($payload['branding']['icon_url'])->toBe(InvoiceOgIcons::companyBrandIconUrl('https://rekko.studio'));
});
