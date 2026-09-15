{{--
    Bill to / Deliver to.

    Both are the JSON snapshots on the order, not the customer's address book:
    an address edited after the order shipped must not rewrite where the order
    went.

    On the invoice, where the two are identical the second column says so
    rather than printing the same six lines twice. The packing slip passes
    $collapseSame = false: the person holding it is looking for the delivery
    address, and "as above" is a worse thing to read at a packing bench than
    the address written out.
--}}
<div class="parties">
    <div class="party">
        <div class="label">{{ $billLabel ?? 'Bill to' }}</div>
        @forelse ($doc['billTo'] as $i => $line)
            <div @class(['name' => $i === 0])>{{ $line }}</div>
        @empty
            <div>&mdash;</div>
        @endforelse
        @if ($doc['email'] !== '')
            <div>{{ $doc['email'] }}</div>
        @endif
    </div>

    <div class="party">
        <div class="label">{{ $shipLabel ?? 'Deliver to' }}</div>
        @if ($doc['sameAddress'] && ($collapseSame ?? true))
            <div>Same as the billing address</div>
        @else
            @forelse ($doc['shipTo'] as $i => $line)
                <div @class(['name' => $i === 0])>{{ $line }}</div>
            @empty
                <div>&mdash;</div>
            @endforelse
        @endif
    </div>
</div>
