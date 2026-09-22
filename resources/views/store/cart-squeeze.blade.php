{{--
    The squeezed cart page — its stylesheet and the one script on it.

    INCLUDED ONLY WHEN `layout = squeeze`, from store/cart.blade.php. On the
    classic layout the @if around that include never runs, so this file
    contributes zero bytes to the rendered page. That is what makes "applying
    the package changes nothing visible" true on the markup side.

    ── WHY THE CSS IS HERE AND NOT IN resources/css/kbb/kbb-cart.css ──────────

    Because of how this shop ships. package.json defines no `build` script, CI
    does not build assets, and the stylesheet the server actually serves is the
    committed public/build/*.css that @vite resolves through the manifest. A
    rule added to the source file and not rebuilt is a rule that is real in the
    repository and absent on the live site — which is this project's signature
    failure, and the exact reason CartPageLayoutTest asserts against the BUILT
    file rather than the source one.

    Inline, the rules ship with the Blade that needs them and cannot be stale.
    The cost is about 4KB on one page of the shop, and only when the owner has
    switched this layout on. If an asset build is ever part of the release, this
    block moves into kbb-cart.css unchanged — every selector is already scoped
    under `.kbb-cartpage.cpg-squeeze`, which exists nowhere else.

    ── SIZING IS CSS ──────────────────────────────────────────────────────────

    Every size below derives from --cpg-row-h, --cpg-addr-h, --cpg-co-h or
    --cpg-sheet-d through calc(). Nothing measures anything and there is no
    resize observer: a JS sizer runs after first paint, so every shopper sees
    one frame of the wrong layout, and it runs again on every scroll-driven
    viewport resize on iOS. The ONLY script on this page is the address sheet,
    and it is a fetch and some class toggles.
--}}
@php
    use App\Support\Url;
@endphp
@push('styles')
<style>
/* ── the knobs, with the values the service emits as fallbacks ───────────── */
.kbb-cartpage.cpg-squeeze{
  --cpg-row-h:96px; --cpg-fscale:1; --cpg-row-bold:600;
  --cpg-per:4.5; --cpg-rec-bold:400; --cpg-rec-price-bold:400; --cpg-drift:1;
  --cpg-rec-lh:1.25; --cpg-rec-gap:2px; --cpg-rec-img-gap:5px;
  --cpg-rec-add-s:1; --cpg-rec-add-x:0px; --cpg-rec-add-y:0px;
  --cpg-qty-s:1;
  --cpg-addr-h:40px; --cpg-co-h:62px; --cpg-bar-f:1;
  --cpg-bar-pad:0px; --cpg-addrbtn-f:1; --cpg-trust-s:1;
  /* The delivery row's own size and the two weights it paints by hand today:
     500 on the heading, 600 on the button. They are fallbacks here and
     settings in the service, so this block still renders today's row if the
     style attribute never arrives. */
  --cpg-addr-f:1; --cpg-addr-bold:500; --cpg-addrbtn-bold:600;
  --cpg-sheet-max:50%; --cpg-sheet-d:1; --cpg-sheet-f:1;

  /* derived, in CSS, once */
  --cpg-pad:calc(var(--cpg-row-h) * .13);
  --cpg-thumb:calc(var(--cpg-row-h) - (var(--cpg-pad) * 2));
  --cpg-f-name:calc((11.5px + var(--cpg-row-h) * .042) * var(--cpg-fscale));
  --cpg-f-brand:calc((7.5px + var(--cpg-row-h) * .022) * var(--cpg-fscale));
  --cpg-f-price:calc((11px + var(--cpg-row-h) * .040) * var(--cpg-fscale));
  /* THE STEPPER, AND --cpg-qty-s IS ON BOTH LINES ON PURPOSE.
     Still off --cpg-row-h, so shortening the row still shrinks the control
     inside it — the shop's own requirement, and the reason this knob is a
     multiplier rather than a pixel size. Putting it on the glyph as well as
     on the box keeps the two in the ratio they have today, which is what
     stops the digit overflowing at 180% or floating in a box at 60%. */
  --cpg-qty-h:calc(var(--cpg-row-h) * .30 * var(--cpg-qty-s));
  --cpg-qty-f:calc(var(--cpg-row-h) * .135 * var(--cpg-fscale) * var(--cpg-qty-s));
  /* The space the last row has to clear. The padding under the docked rows is
     part of the bars' height, so asking for more of it moves the end of the
     page down with it rather than sliding the bars over the last basket line. */
  --cpg-bars:calc(var(--cpg-addr-h) + var(--cpg-co-h) + var(--cpg-bar-pad));

  /* The full-bleed rail is the one thing here wider than the column it sits
     in. `clip` and not `hidden`: `hidden` on one axis forces the other to
     `auto`, which would turn this page into its own scroller. */
  overflow-x:clip;
}
/* Room under the last thing on the page for the two bars that float over it.
   A shopper who cannot see the row they are about to pay for is the whole
   argument for this rule existing. */
.kbb-cartpage.cpg-squeeze .wrap{padding-bottom:calc(var(--cpg-bars) + 24px)}
.kbb-cartpage.cpg-squeeze .grid{grid-template-columns:minmax(0,1fr);gap:0}

/* ── product rows ───────────────────────────────────────────────────────── */
.kbb-cartpage.cpg-squeeze .ci{
  height:var(--cpg-row-h);padding:var(--cpg-pad);gap:var(--cpg-pad);overflow:hidden}
.kbb-cartpage.cpg-squeeze .cth{
  width:var(--cpg-thumb);height:var(--cpg-thumb);font-size:calc(var(--cpg-thumb) * .22)}
.kbb-cartpage.cpg-squeeze .cmid{display:flex;flex-direction:column;justify-content:center;
  gap:calc(var(--cpg-row-h) * .018);min-width:0}
.kbb-cartpage.cpg-squeeze .cbrand{font-size:var(--cpg-f-brand);font-weight:var(--cpg-row-bold);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* Two lines and then an ellipsis, so a long name cannot make one row taller
   than its neighbours and undo the whole point of a fixed row height. */
.kbb-cartpage.cpg-squeeze .cn{font-size:var(--cpg-f-name);font-weight:var(--cpg-row-bold);
  line-height:1.25;margin:0;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;
  -webkit-box-orient:vertical}
.kbb-cartpage.cpg-squeeze .cn a{display:inline;padding:0}
.kbb-cartpage.cpg-squeeze .cvar{font-size:var(--cpg-f-brand);margin:0}
.kbb-cartpage.cpg-squeeze .qty{align-self:flex-start;height:var(--cpg-qty-h);
  margin-top:calc(var(--cpg-row-h) * .022);border-radius:calc(var(--cpg-qty-h) * .28)}
.kbb-cartpage.cpg-squeeze .qty button{width:calc(var(--cpg-qty-h) * 1.5);height:100%;
  font-size:var(--cpg-qty-f);line-height:1}
.kbb-cartpage.cpg-squeeze .qty span{min-width:calc(var(--cpg-qty-h) * 1.1);
  font-size:var(--cpg-qty-f);font-weight:var(--cpg-row-bold)}
.kbb-cartpage.cpg-squeeze .cright{gap:calc(var(--cpg-row-h) * .03)}
.kbb-cartpage.cpg-squeeze .cpr{font-size:var(--cpg-f-price);font-weight:var(--cpg-row-bold)}
.kbb-cartpage.cpg-squeeze .cwas{font-size:var(--cpg-f-brand)}
.kbb-cartpage.cpg-squeeze .crm{font-size:var(--cpg-f-brand);min-height:0;padding:0}
/* "no bold text at all" — the off position of the Bold rows switch. Stated as
   a class rather than by flipping --cpg-row-bold so the `b`/`strong` inside a
   line is covered too. */
.kbb-cartpage.cpg-squeeze.cpg-rowthin .ci b,
.kbb-cartpage.cpg-squeeze.cpg-rowthin .ci strong{font-weight:400}

/* ── recommended: FULL BLEED, no radius, no padding box ─────────────────── */
.kbb-cartpage.cpg-squeeze .cpg-rec{
  position:relative;margin:14px calc(50% - 50vw) 0;width:100vw;max-width:100vw;
  padding:13px 0 15px;border-radius:0;overflow:hidden}
/* The colour wash, on a layer of its own so the cards above it stay opaque and
   the animation never repaints them. */
.kbb-cartpage.cpg-squeeze .cpg-rec::before{
  content:"";position:absolute;inset:0;z-index:0;
  background:linear-gradient(115deg,#FFEDF3,#FFF6EC,#EFF9F3,#F4EFFC,#FFEDF3);
  background-size:280% 280%;
  animation:cpgdrift calc(26s / var(--cpg-drift)) ease-in-out infinite;
  opacity:calc(.45 + .55 * var(--cpg-drift))}
@keyframes cpgdrift{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
/* Movement set to 0 on the screen, and anyone whose phone has asked for less
   of it. The wash stays; only the drift stops. */
.kbb-cartpage.cpg-squeeze.cpg-still .cpg-rec::before{animation:none}
@media (prefers-reduced-motion:reduce){
  .kbb-cartpage.cpg-squeeze .cpg-rec::before{animation:none}}
.kbb-cartpage.cpg-squeeze .cpg-rec > *{position:relative;z-index:1}
.kbb-cartpage.cpg-squeeze .cpg-rec h2{font-size:14px;font-weight:600;margin:0 0 10px;padding:0 14px}
.kbb-cartpage.cpg-squeeze .cpg-rail{display:flex;gap:7px;overflow-x:auto;
  padding:2px 14px 4px;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.kbb-cartpage.cpg-squeeze .cpg-rail::-webkit-scrollbar{display:none}
/* The carousel arrows are DESKTOP ONLY, and hidden here rather than only shown
   in the query, so the phone's answer is the one this rule gives by default and
   there is exactly one place that decides it. */
.kbb-cartpage.cpg-squeeze .cpg-arr{display:none}
/* 4.5 cards across whatever the screen is, because the card is a FRACTION of
   the screen and not a pixel width. The half card is the point: a card cut off
   by the edge is what tells a thumb there is more to the right. */
.kbb-cartpage.cpg-squeeze .cpg-card{
  flex:0 0 calc((100% - 14px - (7px * (var(--cpg-per) - 1))) / var(--cpg-per));
  background:#fff;border:1px solid var(--line-2);border-radius:9px;padding:5px;min-width:0}
.kbb-cartpage.cpg-squeeze .cpg-card .im{position:relative;aspect-ratio:1;border-radius:6px;
  display:grid;place-items:center;color:#fff;font-weight:500;background-size:cover;
  background-position:center;font-size:clamp(11px,3.6vw,17px);margin-bottom:var(--cpg-rec-img-gap)}
/* "the font size of the product names should be small and auto adjust to the
   screen" — clamp() against vw, so it is the SCREEN it adjusts to and not a
   measurement somebody took. */
/* The name is clamped to TWO lines, and the box is two line-heights rather
   than a fixed 2.5em, so opening the lines out gives the second line room
   instead of cropping it. --cpg-rec-lh ships 1.25, which is 2.5em — the number
   that was written here before it was a knob. */
/*
 * ▲ display:block, AND IT IS WHAT MAKES FOUR OF THESE CONTROLS WORK AT ALL.
 *
 * The markup is `<span class="nm">` — an INLINE element. `height`, `overflow`
 * and vertical `margin` do not apply to a non-replaced inline box, and its
 * line boxes are governed by the parent block's strut, so a smaller
 * `line-height` on the span alone changes nothing you can see.
 *
 * So all four of these were dead on the page while looking perfectly correct
 * in the stylesheet:
 *   height  -> the two-line clamp never applied; names ran to three lines
 *   overflow-> nothing was ever clipped
 *   margin  -> the name-to-price gap slider moved nothing
 *   line-height -> the parent's strut still set the line box
 *
 * Reported as "the line height of the product names is still the same even i
 * changed from the backend to minimum", with a screenshot of three-line names
 * under a rule that clamps to two — which is the tell: if the clamp were
 * applying, the third line would be cut.
 *
 * `.pr` is the same shape and gets the same treatment, so its own margin and
 * the gap above it are real too.
 */
/*
 * THREE LINES, AN ELLIPSIS, AND A FADE ON THE LAST ONE.
 *
 * `-webkit-line-clamp` gives the ellipsis, and it is the only thing that does:
 * `text-overflow:ellipsis` is single-line only. It needs the whole trio --
 * display:-webkit-box, -webkit-box-orient:vertical, overflow:hidden -- and it
 * is not prefixed-legacy-only: every current engine implements it under these
 * names, which is why the unprefixed `line-clamp` is not used alone.
 *
 * `display:-webkit-box` also gives the name a BLOCK-level box, which is what
 * makes its height, margin and line-height apply at all. As a plain <span> it
 * was an inline box: height and vertical margin are ignored on one, and its
 * line boxes are set by the parent's strut, so the line-height slider moved
 * nothing. That is the bug this started as.
 *
 * The fade is a mask over the last line's tail, so a name cut mid-word softens
 * out instead of stopping dead next to an ellipsis. -webkit- first, and a
 * browser with neither simply shows the ellipsis, which is today's behaviour
 * and not a broken one.
 *
 * `min-height` and not `height`: a one-line name should not reserve three
 * lines of empty card, but a three-line one must not push the price out of
 * alignment with its neighbours either.
 */
.kbb-cartpage.cpg-squeeze .cpg-card .nm{
  display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:3;line-clamp:3;
  overflow:hidden;
  font-size:clamp(8px,2.45vw,10.5px);
  font-weight:var(--cpg-rec-bold);line-height:var(--cpg-rec-lh);
  min-height:calc(var(--cpg-rec-lh) * 2em);
  margin:0 0 var(--cpg-rec-gap);color:var(--ink-2);
  -webkit-mask-image:linear-gradient(to bottom,#000 calc(100% - 0.55em),rgba(0,0,0,.35) 100%);
  mask-image:linear-gradient(to bottom,#000 calc(100% - 0.55em),rgba(0,0,0,.35) 100%)}
/* The price on its OWN line, under the name. Both were <span> inside the same
   <a>, so they sat side by side on one line whatever the gap was set to. */
.kbb-cartpage.cpg-squeeze .cpg-card .pr{display:block;font-size:clamp(8.5px,2.6vw,11px);
  line-height:var(--cpg-rec-lh);font-weight:var(--cpg-rec-price-bold)}
.kbb-cartpage.cpg-squeeze .cpg-card .pr .cwas{display:inline;margin:0 0 0 3px}
.kbb-cartpage.cpg-squeeze .cpg-card .lk{display:block;color:inherit}
/* The one-tap add, sitting on the corner of the picture. Sized from vw like
   everything else in the card, so it stays in proportion to a card whose width
   is a fraction of the screen. */
/* The size knob is a MULTIPLIER on the clamp, never a replacement for it: the
   button has to stay in proportion to a card that is a fraction of the screen,
   so what the screen works out is still what is being nudged.

   The two offsets are added to the corner it is pinned to -- inline-end and
   bottom -- rather than applied as a translate, so "further out" means the same
   thing in a right-to-left shop as in this one, and so the :active scale below
   still has `transform` to itself. */
.kbb-cartpage.cpg-squeeze .cpg-card .kc-badd{position:absolute;
  inset-inline-end:calc(-3px + var(--cpg-rec-add-x));
  bottom:calc(-3px + var(--cpg-rec-add-y));
  width:calc(clamp(17px,5.4vw,22px) * var(--cpg-rec-add-s));
  height:calc(clamp(17px,5.4vw,22px) * var(--cpg-rec-add-s));border-radius:50%;
  background:var(--green);color:#fff;border:1.5px solid #fff;display:grid;place-items:center;
  font-size:calc(clamp(11px,3.4vw,14px) * var(--cpg-rec-add-s));line-height:1;cursor:pointer;
  padding:0;min-height:0;font-family:inherit}
.kbb-cartpage.cpg-squeeze .cpg-card .kc-badd:active{transform:scale(.9)}

/* ── coupon: minimal, directly under the rail ───────────────────────────── */
.kbb-cartpage.cpg-squeeze .coupon{display:flex;align-items:center;gap:8px;margin:0;
  padding:11px 0;background:none;border:0;border-bottom:1px solid var(--line-2);border-radius:0}
.kbb-cartpage.cpg-squeeze .coupon input{border:0;border-radius:0;background:none;
  border-bottom:1px solid var(--line-2);padding:5px 1px;font-size:13px;min-height:0}
.kbb-cartpage.cpg-squeeze .coupon input:focus{box-shadow:none;border-color:var(--green)}
.kbb-cartpage.cpg-squeeze .coupon button{background:var(--line-2);color:var(--ink-2);
  border-radius:7px;font-size:12px;font-weight:500;letter-spacing:.06em;
  text-transform:uppercase;padding:9px 15px;min-height:0}

/* ── summary ────────────────────────────────────────────────────────────── */
.kbb-cartpage.cpg-squeeze .sum{position:static;margin:14px 0 0;padding:14px}
.kbb-cartpage.cpg-squeeze .srow{padding:3px 0}
.kbb-cartpage.cpg-squeeze .srow .cpg-n{color:var(--muted);font-size:12px}
.kbb-cartpage.cpg-squeeze .srow .cpg-was{color:var(--muted);font-size:11.5px;
  text-decoration:line-through;margin-inline-end:4px}
/* The little (i). A real control and not decoration: each of these charges is
   a rule the shop can change, and a shopper who cannot ask why assumes the
   worst. The note is a title, so it needs no script. */
.kbb-cartpage.cpg-squeeze .cpg-i{display:inline-grid;place-items:center;width:13px;height:13px;
  border-radius:50%;border:1px solid var(--line);color:var(--muted);font-size:8.5px;
  font-weight:600;font-style:normal;vertical-align:1px;cursor:help}
/* "Delivery & VAT are calculated at checkout".

   Deliberately NOT styled as small print. The classic layout's own
   `.srow.note` is 11.5px and muted, which is right for a caption and wrong
   here: this line is the answer to "what will this cost me", and a shopper who
   cannot find that answer stays on the cart page hunting for it instead of
   pressing Checkout. Same size as the charge rows it replaces, with the tick
   colour, so it reads as information and not as a disclaimer. */
.kbb-cartpage.cpg-squeeze .srow.cpg-later{justify-content:flex-start;gap:6px;
  color:var(--green);font-weight:500;padding:6px 0 2px}
/* The shop's own free-delivery bar, standing where the delivery row was. Same
   element and the same stylesheet as the one at the top of the page — this
   only unpicks the card treatment kbb-cart.css gives it, because inside the
   summary it is a row of the summary and not a card sitting on the page. */
.kbb-cartpage.cpg-squeeze .sum .ship{background:none;border:0;border-radius:0;
  box-shadow:none;padding:8px 0 4px;margin:0}
.kbb-cartpage.cpg-squeeze .sum .ship .t{margin-bottom:6px}

/* ── the bar flows, and a bloom rides its leading edge ───────────────────
   THE TRACK HAS TO STOP CLIPPING. kbb-cart.css gives `.ship .bar`
   `overflow:hidden`, which is correct for a flat fill and flattens this
   entirely — the bloom's whole job is to spill past the track. Overridden
   here only, inside the summary, so the classic page's bar is untouched; the
   rounded ends move onto the fill, which is what the radius was doing for. */
.kbb-cartpage.cpg-squeeze .sum .ship .bar{overflow:visible}
.kbb-cartpage.cpg-squeeze .sum .ship .fill{position:relative;border-radius:6px;
  /* A 220%-wide gradient sliding leftward: the bar reads as flowing rather
     than painted. background-position is composited, so this costs nothing
     and cannot stutter behind a busy main thread. */
  background:linear-gradient(90deg,var(--green,#1E9E5A),#54C98A,var(--green,#1E9E5A));
  background-size:220% 100%;
  animation:cpgflow 2.6s linear infinite}
@keyframes cpgflow{from{background-position:100% 0}to{background-position:-120% 0}}
/* Both pseudo-elements are anchored to the RIGHT of the fill, so they ride the
   leading edge and travel with it as the order value grows. The translate is
   part of every transform below, because a transform that omitted it would
   snap the bloom back to the corner the moment its animation took over. */
.kbb-cartpage.cpg-squeeze .sum .ship .fill::before,
.kbb-cartpage.cpg-squeeze .sum .ship .fill::after{
  content:"";position:absolute;top:50%;right:0;pointer-events:none}
.kbb-cartpage.cpg-squeeze .sum .ship .fill::before{
  width:26px;height:26px;border-radius:50%;
  background:radial-gradient(circle,rgba(255,255,255,.95) 0%,rgba(170,240,205,.55) 42%,rgba(170,240,205,0) 72%);
  transform:translate(50%,-50%);
  animation:cpgbloom 1.9s ease-in-out infinite}
@keyframes cpgbloom{
  0%,100%{transform:translate(50%,-50%) scale(.85)}
  50%{transform:translate(50%,-50%) scale(1.15)}}
/* Four petals out of one element, no image and no extra request. */
.kbb-cartpage.cpg-squeeze .sum .ship .fill::after{
  width:11px;height:11px;background:#fff;
  clip-path:polygon(50% 0%,62% 38%,100% 50%,62% 62%,50% 100%,38% 62%,0% 50%,38% 38%);
  transform:translate(50%,-50%) rotate(0deg);
  animation:cpgpetal 4.2s linear infinite}
@keyframes cpgpetal{
  from{transform:translate(50%,-50%) rotate(0deg)}
  to{transform:translate(50%,-50%) rotate(360deg)}}
/* AT ZERO THERE IS NOTHING TO BLOOM FROM, and the shape would sit outside the
   track looking like a stray mark. The Blade puts this class on a fill of
   width 0 rather than the stylesheet guessing from the inline width. */
.kbb-cartpage.cpg-squeeze .sum .ship .fill.cpg-flat::before,
.kbb-cartpage.cpg-squeeze .sum .ship .fill.cpg-flat::after{display:none}
/* STOPPED, not slowed — all three of them. The bar keeps its colour and the
   bloom keeps its glow; nothing moves. It sits a few pixels above the button
   we want tapped, so it is quiet by design and silent by request. */
@media (prefers-reduced-motion:reduce){
  .kbb-cartpage.cpg-squeeze .sum .ship .fill,
  .kbb-cartpage.cpg-squeeze .sum .ship .fill::before,
  .kbb-cartpage.cpg-squeeze .sum .ship .fill::after{animation:none}
  .kbb-cartpage.cpg-squeeze .sum .ship .fill{background-position:0 0}}
.kbb-cartpage.cpg-squeeze .cpg-totband{display:flex;justify-content:space-between;
  align-items:center;gap:12px;background:#EEF8F1;border-radius:9px;padding:10px 12px;
  margin-top:10px;font-size:15px;font-weight:600}
.kbb-cartpage.cpg-squeeze .cpg-totband .tr{text-align:end}
.kbb-cartpage.cpg-squeeze .cpg-totband b{display:block;font-weight:700}
.kbb-cartpage.cpg-squeeze .cpg-totband em{display:block;font-style:normal;font-weight:400;
  font-size:9.5px;color:var(--muted);margin-top:1px}

/* ── trust row: tick, a rule, and the marks. One row, tiny. ─────────────── */
/* ONE MULTIPLIER FOR THE WHOLE ROW, --cpg-trust-s. The tick, its wording, the
   gaps and the payment chips are all a calc() off it, so the row scales as a
   row: a slider that grew the marks and left the tick and the text where they
   were would take a row that reads as one line and pull it apart. Same rule as
   the row height on the basket lines. */
.kbb-cartpage.cpg-squeeze .cpg-trust{display:flex;align-items:center;justify-content:center;
  gap:calc(7px * var(--cpg-trust-s));flex-wrap:wrap;padding:calc(10px * var(--cpg-trust-s)) 0 0;
  font-size:calc(10.5px * var(--cpg-trust-s));color:var(--muted)}
.kbb-cartpage.cpg-squeeze .cpg-trust .sec{display:inline-flex;align-items:center;
  gap:calc(4px * var(--cpg-trust-s));white-space:nowrap}
.kbb-cartpage.cpg-squeeze .cpg-trust .sec svg{width:calc(13px * var(--cpg-trust-s));
  height:calc(13px * var(--cpg-trust-s));color:var(--green);flex:none}
.kbb-cartpage.cpg-squeeze .cpg-trust .sep{color:var(--line-2)}
/* WRAP, not nowrap. `nowrap` was right for six short words and is wrong for
   six drawings: six marks cannot fit one line of a 360px phone at trust_size
   150%, and under nowrap the browser shrank the chips to make them fit — so
   turning the slider UP made the artwork SMALLER. Wrapping lets each mark keep
   the size the slider asked for and take a second line when it needs one. The
   marks' own max-width:100% is still the floor, so a single mark too wide for a
   320px screen scales down rather than overlapping its neighbour. */
.kbb-cartpage.cpg-squeeze .paylogos{margin:0;gap:calc(4px * var(--cpg-trust-s));flex-wrap:wrap}
.kbb-cartpage.cpg-squeeze .paylogos span{height:calc(16px * var(--cpg-trust-s));
  padding:0 calc(4px * var(--cpg-trust-s));font-size:calc(6.5px * var(--cpg-trust-s));
  display:grid;place-items:center;letter-spacing:.03em;border-radius:3px;background:#fff}

/* ── the two floating rows ──────────────────────────────────────────────── */
/* FIXED, not sticky. "it must be floating at the bottom of the screen" — and
   sticky only floats while the element's own containing block is still under
   the viewport, so a short basket would leave the checkout button halfway up
   the page. The padding-bottom on .wrap above is what keeps the last row out
   from under them. */
/* ── the mobile tab bar goes, and it goes from HERE ─────────────────────────

   TWO FLOATING BARS STACKED AT THE FOOT OF A PHONE. The tab bar — Home, Shop,
   Quiz, Saved, Bag — is `position:fixed;bottom:0` and so are these rows, so on
   the squeezed cart page they sit on top of one another and the shopper gets a
   menu where the checkout button should be.

   NOT A SELECTOR SCOPED UNDER .cpg-squeeze, because .tabbar is not inside
   .kbb-cartpage — it is a sibling in the page chrome, and nothing in this
   layer can reach up to it. And deliberately NOT AN EDIT TO
   partials/mobile-chrome.blade.php, which another lane owns and which serves
   every other page of the shop, where the tab bar is wanted.

   THE STYLESHEET IS THE SCOPE. This whole <style> block is pushed only from
   store/cart-squeeze.blade.php, which store/cart.blade.php includes only while
   `layout = squeeze` — so this rule exists on exactly the page that has docked
   rows of its own, and on no other. Switching the layout back to `classic`
   takes the rule away with everything else here; the tab bar returns by itself
   and nothing has to remember to put it back.

   No !important, and it does not need one: the layout renders the styles stack
   AFTER the built stylesheet link, so this is simply the later rule at equal
   specificity. The owner can still switch the tab bar off shop-wide from its
   own module toggle — this is so the cart page does not depend on his
   remembering to.

   (No Blade directive names in this comment, incidentally. The pushed <style>
   is still Blade: a bare stack or vite directive written here compiles and
   runs, which took a suite of 16 failures to notice.) */
.tabbar{display:none}

/* FULL WHITE, AND THE PADDING IS WHITE TOO.

   `--cpg-bar-pad` is the space the shop asks for under the checkout row, and it
   belongs to .cpg-docked rather than to the row inside it so that the white
   runs to the bottom edge of the screen instead of stopping where the row
   stops. A tinted strip under a white bar is what a shopper sees as the bar
   "not reaching the bottom".

   env(safe-area-inset-bottom) is ADDED to it, not substituted for it: on a
   phone with a home indicator the shop's setting is space it asked for on top
   of the space the hardware already takes. It resolves to 0px everywhere else. */
/*
 * z-index 96, AND THE NUMBER IS THE WHOLE FIX.
 *
 * It was 40. `.tabbar` in kbb.css:888 is `position:fixed; bottom:0;
 * z-index:95` with `background:rgba(255,255,255,.97)` and
 * `backdrop-filter:blur(14px)` -- a near-opaque WHITE BAR that outranked the
 * checkout row by 55 and painted straight over it.
 *
 * That is the "weird white bar". It appeared while scrolling and settled when
 * the scroll stopped because Chrome re-rasterises a backdrop-filter during
 * scroll; the bar was there the whole time, and only its paint came and went.
 * Nothing of ours animates on scroll -- the only scroll listeners in this
 * codebase are in home.js and pdp.js, and neither runs on the cart page.
 *
 * The rule below hides .tabbar on this page, which is the first line of
 * defence and the one that was already here. This is the second: the docked
 * rows now outrank EVERYTHING that can sit at the bottom of a storefront page,
 * so a bar that escapes the hide -- an unapplied package, a stale compiled
 * view, a module switched on, something added later -- still cannot cover the
 * Checkout button.
 *
 * 96 and not 999: `.toast` is 100 and the mobile menu is 120, and both of
 * those SHOULD be able to cover these rows. The number is "above every bottom
 * bar", not "above everything".
 */
.kbb-cartpage.cpg-squeeze .cpg-docked{position:fixed;inset-inline:0;bottom:0;z-index:96;
  background:#fff;
  padding-bottom:calc(var(--cpg-bar-pad) + env(safe-area-inset-bottom, 0px));
  box-shadow:0 -2px 14px -6px rgba(42,34,40,.35);
  /* THE SECOND `bottom`, AND IT IS THE WHOLE BUG FIX.
     "when i scroll back to up on the cart page, the screen gives weired white
     bar type ... and it hides the checkouts row."

     `position:fixed` resolves `bottom` against the LAYOUT viewport, and on a
     phone the layout viewport is the tall one -- the height the page has when
     the browser's URL bar is retracted. Scrolling back upward brings that URL
     bar out again. The layout viewport does not change; the VISIBLE part of it
     shrinks, from the top, and its bottom edge is now that much below the
     bottom of the screen. So is this block, which is pinned to it: the address
     row is still on screen, the checkout row has slid off the bottom, and what
     is left between them is the empty top of the checkout row -- a bar of this
     block's own white with nothing in it. That is the band in the screenshot,
     and the Proceed to Checkout button is underneath the screen edge.

     Item count is not part of the cause. Two items do not fill the screen, so
     there is nothing to scroll and the URL bar never leaves; ten items are
     simply what makes the page scrollable enough to see it.

     `100lvh` is that tall layout viewport, `100dvh` is however much of it is
     visible right now, so their difference is exactly the strip hanging off the
     bottom -- and lifting the block by it puts it back on the bottom edge of
     what the shopper can actually see. With the URL bar retracted the two are
     equal, the term is 0px, and this renders precisely as it does today; on a
     desktop browser they are always equal.

     It is a second `bottom` and not an @supports block on purpose: a browser
     that does not parse `lvh`/`dvh` drops this declaration as invalid and keeps
     the `bottom:0` above, which is where it already was.

     `--cpg-bars` deliberately does NOT get the same term. It is padding at the
     end of the document, so putting a `dvh` in it would make the page's height
     change every time the URL bar moved -- and a document that grows and shrinks
     under a scrolling finger drives the URL bar itself, which is a worse bug
     than the one above. The bars float; the room left for them stays still. */
  /*
   * ▲ THE lvh/dvh LIFT WAS HERE AND IS GONE. MEASURED ON THE OWNER'S PHONE.
   *
   * The reasoning was sound and the result was wrong. Chrome on Android
   * ALREADY resolves a fixed element's `bottom` against the visual viewport
   * while the URL bar is out -- so subtracting (100lvh - 100dvh) on top of
   * that counted the browser chrome twice and pushed the bar UP by its height,
   * leaving the page showing through underneath it. The owner's screenshot has
   * the free-delivery bar visible BELOW the checkout row, which is the page,
   * not a gap.
   *
   * So: plain bottom:0, which is correct at rest and correct once the URL bar
   * has finished moving. What remains is a transient during the bar's own
   * animation, and it is not fixed by arithmetic on viewport units -- doing
   * that traded an intermittent artifact for a permanent one.
   */
  bottom:0}
/* #fff and not var(--cream): "the background must be full white". The address
   row and the checkout row are one surface, and a cream strip above a white one
   reads as two bars rather than as the foot of the screen. */
/* --cpg-addr-f is this row's own scale, multiplied INTO the shared bar scale
   rather than replacing it, so "Text in both rows" still moves this row and
   this slider still leaves the checkout row alone. `min-height` below means a
   larger setting grows the bar; it cannot crop the wording. */
.kbb-cartpage.cpg-squeeze .cpg-addrbar{display:flex;align-items:center;justify-content:space-between;
  gap:10px;min-height:var(--cpg-addr-h);padding:0 14px;background:#fff;
  border-top:1px solid var(--line-2);font-size:calc(11.5px * var(--cpg-bar-f) * var(--cpg-addr-f))}
.kbb-cartpage.cpg-squeeze .cpg-addrbar .who{min-width:0;overflow:hidden}
/* ONE LINE, FADED OFF AT THE RIGHT — "the shown address should be blured cut
   from the right side, if the address is going too long. i want in one line
   only."

   A mask and not an ellipsis, and the difference is what each one says. A hard
   "…" reads as truncation and invites a tap to see the rest, which there is
   nothing here to show. A fade reads as "there is more of this" and leaves the
   last legible characters doing their job.

   -webkit-mask-image FIRST and the unprefixed one after, so a browser that
   understands both takes the standard property. A browser that understands
   NEITHER gets the text hard-cut by overflow:hidden, which is what this row
   does today and is not a broken state — which is why there is no script here
   propping it up. */
.kbb-cartpage.cpg-squeeze .cpg-addrbar .who b,
.kbb-cartpage.cpg-squeeze .cpg-addrbar .who span{
  display:block;white-space:nowrap;overflow:hidden}
/* ONLY ONCE THERE IS AN ADDRESS TO FADE.

   The mask says "there is more of this line than fits". On the prompt —
   "Please choose your delivery address" — there is no more of it: the fade just
   dissolves the last word of a sentence that fits, and a half-dissolved
   instruction reads as a rendering fault, which is what it was reported as.
   The class is put on by the server from the session and by paintRow() after a
   tap, so the row is never briefly wrong in either direction. */
.kbb-cartpage.cpg-squeeze .cpg-addrbar.cpg-has .who b,
.kbb-cartpage.cpg-squeeze .cpg-addrbar.cpg-has .who span{
  -webkit-mask-image:linear-gradient(to right,#000 calc(100% - 34px),transparent);
  mask-image:linear-gradient(to right,#000 calc(100% - 34px),transparent)}
/* THE HEADING'S WEIGHT, AND ONLY THE HEADING'S.
   This `b` holds "Please choose your delivery address" before an address is
   picked and "Delivering to Home" after it — the line the owner asked to be
   able to embolden. The address itself, in the span below, is deliberately
   left at the regular weight it inherits: it is the long line that gets faded
   off the right, and thickening it is what makes that fade read as a smear. */
.kbb-cartpage.cpg-squeeze .cpg-addrbar .who b{font-weight:var(--cpg-addr-bold);color:var(--ink-2)}
.kbb-cartpage.cpg-squeeze .cpg-addrbar .who span{color:var(--muted);
  font-size:calc(10px * var(--cpg-bar-f) * var(--cpg-addr-f))}
/* Its own multiplier ON TOP of the shared bar scale, never instead of it, so
   this slider and "Text in both rows" cannot fight and neither can silently
   win — the same rule the row-text slider follows. */
.kbb-cartpage.cpg-squeeze .cpg-addrbtn{flex:none;background:none;border:0;color:var(--green);
  /* Three terms, outermost first: both rows, this row, this button. The
     button is part of the delivery row, so the row's own scale reaches it —
     a size control for "the delivery row" that stopped at the button would
     be telling half the truth. */
  font-size:calc(12px * var(--cpg-bar-f) * var(--cpg-addr-f) * var(--cpg-addrbtn-f));
  font-weight:var(--cpg-addrbtn-bold);cursor:pointer;padding:4px 0;
  font-family:inherit}
.kbb-cartpage.cpg-squeeze .cpg-cobar{display:flex;align-items:center;justify-content:space-between;
  gap:12px;min-height:var(--cpg-co-h);padding:0 14px;background:#fff;
  border-top:1px solid var(--line-2)}
.kbb-cartpage.cpg-squeeze .cpg-cobar .tally{min-width:0}
/* `> span`, AND THE CHILD COMBINATOR IS THE WHOLE FIX.

   As a descendant selector this also matched the two spans INSIDE the price.
   Money::format() renders

     <span class="woocommerce-Price-amount" dir="ltr">
       <span class="woocommerce-Price-currencySymbol">AED</span>1,234</span>

   so `display:block` landed on the symbol and on the amount that wraps it, and
   "AED" became a block of its own with the digits pushed onto the line below —
   the two-line total. white-space:nowrap could never have prevented it: those
   were two block boxes, not a wrapped line, and nowrap only governs wrapping.
   The item count is the only direct span child, so the child combinator says
   what this rule always meant. */
.kbb-cartpage.cpg-squeeze .cpg-cobar .tally > span{display:block;color:var(--muted);
  font-size:calc(11px * var(--cpg-bar-f))}
.kbb-cartpage.cpg-squeeze .cpg-cobar .tally b{display:block;font-weight:700;
  font-size:calc(17px * var(--cpg-bar-f))}
/* And said again from the other side, because the rule above is one careless
   edit away from being a descendant selector once more, and the failure it
   produces is silent. The symbol and its digits are one inline run at the
   tally's own size, and they do not break apart: the nowrap in kbb.css that
   would otherwise cover this is inside a max-width media query, so it is not a
   thing this bar can rely on. */
.kbb-cartpage.cpg-squeeze .cpg-cobar .tally b .woocommerce-Price-amount,
.kbb-cartpage.cpg-squeeze .cpg-cobar .tally b .woocommerce-Price-currencySymbol{
  display:inline;color:inherit;font-size:inherit;white-space:nowrap}
/* "Proceed to Checkout" is nearly three times the width of "Checkout", and it
   shares a 360px row with an item count and a four-digit total. So the button
   GIVES WAY FIRST: `flex:0 1 auto` lets it shrink, `min-width:0` lets it
   actually do so — a flex item's default min-width is auto and that is what
   makes "it should shrink" quietly not work — and the label ends in an ellipsis
   rather than pushing the figures the shopper is about to pay off the screen.
   Horizontal padding is narrower than the short label needed, for the same
   reason. Checked at 360px with a four-digit total and the bar font at 125%. */
.kbb-cartpage.cpg-squeeze .cpg-cobar .cobtn{display:inline-block;width:auto;
  flex:0 1 auto;min-width:0;max-width:100%;white-space:nowrap;overflow:hidden;
  text-overflow:ellipsis;text-align:center;
  background:var(--green);border-radius:10px;box-shadow:none;
  padding:calc(11px * var(--cpg-bar-f)) calc(16px * var(--cpg-bar-f));
  font-size:calc(15px * var(--cpg-bar-f))}
/* The tally neither grows nor shrinks: `0 0 auto`.

   It does not grow, so the button keeps whatever is left rather than being
   squeezed by an empty column. It does not SHRINK either, and that is the half
   that was missing — at `0 1 auto` the figure the shopper is about to pay was
   free to be clipped by the overflow below it once the button asked for more
   room than a 360px row has. The button is the one that gives way; it says so
   itself, two rules up. Checked at 360px with a four-digit total at 125%. */
.kbb-cartpage.cpg-squeeze .cpg-cobar .tally{flex:0 0 auto;white-space:nowrap;overflow:hidden}
.kbb-cartpage.cpg-squeeze .cpg-cobar .cobtn:hover{background:#177F47;transform:none}
/* The classic column's own checkout button and "continue shopping" link are
   redundant once the bar is on screen, and a second Checkout is a shopper
   wondering which one is real. */
.kbb-cartpage.cpg-squeeze .sum > .cobtn,
.kbb-cartpage.cpg-squeeze .sum > .conti{display:none}

/* ── waiting on the server, anywhere on this page ───────────────────────
   Grey blocks in the SHAPE of what is coming, so a wait reads as "loading"
   and not as "empty".

   THE SHIMMER IS A MOVING background-position ON A GRADIENT, and that is the
   whole reason it is worth having. background-position is composited off the
   main thread, so it keeps moving while the main thread is busy doing the very
   fetch it is covering for. An opacity pulse on a transform, or a JS loop,
   stutters exactly when the shopper is looking at it.

   It is only ever shown while a REAL request is in flight: cart.js puts
   `busy` on #cartPage for the length of every cart write and takes it off in a
   finally block, and the sheet's script does the same thing by hand for its own
   fetch. A placeholder that flashes for a fortieth of a second on a warm cache
   reads as a glitch, which is worse than no placeholder. */
.cpg-sk{border-radius:7px;
  background:linear-gradient(100deg,#EFF1F4 30%,#F8F9FB 48%,#EFF1F4 66%);
  background-size:220% 100%;animation:cpgshim 1.15s linear infinite}
@keyframes cpgshim{from{background-position:180% 0}to{background-position:-40% 0}}
@media (prefers-reduced-motion:reduce){.cpg-sk{animation:none}}
.cpg-skcard{border:1px solid #E4E7EC;border-radius:10px;padding:11px;display:grid;gap:7px;
  margin-bottom:8px}
.cpg-skline{height:11px}
.cpg-skline.w40{width:40%}
.cpg-skline.w90{width:90%}
.cpg-skline.w65{width:65%}
/* A screen reader gets nothing at all from a grey rectangle. */
.cpg-vh{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);
  clip-path:inset(50%);white-space:nowrap}

/* The same treatment for every OTHER wait on this page — applying a coupon,
   changing a quantity, removing a line, adding from the rail. cart.js already
   puts `busy` on #cartPage for the length of each of those and removes it in a
   finally block, so this needs no script of its own: one rule, reused, driven
   by a class the shop already sets.

   The old `.kbb-cartpage.busy #kbbCartInner` rule further up kbb-cart.css never
   matched anything — the element's id is `cartInner`, with no kbb prefix — so
   this is also the first time that class has done anything on this page. That
   rule is left alone rather than corrected: it belongs to the classic layout,
   and correcting it would change a page this package promises not to change. */
.kbb-cartpage.cpg-squeeze.busy #cartInner{pointer-events:none}
.kbb-cartpage.cpg-squeeze.busy #cartInner .cpr,
.kbb-cartpage.cpg-squeeze.busy #cartInner .srow span:last-child,
.kbb-cartpage.cpg-squeeze.busy #cartInner .cpg-totband b,
.kbb-cartpage.cpg-squeeze.busy #cartInner .cpg-cobar .tally b{
  color:transparent;border-radius:6px;
  background:linear-gradient(100deg,#EFF1F4 30%,#F8F9FB 48%,#EFF1F4 66%);
  background-size:220% 100%;animation:cpgshim 1.15s linear infinite}
@media (prefers-reduced-motion:reduce){
  .kbb-cartpage.cpg-squeeze.busy #cartInner .cpr,
  .kbb-cartpage.cpg-squeeze.busy #cartInner .srow span:last-child,
  .kbb-cartpage.cpg-squeeze.busy #cartInner .cpg-totband b,
  .kbb-cartpage.cpg-squeeze.busy #cartInner .cpg-cobar .tally b{animation:none}}
/* The owner's switch. Off, a wait is simply a wait. */
.kbb-cartpage.cpg-squeeze.cpg-nosk.busy #cartInner .cpr,
.kbb-cartpage.cpg-squeeze.cpg-nosk.busy #cartInner .srow span:last-child,
.kbb-cartpage.cpg-squeeze.cpg-nosk.busy #cartInner .cpg-totband b,
.kbb-cartpage.cpg-squeeze.cpg-nosk.busy #cartInner .cpg-cobar .tally b{
  color:inherit;background:none;animation:none}

/* ==========================================================================
   DESKTOP — TWO COLUMNS, FROM {{ (int) $kbbCartPage->get('d_min') }}px UP
   ==========================================================================

   EVERY RULE IN THIS BLOCK IS INSIDE THE min-width QUERY, and that is the
   whole design. The owner asked four times in one message not to touch the
   phone. A rule a phone cannot match is a rule that cannot touch it, so the
   guarantee is structural rather than a matter of care, and
   CartDesktopLeavesMobileAloneTest asserts it: no selector added here may sit
   outside the query.

   WHAT WAS ACTUALLY WRONG. `.kbb-cartpage .grid` is already a two-column grid
   in kbb-cart.css — `1fr 372px`, which is what the classic cart page uses. The
   squeezed layout collapses it to one column, at EVERY width:

       .kbb-cartpage.cpg-squeeze .grid{grid-template-columns:minmax(0,1fr)}

   There was no breakpoint anywhere on this page, so a 27-inch monitor got the
   phone layout stretched across it. That rule is right below 1024px and wrong
   above it, so this block puts the second column back — it does not invent a
   layout, it stops suppressing one.

   ONE ELEMENT WAS ADDED to the markup, `.cpg-side`, wrapping the summary and
   the docked rows so the right-hand column is a single grid item that can hold
   both. Below the breakpoint it is `display:contents` — it generates no box, so
   a phone lays those two out precisely as it did before it existed. That
   declaration sits OUTSIDE the query on purpose: "generate no box" is exactly
   what a phone needs it to do.

   The breakpoint is interpolated by Blade rather than read from a custom
   property, because it cannot be: a media query is resolved before custom
   properties exist, so `@media (min-width: var(--x))` is not a thing. Every
   other number here IS a custom property, so the sliders move the page without
   re-rendering it.
   ========================================================================== */
.kbb-cartpage.cpg-squeeze .cpg-side{display:contents}

@media (min-width: {{ (int) $kbbCartPage->get('d_min') }}px){

  /* The page stops growing and centres. A basket row drawn the full width of a
     wide monitor is a line of text nobody can track back to its own price. */
  .kbb-cartpage.cpg-squeeze.cpg-d .wrap{
    max-width:var(--cpg-d-max,1200px);
    margin-inline:auto;
    padding-inline:var(--cpg-d-padx,24px);
    /* The phone reserves room at the foot of the document for the two bars to
       float over. Nothing floats here, so that reservation is a hole, and this
       is the owner's own number in its place. */
    padding-top:var(--cpg-d-pady,24px);
    padding-bottom:var(--cpg-d-pady,24px)}

  .kbb-cartpage.cpg-squeeze.cpg-d .grid{
    grid-template-columns:minmax(0,1fr) var(--cpg-d-aside,380px);
    column-gap:var(--cpg-d-gap,28px);
    align-items:start}

  /* EVERYTHING goes left except the side block, selected by position rather
     than by name: the column that holds the basket rows carries no class of
     its own, and the coupon box and the rail are siblings beside it. Naming
     them would mean naming each one and missing the next one added.

     minmax(0,1fr) and not 1fr: a grid track's default minimum is min-content,
     so one long unbroken product name would push the column wider than the
     page instead of wrapping inside it. */
  .kbb-cartpage.cpg-squeeze.cpg-d .grid > *{grid-column:1}
  .kbb-cartpage.cpg-squeeze.cpg-d .grid{row-gap:var(--cpg-d-secgap,16px)}
  .kbb-cartpage.cpg-squeeze.cpg-d .grid > .cpg-side{
    display:flex;flex-direction:column;gap:var(--cpg-d-secgap,16px);
    grid-column:2;grid-row:1 / span 99}

  /* ── Padding inside a section ───────────────────────────────────────────
     ONE number, applied to the three boxes that hold something: the basket
     card, the rail and the summary. The phone's padding is the page's gutter,
     because the phone's column IS the page; here the page has a gutter of its
     own and a section's padding is a separate decision. */
  .kbb-cartpage.cpg-squeeze.cpg-d .items,
  .kbb-cartpage.cpg-squeeze.cpg-d .sum{padding:var(--cpg-d-secpad,16px)}
  .kbb-cartpage.cpg-squeeze.cpg-d .cpg-rec{padding:var(--cpg-d-secpad,16px)}

  /* The right column travels with the page once it reaches the top. This is
     NOT the phone's fixed bar and the difference is the point: the owner asked
     for the docked rows to stop being stuck to the screen and to move into
     this column, and a sticky column does that while still keeping Proceed to
     Checkout reachable down a long basket. */
  .kbb-cartpage.cpg-squeeze.cpg-d.cpg-dstick .grid > .cpg-side{
    position:sticky;top:var(--cpg-d-top,20px)}

  /* The docked rows, undocked: position:static drops them back into the
     column, and everything that made them a floating bar goes with it. */
  .kbb-cartpage.cpg-squeeze.cpg-d .cpg-docked{
    position:static;z-index:auto;width:auto;
    border:1px solid var(--line-2);border-radius:14px;overflow:hidden;
    padding:var(--cpg-d-dockpad,14px)}

  /* The stepper's desktop multiplier, on both of the phone's calc()s so the
     box and the glyph keep scaling as one shape -- the same rule `qty_size`
     itself follows. It MULTIPLIES that number rather than replacing it, so the
     two sliders stack and neither silently wins. */
  .kbb-cartpage.cpg-squeeze.cpg-d{
    --cpg-qty-h:calc(var(--cpg-row-h) * .30 * var(--cpg-qty-s) * var(--cpg-d-qty,1));
    --cpg-qty-f:calc(var(--cpg-row-h) * .135 * var(--cpg-fscale) * var(--cpg-qty-s) * var(--cpg-d-qty,1))}
  .kbb-cartpage.cpg-squeeze.cpg-d .cpg-addrbar{border-top:0}

  /* The rail is full-bleed on a phone because a phone's column IS the page:
     `margin:14px calc(50% - 50vw) 0; width:100vw; max-width:100vw`. Here it is
     one column of two, and 100vw is the whole WINDOW — measured at 1280px it
     came out 1280 wide starting at x=62, so it ran 124px past its own column
     and straight under the summary. All three declarations have to come off,
     not just the margins; overriding the margin alone leaves the width. */
  /* overflow:VISIBLE, and this is what stops the arrows being cut.
     
     The base rule clips this section -- `border-radius:0;overflow:hidden` --
     which is right on a phone, where the section runs the full width of the
     screen and the clip is what keeps the colour wash inside its corners. Here
     the arrows deliberately hang half outside the section's edge, and a
     clipping parent slices them in half lengthwise: exactly the sliver in the
     owner's screenshot.
     
     The wash keeps its rounded corners because the ::before layer takes the
     radius itself now, rather than borrowing the parent's clip. */
  .kbb-cartpage.cpg-squeeze.cpg-d .cpg-rec{
    margin-inline:0;width:auto;max-width:none;
    border-radius:14px;overflow:visible}
  .kbb-cartpage.cpg-squeeze.cpg-d .cpg-rec::before{border-radius:inherit}
  /* The rail still clips its own overflow -- that is the scroller -- and it is
     inside the section, so the arrows outside are unaffected by it. */
  .kbb-cartpage.cpg-squeeze.cpg-d .cpg-rail{border-radius:inherit}

  /* The rail's count, desktop's own. The phone's number is a fraction of the
     SCREEN — 4.5 cards across 390px is a readable card, 4.5 across a 748px
     column is a card with acres of nothing in it. Same arithmetic as the
     phone's rule, a different number in it, so the half card survives: a card
     cut off by the column edge is what says the rail carries on, and on a
     desktop there is no thumb to swipe, so that cue does MORE work here. */
  .kbb-cartpage.cpg-squeeze.cpg-d .cpg-card{
    flex:0 0 calc((100% - 14px - (7px * (var(--cpg-d-per,5.5) - 1))) / var(--cpg-d-per,5.5))}

  /* ── The carousel arrows ────────────────────────────────────────────────
     A phone swipes and needs no buttons. A desktop has no swipe: the rail
     moves with a trackpad or a shift-wheel, neither of which anybody
     discovers, so the half card announces more products and offers no way to
     reach them. These are that way.

     They sit OUTSIDE .cpg-rail — inside a scroller they would scroll away with
     the cards they move — and are positioned against .cpg-rec, which is
     already `position:relative` for the colour wash behind the cards.

     Centred on the IMAGE, not on the section: the cards carry a name and a
     price under the picture, so the middle of the section is somewhere near
     the text and the arrows would sit low. 42% is the middle of the square. */
  .kbb-cartpage.cpg-squeeze.cpg-d.cpg-darr .cpg-arr{
    display:grid;place-items:center;position:absolute;top:42%;z-index:2;
    width:var(--cpg-d-arrow,34px);height:var(--cpg-d-arrow,34px);
    transform:translateY(-50%);
    border:1px solid var(--line-2);border-radius:50%;background:#fff;
    color:var(--ink-2);cursor:pointer;padding:0;
    box-shadow:0 4px 14px -6px rgba(23,24,28,.4);
    transition:background .15s,color .15s,box-shadow .15s}
  .kbb-cartpage.cpg-squeeze.cpg-d.cpg-darr .cpg-arr:hover{
    background:var(--green,#1f7a4d);color:#fff;box-shadow:0 6px 18px -6px rgba(23,24,28,.5)}
  .kbb-cartpage.cpg-squeeze.cpg-d.cpg-darr .cpg-arr svg{
    width:calc(var(--cpg-d-arrow,34px) * .46);height:calc(var(--cpg-d-arrow,34px) * .46)}
  /* Half in, half out of the section's padding, so they read as belonging to
     the rail without covering the first or last card. */
  .kbb-cartpage.cpg-squeeze.cpg-d.cpg-darr .cpg-arr-l{
    inset-inline-start:calc(var(--cpg-d-arrow,34px) / -2.2)}
  .kbb-cartpage.cpg-squeeze.cpg-d.cpg-darr .cpg-arr-r{
    inset-inline-end:calc(var(--cpg-d-arrow,34px) / -2.2)}
  /* Nothing to scroll to in that direction. Dimmed rather than removed: a
     control that disappears moves everything beside it. */
  .kbb-cartpage.cpg-squeeze.cpg-d.cpg-darr .cpg-arr[disabled]{
    opacity:.35;pointer-events:none}

}
</style>
@endpush

