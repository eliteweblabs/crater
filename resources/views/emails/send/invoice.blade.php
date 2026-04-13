@component('mail::message')
{{-- Header with company name --}}
# Invoice from {{ $data['company']['name'] }}

{!! $data['body'] !!}

@component('mail::button', ['url' => $data['invoice_url'], 'color' => 'success'])
View Invoice & Pay
@endcomponent

Thanks,<br>
{{ $data['company']['name'] }}
@endcomponent
