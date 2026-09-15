{{--
    The support block and the signature, as plain text.

    The text twin of the two blocks at the foot of emails/layout.blade.php, and
    included by every customer-facing text part for the same reason the HTML
    blocks are in the layout: a customer reading this on a watch face or through
    a screen reader is exactly the customer most in need of being told how to
    reach a person.

    {!! !!} throughout, for the reason partials/body-text.blade.php sets out at
    length: this is text/plain, there is no markup context, and escaping here
    would corrupt an apostrophe rather than protect anything.

    Prints nothing at all when the store has configured no channel and no
    signature — EmailBranding returns empty arrays, and an empty heading over
    nothing is worse than a shorter email.
--}}
{{--
    NO BLANK LINE MAY OPEN THIS FILE, and the gap above the block is the
    including template's job.

    Blade drops the leading whitespace of a rendered partial, so blank lines
    written here to separate the block from what came before are silently
    lost -- four of them were, during this lane's work, and the previews are
    where it showed. Each text part therefore leaves its own blank line
    before the @include and this file starts with real content.
--}}
@if (! empty($brand['hasSupport']))
WE ARE HERE IF YOU NEED US
A real person answers. Ask us anything — a question about your order, or
about what to use it with.
@foreach ($brand['support'] as $channel)
{!! $channel['label'] !!}: {!! $channel['value'] !!} ({!! $channel['url'] !!})
@endforeach
@endif
@if (! empty($brand['signature']))

@foreach ($brand['signature'] as $line)
{!! $line !!}
@endforeach
@endif
