{{--
    THE FREE GIFTS BOX IN AN EMAIL (Lane FGE). The owner, 24 October: "i want
    the gift box (opened) on the thank you page, in the emails too, same
    design ... heading should be same from the checkout page for gift box with
    same outlined". The checkout's box is a <fieldset> whose <legend> sits on
    the border; email clients do not draw that, so the same look is built from
    table cells: the top border runs in two short pieces either side of the
    heading, each piece a div whose top edge sits on the heading's middle line,
    and the body carries the other three sides. 1.5px #2e8f62, 12px corners,
    the heading green and bold -- the checkout's values. Always open: one row
    per gift, the photo, the brand in small capitals, "N× " and the name, and
    FREE on the right.

    $lines  list of ['img' => absolute URL|null, 'brand', 'name', 'qty'] --
            KitOrder::lines()' shape, so an order's gift lines pass straight in
    $note   optional plain line under the gifts (the basket reminder's
            "depending on availability")
    $added  true on a successful order's emails: the heading says the gifts
            were added to the order and the line that they arrive with it
            (texts email_added / email_added_note)
    Every word is escaped; the heading and FREE are the owner's texts
    (Marketing -> Free gifts -> Display), in the email's language.
--}}
@if (($lines ?? []) !== [])
@php $gbFg = app(\App\Services\FreeGifts::class); $gbG = '#2e8f62'; @endphp
<tr><td class="px" style="padding:18px 32px 6px;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr>
<td width="14" valign="top" style="width:14px;"><div style="margin-top:11px;height:11px;border-top:1.5px solid {{ $gbG }};border-left:1.5px solid {{ $gbG }};border-top-left-radius:12px;font-size:0;line-height:0;">&nbsp;</div></td>
<td width="1" style="white-space:nowrap;padding:0 7px;font-family:{!! $k['sans'] !!};font-size:15px;line-height:22px;font-weight:700;color:{{ $gbG }};">&#127873;&nbsp;{{ ($added ?? false) ? $gbFg->text('email_added') : $gbFg->text('title') }}</td>
<td valign="top"><div style="margin-top:11px;height:11px;border-top:1.5px solid {{ $gbG }};border-right:1.5px solid {{ $gbG }};border-top-right-radius:12px;font-size:0;line-height:0;">&nbsp;</div></td>
</tr>
<tr><td colspan="3" style="border:1.5px solid {{ $gbG }};border-top:0;border-radius:0 0 12px 12px;padding:2px 14px 12px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">@foreach ($lines as $line)
<tr><td style="padding:7px 0;{{ $loop->first ? '' : 'border-top:1px solid #E4EEE9;' }}"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="52" valign="middle" style="width:52px;">@if (! empty($line['img']))<img src="{{ $line['img'] }}" width="44" height="44" alt="{{ $line['name'] }}" style="display:block;width:44px;height:44px;border-radius:8px;border:1px solid #EEE7EA;background:#ffffff;">@else<div style="width:44px;height:44px;border-radius:8px;background:#FFF0F4;font-size:0;line-height:0;">&nbsp;</div>@endif</td>
<td valign="middle" style="font-family:{!! $k['sans'] !!};">@if (($line['brand'] ?? '') !== '')<div class="muted" style="font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#8C828A;">{{ $line['brand'] }}</div>@endif<div class="ink" style="font-size:13.5px;line-height:1.35;color:{{ $k['text'] }};">@if ((int) ($line['qty'] ?? 1) > 1)<b>{{ (int) $line['qty'] }}&times;&nbsp;</b>@endif{!! \App\Services\Mail\Kit\KitRtl::auto($line['name']) !!}</div></td>
<td width="64" align="right" valign="middle" style="width:64px;padding-left:12px;font-family:{!! $k['sans'] !!};font-size:12px;font-weight:800;letter-spacing:.04em;color:{{ $gbG }};white-space:nowrap;">{{ $gbFg->text('free') }}</td>
</tr></table></td></tr>@endforeach
@php $note = ($added ?? false) ? $gbFg->text('email_added_note') : ($note ?? ''); @endphp
@if ($note !== '')<tr><td style="padding:8px 0 0;border-top:1px dashed {{ $gbG }};font-family:{!! $k['sans'] !!};font-size:12px;line-height:1.5;color:#5F6B65;">{{ $note }}</td></tr>@endif
</table></td></tr>
</table></td></tr>
@endif
