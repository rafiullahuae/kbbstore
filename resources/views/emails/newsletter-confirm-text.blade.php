{{--
    The plain-text half.

    Every other mailable in this shop ships one, and this one needs it more than
    most: a consent request that renders as an empty message in a text-only
    client is a consent request that cannot be answered, and the recipient's only
    remaining option is to mark it as spam.
--}}
{!! __('email.greeting.hello') !!}

{!! __('email.newsletter.somebody_asked_text', ['store' => $brand['storeName'] ?? __('email.newsletter.our')]) !!}

{!! __('email.newsletter.not_yet_text') !!}

{{ $confirmUrl }}

{!! trans_choice('email.newsletter.link_expiry', (int) $days) !!}

{!! __('email.newsletter.do_nothing') !!}

-- {{ $brand['storeName'] ?? 'K Beauty Bliss' }}

{!! __('email.text.unsubscribe_here_from_us') !!}
{{ $unsubscribeUrl }}
