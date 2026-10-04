{{--
    Columns 2 / 3 (Lane MK): side by side on a laptop, stacked on a phone
    (the kit's .stack class). Each cell: an optional picture, a title, a
    line of text (an HtmlString from Blocks::marks), a link. Skincare tips
    fills these from the latest journal posts at send time.
--}}
@php $cw = intdiv(100, max(1, count($cols['items']))); @endphp
<tr><td class="px" style="padding:14px 24px 0;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>@foreach ($cols['items'] as $cell)<td class="stack" width="{{ $cw }}%" valign="top" style="width:{{ $cw }}%;padding:8px;font-family:{!! $k['sans'] !!};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="soft" bgcolor="#FFF8F5" style="background:#FFF8F5;border-radius:14px;"><tr><td style="padding:12px;">
@if ($cell['img'] !== null)@if ($cell['href'] !== null)<a href="{{ $cell['href'] }}">@endif<img src="{{ $cell['img'] }}" width="240" alt="{{ $cell['title'] }}" style="display:block;width:100%;max-width:240px;height:auto;border-radius:10px;margin:0 0 10px;">@if ($cell['href'] !== null)</a>@endif @endif
@if ($cell['title'] !== '')<div class="ink" style="font-size:15px;line-height:1.35;font-weight:700;color:{{ $k['text'] }};">{{ $cell['title'] }}</div>@endif
@if ($cell['plain'] !== '')<div class="ink2" style="margin-top:6px;font-size:13.5px;line-height:1.6;color:#5E545A;">{{ $cell['html'] }}</div>@endif
@if ($cell['href'] !== null && $cols['cta'] !== '')<div style="margin-top:10px;"><a href="{{ $cell['href'] }}" style="font-size:13px;font-weight:700;color:#C13E63;text-decoration:underline;">{{ $cols['cta'] }}</a></div>@endif
</td></tr></table></td>@endforeach</tr></table></td></tr>
