{{--
    Heading (Lane MK), the three shapes the approved campaigns use:
      hero   the kit hero without its icon, or left-aligned (the kit's own
             hero partial is used for the centred one with an icon);
      title  the serif headline of m1 ("Hi Aisha, your glow edit is here");
      label  the kit's small grey caps (m2's "New since your last visit").
    $h['title'] / ['lead'] are HtmlStrings from Blocks::marks() (escaped parts).
--}}
@php
    [$hBg, $hFg] = \App\Services\Mail\Kit\MailKit::TONES[$h['tone']] ?? \App\Services\Mail\Kit\MailKit::TONES['pink'];
    $hAlign = $h['align'] === 'left' ? 'left' : 'center';
@endphp
@if ($h['style'] === 'label')
@include('emails.kit.section-title', ['text' => $h['plainTitle'], 'pad' => '26px 32px 0'])
@elseif ($h['style'] === 'title')
<tr><td class="px" style="padding:28px 32px 0;font-family:{!! $k['sans'] !!};"><div class="ink" style="font-family:Georgia,'Times New Roman',Times,serif;font-size:26px;line-height:1.25;font-weight:700;color:{{ $k['text'] }};text-align:{{ $hAlign }};">{{ $h['title'] }}</div>@if ($h['plainLead'] !== '')<div class="ink2" style="margin-top:10px;font-size:15px;line-height:1.65;color:#5E545A;text-align:{{ $hAlign }};">{{ $h['lead'] }}</div>@endif</td></tr>
@else
<tr><td class="px" align="{{ $hAlign }}" style="padding:30px 32px 6px;font-family:{!! $k['sans'] !!};text-align:{{ $hAlign }};">
@if ($h['icon'] !== 'none')<div style="width:54px;height:54px;line-height:54px;border-radius:27px;background:{{ $hBg }};color:{{ $hFg }};font-size:24px;margin:0 {{ $hAlign === 'center' ? 'auto' : '0' }} 14px;text-align:center;">{!! \App\Services\Mail\Kit\MailKit::ICONS[$h['icon']] ?? '' !!}</div>@endif
@if ($h['eyebrow'] !== '')<div style="font-size:11.5px;letter-spacing:.16em;text-transform:uppercase;color:{{ $hFg }};font-weight:700;">{{ $h['eyebrow'] }}</div>@endif
<div class="h1 ink" style="margin-top:8px;font-family:{!! $k['head'] !!};font-size:28px;line-height:1.2;font-weight:600;color:{{ $k['text'] }};letter-spacing:-.015em;">{{ $h['title'] }}</div>
@if ($h['plainLead'] !== '')<div class="ink2" style="margin:12px {{ $hAlign === 'center' ? 'auto' : '0' }} 0;max-width:460px;font-size:15.5px;line-height:1.6;color:#5E545A;">{{ $h['lead'] }}</div>@endif
</td></tr>
@endif
