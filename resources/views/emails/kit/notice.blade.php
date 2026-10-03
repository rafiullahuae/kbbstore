{{--
    rj-email-kit.cjs notice(): a tinted box with a 4px coloured left edge.
    $html plain text or an HtmlString built from escaped parts; $tone a key of MailKit::TONES.
--}}
@php [$ntcBg, $ntcFg] = \App\Services\Mail\Kit\MailKit::TONES[$tone ?? 'amber'] ?? \App\Services\Mail\Kit\MailKit::TONES['amber']; @endphp
<tr><td class="px" style="padding:20px 32px 0;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $ntcBg }}" style="background:{{ $ntcBg }};border-radius:12px;border-left:4px solid {{ $ntcFg }};"><tr>
<td style="padding:14px 18px;font-family:{!! $k['sans'] !!};font-size:14px;line-height:1.6;color:{{ $k['text'] }};" class="ink">{{ $html }}</td></tr></table></td></tr>
