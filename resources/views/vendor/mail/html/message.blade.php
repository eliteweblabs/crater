@component('mail::layout')
    @php
        $brandLogo = \Crater\Support\ReaveBrandColors::logoEmailUrl()
            ?: \Crater\Support\ReaveBrandColors::resolvedCompanyLogoUrl();
    @endphp
    {{-- Header --}}
    @slot('header')
        @component('mail::header', ['url' => config('app.url')])
            @if($brandLogo)
                <img src="{{ $brandLogo }}" alt="{{ config('app.name') }}" style="max-height: 50px; max-width: 200px;">
            @else
                {{ config('app.name') }}
            @endif
        @endcomponent
    @endslot

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        @slot('subcopy')
            @component('mail::subcopy')
                {{ $subcopy }}
            @endcomponent
        @endslot
    @endisset

    {{-- Footer --}}
    @slot('footer')
        @component('mail::footer')
            © {{ date('Y') }} {{ config('app.name') }}. @lang('All rights reserved.')
        @endcomponent
    @endslot
@endcomponent
