{{--
    THE DELIVERY-ADDRESS SHEET — the one on the cart page and the one on the
    checkout, because they are the same sheet.

    ── WHY THIS IS A PARTIAL AND NOT A COPY ───────────────────────────────────

    The addresses a shopper keeps are the SAME addresses on both pages. A
    signed-out shopper's three live in the session under
    CartAddressState::SESSION_KEY; a signed-in one's live in `addresses`. Both
    pages read that one state through the same /cart/address endpoints, so an
    address added on the cart is already there at the checkout and the other
    way round, with nothing syncing anything. That is a property of having one
    sheet rather than two, which is the whole reason this file exists.

    A second copy of this markup would be a second copy of the session key, the
    endpoints, the guest cap and the enumeration rules that go with them. The
    cart page and the checkout would then drift, and the drift would be
    somebody's home address showing on one page and not the other.

    ── WHERE IT GOES IN THE PAGE ──────────────────────────────────────────────

    OUTSIDE the page's own scrolling box, always. Nested inside one, a sheet
    positioned against `bottom:0` resolves against the CONTENT box rather than
    the visible one, so on a long page it parks itself below everything and
    later-painting rows come up through the middle of it. No z-index fixes
    that: it is a containing-block problem wearing a stacking-order costume.
    It also has to be clear of any ancestor with clipped overflow, which clips
    fixed-position descendants too.

    It is EMPTY in the markup. Its contents come from the one fetch it makes
    when it opens, so a page nobody taps the button on carries no address list
    at all — a saved address is somebody's home, and it does not belong in the
    HTML of a page they have not asked for it on.

    The × is a SIBLING of the sheet rather than a child: on a phone it floats
    above it, clear of the content, so a thumb reaching for it never lands on
    an address by accident. On desktop the script moves it to the panel's own
    top-right corner — see placeClose().
--}}
@php
    use App\Support\Url;

    /*
     * Resolved HERE rather than taken from the including page, so this partial
     * works on any page that includes it without that page having to know what
     * the sheet needs. The cart page and the checkout both just include it.
     */
    $kbbSheet = app(\App\Services\CartPage::class);
    [$kbbSheetClass, $kbbSheetStyle] = $kbbSheet->sheetAttrs();
    $kbbSheetJs = $kbbSheet->jsConfig() + [
        'list'        => Url::to('/cart/address'),
        'store'       => Url::to('/cart/address'),
        'choose'      => Url::to('/cart/address'),
        'chooseGuest' => Url::to('/cart/address/guest'),
    ];
@endphp
<div class="cpg-portal{{ $kbbSheetClass }}"{!! $kbbSheetStyle !!}>
    <div class="cpg-scrim" id="cpgScrim"></div>
    <button class="cpg-x" id="cpgX" type="button" aria-label="{{ __('store.quick_view.close_label') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
    <div class="cpg-sheet" id="cpgSheet" role="dialog" aria-modal="true" aria-label="{{ $kbbSheet->get('sheet_list_title') }}" hidden></div>
