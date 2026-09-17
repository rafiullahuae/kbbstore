{{--
    The plain-text half.

    Every other mailable in this shop ships one, and this one needs it more than
    most: a consent request that renders as an empty message in a text-only
    client is a consent request that cannot be answered, and the recipient's only
    remaining option is to mark it as spam.
--}}
Hello,

Somebody -- we hope it was you -- asked for {{ $brand['storeName'] ?? 'our' }} emails to be sent to this address.

You are not on the list yet. Open the link below and you will be.

{{ $confirmUrl }}

The link works for {{ $days }} days.

If it was not you, do nothing. Without that press we will not add this address, and you will not hear from us again.

-- {{ $brand['storeName'] ?? 'K Beauty Bliss' }}

Never want email from us at this address? Unsubscribe here:
{{ $unsubscribeUrl }}
