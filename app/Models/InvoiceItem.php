<?php

namespace Crater\Models;

use Carbon\Carbon;
use Crater\Traits\HasCustomFieldsTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InvoiceItem extends Model
{
    use HasFactory;
    use HasCustomFieldsTrait;

    protected $guarded = [
        'id'
    ];

    protected $casts = [
        'price' => 'integer',
        'total' => 'integer',
        'discount' => 'float',
        'quantity' => 'float',
        'discount_val' => 'integer',
        'tax' => 'integer',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function taxes()
    {
        return $this->hasMany(Tax::class);
    }

    public function recurringInvoice()
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    public function scopeWhereCompany($query, $company_id)
    {
        $query->where('company_id', $company_id);
    }

    public function scopeInvoicesBetween($query, $start, $end)
    {
        $query->whereHas('invoice', function ($query) use ($start, $end) {
            $query->whereBetween(
                'invoice_date',
                [$start->format('Y-m-d'), $end->format('Y-m-d')]
            );
        });
    }

    public function scopeApplyInvoiceFilters($query, array $filters)
    {
        $filters = collect($filters);

        if ($filters->get('from_date') && $filters->get('to_date')) {
            $start = Carbon::createFromFormat('Y-m-d', $filters->get('from_date'));
            $end = Carbon::createFromFormat('Y-m-d', $filters->get('to_date'));
            $query->invoicesBetween($start, $end);
        }
    }

    public function scopeItemAttributes($query)
    {
        $query->select(
            DB::raw('sum(quantity) as total_quantity, sum(base_total) as total_amount, invoice_items.name')
        )->groupBy('invoice_items.name');
    }

    /**
     * Optional add-on the customer can toggle on the public invoice.
     * Required wins if both tags appear. Also treats the EST-000001 phrasing
     * "can be added anytime" as optional so converted estimates work as-is.
     * Grouped alternatives ({@see optionGroup()}) are exclusive choices, not add-ons.
     */
    public function isOptional(): bool
    {
        if ($this->isGrouped()) {
            return false;
        }

        $name = (string) $this->name;

        if (preg_match('/\(\s*required\s*\)/i', $name)) {
            return false;
        }

        if ($this->isDiscountPackage()) {
            return true;
        }

        return (bool) preg_match(
            '/\(\s*optional\s*\)|\[\s*optional\s*\]|can be added anytime/i',
            $name
        );
    }

    /**
     * Mutually exclusive choice group from a `(group_01)` name tag.
     * `group_1` and `group_01` resolve to the same key. Also accepts
     * `(group 01)` / `[group-01]` and a tag in the description.
     */
    public function optionGroup(): ?string
    {
        $haystack = trim((string) $this->name.' '.(string) $this->description);
        if (! preg_match('/[\(\[]\s*group[\s_-]*(\d+)\s*[\)\]]/i', $haystack, $matches)) {
            return null;
        }

        return 'group_'.str_pad((string) ((int) $matches[1]), 2, '0', STR_PAD_LEFT);
    }

    public function isGrouped(): bool
    {
        return $this->optionGroup() !== null;
    }

    /**
     * Package-discount group from a `(discount_01)` or `(discount_01-100)` tag.
     * `discount_1` and `discount_01` resolve to the same key. The optional
     * `-100` suffix is dollars off that line when every member is selected
     * ({@see packageDiscountCents()}). Also accepts a tag in the description.
     */
    public function discountPackage(): ?string
    {
        $parsed = $this->parseDiscountPackageTag();

        return $parsed['package'] ?? null;
    }

    /**
     * Cents to subtract from this line when its discount package is complete.
     * `(discount_01-100)` → 10000. A plain `(discount_01)` is a member only.
     */
    public function packageDiscountCents(): int
    {
        $parsed = $this->parseDiscountPackageTag();

        return (int) ($parsed['cents'] ?? 0);
    }

    public function isDiscountPackage(): bool
    {
        return $this->discountPackage() !== null;
    }

    /**
     * @return array{package: string, cents: int}|null
     */
    private function parseDiscountPackageTag(): ?array
    {
        $haystack = trim((string) $this->name.' '.(string) $this->description);
        if (! preg_match('/[\(\[]\s*discount[\s_-]*(\d+)(?:[\s_-]+(\d+))?\s*[\)\]]/i', $haystack, $matches)) {
            return null;
        }

        return [
            'package' => 'discount_'.str_pad((string) ((int) $matches[1]), 2, '0', STR_PAD_LEFT),
            'cents' => isset($matches[2]) && $matches[2] !== ''
                ? ((int) $matches[2]) * 100
                : 0,
        ];
    }

    /**
     * Line the customer can change on the public invoice: an optional add-on
     * or one alternative in a `(group_01)` pair.
     */
    public function isCustomerSelectable(): bool
    {
        return $this->isOptional() || $this->isGrouped();
    }

    public function publicDisplayName(): string
    {
        $name = preg_replace(
            '/\s*[\(\[]\s*(optional|required|group[\s_-]*\d+|discount[\s_-]*\d+(?:[\s_-]+\d+)?)\s*[\)\]]/i',
            '',
            (string) $this->name
        );
        $name = preg_replace('/\s*\([^)]*can be added anytime[^)]*\)/i', '', $name);

        $name = trim((string) $name);

        return $name !== '' ? $name : (string) $this->name;
    }
}
