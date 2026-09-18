{{--
The body of the A6 address label — one order's sheet, and nothing around it.

IT LIVES HERE SO THAT THE BULK DOCUMENT AND THE SINGLE ONE CANNOT DRIFT.
resources/views/invoices/shipping-label.blade.php includes it once inside its own
@section('sheet'); invoices/bulk.blade.php includes it once per selected
order. Duplicating this markup would mean a change to a column heading here
landed on the single sheet and not on the batch of twenty the packer
actually prints, and nothing would have failed.

THE @include THAT PULLS THIS IN CARRIES NO SURROUNDING WHITESPACE, and the
comment above closes ONTO the first line of the body below with no newline
between them, for the reason document.blade.php already records: a Blade
comment is removed and the whitespace around it is not, so a stray newline
here rewrites every tracked preview under docs/invoice-previews/ with a
diff that says nothing. InvoicePreviewsTest regenerates those files on every
run, so getting this wrong shows up as a dirty tree, not as a failure.

$doc is the array Services\Invoices\InvoiceDocument::present() returns.
THE COMMENT CLOSES ONTO THE FIRST LINE OF MARKUP with no newline between
them. PHP eats one newline after a `?>`, so `@section('sheet')` followed by
a newline lost it; an `@include` echoes a string and eats nothing. A leading
newline here therefore re-indents the first element of every tracked preview
under docs/invoice-previews/ — measured, not guessed.

Nothing else is read.
--}}    <div class="from">
        <span class="n" dir="auto">{{ __('invoice.label.from', ['name' => $doc['seller']['name']]) }}</span>
        @foreach ($doc['seller']['addressLines'] as $line)
            <div dir="auto">{{ $line }}</div>
        @endforeach
        @if ($doc['seller']['phone'] !== '')
            <div dir="auto">{{ $doc['seller']['phone'] }}</div>
        @endif
    </div>

    <div class="to">
        <div class="label">{{ __('email.invoice.deliver_to') }}</div>
        {{-- The shipping snapshot, falling back to billing exactly as
             InvoiceDocument::present() already decided — one definition of
             "where this order goes", so the label and the delivery note cannot
             name different doors. --}}
        {{-- shipToPostal, not shipTo: the same address WITHOUT its phone line.
             The phone is the one thing on a label a driver reads before they
             set off, so it is printed once, large, on its own line below —
             printing it twice, small and inside the address block, is how a
             label ends up with two numbers on it that a rushed reader assumes
             are different. --}}
        @forelse ($doc['shipToPostal'] as $i => $line)
            <div dir="auto" @class(['name' => $i === 0, 'addr' => $i !== 0])>{{ $line }}</div>
        @empty
            <div class="name">&mdash;</div>
        @endforelse

        @if ($doc['shipPhone'] !== '')
            <div class="tel" dir="auto">{{ __('invoice.label.tel', ['phone' => $doc['shipPhone']]) }}</div>
        @endif
    </div>

    <div class="strip">
        <div>{{ __('invoice.label.strip_order') }}<b dir="auto">{{ $doc['orderNumber'] }}</b></div>
        <div>{{ __('invoice.label.strip_items') }}<b>{{ $doc['itemCount'] }}</b></div>
        <div>{{ __('invoice.label.strip_service') }}<b>{{ $doc['deliveryMethod'] }}</b></div>
    </div>

    @if ($doc['codToCollect'] !== null)
        <div class="cod">
            <div class="k">{{ __('invoice.label.cod_collect') }}</div>
            <div class="v">{!! $doc['codToCollect']['html'] !!}</div>
        </div>
    @endif

    <div class="code">
        @include('invoices.partials.barcode', ['value' => $doc['orderNumber'], 'height' => '13mm'])
    </div>
