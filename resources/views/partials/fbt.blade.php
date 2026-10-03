{{--
    BUY THESE TOGETHER — the product on the page plus one match from each
    category that goes with it. (Lane RB; it replaced Frequently Bought
    Together in this slot, under this file name, so the phone page's
    "Buy these together" row of Mobile sections still places it.)

    The owner: "i need the same design section which we have on the cart page
    "Recommended for you" same carousel, same size. [...] there will be empty
    circle at the corner of product, and by default that circle will be checked
    (filled green) with check (yes icon) white. and user can un-check."

    ── THE CARD IS THE CART RAIL'S CARD ──────────────────────────────────────
    Same structure as store/cart-inner.blade.php's `.cpg-card` — a square
    picture painted as a background, the name clamped under it, the price under
    that — and the same size, by the same arithmetic over the cart page's own
    settings (BuyTogether::cardStyle()). Two differences, both his:
      · the "+" add button on the picture's corner is a TICK here: a real
        checkbox, drawn as a 24px circle at the picture's top-end corner, green
        with a white tick when on, an empty white ring when off;
      · a small white "+" sits between two cards, centred on the pictures.

    ── THE PICTURE IS THE TICK; THE TITLE IS THE LINK ────────────────────────
    "the product image will also work same as the check circle [...] and
    product title will go to the product page. this only for this section."
    The picture is a <label> around that product's ONE checkbox, which is laid
    over the whole picture (kbb-product.css .bt-cb), and the circle is drawn
    inside the same label — so a tap on the photo and a tap on the circle are
    taps on the same input. No script toggles anything: fbt.js reads `checked`
    on `change`. The checkbox is named by the product; the name and the price
    below are the link to the product page, as on the cart rail.

    ── ONE BUTTON ─────────────────────────────────────────────────────────────
    "Buy 4 items together" counts the ticks; resources/js/kbb/fbt.js keeps it
    in step (plain counting — nothing is measured) and posts every ticked
    product to /api/cart/add-together in ONE request, which re-checks each one
    on the server. Its label templates are printed here through __() so the
    Arabic page counts in Arabic.

    ── FOUR ON A PHONE, THE FIFTH PEEKS (Lane RE) ─────────────────────────────
    The owner, from a phone at 390px: "the text is going out the boxes. you can
    adjust the 4 products on the screen, and 5th one can be hidden, and this
    will be as carousel. and upon scroll this section must slightly animate and
    display the half of the 5th product."

      · Four cards fill a phone's row exactly (--bt-per, printed here: four, or
        fewer when the section has fewer); a fifth and sixth wait off the edge
        in the same swipeable, snapping row (`bt-more`).
      · The first time the section scrolls into view the row slides to show
        half of the fifth card and settles back — once. fbt.js only ADDS A
        CLASS from an IntersectionObserver; the slide is a CSS animation whose
        distance is half a card plus a gap, a percentage of the card itself.
        Nothing is measured. Reduced motion gets no slide and a static peek.
      · The price line scales with its own card (container query units) and
        the struck price wraps under the current one rather than out of the box.

    ── THE TOTAL, THE CUT PRICE AND THE SAVING (Lane RE) ──────────────────────
    "above button Total: should be on right side beside the value. and also
    mention cut price as total calculation. and mention you're saving 'AED
    amount'". The total reads `Total: AED 300 AED 219` at the end of the row —
    the struck figure is the ticked products at their REGULAR prices, the other
    is what the shopper pays after any sale and the bundle discount for the
    number ticked — and under it a green pill, "You're saving AED 81". The tier
    for 3, 4 and 5-or-more ticked is printed as data-tiers; fbt.js recomputes
    all three figures as ticks change, with the server's own arithmetic
    (App\Services\BuyTogetherPricing::unitOff()). The basket prices the bundle
    again, on the server, whatever this page said.

    Fully qualified class names and no `use`: this partial is conditionally
    included, and a `use` inside an @if is a parse error.
--}}
@php $kbbBt = $buyTogether ?? null; @endphp
@if (is_array($kbbBt) && ($kbbBt['products'] ?? collect())->isNotEmpty())
@php
    $btItems = $kbbBt['products'];
    $btCfg = $kbbBt['config'];
    $btSvc = app(\App\Services\BuyTogether::class);
    $btMain = $btItems->first();
    $btMainVar = $btSvc->mainVariant($btMain);
    // A variable product's card follows the option chosen in the buy box: the
    // price of every in-stock option, by id, for the script to read back.
    $btVariants = $btMainVar === null ? [] : $btMain->variants
        ->filter(fn ($v) => $v->inStock())
        ->mapWithKeys(fn ($v) => [(string) $v->id => (int) $v->effectivePrice()])
        ->all();
    $btRows = [];
    $btChecked = 0;
    $btTotal = 0;
    // (Lane RE) The struck total and the bundle discount, for what is ticked.
    $btWasTotal = 0;
    $btPricing = app(\App\Services\BuyTogetherPricing::class);
    $btTiers = $btPricing->tiers();
    foreach ($btItems as $btI => $btP) {
        $btIsMain = 0 === $btI;
        $btOos = ! $btIsMain && $btP->stock_status !== 'instock';
        $btNow = ($btIsMain && $btMainVar) ? (int) $btMainVar->effectivePrice() : (int) $btP->effectivePrice();
        $btWas = (! $btIsMain || ! $btMainVar) && $btP->isOnSale() ? (int) $btP->compareAtPrice() : 0;
        $btImgCss = \App\Support\CssUrl::value(\App\Support\ImageVariants::variantUrl((string) $btP->image, 400));
        $btSeed = ($btP->brand?->name ?? '') . $btP->name;
        $btRows[] = [
            'p' => $btP,
            'main' => $btIsMain,
            'oos' => $btOos,
            'now' => $btNow,
            'was' => $btWas,
            // The regular price the struck total adds up: the compare-at when
            // the card prints one, its own price when it does not.
            'reg' => max($btWas, $btNow),
            'style' => $btImgCss !== '' ? "background-image:url('" . e($btImgCss) . "')" : 'background:' . \App\Support\Gradient::for($btSeed),
            'initials' => $btImgCss !== '' ? '' : \App\Support\Gradient::initials($btP->brand?->name ?: $btP->t('name')),
            'name' => $btP->t('name'),
        ];
        if (! $btOos) {
            $btChecked++;
            $btTotal += $btNow;
            // The struck total is what the ticked products cost WITHOUT this
            // section's discount -- their own (sale) prices -- so struck less
            // payable is exactly the buy-together saving. (2.60.361: "you're
            // saving calculate only the discounted price which is set for this
            // buy together section only")
            $btWasTotal += $btNow;
        }
    }
    // The tier for the number ticked on arrival, taken off each ticked card
    // exactly as the basket will take it.
    $btPct = $btTiers[min(\App\Services\BuyTogetherPricing::MAX_GROUP, $btChecked)] ?? 0;
    $btOff = 0;
    foreach ($btRows as $btR0) {
        if (! $btR0['oos']) {
            $btOff += \App\Services\BuyTogetherPricing::unitOff($btR0['now'], $btPct);
        }
    }
    $btPay = $btTotal - $btOff;
    // Only the buy-together discount; a product's own sale is not counted.
    $btSave = $btOff;
    $btWrap = static function (int $minor, string $class): string {
        $amount = \App\Support\Money::amount($minor);

        return \Illuminate\Support\Str::replaceLast($amount, '<span class="' . $class . '">' . e($amount) . '</span>', \App\Support\Money::format($minor));
    };
    $btMore = $btItems->count() > 4;
    $btLabel = $btChecked === 0
        ? __('store.buy_together.button_none')
        : ($btChecked === 1 ? __('store.buy_together.button_one') : __('store.buy_together.button', ['count' => $btChecked]));
    $btTotalHtml = $btWrap($btPay, 'bt-num');
