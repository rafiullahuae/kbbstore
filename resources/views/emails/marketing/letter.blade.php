{{--
    A marketing campaign as a personal letter — Lane EP, "Personal letter
    style (best chance for the Primary tab)". App\Services\Marketing\
    PersonalLetter says what it is for and what it leaves out.

    Deliberately plain: no wordmark, no card, no banner, no button, no web
    font and no <style> block. One column of text at most 560px wide, in the
    system font, the way a message typed in a mail app arrives. The footer is
    small grey text with ONE link, "Unsubscribe".

    THE VIEW PRINTS ONLY WHAT CampaignRenderer::renderLetter() HANDS IT: each
    paragraph is an HtmlString from Blocks::marks() (escaped parts, links
    already scheme-checked by Blocks::safeUrl()), everything else through
    {{ }}. $link is a PersonalLetter constant.

    $markers (the builder's preview only) wraps each block's rows in a
    <tbody data-mkb="n"> so the builder can select a block, as the other looks do.
--}}
@php
    $epOpen = static fn (int $i) => $markers ? '<tbody data-mkb="' . $i . '">' : '';
    $epClose = $markers ? '</tbody>' : '';
    $epFont = 'font-family:Arial,Helvetica,sans-serif;';
    $epP = $epFont . 'margin:0;padding:0 0 16px;font-size:15px;line-height:1.6;color:#222222;';
    $epLast = null;
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#ffffff;">
@if ($preheader !== '')<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">{{ $preheader }}</div>@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:560px;" dir="{{ $dir }}">
<tr><td style="{!! $epP !!}padding:20px 18px 16px;">{{ $hi }}</td></tr>
@foreach ($rows as $row)
@if ($epLast !== $row['i']){!! $epLast !== null ? $epClose : '' !!}{!! $epOpen($row['i']) !!}@php $epLast = $row['i']; @endphp @endif
@if ($row['type'] === 'p')
<tr><td style="{!! $epP !!}padding:0 18px 16px;">@if (! empty($row['bold']))<b>{{ $row['html'] }}</b>@else{{ $row['html'] }}@endif</td></tr>
@elseif ($row['type'] === 'link')
<tr><td style="{!! $epP !!}padding:0 18px 16px;"><a href="{{ $row['href'] }}" style="color:{!! $link !!};text-decoration:underline;">{{ $row['label'] }}</a></td></tr>
@elseif ($row['type'] === 'img')
<tr><td style="padding:0 18px 16px;"><img src="{{ $row['src'] }}" width="{{ $row['w'] }}"@if ($row['h'] !== null) height="{{ $row['h'] }}"@endif alt="{{ $row['alt'] }}" style="display:block;width:{{ $row['w'] }}px;max-width:100%;height:auto;border:0;border-radius:6px;"></td></tr>
@endif
@endforeach
{!! $epLast !== null ? $epClose : '' !!}
<tr><td style="{!! $epP !!}padding:0 18px 16px;">{{ $reply }}</td></tr>
<tr><td style="{!! $epP !!}padding:0 18px 8px;">{{ $sign }}@foreach ($by as $line)<br>{{ $line }}@endforeach</td></tr>
{!! $footer !== null ? $epOpen($footer) : '' !!}
<tr><td style="{!! $epFont !!}padding:24px 18px 24px;font-size:12px;line-height:1.6;color:#777777;">
<div style="border-top:1px solid #e6e6e6;padding-top:12px;">{{ $why }}@foreach ($addresses as $address)<br>{{ $address }}@endforeach
@if ($unsubscribe)<br>{{ $unsubLead }} <a href="{{ $unsubscribe }}" style="color:#777777;text-decoration:underline;">{{ $unsubWord }}</a>@endif</div>
</td></tr>
{!! $footer !== null ? $epClose : '' !!}
</table>
</body>
</html>
