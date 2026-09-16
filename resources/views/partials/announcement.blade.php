{{--
    T-CHROME-2. Hidden when the Notification Bar module takes over, exactly as in
    the theme — the module renders its own bar at the top of the page and two
    stacked bars would look like a mistake. That module is off by default, so
    this is the branch a stock install runs, on every page, to every visitor.

    THE FREE-DELIVERY FIGURE IS THE VISITOR'S OWN, OR IT IS NOT SHOWN.

    This strip carried a shipping-cost promise scoped to one country — the
    shop's own — and showed it to everybody. It was the largest instance left of
    the defect already removed from the checkout, the home page's delivery band,
    the order confirmation and the product page: one country's delivery terms
    read aloud to a shopper standing somewhere else. Being in the site chrome,
    it outnumbered all four put together.

    TWO THINGS WERE WRONG, not one.

    The COUNTRY: StoreComposer asked ShippingService for the threshold of
    `store_country`, so the figure described Dubai wherever the shopper was.
    It now asks for the country ShopperCountry resolves — the one answer this
    application gives to "where is this shopper" — and the bar quotes the
    figure that would actually apply to them.

    The FALLBACK: when no free-delivery method existed for that country the
    template printed a number of its own anyway, so a shop that had never
    offered free delivery advertised one, and a Gulf shopper whose zone has no
    free-shipping method was quoted the UAE's. A null threshold now removes the
    segment instead. Nothing is invented; the rest of the bar stands on its own.

    AND IT MAKES NO COUNTRY CLAIM AT ALL. Naming the destination was the other
    option and was rejected: outside the checkout the country here is a GUESS
    (ShopperCountry::guessed() is true for a header, a time-zone cookie and the
    store default alike), and printing "free delivery to <guess>" states that
    guess back to the shopper as a fact about their order. The figure alone is
    true for whoever is reading it and asserts nothing about where they are.

    The threshold must never be cached in a shared cache — see the note over
    the header extras in App\View\Composers\StoreComposer.
--}}
@unless ($kbbSettings->moduleEnabled('notification_bar'))
    <div class="anno">
        ✨
        @if ($kbbFreeShipThreshold !== null)
            Free delivery over <b>{!! \App\Support\Money::format($kbbFreeShipThreshold, 0) !!}</b> ·
        @endif
        Pay later with Tabby &amp; Tamara · <b>100% authentic</b> K-beauty
    </div>
@endunless
