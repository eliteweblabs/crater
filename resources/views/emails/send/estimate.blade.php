@component('mail::message')
{{-- Header with company name --}}
# Estimate from {{ $data['company']['name'] }}

{!! $data['body'] !!}

@if(!$data['attach']['data'])
@component('mail::button', ['url' => $data['url']])
View Estimate
@endcomponent
@endif

Thanks,<br>
{{ $data['company']['name'] }}
@endcomponent

