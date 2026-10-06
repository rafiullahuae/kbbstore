{{--
    The checkout's order summary folded into ONE THIN ROW (Lane CK).

    The owner: "on checkout page i want to replace the whole summary section to
    this single thin row, with cart icon, Order Summary text, then order total,
    and then down pink (our color) arrow with slight continue animation ... do
    not remove any existing functinoality, just turn off."

    Appearance -> Checkout page -> Fields & attention -> "Order summary:
    collapsed to one row", ON as asked. Off, store/checkout.blade.php does not
    include this file and the summary renders exactly as it did.

    ── NOTHING IS REMOVED, ONLY FOLDED ────────────────────────────────────────

    Everything the summary held is still in the page, under this row, and is
    shown or hidden by ONE class on #kbbSummary (`cosr-open`) -- no request, no
    measuring, no height animated in script. Collapsed on a phone, the summary
    is just this row (its Place order already lives in the box at the foot of
    the page). Collapsed on a laptop, the lines, subtotal and delivery fold and
    Place order stays out, because the summary column is where that button is.

    ── THE TOTAL IS THE ORDER BLOCK'S OWN TOTAL ───────────────────────────────

    Same two spans the order block prints, worked out the same way and with the
    same classes, so every path that already updates the totals updates this
    row too: the country / emirate refresh and the gift toggle both write every
    `.js-total` and `.js-total-fee` on the page. The one path that does not --
    the summary's quantity steppers, which replace the order block wholesale --
    is followed by a MutationObserver on that block (an event, not a poll), so
    the row cannot be left showing yesterday's total. Which of the two spans
    shows is CSS, off the selected payment radio, exactly as the order block's
    two Total rows do.

    Server-rendered collapsed, at a fixed min-height, so nothing moves when the
    script arrives: CLS 0.
--}}
@php
    $kbbRowDp = app(\App\Services\CartService::class)->ledgerDecimals(
        $totals,
        (int) $settings->get('cod_fee', 0),
        (int) $settings->get('gift_fee', '1500'),
    );
    $kbbRowCod = (int) $settings->get('cod_fee', 0);
    $kbbRowGift = ($settings->get('gift_enabled', '1') && session('kbb_gift'))
        ? (int) $settings->get('gift_fee', '1500')
        : 0;
    $kbbRowCot = app(\App\Services\CheckoutPage::class)->desktopTotals();
@endphp
<button type="button" class="cosr" id="kbbSumRow" aria-expanded="false" aria-controls="kbbPanels"><span class="cosr-ic">{!! \App\Support\HeaderIcons::cart() !!}</span><span class="cosr-tx">{{ __('store.checkout.tab_summary') }}</span><span class="cosr-tot"><span class="js-total">{!! \App\Support\Money::format($totals['total'] + $kbbRowGift, $kbbRowDp) !!}</span><span class="js-total-fee">{!! \App\Support\Money::format($totals['total'] + $kbbRowCod + $kbbRowGift, $kbbRowDp) !!}</span></span><span class="cosr-chev" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span></button>
<script>
(function () {
  var box = document.getElementById('kbbSummary');
  var btn = document.getElementById('kbbSumRow');
  if (!box || !btn) { return; }
  btn.addEventListener('click', function () {
    var open = box.classList.toggle('cosr-open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  /* The quantity steppers replace the order block whole; copy its totals
     across when they do. Read from the server's own markup -- nothing here
     adds anything up. */
  document.addEventListener('DOMContentLoaded', function () {
    var slot = box.querySelector('.kbb-order-slot');
    if (!slot || !('MutationObserver' in window)) { return; }
    new MutationObserver(function () {
      ['js-total', 'js-total-fee'].forEach(function (c) {
        var from = slot.querySelector('.' + c);
        var to = btn.querySelector('.' + c);
        if (from && to) { to.innerHTML = from.innerHTML; }
      });
    }).observe(slot, { childList: true });
  });
})();
</script>
@push('styles')
<style>
/* ── THE ORDER SUMMARY ROW (Lane CK) ─────────────────────────────────────── */
.kbb-checkout .summary.cosr-on{padding-top:0}
.kbb-checkout .cosr{display:flex;align-items:center;gap:10px;width:100%;min-height:50px;padding:0;
  margin:0;border:0;border-bottom:1px solid var(--line-2);background:none;font:inherit;color:var(--ink);
  cursor:pointer;text-align:start;-webkit-tap-highlight-color:transparent}
.kbb-checkout .cosr-ic{flex:none;display:grid;place-items:center;width:30px;height:30px;border-radius:50%;
  background:var(--cream);color:var(--pink-deep)}
.kbb-checkout .cosr-ic svg{width:17px;height:17px}
.kbb-checkout .cosr-tx{flex:1;min-width:0;font-size:13.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kbb-checkout .cosr-tot{flex:none;font-size:14.5px;font-weight:800;white-space:nowrap}
.kbb-checkout .cosr .js-total-fee{display:none}
.kbb-checkout:has(#payment_method_cod:checked) .cosr .js-total{display:none}
.kbb-checkout:has(#payment_method_cod:checked) .cosr .js-total-fee{display:inline}
.kbb-checkout .cosr-chev{flex:none;display:grid;place-items:center;width:22px;height:22px;color:var(--pink);
  animation:cosrNudge 1.6s ease-in-out infinite}
.kbb-checkout .cosr-chev svg{width:18px;height:18px;transition:transform .2s ease}
@keyframes cosrNudge{0%,100%{transform:translateY(0)}50%{transform:translateY(3px)}}
.kbb-checkout .summary.cosr-open .cosr-chev{animation:none}
.kbb-checkout .summary.cosr-open .cosr-chev svg{transform:rotate(180deg)}
.kbb-checkout .cosr:focus-visible{outline:2px solid var(--pink);outline-offset:2px;border-radius:6px}
/* Folded: everything that is the SUMMARY goes; the actions stay. !important
   because the order block's COD Total row is shown by a rule carrying an id
   in :has(), which would otherwise outrank this one. */
.kbb-checkout .summary.cosr-on:not(.cosr-open) :is(.sumtabs,.co-items,.kbb-freeship-slot,.sumrow,.js-coupons,.peekfade,.viewfull){!! $kbbRowCot ? ':not(.cotot *)' : '' !!}{display:none!important}{{-- Lane CD: NOT inside .cotot while "Desktop: totals above Place order" is on -- that is the totals card, which stays out above Place order. Off, this rule is the bytes it was. --}}
.kbb-checkout .summary.cosr-on:not(.cosr-open) .cosr{border-bottom-color:transparent}
.kbb-checkout .summary.cosr-on .panels{margin-top:12px}
.kbb-checkout .summary.cosr-on :is(.viewfull,.peekfade){display:none}
@media(max-width:900px){
  /* A phone: folded, the card is the row alone. Open, it is the whole summary
     at full height -- the old 148px peek and its "View full summary" button
     belong to the summary this row replaces. */
  .kbb-checkout .summary.cosr-on{padding-bottom:0}
  .kbb-checkout .summary.cosr-on:not(.cosr-open) .panels{display:none}
  .kbb-checkout .summary.cosr-on.cosr-open{padding-bottom:var(--cop-asidepad)}
  .kbb-checkout .summary.cosr-on .panels{max-height:none;overflow:visible}
}
@media (prefers-reduced-motion:reduce){
  .kbb-checkout .cosr-chev{animation:none}
  .kbb-checkout .cosr-chev svg{transition:none}
}
</style>
@endpush
