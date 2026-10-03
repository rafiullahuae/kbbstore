@extends('emails.layout')

{{--
    "How is your glow? 💌" — Lane RL. Sent once, 3 hours after the Delivered
    email. One row per product, each button to that product's reviews (#sr).
    No star preselection: the review form has no parameter for it, so none is
    promised (see App\Mail\OrderFeedbackRequest).
--}}

@section('body')
    @php $c = $brand['colours'] ?? \App\Services\Mail\EmailBranding::PALETTE; @endphp

    <p style="margin:0 0 10px;font-size:19px;font-weight:700;line-height:1.3;color:{{ $c['ink'] }};">{{ $heading }}</p>

    <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:{{ $c['ink2'] }};">{{ $body }}</p>

    <p style="margin:0 0 6px;font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:{{ $c['muted'] }};font-weight:700;">{{ __('email.feedback.items_heading') }}</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
        @foreach ($products as $p)
            <tr>
                <td style="padding:12px 0;border-top:1px solid {{ $c['line'] }};font-size:14px;line-height:1.4;color:{{ $c['ink'] }};">
                    @if ($p['brand'] !== '')<span style="display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:{{ $c['muted'] }};font-weight:700;">{{ $p['brand'] }}</span>@endif
                    <b>{{ $p['name'] }}</b>
                </td>
                <td align="right" style="padding:12px 0 12px 10px;border-top:1px solid {{ $c['line'] }};white-space:nowrap;">
                    <a href="{{ $p['url'] }}" style="display:inline-block;padding:9px 14px;border-radius:7px;background:{{ $c['pinkDeep'] }};color:{{ $c['white'] }};font-size:13px;font-weight:700;text-decoration:none;">★ {{ __('email.feedback.button') }}</a>
                </td>
            </tr>
        @endforeach
    </table>

    <p style="margin:12px 0 10px;font-size:13px;line-height:1.55;color:{{ $c['ink2'] }};">{{ __('email.feedback.button_note') }}</p>

    <p style="margin:0;font-size:13px;line-height:1.55;color:{{ $c['muted'] }};">{{ __('email.feedback.closing') }}</p>
@endsection