@endphp
<section class="kbb-fbt bt{{ $btMore ? ' bt-more' : '' }} {{ $modules->classFor('fbt') }}" data-bt="{{ $btMain->id }}" aria-labelledby="btTitle"
         style="{{ \App\Services\BuyTogether::cardStyle() }};--bt-per:{{ min(4, $btItems->count()) }};--bt-n:{{ $btItems->count() }}"
         data-tiers="{{ json_encode($btTiers) }}"
         data-many="{{ __('store.buy_together.button', ['count' => ':count']) }}"
         data-one="{{ __('store.buy_together.button_one') }}"
         data-none="{{ __('store.buy_together.button_none') }}"
         data-exp="{{ \App\Support\Money::minorExponent() }}" data-dec="{{ \App\Support\Money::displayDecimals() }}"
         data-view-url="{{ \App\Support\Url::to('/api/product-view') }}"
         @if ($btVariants !== []) data-variants="{{ json_encode($btVariants) }}" @endif>
    <h2 id="btTitle">{{ $kbbBt['heading'] }}</h2>
    <div class="bt-row">
        <div class="bt-rail" role="group" aria-labelledby="btTitle">
            @foreach ($btRows as $btI => $btR)
            <div class="bt-card{{ $btR['main'] ? ' is-main' : '' }}{{ $btR['oos'] ? ' is-oos is-off' : '' }}" data-price="{{ $btR['now'] }}" data-reg="{{ $btR['reg'] }}"@if ($btR['main'] && $btMainVar) data-bt-var @endif>
                <label class="im" style="{{ $btR['style'] }}">{{ $btR['initials'] }}<input type="checkbox" class="bt-cb" value="{{ $btR['p']->id }}"@if (! $btR['oos']) checked @else disabled @endif aria-label="{{ $btR['name'] }}"><span class="bt-tick" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="m5.5 12.5 4.2 4.2 8.8-9.4"/></svg></span>@if ($btI > 0)<span class="bt-plus" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 6v12M6 12h12"/></svg></span>@endif</label>
                <a class="lk" href="{{ $btR['p']->url() }}">
                    <span class="nm">{{ $btR['name'] }}</span>
                    <span class="pr">@if ($btR['oos']){{ __('store.buy_together.sold_out') }}@else{!! \App\Support\Money::format($btR['now']) !!}@if ($btR['was'] > $btR['now'])<span class="cwas">{!! \App\Support\Money::format($btR['was']) !!}</span>@endif @endif</span>
                </a>
            </div>
            @endforeach
        </div>
        <div class="bt-foot">
            {{-- (Lane RH) The row needs "Show the total and buy-together
                 discount" (off by default: with it off there is no discount
                 to show, BuyTogetherPricing prices nothing). "Show on phones"
                 / "Show on laptops" only hide it below / from 1024px, the
                 block's own breakpoint; both off draws nothing. --}}
            @if ($btCfg['show_total'] && ! empty($btCfg['discount_on']) && (! empty($btCfg['row_phone']) || ! empty($btCfg['row_laptop'])))
            <div class="bt-sumrow{{ empty($btCfg['row_phone']) ? ' bt-hide-m' : '' }}{{ empty($btCfg['row_laptop']) ? ' bt-hide-d' : '' }}">
            <p class="bt-save" aria-live="polite"@if ($btSave <= 0) hidden @endif><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5" fill="currentColor" stroke="none"/></svg><span>{{ __('store.buy_together.saving') }}</span> <b>{!! $btWrap($btSave, 'bt-save-num') !!}</b></p>
            <p class="bt-total"><span class="bt-total-label">{{ __('store.buy_together.total') }}</span> <s class="bt-was"@if ($btWasTotal <= $btPay) hidden @endif>{!! $btWrap($btWasTotal, 'bt-was-num') !!}</s> <b class="bt-sum">{!! $btTotalHtml !!}</b></p>
            </div>
            @endif
            <button type="button" class="bt-buy" data-bt-buy @if ($btChecked === 0) disabled @endif><span class="bt-spin" aria-hidden="true"></span><span class="bt-label" aria-live="polite">{{ $btLabel }}</span></button>
        </div>
    </div>
</section>
@endif
