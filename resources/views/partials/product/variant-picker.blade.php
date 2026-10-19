{{--
    "PAGE A": round swatches and size pills. (Lane VPA)

    The owner's choice from docs/variant-options/index.html — "Shade: 03 Linen"
    above round swatches, "Size" above pills. Drawn from App\Support\
    VariantOptions, the same shape the card's dots come from.

    Picking an option moves, without a request: the price block and the sticky
    bar's price (pdp.js setPrice()), the stock line, Add to cart / Buy it now
    (disabled on a sold-out option, which the server refuses anyway), the main
    photograph when the option has its own, the SKU line, the hidden
    `variation_id` the form posts, and "Tell me when it is back"'s variant.

    EVERYTHING THE SCRIPT NEEDS IS IN `data-vpa`, built here once: prices
    pre-formatted by Money::format() (server-composed markup, the `pricehtml`
    contract pdp.js documents), each option's picture with its img-cache
    srcset, and the four sentences it swaps in, in the page's language. Names
    are escaped; swatch colours are #RRGGBB or a CssUrl-checked picture
    (VariantOptions::swatchStyle()).

    THE OLD ROWS ARE NOT HIDDEN BY "BUNDLE SECTION" ANY MORE. `pm-vpa` puts
    this section back on screen when Appearance -> Product page -> Mobile /
    Desktop sections has Bundle section off: that switch was meant for quantity
    bundles, and with it off a shopper could not choose a shade at all on
    Missha, TIRTIR, Heimish or House of Hur (found shooting the designs).
--}}
@php
    use App\Support\Money;

    $vpaSel = $kbbVpa['variants'][$kbbVpa['selected']] ?? null;
    $vpaChoice = $vpaSel['vals'] ?? [];
    $vpaPrices = [];
    $vpaPriceAt = [];
    $vpaImages = [];
    $vpaImageAt = [];
    $vpaRows = [];
    $vpaHtml = static function (string $markup) use (&$vpaPrices, &$vpaPriceAt): int {
        if (! isset($vpaPriceAt[$markup])) {
            $vpaPriceAt[$markup] = count($vpaPrices);
            $vpaPrices[] = $markup;
        }

        return $vpaPriceAt[$markup];
    };

    foreach ($kbbVpa['variants'] as $vv) {
        $vdp = $vv['was'] !== null ? Money::decimalsToDistinguish($vv['was'], $vv['price']) : null;
        $voff = $vv['was'] !== null && $vv['was'] > 0 ? (int) round((1 - $vv['price'] / $vv['was']) * 100) : 0;
        $vimg = -1;

        if ($vv['image'] !== null) {
            if (! isset($vpaImageAt[$vv['image']])) {
                $vpaImageAt[$vv['image']] = count($vpaImages);
                $vpaImages[] = [$vv['image'], \App\Support\ImageVariants::detailSrcsetFor($vv['image'])];
            }

            $vimg = $vpaImageAt[$vv['image']];
        }

        $vpaRows[] = [
            $vv['id'],
            array_map(static fn (array $a): int => (int) ($vv['vals'][$a['id']] ?? 0), $kbbVpa['axes']),
            $vpaHtml(Money::format($vv['price'], $vdp)),
            $vv['was'] !== null ? $vpaHtml(Money::format($vv['was'], $vdp)) : -1,
            $voff > 0 ? \App\Support\Bidi::number('-' . $voff . '%') : '',
            $vv['in'] ? 1 : 0,
            $vimg,
            (string) ($vv['sku'] ?? ''),
        ];
    }

    $vpaSkuAny = collect($kbbVpa['variants'])->contains(fn ($vv) => $vv['sku'] !== null);
    $vpaData = json_encode([
        'pid' => (int) $product->id,
        'v' => $vpaRows,
        'p' => $vpaPrices,
        'i' => $vpaImages,
        't' => [
            'in' => __('store.product.stock_in'),
            'out' => __('store.product.stock_sold_out'),
            'add' => __('store.product_card.add_to_cart'),
            'sold' => __('store.product.sold_out_tag'),
            'sku' => __('store.product.variant_sku', ['sku' => ':sku']),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
@endphp
<div class="vpa" id="vpa" data-vpa="{{ $vpaData }}">
@foreach ($kbbVpa['axes'] as $vpaI => $vpaAxis)
    @php
        $vpaOn = null;
        foreach ($vpaAxis['values'] as $vpaVal) {
            if (($vpaChoice[$vpaAxis['id']] ?? null) === $vpaVal['id']) {
                $vpaOn = $vpaVal;
            }
        }
        $vpaColour = $vpaAxis['kind'] === 'colour';
    @endphp
    <div class="vpa-ax{{ $vpaColour ? ' vpa-c' : ' vpa-s' }}" data-ax="{{ $vpaI }}">
        <div class="vpa-lbl" id="vpaL{{ $vpaI }}">{{ $vpaAxis['name'] }}: <b dir="auto">{{ $vpaOn['name'] ?? '' }}</b></div>
        <div class="vpa-row" role="radiogroup" aria-labelledby="vpaL{{ $vpaI }}">
        @foreach ($vpaAxis['values'] as $vpaVal)
            @php
                $vpaSold = \App\Support\VariantOptions::valueOut($kbbVpa, (int) $vpaAxis['id'], (int) $vpaVal['id'], $vpaChoice);
                $vpaIsOn = $vpaOn !== null && $vpaOn['id'] === $vpaVal['id'];
                $vpaTitle = $vpaVal['name'] . ($vpaSold ? ' — ' . __('store.product.sold_out_tag') : '');
            @endphp
            @if ($vpaColour)
            <button type="button" class="vpa-sw{{ $vpaSold ? ' out' : '' }}" role="radio" aria-checked="{{ $vpaIsOn ? 'true' : 'false' }}" data-v="{{ $vpaVal['id'] }}" data-n="{{ $vpaVal['name'] }}" aria-label="{{ $vpaTitle }}" title="{{ $vpaTitle }}" style="{{ \App\Support\VariantOptions::swatchStyle($vpaVal) }}"></button>
            @else
            <button type="button" class="vpa-pill{{ $vpaSold ? ' out' : '' }}" role="radio" aria-checked="{{ $vpaIsOn ? 'true' : 'false' }}" data-v="{{ $vpaVal['id'] }}" data-n="{{ $vpaVal['name'] }}" aria-label="{{ $vpaTitle }}" dir="auto">{{ $vpaVal['name'] }}</button>
            @endif
        @endforeach
        </div>
    </div>
@endforeach
@if ($vpaSkuAny)
    <div class="vpa-sku" id="vpaSku">{{ ($vpaSel['sku'] ?? null) !== null ? __('store.product.variant_sku', ['sku' => $vpaSel['sku']]) : '' }}</div>
@endif
</div>
<input type="hidden" name="variation_id" id="kbbVarId" value="{{ $vpaSel['id'] ?? '' }}">
