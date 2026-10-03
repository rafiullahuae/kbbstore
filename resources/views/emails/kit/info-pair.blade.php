{{--
    rj-email-kit.cjs infoPair(): two boxes side by side on a laptop, stacked
    on a phone (.stack in the shell's one media query).

    $left, $right   [title, body, title2|null, body2|null] — titles plain
                    text; bodies plain text or an HtmlString built from
                    escaped parts (address lines joined with <br>)
--}}
@php
    $ipBox = static fn (string $title, $body) => '<div class="muted" style="font-size:11px;letter-spacing:.13em;text-transform:uppercase;color:#8C828A;font-weight:700;margin-bottom:6px;">' . e($title) . '</div><div class="ink" style="font-size:14px;line-height:1.6;color:#2A2228;">' . e($body) . '</div>';
@endphp
<tr><td class="px" style="padding:24px 32px 0;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td class="stack" width="50%" valign="top" style="width:50%;padding-right:12px;font-family:{!! $k['sans'] !!};">{!! $ipBox($left[0], $left[1]) !!}@if (! empty($left[2]))<div style="height:14px"></div>{!! $ipBox($left[2], $left[3]) !!}@endif</td>
<td class="stack stack-gap" width="50%" valign="top" style="width:50%;padding-left:12px;font-family:{!! $k['sans'] !!};">{!! $ipBox($right[0], $right[1]) !!}@if (! empty($right[2]))<div style="height:14px"></div>{!! $ipBox($right[2], $right[3]) !!}@endif</td>
</tr></table></td></tr>
