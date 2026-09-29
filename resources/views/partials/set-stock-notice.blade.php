{{--
    ONE JAR, CLAIMED TWICE — what the shopper is told. (Lane SEC)

    A basket holding a set AND a product that is inside it asked for the same
    unit twice, and the order could not be placed at all: Place order answered
    "1025 Dokdo Toner (in Medicube booster set) is sold out" for ever, because
    nothing the shopper could press changed the basket. App\Services\SetStock-
    Reconciler now takes the loose line out and keeps the set — the owner's
    decision — and this is the sentence that says so.

    ONE PARTIAL FOR BOTH PAGES, so the cart and the checkout cannot end up
    saying two different things about the same event. `$setStockNotices` is the
    list SetStockReconciler returned; both controllers hand it over.

    IT RENDERS ZERO BYTES WHEN THERE IS NOTHING TO SAY, which is every render on
    a shop where no set has claimed the last of anything — the @forelse's empty
    branch is empty, so StorefrontEnglishUnchangedTest still sees the page it
    saw before.

    ESCAPED, BOTH NAMES. `:product` and `:set` are product names out of the
    database — operator-editable text, never a constant — so this goes through
    {{ }} and never {!! !!}. The keys carry no markup, and
    ArabicInterfaceDraftsTest's parity case is what keeps the Arabic that way
    too.

    NO NEW CSS. `.woocommerce-info` is already styled on `.kbb-cartpage`
    (resources/css/kbb/kbb-cart.css) and on `.kbb-checkout`
    (resources/css/kbb/kbb-checkout.css), both of which belong to other lanes
    this round.
--}}
@foreach ($setStockNotices ?? [] as $kbbSetStockNotice)
    <div class="woocommerce-info" role="status">{{ $kbbSetStockNotice['left'] === 0
        ? __('store.cart.set_took_the_last_one', [
            'product' => $kbbSetStockNotice['product'],
            'set' => $kbbSetStockNotice['set'],
        ])
        : __('store.cart.set_took_some', [
            'product' => $kbbSetStockNotice['product'],
            'left' => $kbbSetStockNotice['left'],
            'set' => $kbbSetStockNotice['set'],
        ]) }}</div>
@endforeach
