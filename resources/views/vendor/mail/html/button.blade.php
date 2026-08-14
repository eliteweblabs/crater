{{--
  Invoice CTAs (color=success) use the REΛVE site pill:
  linear-gradient(145deg, #f472b6, #c026d3, #6366f1) + white label.
  Keep the gradient on the <td>, not the <a> — Laravel's border-as-padding
  button otherwise paints the gradient only behind the words.
--}}
@php
    $color = $color ?? 'primary';
    $isReaveCta = $color === 'success';
@endphp
<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
    <tr>
        <td align="center">
            <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tr>
                    <td align="center">
                        <table border="0" cellpadding="0" cellspacing="0" role="presentation">
                            <tr>
                                @if ($isReaveCta)
                                <td align="center" bgcolor="#c026d3" style="border-radius: 999px; background-color: #c026d3; background-image: linear-gradient(145deg, #f472b6 0%, #c026d3 52%, #6366f1 100%); box-shadow: 0 2px 16px rgba(192, 38, 211, 0.35);">
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
