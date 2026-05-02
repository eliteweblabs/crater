<!DOCTYPE html>
<html>

<head>
    <title>@lang('pdf_payment_label') - {{ $payment->payment_number }}</title>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />

    <style type="text/css">
        /* -- Base -- */
        body {
            font-family: "DejaVu Sans";
        }

        html {
            margin: 0px;
            padding: 0px;
            margin-top: 50px;
        }

        .text-center {
            text-align: center;
        }

        hr {
            margin: 0 30px 0 30px;
            color: rgba(0, 0, 0, 0.2);
            border: 0.5px solid #EAF1FB;
        }

        /* -- Header -- */

        .header-bottom-divider {
            color: rgba(0, 0, 0, 0.2);
            top: 90px;
            left: 0px;
            width: 100%;
            margin-left: 0%;
        }

        .header-container {
            position: absolute;
            width: 100%;
            height: 90px;
            left: 0px;
            top: -50px;
        }

        .header-logo {
            margin-top: 20px;
            padding-bottom: 20px;
            text-transform: capitalize;
            color: #817AE3;
        }

        .content-wrapper {
            display: block;
            margin-top: 0px;
            padding-top: 16px;
            padding-bottom: 20px;
        }

        /* -- Receipt title + status badge -- */

        .receipt-title-bar {
            padding: 0 30px;
            margin-top: 18px;
            margin-bottom: 4px;
        }

        .receipt-title {
            display: inline-block;
            font-size: 18px;
            line-height: 24px;
            letter-spacing: 0.08em;
            font-weight: bold;
            color: #5851D8;
            text-transform: uppercase;
        }

        .receipt-status-badge {
            float: right;
            display: inline-block;
            padding: 6px 14px;
            font-size: 12px;
            line-height: 14px;
            letter-spacing: 0.1em;
            font-weight: bold;
            text-transform: uppercase;
            border-radius: 3px;
            border: 2px solid;
        }

        .receipt-status-badge--paid {
            color: #1F8E4F;
            border-color: #1F8E4F;
            background: #E8F8EF;
        }

        .receipt-status-badge--partial {
            color: #B86E00;
            border-color: #B86E00;
            background: #FFF6E5;
        }

        .company-address-container {
            padding-top: 15px;
            padding-left: 30px;
            float: left;
            width: 30%;
            margin-bottom: 2px;
        }

        .company-address-container h1 {
            font-size: 15px;
            line-height: 22px;
            letter-spacing: 0.05em;
            margin-bottom: 0px;
            margin-top: 10px;
        }

        .company-address {
            margin-top: 16px;
            text-align: left;
            font-size: 12px;
            line-height: 15px;
            color: #595959;
            width: 280px;
            word-wrap: break-word;
        }

        .payment-details-container {
            float: right;
            padding: 10px 30px 0 0;
            margin-top: 18px;
        }

        .attribute-label {
            font-size: 12px;
            line-height: 18px;
            padding-right: 40px;
            text-align: left;
            color: #55547A;
        }

        .attribute-value {
            font-size: 12px;
            line-height: 18px;
            text-align: right;
        }

        /* -- Billing -- */

        .billing-address-container {
            padding-top: 50px;
            float: left;
            padding-left: 30px;
        }

        .billing-address {
            font-size: 12px;
            line-height: 15px;
            color: #595959;
            padding: 45px 0px 0px 30px;
            margin: 0px;
            width: 220px;
            word-wrap: break-word;
        }

        /* -- Items Table -- */

        .items-table {
            margin-top: 35px;
            padding: 0px 30px 10px 30px;
            page-break-before: avoid;
            page-break-after: auto;
        }

        .items-table hr {
            height: 0.1px;
        }

        .item-table-heading {
            font-size: 13.5;
            text-align: center;
            color: rgba(0, 0, 0, 0.85);
            padding: 5px;
            color: #55547A;
        }

        tr.item-table-heading-row th {
            border-bottom: 0.620315px solid #E8E8E8;
            font-size: 12px;
            line-height: 18px;
        }

        tr.item-row td {
            font-size: 12px;
            line-height: 18px;
        }

        .item-cell {
            font-size: 13;
            text-align: center;
            padding: 5px;
            padding-top: 10px;
            color: #040405;
        }

        .item-description {
            color: #595959;
            font-size: 9px;
            line-height: 12px;
        }

        /* -- Total Display Table (used by items partial) -- */

        .total-display-container {
            padding: 0 25px;
        }

        .total-display-table {
            border-top: none;
            page-break-inside: avoid;
            page-break-before: auto;
            page-break-after: auto;
            margin-top: 20px;
            float: right;
            width: auto;
        }

        .total-table-attribute-label {
            font-size: 13px;
            color: #55547A;
            text-align: left;
            padding-left: 10px;
        }

        .total-table-attribute-value {
            font-weight: bold;
            text-align: right;
            font-size: 13px;
            color: #040405;
            padding-right: 10px;
            padding-top: 2px;
            padding-bottom: 2px;
        }

        .total-border-left {
            border: 1px solid #E8E8E8 !important;
            border-right: 0px !important;
            padding-top: 0px;
            padding: 8px !important;
        }

        .total-border-right {
            border: 1px solid #E8E8E8 !important;
            border-left: 0px !important;
            padding-top: 0px;
            padding: 8px !important;
        }

        /* -- Payment Summary (added below the invoice totals) -- */

        .payment-summary-container {
            padding: 0 25px;
            clear: both;
        }

        .payment-summary-table {
            page-break-inside: avoid;
            margin-top: 16px;
            float: right;
            width: auto;
            min-width: 280px;
            border-collapse: collapse;
        }

        .payment-summary-row td {
            font-size: 13px;
            padding: 6px 10px;
        }

        .payment-summary-label {
            color: #55547A;
            text-align: left;
        }

        .payment-summary-value {
            color: #1F8E4F;
            font-weight: bold;
            text-align: right;
        }

        .balance-due-row td {
            font-size: 14px;
            padding: 10px;
            border: 1px solid #E8E8E8;
            background: #F9FBFF;
        }

        .balance-due-label {
            color: #040405;
            font-weight: bold;
            text-align: left;
        }

        .balance-due-value {
            color: #5851D8;
            font-weight: bold;
            text-align: right;
        }

        /* -- Standalone receipt total box (no invoice attached) -- */

        .total-display-box {
            min-width: 315px;
            display: block;
            margin-right: 30px;
            background: #F9FBFF;
            border: 1px solid #EAF1FB;
            box-sizing: border-box;
            float: right;
            padding: 12px 15px 15px 15px;
        }

        .total-display-label {
            display: inline;
            font-weight: bold;
            font-size: 14px;
            line-height: 21px;
            color: #595959;
        }

        .total-display-box .amount {
            float: right;
            font-weight: bold;
            font-size: 14px;
            line-height: 21px;
            text-align: right;
            color: #5851D8;
            margin-left: 150px;
        }

        /* -- Notes -- */

        .notes {
            font-size: 12px;
            color: #595959;
            margin-top: 30px;
            margin-left: 30px;
            width: 442px;
            text-align: left;
            page-break-inside: avoid;
            clear: both;
        }

        .notes-label {
            font-size: 15px;
            line-height: 22px;
            letter-spacing: 0.05em;
            color: #040405;
            width: 108px;
            white-space: nowrap;
            height: 19.87px;
            padding-bottom: 10px;
        }

        /* -- Helpers -- */

        .text-primary {
            color: #5851DB;
        }

        table .text-left {
            text-align: left;
        }

        table .text-right {
            text-align: right;
        }

        .border-0 {
            border: none;
        }

        .py-2 {
            padding-top: 2px;
            padding-bottom: 2px;
        }

        .py-8 {
            padding-top: 8px;
            padding-bottom: 8px;
        }

        .py-3 {
            padding: 3px 0;
        }

        .pr-20 {
            padding-right: 20px;
        }

        .pr-10 {
            padding-right: 10px;
        }

        .pl-20 {
            padding-left: 20px;
        }

        .pl-10 {
            padding-left: 10px;
        }

        .pl-0 {
            padding-left: 0;
        }

    </style>

    @if (App::isLocale('th'))
        @include('app.pdf.locale.th')
    @endif
