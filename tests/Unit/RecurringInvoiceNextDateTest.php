<?php

use Crater\Models\RecurringInvoice;

test('getNextInvoiceDate includes the first cron match on the start day', function () {
    $next = RecurringInvoice::getNextInvoiceDate('0 0 1 9 *', '2026-09-01 00:00:00');

    expect($next)->toBe('2026-09-01 00:00:00');
});

test('getNextInvoiceDate returns the next annual run after an earlier start date', function () {
    $next = RecurringInvoice::getNextInvoiceDate('0 0 1 9 *', '2026-08-31');

    expect($next)->toBe('2026-09-01 00:00:00');
});

test('getNextInvoiceDate can compute the following cycle after generation', function () {
    $next = RecurringInvoice::getNextInvoiceDate('0 0 1 9 *', '2026-09-01 00:00:01', false);

    expect($next)->toBe('2027-09-01 00:00:00');
});

test('getCurrentDueInvoiceDate returns the latest cron run on or before now', function () {
    Carbon::setTestNow('2026-09-01 14:00:00');

    $due = RecurringInvoice::getCurrentDueInvoiceDate('0 0 1 9 *', '2026-08-31');

    expect($due)->toBe('2026-09-01 00:00:00');

    Carbon::setTestNow();
});
