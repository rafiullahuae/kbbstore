{{--
    One line of a placed order. Same markup as partials/checkout/summary-items,
    minus the quantity steppers and the remove button — an order is a record,
    not a basket, and nothing on this page is editable.

    $item is an App\Models\OrderItem. Everything printed comes off the item
    itself except the thumbnail, which is the only thing that needs the product
    row and the only thing that degrades when the product is gone.
--}}
@php
    use App\Support\Gradient;
    use App\Support\Money;

    $product = $item->product;
    $image = $product?->image;
    $thumb = $image
        ? "background-image:url('" . e($image) . "')"
        : 'background:' . Gradient::for((string) ($item->brand ?? '') . (string) $item->name);

    $variant = is_array($item->variant_attributes)
        ? implode(' · ', array_filter(array_map('strval', $item->variant_attributes)))
        : '';

    /*
     * (Lane SET) THE SNAPSHOT, never the pivot. This is a placed order, so what
     * it prints is what was in the box on the day — App\Support\SetContents::-
     * fromOrderItem() reads one JSON column and touches no relation, which is
     * also why this page gains no query. NONE for every line that is not a set.
     */
    $kbbSet = \App\Support\SetContents::fromOrderItem($item);
@endphp
<div class="ci">
    <div class="cth" style="{{ $thumb }}"><span class="qb">{{ (int) $item->quantity }}</span></div>
    <div class="cinfo">
        @if ($item->brand)<div class="co-brand">{{ $item->brand }}</div>@endif
        <div class="n">{{ $item->name }}</div>
        @if ($kbbSet['members'])@include('partials.set-row', ['contents' => $kbbSet, 'surface' => 'order', 'key' => 'r' . $item->id])@endif{{-- (Lane SET) AT THE START OF THIS LINE and never at the end of the one above. A Blade directive compiles to a PHP close tag, and PHP eats a single newline immediately after one -- so a conditional appended to the end of a line SWALLOWS THAT LINE'S NEWLINE, which is a byte changed on every basket in the shop whether or not it holds a set. Measured: StorefrontEnglishUnchangedTest went red on /cart, /checkout and the account order page for exactly that. Here the directives are followed by the line's own content, so nothing is emitted and nothing is eaten when the line is not a set. --}}<div class="co-linemeta">
            {{ (int) $item->quantity }} × {!! Money::format((int) $item->unit_price) !!}@if ($variant !== '') <span>· {{ $variant }}</span>@endif
        </div>
    </div>
    <div class="cside">
        <div class="cprice">{!! Money::format((int) $item->total) !!}</div>
    </div>
</div>
