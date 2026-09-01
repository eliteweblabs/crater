<?php

namespace Crater\Console\Commands;

use Carbon\Carbon;
use Crater\Models\CompanySetting;
use Crater\Models\RecurringInvoice;
use Illuminate\Console\Command;

class GenerateDueRecurringInvoices extends Command
{
    protected $signature = 'recurring-invoices:generate-due {--dry-run : Show due invoices without creating them}';

    protected $description = 'Generate invoices for active recurring schedules whose due date has passed';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $generated = 0;

        RecurringInvoice::query()
            ->where('status', RecurringInvoice::ACTIVE)
            ->where('starts_at', '<=', Carbon::now())
            ->orderBy('id')
            ->chunk(100, function ($recurringInvoices) use ($dryRun, &$generated) {
                foreach ($recurringInvoices as $recurringInvoice) {
                    $timeZone = CompanySetting::getSetting('time_zone', $recurringInvoice->company_id);
                    $dueAt = RecurringInvoice::getCurrentDueInvoiceDate(
                        $recurringInvoice->frequency,
                        $recurringInvoice->starts_at,
                        $timeZone
                    );

                    if (! $dueAt) {
                        continue;
                    }

                    $dueDate = Carbon::parse($dueAt)->format('Y-m-d');
                    $exists = $recurringInvoice->invoices()
                        ->whereDate('invoice_date', $dueDate)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $customerName = optional($recurringInvoice->customer)->name ?? 'unknown customer';
                    $this->line(sprintf(
                        'Generating missed invoice for recurring invoice #%d (%s), due %s',
                        $recurringInvoice->id,
                        $customerName,
                        $dueDate
                    ));

                    if (! $dryRun) {
                        $recurringInvoice->generateInvoice();
                    }

                    $generated++;
                }
            });

        if ($generated === 0) {
            $this->info('No due recurring invoices needed generation.');
            return 0;
        }

        $this->info(($dryRun ? 'Would generate' : 'Generated')." {$generated} invoice(s).");

        return 0;
    }
}
