{{--
    The builder's Columns 2 / 3 block (Lane EK): two or three cards side by
    side on a laptop, stacked on a phone (the kit's `stack` class). Each card
    a picture (MailKit::image, absolute), a title and a line, all escaped,
    and an optional link MailKit::url() checked.
    $items list of ['img' => URL|null, 'title', 'text', 'href' => URL|null]; $cols 2|3
--}}
@php $colW = intdiv(100, max(2, min(3, (int) ($cols ?? 2)))); @endphp
<tr><td class="px" style="padding:16px 24px 0;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>@foreach ($items as $it)<td class="stack" width="{{ $colW }}%" valign="top" style="width:{{ $colW }}%;padding:8px;font-family:{!! $k['sans'] !!};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="soft" bgcolor="#FFF8F5" style="background:#FFF8F5;border-radius:14px;"><tr><td style="padding:12px;" align="center">
@if (! empty($it['img']))@if (! empty($it['href']))<a href="{{ $it['href'] }}">@endif<img src="{{ $it['img'] }}" width="200" alt="{{ $it['title'] }}" style="display:block;width:100%;max-width:200px;height:auto;border-radius:10px;border:0;">@if (! empty($it['href']))</a>@endif
@endif
@if (($it['title'] ?? '') !== '')<div class="ink" style="margin-top:10px;font-size:14px;font-weight:700;color:{{ $k['text'] }};">{{ $it['title'] }}</div>@endif
@if (($it['text'] ?? '') !== '')<div class="ink2" style="margin-top:4px;font-size:13px;line-height:1.5;color:#5E545A;">{{ $it['text'] }}</div>@endif
</td></tr></table></td>@endforeach</tr></table></td></tr>
