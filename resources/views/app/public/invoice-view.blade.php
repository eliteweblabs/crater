<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    
    <!-- OG Meta Tags for Social Sharing - Uses Client's Logo! -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="Invoice {{ $invoice->invoice_number }} - ${{ number_format($invoice->total / 100, 2) }}">
    <meta property="og:description" content="{{ $invoice->customer->name }} - Due {{ $invoice->formattedDueDate }}">
    @if($invoice->customer->avatar)
    <meta property="og:image" content="{{ $invoice->customer->avatar }}">
    @elseif($invoice->company && $invoice->company->logo)
    <meta property="og:image" content="{{ asset('uploads/'.$invoice->company->logo) }}">
    @endif
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="{{ $invoice->customer->name }}">
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Invoice {{ $invoice->invoice_number }}">
    <meta name="twitter:description" content="{{ $invoice->customer->name }} - Due {{ $invoice->formattedDueDate }}">

    @if($invoice->paid_status !== 'PAID' && config('services.stripe.key'))
    <script src="https://js.stripe.com/v3/"></script>
    @endif

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; line-height: 1.6; }
        .container { max-width: 800px; margin: 40px auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; padding-bottom: 20px; border-bottom: 2px solid #eee; }
        .logo img { max-width: 200px; height: auto; }
        .invoice-info { text-align: right; }
        .invoice-info h1 { font-size: 32px; font-weight: 300; color: #666; margin-bottom: 8px; }
        .invoice-info .number { font-size: 18px; color: #999; }
        .status { display: inline-block; padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: 600; text-transform: uppercase; margin-top: 8px; }
        .status.sent { background: #e3f2fd; color: #1976d2; }
        .status.paid { background: #e8f5e9; color: #388e3c; }
        .status.overdue { background: #ffebee; color: #d32f2f; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 40px; }
        .party h3 { font-size: 12px; text-transform: uppercase; color: #999; margin-bottom: 8px; font-weight: 600; }
        .party p { margin: 4px 0; }
        .items { margin: 40px 0; }
        .items table { width: 100%; border-collapse: collapse; }
        .items th { text-align: left; padding: 12px 0; border-bottom: 2px solid #eee; font-size: 12px; text-transform: uppercase; color: #999; font-weight: 600; }
        .items td { padding: 16px 0; border-bottom: 1px solid #f5f5f5; }
        .items .description { color: #666; font-size: 14px; }
        .items .amount { text-align: right; }
        .totals { margin-left: auto; width: 300px; margin-top: 20px; }
        .totals .row { display: flex; justify-content: space-between; padding: 8px 0; }
        .totals .row.total { font-size: 20px; font-weight: 600; padding-top: 16px; border-top: 2px solid #eee; margin-top: 8px; }
        .payment-section { margin-top: 40px; padding: 30px; background: #f9f9f9; border-radius: 8px; }
        .payment-section h2 { font-size: 20px; margin-bottom: 20px; color: #333; }
        #payment-element { margin-bottom: 20px; }
        .btn { display: inline-block; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-weight: 600; text-align: center; cursor: pointer; border: none; font-size: 16px; transition: all 0.2s; width: 100%; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-secondary { background: white; color: #666; border: 1px solid #ddd; margin-top: 12px; }
        .btn-secondary:hover { border-color: #999; color: #333; }
        .error-message { color: #d32f2f; margin-top: 12px; font-size: 14px; }
        .success-message { background: #e8f5e9; border: 1px solid #388e3c; color: #2e7d32; padding: 16px; border-radius: 6px; margin-bottom: 20px; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #eee; color: #999; font-size: 14px; text-align: center; }
        @media (max-width: 640px) {
            .container { padding: 20px; margin: 20px; }
            .header { flex-direction: column; gap: 20px; }
            .invoice-info { text-align: left; }
            .parties { grid-template-columns: 1fr; gap: 20px; }
            .totals { width: 100%; }
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

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 40px; font-size: 14px;">
            <div>
                <p><strong>Invoice Date:</strong> {{ $invoice->formattedInvoiceDate }}</p>
                <p><strong>Due Date:</strong> {{ $invoice->formattedDueDate }}</p>
            </div>
            <div>
                <p><strong>Amount Due:</strong> <span style="font-size: 18px; font-weight: 600; color: #667eea;">${{ number_format($invoice->total / 100, 2) }}</span></p>
            </div>
        </div>

        <div class="items">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style="text-align: center; width: 80px;">Qty</th>
                        <th style="text-align: right; width: 100px;">Rate</th>
                        <th style="text-align: right; width: 120px;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoice->items as $item)
                    <tr>
                        <td>
                            <div style="font-weight: 500;">{{ $item->name }}</div>
                            @if($item->description)
                                <div class="description">{{ $item->description }}</div>
                            @endif
                        </td>
                        <td style="text-align: center;">{{ $item->quantity }}</td>
                        <td style="text-align: right;">${{ number_format($item->price / 100, 2) }}</td>
                        <td class="amount">${{ number_format($item->total / 100, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="totals">
            <div class="row">
                <span>Subtotal</span>
                <span>${{ number_format($invoice->sub_total / 100, 2) }}</span>
            </div>
            @if($invoice->tax > 0)
            <div class="row">
                <span>Tax</span>
                <span>${{ number_format($invoice->tax / 100, 2) }}</span>
            </div>
            @endif
            <div class="row total">
                <span>Total</span>
                <span>${{ number_format($invoice->total / 100, 2) }}</span>
            </div>
        </div>

        @if($invoice->notes)
        <div style="margin-top: 40px; padding: 20px; background: #f9f9f9; border-radius: 6px;">
            <h3 style="font-size: 14px; text-transform: uppercase; color: #999; margin-bottom: 8px;">Notes</h3>
            <p style="color: #666;">{{ $invoice->notes }}</p>
        </div>
        @endif

        @if($invoice->paid_status !== 'PAID' && config('services.stripe.key'))
        <div id="stripe-checkout" style="margin-top: 40px;"></div>

        @if(env('VENMO_HANDLE'))
        <div style="margin-top: 16px; text-align: center;">
            <a href="https://venmo.com/?txn=pay&recipients={{ env('VENMO_HANDLE') }}&amount={{ number_format($invoice->total / 100, 2) }}&note=Invoice+{{ $invoice->invoice_number }}"
               target="_blank"
               style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; background: #008CFF; color: #fff; font-size: 15px; font-weight: 600; text-decoration: none; border-radius: 8px;">
                <svg width="18" height="18" viewBox="0 0 300 300" fill="white"><path d="M232.3 30c9.5 15.7 13.8 31.9 13.8 52.1 0 64.9-55.4 149.3-100.4 208.5H57.4L18 38.5l80.2-7.6 22.1 88.7c20.6-37.8 46-97.4 46-137.3 0-22.1-3.8-37.2-9.7-49.3L232.3 30z"/></svg>
                Pay with Venmo
            </a>
        </div>
        @endif

        <script>
            (async () => {
                const stripe = Stripe('{{ config("services.stripe.key") }}');

                const checkout = await stripe.initEmbeddedCheckout({
                    fetchClientSecret: async () => {
                        const res = await fetch(
                            '/api/invoices/{{ $invoice->unique_hash }}/checkout-session',
                            {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                },
                            }
                        );
                        const data = await res.json();
                        if (data.error) {
                            console.error('Payment init error:', data.error);
                            return null;
                        }
                        return data.clientSecret;
                    },
                });

                checkout.mount('#stripe-checkout');
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
            <a href="{{ url("/invoices/pdf/{$invoice->unique_hash}") }}" class="btn btn-secondary" target="_blank">
                📄 Download PDF
            </a>
        </div>

        <div class="footer">
            <p>Thank you for your business!</p>
            <p style="margin-top: 8px;">{{ $invoice->company->name }}</p>
            @if($invoice->company->website)
            <p style="margin-top: 4px;"><a href="{{ $invoice->company->website }}" target="_blank" style="color: #667eea;">{{ $invoice->company->website }}</a></p>
            @endif
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

    @if($invoice->paid_status !== 'PAID' && config('services.stripe.key'))
    {{-- Shown when Apple Pay is unavailable in the current browser --}}
    <div id="apple-pay-hint" style="display:none; margin-top:16px; padding:12px 16px; background:#f5f5f7; border-radius:8px; text-align:center;">
        <span style="font-size:13px; color:#666;">
            🍎 Want to pay with Apple Pay?
            <strong style="color:#333;">Open this invoice in Safari</strong> on your iPhone, iPad, or Mac.
        </span>
    </div>

    {{-- Sticky Apple Pay button — only renders on devices that support it --}}
    <div id="apple-pay-bar" style="display:none; position:fixed; bottom:0; left:0; right:0; padding:12px 16px 20px; background:#fff; box-shadow:0 -2px 12px rgba(0,0,0,0.12); z-index:9999;">
        <div id="apple-pay-btn" style="height:55px; border-radius:12px; overflow:hidden;"></div>
    </div>

    <script>
    (async () => {
        const stripe   = Stripe('{{ config("services.stripe.key") }}');
        const elements = stripe.elements();

        const pr = stripe.paymentRequest({
            country: 'US',
            currency: '{{ strtolower($invoice->currency->code ?? "usd") }}',
            total: {
                label: 'Invoice #{{ $invoice->invoice_number }}',
                amount: {{ (int)$invoice->due_amount }},
            },
            requestPayerName:  true,
            requestPayerEmail: true,
        });

        const btn = elements.create('paymentRequestButton', {
            paymentRequest: pr,
            style: { paymentRequestButton: { type: 'buy', theme: 'dark', height: '55px' } },
        });

        const result = await pr.canMakePayment();
        if (result && result.applePay) {
            btn.mount('#apple-pay-btn');
            document.getElementById('apple-pay-bar').style.display = 'block';
            document.body.style.paddingBottom = '100px';
        } else {
            document.getElementById('apple-pay-hint').style.display = 'block';
        }

        pr.on('paymentmethod', async (ev) => {
            const res = await fetch('/api/invoices/{{ $invoice->unique_hash }}/payment-intent', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            });
            const { clientSecret, error } = await res.json();

            if (error) { ev.complete('fail'); return; }

            const { error: confirmError } = await stripe.confirmCardPayment(
                clientSecret,
                { payment_method: ev.paymentMethod.id },
                { handleActions: false }
            );

            if (confirmError) {
                ev.complete('fail');
            } else {
                ev.complete('success');
                window.location.href = '/invoices/{{ $invoice->unique_hash }}?payment=success';
            }
        });
    })();
    </script>
    @endif

</body>
</html>
