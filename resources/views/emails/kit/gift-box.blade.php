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
    $cta    optional closing line at the very bottom, green and bold (the
            basket reminder's "Don't miss out on your FREE GIFTS!", text basket_cta)
    $added  true on a successful order's emails: the heading says the gifts
            were added to the order and the line that they arrive with it
            (texts email_added / email_added_note)
    Every word is escaped; the heading and FREE are the owner's texts
    (Marketing -> Free gifts -> Display), in the email's language.
--}}
@if (($lines ?? []) !== [])
@php
    $gbFg = app(\App\Services\FreeGifts::class);
    $gbG = '#2e8f62';
    // Written left to right like every other kit block: an Arabic email is
    // mirrored as a whole by KitRtl::mirror() (dir="rtl" tables, left/right
    // swapped in every style and align, the one-sided corner radii included).
    $gbStart = 'left';
    $gbEnd = 'right';
    // One corner piece: a 22px cell split in two 11px rows, the lower one
    // carrying the top border and the side border, so the line meets the
    // heading's middle. Plain table cells and heights, which Outlook keeps
    // (it squares the corner; every other client rounds it).
    $gbCorner = static fn (string $side): string => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td height="11" style="height:11px;font-size:0;line-height:0;">&nbsp;</td></tr><tr><td height="11" style="height:11px;border-top:1.5px solid #2e8f62;border-' . $side . ':1.5px solid #2e8f62;border-top-' . $side . '-radius:12px;font-size:0;line-height:0;">&nbsp;</td></tr></table>';
@endphp
<tr><td class="px" style="padding:18px 32px 6px;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr>
<td width="14" valign="top" style="width:14px;">{!! $gbCorner($gbStart) !!}</td>
<td width="1" style="white-space:nowrap;padding:0 7px;font-family:{!! $k['sans'] !!};font-size:15px;line-height:22px;font-weight:700;color:{{ $gbG }};">&#127873;&nbsp;{{ ($added ?? false) ? $gbFg->text('email_added') : $gbFg->text('title') }}</td>
<td valign="top">{!! $gbCorner($gbEnd) !!}</td>
</tr>
<tr><td colspan="3" style="border:1.5px solid {{ $gbG }};border-top:0;border-radius:0 0 12px 12px;padding:2px 14px 12px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">@foreach ($lines as $line)
<tr><td style="padding:7px 0;{{ $loop->first ? '' : 'border-top:1px solid #E4EEE9;' }}"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="52" valign="middle" style="width:52px;">@if (! empty($line['img']))<img src="{{ $line['img'] }}" width="44" height="44" alt="{{ $line['name'] }}" style="display:block;width:44px;height:44px;border-radius:8px;border:1px solid #EEE7EA;background:#ffffff;">@else<div style="width:44px;height:44px;border-radius:8px;background:#FFF0F4;font-size:0;line-height:0;">&nbsp;</div>@endif</td>
<td valign="middle" style="font-family:{!! $k['sans'] !!};">@if (($line['brand'] ?? '') !== '')<div class="muted" style="font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#8C828A;">{{ $line['brand'] }}</div>@endif<div class="ink" style="font-size:13.5px;line-height:1.35;color:{{ $k['text'] }};">@if ((int) ($line['qty'] ?? 1) > 1)<b>{{ (int) $line['qty'] }}&times;&nbsp;</b>@endif{!! \App\Services\Mail\Kit\KitRtl::auto($line['name']) !!}</div></td>
<td width="64" align="{{ $gbEnd }}" valign="middle" style="width:64px;padding-{{ $gbStart }}:12px;font-family:{!! $k['sans'] !!};font-size:12px;font-weight:800;letter-spacing:.04em;color:{{ $gbG }};white-space:nowrap;">{{ $gbFg->text('free') }}</td>
</tr></table></td></tr>@endforeach
@php $note = ($added ?? false) ? $gbFg->text('email_added_note') : ($note ?? ''); @endphp
@if ($note !== '')<tr><td style="padding:8px 0 0;border-top:1px dashed {{ $gbG }};font-family:{!! $k['sans'] !!};font-size:12px;line-height:1.5;color:#5F6B65;">{{ $note }}</td></tr>@endif
@if (($cta ?? '') !== '')<tr><td style="padding:{{ $note !== '' ? '6px' : '8px' }} 0 0;{{ $note !== '' ? '' : 'border-top:1px dashed ' . $gbG . ';' }}font-family:{!! $k['sans'] !!};font-size:13.5px;line-height:1.45;font-weight:700;color:{{ $gbG }};">{{ $cta }}</td></tr>@endif
</table></td></tr>
</table></td></tr>
@endif
