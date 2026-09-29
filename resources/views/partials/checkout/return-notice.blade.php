{{--
    The one sentence a shopper needs when they come back from Tabby or Tamara
    without a payment, drawn on the basket page. (Lane PLC)

    ── WHY THE BASKET PAGE AND NOT THE CHECKOUT ────────────────────────────────

    The checkout already prints $errors->first() in its own .co-notices band and
    needs nothing from this partial. But CheckoutController::place() marks the
    basket `converted` inside the transaction that writes the order, and nothing
    on the return leg puts it back — so a shopper who abandoned at the provider
    has no active basket, and the checkout's own empty-basket guard would bounce
    them on to /cart/ and eat the flashed message on the way.

    Store\CheckoutReturnController sends such a visitor straight here instead,
    and this is what shows them why.

    ── IT DRAWS NOTHING WITHOUT A MESSAGE, AND THAT IS LOAD-BEARING ────────────

    $errors is empty on every ordinary visit to the basket, so this renders as
    the empty string and the page is byte for byte what it was — which is what
    keeps StorefrontEnglishUnchangedTest green for /cart and (with a basket)
    /cart, neither of which is ever walked with a flashed error.

    The markup and the inline style are copied from store/checkout.blade.php's
    own notice band rather than invented, so the two say the same thing in the
    same voice, and no new CSS ships with this.
--}}
@if ($errors->any())
<div class="co-notices" style="max-width:1040px;margin:0 auto;padding:16px 20px 0">
    <div class="co-note err" role="alert">{{ $errors->first() }}</div>
</div>
@endif
