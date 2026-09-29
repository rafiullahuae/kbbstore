{{--
    The Place-order overlay's stylesheet. (Lane PLC)

    ONE COPY, INCLUDED BY BOTH LEGS. partials/checkout/placing-overlay draws the
    overlay on the checkout and partials/checkout/placed-tick draws the same
    card on the order-received page when a shopper comes back from Tabby or
    Tamara. Two copies of these rules would be two palettes a release apart, and
    the whole point of the return leg is that it looks like the same overlay
    continuing.

    IT IS A <style> IN A VIEW, NOT resources/css. The reason is the one
    partials/checkout/express-wallets states at length: this host serves BUILT
    assets and has no Node, so anything added under resources/css ships INERT
    until somebody rebuilds the bundle off-server. A checkout that freezes and
    never draws the overlay it froze for is worse than one that never froze.

    SELF-CONTAINED. The overlay is appended to <body>, outside .kbb-checkout,
    so none of that section's custom properties are in scope. Its palette is
    restated as literal values rather than inherited, and the values are
    kbb-checkout.css's own: --pink #E0567B, --pink-deep #C13E63, --ink #2A2228,
    --muted #8C828A, --cream #FFF8F5, and the confirmation green #2E9E68 that
    store/checkout-success already paints its heading with.

    NOT ONE [dir] SELECTOR. The card is centred, the text is centred, and the
    only asymmetric thing on it -- the tick -- is a glyph that is NOT mirrored
    in right-to-left scripts. Everything that could need a side uses a logical
    property. Shot on /ar at 390 and 1280.
--}}<style>
.kbb-placing{position:fixed;inset:0;z-index:9000;display:flex;align-items:center;justify-content:center;
  padding:24px;background:rgba(255,248,245,.95);opacity:0;
  /* THE FREEZE. touch-action stops a finger dragging the page underneath and
     overscroll-behavior stops the wheel chaining to it. Neither needs
     overflow:hidden on <body>, which removes the desktop scrollbar and shifts
     the whole page sideways at the exact moment the shopper is watching it. */
  touch-action:none;overscroll-behavior:none;
  transition:opacity .18s cubic-bezier(.22,.61,.36,1)}
/* THE BLUR, WITH THE SOLID TINT AS ITS FALLBACK AND NOT THE OTHER WAY ROUND.
   backdrop-filter is missing on older Safari and on Firefox before 103, and can
   be switched off by the reader. So the rule above -- a 95%-opaque cream wash
   that hides the page perfectly well -- is what EVERY browser gets, and only a
   browser that says it can blur is given the translucent version. Written this
   way round rather than as `@supports not (…)`, which is itself unsupported on
   the oldest browsers this has to be right on. Shot both ways. */
@supports ((backdrop-filter:blur(2px)) or (-webkit-backdrop-filter:blur(2px))){
  .kbb-placing{background:rgba(255,248,245,.55);-webkit-backdrop-filter:blur(10px) saturate(1.06);backdrop-filter:blur(10px) saturate(1.06)}
}
.kbb-placing.is-up{opacity:1}
.kbb-placing-card{background:#fff;border-radius:20px;padding:32px 28px 26px;width:100%;max-width:330px;
  text-align:center;box-shadow:0 30px 70px -32px rgba(42,34,40,.5),0 0 0 1px rgba(42,34,40,.05);
  opacity:0;transform:translateY(10px) scale(.97);
  transition:opacity .24s cubic-bezier(.22,.61,.36,1),transform .3s cubic-bezier(.22,.61,.36,1)}
.kbb-placing.is-up .kbb-placing-card{opacity:1;transform:none}
.kbb-placing-mark{position:relative;width:96px;height:96px;margin:0 auto 18px}
.kbb-placing-mark > svg{position:absolute;inset:0;width:100%;height:100%;display:block}
/* ── the indicator ──────────────────────────────────────────────────────────
   pathLength="100" is what keeps this honest AND what keeps it out of
   JavaScript: the dash figures are percentages of the circle, so nothing has to
   ask the browser how long the path is. The fill runs to 88% and STOPS, because
   the shop does not know how far along the order is. It completes only when the
   server has answered — which is the whole of "never show the tick before the
   server has confirmed". */
