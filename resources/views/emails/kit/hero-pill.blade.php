{{--
    hero(), with the eyebrow as a small soft pill in sentence case instead of
    spaced capitals (2.60.562, the UAE number email). The owner asked for "a
    small line above heading"; in capitals "YOUR UAE PHONE NUMBER IS MISSING"
    reads as the warning he had just turned down. Same five values as
    emails.kit.hero; everything else in it is that partial's.
--}}
@php
    [$heroBg, $heroFg] = \App\Services\Mail\Kit\MailKit::TONES[$tone ?? 'pink'] ?? \App\Services\Mail\Kit\MailKit::TONES['pink'];
    $heroIcon = \App\Services\Mail\Kit\MailKit::ICONS[$icon ?? 'heart'] ?? \App\Services\Mail\Kit\MailKit::ICONS['heart'];
@endphp
<tr><td class="px" align="center" style="padding:30px 32px 6px;font-family:{!! $k['sans'] !!};">
<div style="width:54px;height:54px;line-height:54px;border-radius:27px;background:{{ $heroBg }};color:{{ $heroFg }};font-size:24px;margin:0 auto 14px;text-align:center;">{!! $heroIcon !!}</div>
<span style="display:inline-block;padding:5px 13px;border-radius:99px;background:{{ $heroBg }};color:{{ $heroFg }};font-size:12.5px;line-height:1.4;font-weight:700;">&#9679;&nbsp; {{ $eyebrow }}</span>
<div class="h1 ink" style="margin-top:12px;font-family:{!! $k['head'] !!};font-size:28px;line-height:1.2;font-weight:600;color:{{ $k['text'] }};letter-spacing:-.015em;">{{ $title }}</div>
<div class="ink2" style="margin:12px auto 0;max-width:460px;font-size:15.5px;line-height:1.6;color:#5E545A;">{{ $lead }}</div>
</td></tr>
