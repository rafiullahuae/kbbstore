{{--
    "and then quantity + add to cart button row." (Lane PDP)

    ONE ROW: the stepper at its natural width, the button taking the rest. The
    shipped page already draws it this way (`.buyrow`); what each candidate
    changes is what the row sits ON — a card's floor, a tinted strip, the open
    page — and whether it is echoed by a docked bar further down.

    ▲ IT IS INERT. No form, no action, no hidden variation_id. These five pages
      are drawings of a layout, and a preview that could take money would be a
      second checkout path nobody has tested. The button carries `disabled` when
      the product is out of stock purely so the owner sees that state drawn.

    ▲ THE STOCK LINE IS ABOVE IT, not below, and the delivery line is not here
      at all — it is down with the other two assurances where he put it
      ("Authenticity line, delivery line, and payment icons"). The measured
      complaint against the shipped page was three delivery promises in two
      places; this order states each thing once.
--}}
@php
    $pvLowAt = (int) $settings->get('low_stock_at', 5);
    // `stock`, not `stock_quantity` -- there is no such column, and reading the
    // name the importer uses is why the shipped "Only N left" line had never
    // rendered once for anybody.
    $pvLeft = $product->manage_stock ? $product->stock : null;
    $pvLow = ! $out && $pvLowAt > 0 && is_numeric($pvLeft) && $pvLeft > 0 && $pvLeft <= $pvLowAt;
@endphp

<div class="pv-stock{{ $out ? ' out' : '' }}"><span class="dot"></span>
    @if ($out)
        {{ __('store.product.stock_sold_out') }}
    @elseif ($pvLow)
        {{ trans_choice('store.product.stock_low', (int) $pvLeft) }}
    @else
        {{ __('store.product.stock_in') }}
    @endif
</div>

<div class="pv-buyrow">
    <div class="pv-qty"><button type="button" tabindex="-1">−</button><span>1</span><button type="button" tabindex="-1">+</button></div>
    <button class="pv-add" type="button" @disabled($out)><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/></svg> {{ $out ? __('store.product.sold_out_tag') : __('store.product_card.add_to_cart') }}</button>
</div>
