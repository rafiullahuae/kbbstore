{{--
    Product row (Lane MK): one product per line — picture left, brand, name and
    price beside it, the button at the end. The compact sibling of the kit's
    product grid, for "three quick picks" under a message. Same card colours
    and button as emails/kit/product-grid; $products are ProductFill::cards()
    with hrefs the renderer mapped.
--}}
<tr><td class="px" style="padding:8px 24px 0;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">@foreach ($products as $p)
<tr><td style="padding:6px 8px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="soft" bgcolor="#FFF8F5" style="background:#FFF8F5;border-radius:14px;"><tr>
<td width="104" valign="middle" style="width:104px;padding:10px 0 10px 10px;">@if (! empty($p['img']))<a href="{{ $p['href'] }}"><img class="pimg" src="{{ $p['img'] }}" width="94" height="94" alt="{{ $p['name'] }}" style="display:block;width:94px;height:94px;border-radius:10px;"></a>@endif</td>
<td valign="middle" style="padding:10px 14px;font-family:{!! $k['sans'] !!};">@if (($p['brand'] ?? '') !== '')<div style="font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:#C13E63;font-weight:700;">{{ $p['brand'] }}</div>@endif
<div class="ink" style="margin-top:3px;font-size:14px;line-height:1.35;font-weight:600;color:{{ $k['text'] }};">{{ $p['name'] }}</div>
<div class="ink" style="margin-top:5px;font-size:14.5px;font-weight:800;color:{{ $k['text'] }};">{{ $p['price'] }}@if (! empty($p['was'])) <span style="font-size:12px;font-weight:500;color:#8C828A;text-decoration:line-through;">{{ $p['was'] }}</span>@endif</div>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 0;"><tr><td bgcolor="{{ $k['text'] }}" style="background:{{ $k['text'] }};border-radius:99px;"><a href="{{ $p['href'] }}" style="display:inline-block;padding:7px 16px;font-size:12px;font-weight:700;color:#FFFFFF;">{{ $cta }}</a></td></tr></table>
</td></tr></table></td></tr>@endforeach</table></td></tr>
