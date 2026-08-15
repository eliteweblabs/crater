<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php
        $clientName = $invoice->customer->name ?: 'client';
        $pageTitle = $invoice->paid_status === 'PAID'
            ? 'Invoice for '.$clientName
            : 'New invoice for '.$clientName;
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

    @if($invoice->paid_status !== 'PAID' && config('services.stripe.key'))
    <script src="https://js.stripe.com/v3/"></script>
    @endif

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { overflow-x: hidden; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; line-height: 1.6; }
        .container { max-width: 800px; margin: 40px auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: visible; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; padding-bottom: 20px; border-bottom: 2px solid #eee; }
        .logo img { max-width: 200px; height: auto; }
        .invoice-info { text-align: right; }
        .invoice-info h1 { font-size: 32px; font-weight: 300; color: #666; margin-bottom: 8px; }
        .invoice-info .number { font-size: 18px; color: #999; }
        .status { display: inline-block; padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: 600; text-transform: uppercase; margin-top: 8px; }
        .status.sent { background: #e3f2fd; color: #1976d2; }
        .status.paid { background: #e8f5e9; color: #388e3c; }
        .status.overdue { background: #ffebee; color: #d32f2f; }
        .meta-dates { margin-bottom: 40px; font-size: 14px; }
        .meta-dates p { margin: 0; line-height: 1.7; white-space: nowrap; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 40px; }
        .party h3 { font-size: 12px; text-transform: uppercase; color: #999; margin-bottom: 8px; font-weight: 600; }
        .party p { margin: 4px 0; }
        .items { margin: 40px -40px 20px; overflow: visible; }
        .item-row { position: relative; overflow: visible; padding: 16px 40px; border-bottom: 1px solid #eee; }
        .item-row--optional { background: #faf7ff; }
        .item-row--off { opacity: 0.55; }
        .item-switch { float: left; position: relative; display: inline-flex; cursor: pointer; margin: 3px 12px 8px 0; }
        .items .item-title { font-weight: 500; }
        .items .description { color: #666; font-size: 14px; }
        .items .amount { float: right; margin: 0 0 8px 12px; font-weight: 500; white-space: nowrap; }
        .item-switch input { position: absolute; inset: 0; z-index: 1; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
        .item-switch-track {
            position: relative;
            display: block;
            width: 44px;
            height: 26px;
            border-radius: 999px;
            background: #d4d0db;
            box-shadow: inset 0 0 0 1px rgba(11, 5, 18, 0.08);
            transition: background 0.18s ease, box-shadow 0.18s ease;
        }
        .item-switch-track::after {
            content: '';
            position: absolute;
            top: 3px;
            left: 3px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 1px 4px rgba(11, 5, 18, 0.28);
            transition: transform 0.18s ease;
        }
        .item-switch input:checked + .item-switch-track {
            background: linear-gradient(145deg, #f472b6 0%, #c026d3 52%, #6366f1 100%);
            box-shadow: 0 2px 10px rgba(192, 38, 211, 0.35);
        }
        .item-switch input:checked + .item-switch-track::after {
            transform: translateX(18px);
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
        .pay-cta { display: none; margin: 24px 0 0; width: 100%; padding: 14px 28px; border: none; border-radius: 999px; font-size: 16px; font-weight: 600; letter-spacing: -0.01em; color: #ffffff; cursor: pointer; background: linear-gradient(145deg, #f472b6 0%, #c026d3 52%, #6366f1 100%); box-shadow: 0 2px 16px rgba(192, 38, 211, 0.35); }
        .pay-cta.is-visible { display: block; }
        .pay-cta:disabled { opacity: 0.6; cursor: not-allowed; }
        .payment-section { margin-top: 40px; padding: 30px; background: #f9f9f9; border-radius: 8px; }
        .payment-section h2 { font-size: 20px; margin-bottom: 20px; color: #333; }
        #payment-element { margin-bottom: 20px; }
        .btn { display: inline-block; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-weight: 600; text-align: center; cursor: pointer; border: none; font-size: 16px; transition: all 0.2s; width: 100%; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-secondary { background: white; color: #666; border: 1px solid #ddd; margin-top: 12px; }
        .btn-secondary:hover { border-color: #999; color: #333; }
        .btn-pdf { border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: auto; padding: 12px 22px; }
        .btn-pdf svg { flex: none; }
        .foot-tag {
            letter-spacing: 0.02em;
            display: inline-flex;
            align-items: center;
            gap: 0;
            line-height: 1;
            font-size: 12px;
            color: #bbb;
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
        @media (max-width: 640px) {
            .container { padding: 20px; margin: 20px; }
            .header { flex-direction: column; gap: 20px; }
            .invoice-info { text-align: left; }
            .parties { grid-template-columns: 1fr; gap: 20px; }
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
                <h1>INVOICE</h1>
                <div class="number">{{ $invoice->invoice_number }}</div>
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

        <div class="items">
            @foreach($invoice->items as $item)
            @php
                $optional = $item->isOptional();
                $included = ! $optional || (float) $item->quantity > 0;
            @endphp
            <div class="item-row {{ $optional ? 'item-row--optional' : '' }} {{ $optional && ! $included ? 'item-row--off' : '' }}"
                data-optional="{{ $optional ? '1' : '0' }}"
                data-item-id="{{ $item->id }}"
                data-price="{{ (int) $item->price }}"
                data-fixed-cents="{{ $optional ? 0 : (int) $item->total }}"
                data-included="{{ $included ? '1' : '0' }}">
                @if($optional)
                <label class="item-switch">
                    <input type="checkbox" class="optional-toggle" value="{{ $item->id }}" autocomplete="off" {{ $included ? 'checked' : '' }} aria-label="Add {{ $item->publicDisplayName() }}">
                    <span class="item-switch-track" aria-hidden="true"></span>
                </label>
                @endif
                @if($optional)
                    <span class="addon-badge">Optional</span>
                @endif
                <div class="amount">${{ number_format(($included || ! $optional ? $item->total : 0) / 100, 2) }}</div>
                <div class="item-title">
                    {{ $item->publicDisplayName() }}
                </div>
                @if($item->description)
                    <div class="description">{{ $item->description }}</div>
                @endif
            </div>
            @endforeach
        </div>

        <div class="totals">
            @if($invoice->tax > 0)
            <div class="row">
                <span>Tax</span>
                <span>${{ number_format($invoice->tax / 100, 2) }}</span>
            </div>
            @endif
            <div class="row total">
                <span>Total</span>
                <span id="total-display">${{ number_format($invoice->total / 100, 2) }}</span>
            </div>
        </div>

        @if($invoice->notes)
        <div style="margin-top: 40px; padding: 20px; background: #f9f9f9; border-radius: 6px;">
            <h3 style="font-size: 14px; text-transform: uppercase; color: #999; margin-bottom: 8px;">Notes</h3>
            <p style="color: #666;">{{ $invoice->notes }}</p>
        </div>
        @endif

        @if($invoice->paid_status !== 'PAID' && config('services.stripe.key'))
        <button type="button" id="pay-cta" class="pay-cta"></button>
        <div id="stripe-checkout" style="margin-top: 24px;"></div>

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
                const taxCents = {{ (int) $invoice->tax }};
                const discountCents = {{ (int) ($invoice->discount_val ?? 0) }};
                const hasOptional = {{ !empty($hasOptionalItems) ? 'true' : 'false' }};
                const money = (cents) => (cents / 100).toLocaleString('en-US', { style: 'currency', currency: 'USD' });

                const selectedOptionalIds = () =>
                    Array.from(document.querySelectorAll('.optional-toggle:checked')).map((el) => Number(el.value));

                const liveCents = () => {
                    let subtotal = 0;
                    document.querySelectorAll('.item-row').forEach((row) => {
                        if (row.dataset.optional === '1') {
                            const on = row.querySelector('.optional-toggle')?.checked;
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
                        const optional = row.dataset.optional === '1';
                        const on = optional ? !!row.querySelector('.optional-toggle')?.checked : true;
                        const cents = optional ? (on ? Number(row.dataset.price || 0) : 0) : Number(row.dataset.fixedCents || 0);
                        if (optional) subtotal += cents;
                        else subtotal += cents;
                        const amountEl = row.querySelector('.amount');
                        const qtyEl = row.querySelector('.item-qty');
                        if (amountEl) amountEl.textContent = money(cents);
                        if (optional && qtyEl) qtyEl.textContent = on ? '1' : '0';
                        row.classList.toggle('item-row--off', optional && !on);
                    });
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

                let checkout = null;
                let stripe = null;
                const payBtn = document.getElementById('pay-cta');
                const mountEl = document.getElementById('stripe-checkout');

                const destroyCheckout = () => {
                    if (checkout) {
                        try { checkout.destroy(); } catch (e) {}
                        checkout = null;
                    }
                    if (mountEl) mountEl.innerHTML = '';
                    if (payBtn) {
                        payBtn.classList.add('is-visible');
                        payBtn.disabled = false;
                        payBtn.textContent = 'Pay ' + money(liveCents());
                    }
                };

                const mountCheckout = async () => {
                    if (!stripeKey || !mountEl) return;
                    if (!stripe) stripe = Stripe(stripeKey);
                    if (checkout) {
                        try { checkout.destroy(); } catch (e) {}
                        checkout = null;
                    }
                    if (payBtn) {
                        payBtn.disabled = true;
                        payBtn.textContent = 'Loading payment…';
                    }
                    checkout = await stripe.initEmbeddedCheckout({
                        fetchClientSecret: async () => {
                            const res = await fetch('/api/invoices/' + invoiceHash + '/checkout-session', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': csrf,
                                },
                                body: JSON.stringify({ optional_item_ids: selectedOptionalIds() }),
                            });
                            const data = await res.json();
                            if (data.error) {
                                console.error('Payment init error:', data.error);
                                destroyCheckout();
                                return null;
                            }
                            return data.clientSecret;
                        },
                    });
                    checkout.mount('#stripe-checkout');
                    if (payBtn) {
                        payBtn.classList.remove('is-visible');
                    }
                };

                document.querySelectorAll('.optional-toggle').forEach((box) => {
                    box.addEventListener('change', () => {
                        paintTotals();
                        destroyCheckout();
                    });
                });

                paintTotals();

                if (hasOptional) {
                    if (payBtn) {
                        payBtn.classList.add('is-visible');
                        payBtn.textContent = 'Pay ' + money(liveCents());
                        payBtn.addEventListener('click', mountCheckout);
                    }
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

        <div style="margin-top: 20px; text-align: center;">
            <a href="{{ url("/invoices/pdf/{$invoice->unique_hash}") }}" class="btn btn-secondary btn-pdf" target="_blank">
                {{-- Lucide file-text — PDF download --}}
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                Download PDF
            </a>
        </div>

        <div class="footer">
            <p>Thank you for your business!</p>
            <p style="margin-top: 8px;">{{ $invoice->company->name }}</p>
            @if($invoice->company->website)
            <p style="margin-top: 4px;"><a href="{{ $invoice->company->website }}" target="_blank" style="color: #667eea;">{{ $invoice->company->website }}</a></p>
            @endif
            <p style="margin-top: 16px;">
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
