{{--
    rj-email-kit.cjs help(): "Questions? A real person answers." and the
    store's real channels, three across on a laptop, stacked on a phone.

    Every channel is EmailBranding::support() — a value the owner set — and
    MailKit re-checks each URL before it becomes an href. A store with no
    channel configured gets no box at all: a heading promising a person over
    nothing is worse than a shorter email.
--}}
@if ($k['support'] !== [])
<tr><td class="px" style="padding:28px 32px 0;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="soft" bgcolor="#FFF0F4" style="background:#FFF0F4;border-radius:12px;">
<tr><td style="padding:18px 18px 4px;font-family:{!! $k['sans'] !!};">
<div class="ink" style="font-size:15px;font-weight:800;color:{{ $k['text'] }};">{{ __('email.kit.help_heading') }}</div>
<div class="ink2" style="margin-top:3px;font-size:13px;line-height:1.55;color:#5E545A;">{{ __('email.kit.help_body') }}</div></td></tr>
<tr><td style="padding:8px 12px 14px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
@foreach ($k['support'] as $ch)<td class="stack" valign="top" style="padding:6px 6px;font-family:{!! $k['sans'] !!};">
<a href="{{ $ch['url'] }}" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="34" style="width:34px;"><div style="width:28px;height:28px;line-height:28px;border-radius:14px;background:{{ ['whatsapp' => '#2E9E6B', 'email' => '#C13E63', 'instagram' => '#E0567B'][$ch['kind']] }};color:#FFFFFF;font-size:13px;font-weight:700;text-align:center;">{{ $ch['glyph'] }}</div></td>
<td><div class="ink" style="font-size:13.5px;font-weight:700;color:{{ $k['text'] }};">{{ $ch['value'] }}</div><div class="muted" style="font-size:11.5px;color:#8C828A;">{{ $ch['label'] }}</div></td>
</tr></table></a></td>
@endforeach</tr></table></td></tr></table></td></tr>
@endif
