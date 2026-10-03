{{--
    rj-email-kit.cjs button(): bulletproof — the table cell carries the colour
    and the radius, so Outlook's Word engine (which drops padding on an <a>)
    still draws a button.

    $label  plain text;  $href  a URL the CALLING VIEW built or scheme-checked
    (MailKit::url) — never a raw setting;  $align 'center' | 'left';  $ghost bool
--}}
@php
    $btnAlign = ($align ?? 'center') === 'left' ? 'left' : 'center';
    $btnGhost = (bool) ($ghost ?? false);
    $btnBg = $btnGhost ? '#FFFFFF' : $k['button'];
    $btnFg = $btnGhost ? $k['button'] : '#FFFFFF';
@endphp
<tr><td class="px" style="padding:26px 32px 0;font-family:{!! $k['sans'] !!};"><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="{{ $btnAlign }}" style="margin:0 {{ $btnAlign === 'center' ? 'auto' : '0' }};">
<tr><td align="center" bgcolor="{{ $btnBg }}" style="background:{{ $btnBg }};border-radius:99px;{{ $btnGhost ? 'border:2px solid ' . $k['button'] . ';' : '' }}">
<a href="{{ $href }}" style="display:inline-block;padding:15px 34px;font-family:{!! $k['sans'] !!};font-size:15px;font-weight:700;color:{{ $btnFg }};text-decoration:none;letter-spacing:.02em;">{{ $label }}</a>
</td></tr></table></td></tr>
