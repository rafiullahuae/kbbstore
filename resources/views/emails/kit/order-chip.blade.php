{{--
    rj-email-kit.cjs orderChip(): Order · Placed · Total on a cream card.

    $chipNumber, $chipPlaced   plain text
    $chipTotal                 plain text or the shop's money HtmlString
    $chipExtra                 optional HtmlString built from escaped parts
--}}
<tr><td class="px" style="padding:18px 32px 0;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="soft" bgcolor="#FFF8F5" style="background:#FFF8F5;border-radius:12px;border:1px solid #F0E4E9;">
<tr><td style="padding:14px 18px;font-family:{!! $k['sans'] !!};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td valign="top" style="font-size:13px;color:#5E545A;"><span class="muted" style="font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:#8C828A;font-weight:700;">{{ __('email.kit.chip_order') }}</span><br><span style="font-size:18px;font-weight:800;color:#C13E63;">{{ $chipNumber }}</span></td>
<td valign="top" style="font-size:13px;color:#5E545A;"><span class="muted" style="font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:#8C828A;font-weight:700;">{{ __('email.kit.chip_placed') }}</span><br><span class="ink" style="font-size:14px;font-weight:600;color:#2A2228;">{{ $chipPlaced }}</span></td>
<td valign="top" style="font-size:13px;color:#5E545A;"><span class="muted" style="font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:#8C828A;font-weight:700;">{{ __('email.kit.chip_total') }}</span><br><span class="ink" style="font-size:14px;font-weight:600;color:#2A2228;">{{ $chipTotal }}</span></td>
</tr></table>{{ $chipExtra ?? '' }}</td></tr></table></td></tr>
