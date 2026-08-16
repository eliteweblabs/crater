<?php

use Crater\Models\Company;
use Crater\Models\Invoice;
use Crater\Models\InvoiceItem;
use Illuminate\Support\Collection;

test('share preview title uses business name and first required item', function () {
    $invoice = new Invoice(['notes' => '', 'invoice_number' => 'INV-00025']);
    $invoice->setRelation('company', new Company(['name' => 'REΛVE']));
    $invoice->setRelation('items', Collection::make([
        new InvoiceItem(['name' => 'Web Design (required)']),
        new InvoiceItem(['name' => 'Plausible Analytics (optional)']),
    ]));

    expect($invoice->sharePreviewSubject())->toBe('Web Design')
        ->and($invoice->sharePreviewTitle())->toBe('REΛVE - Invoice for Web Design');
});

test('share preview subject uses the first item when every row is optional', function () {
    $invoice = new Invoice(['notes' => 'Monthly retainer', 'invoice_number' => 'INV-1']);
    $invoice->setRelation('company', new Company(['name' => 'Acme Studio']));
    $invoice->setRelation('items', Collection::make([
        new InvoiceItem(['name' => 'Add-on (optional)']),
    ]));

    expect($invoice->sharePreviewTitle())->toBe('Acme Studio - Invoice for Add-on');
});

test('share preview title falls back to notes then invoice number', function () {
    $withNotes = new Invoice(['notes' => "  Site rebuild\nphase 2  ", 'invoice_number' => 'INV-9']);
    $withNotes->setRelation('company', new Company(['name' => 'REΛVE']));
    $withNotes->setRelation('items', Collection::make());

    $numberOnly = new Invoice(['notes' => '', 'invoice_number' => 'INV-9']);
    $numberOnly->setRelation('company', new Company(['name' => 'REΛVE']));
    $numberOnly->setRelation('items', Collection::make());

    $noBusiness = new Invoice(['notes' => '', 'invoice_number' => 'INV-9']);
    $noBusiness->setRelation('company', new Company(['name' => '']));
    $noBusiness->setRelation('items', Collection::make([
        new InvoiceItem(['name' => 'Hosting (required)']),
    ]));

    expect($withNotes->sharePreviewTitle())->toBe('REΛVE - Invoice for Site rebuild phase 2')
        ->and($numberOnly->sharePreviewTitle())->toBe('REΛVE - Invoice for INV-9')
        ->and($noBusiness->sharePreviewTitle())->toBe('Invoice for Hosting');
});
