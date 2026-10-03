{{--
    rj-email-kit.cjs hero() for look A: one icon, one eyebrow, one headline,
    one sentence.

    $icon   a key of MailKit::ICONS (constant entities, printed raw)
    $tone   a key of MailKit::TONES
    $eyebrow, $title   plain text — escaped
    $lead   plain text, or an HtmlString the caller built from escaped parts
            (Blade's {{ }} leaves an HtmlString as it is and escapes anything else)
--}}
@php
    [$heroBg, $heroFg] = \App\Services\Mail\Kit\MailKit::TONES[$tone ?? 'pink'] ?? \App\Services\Mail\Kit\MailKit::TONES['pink'];
    $heroIcon = \App\Services\Mail\Kit\MailKit::ICONS[$icon ?? 'heart'] ?? \App\Services\Mail\Kit\MailKit::ICONS['heart'];
@endphp
<tr><td class="px" align="center" style="padding:30px 32px 6px;font-family:{!! $k['sans'] !!};">
<div style="width:54px;height:54px;line-height:54px;border-radius:27px;background:{{ $heroBg }};color:{{ $heroFg }};font-size:24px;margin:0 auto 14px;text-align:center;">{!! $heroIcon !!}</div>
<div style="font-size:11.5px;letter-spacing:.16em;text-transform:uppercase;color:{{ $heroFg }};font-weight:700;">{{ $eyebrow }}</div>
<div class="h1 ink" style="margin-top:8px;font-family:{!! $k['head'] !!};font-size:28px;line-height:1.2;font-weight:600;color:#2A2228;letter-spacing:-.015em;">{{ $title }}</div>
<div class="ink2" style="margin:12px auto 0;max-width:460px;font-size:15.5px;line-height:1.6;color:#5E545A;">{{ $lead }}</div>
</td></tr>
