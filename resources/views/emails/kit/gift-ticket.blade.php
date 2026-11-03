{{--
    THE GIFT OFFER IN AN EMAIL -- design A "Golden ticket" (Lane GO), the
    owner's final choice of 3 November (docs/plans/gift-offers/
    abandoned-email-designs.html, words 1, eyebrow "ADDITIONAL GIFT FOR YOU").
    Drawn at the top of the abandoned-cart email, before the bag, and at the
    top of "Your items" in both pending-order reminders. The shop's green free
    gifts box is untouched and stays where it was.

    $o  GiftOffers::emailView(): plain strings (escaped here) and URLs that
        method built. Absent -- module off, place off, no range, nothing in
        stock or allocation -- and this partial is never included at all.

    Dark mode: the gt-* classes are redrawn by the kit head (emails.kit.doc,
    only when this block is in the email) under prefers-color-scheme and the
    [data-ogsc]/[data-ogsb] hooks Outlook.com uses. Every image declares its
    width, height and alt; the moving clock is a GIF whose alt is the end time,
    so an image-blocked client still reads when the gift ends.
--}}
@php($goRtl = \App\Support\Locale::isRtl())
<tr><td class="px" style="padding:22px 32px 4px;font-family:{!! $k['sans'] !!};"><table role="presentation" class="gt-card" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FFF7EC;border:1.5px dashed #E2BE7E;border-radius:16px;"><tr><td align="center" style="padding:20px 20px 22px;font-family:{!! $k['sans'] !!};">
<div class="gt-gold" style="font-size:11px;font-weight:700;letter-spacing:.14em;color:#B08433;">&#10022; {{ $o['eyebrow'] }} &#10022;</div>
<div class="h1 gt-ink" style="margin-top:6px;font-size:26px;line-height:1.2;font-weight:800;color:#2A2228;">{{ $o['title'] }}</div>
<div class="gt-soft" style="margin-top:6px;font-size:14px;line-height:1.55;color:#6A5E64;">{{ $o['body'] }}</div>
<table role="presentation" class="gt-in" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:16px;background:#FFFFFF;border-radius:14px;border:1px solid #F1E2C8;"><tr>
<td width="96" style="width:96px;padding:12px;">@if (! empty($o['img']))<img src="{{ $o['img'] }}" width="84" height="84" alt="{{ $o['name'] }}" style="display:block;width:84px;height:84px;border-radius:12px;">@else<div style="width:84px;height:84px;border-radius:12px;background:#FFF0F4;font-size:0;line-height:0;">&nbsp;</div>@endif</td>
<td valign="middle" style="padding:12px {{ $goRtl ? '0 12px 12px' : '12px 12px 0' }};text-align:{{ $goRtl ? 'right' : 'left' }};font-family:{!! $k['sans'] !!};">@if ($o['brand'] !== '')<div class="gt-soft" style="font-size:11px;font-weight:700;letter-spacing:.08em;color:#8C828A;">{{ mb_strtoupper($o['brand']) }}</div>@endif<div class="gt-ink" style="margin:3px 0 7px;font-size:14.5px;line-height:1.35;font-weight:600;color:#2A2228;">{{ $o['name'] }}</div><span style="display:inline-block;padding:3px 9px;margin:0 4px 4px 0;border-radius:99px;background:#FBE3EA;color:#C13E63;font-size:11px;font-weight:700;letter-spacing:.04em;white-space:nowrap;">{{ $o['chips'][0] }}</span><span style="display:inline-block;padding:3px 9px;margin:0 4px 4px 0;border-radius:99px;background:#F6EBD7;color:#8A6420;font-size:11px;font-weight:700;letter-spacing:.04em;white-space:nowrap;">{{ $o['chips'][1] }}</span><span style="display:inline-block;padding:3px 9px;margin:0 4px 4px 0;border-radius:99px;background:#EFEAE6;color:#5E545A;font-size:11px;font-weight:700;letter-spacing:.04em;white-space:nowrap;">{{ $o['chips'][2] }}</span></td></tr></table>
<div class="gt-soft" style="margin-top:14px;font-size:12.5px;color:#8C828A;">{{ $o['clock_label'] }}</div>
<div style="margin-top:4px;">@if (! empty($o['clock']))<img src="{{ $o['clock'] }}" width="{{ $o['clock_w'] }}" height="{{ $o['clock_h'] }}" alt="{{ $o['held'] }}" style="display:inline-block;width:{{ $o['clock_w'] }}px;height:{{ $o['clock_h'] }}px;border:0;">@else<span class="gt-pink" style="font-size:21px;font-weight:700;color:#C13E63;">{{ $o['held'] }}</span>@endif</div>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:16px auto 0;"><tr><td align="center" bgcolor="#C13E63" style="background:#C13E63;border-radius:99px;"><a href="{{ $o['href'] }}" style="display:inline-block;padding:14px 30px;font-family:{!! $k['sans'] !!};font-size:15px;font-weight:700;color:#FFFFFF;text-decoration:none;">{{ $o['button'] }} {!! $goRtl ? '&larr;' : '&rarr;' !!}</a></td></tr></table>
<div class="gt-soft" style="margin-top:10px;font-size:12px;color:#A0959B;">{{ $o['worth'] }}</div>
</td></tr></table></td></tr>
