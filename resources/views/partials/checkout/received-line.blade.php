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
@endphp
<div class="ci">
    <div class="cth" style="{{ $thumb }}"><span class="qb">{{ (int) $item->quantity }}</span></div>
    <div class="cinfo">
        @if ($item->brand)<div class="co-brand">{{ $item->brand }}</div>@endif
        <div class="n">{{ $item->name }}</div>
        <div class="co-linemeta">
            {{ (int) $item->quantity }} × {!! Money::format((int) $item->unit_price) !!}@if ($variant !== '') <span>· {{ $variant }}</span>@endif
        </div>
    </div>
    <div class="cside">
        <div class="cprice">{!! Money::format((int) $item->total) !!}</div>
    </div>
</div>