.kbb-placing-ring{animation:kbbp-spin 2.6s linear infinite}
.kbb-placing-track{fill:none;stroke:#F6E6EC;stroke-width:6}
.kbb-placing-fill{fill:none;stroke:#E0567B;stroke-width:6;stroke-linecap:round;
  stroke-dasharray:100;stroke-dashoffset:96;animation:kbbp-fill 7s cubic-bezier(.16,.84,.3,1) forwards}
@keyframes kbbp-spin{from{transform:rotate(-90deg)}to{transform:rotate(270deg)}}
@keyframes kbbp-fill{from{stroke-dashoffset:96}to{stroke-dashoffset:12}}
/* ── the tick ─────────────────────────────────────────────────────────────── */
.kbb-placing-tick{opacity:0;transform:scale(.55) rotate(-24deg)}
.kbb-placing-halo{fill:#EDF9F2}
.kbb-placing-burst{fill:#CBEBD9}
.kbb-placing-disc{fill:#2E9E68}
.kbb-placing-check{fill:none;stroke:#fff;stroke-width:5.4;stroke-linecap:round;stroke-linejoin:round;
  stroke-dasharray:100;stroke-dashoffset:100}
.kbb-placing-ping{position:absolute;inset:8px;border-radius:50%;border:2px solid #2E9E68;opacity:0;pointer-events:none}
.kbb-placing.is-done .kbb-placing-ring{opacity:0;transition:opacity .2s linear}
.kbb-placing.is-done .kbb-placing-tick{opacity:1;transform:none;
  transition:opacity .2s ease-out,transform .46s cubic-bezier(.2,1.45,.36,1)}
.kbb-placing.is-done .kbb-placing-check{animation:kbbp-draw .36s .18s cubic-bezier(.65,0,.35,1) forwards}
.kbb-placing.is-done .kbb-placing-ping{animation:kbbp-ping .7s .1s cubic-bezier(.16,.84,.3,1) forwards}
@keyframes kbbp-draw{to{stroke-dashoffset:0}}
@keyframes kbbp-ping{0%{opacity:.55;transform:scale(.7)}100%{opacity:0;transform:scale(1.5)}}
/* ── the words ────────────────────────────────────────────────────────────── */
.kbb-placing-title{margin:0;font-size:16px;font-weight:800;color:#2A2228;line-height:1.35}
.kbb-placing-note{margin:7px 0 0;font-size:12.5px;font-weight:500;color:#8C828A;line-height:1.5}
.kbb-placing-note:empty{display:none}
.kbb-placing-out{display:inline-flex;align-items:center;gap:7px;margin-top:12px;padding:11px 18px;
  border-radius:99px;background:#E0567B;color:#fff;font-size:13px;font-weight:700;text-decoration:none}
.kbb-placing-out:hover{background:#C13E63;color:#fff}
.kbb-placing-sr{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;
  clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;border:0}
@media (max-width:420px){
  .kbb-placing{padding:18px}
  /* NOT max-width:100%. At 390 the overlay's own 18px of padding leaves 354,
     and a card allowed to fill it reads as a page rather than as a card over
     one. Measured at 390x844: 330 wide with 12px of cream either side. */
  .kbb-placing-card{padding:28px 22px 24px}
  .kbb-placing-mark{width:84px;height:84px;margin-bottom:16px}
}
/* ── THE RETURN LEG'S OWN BEHAVIOUR: IT TAKES ITSELF DOWN, IN CSS ──────────
   The order-received page renders this card already up, so that a shopper
   coming back from Tabby or Tamara sees the state of their order at FIRST
   PAINT rather than after a script has run. That makes getting it back down
   the safety question, and the answer may not be JavaScript: an overlay that
   needs a script to disappear is an overlay that covers the whole order-
   received page for ever on a browser where the script did not run.

   So the dismissal is an animation with `forwards`. It is scheduled by the
   document that drew it, it cannot fail to start, and it ends at
   visibility:hidden AND pointer-events:none so the page underneath is both
   readable and clickable. The delay is set per state by an inline custom
   property on the element — see partials/checkout/placed-tick. */
.kbb-placing.is-selfclosing{animation:kbbp-out .4s var(--kbbp-hold,1.25s) forwards;
  /* AND IT NEVER TAKES A CLICK. The checkout's overlay is modal because an
     order is in flight and the page behind it must not be touched. This one is
     over a RECEIPT the shopper came back to read, and while the payment is
     still being confirmed it can be on screen for four or five seconds — which
     is a long time to be unable to press "Track your order" on your own order.
     The card carries no controls, so nothing is lost by letting every press
     through to the page underneath from the first frame. */
  pointer-events:none}
@keyframes kbbp-out{to{opacity:0;visibility:hidden;pointer-events:none}}
/* ── prefers-reduced-motion ────────────────────────────────────────────────
   NON-NEGOTIABLE, and this is the shape of the compromise. Everything that
   MOVES is gone: the ring stops rotating, the card stops rising, the star stops
   scaling and swinging in, the expanding ping is removed entirely, and the tick
   is drawn ALREADY COMPLETE rather than stroked on.

   That last one was a correction, made after looking at the picture. The first
   version kept the stroke drawing on the reasoning that it was information
   rather than decoration — and the 1280 shot caught it at 250ms showing a
   green badge with half a tick in it, which is not a slower confirmation, it is
   a different and meaningless symbol. A reader who has asked for less motion
   gets the finished mark at once.

   What DOES stay is the ring's own fill while the order is in flight, because
   that is the only indication that the shop is working at all, and an indicator
   that never changes is indistinguishable from a page that has died. It does
   not move an object across the screen, which is what 2.3.3 is about.

   `kbbp-out` IS DELIBERATELY NOT DISABLED HERE, and that is the important
   line in this block. It is not decoration either: it is how the return leg's
   overlay gets off the screen. A blanket `animation:none` under this query
   would leave a reader who asked for less motion looking at a card they cannot
   dismiss, over the order they came back to read. Its duration is cut to
   nothing instead, so it becomes a cut rather than a fade. */
@media (prefers-reduced-motion:reduce){
  .kbb-placing,.kbb-placing-card{transition:opacity .01ms linear;transform:none}
  .kbb-placing.is-up .kbb-placing-card{transform:none}
  .kbb-placing-ring{animation:none;transform:rotate(-90deg)}
  .kbb-placing-tick{transform:none}
  .kbb-placing.is-done .kbb-placing-tick{transition:opacity .01ms linear;transform:none}
  .kbb-placing.is-done .kbb-placing-check{animation:none;stroke-dashoffset:0}
  .kbb-placing-ping,.kbb-placing.is-done .kbb-placing-ping{animation:none;display:none}
  .kbb-placing.is-selfclosing{animation:kbbp-out .01ms var(--kbbp-hold,1.25s) forwards}
}
</style>