</head>

<body>
    <div class="header-container">
        <table width="100%">
            <tr>
                <td class="text-center">
                    @if ($logo)
                        <img class="header-logo" style="height:50px" src="{{ $logo }}" alt="Company Logo">
                    @else
                        @if ($payment->customer && $payment->customer->company)
                            <h2 class="header-logo">{{ $payment->customer->company->name }}</h2>
                        @endif
                    @endif
                </td>
            </tr>
        </table>
        <hr class="header-bottom-divider" style="border: 0.620315px solid #E8E8E8;" />
    </div>

    <div class="content-wrapper">
        <div class="receipt-title-bar">
            <span class="receipt-title">@lang('pdf_payment_receipt_label')</span>
            @if ($invoice)
                @if ($invoice->due_amount <= 0)
                    <span class="receipt-status-badge receipt-status-badge--paid">
                        @lang('pdf_paid_in_full')
                    </span>
                @else
                    <span class="receipt-status-badge receipt-status-badge--partial">
                        @lang('pdf_partial_payment')
                    </span>
                @endif
            @endif
            <div style="clear: both;"></div>
        </div>

        <div style="padding-top: 10px">
            <div class="company-address-container company-address">
                {!! $company_address !!}
            </div>

            <div class="payment-details-container">
                <table>
                    <tr>
                        <td class="attribute-label">@lang('pdf_payment_number')</td>
                        <td class="attribute-value">&nbsp;{{ $payment->payment_number }}</td>
                    </tr>
                    <tr>
                        <td class="attribute-label">@lang('pdf_payment_date')</td>
                        <td class="attribute-value">&nbsp;{{ $payment->formattedPaymentDate }}</td>
                    </tr>
                    <tr>
                        <td class="attribute-label">@lang('pdf_payment_mode')</td>
                        <td class="attribute-value">
                            &nbsp;{{ $payment->paymentMethod ? $payment->paymentMethod->name : '-' }}
                        </td>
                    </tr>
                    @if ($invoice && $invoice->invoice_number)
                        <tr>
                            <td class="attribute-label">@lang('pdf_invoice_number')</td>
                            <td class="attribute-value">&nbsp;{{ $invoice->invoice_number }}</td>
                        </tr>
                        <tr>
                            <td class="attribute-label">@lang('pdf_invoice_date')</td>
                            <td class="attribute-value">&nbsp;{{ $invoice->formattedInvoiceDate }}</td>
                        </tr>
                    @endif
                </table>
            </div>

            <div style="clear: both;"></div>
        </div>

        <div class="billing-address-container billing-address">
            @if ($billing_address)
                <b>@lang('pdf_received_from')</b> <br>
                {!! $billing_address !!}
            @endif
        </div>

        <div style="clear: both;"></div>

        @if ($invoice)
            <div style="position: relative; clear: both;">
                @include('app.pdf.invoice.partials.table')
            </div>

            <div class="payment-summary-container">
                <table cellspacing="0" border="0" class="payment-summary-table">
                    <tr class="payment-summary-row">
                        <td class="payment-summary-label">@lang('pdf_payment_amount_received_label')</td>
                        <td class="payment-summary-value">
                            -{!! format_money_pdf($payment->amount, $payment->customer->currency) !!}
                        </td>
                    </tr>
                    <tr class="balance-due-row">
                        <td class="balance-due-label">@lang('pdf_balance_due')</td>
                        <td class="balance-due-value">
                            {!! format_money_pdf($invoice->due_amount, $payment->customer->currency) !!}
                        </td>
                    </tr>
                </table>
                <div style="clear: both;"></div>
            </div>
        @else
            <div class="total-display-box">
                <p class="total-display-label">@lang('pdf_payment_amount_received_label')</p>
                <span class="amount">{!! format_money_pdf($payment->amount, $payment->customer->currency) !!}</span>
            </div>
            <div style="clear: both;"></div>
        @endif

        <div class="notes">
            @if ($notes)
                <div class="notes-label">
                    @lang('pdf_notes')
                </div>
                {!! $notes !!}
            @endif
        </div>
    </div>
</body>

</html>
