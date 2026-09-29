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

    The class names are store/checkout.blade.php's own notice band, so the two
    say the same thing in the same voice. The RULES for them are not: `.co-note`
    lives in resources/css/kbb/kbb-checkout.css, which only the checkout and the
    order-received page load — measured, and the first shot of this state was a
    correct sentence in unstyled body text on the basket page. So the handful of
    declarations travel with the message, inside the @if, and a basket page with
    nothing to say still ships not one byte of them.
--}}
@if ($errors->any())
<style>
.kbb-cartpage .co-notices{max-width:1040px;margin:0 auto;padding:0 0 14px}
.kbb-cartpage .co-note{border-radius:12px;padding:12px 15px;font-size:13px;font-weight:600;line-height:1.5}
.kbb-cartpage .co-note.err{background:#FDECEF;border:1px solid #F3C4CE;color:#A82F53}
</style>
<div class="co-notices">
    <div class="co-note err" role="alert">{{ $errors->first() }}</div>
</div>
@endif
