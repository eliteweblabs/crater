<?php

namespace Crater\Console\Commands;

use Crater\Models\RecurringInvoice;
use Illuminate\Console\Command;

class RecalculateRecurringInvoiceNextDates extends Command
{
    protected $signature = 'recurring-invoices:recalculate-next-dates {--dry-run : Show changes without saving}';

    protected $description = 'Recalculate next_invoice_at for all recurring invoices using corrected cron logic';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        RecurringInvoice::query()->orderBy('id')->chunk(100, function ($recurringInvoices) use ($dryRun, &$updated) {
            foreach ($recurringInvoices as $recurringInvoice) {
                $nextInvoiceAt = RecurringInvoice::getNextInvoiceDate(
                    $recurringInvoice->frequency,
                    $recurringInvoice->starts_at
                );

                if ($recurringInvoice->next_invoice_at === $nextInvoiceAt) {
                    continue;
                }

                $this->line(sprintf(
                    'Recurring invoice #%d (%s): %s -> %s',
                    $recurringInvoice->id,
                    optional($recurringInvoice->customer)->name ?? 'unknown customer',
                    $recurringInvoice->next_invoice_at,
                    $nextInvoiceAt
                ));

                if (! $dryRun) {
                    $recurringInvoice->next_invoice_at = $nextInvoiceAt;
                    $recurringInvoice->save();
                }

                $updated++;
            }
        });

        if ($updated === 0) {
            $this->info('No recurring invoices needed next date updates.');
            return 0;
        }

        $this->info(($dryRun ? 'Would update' : 'Updated')." {$updated} recurring invoice(s).");

        return 0;
    }
}
