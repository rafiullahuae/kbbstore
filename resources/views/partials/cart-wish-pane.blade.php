{{--
    The cart panel's heart tab: what is inside #kcWish. (Lane HLB, 17 October 2026)

    The owner: "in mobile the wishlist will be move to the cart panel beside
    browsed tab", then "only the heart icon ... at the end of the cart panel,
    same for arabic". The rows are the Browsed rows (the round-2 picture he
    approved, docs/lane-hl/round2/cart-heart-nobadge-filled-390.png) with a
    filled heart that takes the product off the list beside the + that adds it
    to the bag.

    Drawn by partials/cart-drawer with the page, and by
    WishlistController::toggle() into the reply of the request a heart already
    makes -- so a heart pressed anywhere updates this list with no request of
    its own. $wish is a collection of products already loaded by
    App\Support\CartPanelLists (no query here); $inCart the product ids in the
    bag; $wishTotal how many the wishlist holds in all.

    Every value is {{ }}-escaped; the hearts and the plus are constants.
--}}
@php
    use App\Support\CssUrl;
    use App\Support\Gradient;
    $kbbWp = app(\App\Services\CartPanel::class);
    $kbbWin = $inCart ?? [];
@endphp
@if ($wish->isNotEmpty())
    <div class="dbody">
        @foreach ($wish as $wp)
            @php
                $wbrand = $wp->brand?->t('name') ?? '';
                $wname  = $wp->t('name');
                $wseed  = ($wp->brand?->name ?? '') . $wp->name;
                $wimgCss = CssUrl::value(\App\Support\ImageVariants::variantUrl((string) $wp->image, 400));
                $wthumb = $wimgCss !== ''
                    ? "background:#fff url('" . e($wimgCss) . "') center/cover"
                    : 'background:' . Gradient::for($wseed);
                $kbbWSet = \App\Support\SetContents::fromProduct($wp);
                $wIn  = in_array($wp->id, $kbbWin, true);
                $wOut = $wp->stock_status !== 'instock';
                $wLbl = $wOut
                    ? __('store.cart_drawer.browsed_sold_out')
                    : ($wIn ? __('store.cart_drawer.browsed_in_bag') : __('store.cart_drawer.browsed_add'));
            @endphp
            <div class="kc-item" data-wrow="{{ $wp->id }}">
                <a class="kc-th" href="{{ $wp->url() }}" style="{{ $wthumb }}">{{ $wimgCss !== '' ? '' : Gradient::initials($wbrand ?: $wname) }}</a>
                <div class="kc-mid">
                    <div class="kc-nm"><a href="{{ $wp->url() }}">{{ $wname }}</a></div>
                    @if ($kbbWSet['members'])@include('partials.set-row', ['contents' => $kbbWSet, 'surface' => 'browsed', 'key' => 'w' . $wp->id])@endif<div class="kc-pr" style="font-size:12.5px">{!! \App\Support\Money::format($wp->effectivePrice()) !!}</div>
                </div>
                <div class="kc-right">
                    <button type="button" class="kc-wrm on" data-kbb-wish="{{ $wp->id }}" aria-label="{{ $kbbWp->get('txt_wish_remove') }}"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.2-9.4A5 5 0 0 1 12 6a5 5 0 0 1 9.2 5.1C19.5 15.9 12 20.5 12 20.5z"/></svg></button>
                    <button type="button" class="kc-badd{{ $wIn ? ' in' : '' }}" data-add="{{ $wp->id }}" @disabled($wOut) aria-label="{{ $wLbl }}" title="{{ $wLbl }}">
                        @if ($wIn)
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                        @else
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        @endif
                    </button>
                </div>
            </div>
        @endforeach
        @if (($wishTotal ?? 0) > $wish->count())
            <a class="kc-wall" href="{{ \App\Support\Url::to('/my-wishlist/') }}">{{ $kbbWp->get('txt_tab_wish') }} ({{ (int) $wishTotal }})</a>
        @endif
    </div>
@else
    <div class="dbody"><div class="empty-d kc-wempty"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.2-9.4A5 5 0 0 1 12 6a5 5 0 0 1 9.2 5.1C19.5 15.9 12 20.5 12 20.5z"/></svg>{{ $kbbWp->get('txt_wish_none') }}<br>{{ $kbbWp->get('txt_wish_none_sub') }}</div></div>
@endif
