<?php

use Crater\Models\Invoice;
use Crater\Models\InvoiceItem;
use Crater\Models\Item;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
});

test('invoice item belongs to invoice', function () {
    $invoiceItem = InvoiceItem::factory()->forInvoice()->create();

    $this->assertTrue($invoiceItem->invoice()->exists());
});

test('invoice item belongs to item', function () {
    $invoiceItem = InvoiceItem::factory()->create([
        'item_id' => Item::factory(),
        'invoice_id' => Invoice::factory(),
    ]);

    $this->assertTrue($invoiceItem->item()->exists());
});


test('optional tag is detected on invoice item names', function () {
    expect((new InvoiceItem(['name' => 'Phaseline Analytics (optional)']))->isOptional())->toBeTrue();
    expect((new InvoiceItem(['name' => 'Booksy White Labeling (one time fee, can be added anytime)']))->isOptional())->toBeTrue();
    expect((new InvoiceItem(['name' => 'Railway Web Hosting (required)']))->isOptional())->toBeFalse();
    expect((new InvoiceItem(['name' => 'Web Design']))->isOptional())->toBeFalse();
});

test('public display name strips optional tags', function () {
    $item = new InvoiceItem(['name' => 'Booksy White Labeling (optional)']);

    expect($item->publicDisplayName())->toBe('Booksy White Labeling');
});

test('invoice item has many taxes', function () {
    $invoiceItem = InvoiceItem::factory()->hasTaxes(5)->create([
        'invoice_id' => Invoice::factory(),
    ]);

    $this->assertCount(5, $invoiceItem->taxes);

    $this->assertTrue($invoiceItem->taxes()->exists());
});
