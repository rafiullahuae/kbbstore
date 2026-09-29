{{--
    Appearance → Set — the live preview's document.            (Lane SA, SA3)

    Rendered by Admin\SetAppearanceApiController::preview() into an iframe on
    the screen, from the values the owner has TYPED and not saved.

    ── IT INCLUDES THE REAL PARTIAL ───────────────────────────────────────────

    `partials.set-row` is the same file the cart drawer, the cart page, the
    checkout summary, the browsed rail and an order's detail page include, with
    the same `$contents` shape. A second copy of that markup here would disagree
    with the shop the first time either was touched — the fault
    HomepageLayouts::summaries() shipped — so this file draws no circles and no
    popup of its own. It supplies the SURROUNDINGS and nothing else.

    ── AND THE SURROUNDINGS ARE THE REAL ONES TOO ─────────────────────────────

    The box is sized in percentages of `--cp-thumb` and `--cp-name`, which are
    App\Services\CartPanel's tokens and come from Appearance → Cart panel. So
    the wrapper carries CartPanel::cssVariables() verbatim, exactly as
    partials/drawers.blade.php does. Without them the preview would resolve the
    fallbacks (42px and 12.5px) and a shop whose cart thumbnail is 56px would be
    shown a smaller fan than it actually has.

    ── TWO ROWS IN THE BASKET, AND THAT IS THE POINT OF THE FIRST BLOCK ───────

    The owner, 29 September: *"the set row padding etc is disturbing the whole
    cart all rows, these controls must be apply only and only on Set rows, not
    on other rows!!!!"* He was right, and the five `ci_*` controls are now
    emitted at `.kbb-cartpage .items .ci.ci-set`.

    A preview that drew ONE row could not have shown him that, and could not
    show him it is fixed. So the first block is a real `.kbb-cartpage .items`
    holding a SET line and an ORDINARY line, and the five sliders visibly move
    the first and leave the second where it is. The `.ci` declarations that
    stand UNDER the owner's — the ones in resources/css/kbb/kbb-cart.css, a
    compiled Vite sheet this document does not load — are copied into the
    stylesheet below and cited there, so the ordinary row sits at the shipped
    padding rather than at no padding at all, which is what makes the
    difference between the two rows readable as a difference.

    ── THE POPUP REALLY OPENS ─────────────────────────────────────────────────

    The partial ships its own script, guarded by `window.__ksetPopupsReady`.
    This is a fresh window, so the guard passes and the button works: the owner
    presses "What's inside" in the frame and sees the box he just sized, at the
    width the frame is set to. Nothing here simulates an open state with a class
    or a forced display, because a simulated popup is one that can be right in
    the preview and wrong on the shop.

    ── AND THE THIRD BLOCK IS THE PRODUCT PAGE'S LIST ─────────────────────────

    Sixty of this screen's controls are the "What is in this set" panel in the
    buy column — its fill, its radius, its four paddings, the overhang, the ring
    and the drop under each chip — and until Lane SA2 the preview drew none of
    them. It includes `partials.set-contents-panel`, the same file
    store/product.blade.php includes, handed the SAME `$setContents` the two
    rows above are drawn from, so the preview cannot describe a set differently
    from the page.

    IT IS WRAPPED IN A COLUMN OF THE BUY COLUMN'S OWN WIDTH, because that is the
    one thing about this list that is not obvious from the controls: `.buybox`
    is 346px at a 390px viewport and 582px at 1280, so the list is narrow at
    every width and a preview drawn at the frame's full width would show the
    owner a measure the shop never gives it. The frame's Phone and Desktop
    buttons still drive the media query; this only caps the column.

    ── TWO SURFACES FOR THE BOX, AND THE SECOND ONE OPENS UPWARD ──────────────

    `surface` decides that: the cart surfaces hang the popup below the button
    and the checkout and order surfaces hang it above, because the checkout's
    last line has nothing below it. Both are drawn so that the owner sizing the
    popup sees both directions rather than discovering the second one on a live
    checkout.

    ── IT HAS A DIRECTION, AND IT CAN BE SEEN BOTH WAYS ───────────────────────

    This file used to open with `lang="en" dir="ltr"` written in, and its
    controller had no locale handling at all — so the one rendering most likely
    to be wrong, the mirrored Arabic one, was the one rendering the owner could
    never look at. The panel hangs its photographs off its LEADING edge and pads
    its four sides separately for exactly that reason, and a leading edge is a
    different edge in Arabic.

    So `$lang` and `$dir` are handed in, the controller renders the whole view
    inside that locale so every __() string in both partials is the string an
    Arabic shopper reads, and the screen has an English/العربية pair beside the
    width buttons.

    `$dir` is App\Support\Locale::direction()'s answer and NOT the language's
    natural one, which is a deliberate difference: this shop can serve Arabic
    words in a left-to-right layout while the mirrored stylesheet is being
    finished, and a preview that mirrored anyway would be showing the owner a
    page his shop does not serve. When the two disagree the strip at the top of
    this document says so, and names the switch.

    ── ESCAPING ───────────────────────────────────────────────────────────────

    The only {!! !!} below are the two stylesheets this application builds
    itself: SetAppearance::css(), whose every byte is a literal in that class or
    an integer out of a clamped range or a colour that has been through
    Color::isValidHex(), and CartPanel::cssVariables(), which is in a style
    ATTRIBUTE and goes through e() exactly as drawers.blade.php does it.

    The live overlay at the bottom is neither. It is a string the SCREEN builds
    and posts in, and this document takes it as hostile: it is filtered through
    an allowlist of the characters a declaration block can be made of before it
    is ever assigned, it is assigned to .textContent of a style element and so
    is never parsed as markup, and the frame is sandboxed without
    allow-same-origin, so the window it arrives in owns nothing but itself.
--}}
@php
    /* Defaults, so this view still renders if it is ever called without them —
       and so the two attributes that used to be written in are one variable. */
    $lang = $lang ?? 'en';
    $dir = $dir ?? 'ltr';
    $naturalDir = $naturalDir ?? $dir;
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set preview</title>
<style>
:root{--ink:#2A2228;--ink-2:#5E545A;--ink-soft:#8a7c83;--line:rgba(42,34,40,.10);
      --line-2:rgba(42,34,40,.06);--cp-accent:#c9587f}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{font-family:"Poppins",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
     color:var(--ink);background:#fff;line-height:1.5;font-size:14px;-webkit-font-smoothing:antialiased}
button{font-family:inherit;cursor:pointer;border:none;background:none;color:inherit}
/* The cart line around the box, drawn plainly: the preview is about the set
   box, and a faithful copy of the whole basket row would be a second copy of
   another lane's markup for no gain. */
.sap-line{padding:14px 16px;border-bottom:1px solid var(--line)}
.sap-line:last-child{border-bottom:0}
.sap-nm{font-size:13px;font-weight:600;line-height:1.35;color:var(--ink)}
.sap-tag{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
         color:var(--ink-soft);margin-bottom:8px}
.sap-mark{font-size:9.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
          border-radius:999px;padding:1px 7px;margin-inline-start:7px;white-space:nowrap}
.sap-mark.is-set{background:#FDE8EF;color:#9D2B51}
.sap-mark.is-plain{background:rgba(42,34,40,.06);color:var(--ink-soft)}
.sap-dirnote{background:#FEF3C7;color:#7C2D12;font-size:11.5px;line-height:1.5;
             padding:9px 16px;border-bottom:1px solid #FCD34D}
/* The buy column's real measure, so the list is judged at the width it is
   drawn at. min() and not a media query: one declaration, and it tracks the
   frame at every width rather than at two. */
.sap-buy{inline-size:min(100%,582px);margin-block-start:10px}
:root{--pink-soft:#FFF0F4;--pink-deep:#C13E63;--pink-ink:#9D2B51;--muted:#8a7c83}
/* `.opt-label` belongs to kbb-product.css, which this document does not load.
   The heading's own controls override it from inside `.ksl`; this is the base
   they override, copied from that sheet so the preview starts where the page
   starts. */
.opt-label{font-size:12.5px;font-weight:700;margin-bottom:9px;display:flex;justify-content:space-between}
.opt-label span{font-weight:500;color:var(--muted)}
/* ── THE BASKET ROW'S OWN SHEET, COPIED FROM resources/css/kbb/kbb-cart.css ──
   Same reason as `.opt-label` above and no other: that file is compiled by Vite
   and this document does not load it, so without these the ordinary row would
   have no padding at all and the set row's padding would read as a setting that
   applies to everything — the exact impression the ci_* controls were just
   fixed to stop giving. These are the base; SetAppearance::css() lands on top
   of them at (0,4,0), for the set row only, exactly as on the real cart page. */
.kbb-cartpage .items{background:#fff;border:1px solid var(--line-2);border-radius:13px;overflow:hidden}
.kbb-cartpage .ci{display:flex;gap:12px;align-items:center;padding:11px 14px;border-bottom:1px solid var(--line-2)}
.kbb-cartpage .ci:last-child{border-bottom:none}
.kbb-cartpage .cth{width:56px;height:56px;border-radius:10px;flex:none;display:grid;place-items:center;
                   color:#fff;font-weight:800;font-size:11px;text-align:center;line-height:1.1;
                   background:linear-gradient(135deg,#F7C6D4,#E8919F)}
.kbb-cartpage .cmid{flex:1;min-width:0}
.kbb-cartpage .cbrand{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--pink-ink)}
.kbb-cartpage .cn{font-size:13px;font-weight:500;margin:1px 0 6px;color:var(--ink);line-height:1.3}
.kbb-cartpage .cvar{font-size:11.5px;color:var(--muted);margin:-4px 0 8px}
@media (max-width:600px){
  .kbb-cartpage .ci{padding:10px 12px;gap:11px}
  .kbb-cartpage .cth{width:52px;height:52px}
  .kbb-cartpage .cn{font-size:12.5px;margin:1px 0 5px}
}
</style>
{{-- The owner's own numbers, from what he has typed. Emitted BEFORE the
     partial's @once block, which is where the shop emits it too — see the note
     at the top of partials/set-appearance-css.blade.php for why that order is
     load-bearing rather than tidy. --}}
<style id="kbb-set">{!! $setCss !!}</style>
{{-- And the live overlay, which is empty until the owner touches a slider.
     AFTER #kbb-set and never before it: it re-declares the very same custom
     properties at the very same selectors, so it wins on document order alone
     and needs no !important and no extra class to do it. --}}
<style id="kbb-set-live"></style>
</head>
<body>
@if ($dir !== $naturalDir)
<div class="sap-dirnote">This shop serves Arabic <b>left to right</b>: the mirrored layout
    (<code>language_rtl_enabled</code>) is switched off, so this is what /ar really draws today.
    Turn it on to size the panel against the mirrored rendering.</div>
@endif
<div style="{{ $cartVars }}">
    <div class="sap-line">
        <div class="sap-tag">Cart page &middot; a set line and an ordinary line</div>
        {{-- The real `.kbb-cartpage .items` shape, so the five Set-row sliders
             can be seen landing on the first row and not on the second. --}}
        <div class="kbb-cartpage">
            <div class="items">
                <div class="ci ci-set">
                    <div class="cth">SET</div>
                    <div class="cmid">
                        <div class="cbrand">K-Beauty Bliss</div>
                        <div class="cn">Glass Skin Starter Set<span class="sap-mark is-set">set row</span></div>
                        @include('partials.set-row', ['contents' => $setContents, 'surface' => 'cart', 'key' => 'preview-cart'])
                    </div>
                </div>
                <div class="ci">
                    <div class="cth">COSRX</div>
                    <div class="cmid">
                        <div class="cbrand">COSRX</div>
                        <div class="cn">Advanced Snail 96 Mucin Power Essence<span class="sap-mark is-plain">ordinary row</span></div>
                        <div class="cvar">100ml</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="sap-line">
        <div class="sap-tag">Cart drawer &middot; checkout summary &middot; order &mdash; the popup opens upward</div>
        <div class="sap-nm">Glass Skin Starter Set</div>
        @include('partials.set-row', ['contents' => $setContents, 'surface' => 'checkout', 'key' => 'preview-checkout'])
    </div>
    <div class="sap-line">
        <div class="sap-tag">Product page &middot; the buy column's list</div>
        <div class="sap-buy">
            @include('partials.set-contents-panel', ['kbbSetPreviewContents' => $setContents])
        </div>
    </div>
</div>
@verbatim
<script>
/*
 * The live overlay's receiver.                                     (Lane SA3)
 *
 * The owner asked for a preview that moves as he drags. A request per pixel is
 * not that, and this document already carries the answer: almost everything on
 * the Set screen is a CUSTOM PROPERTY read by the two storefront partials, so
 * the screen can re-declare those properties and the browser resolves them
 * with no server in it at all.
 *
 * IT CANNOT BE DONE FROM THE SCREEN'S SIDE. The frame is sandboxed without
 * allow-same-origin — the parent holds an opaque origin here and
 * `frame.contentDocument` is null — so the parent posts the text in and this
 * script is what assigns it. That is the secure direction as well as the only
 * one: the window that applies the CSS is the window that is going to be
 * affected by it, and nothing outside this file can reach anything else in it.
 *
 * WHAT ARRIVES IS TREATED AS HOSTILE even though it is built two files away
 * out of clamped integers and hexes, because "it is built two files away" is
 * exactly the assumption that stops being true later:
 *
 *   · only a message whose source is this frame's own parent is read at all;
 *   · the payload must be a string, and every character outside the set a
 *     declaration block is made of is dropped before it is used;
 *   · it is assigned to .textContent, so a closing style tag inside it is five
 *     words of stylesheet and never markup.
 */
(function () {
  'use strict';

  var live = document.getElementById('kbb-set-live');

  /* The characters a block of custom-property declarations can be spelled
     with: selectors, at-rules, hex colours, numbers, units and the three kinds
     of bracket. Anything else — a quote, a backslash, an angle bracket — simply
     does not survive. */
  function clean(css) {
    return String(css).replace(/[^-A-Za-z0-9_#.,:;(){}%\s@]/g, '');
  }

  window.addEventListener('message', function (e) {
    if (e.source !== window.parent || !e.data || typeof e.data !== 'object') return;
    if (e.data.kbbSetLive !== 'css' || typeof e.data.css !== 'string') return;
    if (live) live.textContent = clean(e.data.css);
  });

  /* The screen cannot know when srcdoc has finished parsing without watching
     for something, and a load event on the frame fires for reasons that are
     not this. So the document says so itself, once, and the screen answers
     with whatever overlay is current — which is what keeps a drag that
     happened DURING a re-render from being lost. */
  try {
    window.parent.postMessage({ kbbSetLive: 'ready' }, '*');
  } catch (err) { /* no parent to tell: the document is still correct */ }
})();
</script>
@endverbatim
</body>
</html>
