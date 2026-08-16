<?php

use Crater\Models\Company;
use Crater\Models\Invoice;

test('share preview title uses business name and Invoice for Service', function () {
    $invoice = new Invoice();
    $invoice->setRelation('company', new Company(['name' => 'REΛVE']));

    expect($invoice->sharePreviewTitle())->toBe('REΛVE - Invoice for Service');
});

test('share preview title falls back when the company name is empty', function () {
    $invoice = new Invoice();
    $invoice->setRelation('company', new Company(['name' => '']));

    expect($invoice->sharePreviewTitle())->toBe('Invoice for Service');
});
