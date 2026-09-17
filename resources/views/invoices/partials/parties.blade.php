{{--
    Bill to / Deliver to.

    Both are the JSON snapshots on the order, not the customer's address book:
    an address edited after the order shipped must not rewrite where the order
    went.

    On the invoice, where the two are identical the second column says so
    rather than printing the same six lines twice. The packing slip and the
    delivery note pass $collapseSame = false: the person holding one of those is
    looking for the delivery address, and "as above" is a worse thing to read at
    a packing bench or a door than the address written out.

    $showEmail defaults to true, which is what the invoice and the packing slip
    have always done. The delivery note passes false — it is handed to whoever
    opens the parcel, who on a gift order is not the person whose email address
    that is.

    EVERY CUSTOMER-TYPED LINE CARRIES dir="auto". An address written in Arabic
    is laid out by the page's LTR paragraph direction unless the element says
    otherwise, which puts a trailing house or street number at the wrong end of
    the line. dir="auto" resolves the direction from the line's own first strong
    character and makes it a bidi isolate besides, so an Arabic line cannot
    reorder the English one beside it. See the ARABIC note in document.blade.php.
--}}
<div class="parties">
    <div class="party">
        <div class="label">{{ $billLabel ?? __('email.invoice.bill_to') }}</div>
        @forelse ($doc['billTo'] as $i => $line)
            <div dir="auto" @class(['name' => $i === 0])>{{ $line }}</div>
        @empty
            <div>&mdash;</div>
        @endforelse
        @if (($showEmail ?? true) && $doc['email'] !== '')
            <div dir="auto">{{ $doc['email'] }}</div>
        @endif
    </div>

    <div class="party">
        <div class="label">{{ $shipLabel ?? __('email.invoice.deliver_to') }}</div>
        @if ($doc['sameAddress'] && ($collapseSame ?? true))
            <div>{{ __('email.invoice.same_as_billing') }}</div>
        @else
            @forelse ($doc['shipTo'] as $i => $line)
                <div dir="auto" @class(['name' => $i === 0])>{{ $line }}</div>
            @empty
                <div>&mdash;</div>
            @endforelse
        @endif
    </div>
</div>
