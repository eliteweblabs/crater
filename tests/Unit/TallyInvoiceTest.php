<?php

use Crater\Models\Invoice;
use Crater\Support\TallyInvoice;

test('tally invoice is detected by reference subject prefix', function () {
    $invoice = new Invoice(['reference_number' => 'Tally']);
    expect(TallyInvoice::isTally($invoice))->toBeTrue();

    $invoice->reference_number = 'tally — prepayment';
    expect(TallyInvoice::isTally($invoice))->toBeTrue();

    $invoice->reference_number = 'Website redesign';
    expect(TallyInvoice::isTally($invoice))->toBeFalse();
});
