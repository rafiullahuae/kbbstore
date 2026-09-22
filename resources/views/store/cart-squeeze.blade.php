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
    $cpgJs = $kbbCartPage->jsConfig() + [
        'list' => Url::to('/cart/address'),
        'store' => Url::to('/cart/address'),
        'choose' => Url::to('/cart/address'),
        // The signed-out shopper's three, chosen by handle on their own path.
        // A separate base and not a separate suffix on `choose`, so no string
        // the script builds can put a handle where an id goes.
        'chooseGuest' => Url::to('/cart/address/guest'),
    ];
@endphp
@push('styles')
<style>
/* ── the knobs, with the values the service emits as fallbacks ───────────── */
.kbb-cartpage.cpg-squeeze{
  --cpg-row-h:96px; --cpg-fscale:1; --cpg-row-bold:600;
  --cpg-per:4.5; --cpg-rec-bold:400; --cpg-rec-price-bold:400; --cpg-drift:1;
  --cpg-rec-lh:1.25; --cpg-rec-gap:2px; --cpg-rec-img-gap:5px;
  --cpg-rec-add-s:1; --cpg-rec-add-x:0px; --cpg-rec-add-y:0px;
  --cpg-addr-h:40px; --cpg-co-h:62px; --cpg-bar-f:1;
  --cpg-bar-pad:0px; --cpg-addrbtn-f:1; --cpg-trust-s:1;
  --cpg-sheet-max:50%; --cpg-sheet-d:1; --cpg-sheet-f:1;

  /* derived, in CSS, once */
  --cpg-pad:calc(var(--cpg-row-h) * .13);
  --cpg-thumb:calc(var(--cpg-row-h) - (var(--cpg-pad) * 2));
  --cpg-f-name:calc((11.5px + var(--cpg-row-h) * .042) * var(--cpg-fscale));
  --cpg-f-brand:calc((7.5px + var(--cpg-row-h) * .022) * var(--cpg-fscale));
  --cpg-f-price:calc((11px + var(--cpg-row-h) * .040) * var(--cpg-fscale));
  --cpg-qty-h:calc(var(--cpg-row-h) * .30);
  --cpg-qty-f:calc(var(--cpg-row-h) * .135 * var(--cpg-fscale));
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
.kbb-cartpage.cpg-squeeze .paylogos{margin:0;gap:calc(4px * var(--cpg-trust-s));flex-wrap:nowrap}
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
.kbb-cartpage.cpg-squeeze .cpg-addrbar{display:flex;align-items:center;justify-content:space-between;
  gap:10px;min-height:var(--cpg-addr-h);padding:0 14px;background:#fff;
  border-top:1px solid var(--line-2);font-size:calc(11.5px * var(--cpg-bar-f))}
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
.kbb-cartpage.cpg-squeeze .cpg-addrbar .who b{font-weight:500;color:var(--ink-2)}
.kbb-cartpage.cpg-squeeze .cpg-addrbar .who span{color:var(--muted);
  font-size:calc(10px * var(--cpg-bar-f))}
/* Its own multiplier ON TOP of the shared bar scale, never instead of it, so
   this slider and "Text in both rows" cannot fight and neither can silently
   win — the same rule the row-text slider follows. */
.kbb-cartpage.cpg-squeeze .cpg-addrbtn{flex:none;background:none;border:0;color:var(--green);
  font-size:calc(12px * var(--cpg-bar-f) * var(--cpg-addrbtn-f));
  font-weight:600;cursor:pointer;padding:4px 0;
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

/* ── the address sheet ──────────────────────────────────────────────────── */
/* The page behind is DIMMED AND BLURRED. */
.cpg-scrim{position:fixed;inset:0;background:rgba(23,24,28,.34);z-index:97;opacity:0;
  pointer-events:none;transition:opacity .2s;
  -webkit-backdrop-filter:blur(var(--cpg-sheet-blur,3px));
  backdrop-filter:blur(var(--cpg-sheet-blur,3px))}
.cpg-scrim.on{opacity:1;pointer-events:auto}
/* And FROZEN, which is a separate thing and the one that matters. Without it a
   finger that misses the sheet scrolls the cart underneath it, and the address
   you were about to tap has moved by the time you tap again. Both elements
   carry the class because which one scrolls the document differs by browser,
   and `position:fixed` goes on <body> as well, because iOS Safari ignores
   overflow:hidden there. */
html.cpg-frozen,body.cpg-frozen{overflow:hidden}
/* Deliberately NOT `position:fixed` on <body>, which is the usual next step
   and is wrong here: it takes the body out of flow, the page jumps to the top,
   and the shopper who closes the sheet is somewhere else in their basket. The
   scrim absorbing the touch is enough for the case that actually bites — a
   finger that misses the sheet — and it costs nobody their scroll position. */
.cpg-scrim.on{touch-action:none}

/* PURE WHITE IN BOTH THEMES, and deliberately. The sheet sits over a dimmed
   page as its own surface; a panel that followed the page's colours would read
   as part of what is behind it rather than as a thing on top of it. */
.cpg-sheet{position:fixed;inset-inline:0;bottom:0;z-index:98;background:#fff;color:#17181C;
  /*
   * bottom:0, like .cpg-docked above, and for the same reason it ended up
   * there: a calc(100lvh - 100dvh) lift was tried on both and measured wrong
   * on a real phone. Chrome on Android already resolves a fixed bottom against
   * the visual viewport while the URL bar is out, so the subtraction counted
   * the chrome twice and floated the sheet above the screen edge.
   *
   * The sheet is overflow:hidden by design -- see the note below -- so if it
   * ever does hang off the bottom, "+ Add New Address" and "Deliver here"
   * cannot be scrolled back into reach. That makes it worth watching, but not
   * worth a second wrong fix.
   */
  border-radius:16px 16px 0 0;
  padding:14px 16px calc(12px + env(safe-area-inset-bottom,0px));
  transform:translateY(102%);transition:transform .26s cubic-bezier(.32,.72,0,1);
  max-height:var(--cpg-sheet-max,50%);
  /* HIDDEN, NOT auto. The sheet itself never scrolls: Home / Office / Deliver
     here has to stay where a thumb expects it, and a sheet that scrolls is a
     sheet whose commit button walks off the bottom. When there are more
     addresses than fit, .cpg-list scrolls inside its own box instead. */
  overflow:hidden;overscroll-behavior:contain;
  display:flex;flex-direction:column}
.cpg-sheet.on{transform:translateY(0)}
/* CHOOSING IS A SMALLER JOB THAN TYPING, so it gets a smaller popup. The sheet
   wears .cpg-pick while it is showing the saved list and that class swaps the
   cap for the list's own, which ships shorter than the form's (38% against
   50%). Nothing else about the sheet changes: same element, same markup, same
   slide — one declaration.

   IT IS STILL A CAP AND NOT A HEIGHT. Two addresses make a popup two addresses
   tall; the cap only decides where it stops growing. And when it does stop,
   .cpg-list is the one thing allowed to scroll — the sheet keeps overflow:hidden
   above and is a flex column, so the list is the only child that can shrink and
   + Add New Address stays on screen where a thumb can reach it. A sheet that
   scrolled would walk that link off the bottom, which is the requirement this
   must not regress. */
.cpg-sheet.cpg-pick{max-height:var(--cpg-sheet-max-list,38%)}
/* CLOSED MEANS GONE, not merely slid below the edge. A sheet that is only
   translated away still paints, still holds focus and is still reachable by
   Tab — a shopper tabbing through the cart lands inside an invisible dialog.
   [hidden] is applied once the slide has finished; the script also empties it,
   so a saved address is not left sitting in the document. */
.cpg-sheet[hidden]{display:none}
@media (prefers-reduced-motion:reduce){.cpg-sheet{transition:none}}
/* "give option on backend to make the city and the country in same row."

   One class, one rule, and it relies on the markup already being right: Area
   and Apartment / building carry `.full` and so keep the whole width, City and
   Country do not and so pair up.

   PORTRAIT ONLY. Held sideways the popup already puts every field two across
   — the rule immediately below — and a second declaration doing the same job
   there would fight it for the same property; whichever won, one of the two
   would be putting Area beside Apartment, which is not what this switch says
   it does. */
@media (orientation:portrait){
  .cpg-portal.cpg-twoup .cpg-fields{grid-template-columns:1fr 1fr;align-items:end}
  .cpg-portal.cpg-twoup .cpg-fields > .full{grid-column:1 / -1}
}
/* A phone on its side has roughly half the height and twice the width, so the
   fields go two across, the address list goes two across, and the cap rises.
   SAME MARKUP, SAME CLASSES — only the grid changes, which is why rotating the
   phone measures nothing and runs no script. */
@media (orientation:landscape){
  .cpg-sheet{max-height:var(--cpg-sheet-max-l,82%);padding-top:11px}
  /* Restated inside the query, and it has to be: `.cpg-sheet.cpg-pick` outbids
     the bare `.cpg-sheet` above on specificity, so without this line a phone on
     its side would keep the upright list cap and ignore the landscape one. Same
     specificity as the portrait rule, so source order decides and this block is
     below it. */
  .cpg-sheet.cpg-pick{max-height:var(--cpg-sheet-max-list-l,76%)}
  .cpg-fields{grid-template-columns:1fr 1fr;align-items:end}
  .cpg-fields > .full{grid-column:1 / -1}
  .cpg-list{grid-template-columns:1fr 1fr}
}
/* The close button is ABOVE the sheet, not inside it: its own round white
   target clear of the content, so a thumb reaching for it never lands on an
   address by accident. */
.cpg-x{position:fixed;inset-inline-end:14px;z-index:99;width:38px;height:38px;border-radius:50%;
  background:#fff;border:0;box-shadow:0 2px 10px -2px rgba(23,24,28,.3);cursor:pointer;
  display:grid;place-items:center;color:#17181C;opacity:0;pointer-events:none;bottom:0;
  transition:opacity .18s,transform .26s cubic-bezier(.32,.72,0,1);transform:translateY(12px)}
.cpg-x.on{opacity:1;pointer-events:auto;transform:translateY(0)}
.cpg-x svg{width:17px;height:17px}
.cpg-sheet h2{font-size:calc(17px * var(--cpg-sheet-f,1));font-weight:700;margin:0 0 12px;
  color:#17181C;flex:none}
/* The one thing in the sheet allowed to scroll, and only when it has to. */
.cpg-list{display:grid;gap:8px;margin-bottom:10px;min-height:0;overflow-y:auto;
  overscroll-behavior:contain;-webkit-overflow-scrolling:touch}
.cpg-al{position:relative;display:flex;gap:10px;align-items:flex-start;border:1px solid #E4E7EC;
  border-radius:10px;padding:calc(11px * var(--cpg-sheet-d,1));cursor:pointer;background:#fff;
  overflow:hidden;text-align:start;width:100%;font-family:inherit;color:#17181C}
.cpg-al[aria-selected="true"]{border-color:#1E9E5A}
.cpg-al[aria-selected="true"]::after{content:"";position:absolute;top:0;inset-inline-end:0;
  width:36px;height:30px;border-radius:0 9px 0 10px;background:#1E9E5A;
  background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'><path d='m4.5 12.5 5 5 10-11'/></svg>");
  background-size:15px;background-repeat:no-repeat;background-position:center}
.cpg-al .ad{min-width:0;flex:1}
.cpg-al .ad b{display:block;font-weight:700;font-size:calc(14px * var(--cpg-sheet-f,1));
  margin-bottom:3px;padding-inline-end:40px}
.cpg-al .ad span{display:block;color:#6B7280;font-size:calc(12.5px * var(--cpg-sheet-f,1));
  line-height:1.45}
.cpg-tag{flex:none;align-self:center;background:#E8F6EE;color:#177F47;
  font-size:calc(11px * var(--cpg-sheet-f,1));font-weight:500;padding:3px 9px;border-radius:5px}
/* "We keep your 3 most recent" — a fact, under the list, for a shopper who is
   not signed in and has three. flex:none like + Add New Address below it: the
   sheet is overflow:hidden and .cpg-list is the one thing allowed to scroll,
   so a note that could shrink would be squeezed out of existence by a full
   list instead of pushing the list's own scrollbox in. Sized from the popup's
   own font knob, like everything else in here. */
.cpg-note{flex:none;margin:calc(4px * var(--cpg-sheet-d,1)) 0 0;color:#6B7280;
  font-size:calc(11.5px * var(--cpg-sheet-f,1));line-height:1.35}
.cpg-addnew{display:flex;align-items:center;gap:7px;background:none;border:0;color:#1E9E5A;
  font-size:calc(14px * var(--cpg-sheet-f,1));font-weight:600;cursor:pointer;font-family:inherit;
  padding:calc(9px * var(--cpg-sheet-d,1)) 0;flex:none}
.cpg-fields{display:grid;gap:calc(9px * var(--cpg-sheet-d,1));margin-bottom:11px;
  min-height:0;overflow-y:auto;overscroll-behavior:contain}
.cpg-fields label{font-size:calc(11.5px * var(--cpg-sheet-f,1));font-weight:600;display:block;
  margin-bottom:3px;color:#3C3A40}
.cpg-fields .fi{width:100%;border:1px solid #E4E7EC;border-radius:8px;background:#fff;
  color:#17181C;font-family:inherit;font-size:calc(13px * var(--cpg-sheet-f,1));
  padding:calc(8px * var(--cpg-sheet-d,1)) 10px}
.cpg-fields .geo{font-size:calc(10.5px * var(--cpg-sheet-f,1));color:#1E9E5A;font-weight:500;margin:0}
/* Home · Office · Deliver here, ONE row, and the split is the point: two of
   them are choices and wear the light green of a selection, one ends the task
   and is solid dark green with a tick. Which button finishes is readable
   without reading the labels. 1fr on the last column so the commit takes
   whatever the two marks leave, at any text size. */
.cpg-actrow{display:grid;grid-template-columns:auto auto 1fr;gap:7px;align-items:stretch;
  margin-top:2px;flex:none}
.cpg-mark{display:inline-flex;align-items:center;justify-content:center;gap:6px;
  border:1px solid #E4E7EC;background:#fff;color:#3C3A40;border-radius:9px;
  padding:calc(9px * var(--cpg-sheet-d,1)) calc(12px * var(--cpg-sheet-d,1));
  font-family:inherit;font-size:calc(12.5px * var(--cpg-sheet-f,1));font-weight:600;
  cursor:pointer;white-space:nowrap}
.cpg-mark svg{width:calc(15px * var(--cpg-sheet-f,1));height:calc(15px * var(--cpg-sheet-f,1));
  flex:none}
.cpg-mark[aria-pressed="true"]{border-color:#1E9E5A;background:#E8F6EE;color:#177F47}
.cpg-deliver{display:inline-flex;align-items:center;justify-content:center;gap:6px;
  background:#1E9E5A;color:#fff;border:0;border-radius:9px;
  padding:calc(9px * var(--cpg-sheet-d,1)) calc(10px * var(--cpg-sheet-d,1));
  font-family:inherit;font-weight:700;font-size:calc(13.5px * var(--cpg-sheet-f,1));
  cursor:pointer;white-space:nowrap}
.cpg-deliver:hover{background:#177F47}
.cpg-deliver svg{width:calc(15px * var(--cpg-sheet-f,1));height:calc(15px * var(--cpg-sheet-f,1));
  flex:none}
.cpg-err{color:#B4443C;font-size:calc(12px * var(--cpg-sheet-f,1));margin:0 0 8px;flex:none}

/* ── the country picker ─────────────────────────────────────────────────
   IT OPENS UPWARD. A list dropping downward out of a field this near the
   bottom of the screen either lands underneath the two docked bars or pushes
   them off it.

   WHICH IS WHY IT IS NOT A NATIVE <select>. A select gives the page no say at
   all in which direction it opens — Android in particular opens it wherever
   the platform feels like. The cost of the decision is that the keyboard
   contract is now ours to honour: arrows move, Enter chooses, Escape closes
   and puts focus back on the button, which the script below does. */
.cpg-cpick{position:relative}
.cpg-cpick .fi{display:flex;align-items:center;justify-content:space-between;gap:8px;
  text-align:start;cursor:pointer;width:100%}
.cpg-cpick .fi .car{flex:none;width:9px;height:9px;border-inline-end:1.6px solid #9AA0AA;
  border-bottom:1.6px solid #9AA0AA;transform:rotate(-135deg) translate(-2px,-2px)}
.cpg-cmenu{position:absolute;inset-inline:0;bottom:calc(100% + 5px);z-index:5;background:#fff;
  border:1px solid #E4E7EC;border-radius:9px;box-shadow:0 -6px 22px -8px rgba(23,24,28,.28);
  max-height:190px;overflow-y:auto;overscroll-behavior:contain;padding:4px;display:none}
.cpg-cmenu.on{display:block}
.cpg-cmenu button{display:flex;align-items:center;justify-content:space-between;gap:8px;
  width:100%;border:0;background:none;color:#17181C;font-family:inherit;
  font-size:calc(12.5px * var(--cpg-sheet-f,1));text-align:start;padding:8px 9px;
  border-radius:6px;cursor:pointer}
.cpg-cmenu button:hover,.cpg-cmenu button:focus-visible{background:#F4F6F8}
.cpg-cmenu button[aria-selected="true"]{color:#177F47;font-weight:600}
.cpg-cmenu button .gx{font-size:9.5px;color:#1E9E5A;font-weight:600;flex:none}
</style>
@endpush

@push('scripts')
<script>
/*
 * The address sheet. The ONLY script the squeezed cart page adds.
 *
 * One fetch when it opens, one POST when something is chosen, and class
 * toggles in between. Nothing here measures or sizes anything — every
 * dimension on this page comes out of calc() in the block above, and the
 * landscape layout is a media query, so rotating the phone runs none of this.
 *
 * The docked row is rendered by the server with whatever the session already
 * holds, so a shopper who reloads, or who changes a quantity and gets the cart
 * re-rendered under them, keeps their chosen address without this script
 * running at all. It only has to update the row for the tap that just happened.
 */
(function () {
  'use strict';

  var root = document.getElementById('cartPage');
  var sheet = document.getElementById('cpgSheet');
  if (!root || !sheet) return;

  var CFG = @json($cpgJs);
  var scrim = document.getElementById('cpgScrim');
  var closeBtn = document.getElementById('cpgX');

  var state = null;      // the last payload from the server
  var tag = 'home';
  var country = null;    // null until the geo default arrives
  var busy = false;
  var hideTimer = null;  // the one that takes the sheet off the screen

  /* Inline, and stroked from `currentColor`, so each icon takes its button's
     colour in both of its states without a second copy of the path. */
  var I_HOME = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 10.5 12 3.5l8.5 7"/><path d="M5.5 9.7V20h13V9.7"/><path d="M10 20v-5.2h4V20"/></svg>';
  var I_WORK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="7.5" width="18" height="12" rx="2"/><path d="M9 7.5V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v1.5"/><path d="M3 12.5h18"/></svg>';
  var I_TICK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 12.5 5 5 10-11"/></svg>';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  async function call(url, body) {
    var opts = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-TOKEN'] = (window.KBB && window.KBB.csrf) || '';
      opts.body = JSON.stringify(body);
    }
    var r = await fetch(url, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) {
      var err = new Error('cart address ' + r.status);
      err.status = r.status;
      err.body = payload;
      throw err;
    }
    return payload;
  }

  /* ---------------------------------------------------------------- draw */

  /* Grey blocks in the SHAPE of the thing that is coming. Drawn only while a
     real request is in flight — never for content already in hand. */
  function skeletonHTML(rows) {
    if (root.classList.contains('cpg-nosk')) return '<h2>' + esc(CFG.listTitle) + '</h2>';

    var cards = '';
    for (var i = 0; i < rows; i++) {
      cards += '<div class="cpg-skcard" aria-hidden="true">'
        + '<div class="cpg-sk cpg-skline w40"></div>'
        + '<div class="cpg-sk cpg-skline w90"></div>'
        + '<div class="cpg-sk cpg-skline w65"></div></div>';
    }

    return '<h2>' + esc(CFG.listTitle) + '</h2>' + cards
      + '<span class="cpg-vh" role="status">' + esc(CFG.loading) + '</span>';
  }

  function listHTML() {
    var rows = (state && state.addresses) || [];
    var chosenId = state && state.chosen ? state.chosen.id : null;
    var chosenKey = state && state.chosen ? state.chosen.key : null;

    var items = rows.map(function (a) {
      /* A ROW WITH NO id IS ONE OF THE GUEST'S OWN, held in the session. It is
         re-selected by its HANDLE and not by an id, on its own endpoint: a
         handle names a slot in one session and must never arrive at
         /cart/address/{id}/choose, which ends in a lookup in `addresses`.

         A row with neither an id nor a handle is the old case and still
         behaves the old way — drawn as the current choice, tapped to confirm,
         never posted anywhere. There is nothing to re-select it BY, and "null"
         in either URL is a 404 that leaves the sheet sitting open looking
         broken. */
      var mine = a.id === null || a.id === undefined;
      var gkey = mine && a.key ? a.key : null;

      var on = gkey ? gkey === chosenKey : (!mine && a.id === chosenId);

      return '<button type="button" class="cpg-al"'
        + (gkey ? ' data-cpg-gpick="' + esc(gkey) + '"'
                : (mine ? ' data-cpg-keep' : ' data-cpg-pick="' + a.id + '"'))
        + ' aria-selected="' + (on || (mine && !gkey) ? 'true' : 'false') + '">'
        + '<span class="ad"><b>' + esc(a.name || CFG[a.tag] || a.tag) + '</b>'
        + '<span>' + esc(a.line) + '</span></span>'
        + '<span class="cpg-tag">' + esc(CFG[a.tag] || a.tag) + '</span>'
        + '</button>';
    }).join('');

    /* Said out loud, and only when it is about to be true. The cap belongs to
       a shopper who is not signed in, and the fourth address replaces the
       oldest rather than being refused — so the sheet says which, at the point
       where the next save will actually replace something. `guestMax` comes
       from the server so the number here and the number the server enforces
       cannot drift apart. */
    var cap = (state && state.guestMax) || 0;
    var note = (state && !state.signedIn && cap > 0 && rows.length >= cap)
      ? '<p class="cpg-note">' + esc(CFG.guestNote) + '</p>'
      : '';

    return '<h2>' + esc(CFG.listTitle) + '</h2>'
      + '<div class="cpg-list">' + items + '</div>'
      + note
      + '<button type="button" class="cpg-addnew" data-cpg-new>'
      + '<span aria-hidden="true">+</span> ' + esc(CFG.addNew.replace(/^\+\s*/, '')) + '</button>';
  }

  function formHTML() {
    var geo = (state && state.geo) || { country: '', countryName: '' };
    var list = (state && state.countries) || [];

    if (country === null) country = geo.country || '';

    var label = '';
    var opts = list.map(function (c) {
      if (c.code === country) label = c.name;
      return '<button type="button" role="option" data-cpg-country="' + esc(c.code) + '"'
        + ' aria-selected="' + (c.code === country ? 'true' : 'false') + '">'
        + '<span>' + esc(c.name) + '</span>'
        + (c.code === geo.country ? '<span class="gx">' + esc(CFG.geoMark) + '</span>' : '')
        + '</button>';
    }).join('');

    return '<h2>' + esc(CFG.formTitle) + '</h2>'
      + '<p class="cpg-err" id="cpgErr" hidden></p>'
      + '<div class="cpg-fields">'
      + '<div class="full"><label for="cpgArea">' + esc(CFG.area) + '</label>'
      + '<input class="fi" id="cpgArea" placeholder="' + esc(CFG.areaHint)
      + '" autocomplete="address-level2"></div>'
      + '<div class="full"><label for="cpgApt">' + esc(CFG.apt) + '</label>'
      + '<input class="fi" id="cpgApt" placeholder="' + esc(CFG.aptHint)
      + '" autocomplete="address-line1"></div>'
      /* City is EMPTY, with a grey placeholder. Only the country is filled in
         from where the shopper is, which is why the green line below says so in
         the singular — a note claiming to have filled a field it left blank is
         a note nobody believes twice. */
      + '<div><label for="cpgCity">' + esc(CFG.city) + '</label>'
      + '<input class="fi" id="cpgCity" placeholder="' + esc(CFG.cityHint)
      + '" autocomplete="address-level1"></div>'
      + '<div class="cpg-cpick"><label for="cpgCountry">' + esc(CFG.country) + '</label>'
      + '<button class="fi" id="cpgCountry" type="button" aria-haspopup="listbox" aria-expanded="false">'
      + '<span id="cpgCountryName">' + esc(label || geo.countryName) + '</span>'
      + '<span class="car" aria-hidden="true"></span></button>'
      + '<div class="cpg-cmenu" id="cpgCmenu" role="listbox" aria-label="' + esc(CFG.country) + '">'
      + opts + '</div></div>'
      + '<p class="geo full">&#10003; ' + esc(CFG.geoNote) + '</p>'
      + '</div>'
      /* Home · Office · Deliver here, one row. Two choices and a commit. */
      + '<div class="cpg-actrow">'
      + '<button class="cpg-mark" type="button" data-cpg-tag="home" aria-pressed="'
      + (tag === 'home') + '">' + I_HOME + ' ' + esc(CFG.home) + '</button>'
      + '<button class="cpg-mark" type="button" data-cpg-tag="office" aria-pressed="'
      + (tag === 'office') + '">' + I_WORK + ' ' + esc(CFG.office) + '</button>'
      + '<button class="cpg-deliver" type="button" data-cpg-save>' + I_TICK + ' '
      + esc(CFG.save) + '</button>'
      + '</div>';
  }

  /* `pick` is true for the two screens that show the SAVED LIST — the list
     itself and the placeholder in its shape — and false for the form. It puts
     .cpg-pick on the sheet, which is the whole of how the shorter cap is
     selected; the sizing itself stays in CSS. Nothing here measures anything. */
  function paint(html, pick) {
    sheet.classList.toggle('cpg-pick', pick === true);
    sheet.innerHTML = html;
    // The close button rides just above whatever height the sheet settled at.
    requestAnimationFrame(function () {
      closeBtn.style.bottom = (sheet.offsetHeight + 12) + 'px';
    });
  }

  /* The docked row, after a choice. The server renders it on every page load
     and every cart re-render; this is only the tap that has just happened. */
  function paintRow() {
    var head = document.getElementById('cpgAddrHead');
    var sub = document.getElementById('cpgAddrSub');
    var btn = document.getElementById('cpgAddrBtn');
    if (!head || !btn) return;

    var a = state && state.chosen;

    /* The fade off the right belongs to an address, not to the prompt that
       stands in for one — see the rule in the stylesheet. Toggled here as well
       as rendered by the server, so the row is right in the first paint AND
       after a tap. */
    var bar = head.closest('.cpg-addrbar');
    if (bar) bar.classList.toggle('cpg-has', !!a);

    if (a) {
      head.textContent = CFG.chosen.replace('{tag}', CFG[a.tag] || a.tag);
      if (sub) { sub.textContent = a.line; sub.hidden = false; }
      btn.textContent = CFG.btnChange;
    } else {
      head.textContent = CFG.heading;
      if (sub) { sub.textContent = ''; sub.hidden = true; }
      btn.textContent = state && state.signedIn ? CFG.btnChange : CFG.btnAdd;
    }
  }

  /* -------------------------------------------------------------- open */

  function freeze(on) {
    document.documentElement.classList.toggle('cpg-frozen', on);
    document.body.classList.toggle('cpg-frozen', on);
  }

  function closeCountry() {
    var menu = document.getElementById('cpgCmenu');
    var btn = document.getElementById('cpgCountry');
    if (menu) menu.classList.remove('on');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }

  function close() {
    if (sheet.hidden) return;

    sheet.classList.remove('on');
    scrim.classList.remove('on');
    closeBtn.classList.remove('on');
    closeCountry();
    freeze(false);

    /* GONE FROM THE SCREEN once the slide has finished — not merely pushed
       below the edge, where it goes on painting and goes on taking Tab. The
       timer is cancelled on reopen: without that, a fast close-then-open hides
       the sheet that was just opened. */
    clearTimeout(hideTimer);
    hideTimer = setTimeout(function () {
      sheet.hidden = true;
      sheet.innerHTML = '';
      // Emptied of content, it is neither screen, so it claims to be neither.
      sheet.classList.remove('cpg-pick');
    }, 280);
  }

  async function launch() {
    clearTimeout(hideTimer);
    sheet.hidden = false;
    /* ONE FRAME with the sheet laid out but still translated down, so the
       slide has somewhere to come from. Without it the browser has no start
       value to animate between and the sheet simply appears. */
    requestAnimationFrame(function () { sheet.classList.add('on'); });
    scrim.classList.add('on');
    closeBtn.classList.add('on');
    freeze(true);

    /* A REAL request is about to happen, so the placeholder earns its place.
       On the second opening the list is already in hand, so the content goes
       straight in and no placeholder is drawn at all — a skeleton that flashes
       for a fortieth of a second reads as a glitch. */
    if (state === null) {
      // The placeholder is list-shaped, so it takes the list's cap too — a
      // skeleton in a taller box than the thing it stands in for is a sheet
      // that visibly shrinks the moment the real list lands.
      paint(skeletonHTML(2), true);

      try {
        state = await call(CFG.list);
      } catch (e) {
        state = { addresses: [], chosen: null, signedIn: false, geo: {} };
      }
    }

    /* ONE SAVED ADDRESS STILL OPENS THE LIST. `length` and not `length > 1`,
       and that is a decision rather than what fell out of the code: one row
       plus + Add New Address is still a choice, and a shopper who has an
       address on file and is dropped straight into an empty form reads it as
       "mine is gone" — then types it again, and the shop holds it twice. The
       only case with nothing to choose from is none at all, and that opens the
       form. */
    var hasSaved = !!(state.addresses && state.addresses.length);

    paint(hasSaved ? listHTML() : formHTML(), hasSaved);
  }

  document.addEventListener('click', function (e) {
    if (e.target.closest('#cpgAddrBtn')) { e.preventDefault(); launch(); return; }
    if (e.target === scrim || e.target.closest('#cpgX')) { close(); return; }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || sheet.hidden) return;

    // Escape closes the country list first, and only then the sheet. Closing
    // both at once loses a shopper's half-finished address to one keystroke.
    var menu = document.getElementById('cpgCmenu');
    if (menu && menu.classList.contains('on')) {
      closeCountry();
      var btn = document.getElementById('cpgCountry');
      if (btn) btn.focus();
      return;
    }

    close();
  });

  /* The keyboard contract a native <select> would have given for free, and
     which this owes because it is not one: arrows move, Enter and Space
     choose, Escape closes and hands focus back. */
  sheet.addEventListener('keydown', function (e) {
    var opts = Array.prototype.slice.call(sheet.querySelectorAll('[data-cpg-country]'));
    if (opts.length === 0) return;

    var here = opts.indexOf(document.activeElement);

    if (e.target.closest('#cpgCountry') && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
      e.preventDefault();
      openCountry(true);
      (opts[e.key === 'ArrowUp' ? opts.length - 1 : 0] || opts[0]).focus();
      return;
    }

    if (here === -1) return;

    if (e.key === 'ArrowDown') { e.preventDefault(); (opts[here + 1] || opts[0]).focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); (opts[here - 1] || opts[opts.length - 1]).focus(); }
    else if (e.key === 'Home') { e.preventDefault(); opts[0].focus(); }
    else if (e.key === 'End') { e.preventDefault(); opts[opts.length - 1].focus(); }
    else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); document.activeElement.click(); }
  });

  function openCountry(on) {
    var menu = document.getElementById('cpgCmenu');
    var btn = document.getElementById('cpgCountry');
    if (!menu || !btn) return;
    menu.classList.toggle('on', on);
    btn.setAttribute('aria-expanded', String(on));
  }

  sheet.addEventListener('click', async function (e) {
    if (busy) return;

    var mark = e.target.closest('[data-cpg-tag]');
    if (mark) {
      tag = mark.getAttribute('data-cpg-tag');
      sheet.querySelectorAll('[data-cpg-tag]').forEach(function (b) {
        b.setAttribute('aria-pressed', String(b === mark));
      });
      return;
    }

    // The country list: the button toggles it, an option chooses and closes it,
    // and a tap anywhere else inside the sheet dismisses it.
    if (e.target.closest('#cpgCountry')) {
      var menu = document.getElementById('cpgCmenu');
      openCountry(!(menu && menu.classList.contains('on')));
      return;
    }

    var opt = e.target.closest('[data-cpg-country]');
    if (opt) {
      country = opt.getAttribute('data-cpg-country');
      var name = opt.querySelector('span');
      var out = document.getElementById('cpgCountryName');
      if (out && name) out.textContent = name.textContent;
      sheet.querySelectorAll('[data-cpg-country]').forEach(function (b) {
        b.setAttribute('aria-selected', String(b === opt));
      });
      closeCountry();
      var back = document.getElementById('cpgCountry');
      if (back) back.focus();
      return;
    }

    if (!e.target.closest('.cpg-cpick')) closeCountry();

    // + Add New Address: the form, and with it the form's taller cap.
    if (e.target.closest('[data-cpg-new]')) { paint(formHTML(), false); return; }

    /* One of the guest's session addresses. Its own endpoint, taking its own
       handle: the server resolves it against this session's list and opens no
       table, and the handle never goes near the route that takes an id. Read
       FIRST, so a guest row can never fall through to either branch below. */
    var gpick = e.target.closest('[data-cpg-gpick]');
    if (gpick) {
      busy = true;
      try {
        state = await call(CFG.chooseGuest + '/' + encodeURIComponent(gpick.getAttribute('data-cpg-gpick')) + '/choose', {});
        paintRow();
        close();
      } catch (err) { /* the sheet stays open on a failure, still showing the list */ }
      busy = false;
      return;
    }

    /* An address with neither an id nor a handle — already chosen, and not
       re-selectable by either. Tapping it confirms and closes, with no request:
       choosing what is already chosen has nothing to tell the server. */
    if (e.target.closest('[data-cpg-keep]')) { close(); return; }

    /* Tapping an address IS the choice: it selects, it closes, and it lands in
       the docked row. There is no confirm step, because the row is the
       confirmation. */
    var pick = e.target.closest('[data-cpg-pick]');
    if (pick) {
      busy = true;
      try {
        state = await call(CFG.choose + '/' + pick.getAttribute('data-cpg-pick') + '/choose', {});
        paintRow();
        close();
      } catch (err) { /* the sheet stays open on a failure, still showing the list */ }
      busy = false;
      return;
    }

    if (e.target.closest('[data-cpg-save]')) {
      busy = true;
      var val = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };
      try {
        state = await call(CFG.store, {
          area: val('cpgArea'),
          apartment: val('cpgApt'),
          city: val('cpgCity'),
          country: country || (state && state.geo && state.geo.country) || '',
          tag: tag,
        });
        paintRow();
        close();
      } catch (err) {
        var box = document.getElementById('cpgErr');
        if (box) {
          box.textContent = (err.body && err.body.error) || CFG.saveFailed;
          box.hidden = false;
        }
      }
      busy = false;
    }
  });
})();
</script>
@endpush
