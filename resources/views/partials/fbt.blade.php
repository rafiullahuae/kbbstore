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

    ── ONE BUTTON ─────────────────────────────────────────────────────────────
    "Buy 4 items together" counts the ticks; resources/js/kbb/fbt.js keeps it
    in step (plain counting — nothing is measured) and posts every ticked
    product to /api/cart/add-together in ONE request, which re-checks each one
    on the server. Its label templates are printed here through __() so the
    Arabic page counts in Arabic.

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
    foreach ($btItems as $btI => $btP) {
        $btIsMain = 0 === $btI;
        $btOos = ! $btIsMain && $btP->stock_status !== 'instock';
        $btNow = ($btIsMain && $btMainVar) ? (int) $btMainVar->effectivePrice() : (int) $btP->effectivePrice();
        $btWas = (! $btIsMain || ! $btMainVar) && $btP->isOnSale() ? (int) $btP->compareAtPrice() : 0;
        $btImg = \App\Support\CssUrl::value(\App\Support\ImageVariants::variantUrl((string) $btP->image, 400));
        $btSeed = ($btP->brand?->name ?? '') . $btP->name;
        $btRows[] = [
            'p' => $btP,
            'main' => $btIsMain,
            'oos' => $btOos,
            'now' => $btNow,
            'was' => $btWas,
            'style' => $btImg !== '' ? "background-image:url('" . e($btImg) . "')" : 'background:' . \App\Support\Gradient::for($btSeed),
            'initials' => $btImg !== '' ? '' : \App\Support\Gradient::initials($btP->brand?->name ?: $btP->t('name')),
            'name' => $btP->t('name'),
        ];
        if (! $btOos) {
            $btChecked++;
            $btTotal += $btNow;
        }
    }
    $btLabel = $btChecked === 0
        ? __('store.buy_together.button_none')
        : ($btChecked === 1 ? __('store.buy_together.button_one') : __('store.buy_together.button', ['count' => $btChecked]));
    $btAmount = \App\Support\Money::amount($btTotal);
    $btTotalHtml = \Illuminate\Support\Str::replaceLast($btAmount, '<span class="bt-num">' . e($btAmount) . '</span>', \App\Support\Money::format($btTotal));
@endphp
<section class="kbb-fbt bt {{ $modules->classFor('fbt') }}" data-bt="{{ $btMain->id }}" aria-labelledby="btTitle"
         style="{{ \App\Services\BuyTogether::cardStyle() }}"
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
            <div class="bt-card{{ $btR['main'] ? ' is-main' : '' }}{{ $btR['oos'] ? ' is-oos is-off' : '' }}" data-price="{{ $btR['now'] }}"@if ($btR['main'] && $btMainVar) data-bt-var @endif>
                <label class="im" style="{{ $btR['style'] }}">{{ $btR['initials'] }}<input type="checkbox" class="bt-cb" value="{{ $btR['p']->id }}"@if (! $btR['oos']) checked @else disabled @endif aria-label="{{ __('store.buy_together.include', ['name' => $btR['name']]) }}"><span class="bt-tick" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="m5.5 12.5 4.2 4.2 8.8-9.4"/></svg></span>@if ($btI > 0)<span class="bt-plus" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 6v12M6 12h12"/></svg></span>@endif</label>
                <a class="lk" href="{{ $btR['p']->url() }}">
                    <span class="nm">{{ $btR['name'] }}</span>
                    <span class="pr">@if ($btR['oos']){{ __('store.buy_together.sold_out') }}@else{!! \App\Support\Money::format($btR['now']) !!}@if ($btR['was'] > $btR['now'])<span class="cwas">{!! \App\Support\Money::format($btR['was']) !!}</span>@endif @endif</span>
                </a>
            </div>
            @endforeach
        </div>
        <div class="bt-foot">
            @if ($btCfg['show_total'])
            <p class="bt-total"><span>{{ __('store.buy_together.total') }}</span> <b class="bt-sum">{!! $btTotalHtml !!}</b></p>
            @endif
            <button type="button" class="bt-buy" data-bt-buy @if ($btChecked === 0) disabled @endif><span class="bt-spin" aria-hidden="true"></span><span class="bt-label" aria-live="polite">{{ $btLabel }}</span></button>
        </div>
    </div>
</section>
@endif
