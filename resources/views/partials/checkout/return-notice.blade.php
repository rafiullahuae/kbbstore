{{--
    What a shopper is told on the basket page after a payment that did not
    complete. (Lane PLC; Lane BK)

    ── THE BASKET IS ALREADY BACK WHEN THIS DRAWS (Lane BK) ────────────────────

    This used to sit over an EMPTY bag with a "Put my basket back" button —
    the owner's screenshot. The return itself now gives the basket back
    (App\Services\Checkout\UnfinishedPayment), so the page under this notice
    lists every line, and the notice says the two true things calmly and offers
    the one next step: Try again, to the checkout, where the shopper's details
    and chosen delivery are still remembered in their browser.

    UnfinishedPayment::noticeFor() is 'restored' or 'merged' (they had started
    a new basket in between; the lines were added to it, and the sentence says
    so) -- from this request's flash, or from ?back= when this session really
    was given that basket back. A crafted ?back= on anybody else draws nothing.

    ── IT DRAWS NOTHING WITHOUT SOMETHING TO SAY, AND THAT IS LOAD-BEARING ─────

    $errors is empty and the key unset on every ordinary visit, so this renders
    as the empty string and the page is byte for byte what it was — which keeps
    StorefrontEnglishUnchangedTest green. The handful of declarations travel
    with the message, inside the @if: `.co-note` lives in kbb-checkout.css,
    which the basket page does not load. Logical properties, no [dir] selector:
    the same rules serve /ar mirrored. Server-rendered, so nothing shifts.
--}}
@php
    $kbbBack = \App\Services\Checkout\UnfinishedPayment::noticeFor(request());
@endphp
@if ($errors->any() || $kbbBack !== '')
<style>
.kbb-cartpage .co-notices{max-width:1040px;margin:0 auto;padding:0 0 14px}
.kbb-cartpage .co-note{border-radius:12px;padding:12px 15px;font-size:13px;font-weight:600;line-height:1.5}
.kbb-cartpage .co-note.err{background:#FDECEF;border:1px solid #F3C4CE;color:#A82F53}
.kbb-cartpage .co-note.ok{background:#EEF8F1;border:1px solid #BFE0CD;color:#1F7D52}
.kbb-cartpage .co-note .co-retry{display:inline-flex;align-items:center;margin-block-start:10px;
  background:#1F7D52;color:#fff;border-radius:99px;padding:10px 20px;min-height:40px;box-sizing:border-box;
  font-size:13px;font-weight:700;line-height:1.2;text-decoration:none}
.kbb-cartpage .co-note .co-retry:hover{background:#196A45}
</style>
<div class="co-notices">
@if ($kbbBack !== '')
    <div class="co-note ok" role="status">{{ $kbbBack === 'merged' ? __('store.checkout.return_merged') : __('store.checkout.return_restored') }}<br><a class="co-retry" href="{{ \App\Support\Url::to('/checkout/') }}">{{ __('store.checkout.return_try_again') }}</a></div>
@else
    <div class="co-note err" role="alert">{{ $errors->first() }}</div>
@endif
</div>
@endif
