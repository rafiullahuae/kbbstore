{!! $eyebrow !!}

{!! $heading !!}

{!! wordwrap($body, 72) !!}

{!! __('email.phone_request.row_have') !!}: {!! $currentPhone === '' ? '-' : $currentPhone !!}
{!! __('email.phone_request.row_need') !!}: 05X XXX XXXX

{!! __('email.phone_request.button') !!}
{!! $phoneUrl !!}

{!! wordwrap(__('email.phone_request.note'), 72) !!}

{!! __('email.kit.chip_order') !!}: {!! $order['number'] !!} · {!! $order['totalPlain'] !!}

@include('emails.partials.support-text')
