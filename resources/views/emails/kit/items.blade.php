{{--
    rj-email-kit.cjs items(): product lines WITH pictures.

    $lines  list of [
              'img'     => absolute URL or null (MailKit::image()),
              'brand', 'name', 'variant'   plain text,
              'qty'     => int,
              'unit', 'total'               plain text or the shop's money HtmlString,
              'sub'     => list of plain lines (what is in a set), usually [],
            ]
    $showPrice  false drops the price column and the "each" figure

    The picture is 64px square, declared in both attributes and style so an
    image-blocked client keeps the row's shape. With no picture the same
    square is drawn as a soft block rather than a broken image.
--}}
<tr><td class="px" style="padding:0 32px;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">@foreach ($lines as $line)<tr><td style="padding:12px 0;{{ $loop->first ? '' : 'border-top:1px solid #F0E4E9;' }}" class="line">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="76" valign="top" style="width:76px;">@if (! empty($line['img']))<img class="pimg" src="{{ $line['img'] }}" width="64" height="64" alt="{{ $line['name'] }}" style="display:block;width:64px;height:64px;border-radius:10px;background:#FFF0F4;">@else<div class="pimg" style="width:64px;height:64px;border-radius:10px;background:#FFF0F4;font-size:0;line-height:0;">&nbsp;</div>@endif</td>
<td valign="top" style="font-family:{!! $k['sans'] !!};">
@if (($line['brand'] ?? '') !== '')<div class="muted" style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#C13E63;font-weight:700;">{{ $line['brand'] }}</div>
@endif<div class="ink" style="margin-top:2px;font-size:14.5px;line-height:1.35;font-weight:600;color:#2A2228;">{{ $line['name'] }}</div>
@foreach ($line['sub'] ?? [] as $subLine)<div class="muted" style="margin-top:2px;font-size:12px;line-height:1.45;color:#8C828A;">{{ $subLine }}</div>
@endforeach<div class="muted" style="margin-top:3px;font-size:12.5px;color:#8C828A;">@if (($line['variant'] ?? '') !== ''){{ $line['variant'] }} &middot; @endif{{ __('email.kit.qty', ['count' => (int) $line['qty']]) }}@if (($showPrice ?? true) && (int) $line['qty'] > 1) &middot; {{ $line['unit'] }} {{ __('email.items.each') }}@endif</div>
</td>
@if ($showPrice ?? true)<td width="96" align="right" valign="top" class="ink" style="width:96px;font-family:{!! $k['sans'] !!};font-size:14.5px;font-weight:700;color:#2A2228;white-space:nowrap;">{{ $line['total'] }}</td>@endif
</tr></table></td></tr>@endforeach</table></td></tr>
