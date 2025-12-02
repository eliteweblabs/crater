@component('mail::message')
{{-- Header with company name --}}
# Payment Receipt from {{ $data['company']['name'] }}

{!! $data['body'] !!}

@if(!$data['attach']['data'])
@component('mail::button', ['url' => $data['url']])
View Payment
@endcomponent
@endif

Thanks,<br>
{{ $data['company']['name'] }}
@endcomponent
