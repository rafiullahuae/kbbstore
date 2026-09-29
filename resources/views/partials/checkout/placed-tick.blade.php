{{--
    THE RETURN LEG — the tick that is waiting when a shopper comes back. (Lane PLC)

    ═══════════════════════════════════════════════════════════════════════════
    WHAT IT IS FOR
    ═══════════════════════════════════════════════════════════════════════════

    The owner, on Tabby and Tamara: "it will show if possible nicely along with
    the redirection to the tabby or tamara site to complete the order, and will
    come back with a succesfull tick star, and show the order details page."

    Nothing of the checkout's animation survives that round trip and none of it
    needs to. The leaving state is one page and this is another, and this one
    reads its state from the ORDER, on the server, at render time.

    ═══════════════════════════════════════════════════════════════════════════
    THE ONE RULE: NEVER A TICK FOR A PAYMENT THAT HAS NOT BEEN APPROVED
    ═══════════════════════════════════════════════════════════════════════════

    A shopper arriving at /checkout/success is not evidence of anything. Tamara
    and Tabby send them to that address when the plan is approved, and a shopper
    can also reach it by pressing Back, by re-opening a tab, or from a bookmark;
    the shop's own confirmation arrives separately, by webhook, and a fast
    connection beats it home.

    So the tick is App\Services\Checkout\PlacementState's answer for THIS order
    and nothing else — `paid_at`, the order's status, and (for cash on delivery,
    which never carries `paid_at`) the gateway's own journey(). Not the URL, not
    a query parameter, not anything the browser carried back. A tick over an
    unapproved order is the single worst outcome available on this page.

    ═══════════════════════════════════════════════════════════════════════════
    WHY IT ONLY DRAWS FOR A GATEWAY THE SHOPPER ACTUALLY LEFT THE SHOP FOR
    ═══════════════════════════════════════════════════════════════════════════

    Cash on delivery and the card fields already showed their tick on the
    checkout, in the document that placed the order, a moment before navigating
    here. Drawing a second one would be two ticks for one order.

    The question "did they leave" is asked of the gateway —
    PaymentGateway::journey() === 'redirect' — and not of a list of names here.
    So a redirect gateway added next year gets this behaviour with no edit to
    this file, and the order-received page for every OTHER kind of order renders
    byte for byte what it rendered before this package. That is also what keeps
    StorefrontEnglishUnchangedTest green: the walk requests /checkout/success
    with no order at all, and this partial draws nothing without one.

    ═══════════════════════════════════════════════════════════════════════════
    THE BOUND. A SPINNER WITH NO END IS THE FAILURE MODE HERE
    ═══════════════════════════════════════════════════════════════════════════

    Two mechanisms, and the safety one is the one that cannot fail:

      1. THE CARD TAKES ITSELF DOWN IN CSS. `.is-selfclosing` runs `kbbp-out`
         with `forwards` after --kbbp-hold, ending at visibility:hidden and
         pointer-events:none. It is scheduled by the document that drew it, it
         needs no script, and it cannot be prevented by one failing to run. So
         the worst case — a browser with JavaScript off, a bundle that did not
         load, an error thrown by something else on the page — is a card that
         is gone a few seconds later, over an order-received page that was fully
         rendered underneath it the whole time.

      2. ONE REFRESH, AND ONLY WHEN THERE IS SOMETHING TO WAIT FOR. When the
         payment is not confirmed yet, the page reloads ITSELF ONCE after 4.5
         seconds — soon enough to catch the ordinary webhook, and once, because
         the marker that it has happened is in the address the reload goes to.
         A second pass never schedules a third. This was chosen over polling an
         endpoint (a new unauthenticated surface, a second way to ask about an
         order, and a rate limit to get right) and over asking the provider
         synchronously while rendering (a third-party network call inside a page
         render, which is how a slow provider becomes a slow shop).

    location.replace, not assign: the shopper's Back button should take them
    where they came from, not to the pre-refresh copy of this same page.

    ═══════════════════════════════════════════════════════════════════════════

    Expects: $order (App\Models\Order|null), as store/checkout-success has it.
--}}
@php
    $kbbPlaced = app(\App\Services\Checkout\PlacementState::class);

    /*
     * The marker for "this page has already refreshed itself once". Read as a
     * presence test and never echoed: what a shopper types into it cannot reach
     * the page, and the address the reload goes to is BUILT below from the
     * order's own number rather than assembled out of the current query string.
     */
    $kbbConfirmingPass = request()->query('confirming') !== null;

    /*
     * ONLY ON THE ARRIVAL, NOT ON EVERY LATER VISIT.
     *
     * `kbb_last_order` is the marker place() writes into the session, and this
     * view consumes it further down for the Purchase pixel — so it is true
     * exactly once, for the browser that placed this order, on the visit it
     * comes back on. Reading it here does not consume it; the pixel's own
     * @php block still gets it.
     *
     * Without this gate a shopper opening the receipt again a week later, from
     * the confirmation email or a bookmark, would be shown a celebration for
     * something that happened last Tuesday. `confirming` carries the second
     * pass, which is this page reloading ITSELF and therefore still the arrival.
     */
    $kbbArrival = $kbbConfirmingPass
        || (string) session('kbb_last_order', '') === (string) ($order?->order_number ?? '');

    $kbbLeftShop = $kbbArrival && $kbbPlaced->leftTheShopFor($order ?? null);
    $kbbPlacedState = $kbbLeftShop ? $kbbPlaced->forOrder($order) : null;
@endphp
@if ($kbbPlacedState === \App\Services\Checkout\PlacementState::CONFIRMED)
<!--kbb-placed-->@include('partials.checkout.placing-style')
@include('partials.checkout.placing-card', [
    'label' => __('store.order_received.placed_label'),
    'title' => __('store.order_received.placed_title'),
    'note' => '',
    'classes' => 'is-up is-done is-selfclosing',
    'hold' => '1.25s',
    'modal' => false,
])
<!--/kbb-placed-->
@elseif ($kbbPlacedState === \App\Services\Checkout\PlacementState::AWAITING)
<!--kbb-placed-->@include('partials.checkout.placing-style')
@include('partials.checkout.placing-card', [
    'label' => __('store.order_received.placed_label'),
    'title' => __('store.order_received.confirming_title'),
    'note' => $kbbConfirmingPass ? __('store.order_received.confirming_slow') : __('store.order_received.confirming_note'),
    'classes' => 'is-up is-selfclosing',
    /* The first pass holds until just after the reload would have fired, so the
       card does not blink out and back in; the second holds long enough to be
       read and no longer. */
    'hold' => $kbbConfirmingPass ? '3s' : '5.5s',
    'modal' => false,
])
@if (! $kbbConfirmingPass)
<script>
/* THE ONE REFRESH. Guarded by the same condition that rendered it, so a page
   that has already been through here does not schedule another — the bound is
   in the address, not in a counter this script has to keep. */
window.setTimeout(function () {
  window.location.replace(@json(\App\Support\Url::redirect('/checkout/success', request()).'?order='.urlencode((string) $order->order_number).'&confirming=1'));
}, 4500);
</script>
@endif
<!--/kbb-placed-->
@endif
