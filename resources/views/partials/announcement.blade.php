{{--
    T-CHROME-2. Hidden when the Notification Bar module takes over, exactly as in
    the theme — the module renders its own bar at the top of the page and two
    stacked bars would look like a mistake.

    NOTHING INCLUDES THIS FILE (Lane DM). Three source comments described this
    strip as appearing "on every page, to every visitor" — this one, the header
    of ShippingService::thresholdHere() and the note over kbbFreeShipThreshold
    in StoreComposer — and tests/Feature/SitewideDeliveryClaimsTest renders it
    directly and asserts on its strings as shipped chrome. It is included by no
    layout and no page: `@include('partials.announcement')` appears nowhere in
    resources/views. The .anno rule survives in three stylesheets, so it was
    site chrome once.

    That matters for the two claims below rather than for the delivery figure.
    "Pay later with Tabby & Tamara" and "100% authentic K-beauty" are live
    marketing claims that nobody has reviewed, and they go out to every visitor
    of the site the moment anyone adds one include line — quietly, because the
    comments here say that is already the case. Whoever wires this up owes both
    claims a check first: Tabby and Tamara must actually be enabled as payment
    methods, and "100% authentic" is a statement about sourcing, not a slogan.

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
            {!! __('store.delivery.free_over', ['amount' => '<b>' . \App\Support\Money::format($kbbFreeShipThreshold, 0) . '</b>']) !!} ·
        @endif
        {{-- THE CLAIM IS A SETTING NOW, THOUGH NOTHING RENDERS THIS FILE — Lane DR.

             The header above is right that no layout includes this partial, so
             "100% authentic K-beauty" is not currently reaching anybody. It is
             one `@include` line away from the chrome of every page, and the
             comments in this repository already describe it as if it were
             there, so it goes through the same box as the claims that ARE live:
             App\Support\TrustClaims, defaulting to this wording, and cleared it
             disappears along with the separator in front of it.

             "Pay later with Tabby & Tamara" is NOT routed through TrustClaims
             and is left exactly as it was. It is a claim about which payment
             methods are enabled, which is a question `payment_providers`
             answers — a text box would be the wrong fix for it, and the right
             one belongs to whoever wires this strip up. The header above
             already records that debt.

             THE @if IS ON ITS OWN LINE, AND THAT IS NOT COSMETIC. Blade's
             directive pattern is anchored with \B, so an @if written directly
             after a word character — `Tamara@if (...)` — is not a directive at
             all: it is compiled through as literal text and its @endif then
             closes the @unless wrapping this file, which fails as "unexpected
             endif" somewhere else entirely. Confirmed here the hard way. --}}
        @php($annoAuth = \App\Support\TrustClaims::text($kbbSettings, 'anno_authentic_text'))
        {{ __('store.announcement.pay_later') }}
        @if ($annoAuth !== null)
            · <b>{{ $annoAuth }}</b>
        @endif
    </div>
@endunless
