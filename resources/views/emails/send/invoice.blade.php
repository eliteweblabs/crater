@component('mail::message')
{{-- Header with company name --}}
# Invoice from {{ $data['company']['name'] }}

{!! $data['body'] !!}

@if(!$data['attach']['data'])
@component('mail::button', ['url' => $data['url']])
View Invoice
@endcomponent
@endif

@if($data['invoice']['paid_status'] !== 'PAID')
@component('mail::button', ['url' => url('/invoices/pdf/' . $data['invoice']['unique_hash']), 'color' => 'success'])
Pay Invoice
@endcomponent
@endif

Thanks,<br>
{{ $data['company']['name'] }}
@endcomponent
