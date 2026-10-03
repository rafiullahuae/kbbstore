{{--
    rj-email-kit.cjs productGrid(): product cards, $cols across.

    $products  list of ['img' => URL|null, 'h' => declared height at 200 wide,
               'brand', 'name' plain, 'price', 'was' (plain or money HtmlString,
               'was' may be null), 'href' => a URL the caller built]
    $cols      1 or 2;  $cta  the button label (plain)
--}}
@php
    $pgCols = max(1, min(2, (int) ($cols ?? 2)));
    $pgW = intdiv(100, $pgCols);
@endphp
<tr><td class="px" style="padding:8px 24px 0;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">@foreach (array_chunk($products, $pgCols) as $pgRow)<tr>@foreach ($pgRow as $p)<td class="gpad" width="{{ $pgW }}%" valign="top" style="width:{{ $pgW }}%;padding:8px;font-family:{!! $k['sans'] !!};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="soft" bgcolor="#FFF8F5" style="background:#FFF8F5;border-radius:14px;"><tr><td style="padding:12px;" align="center">
@if (! empty($p['img']))<img src="{{ $p['img'] }}" width="200" height="{{ (int) ($p['h'] ?? 200) }}" alt="{{ $p['name'] }}" style="display:block;width:100%;max-width:200px;height:auto;border-radius:10px;">@endif
@if (($p['brand'] ?? '') !== '')<div class="muted" style="margin-top:10px;font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:#C13E63;font-weight:700;">{{ $p['brand'] }}</div>
@endif<div class="ink" style="margin-top:3px;font-size:14px;line-height:1.35;font-weight:600;color:#2A2228;min-height:38px;">{{ $p['name'] }}</div>
@if (($p['price'] ?? '') !== '')<div style="margin-top:6px;font-size:15px;font-weight:800;color:#2A2228;" class="ink">{{ $p['price'] }}@if (! empty($p['was'])) <span style="font-size:12.5px;font-weight:500;color:#8C828A;text-decoration:line-through;">{{ $p['was'] }}</span>@endif</div>
@endif<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:10px auto 2px;"><tr><td bgcolor="#2A2228" style="background:#2A2228;border-radius:99px;"><a href="{{ $p['href'] }}" style="display:inline-block;padding:8px 18px;font-size:12.5px;font-weight:700;color:#FFFFFF;">{{ $cta }}</a></td></tr></table>
</td></tr></table></td>@endforeach</tr>@endforeach</table></td></tr>
