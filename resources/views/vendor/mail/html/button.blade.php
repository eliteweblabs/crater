{{--
  Invoice CTAs (color=success) use company brand_primary → brand_secondary
  from reΛVe /api/branding/colors. Keep the gradient on the <td>, not the <a>
  — Laravel's border-as-padding button otherwise paints only behind the words.
--}}
@php
    $color = $color ?? 'primary';
    $isReaveCta = $color === 'success';
    $brand = $isReaveCta ? \Crater\Support\ReaveBrandColors::fetch() : null;
@endphp
<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
    <tr>
        <td align="center">
            <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tr>
                    <td align="center">
                        <table border="0" cellpadding="0" cellspacing="0" role="presentation">
                            <tr>
                                @if ($isReaveCta && $brand)
                                <td align="center" bgcolor="{{ $brand['primary'] }}" style="border-radius: 999px; background-color: {{ $brand['primary'] }}; background-image: {{ $brand['gradient'] }}; box-shadow: {{ $brand['shadow'] }};">
                                    <a href="{{ $url }}" target="_blank" style="display: inline-block; padding: 12px 24px; border-radius: 999px; color: #ffffff; font-size: 15px; font-weight: 600; letter-spacing: -0.01em; line-height: 1; text-decoration: none;">{{ $slot }}</a>
                                </td>
                                @else
                                <td>
                                    <a href="{{ $url }}" class="button button-{{ $color }}" target="_blank">{{ $slot }}</a>
                                </td>
                                @endif
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
