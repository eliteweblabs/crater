<?php

use Crater\Models\Company;
use Crater\Models\Customer;
use Crater\Models\Invoice;

test('share preview title uses client, business, and Invoice for Service', function () {
    $invoice = new Invoice();
    $invoice->setRelation('customer', new Customer(['name' => 'Campion Home']));
    $invoice->setRelation('company', new Company(['name' => 'REΛVE']));

    expect($invoice->sharePreviewTitle())->toBe('Campion Home / REΛVE - Invoice for Service');
});

test('share preview title omits a missing client or business name', function () {
    $businessOnly = new Invoice();
    $businessOnly->setRelation('customer', new Customer(['name' => '']));
    $businessOnly->setRelation('company', new Company(['name' => 'REΛVE']));

    $clientOnly = new Invoice();
    $clientOnly->setRelation('customer', new Customer(['name' => 'Campion Home']));
    $clientOnly->setRelation('company', new Company(['name' => '']));

    $neither = new Invoice();
    $neither->setRelation('customer', new Customer(['name' => '']));
    $neither->setRelation('company', new Company(['name' => '']));

    expect($businessOnly->sharePreviewTitle())->toBe('REΛVE - Invoice for Service')
        ->and($clientOnly->sharePreviewTitle())->toBe('Campion Home - Invoice for Service')
        ->and($neither->sharePreviewTitle())->toBe('Invoice for Service');
});
