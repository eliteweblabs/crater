@component('mail::message')
{{-- Header with company name --}}
# Invoice from {{ $data['company']['name'] }}

{!! $data['body'] !!}

@if(!$data['attach']['data'])
@component('mail::button', ['url' => $data['invoice_url']])
View Invoice
@endcomponent
@endif

@if($data['invoice']['paid_status'] !== 'PAID')
@component('mail::button', ['url' => $data['invoice_url'], 'color' => 'success'])
Pay Invoice
@endcomponent
@endif

Thanks,<br>
{{ $data['company']['name'] }}
@endcomponent
