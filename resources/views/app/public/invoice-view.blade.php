<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
    @php
        $pageTitle = $invoice->sharePreviewTitle();
    @endphp
    <title>{{ $pageTitle }}</title>
    
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $invoice->customer->name }}{{ $invoice->formattedDueDate ? ' · Due '.$invoice->formattedDueDate : '' }}">
    <meta property="og:image" content="{{ $ogImageUrl ?? url('/invoices/'.$invoice->unique_hash.'/og.png').'?v=reave' }}">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Invoice {{ $invoice->invoice_number }} for {{ $invoice->customer->name }}">
    <meta property="og:url" content="{{ url('/invoices/'.$invoice->unique_hash) }}">
    <meta property="og:site_name" content="{{ $invoice->company->name ?? 'Invoice' }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $invoice->customer->name }}{{ $invoice->formattedDueDate ? ' · Due '.$invoice->formattedDueDate : '' }}">
    <meta name="twitter:image" content="{{ $ogImageUrl ?? url('/invoices/'.$invoice->unique_hash.'/og.png').'?v=reave' }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Mozilla+Text:ital,wght@0,400;0,500;0,600;1,400&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">

    @if($invoice->paid_status !== 'PAID' && config('services.stripe.key'))
    <script src="https://js.stripe.com/v3/"></script>
    @endif

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { overflow-x: hidden; }
        body {
            font-family: "Mozilla Text", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        h1, h2, h3, .invoice-info, .item-title, .amount, .totals, .status, .pay-cta, .btn {
            font-family: "Space Grotesk", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .container { max-width: 800px; margin: 40px auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: visible; }
        .header {
            display: grid;
            grid-template-columns: 1fr auto;
            grid-template-areas: "logo info";
            align-items: start;
            justify-items: end;
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 2px solid #eee;
        }
        .logo { grid-area: logo; justify-self: start; }
        .logo img { max-width: 200px; height: auto; }
        .invoice-info {
            grid-area: info;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            text-align: right;
        }
        .invoice-info h1 { font-size: 28px; font-weight: 300; color: #666; margin: 0; letter-spacing: -0.02em; }
        .status { display: inline-block; padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .status.sent { background: #e3f2fd; color: #1976d2; }
        .status.paid { background: #e8f5e9; color: #388e3c; }
        .status.overdue { background: #ffebee; color: #d32f2f; }
        .meta-dates { margin: 0; font-size: 14px; }
        .meta-dates p { margin: 0; line-height: 1.7; white-space: nowrap; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin: 0 0 24px; }
        .party h3 { font-size: 12px; text-transform: uppercase; color: #999; margin-bottom: 8px; font-weight: 600; }
        .party p { margin: 4px 0; }
        .items { margin: 24px -40px 20px; overflow: visible; border-top: 1px solid #eee; }
        .item-row { display: block; position: relative; overflow: visible; padding: 16px 40px; border-bottom: 1px solid #eee; }
        .item-row::after { content: ''; display: table; clear: both; }
        .item-row--optional,
        .item-row--grouped { background: #faf7ff; cursor: pointer; }
        /* Own top rule after a required row so the Optional / Choose one chip
           sits on this row’s edge, not the white item above. Consecutive
           selectable rows keep a single shared border. */
        .item-row:not(.item-row--optional):not(.item-row--grouped) + .item-row--optional,
        .item-row:not(.item-row--optional):not(.item-row--grouped) + .item-row--grouped { border-top: 1px solid #eee; }
        .item-row--off .item-title,
        .item-row--off .description,
        .item-row--off .amount,
        .item-row--off .item-switch { opacity: 0.55; }
        .amount-was { display: block; text-decoration: line-through; font-weight: 400; opacity: 0.55; font-size: 13px; line-height: 1.2; }
        .item-heading { display: flex; align-items: center; gap: 8px; min-width: 0; }
        .item-switch { position: relative; display: inline-flex; flex: none; cursor: pointer; margin: 0; }
        .items .item-title { font-weight: 500; line-height: 1.2; min-width: 0; }
        .items .description { color: #666; font-size: 14px; }
        .items .description a { color: inherit; text-decoration: none; pointer-events: none; }
        .items .amount {
            float: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            margin: 0 0 8px 12px;
            font-weight: 500;
            white-space: nowrap;
            min-width: 6.25em;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        .item-switch input { position: absolute; inset: 0; z-index: 1; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
        .item-switch-track {
            position: relative;
            display: block;
            width: 26px;
            height: 16px;
            border-radius: 999px;
            background: #d4d0db;
            box-shadow: inset 0 0 0 1px rgba(11, 5, 18, 0.08);
            transition: background 0.18s ease, box-shadow 0.18s ease;
        }
        .item-switch-track::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 1px 3px rgba(11, 5, 18, 0.28);
            transition: transform 0.18s ease;
        }
        .item-switch input:checked + .item-switch-track {
            background: linear-gradient(145deg, #f472b6 0%, #c026d3 52%, #6366f1 100%);
            box-shadow: 0 2px 10px rgba(192, 38, 211, 0.35);
        }
        .item-switch input:checked + .item-switch-track::after {
            transform: translateX(10px);
        }
        .item-switch input:focus-visible + .item-switch-track {
            outline: 2px solid #c026d3;
            outline-offset: 2px;
        }
        .addon-badge {
            position: absolute;
            top: 0;
            right: 0;
            z-index: 2;
            display: inline-block;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.04em;
            line-height: 1.2;
            text-transform: uppercase;
            color: #ffffff;
            background: linear-gradient(145deg, #f472b6 0%, #c026d3 52%, #6366f1 100%);
            box-shadow: 0 2px 8px rgba(192, 38, 211, 0.28);
            /* Hang by the pill’s end-cap radius so the card edge bisects that circle. */
            transform: translate(9px, -50%);
            pointer-events: none;
            white-space: nowrap;
        }
        .totals { margin-left: auto; width: 300px; margin-top: 20px; }
        .totals .row { display: flex; justify-content: space-between; padding: 8px 0; }
        .totals .row.total { font-size: 20px; font-weight: 600; }
        .pay-cta { display: none; margin: 16px 0 0; width: 100%; padding: 14px 28px; border: none; border-radius: 999px; font-size: 16px; font-weight: 600; letter-spacing: -0.01em; color: #ffffff; cursor: pointer; background: linear-gradient(145deg, #f472b6 0%, #c026d3 52%, #6366f1 100%); box-shadow: 0 2px 16px rgba(192, 38, 211, 0.35); }
        .pay-cta.is-visible { display: block; }
        .pay-cta:disabled { opacity: 0.6; cursor: not-allowed; }
        #payment-element { margin-top: 24px; width: 100%; }
        #payment-element:empty { display: none; margin: 0; }
        .pay-error { color: #d32f2f; margin-top: 12px; font-size: 14px; }
        .btn { display: inline-block; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-weight: 600; text-align: center; cursor: pointer; border: none; font-size: 16px; transition: all 0.2s; width: 100%; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-secondary { background: white; color: #666; border: 1px solid #ddd; margin-top: 12px; }
        .btn-secondary:hover { border-color: #999; color: #333; }
        .btn-pdf { border-radius: 999px; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 22px; }
        .btn-pdf svg { flex: none; }
        .foot-tag {
            display: inline-flex;
            align-items: center;
            gap: 0;
            line-height: 1;
        }
        .foot-bean {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 10px;
            height: 10px;
            margin: 0 1px;
            flex: none;
            overflow: visible;
        }
        .foot-bean-icon { display: block; width: 10px; height: 13px; flex: none; }
        .foot-bean--a { transform: rotate(28deg); }
        .foot-bean--b { transform: rotate(-28deg); }
        .error-message { color: #d32f2f; margin-top: 12px; font-size: 14px; }
        .success-message { background: #e8f5e9; border: 1px solid #388e3c; color: #2e7d32; padding: 16px; border-radius: 6px; margin-bottom: 20px; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #eee; color: #999; font-size: 14px; text-align: center; }
        .pdf-hint { margin-top: 20px; text-align: center; color: #999; font-size: 13px; }
        @media (max-width: 640px) {
            .container { padding: 20px; margin: 20px; }
            .header {
                display: grid;
                grid-template-columns: 1fr auto;
                grid-template-areas:
                    "logo logo"
                    "info info";
                gap: 8px 12px;
                align-items: start;
            }
            .logo { grid-area: logo; }
            .invoice-info { grid-area: info; justify-content: flex-start; text-align: left; justify-self: start; }
            .parties { grid-template-columns: 1fr; gap: 24px; }
            .totals { width: 100%; }
            .items { margin-left: -20px; margin-right: -20px; }
            .item-row { padding-left: 16px; padding-right: 16px; }
            .addon-badge { transform: translate(9px, -50%); }
        }
    </style>
</head>
<body>
    <div class="container">
        @if($invoice->paid_status === 'PAID')
        <div class="success-message">
            ✅ This invoice has been paid. Thank you!
        </div>
        @endif

        <div class="header">
            <div class="logo">
                <img src="{{ $invoice->company->logo ?? asset('build/img/crater-logo.png') }}" alt="{{ $invoice->company->name }}">
            </div>
            <div class="invoice-info">
                @php
                    $invoiceShortNo = preg_match('/(\d+)\s*$/', (string) $invoice->invoice_number, $m)
                        ? (string) intval($m[1])
                        : (string) $invoice->invoice_number;
                @endphp
                <h1>INVOICE #{{ $invoiceShortNo }}</h1>
                <span class="status {{ strtolower($invoice->status) }}">{{ $invoice->paid_status === 'PAID' ? 'PAID' : $invoice->status }}</span>
            </div>
        </div>

        <div class="parties">
            <div class="party">
                <h3>From</h3>
                <p><strong>{{ $invoice->company->name }}</strong></p>
                @if($invoice->company->address_street_1)
                    <p>{{ $invoice->company->address_street_1 }}</p>
                @endif
                @if($invoice->company->city || $invoice->company->state || $invoice->company->zip)
                    <p>{{ $invoice->company->city }}@if($invoice->company->state), {{ $invoice->company->state }}@endif @if($invoice->company->zip){{ $invoice->company->zip }}@endif</p>
                @endif
            </div>
            <div class="party">
                <h3>Bill To</h3>
                <p><strong>{{ $invoice->customer->name }}</strong></p>
                @if($invoice->customer->contact_name)
                    <p>{{ $invoice->customer->contact_name }}</p>
                @endif
                @if($invoice->customer->email)
                    <p>{{ $invoice->customer->email }}</p>
                @endif
            </div>
        </div>

        <div class="meta-dates">
            <p><strong>Invoice Date:</strong> {{ $invoice->formattedInvoiceDate }}</p>
            <p><strong>Due Date:</strong> {{ $invoice->formattedDueDate }}</p>
        </div>

        <div class="items" autocomplete="off">
            @php
                $paid = $invoice->paid_status === 'PAID';
                $groupActiveIds = $invoice->activeGroupedItemIds();
                $groupBadgeShown = [];
                $rowMeta = [];
                foreach ($invoice->items as $metaItem) {
                    $metaGroup = $metaItem->optionGroup();
                    $metaOptional = ! $paid && $metaItem->isOptional();
                    $metaGrouped = ! $paid && $metaGroup !== null;
                    if ($metaGrouped) {
                        $metaIncluded = isset($groupActiveIds[$metaGroup]) && (int) $groupActiveIds[$metaGroup] === (int) $metaItem->id;
                    } else {
                        $metaIncluded = ! $metaOptional || (float) $metaItem->quantity > 0;
                    }
                    $rowMeta[(int) $metaItem->id] = [
                        'group' => $metaGroup,
                        'optional' => $metaOptional,
                        'grouped' => $metaGrouped,
                        'selectable' => $metaOptional || $metaGrouped,
                        'included' => $metaIncluded,
                    ];
                }
                $packageComplete = [];
                foreach ($invoice->items->groupBy(fn ($i) => $i->discountPackage()) as $pkg => $members) {
                    if ($pkg === null || $pkg === '') {
                        continue;
                    }
                    $packageComplete[$pkg] = $members->count() >= 2
                        && $members->every(fn ($i) => ! empty($rowMeta[(int) $i->id]['included']));
                }
            @endphp
            @foreach($invoice->items as $item)
            @php
                $meta = $rowMeta[(int) $item->id];
                $group = $meta['group'];
                $optional = $meta['optional'];
                $grouped = $meta['grouped'];
                $selectable = $meta['selectable'];
                $included = $meta['included'];
                $packageOff = (! $paid && $included && ! empty($packageComplete[$item->discountPackage() ?? '']))
                    ? (int) $item->packageDiscountCents()
                    : 0;
                $displayPrice = max(0, (int) $item->price - $packageOff);
                $showGroupBadge = $grouped && ! isset($groupBadgeShown[$group]);
                if ($showGroupBadge) {
                    $groupBadgeShown[$group] = true;
                }
            @endphp
            @if($paid && $item->isCustomerSelectable() && (float) $item->quantity <= 0)
                @continue
            @endif
            <{{ $selectable ? 'label' : 'div' }} class="item-row {{ $selectable ? 'item-row--optional' : '' }} {{ $grouped ? 'item-row--grouped' : '' }} {{ $selectable && ! $included ? 'item-row--off' : '' }}"
                data-optional="{{ $optional ? '1' : '0' }}"
                data-grouped="{{ $grouped ? '1' : '0' }}"
                data-group="{{ $group ?? '' }}"
                data-discount-package="{{ $item->discountPackage() ?? '' }}"
                data-package-discount-cents="{{ (int) $item->packageDiscountCents() }}"
                data-item-id="{{ $item->id }}"
                data-price="{{ (int) $item->price }}"
                data-fixed-cents="{{ $selectable ? 0 : (int) $item->total }}"
                data-included="{{ $included ? '1' : '0' }}">
                @if($optional)
                    <span class="addon-badge">Optional</span>
                @elseif($showGroupBadge)
                    <span class="addon-badge">Choose one</span>
                @endif
                <div class="amount">
                    @if($selectable && $packageOff > 0)
                        <span class="amount-now">${{ number_format($displayPrice / 100, 2) }}</span>
                        <s class="amount-was">${{ number_format(((int) $item->price) / 100, 2) }}</s>
                    @else
                        ${{ number_format(($selectable ? $item->price : $item->total) / 100, 2) }}
                    @endif
                </div>
                <div class="item-heading">
                    @if($selectable)
                    <span class="item-switch">
                        <input type="checkbox" class="optional-toggle selectable-toggle" value="{{ $item->id }}" autocomplete="off" data-default-on="{{ $included ? '1' : '0' }}" {{ $included ? 'checked' : '' }} aria-label="{{ $grouped ? 'Select' : 'Add' }} {{ $item->publicDisplayName() }}">
                        <span class="item-switch-track" aria-hidden="true"></span>
                    </span>
                    @endif
                    <div class="item-title">
                        {{ $item->publicDisplayName() }}
                    </div>
                </div>
                @if($item->description)
                    <div class="description" x-apple-data-detectors="false">{{ $item->description }}</div>
                @endif
            </{{ $selectable ? 'label' : 'div' }}>
            @endforeach
        </div>
        @if($invoice->paid_status !== 'PAID')
        <script>
            window.invoicePackage = (function () {
                const money = (cents) => (cents / 100).toLocaleString('en-US', { style: 'currency', currency: 'USD' });
                const rowOn = (row) => {
                    const box = row.querySelector('.selectable-toggle');
                    return box ? !!box.checked : true;
                };
                const packageComplete = (key) => {
                    if (!key) return false;
                    const members = Array.from(document.querySelectorAll('.item-row[data-discount-package="' + key + '"]'));
                    return members.length >= 2 && members.every(rowOn);
                };
                const rowBillCents = (row) => {
                    const selectable = row.dataset.optional === '1' || row.dataset.grouped === '1';
                    const on = selectable ? rowOn(row) : true;
                    const price = selectable ? Number(row.dataset.price || 0) : Number(row.dataset.fixedCents || 0);
                    if (selectable && !on) {
                        return { bill: 0, display: price, original: price, off: 0 };
                    }
                    const off = packageComplete(row.dataset.discountPackage || '')
                        ? Number(row.dataset.packageDiscountCents || 0)
                        : 0;
                    const billed = Math.max(0, price - Math.min(Math.max(0, off), price));
                    return { bill: billed, display: billed, original: price, off: billed === price ? 0 : off };
                };
                const paintAmounts = () => {
                    document.querySelectorAll('.item-row').forEach((row) => {
                        const { display, original, off } = rowBillCents(row);
                        const amountEl = row.querySelector('.amount');
                        if (!amountEl) return;
                        if (off > 0) {
                            amountEl.innerHTML = '<span class="amount-now">' + money(display) + '</span><s class="amount-was">' + money(original) + '</s>';
                        } else {
                            amountEl.textContent = money(original);
                        }
                    });
                };
                return { money, rowOn, packageComplete, rowBillCents, paintAmounts };
            })();
        </script>
        <script>
            (function () {
                const rows = document.querySelectorAll('.item-row[data-grouped="1"]');
                if (!rows.length) return;

                const peers = (group) =>
                    document.querySelectorAll('.item-row[data-group="' + group + '"] .selectable-toggle');

                const paintGroupRows = () => {
                    rows.forEach((row) => {
                        const on = !!row.querySelector('.selectable-toggle')?.checked;
                        row.classList.toggle('item-row--off', !on);
                    });
                };

                const firstByGroup = {};
                rows.forEach((row) => {
                    const group = row.dataset.group;
                    const box = row.querySelector('.selectable-toggle');
                    if (!group || !box) return;
                    const isFirst = !firstByGroup[group];
                    firstByGroup[group] = firstByGroup[group] || box;
                    box.checked = isFirst;
                    box.defaultChecked = isFirst;
                });
                paintGroupRows();

                rows.forEach((row) => {
                    const box = row.querySelector('.selectable-toggle');
                    if (!box) return;
                    box.addEventListener('change', () => {
                        const group = row.dataset.group;
                        if (!group) return;
                        const boxes = peers(group);
                        if (box.checked) {
                            boxes.forEach((other) => {
                                if (other !== box) other.checked = false;
                            });
                        } else if (![...boxes].some((el) => el.checked)) {
                            box.checked = true;
                        }
                        paintGroupRows();
                        if (window.invoicePackage) window.invoicePackage.paintAmounts();
                    });
                });
            })();
        </script>
        <script>
            (function () {
                if (!window.invoicePackage) return;
                window.invoicePackage.paintAmounts();
                document.querySelectorAll('.selectable-toggle').forEach((box) => {
                    box.addEventListener('change', () => window.invoicePackage.paintAmounts());
                });
            })();
        </script>
        @endif

        @if($invoice->tax > 0)
        <div class="totals">
            <div class="row">
                <span>Tax</span>
                <span>${{ number_format($invoice->tax / 100, 2) }}</span>
            </div>
        </div>
        @endif

        @if($invoice->notes)
        <div style="margin-top: 40px; padding: 20px; background: #f9f9f9; border-radius: 6px;">
            <h3 style="font-size: 14px; text-transform: uppercase; color: #999; margin-bottom: 8px;">Notes</h3>
            <p style="color: #666;">{{ $invoice->notes }}</p>
        </div>
        @endif

        @if($invoice->paid_status !== 'PAID' && config('services.stripe.key'))
        <div id="payment-element"></div>
        <p id="pay-error" class="pay-error" hidden></p>
        <button type="button" id="pay-cta" class="pay-cta"></button>

        @if(env('VENMO_HANDLE'))
        <div style="margin-top: 16px; text-align: center;">
            <a id="venmo-pay" href="https://venmo.com/?txn=pay&recipients={{ env('VENMO_HANDLE') }}&amount={{ number_format($invoice->total / 100, 2) }}&note=Invoice+{{ $invoice->invoice_number }}"
               target="_blank"
               style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; background: #008CFF; color: #fff; font-size: 15px; font-weight: 600; text-decoration: none; border-radius: 8px;">
                <svg width="18" height="18" viewBox="0 0 300 300" fill="white"><path d="M232.3 30c9.5 15.7 13.8 31.9 13.8 52.1 0 64.9-55.4 149.3-100.4 208.5H57.4L18 38.5l80.2-7.6 22.1 88.7c20.6-37.8 46-97.4 46-137.3 0-22.1-3.8-37.2-9.7-49.3L232.3 30z"/></svg>
                Pay with Venmo
            </a>
        </div>
        @endif

        <script>
            (function () {
                const stripeKey = @json(config('services.stripe.key'));
                const invoiceHash = @json($invoice->unique_hash);
                const csrf = @json(csrf_token());
                const customerEmail = @json($invoice->customer->email ?: null);
                const customerName = @json($invoice->customer->contact_name ?: $invoice->customer->name ?: null);
                const taxCents = {{ (int) $invoice->tax }};
                const discountCents = {{ (int) ($invoice->discount_val ?? 0) }};
                const hasOptional = {{ !empty($hasOptionalItems) ? 'true' : 'false' }};
                const money = (cents) => (cents / 100).toLocaleString('en-US', { style: 'currency', currency: 'USD' });

                const selectedOptionalIds = () =>
                    Array.from(document.querySelectorAll('.selectable-toggle:checked')).map((el) => Number(el.value));

                const rowToggle = (row) => row.querySelector('.selectable-toggle');
                const rowSelectable = (row) => row.dataset.optional === '1' || row.dataset.grouped === '1';

                const liveCents = () => {
                    let subtotal = 0;
                    document.querySelectorAll('.item-row').forEach((row) => {
                        if (window.invoicePackage) {
                            subtotal += window.invoicePackage.rowBillCents(row).bill;
                            return;
                        }
                        if (rowSelectable(row)) {
                            const on = rowToggle(row)?.checked;
                            subtotal += on ? Number(row.dataset.price || 0) : 0;
                        } else {
                            subtotal += Number(row.dataset.fixedCents || 0);
                        }
                    });
                    return Math.max(0, subtotal - discountCents + taxCents);
                };

                const paintTotals = () => {
                    const total = liveCents();
                    let subtotal = 0;
                    document.querySelectorAll('.item-row').forEach((row) => {
                        const selectable = rowSelectable(row);
                        const on = selectable ? !!rowToggle(row)?.checked : true;
                        const billed = window.invoicePackage
                            ? window.invoicePackage.rowBillCents(row)
                            : { bill: selectable && !on ? 0 : (selectable ? Number(row.dataset.price || 0) : Number(row.dataset.fixedCents || 0)) };
                        subtotal += billed.bill;
                        const qtyEl = row.querySelector('.item-qty');
                        if (selectable && qtyEl) qtyEl.textContent = on ? '1' : '0';
                        row.classList.toggle('item-row--off', selectable && !on);
                    });
                    if (window.invoicePackage) window.invoicePackage.paintAmounts();
                    const due = document.getElementById('amount-due');
                    const sub = document.getElementById('subtotal-display');
                    const tot = document.getElementById('total-display');
                    if (due) due.textContent = money(total);
                    if (sub) sub.textContent = money(subtotal);
                    if (tot) tot.textContent = money(total);
                    const venmo = document.getElementById('venmo-pay');
                    if (venmo) {
                        const url = new URL(venmo.href);
                        url.searchParams.set('amount', (total / 100).toFixed(2));
                        venmo.href = url.toString();
                    }
                    return total;
                };

                let stripe = null;
                let elements = null;
                let paymentElement = null;
                let paymentReady = { type: null, complete: false };
                let ignoreConfirmUntil = 0;
                const payBtn = document.getElementById('pay-cta');
                const mountEl = document.getElementById('payment-element');
                const errorEl = document.getElementById('pay-error');
                const returnUrl = window.location.origin + '/invoices/' + invoiceHash + '?payment=success';

                const showError = (msg) => {
                    if (!errorEl) return;
                    if (msg) {
                        errorEl.textContent = msg;
                        errorEl.hidden = false;
                    } else {
                        errorEl.textContent = '';
                        errorEl.hidden = true;
                    }
                };

                const labelPay = () => {
                    if (payBtn) {
                        payBtn.classList.add('is-visible');
                        payBtn.disabled = false;
                        payBtn.textContent = 'Pay ' + money(liveCents());
                    }
                };

                const destroyCheckout = () => {
                    if (paymentElement) {
                        try { paymentElement.unmount(); } catch (e) {}
                        paymentElement = null;
                    }
                    elements = null;
                    paymentReady = { type: null, complete: false };
                    ignoreConfirmUntil = 0;
                    if (mountEl) mountEl.innerHTML = '';
                    showError('');
                    labelPay();
                };

                const mountCheckout = async () => {
                    if (!stripeKey || !mountEl) return false;
                    if (!stripe) stripe = Stripe(stripeKey);
                    if (paymentElement) {
                        try { paymentElement.unmount(); } catch (e) {}
                        paymentElement = null;
                    }
                    elements = null;
                    mountEl.innerHTML = '';
                    showError('');
                    if (payBtn) {
                        payBtn.disabled = true;
                        payBtn.textContent = 'Loading payment…';
                    }
                    const res = await fetch('/api/invoices/' + invoiceHash + '/payment-intent', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({ optional_item_ids: selectedOptionalIds() }),
                    });
                    const data = await res.json();
                    if (data.error || !data.clientSecret) {
                        console.error('Payment init error:', data.error);
                        destroyCheckout();
                        showError(data.error || 'Unable to start payment.');
                        return false;
                    }
                    elements = stripe.elements({
                        clientSecret: data.clientSecret,
                        appearance: {
                            theme: 'stripe',
                            variables: {
                                colorPrimary: '#c026d3',
                                colorBackground: '#ffffff',
                                colorText: '#333333',
                                fontFamily: 'Mozilla Text, system-ui, sans-serif',
                                borderRadius: '8px',
                            },
                        },
                    });
                    const billingDetails = {};
                    if (customerEmail) billingDetails.email = customerEmail;
                    if (customerName) billingDetails.name = customerName;
                    paymentElement = elements.create('payment', {
                        defaultValues: { billingDetails },
                    });
                    paymentElement.on('change', (event) => {
                        const nextType = event.value && event.value.type ? event.value.type : null;
                        if (nextType !== paymentReady.type) {
                            // Card fields collapse when switching to Apple Pay; the
                            // same tap can land on Pay and confirm the empty card.
                            ignoreConfirmUntil = Date.now() + 500;
                            showError('');
                        }
                        paymentReady = { type: nextType, complete: !!event.complete };
                    });
                    paymentElement.mount('#payment-element');
                    labelPay();
                    return true;
                };

                const confirmPay = async () => {
                    if (!elements) {
                        await mountCheckout();
                        return;
                    }
                    if (Date.now() < ignoreConfirmUntil) {
                        return;
                    }
                    showError('');
                    if (payBtn) {
                        payBtn.disabled = true;
                        payBtn.textContent = 'Processing…';
                    }
                    const { error } = await stripe.confirmPayment({
                        elements,
                        confirmParams: { return_url: returnUrl },
                    });
                    if (error) {
                        const switchedAwayFromCard = paymentReady.type && paymentReady.type !== 'card';
                        const cardIncomplete = error.type === 'validation_error'
                            && /card details/i.test(error.message || '');
                        if (switchedAwayFromCard && cardIncomplete) {
                            showError('');
                        } else {
                            showError(error.message || 'Payment failed.');
                        }
                        labelPay();
                    }
                };

                document.querySelectorAll('.selectable-toggle').forEach((box) => {
                    box.addEventListener('change', () => {
                        const row = box.closest('.item-row');
                        const group = row && row.dataset.group;
                        if (group) {
                            const peers = document.querySelectorAll('.item-row[data-group="' + group + '"] .selectable-toggle');
                            if (box.checked) {
                                peers.forEach((other) => {
                                    if (other !== box) other.checked = false;
                                });
                            } else if (![...peers].some((el) => el.checked)) {
                                box.checked = true;
                            }
                        }
                        paintTotals();
                        destroyCheckout();
                    });
                });

                paintTotals();

                if (payBtn) {
                    payBtn.addEventListener('click', confirmPay);
                }

                if (hasOptional) {
                    labelPay();
                } else {
                    mountCheckout();
                }
            })();
        </script>
        @endif

        @if(env('VENMO_HANDLE'))
        <div style="margin-top: 20px; text-align: center;">
            <a href="https://venmo.com/?txn=pay&recipients={{ env('VENMO_HANDLE') }}&amount={{ number_format($invoice->total / 100, 2) }}&note=Invoice+{{ $invoice->invoice_number }}" class="btn btn-secondary" target="_blank" style="background: #008CFF; color: white; border-color: #008CFF;">
                💙 Pay with Venmo
            </a>
        </div>
        @endif

        @if(env('CASHAPP_HANDLE'))
        <div style="margin-top: 20px; text-align: center;">
            <a href="https://cash.app/${{ env('CASHAPP_HANDLE') }}/{{ number_format($invoice->total / 100, 2) }}" class="btn btn-secondary" target="_blank" style="background: linear-gradient(135deg, #FFDF00 0%, #EFEFEF 100%); color: black; border: 2px solid #000;">
                💵 Pay with Cash App
            </a>
        </div>
        @endif

        @if(env('PAYPAL_EMAIL'))
        <div style="margin-top: 20px; text-align: center;">
            <a href="https://www.paypal.com/paypalme/{{ env('PAYPAL_EMAIL') }}/{{ number_format($invoice->total / 100, 2) }}" class="btn btn-secondary" target="_blank" style="background: #003087; color: white;">
                🅿️ Pay with PayPal
            </a>
        </div>
        @endif

        @php
            $pdfReady = $invoice->paid_status === 'PAID'
                || ! $invoice->hasCustomerSelectableItems();
        @endphp
        @if($pdfReady)
        <div style="margin-top: 20px; text-align: center;">
            <a href="{{ url("/invoices/pdf/{$invoice->unique_hash}") }}" class="btn btn-secondary btn-pdf" target="_blank">
                {{-- Lucide file-text — PDF download --}}
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                Download PDF
            </a>
        </div>
        @else
        <p class="pdf-hint">A PDF receipt will be available after payment.</p>
        @endif

        <div class="footer">
            <p>Thank you for your business!</p>
            <p style="margin-top: 8px;">{{ $invoice->company->name }}</p>
            @if($invoice->company->website)
            <p style="margin-top: 4px;"><a href="{{ $invoice->company->website }}" target="_blank" style="color: #667eea;">{{ $invoice->company->website }}</a></p>
            @endif
            <p style="margin-top: 8px;">
                <span class="foot-tag" aria-label="Baked in Boston">
                    Baked in B<span class="foot-bean foot-bean--a" aria-hidden="true"><svg class="foot-bean-icon" viewBox="0 0 11 14" focusable="false" aria-hidden="true"><path d="M5.8 1.1c2.9-.1 4.6 2.2 4.5 5.3-.1 3.1-2 5.9-4.4 6.3-1.4.3-2.6-.4-3-1.6-.35-.95-.15-2.05.55-2.35.65-.25 1.15.75.95 2.05-.35 2.2-2.25 1.35-2.75-1.05C1.15 7.35 1.55 3.95 3.25 2.25 4.15 1.35 5 1.1 5.8 1.1Z" fill="currentColor"></path><path d="M3.45 3.35q.55 3.65.95 7.35" fill="none" stroke="currentColor" stroke-width=".7" stroke-linecap="round" opacity=".4"></path></svg></span>st<span class="foot-bean foot-bean--b" aria-hidden="true"><svg class="foot-bean-icon" viewBox="0 0 11 14" focusable="false" aria-hidden="true"><path d="M5.8 1.1c2.9-.1 4.6 2.2 4.5 5.3-.1 3.1-2 5.9-4.4 6.3-1.4.3-2.6-.4-3-1.6-.35-.95-.15-2.05.55-2.35.65-.25 1.15.75.95 2.05-.35 2.2-2.25 1.35-2.75-1.05C1.15 7.35 1.55 3.95 3.25 2.25 4.15 1.35 5 1.1 5.8 1.1Z" fill="currentColor"></path><path d="M3.45 3.35q.55 3.65.95 7.35" fill="none" stroke="currentColor" stroke-width=".7" stroke-linecap="round" opacity=".4"></path></svg></span>n
                </span>
            </p>
        </div>
    </div>

    <!-- All Invoices Section -->
    @if($customerInvoices && $customerInvoices->count() > 0)
    <div class="container" style="margin-top: 20px;">
        <div style="padding: 20px; background: #f9f9f9; border-radius: 8px;">
            <h3 style="font-size: 18px; margin-bottom: 16px; color: #333;">All Open Invoices</h3>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="text-align: left; padding: 12px 0; border-bottom: 2px solid #eee; font-size: 12px; text-transform: uppercase; color: #999;">Invoice #</th>
                        <th style="text-align: left; padding: 12px 0; border-bottom: 2px solid #eee; font-size: 12px; text-transform: uppercase; color: #999;">Date</th>
                        <th style="text-align: left; padding: 12px 0; border-bottom: 2px solid #eee; font-size: 12px; text-transform: uppercase; color: #999;">Due</th>
                        <th style="text-align: right; padding: 12px 0; border-bottom: 2px solid #eee; font-size: 12px; text-transform: uppercase; color: #999;">Amount</th>
                        <th style="text-align: center; padding: 12px 0; border-bottom: 2px solid #eee; font-size: 12px; text-transform: uppercase; color: #999;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($customerInvoices as $inv)
                    <tr>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f5f5f5;">
                            @if($inv->unique_hash === $invoice->unique_hash)
                            <strong>{{ $inv->invoice_number }}</strong>
                            @else
                            <a href="{{ url("/invoices/{$inv->unique_hash}") }}" style="color: #667eea; font-weight: 500;">{{ $inv->invoice_number }}</a>
                            @endif
                        </td>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f5f5f5; color: #666;">{{ $inv->formattedInvoiceDate }}</td>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f5f5f5; color: #666;">{{ $inv->formattedDueDate }}</td>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f5f5f5; text-align: right; font-weight: 500;">${{ number_format($inv->total / 100, 2) }}</td>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f5f5f5; text-align: center;">
                            <span class="status {{ strtolower($inv->paid_status === 'PAID' ? 'paid' : $inv->status) }}" style="display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase;">
                                {{ $inv->paid_status === 'PAID' ? 'PAID' : $inv->status }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif


</body>
</html>
