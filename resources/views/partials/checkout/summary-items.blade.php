{{-- Matched to the live page source: the summary line shows the product name
     only, with no brand row above it. --}}
@php use App\Support\CssUrl; use App\Support\Gradient; use App\Support\Money; @endphp
@foreach ($items as $item)
    @php
        $p = $item->product;
        // t() for the words; the gradient seed stays on the English columns so
        // a line keeps one colour in both languages.
        $name = $p?->t('name');
        $img = $item->variant?->image ?: $p?->image;
        // (Lane IM) Order summary line thumbnail: a small square drawn from the
        // full-size photograph. See ImageVariants::variantUrl().
        $imgCss = CssUrl::value(\App\Support\ImageVariants::variantUrl((string) $img, 400));
        $thumb = $imgCss !== ''
            ? "background-image:url('" . e($imgCss) . "')"
            : 'background:' . Gradient::for(($p?->brand?->name ?? '') . ($p?->name ?? ''));
        // (Lane SET) NONE unless the line is a set; members eager-loaded by
        // CheckoutController::loadCart() and only when one is.
        $kbbSet = \App\Support\SetContents::fromProduct($p, (int) $item->unit_price);
    @endphp
        <div class="ci" data-key="{{ $item->id }}">
            <div class="cth" style="{{ $thumb }}"><span class="qb">{{ $item->quantity }}</span></div>
            <div class="cinfo">
                <div class="n">{{ $name }}</div>
                @if ($kbbSet['members'])@include('partials.set-row', ['contents' => $kbbSet, 'surface' => 'checkout', 'key' => 'o' . $item->id])@endif{{-- (Lane SET) AT THE START OF THIS LINE and never at the end of the one above. A Blade directive compiles to a PHP close tag, and PHP eats a single newline immediately after one -- so a conditional appended to the end of a line SWALLOWS THAT LINE'S NEWLINE, which is a byte changed on every basket in the shop whether or not it holds a set. Measured: StorefrontEnglishUnchangedTest went red on /cart, /checkout and the account order page for exactly that. Here the directives are followed by the line's own content, so nothing is emitted and nothing is eaten when the line is not a set. --}}<div class="qty">
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