</div>
@push('styles')
<style>
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
.cpg-sheet h2{font-size:calc(17px * var(--cpg-sheet-f,1));font-weight:700;margin:0;
  color:#17181C;flex:none;min-width:0}
/* TITLE LEFT, ACTIONS RIGHT, in one row inside the panel.

   The close button used to be the only way out of the form, and it closed the
   whole sheet: a shopper who tapped "Add New Address" to look at the form had
   no way back to the addresses they already had, which reads as having lost
   them. `Back to address` goes straight back to the list.

   The X in this row is DESKTOP ONLY -- see the min-width block at the foot of
   this stylesheet. On a phone the close button is the floating round one above
   the sheet, which is a bigger target than anything that would fit in here and
   is not moving. */
.cpg-head{display:flex;align-items:center;justify-content:space-between;gap:10px;
  margin:0 0 12px;flex:none}
.cpg-acts{display:flex;align-items:center;gap:6px;flex:none}
.cpg-back{display:inline-flex;align-items:center;gap:5px;border:0;background:none;
  cursor:pointer;padding:5px 7px;border-radius:8px;color:var(--green,#1f7a4d);
  font:600 calc(12px * var(--cpg-sheet-f,1))/1.1 inherit}
.cpg-back:hover{background:rgba(31,122,77,.08)}
.cpg-back svg{width:calc(14px * var(--cpg-sheet-f,1));height:calc(14px * var(--cpg-sheet-f,1));flex:none}
/* Hidden on a phone, shown on desktop. Declared here rather than only in the
   query so there is ONE rule deciding it, and the phone's answer is the one it
   gives by default. */
.cpg-xin{display:none;border:0;background:none;cursor:pointer;padding:5px;
  border-radius:8px;color:#17181C;line-height:0}
.cpg-xin:hover{background:rgba(23,24,28,.07)}
.cpg-xin svg{width:calc(17px * var(--cpg-sheet-f,1));height:calc(17px * var(--cpg-sheet-f,1))}
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

@media (min-width: {{ (int) $kbbSheet->get('d_min') }}px){
  /* ── The address popup ──────────────────────────────────────────────────
     A phone's sheet rises from the bottom edge because that is where a thumb
     is. A desktop has no bottom edge worth rising from, so it is a panel
     centred over the page with the page blurred behind it. Everything inside
     is untouched: the skeleton, the country list that opens upward, the
     Home / Office / Deliver here row and both height caps. */
  .cpg-scrim{backdrop-filter:blur(var(--cpg-d-blur,4px));
    -webkit-backdrop-filter:blur(var(--cpg-d-blur,4px))}

  .cpg-sheet{
    top:50%;left:50%;right:auto;bottom:auto;
    width:min(var(--cpg-d-modal,460px), calc(100vw - 48px));
    max-height:min(78vh, 720px);
    border-radius:16px;
    transform:translate(-50%,-46%) scale(.98)}
  .cpg-sheet.on{transform:translate(-50%,-50%) scale(1)}

  /* THE SAME ROUND BUTTON AS THE PHONE'S, at the panel's top-right corner
     rather than above it. placeClose() in the script puts it there, because
     the panel is centred and only the script knows how tall it ended up. The
     in-panel X is gone: one close button, one shape, both layouts. */
  .cpg-xin{display:none}
}
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

  /* THE SHEET IS THE ONLY THING THIS NEEDS, and that is the change that let
     the checkout have it too. It used to require #cartPage as well and return
     if it was missing, which is correct on the cart page and is why nothing
     happened anywhere else. The one thing #cartPage was read for is the
     skeleton switch, which has a default of its own below. */
  var sheet = document.getElementById('cpgSheet');
  if (!sheet) return;

  var root = document.getElementById('cartPage');

  var CFG = @json($kbbSheetJs);
  var scrim = document.getElementById('cpgScrim');
  var closeBtn = document.getElementById('cpgX');

  var state = null;      // the last payload from the server
  var tag = 'home';
  var country = null;    // null until the geo default arrives
  var busy = false;
  var hideTimer = null;  // the one that takes the sheet off the screen

  /* WHERE THE CLOSE BUTTON GOES, and it is two different places.
     
     On a phone the sheet rises from the bottom edge and the button rides just
     above whatever height it settled at — its own round white target, clear of
     the content, so a thumb reaching for it never lands on an address.
     
     On desktop the panel is centred, so "just above the sheet" is the middle of
     the screen and the button would sit on top of the page. It goes to the
     panel's own top-right corner instead, just outside it, in the same circle.
     The panel is centred at 50%/50%, so its edges are 50% ± half its box —
     which is why this is in the script: the panel's height depends on what is
     in it, and the arithmetic needs both numbers.
     
     THE SHEET'S OWN offsetHeight IS ALREADY WHAT THE PHONE PATH USES, so this
     measures nothing the page did not already measure. Nothing here sizes
     anything: it positions one button that CSS cannot reach, after the paint
     that decided the panel's box. */
  function placeClose() {
    var desk = CFG.desktop && window.matchMedia('(min-width:' + (CFG.bp || 1024) + 'px)').matches;

    if (desk) {
      closeBtn.style.bottom = 'auto';
      closeBtn.style.top = 'calc(50% - ' + (sheet.offsetHeight / 2) + 'px - 46px)';
      closeBtn.style.insetInlineEnd = 'calc(50% - ' + (sheet.offsetWidth / 2) + 'px)';
      return;
    }

    closeBtn.style.top = '';
    closeBtn.style.insetInlineEnd = '';
    closeBtn.style.bottom = (sheet.offsetHeight + 12) + 'px';
  }

  /* The panel moves when the window does — a narrower window makes it taller,
     and the button has to follow the corner it is pinned to. */
  window.addEventListener('resize', function () {
    if (sheet.classList.contains('on')) placeClose();
  });

  /* Grey out an arrow with nothing to scroll to. Rounded before comparing:
     a scroller at its end reports a fractional scrollLeft on a zoomed page or
     a high-DPI screen, so `>=` against the exact maximum is false by half a
     pixel and the arrow never dims. */
  function railArrows(railEl) {
    var box = railEl.parentNode;
    var l = box.querySelector('.cpg-arr-l');
    var r = box.querySelector('.cpg-arr-r');
    var max = railEl.scrollWidth - railEl.clientWidth;
    if (l) l.disabled = Math.round(railEl.scrollLeft) <= 0;
    if (r) r.disabled = Math.round(railEl.scrollLeft) >= Math.round(max) - 1;
  }

  /* Bound on the document for the same reason the clicks are: the rail itself
     is replaced on every cart write. `true` is the capture phase, because
     scroll does not bubble. */
  document.addEventListener('scroll', function (e) {
    var el = e.target;
    if (el && el.classList && el.classList.contains('cpg-rail')) railArrows(el);
  }, true);

  /* And once at load, so an arrow with nothing behind it starts dimmed rather
     than waiting for the first scroll to find out. */
  function railArrowsAll() {
    Array.prototype.forEach.call(document.querySelectorAll('.cpg-rail'), railArrows);
  }
  railArrowsAll();
  document.addEventListener('kbb:cart-updated', railArrowsAll);

  /* Inline, and stroked from `currentColor`, so each icon takes its button's
     colour in both of its states without a second copy of the path. */
  var I_HOME = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 10.5 12 3.5l8.5 7"/><path d="M5.5 9.7V20h13V9.7"/><path d="M10 20v-5.2h4V20"/></svg>';
  var I_WORK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="7.5" width="18" height="12" rx="2"/><path d="M9 7.5V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v1.5"/><path d="M3 12.5h18"/></svg>';
  var I_TICK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 12.5 5 5 10-11"/></svg>';
  var I_BACK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg>';
  var I_CLOSE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';

  /* The panel's own header. The X in it is desktop-only (CSS decides), and the
     back link appears only when there is a list to go back to -- on a first
     address the form IS the sheet, and a link to an empty list is a link to
     nothing. */
  function headHTML(title, withBack) {
    return '<div class="cpg-head"><h2>' + esc(title) + '</h2><div class="cpg-acts">'
      + (withBack
          ? '<button type="button" class="cpg-back" data-cpg-back>' + I_BACK + '<span>' + esc(CFG.back || 'Back to address') + '</span></button>'
          : '')
      + '<button type="button" class="cpg-xin" data-cpg-xin aria-label="Close">' + I_CLOSE + '</button>'
      + '</div></div>';
  }

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
    /* The shimmer is off when the cart page says so. On a page that carries no
       #cartPage — the checkout — there is nothing to say otherwise, so it
       stays on, which is the shipped default either way. */
    if (root && root.classList.contains('cpg-nosk')) return headHTML(CFG.listTitle, false);

    var cards = '';
    for (var i = 0; i < rows; i++) {
      cards += '<div class="cpg-skcard" aria-hidden="true">'
        + '<div class="cpg-sk cpg-skline w40"></div>'
        + '<div class="cpg-sk cpg-skline w90"></div>'
        + '<div class="cpg-sk cpg-skline w65"></div></div>';
    }

    return headHTML(CFG.listTitle, false) + cards
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

    return headHTML(CFG.listTitle, false)
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

    return headHTML(CFG.formTitle, ((state && state.addresses) || []).length > 0)
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
    requestAnimationFrame(placeClose);
  }

  /* The docked row, after a choice. The server renders it on every page load
     and every cart re-render; this is only the tap that has just happened. */
  /* THE CHECKOUT'S SHIPPING ADDRESS SECTION, and the four hidden inputs the
     order is actually posted with.

     paintRow() above draws the cart page's docked row and returns early when
     that row is not on the page, which it is not here. This draws the other
     one. Both are called from paintChosen(), so a tap keeps them in step
     without either knowing the other exists.

     THE COUNTRY DISPATCHES `change`. checkout.js listens for it on
     #billing_country and re-fetches the delivery rates, the free-shipping bar
     and the totals. Reusing that rather than posting somewhere new is what
     keeps the price a shopper is shown and the price they are charged on one
     code path. It fires only when the country actually changed: the handler
     does a round trip, and choosing between two addresses in the same country
     does not need one. */
  function paintCheckout() {
    var box = document.getElementById('cka');
    if (!box) return;

    var a = (state && state.chosen) || null;
    var f = (a && a.form) || {};

    var tag = document.getElementById('ckaTag');
    var line = document.getElementById('ckaLine');
    var prompt = document.getElementById('ckaPrompt');
    var btn = document.getElementById('cpgAddrBtn');

    if (a) {
      /* The prompt row and the chosen row are different shapes, and the server
         rendered whichever was right at load. Going from none to one has to
         build the row this page has never had. */
      if (!tag) {
        box.insertAdjacentHTML('afterbegin',
          '<div class="cka-list"><div class="cka-row on"><span class="cka-ic"></span>'
          + '<span class="cka-ad"><b id="ckaTag"></b><i id="ckaLine"></i></span></div></div>');
        var empty = box.querySelector('.cka-empty');
        var moved = box.querySelector('.cka-row');
        if (btn && moved) moved.appendChild(btn);
        if (empty) empty.remove();
        tag = document.getElementById('ckaTag');
        line = document.getElementById('ckaLine');
      }
      if (tag) tag.textContent = CFG[a.tag] || a.tag;
      if (line) line.textContent = a.line;
      if (btn) btn.textContent = CFG.btnChange;
    } else if (prompt) {
      prompt.textContent = CFG.heading;
      if (btn) btn.textContent = state && state.signedIn ? CFG.btnChange : CFG.btnAdd;
    }

    var set = function (id, value) {
      var el = document.getElementById(id);
      if (!el) return false;
      var was = el.value;
      el.value = value == null ? '' : String(value);
      return was !== el.value;
    };

    set('billing_address_1', f.line1);
    set('billing_city', f.city);
    set('billing_state', f.state);

    if (set('billing_country', f.country)) {
      document.getElementById('billing_country')
        .dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  /* One call for both rows, so a tap can never update one and forget the
     other. */
  function paintChosen() {
    paintRow();
    paintCheckout();
  }

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
    /* The rail's arrows. Delegated on document, like everything else here,
       because cart.js replaces the whole of #cartInner on every quantity
       change and a handler bound to the button would go with it.

       One card plus its gap times the whole cards on screen, so a press moves
       a predictable number of products rather than an arbitrary number of
       pixels, and the half card stays a half card at the far edge. */
    var arrow = e.target.closest('[data-cpg-rail]');
    if (arrow) {
      var railEl = arrow.parentNode.querySelector('.cpg-rail');
      if (railEl) {
        /* A SCREENFUL LESS A SLIVER, computed from the rail's own visible
           width. It deliberately does NOT measure a card: this page's whole
           sizing rule is that nothing in JavaScript decides how big anything
           is -- see this file's header, and the two tests that forbid the
           element-measuring APIs in here by name -- because a script that
           sizes the layout makes the first paint wrong on every phone.

           Nothing is being sized here; this is how far to scroll when someone
           presses a button, which cannot affect a paint that has already
           happened. Keeping it off card geometry means it is also right while
           the rail is mid-animation, when a card's measured width is whatever
           the transition is part-way through. The sliver of overlap leaves the
           card you were looking at just in view, so the eye keeps its place. */
        railEl.scrollBy({
          left: railEl.clientWidth * 0.86 * Number(arrow.dataset.cpgRail),
          behavior: 'smooth'
        });
      }
      return;
    }
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
    /* Straight back to the saved list, with no round trip: `state` already
       holds the addresses the sheet was opened with, which is what listHTML()
       draws from. Re-fetching would put a skeleton on screen to show a list
       that never left memory. */
    if (e.target.closest('[data-cpg-back]')) { paint(listHTML(), true); return; }
    if (e.target.closest('[data-cpg-xin]')) { close(); return; }

    /* One of the guest's session addresses. Its own endpoint, taking its own
       handle: the server resolves it against this session's list and opens no
       table, and the handle never goes near the route that takes an id. Read
       FIRST, so a guest row can never fall through to either branch below. */
    var gpick = e.target.closest('[data-cpg-gpick]');
    if (gpick) {
      busy = true;
      try {
        state = await call(CFG.chooseGuest + '/' + encodeURIComponent(gpick.getAttribute('data-cpg-gpick')) + '/choose', {});
        paintChosen();
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
        paintChosen();
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
        paintChosen();
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
