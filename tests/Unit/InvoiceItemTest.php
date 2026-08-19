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

test('group tag is detected on invoice item names', function () {
    $yearly = new InvoiceItem(['name' => 'One Year Hosting Plan (group_01)']);
    $monthly = new InvoiceItem(['name' => 'Monthly Hosting Plan (group_1)']);

    expect($yearly->isGrouped())->toBeTrue()
        ->and($yearly->optionGroup())->toBe('group_01')
        ->and($yearly->isOptional())->toBeFalse()
        ->and($yearly->isCustomerSelectable())->toBeTrue()
        ->and($monthly->optionGroup())->toBe('group_01')
        ->and((new InvoiceItem(['name' => 'Railway Web Hosting (group 01)']))->optionGroup())->toBe('group_01')
        ->and((new InvoiceItem(['name' => 'Railway Web Hosting (required)']))->isGrouped())->toBeFalse()
        ->and((new InvoiceItem(['name' => 'Web Design']))->optionGroup())->toBeNull();
});

test('public display name strips optional tags', function () {
    $item = new InvoiceItem(['name' => 'Booksy White Labeling (optional)']);

    expect($item->publicDisplayName())->toBe('Booksy White Labeling');
});

test('public display name strips group tags', function () {
    $item = new InvoiceItem(['name' => 'One Year Hosting Plan (group_01)']);

    expect($item->publicDisplayName())->toBe('One Year Hosting Plan');
});

test('discount package tags are detected on invoice item names', function () {
    $label = new InvoiceItem(['name' => 'Booksy White Label (optional) (discount_01)']);
    $chat = new InvoiceItem(['name' => 'Agentic Chat (optional) (discount_01-100)']);
    $alt = new InvoiceItem(['name' => 'Agentic Chat (discount_1-100)']);

    expect($label->discountPackage())->toBe('discount_01')
        ->and($label->packageDiscountCents())->toBe(0)
        ->and($label->isDiscountPackage())->toBeTrue()
        ->and($label->isOptional())->toBeTrue()
        ->and($chat->discountPackage())->toBe('discount_01')
        ->and($chat->packageDiscountCents())->toBe(10000)
        ->and($alt->discountPackage())->toBe('discount_01')
        ->and($alt->isOptional())->toBeTrue()
        ->and($alt->publicDisplayName())->toBe('Agentic Chat')
        ->and((new InvoiceItem(['name' => 'Booksy™ White Label (discount_01)']))->publicDisplayName())->toBe('Booksy™ White Label')
        ->and((new InvoiceItem(['name' => 'Booksy White Label (discount 01)']))->discountPackage())->toBe('discount_01')
        ->and((new InvoiceItem(['name' => 'Web Design (optional)']))->discountPackage())->toBeNull()
        ->and((new InvoiceItem(['name' => 'Web Design']))->isDiscountPackage())->toBeFalse();
});

test('public display name strips discount package tags', function () {
    $item = new InvoiceItem(['name' => 'Agentic Chat (optional) (discount_01-100)']);

    expect($item->publicDisplayName())->toBe('Agentic Chat');
});

test('invoice item has many taxes', function () {
    $invoiceItem = InvoiceItem::factory()->hasTaxes(5)->create([
        'invoice_id' => Invoice::factory(),
    ]);

    $this->assertCount(5, $invoiceItem->taxes);

    $this->assertTrue($invoiceItem->taxes()->exists());
});
