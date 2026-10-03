{{--
    rj-email-kit.cjs coupon(): a dashed pink card with the code in large type.
    $code, $line, $expires plain text (or HtmlString built from escaped parts).
--}}
<tr><td class="px" style="padding:22px 32px 0;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFF0F4" class="soft" style="background:#FFF0F4;border:2px dashed #E0567B;border-radius:16px;">
<tr><td align="center" style="padding:22px 18px;font-family:{!! $k['sans'] !!};">
<div style="font-size:11.5px;letter-spacing:.16em;text-transform:uppercase;color:#C13E63;font-weight:700;">{{ __('email.kit.coupon_label') }}</div>
<div style="margin-top:8px;font-family:'Courier New',Courier,monospace;font-size:28px;font-weight:700;letter-spacing:.14em;color:{{ $k['text'] }};" class="ink">{{ $code }}</div>
<div class="ink2" style="margin-top:6px;font-size:14px;color:#5E545A;">{{ $line }}</div>
<div class="muted" style="margin-top:4px;font-size:12px;color:#8C828A;">{{ $expires }}</div>
</td></tr></table></td></tr>
