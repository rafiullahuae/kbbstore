{{--
    THE EXIT POPUP ON THE SHOP (Lane GO). Built, and OFF by default (the
    owner: "build the popup function also but keep turned off by default").

    $goPop  GiftOffers::popup(): null while the popup is off (no query), for
            an admin, an empty basket, a basket already holding a gift, or a
            range that gives nothing -- and then this prints NOTHING and no
            script is ever loaded.

    With it: an inert <template> (the real popup markup and its styles, parsed
    but never rendered until needed) and a ~600-byte inline watcher. The
    watcher waits for real exit intent -- the mouse leaving through the top of
    the window on a computer; leaving the tab or app and coming back on a
    phone -- at most once per session, not for 24 hours after "Maybe later",
    never after a submit (Place order) and, with "Checkout" chosen as the
    audience, only after the shopper did something on the checkout. Only then
    is the popup script (resources/js/kbb/gift-offer-popup.js, ~2 KB) fetched,
    async. Nothing in the head, nothing render-blocking, nothing measured.
    Inside the cart panel's fragment, so a basket change redraws the gift for
    the new range.
--}}@if (! empty($goPop))<template data-kgo-pop data-r="{{ $goPop['range_id'] }}" data-aud="{{ $goPop['audience'] }}" data-min="{{ $goPop['minutes'] }}" data-claim="{{ \App\Support\Url::to('/gift-offer/popup') }}" data-ev="{{ \App\Support\Url::to('/gift-offer/event') }}" data-js="{{ $goPop['js'] }}">@include('partials.gift-offers.popup-css')@include('partials.gift-offers.popup-card', ['p' => $goPop])</template>@endif
