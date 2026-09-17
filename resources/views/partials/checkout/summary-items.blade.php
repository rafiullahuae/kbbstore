{{-- Matched to the live page source: the summary line shows the product name
     only, with no brand row above it. --}}
@php use App\Support\Gradient; use App\Support\Money; @endphp
@foreach ($items as $item)
    @php
        $p = $item->product;
        // t() for the words; the gradient seed stays on the English columns so
        // a line keeps one colour in both languages.
        $name = $p?->t('name');
        $img = $item->variant?->image ?: $p?->image;
        $thumb = $img
            ? "background-image:url('" . e($img) . "')"
            : 'background:' . Gradient::for(($p?->brand?->name ?? '') . ($p?->name ?? ''));
    @endphp
        <div class="ci" data-key="{{ $item->id }}">
            <div class="cth" style="{{ $thumb }}"><span class="qb">{{ $item->quantity }}</span></div>
            <div class="cinfo">
                <div class="n">{{ $name }}</div>
                <div class="qty">
                    <button type="button" class="co-q" data-key="{{ $item->id }}" data-d="-1" aria-label="{{ __('store.cart.decrease_quantity') }}">−</button>
                    <span>{{ $item->quantity }}</span>
                    <button type="button" class="co-q" data-key="{{ $item->id }}" data-d="1" aria-label="{{ __('store.cart.increase_quantity') }}">+</button>
                </div>
            </div>
            <div class="cside">
                <button type="button" class="co-rm" data-key="{{ $item->id }}" aria-label="{{ __('store.checkout.remove_item_label', ['product' => $name]) }}">✕</button>
                <div class="cprice">{!! Money::format($item->lineTotal()) !!}</div>
            </div>
        </div>
@endforeach