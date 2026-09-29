{{--
    THE BUNDLE BARS. "and then bundles purchase bars (we have that already on
    the product page)". (Lane PDP)

    ▲ THEY ARE THE REAL ONES. `$variants` and `$bundles` are what
      Store\ProductController::show() handed this page — the same
      App\Services\BundleService tiers and the same variation rows the shipped
      page draws, in the same `.variants`/`.variant` markup so they inherit the
      shop's own styling from kbb-product.css and each candidate only has to say
      how it differs. A preview that drew invented bundle bars would be showing
      him a strip his catalogue cannot produce.

    ▲ A SET SHOWS ITS CONTENTS HERE INSTEAD, and that is deliberate rather than
      accidental. BundleService::forProduct() answers an empty array for a set
      and a set is never $isVar, so neither branch below draws anything — and
      partials/set-contents-panel is included in this slot, which is where Lane
      SF put it on the shipped page with an arrow drawn from the owner's own
      screenshot. The "hanging photos" treatment he chose is kept intact: this
      file includes the shop's partial rather than re-drawing it.

    ▲ THE SELECTED ROW IS THE FIRST ONE THAT CAN BE BOUGHT, not row 0. A product
      whose first size is sold out highlighted nothing at all and armed the
      button with the sold-out row — the defect the shipped page's `$buyable`
      exists to answer, repeated here rather than quietly re-introduced in a
      drawing the owner is being asked to choose from.

    ▲ NOTHING HERE POSTS. These previews are drawings: there is no <form>, the
      rows are inert, and the Add to cart beside them is a button that submits
      nothing. A preview that could take money would be a second checkout path
      nobody has tested.
--}}
@php
    /* THE SAME FOUR FACTS THE SHIPPED PAGE WORKS OUT, WORKED OUT THE SAME WAY.

       These are computed in resources/views/store/product.blade.php's own @php
       block, which this lane may not touch and does not @include -- so they are
       recomputed here from the same inputs rather than passed through a
       controller that would then be building view state for a preview. Each
       line below is the shipped line, and the reasoning behind $buyable in
       particular is worth keeping in sight: `$variants->first()` regardless of
       stock is what left a product whose first size was sold out with NO option
       highlighted and an armed button pointing at the sold-out row.

       NO QUERY IS ADDED. `$product->variants` was eager-loaded by
       Store\ProductController::show() before this template was chosen, so every
       line here reads memory. StorefrontQueryBudgetTest's product-page budget
       does not move for a preview because a preview asks the controller the
       same question the page does and then draws it differently. */
    $variants = $product->variants;
    $isVar = $variants->isNotEmpty();
    $buyable = $isVar ? $variants->first(fn ($v) => $v->inStock()) : null;
    $hasBundle = $variants->contains(fn ($v) => (bool) $v->tag);
    // The shipped page's own two literals, in the shipped page's own @php
    // block, so this preview cannot say something the product page would not.
    $optNote = $hasBundle ? 'Save more with bundles' : $variants->count().' options';

    /* The headline pair's precision, so a 1-unit bundle row and the price at
       the top of the page are never two widths of the same number -- the rule
       the shipped page states at length above its own bundle loop. */
    $pvDpHead = $onSale
        ? \App\Support\Money::decimalsToDistinguish((int) $product->compareAtPrice(), $price)
        : null;
@endphp

@if ($isVar)
    <div class="pv-optlabel">{{ __('store.product.choose_option') }} <span>{{ $optNote }}</span></div>
    <div class="variants pv-variants">
        @foreach ($variants as $n => $v)
            @php
                $pvSale = $v->effectivePrice();
                $pvReg = (int) ($v->price ?: $pvSale);
                $pvOos = ! $v->inStock();
                $pvOff = ($pvReg > 0 && $pvSale < $pvReg) ? (int) round((1 - $pvSale / $pvReg) * 100) : 0;
                $pvVdp = ($pvSale < $pvReg) ? \App\Support\Money::decimalsToDistinguish($pvReg, $pvSale) : null;
                $pvImg = \App\Support\ImageVariants::variantUrl((string) $v->image, 400);
            @endphp
            <div class="variant{{ $buyable && $v->is($buyable) ? ' on' : '' }}{{ $pvOos ? ' oos' : '' }}">
                @if (($pvImgCss = \App\Support\CssUrl::value($pvImg)) !== '')<span class="vsw" style="background-image:url('{{ $pvImgCss }}')"></span>@else<span class="vr"></span>@endif<span class="vn">{{ $v->label() ?: __('store.product.option_fallback', ['number' => $n + 1]) }}</span><span class="vp">@if ($pvSale < $pvReg)<s>{!! \App\Support\Money::format($pvReg, $pvVdp) !!}</s>@endif{!! \App\Support\Money::format($pvSale, $pvVdp) !!}</span>@if ($pvOos)<span class="vtag sold">{{ __('store.product.sold_out_tag') }}</span>@elseif ($v->tag)<span class="vtag">{{ $v->tag }}</span>@elseif ($pvOff)<span class="vtag">{{ __('store.product.save_percent', ['percent' => $pvOff]) }}</span>@endif
            </div>
        @endforeach
    </div>
@elseif ($bundles)
    <div class="pv-optlabel">{{ __('store.product.choose_option') }} <span>{{ __('store.product.bundles_note') }}</span></div>
    <div class="variants pv-variants">
        @foreach ($bundles as $n => $b)
            @php
                $pvBdp = $b['saved'] > 0
                    ? \App\Support\Money::decimalsToDistinguish((int) $b['was'], (int) $b['total'])
                    : null;
                $pvBdp = max($pvBdp ?? \App\Support\Money::displayDecimals(), $pvDpHead ?? \App\Support\Money::displayDecimals());
            @endphp
            <div class="variant{{ 0 === $n ? ' on' : '' }}">
                <span class="vr"></span><span class="vn">{{ $b['label'] }}</span><span class="vp">@if ($b['saved'] > 0)<s>{!! \App\Support\Money::format($b['was'], $pvBdp) !!}</s>@endif{!! \App\Support\Money::format($b['total'], $pvBdp) !!}</span>@if ($b['tag'])<span class="vtag">{{ $b['tag'] }}</span>@endif
            </div>
        @endforeach
    </div>
@endif

@include('partials.set-contents-panel')
