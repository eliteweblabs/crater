<?php

namespace Crater\Support;

use Crater\Models\CompanySetting;
use Crater\Models\Customer;
use Crater\Models\Invoice;
use Crater\Models\InvoiceItem;
use Crater\Services\SerialNumberFormatter;
use Illuminate\Support\Facades\DB;

/**
 * Prepayment "Tally" invoices for Siri / record-payment — subject (ref) starts with "Tally".
 */
class TallyInvoice
{
    public const REFERENCE_SUBJECT = 'Tally';

    public static function isTally(Invoice $invoice): bool
    {
        $ref = strtolower(trim((string) $invoice->reference_number));

        return $ref !== '' && str_starts_with($ref, 'tally');
    }

    /**
     * Most recent tally for this customer (including paid), until the ref is edited away from Tally.
     */
    public static function findActiveForCustomer(int $companyId, int $customerId): ?Invoice
    {
        return Invoice::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->whereRaw('LOWER(TRIM(reference_number)) LIKE ?', ['tally%'])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Add a prepayment line so due_amount reflects new money before we record the payment row.
     */
    public static function addPrepaymentLine(Invoice $invoice, int $amountCents): void
    {
        if ($amountCents <= 0) {
            return;
        }

        $exchangeRate = (float) ($invoice->exchange_rate ?: 1);
        $currencyId   = (int) $invoice->currency_id;
        $companyId    = (int) $invoice->company_id;

        InvoiceItem::create([
            'invoice_id'        => $invoice->id,
            'name'              => 'Prepayment',
            'description'       => '',
            'quantity'          => 1,
            'price'             => $amountCents,
            'total'             => $amountCents,
            'base_price'        => (int) round($amountCents * $exchangeRate),
            'base_total'        => (int) round($amountCents * $exchangeRate),
            'discount'          => 0,
            'discount_type'     => 'fixed',
            'discount_val'      => 0,
            'base_discount_val' => 0,
            'tax'               => 0,
            'base_tax'          => 0,
            'exchange_rate'     => $exchangeRate,
            'currency_id'       => $currencyId,
            'company_id'        => $companyId,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $invoice->sub_total       = (int) $invoice->sub_total + $amountCents;
        $invoice->total           = (int) $invoice->total + $amountCents;
        $invoice->due_amount      = (int) $invoice->due_amount + $amountCents;
        $invoice->base_sub_total  = (int) round($invoice->sub_total * $exchangeRate);
        $invoice->base_total      = (int) round($invoice->total * $exchangeRate);
        $invoice->base_due_amount = (int) round($invoice->due_amount * $exchangeRate);
        if ($invoice->paid_status === Invoice::STATUS_PAID) {
            $invoice->paid_status = Invoice::STATUS_UNPAID;
            $invoice->status      = Invoice::STATUS_DRAFT;
        }
        $invoice->save();
    }

    public static function createForPrepayment(
        Customer $customer,
        int $companyId,
        int $amountCents,
        string $paymentDate,
        string $notes = ''
    ): Invoice {
        return DB::transaction(function () use ($customer, $companyId, $amountCents, $paymentDate, $notes) {
            $companyCurrencyId = (int) (CompanySetting::getSetting('currency', $companyId) ?? 1);
            $taxPerItem        = CompanySetting::getSetting('tax_per_item', $companyId) ?? 'NO';
            $discountPerItem   = CompanySetting::getSetting('discount_per_item', $companyId) ?? 'NO';
            $currencyId        = (int) ($customer->currency_id ?: $companyCurrencyId);
            $exchangeRate      = 1;
            $uniqueHash        = \Illuminate\Support\Str::random(32);
            $creatorId         = (int) (DB::table('users')->orderBy('id')->value('id') ?? 1);

            $rows = DB::table('invoices')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->get(['sequence_number', 'invoice_number']);

            $maxSeq = 0;
            foreach ($rows as $row) {
                if ($row->sequence_number !== null && (int) $row->sequence_number > $maxSeq) {
                    $maxSeq = (int) $row->sequence_number;
                }
                if ($row->invoice_number && preg_match('/(\d+)$/', $row->invoice_number, $m)) {
                    if ((int) $m[1] > $maxSeq) {
                        $maxSeq = (int) $m[1];
                    }
                }
            }
            $nextSeq = $maxSeq + 1;

            $maxCustSeq = (int) (DB::table('invoices')
                ->where('company_id', $companyId)
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->max('customer_sequence_number') ?? 0);
            $nextCustSeq = $maxCustSeq + 1;

            $invoiceModel = new Invoice();
            $serial = (new SerialNumberFormatter())
                ->setModel($invoiceModel)
                ->setCompany($companyId);
            $serial->nextSequenceNumber         = $nextSeq;
            $serial->nextCustomerSequenceNumber = $nextCustSeq;
            $invoiceNumber = $serial->getNextNumber();

            $inv = Invoice::create([
                'invoice_date'             => $paymentDate,
                'due_date'                 => $paymentDate,
                'invoice_number'           => $invoiceNumber,
                'reference_number'         => self::REFERENCE_SUBJECT,
                'sequence_number'          => $nextSeq,
                'customer_sequence_number' => $nextCustSeq,
                'customer_id'              => $customer->id,
                'company_id'               => $companyId,
                'creator_id'               => $creatorId,
                'currency_id'              => $currencyId,
                'exchange_rate'            => $exchangeRate,
                'sub_total'                => $amountCents,
                'total'                    => $amountCents,
                'due_amount'               => $amountCents,
                'base_sub_total'           => $amountCents,
                'base_total'               => $amountCents,
                'base_due_amount'          => $amountCents,
                'tax'                      => 0,
                'base_tax'                 => 0,
                'discount'                 => 0,
                'discount_type'            => 'fixed',
                'discount_val'             => 0,
                'base_discount_val'        => 0,
                'tax_per_item'             => $taxPerItem,
                'discount_per_item'        => $discountPerItem,
                'paid_status'              => Invoice::STATUS_UNPAID,
                'notes'                    => $notes,
                'status'                   => Invoice::STATUS_DRAFT,
                'template_name'            => 'invoice1',
                'unique_hash'              => $uniqueHash,
            ]);

            InvoiceItem::create([
                'invoice_id'        => $inv->id,
                'name'              => 'Prepayment',
                'description'       => '',
                'quantity'          => 1,
                'price'             => $amountCents,
                'total'             => $amountCents,
                'base_price'        => $amountCents,
                'base_total'        => $amountCents,
                'base_discount_val' => 0,
                'base_tax'          => 0,
                'discount'          => 0,
                'discount_type'     => 'fixed',
                'discount_val'      => 0,
                'tax'               => 0,
                'exchange_rate'     => $exchangeRate,
                'currency_id'       => $currencyId,
                'company_id'        => $companyId,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            return $inv;
        });
    }
}
