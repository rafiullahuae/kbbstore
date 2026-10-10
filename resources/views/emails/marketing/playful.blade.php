{{--
    A marketing campaign in design C, "Playful K-beauty" (Lane EC) — the look
    the owner picked from Lane ED's "New Look, Less Prices" previews
    (docs/email-new-look-options/c-playful.html) on 10 October: "this is
    finalized, but make sure for desktop and mobile both it works. and remove
    the returns words completely, we don't offer returns."

    THE SAME ROWS AS emails.marketing.campaign. CampaignRenderer builds them
    once and hands them to whichever look the campaign chose, so every block
    works in both looks and a campaign can switch look without losing a word.

    Email-safe by construction, as the preview is: tables, inline styles,
    600px, no script, no CSS grid or flex, layout that holds where a client
    ignores <style> (the media queries only fold 3 product columns to 2 and
    shrink the headline on a phone), an Outlook ghost table round the fluid
    grid, a VML button for Outlook, and dark-mode colours for the clients that
    honour prefers-color-scheme / Outlook.com's data-ogsc.

    Printed raw here, and why each is safe: colours, fonts and entities are
    EmailTheme / Blocks / MailKit constants; $row['title'] and ['lead'] are
    HtmlStrings Blocks::marks() built from escaped parts; MailKit::fontFaceCss()
    is pattern-checked. Every other value goes through {{ }} or e().

    NO RETURNS LINK: "Shipping & delivery · Privacy policy · Contact us".
--}}
@php
    $C = \App\Services\Marketing\EmailTheme::C;
    $rtl = $locale === 'ar';
    $dir = $rtl ? 'rtl' : 'ltr';
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
    $F = $rtl ? \App\Services\Marketing\EmailTheme::SANS_AR : \App\Services\Marketing\EmailTheme::SANS;
    $track = static fn (string $em) => $rtl ? '0' : $em;
    $W = static fn (string $key, array $vars = []) => \App\Services\Marketing\EmailTheme::word($locale, $key, $vars);
    $mkOpen = static fn (int $i) => $markers ? '<tbody data-mkb="' . $i . '">' : '';
    $mkClose = $markers ? '</tbody>' : '';
    $tagline = null;
    foreach ($rows as $r) { if ($r['type'] === 'mini_header' && $topbar !== null && ($r['tagline'] ?? '') !== '') { $tagline = $r['tagline']; } }
    $fontFace = \App\Services\Mail\Kit\MailKit::fontFaceCss();
    $btn = static function (array $row) use ($C, $F): string {
        $dark = (bool) ($row['dark'] ?? false);
        $ghost = (bool) ($row['ghost'] ?? false);
        $bg = $dark ? $C['text'] : ($ghost ? '#FFFFFF' : $C['pink']);
        $fg = $ghost ? $C['pink'] : '#FFFFFF';
        $size = $dark ? 15 : 16;
        $pad = $dark ? '15px 32px' : '16px 34px';
        $w = min(520, 70 + (int) ceil(mb_strlen($row['label']) * $size * 0.56));
        $h = $dark ? 49 : 52;
        $label = e($row['label']);
        $href = e($row['href']);
        $tdCls = $dark ? ' class="btn-ink"' : '';
        $aCls = $dark ? ' class="btn-ink-a"' : '';

        return '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $href . '" style="height:' . $h . 'px;v-text-anchor:middle;width:' . $w . 'px;" arcsize="50%" ' . ($ghost ? 'strokecolor="' . $C['pink'] . '" strokeweight="1.5pt"' : 'stroke="f"') . ' fillcolor="' . $bg . '"><w:anchorlock/><center style="color:' . $fg . ';font-family:Arial,sans-serif;font-size:' . $size . 'px;font-weight:bold;">' . $label . '</center></v:roundrect><![endif]-->'
            . '<!--[if !mso]><!--><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto;"><tr><td' . $tdCls . ' bgcolor="' . $bg . '" style="border-radius:999px;background:' . $bg . ';mso-padding-alt:0;">'
            . '<a href="' . $href . '"' . $aCls . ' style="display:inline-block;padding:' . $pad . ';border:1.5px solid ' . ($ghost ? $C['pink'] : $bg) . ';font-family:' . $F . ';font-size:' . $size . 'px;line-height:1.2;font-weight:700;letter-spacing:0;color:' . $fg . ';text-decoration:none;border-radius:999px;">' . $label . '</a></td></tr></table><!--<![endif]-->';
    };
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $dir }}" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no,address=no,email=no,date=no">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $kitTitle ?? '' }}</title>
@if ($fontFace !== '')
<style>{!! $fontFace !!}</style>
@endif
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><style>td,th,p,a,span,div,h1{font-family:{{ $rtl ? 'Tahoma,Arial' : 'Arial' }},sans-serif!important}</style><![endif]-->
<style>
body{margin:0!important;padding:0!important;width:100%!important;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
table{border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt}
img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic}
a{text-decoration:none}
u + #body a, #MessageViewBody a{color:inherit;text-decoration:none;font-size:inherit;font-family:inherit;font-weight:inherit;line-height:inherit}
@media only screen and (max-width:480px){
  .px{padding-left:20px!important;padding-right:20px!important}
  .stack{display:block!important;width:100%!important;max-width:100%!important;box-sizing:border-box!important}
  .hy{max-width:50%!important}
  .h1c{font-size:38px!important}
  .pimg{width:64px!important;height:64px!important}
  .gpad{padding:5px!important}
}
@media only screen and (max-width:400px){
  .bstack{display:block!important;width:100%!important;max-width:100%!important;box-sizing:border-box!important}
}
@media only screen and (max-width:360px){
  .h1c{font-size:32px!important}
  .pc-pad{padding-left:8px!important;padding-right:8px!important}
}
@media (prefers-color-scheme:dark){
  .bg-outer{background:#17111A!important}
  .card{background:#211921!important}
  .soft{background:#2E2229!important}
  .ink{color:#F7EEF2!important}
  .ink2{color:#D7C8CF!important}
  .muted{color:#A898A0!important}
  .pink{color:#F38BA8!important}
  .line{border-color:#3A2B33!important}
  .rule{background:#3A2B33!important}
  .btn-ink,.btn-ink-a{background:#F38BA8!important;border-color:#F38BA8!important}
  .btn-ink-a{color:#211921!important}
  .hl{background:#5A2238!important;color:#FFD3E0!important}
@foreach (\App\Services\Marketing\EmailTheme::TINTS_DARK as $ti => $tc)
  .t{{ $ti }}{background:{{ $tc }}!important}
@endforeach
}
[data-ogsc] .ink{color:#F7EEF2!important}
[data-ogsc] .ink2{color:#D7C8CF!important}
[data-ogsc] .muted{color:#A898A0!important}
[data-ogsc] .pink{color:#F38BA8!important}
[data-ogsb] .bg-outer{background:#17111A!important}
[data-ogsb] .card{background:#211921!important}
[data-ogsb] .soft{background:#2E2229!important}
</style>
</head>
<body id="body" class="bg-outer" style="margin:0;padding:0;background:{{ $C['bg'] }};" bgcolor="{{ $C['bg'] }}">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:{{ $C['bg'] }};opacity:0;">{{ $kitPreheader ?? '' }}{!! str_repeat('&#8199;&#847;', 40) !!}</div>
<table role="presentation" class="bg-outer" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $C['bg'] }}" style="width:100%;background:{{ $C['bg'] }};" dir="{{ $dir }}">
<tr><td align="center" style="padding:20px 10px 28px;">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
@if ($tagline !== null){!! $mkOpen($topbar) !!}<tr><td align="center" style="padding:6px 20px 16px;font-family:{!! $F !!};"><span class="pink" style="font-size:12px;font-weight:700;letter-spacing:{{ $track('.16em') }};text-transform:uppercase;color:{{ $C['pink'] }};">{{ $tagline }}</span></td></tr>{!! $mkClose !!}@endif
<tr><td class="card" bgcolor="#FFFFFF" style="background:#FFFFFF;border-radius:32px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
@foreach ($rows as $row)
{!! $mkOpen($row['i']) !!}
@switch($row['type'])
@case('mini_header')
<tr><td align="center" style="padding:28px 30px 0;"><div class="ink" dir="ltr" style="font-family:{!! \App\Services\Marketing\EmailTheme::SANS !!};font-size:20px;font-weight:800;letter-spacing:-.02em;color:{{ $C['text'] }};">{{ $k['wordmark'][0] }}@if ($k['wordmark'][1] !== '') <span class="pink" style="color:{{ $C['pink'] }};">{{ $k['wordmark'][1] }}</span>@endif</div>
@if ($row['nav'])<div style="margin-top:8px;font-family:{!! $F !!};font-size:12.5px;"><a href="{{ \App\Services\Mail\Kit\MailKit::url('/shop/') }}" class="muted" style="color:{{ $C['link'] }};">{{ $W('nav_shop') }}</a>&nbsp;&nbsp;&middot;&nbsp;&nbsp;<a href="{{ \App\Services\Mail\Kit\MailKit::url('/track-my-order/') }}" class="muted" style="color:{{ $C['link'] }};">{{ $W('nav_track') }}</a>&nbsp;&nbsp;&middot;&nbsp;&nbsp;<a href="{{ \App\Services\Mail\Kit\MailKit::url('/my-account/') }}" class="muted" style="color:{{ $C['link'] }};">{{ $W('nav_account') }}</a></div>@endif
</td></tr>
@break
@case('hero_image')
<tr><td style="padding:26px 18px 0;">@if ($row['href'] !== null)<a href="{{ $row['href'] }}" style="display:block;">@endif<img src="{{ $row['src'] }}" width="564"@if ($row['h'] !== null) height="{{ (int) round($row['h'] * 564 / 600) }}"@endif alt="{{ $row['alt'] }}" style="display:block;width:100%;max-width:564px;height:auto;border:0;border-radius:24px;background:{{ $C['bg'] }};">@if ($row['href'] !== null)</a>@endif</td></tr>
@break
@case('heading')
@if ($row['style'] === 'hero')
<tr><td align="{{ $row['align'] === 'left' ? $start : 'center' }}" class="px" style="padding:22px 34px 0;text-align:{{ $row['align'] === 'left' ? $start : 'center' }};">
@if ($row['eyebrow'] !== '')<div class="pink" style="margin:0 0 10px;font-family:{!! $F !!};font-size:12px;font-weight:700;letter-spacing:{{ $track('.16em') }};text-transform:uppercase;color:{{ $C['pink'] }};">{{ $row['eyebrow'] }}</div>@endif
<h1 class="h1c ink" style="margin:0;font-family:{!! $F !!};font-size:46px;line-height:{{ $rtl ? '1.3' : '1.08' }};font-weight:800;letter-spacing:{{ $track('-.035em') }};color:{{ $C['text'] }};">@if ($row['plainTitle'] !== ''){{ $row['title'] }}@endif @if ($row['highlight'] !== '')@if ($row['plainTitle'] !== '')<br>@endif<span class="hl" style="background:{{ $C['hl'] }};color:{{ $C['ink3'] }};padding:0 10px;border-radius:14px;">{{ $row['highlight'] }}</span>@endif</h1>
@if ($row['plainLead'] !== '')<p class="ink2" style="margin:18px {{ $row['align'] === 'left' ? '0' : 'auto' }} 0;max-width:430px;font-family:{!! $F !!};font-size:16.5px;line-height:1.65;color:{{ $C['text2'] }};">{{ $row['lead'] }}</p>@endif
</td></tr>
@elseif ($row['style'] === 'title')
<tr><td align="center" class="px" style="padding:40px 30px 6px;text-align:center;">
@if ($row['icon'] !== 'none')<div style="font-size:26px;line-height:1;">{!! \App\Services\Mail\Kit\MailKit::ICONS[$row['icon']] ?? '' !!}</div>@endif
@if ($row['plainTitle'] !== '' || $row['highlight'] !== '')<div class="ink" style="margin-top:8px;font-family:{!! $F !!};font-size:26px;font-weight:800;letter-spacing:{{ $track('-.02em') }};color:{{ $C['text'] }};">{{ $row['title'] }}@if ($row['highlight'] !== '') <span class="hl" style="background:{{ $C['hl'] }};color:{{ $C['ink3'] }};padding:0 8px;border-radius:10px;">{{ $row['highlight'] }}</span>@endif</div>@endif
@if ($row['plainLead'] !== '')<div class="muted" style="margin-top:6px;font-family:{!! $F !!};font-size:14px;line-height:1.5;color:{{ $C['muted'] }};">{{ $row['lead'] }}</div>@endif
</td></tr>
<tr><td style="padding:16px 0 0;font-size:0;line-height:0;">&nbsp;</td></tr>
@else
@include('emails.kit.section-title', ['text' => $row['plainTitle'], 'pad' => '26px 32px 0'])
@endif
@break
@case('text')
<tr><td class="px" style="padding:{{ $row['pad'] }};font-family:{!! $F !!};"><div class="ink2" style="font-size:{{ $row['size'] }}px;line-height:1.65;color:{{ $C['text2'] }};text-align:{{ $row['center'] ? 'center' : $start }};">{{ $row['html'] }}</div></td></tr>
@break
@case('button')
<tr><td align="center" style="padding:{{ $row['dark'] ? '20px' : '26px' }} 30px 0;">{!! $btn($row) !!}</td></tr>
@break
@case('product_grid')
@if ($row['title'] !== '')@include('emails.kit.section-title', ['text' => $row['title'], 'pad' => '26px 32px 0'])@endif
@if ($row['layout'] === 'playful')
@include('emails.marketing.playful-cards', ['products' => $row['products'], 'cols' => $row['cols'], 'tintClass' => true])
@else
@include('emails.kit.product-grid', ['products' => $row['products'], 'cols' => $row['cols'], 'cta' => $row['cta']])
@endif
@break
@case('product_row')
@if ($row['title'] !== '')@include('emails.kit.section-title', ['text' => $row['title'], 'pad' => '26px 32px 0'])@endif
@include('emails.marketing.product-row', ['products' => $row['products'], 'cta' => $row['cta']])
@break
@case('badges')
<tr><td class="px" style="padding:20px 24px 6px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">@foreach (array_chunk($row['items'], 2) as $bi => $pair)<tr>@foreach ($pair as $bj => $chip)@php $tint = \App\Services\Marketing\EmailTheme::CHIP_TINTS[($bi * 2 + $bj) % 4]; @endphp<td class="bstack" width="50%" valign="top" style="width:50%;padding:0 6px 12px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $tint }}" class="soft" style="background:{{ $tint }};border-radius:18px;"><tr><td width="44" valign="middle" style="padding:12px 0 12px 0;padding-{{ $start }}:14px;width:44px;font-size:22px;line-height:1;">{!! \App\Services\Marketing\Blocks::BADGE_ICONS[$chip['icon']] ?? '' !!}</td><td valign="middle" class="ink" style="padding:12px 0;padding-{{ $start }}:6px;padding-{{ $end }}:14px;font-family:{!! $F !!};font-size:13px;line-height:1.4;color:{{ $C['text'] }};text-align:{{ $start }};">@if ($chip['bold'] !== '')<b>{{ $chip['bold'] }}</b>@endif{{ $chip['sep'] }}{{ $chip['text'] }}</td></tr></table></td>@endforeach @if (count($pair) === 1)<td class="bstack" width="50%" style="width:50%;"></td>@endif</tr>@endforeach</table></td></tr>
@break
@case('coupon')
@include('emails.kit.coupon', ['code' => $row['code'], 'line' => $row['line'], 'expires' => $row['expires']])
@break
@case('image')
@include('emails.marketing.image', ['img' => $row])
@break
@case('columns')
@include('emails.marketing.columns', ['cols' => $row])
@break
@case('divider')
<tr><td class="px" style="padding:22px 32px 0;"><div class="rule" style="height:1px;line-height:1px;font-size:0;background:{{ $C['rule'] }};">&nbsp;</div></td></tr>
@break
@case('spacer')
@include('emails.kit.gap', ['h' => $row['h']])
@break
@case('social')
@include('emails.marketing.social', ['links' => $row['links']])
@break
@endswitch
{!! $mkClose !!}
@endforeach
<tr><td style="height:36px;line-height:36px;font-size:0;">&nbsp;</td></tr>
</table></td></tr>
@if ($footer !== null)
{!! $mkOpen($footer) !!}
@php
    $ftWebCopy = \App\Services\Mail\Kit\WebCopy::mint();
    $ftUnsub = \App\Services\Mail\Kit\MailKit::url($unsubscribe ?? null);
    $ftA = static fn (string $href, string $label, bool $strong = false) => '<a href="' . e($href) . '" class="muted" style="color:' . $C['link'] . ';text-decoration:' . ($strong ? 'underline' : 'none') . ';' . ($strong ? 'font-weight:600;' : '') . '">' . e($label) . '</a>';
    $ftDot = '&nbsp;&nbsp;&middot;&nbsp;&nbsp;';
@endphp
@if ($note !== '')<tr><td align="center" class="muted" style="padding:22px 20px 0;font-family:{!! $F !!};font-size:12.5px;color:{{ $C['muted'] }};">{{ $note }}</td></tr>@endif
<tr><td align="center" style="padding:30px 26px 12px;font-family:{!! $F !!};">
<div class="ink" dir="ltr" style="font-family:{!! \App\Services\Marketing\EmailTheme::SANS !!};font-size:18px;font-weight:800;letter-spacing:-.02em;color:{{ $C['text'] }};">{{ $k['wordmark'][0] }}@if ($k['wordmark'][1] !== '') <span class="pink" style="color:{{ $C['deep'] }};">{{ $k['wordmark'][1] }}</span>@endif</div>
<div class="muted" style="margin-top:4px;font-size:12px;color:{{ $C['muted'] }};">{{ $W('tagline') }}</div>
</td></tr>
<tr><td style="padding:0 60px;"><div class="rule" style="height:1px;line-height:1px;font-size:0;background:{{ $C['rule'] }};">&nbsp;</div></td></tr>
@if ($k['addresses'] !== [])
<tr><td align="center" class="muted" style="padding:14px 24px 2px;font-family:{!! $F !!};font-size:12px;line-height:1.6;color:{{ $C['muted'] }};">@foreach ($k['addresses'] as $place)<span dir="auto">{{ implode(', ', $place['lines']) }}</span>@if (! $loop->last)<br>@endif @endforeach</td></tr>
@endif
<tr><td align="center" class="muted" style="padding:8px 22px 6px;font-family:{!! $F !!};font-size:12px;line-height:2;color:{{ $C['muted'] }};">
{!! implode($ftDot, array_map(static fn (string $key, string $path) => $ftA(\App\Support\Url::external($path), $W($key)), array_keys(\App\Services\Marketing\EmailTheme::FOOTER_LINKS), \App\Services\Marketing\EmailTheme::FOOTER_LINKS)) !!}
@if ($ftUnsub !== null)<br>{!! $ftA($ftUnsub, $W('unsubscribe'), true) !!}{!! $ftDot !!}{!! $ftA($ftUnsub, $W('preferences')) !!}@endif
</td></tr>
<tr><td align="center" class="muted" style="padding:6px 34px 4px;font-family:{!! $F !!};font-size:11.5px;line-height:1.65;color:{{ $C['muted'] }};">{{ $why }}<br>{{ $W('copyright', ['year' => $k['year'], 'store' => $k['storeName']]) }}</td></tr>
@if ($ftWebCopy !== null)
<tr><td align="center" style="padding:8px 24px 6px;font-family:{!! $F !!};font-size:12px;">{!! $ftA($ftWebCopy, $W('view'), true) !!}</td></tr>
@endif
{!! $mkClose !!}
@endif
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table>
</body>
</html>
