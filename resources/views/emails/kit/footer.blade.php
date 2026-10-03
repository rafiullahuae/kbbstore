{{--
    rj-email-kit.cjs footer(): below the card. The owner's words (3 Oct):
    "in very bottom footer, don't include phone email, what is repeated in the
    questions? box. just keep address, terms pages, un-subscribe option etc.
    make nice footer, not just throw the content."

    Wordmark and tagline; the Dubai and Korea addresses side by side (stacked
    on a phone); the policy pages; Unsubscribe · Email preferences on the
    messages that have a list to leave (pass $unsubscribeUrl); why this
    arrived and the copyright; "View this email in your browser" last.

    $why             plain text or an HtmlString built from escaped parts
    $unsubscribeUrl  null on a transactional email, else the signed opt-out
                     link the Mailable was handed

    An address the owner has not filled in is left out, and with neither the
    whole row goes — a placeholder is for a preview, never for a customer.
    The browser copy is minted by WebCopy and only exists if this message is
    actually sent; a render that is never sent leaves a link that 404s.
--}}
@php
    $ftA = static fn (string $href, string $label, bool $strong = false) => '<a href="' . e($href) . '" style="color:#5E545A;text-decoration:' . ($strong ? 'underline' : 'none') . ';' . ($strong ? 'font-weight:600;' : '') . '">' . e($label) . '</a>';
    $ftDot = '&nbsp;&nbsp;&middot;&nbsp;&nbsp;';
    $ftWebCopy = \App\Services\Mail\Kit\WebCopy::mint();
    $ftUnsub = \App\Services\Mail\Kit\MailKit::url($unsubscribeUrl ?? null);
@endphp
<tr><td align="center" style="padding:26px 26px 14px;font-family:{!! $k['sans'] !!};">
<div style="font-size:17px;font-weight:800;letter-spacing:-.02em;color:{{ $k['text'] }};">{{ $k['wordmark'][0] }}<span style="color:#C13E63;">{{ $k['wordmark'][1] }}</span></div>
<div class="muted" style="margin-top:3px;font-size:11.5px;color:#8C828A;">{{ __('email.kit.footer_tagline') }}</div>
</td></tr>
<tr><td style="padding:0 40px;"><div style="height:1px;line-height:1px;font-size:0;background:#FCE0E8;">&nbsp;</div></td></tr>
@if ($k['addresses'] !== [])
<tr><td align="center" style="padding:16px 22px 6px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:460px;margin:0 auto;"><tr>@foreach ($k['addresses'] as $place)<td class="stack" width="{{ count($k['addresses']) > 1 ? '50%' : '100%' }}" valign="top" align="center" style="width:{{ count($k['addresses']) > 1 ? '50%' : '100%' }};padding:0 10px 10px;font-family:{!! $k['sans'] !!};">
<div style="font-size:15px;line-height:1;">&#128205;</div>
<div style="margin-top:5px;font-size:11px;letter-spacing:.14em;text-transform:uppercase;font-weight:700;color:#5E545A;">{{ $place['label'] }}</div>
<div class="muted" style="margin-top:4px;font-size:12px;line-height:1.55;color:#8C828A;">@foreach ($place['lines'] as $placeLine){{ $placeLine }}@if (! $loop->last)<br>@endif
@endforeach</div></td>@endforeach</tr></table></td></tr>
<tr><td style="padding:0 40px;"><div style="height:1px;line-height:1px;font-size:0;background:#FCE0E8;">&nbsp;</div></td></tr>
@endif
<tr><td align="center" style="padding:14px 22px;font-family:{!! $k['sans'] !!};font-size:12px;line-height:1.9;color:#8C828A;" class="muted">
{!! implode($ftDot, array_map(fn (array $l) => $ftA($l['url'], $l['label']), $k['links'])) !!}
@if ($ftUnsub !== null)<br>{!! $ftA($ftUnsub, __('email.common.unsubscribe'), true) !!}{!! $ftDot !!}{!! $ftA($ftUnsub, __('email.kit.preferences')) !!}@endif
</td></tr>
<tr><td style="padding:0 40px;"><div style="height:1px;line-height:1px;font-size:0;background:#FCE0E8;">&nbsp;</div></td></tr>
<tr><td align="center" style="padding:14px 30px 4px;font-family:{!! $k['sans'] !!};font-size:11.5px;line-height:1.6;color:#8C828A;" class="muted">{{ $why }}<br>{{ __('email.kit.copyright', ['year' => $k['year'], 'store' => $k['storeName']]) }}</td></tr>
@if ($ftWebCopy !== null)
<tr><td align="center" style="padding:10px 26px 8px;font-family:{!! $k['sans'] !!};font-size:12px;" class="muted">{!! $ftA($ftWebCopy, __('email.kit.view_in_browser'), true) !!}</td></tr>
@endif
