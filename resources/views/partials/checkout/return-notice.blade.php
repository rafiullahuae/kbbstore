{{--
    What a shopper is told on the basket page after a payment that did not
    complete — and the one button that puts their basket back. (Lane PLC)

    ── WHY THE BASKET PAGE AND NOT THE CHECKOUT ────────────────────────────────

    The checkout already prints $errors->first() in its own .co-notices band and
    needs nothing from this partial. But CheckoutController::place() marks the
    basket `converted` inside the transaction that writes the order, so a
    shopper who abandoned at Tabby or Tamara has no active basket, and the
    checkout's own empty-basket guard would bounce them on to /cart/ and eat the
    flashed message on the way.

    Store\CheckoutReturnController sends such a visitor straight here instead,
    and this is what shows them why — and what offers them the way back.

    ── THE BUTTON IS A POST, AND IT IS A BUTTON RATHER THAN A SCRIPT ───────────

    Pressing it moves the order to `failed` and hands the stock and the coupon
    back with it. The whole of why that is the shopper's press to make rather
    than something the page does on arrival is written out on
    CheckoutReturnController::restore(); the short version is that a shopper who
    backed out at the provider may still go back and finish, and killing their
    order the instant they touch our return address takes that away silently.

    It is offered only when the controller has found something to put back AND
    the offer still names the order `kbb_last_order` does — one reader,
    CheckoutReturnController::offeredOrderNumber(), which restore() gates on
    too, so a button is drawn exactly when pressing it would work. Reading it
    costs the basket page no query at all.

    ── IT DRAWS NOTHING WITHOUT SOMETHING TO SAY, AND THAT IS LOAD-BEARING ─────

    $errors is empty and no session key is set on every ordinary visit to the
    basket, so this renders as the empty string and the page is byte for byte
    what it was — which is what keeps StorefrontEnglishUnchangedTest green
    for /cart and (with a basket) /cart, neither of which is ever walked with a
    flashed message.

    The class names are store/checkout.blade.php's own notice band, so the two
    say the same thing in the same voice. The RULES for them are not: `.co-note`
    lives in resources/css/kbb/kbb-checkout.css, which only the checkout and the
    order-received page load — measured, and the first shot of this state was a
    correct sentence in unstyled body text on the basket page. So the handful of
    declarations travel with the message, inside the @if, and a basket page with
    nothing to say still ships not one byte of them.
--}}
@php
    // THE SAME COMPARISON restore() MAKES, not merely "is the key set". See
    // CheckoutReturnController::offeredOrderNumber(): the offer carries an order
    // NUMBER and `kbb_last_order` moves with every order, so asking only whether
    // the key existed drew a button the endpoint was about to refuse.
    $kbbReturnRestorable = \App\Http\Controllers\Store\CheckoutReturnController::offeredOrderNumber() !== '';
    $kbbReturnRestored = session(\App\Http\Controllers\Store\CheckoutReturnController::RESTORED_KEY) !== null;
@endphp
@if ($errors->any() || $kbbReturnRestored || $kbbReturnRestorable)
<style>
.kbb-cartpage .co-notices{max-width:1040px;margin:0 auto;padding:0 0 14px}
.kbb-cartpage .co-note{border-radius:12px;padding:12px 15px;font-size:13px;font-weight:600;line-height:1.5}
.kbb-cartpage .co-note.err{background:#FDECEF;border:1px solid #F3C4CE;color:#A82F53}
.kbb-cartpage .co-note.ok{background:#EEF8F1;border:1px solid #BFE0CD;color:#1F7D52}
/* The button sits INSIDE the notice, on its own line, because it is the answer
   to the sentence above it rather than a control of the basket. Logical
   properties throughout: margin-block-start rather than margin-top, and no
   [dir] selector anywhere — shot on /ar with the mirrored layout on. */
.kbb-cartpage .co-note form{margin-block-start:10px}
.kbb-cartpage .co-restore{display:inline-flex;align-items:center;gap:8px;border:0;cursor:pointer;
  background:#E0567B;color:#fff;border-radius:99px;padding:11px 20px;font-family:inherit;
  font-size:13px;font-weight:700;line-height:1.2}
.kbb-cartpage .co-restore:hover{background:#C13E63}
.kbb-cartpage .co-restore svg{width:16px;height:16px;flex-shrink:0}
</style>
<div class="co-notices">
@if ($kbbReturnRestored)
    <div class="co-note ok" role="status">{{ __('store.checkout.restore_done') }}</div>
@else
    {{-- $errors->first() is '' on a RELOAD, and the sentence has to survive one:
         the offer is a session value rather than a flash precisely so that
         reloading the basket page does not take the way back away, and drawing
         nothing without a flash defeated that. The generic sentence is the one
         that does not name a provider this request no longer knows. --}}
    <div class="co-note err" role="alert">{{ $errors->first() ?: __('store.checkout.return_not_completed') }}
@if ($kbbReturnRestorable)
        <form method="post" action="{{ \App\Support\Url::to('/checkout/restore-basket') }}">
            @csrf
            <button type="submit" class="co-restore">
                {{-- A CONSTANT, drawn here and reachable from nothing: the
                     counter-clockwise arrow every undo on this shop uses. --}}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>
                {{ __('store.checkout.restore_basket') }}
            </button>
        </form>
@endif
    </div>
@endif
</div>
@endif
