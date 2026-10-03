{{--
    rj-email-kit.cjs rateRow(), wrapped in its row: one product per line, five
    tappable stars; each opens that product's review form with the rating
    already chosen.

    $rates  list of ['img' => URL|null, 'brand', 'name' plain, 'href' => the
            product URL the caller built and scheme-checked]
--}}
<tr><td class="px" style="padding:0 32px 0;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">@foreach ($rates as $rate)<tr><td style="padding:12px 0;border-bottom:1px solid #F0E4E9;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="64" valign="middle" style="width:64px;">@if (! empty($rate['img']))<img src="{{ $rate['img'] }}" width="56" height="56" alt="" style="display:block;width:56px;height:56px;border-radius:12px;">@else<div style="width:56px;height:56px;border-radius:12px;background:#FFF0F4;font-size:0;line-height:0;">&nbsp;</div>@endif</td>
<td valign="middle" style="padding-left:12px;font-family:{!! $k['sans'] !!};"><div class="muted" style="font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:#C13E63;font-weight:700;">{{ $rate['brand'] }}</div>
<div class="ink" style="font-size:14px;font-weight:600;color:#2A2228;line-height:1.3;">{{ $rate['name'] }}</div>
<div style="margin-top:4px;white-space:nowrap;">@foreach ([1, 2, 3, 4, 5] as $n)<a href="{{ $rate['href'] }}?rating={{ $n }}#write-review" style="display:inline-block;font-size:24px;line-height:28px;color:#E0567B;text-decoration:none;padding:0 2px;" aria-label="{{ trans_choice('email.kit.stars', $n, ['count' => $n]) }}">&#9733;</a>@endforeach</div></td></tr></table></td></tr>@endforeach</table></td></tr>
