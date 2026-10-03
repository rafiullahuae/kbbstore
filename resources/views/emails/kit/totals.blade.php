{{--
    rj-email-kit.cjs totals(): the money, then the grand total in the brand pink.

    $rows   list of [label, value, accent] — label plain text, value plain text
            or the shop's money HtmlString, accent true for a saving (green)
    $grand  [label, value, note|null] — the note is the small line under it
--}}
<tr><td class="px" style="padding:6px 32px 0;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #F0E4E9;" class="line">
<tr><td colspan="2" style="height:8px;font-size:0;line-height:8px;">&nbsp;</td></tr>@foreach ($rows as [$totLabel, $totValue, $totAccent])<tr>
<td class="ink2" style="padding:5px 0;font-family:{!! $k['sans'] !!};font-size:14px;color:#5E545A;">{{ $totLabel }}</td>
<td align="right" style="padding:5px 0;font-family:{!! $k['sans'] !!};font-size:14px;color:{{ $totAccent ? '#2E9E6B' : $k['text'] }};font-weight:{{ $totAccent ? 700 : 500 }};" class="{{ $totAccent ? '' : 'ink' }}">{{ $totValue }}</td></tr>@endforeach
<tr><td colspan="2" style="padding-top:10px;border-bottom:1px solid #F0E4E9;" class="line"></td></tr>
<tr><td class="ink" style="padding:14px 0 4px;font-family:{!! $k['sans'] !!};font-size:16px;font-weight:800;color:{{ $k['text'] }};">{{ $grand[0] }}</td>
<td align="right" style="padding:14px 0 4px;font-family:{!! $k['sans'] !!};font-size:20px;font-weight:800;color:#C13E63;">{{ $grand[1] }}</td></tr>
@if (! empty($grand[2]))<tr><td colspan="2" class="muted" align="right" style="font-family:{!! $k['sans'] !!};font-size:12px;color:#8C828A;">{{ $grand[2] }}</td></tr>@endif
</table></td></tr>
