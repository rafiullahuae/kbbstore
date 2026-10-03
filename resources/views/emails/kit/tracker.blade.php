{{--
    rj-email-kit.cjs tracker(): Placed → Confirmed → Shipped → Delivered.

    $at       index of the last step reached (0-based)
    $labels   the step names, plain text
    $stopped  null, or the label drawn in red on the step after $at (a
              cancelled or failed order: the reached part greys, a cross follows)
--}}
@php
    $trkStopped = $stopped ?? null;
    $trkDone = $trkStopped !== null ? '#8C828A' : '#E0567B';
    $trkLast = count($labels) - 1;
@endphp
<tr><td class="px" style="padding:22px 24px 4px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>@foreach (array_values($labels) as $i => $trkLabel)@php
    $reached = $i <= $at;
    $isStop = $trkStopped !== null && $i === $at + 1;
    $dotBg = $isStop ? '#C0392B' : ($reached ? $trkDone : '#FFFFFF');
    $dotBorder = $isStop ? '#C0392B' : ($reached ? $trkDone : '#E4D6DC');
    $glyph = $isStop ? '&#10005;' : ($reached ? '&#10003;' : '');
    $labColor = $isStop ? '#C0392B' : ($reached ? '#2A2228' : '#8C828A');
    $left = $i === 0 ? 'transparent' : ($i <= $at ? $trkDone : ($isStop ? '#C0392B' : '#EADDE3'));
    $right = $i === $trkLast ? 'transparent' : ($i < $at ? $trkDone : '#EADDE3');
@endphp<td width="25%" align="center" valign="top" style="width:25%;font-family:{!! $k['sans'] !!};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="50%" style="padding-top:11px;"><div style="height:3px;line-height:3px;font-size:0;background:{{ $left }};">&nbsp;</div></td>
<td width="26" style="width:26px;"><div style="width:22px;height:22px;line-height:22px;border-radius:13px;background:{{ $dotBg }};border:2px solid {{ $dotBorder }};color:#FFFFFF;font-size:12px;font-weight:700;text-align:center;">{!! $glyph !!}</div></td>
<td width="50%" style="padding-top:11px;"><div style="height:3px;line-height:3px;font-size:0;background:{{ $right }};">&nbsp;</div></td>
</tr></table>
<div class="{{ $reached ? 'ink' : 'muted' }}" style="margin-top:7px;font-size:12px;line-height:1.3;font-weight:{{ $reached || $isStop ? 700 : 500 }};color:{{ $labColor }};">{{ $isStop ? $trkStopped : $trkLabel }}</div>
</td>@endforeach</tr></table></td></tr>
