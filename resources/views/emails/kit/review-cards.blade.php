{{--
    The review request's product cards (Lane RV): one card per product, its
    picture, brand, name and variant, and five large stars where EACH STAR IS A
    LINK to the review page with that product and that rating already chosen.
    No script — an email cannot run one — so the choice travels in the link.

    $cards    ReviewRequestMail::$products — every string plain, every URL built
              by ReviewRequests::pageUrl()/Url::external(), never a setting
    $starHex  one of ReviewRequestSettings::STAR_COLOURS (a constant #rrggbb)

    44px per star (34px glyph + padding): a thumb-sized target, and five of them
    are 220px — inside the 287px a 375px phone leaves for the card.
--}}
@php
    $rcRtl = \App\Support\Locale::isRtl();
    $rcPadText = $rcRtl ? '0 14px 0 0' : '0 0 0 14px';
@endphp
@foreach ($cards as $card)
<tr><td class="px" style="padding:{{ $loop->first ? '18px' : '12px' }} 32px 0;font-family:{!! $k['sans'] !!};">
<table role="presentation" class="card line" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border:1px solid #F0E4E9;border-radius:18px;background:#FFFFFF;border-collapse:separate;">
<tr><td style="padding:16px 16px 4px;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="76" valign="middle" style="width:76px;">@if (! empty($card['img']))<a href="{{ $card['url'] }}" style="text-decoration:none;"><img src="{{ $card['img'] }}" width="76" height="76" alt="{{ $card['name'] }}" style="display:block;width:76px;height:76px;border-radius:14px;background:#FFF0F4;"></a>@else<div class="soft" style="width:76px;height:76px;border-radius:14px;background:#FFF0F4;font-size:0;line-height:0;">&nbsp;</div>@endif</td>
<td valign="middle" style="padding:{{ $rcPadText }};font-family:{!! $k['sans'] !!};">@if ($card['brand'] !== '')<div style="font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:#C13E63;font-weight:700;">{{ $card['brand'] }}</div>@endif
<div style="margin-top:3px;font-size:15.5px;font-weight:600;line-height:1.3;"><a class="ink" href="{{ $card['url'] }}" style="color:{{ $k['text'] }};text-decoration:none;"><span dir="auto">{{ $card['name'] }}</span></a></div>
@if ($card['variant'] !== '')<div class="muted" style="margin-top:3px;font-size:12.5px;color:#8C828A;"><span dir="auto">{{ $card['variant'] }}</span></div>@endif</td>
</tr></table></td></tr>
<tr><td align="center" dir="ltr" style="padding:8px 8px 0;font-family:{!! $k['sans'] !!};white-space:nowrap;">@foreach ($card['stars'] as $n => $href)<a href="{{ $href }}" style="display:inline-block;width:44px;font-size:34px;line-height:44px;color:{{ $starHex }};text-decoration:none;text-align:center;" title="{{ trans_choice('email.kit.stars', $n + 1, ['count' => $n + 1]) }}" aria-label="{{ trans_choice('email.kit.stars', $n + 1, ['count' => $n + 1]) }}">&#9733;</a>@endforeach</td></tr>
<tr><td align="center" class="muted" style="padding:2px 16px 14px;font-family:{!! $k['sans'] !!};font-size:12px;color:#8C828A;">{{ __('email.review_request.tap_hint') }}</td></tr>
</table></td></tr>
@endforeach
