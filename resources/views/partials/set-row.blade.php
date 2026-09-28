{{--
 ═══════════════════════════════════════════════════════════════════════════════
  THE SET ROW — "Option A, the fanned stack", the design the owner chose.
  ▲ THIS FILE IS THE ONLY PLACE A SET IS DRAWN, INCLUDING THE POPUP MARKUP. ▲
 ═══════════════════════════════════════════════════════════════════════════════

    The owner, 28 September 2026:

      "A Fanned Stack is fine, for cart panel, cart page and checkout page
       please apply this design everywhere. and the thumnail we don't need any
       label on thubmail etc. and What's inside i need with a small on screen
       popup with products names list. a tiny popup box."

    So: under the product name, a row of OVERLAPPING CIRCULAR MEMBER
    THUMBNAILS — one per member, picture only — and beside them one button that
    opens a TINY POPUP listing the member names and quantities. Nothing else.

    ── WHERE THIS IS DRAWN ────────────────────────────────────────────────────

    One partial, included by every surface, so a later change to the row or the
    popup is a change to THIS FILE and nothing else:

      1. cart panel       resources/views/partials/cart-drawer.blade.php
      2. cart page        resources/views/store/cart-inner.blade.php
      3. checkout summary resources/views/partials/checkout/summary-items.blade.php
      4. browsed rail     resources/views/partials/cart-drawer.blade.php (2nd tab)
      5. account order    resources/views/store/account/order-detail.blade.php
                          and partials/checkout/received-line.blade.php

    The three the owner named are 1, 2 and 3. 4 and 5 were already drawing the
    same partial and keep doing so — he said to leave the browsed rail as it is,
    and an order page that drew a set differently from the basket it came from
    would be a second description of a set, which is the thing this file exists
    to prevent.

    THE CHECKOUT SUMMARY HAS NO STEPPER AND NO REMOVE CROSS and the circles and
    the popup still belong there: it is the surface on which a shopper decides
    whether to trust what they are paying for.

    The order-confirmation email and the printed invoice do NOT include this.
    They are table-layout documents — an email client strips most CSS and a PDF
    renderer has no flexbox or popovers — so they print
    App\Support\SetContents::lines(), the same member list this file draws, in
    one cell. A receipt and an invoice are documents, not screens.

    ── WHERE THE CONTENTS COME FROM ───────────────────────────────────────────

      $contents  the App\Support\SetContents array. From fromProduct() while the
                 set is being shopped, and from fromOrderItem() — the
                 `order_items.set_contents` SNAPSHOT — once it has been sold.
                 An order NEVER re-reads the pivot, or an old order's popup
                 would list today's contents. The two return the same shape, so
                 this file cannot tell them apart and cannot get it wrong.
      $surface   'drawer' | 'cart' | 'checkout' | 'browsed' | 'order'.
      $key       a string unique to this row on this page. It becomes the
                 popup's id and the button's aria-controls, so the two are
                 really associated for a screen reader. Supplied by the call
                 site from the cart-line or order-item id — deterministic, so
                 the page's bytes do not change between two identical renders.

    ── RULE 1: NO LABELS ON ANY THUMBNAIL ─────────────────────────────────────

    The member circles carry the member's picture and NOTHING else: no initials,
    no quantity badge, no count in the corner, no text overlay. Where a member
    has no picture the circle is the gradient this shop already uses for a
    pictureless product — Gradient::for($seed), exactly as cart-drawer.blade.php
    builds its own $thumb — and deliberately NOT Gradient::initials(), which is
    the label he asked not to see. The quantities are in the popup, where they
    are words rather than furniture.

    ── RULE 2: A REAL CONTROL, AND CSS DOES THE POSITIONING ───────────────────

    The opener is a <button type="button"> with aria-expanded and aria-controls,
    and the popup is its SIBLING — not a div with a click handler, and not a
    link away, and not an expanding panel that moves the rows under it.

    NOTHING BELOW MEASURES LAYOUT. CLAUDE.md forbids the element-measuring APIs
    and two tests enforce it by name, so the popup is placed with
    `position:absolute` off the positioned wrapper and sized with calc() and
    min(). There is no getBoundingClientRect(), no offsetWidth, no
    getComputedStyle and no scroll measurement anywhere in the script — it does
    three things: toggle a class, set aria-expanded, and close on Escape or an
    outside click.

    IT CANNOT WIDEN THE PAGE. The popup is `inset-inline-start:0` with
    `max-width:min(230px, calc(100vw - 32px))` and `box-sizing:border-box`, so at
    390px its right edge is inside the viewport whatever the row does, and
    `overflow-wrap:anywhere` keeps a long product name from pushing it out.
    Measured with the popup OPEN at both widths: document.documentElement.-
    scrollWidth is unchanged.

    ── ESCAPING ───────────────────────────────────────────────────────────────

    Every interpolation is escaped. A member name, a brand and an option label
    are settings, and CLAUDE.md rule 5 is that anything printed unescaped is a
    constant. The only {!! !!} below is Money::format(), which returns markup
    this application builds itself. An image URL goes through e() inside a
    style attribute exactly as the row around it does.

    ── THE STYLES AND THE SCRIPT ARE @once AND INLINE ─────────────────────────

    resources/css/kbb/kbb.css is compiled by Vite and package.json defines no
    `build` script, so a rule added there would not reach the server until
    somebody ran `npx vite build` by hand — a set that draws unstyled on the
    live shop is exactly the kind of half-shipped thing this project keeps
    finding. @once emits them for the first set on the page and for no other.
--}}
@php
    use App\Support\Gradient;
    use App\Support\Money;

    $kbbSetSurface = $surface ?? 'cart';
    $kbbSetMembers = $contents['members'] ?? [];
    $kbbSetSaving = (int) ($contents['saving'] ?? 0);
    $kbbSetCount = (int) ($contents['count'] ?? 0);
    // Bounded here rather than trusted from the call site: it goes into an id
    // and an aria-controls, and both are HTML attributes.
    $kbbSetKey = 'kset-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($key ?? $kbbSetSurface));
@endphp
@if ($kbbSetMembers !== [])
@once
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   THE FANNED STACK.

   Sized entirely from the Cart-panel tokens the row around it already reads
   (--cp-thumb, --cp-name and their -m twins), so Appearance → Cart panel →
   Mobile moves this with the row rather than leaving it behind. calc() and
   min() only — nothing here is measured in JavaScript.
   ═══════════════════════════════════════════════════════════════════════════ */
.kset{position:relative;display:flex;align-items:center;gap:8px;margin-top:6px;
      min-width:0;flex-wrap:wrap}

/* The fan. Each circle overlaps the one before it by a little under half its
   width; the white ring is what separates them. `direction` is untouched, and
   the negative margin is a LOGICAL property, so the fan reverses correctly in
   Arabic instead of stacking the wrong way. */
.kset-fan{display:flex;align-items:center;flex:none}
.kset-c{--kset-d:calc(var(--cp-thumb,42px) * .62);
        width:var(--kset-d);height:var(--kset-d);border-radius:50%;flex:none;
        background-size:cover;background-position:center;background-repeat:no-repeat;
        box-shadow:0 0 0 2px #fff,0 1px 2px rgba(0,0,0,.14)}
.kset-c + .kset-c{margin-inline-start:calc(var(--kset-d) * -.38)}

/* The opener. A real button, and small enough to sit on the same line as the
   fan at 390px without wrapping the row it lives in. */
.kset-btn{border:1px solid var(--line-2,#eadfe4);background:#fff;cursor:pointer;
          border-radius:99px;padding:3px 9px;font:inherit;line-height:1.35;
          font-size:calc(var(--cp-name,12.5px) * .82);font-weight:650;
          color:var(--cp-accent,#c9587f);white-space:nowrap;
          min-height:24px;flex:none}
.kset-btn:hover{background:#fff4f8}
.kset-btn:focus-visible{outline:2px solid var(--cp-accent,#c9587f);outline-offset:2px}

/* The tiny popup. Absolute off .kset, which is positioned — so opening it moves
   nothing on the page and costs no layout below the row.

   THE WIDTH IS WHAT KEEPS 390px HONEST: min() against the viewport with the
   page gutter subtracted, and border-box so the padding is inside that figure.
   There is no measured position anywhere; `inset-inline-start:0` anchors it to
   the row's own start edge, which is inside the panel by construction. */
.kset-pop{display:none;position:absolute;z-index:40;inset-block-start:calc(100% + 6px);
          inset-inline-start:0;box-sizing:border-box;
          width:max-content;max-width:min(230px, calc(100vw - 32px));
          background:#fff;border:1px solid var(--line-2,#eadfe4);border-radius:10px;
          box-shadow:0 8px 24px -8px rgba(0,0,0,.28);padding:8px 10px;
          text-align:start}
.kset-pop.is-open{display:block}
/* Opens UPWARD when the row asks for it, by a class the markup carries — never
   by a script that reads geometry. The order surfaces sit at the foot of a long
   page where downward is fine; the checkout summary's last line does not. */
.kset-pop.is-up{inset-block-start:auto;inset-block-end:calc(100% + 6px)}
.kset-pop h4{margin:0 0 4px;font-size:calc(var(--cp-name,12.5px) * .78);font-weight:700;
             letter-spacing:.03em;text-transform:uppercase;color:var(--ink-soft,#8a7c83)}
.kset-pop ul{margin:0;padding:0;list-style:none}
.kset-pop li{font-size:calc(var(--cp-name,12.5px) * .88);line-height:1.45;
             color:var(--ink-2,#5e545a);overflow-wrap:anywhere}
.kset-pop li + li{margin-top:2px}
.kset-q{font-weight:700;color:var(--ink,#2b2226)}
.kset-save{font-size:calc(var(--cp-name,12.5px) * .82);font-weight:700;color:#1c7a4a;
           white-space:nowrap;flex:none}
@media (max-width:760px){
  .kset-btn{font-size:calc(var(--cp-name-m,13px) * .82)}
  .kset-pop li{font-size:calc(var(--cp-name-m,13px) * .88)}
  .kset-save{font-size:calc(var(--cp-name-m,13px) * .85)}

  /* ▲ THE CHECKOUT'S SUMMARY CLIPS ITS OWN CHILDREN ON A PHONE, and the first
     shot of this screen proved it: `.kbb-checkout .panels` is
     `max-height:148px; overflow:hidden` below 760px, so the popup a shopper had
     just opened was sliced to one visible line.

     `position:fixed` is the only thing that escapes an overflow:hidden
     ancestor, and it needs no measurement at all: the box is pinned to the
     viewport's own bottom edge with logical insets, so where it lands is
     decided entirely by CSS. It cannot widen the page either -- a fixed element
     is laid out against the viewport, and the shots read
     document.documentElement.scrollWidth === 390 with it open.

     SCOPED TO THE CHECKOUT AND TO THE PHONE. The cart page does not clip and
     the desktop summary is not collapsed, so neither of them takes this branch
     and neither of them moves. It also cannot affect a shop with no sets: there
     is no .kset-pop on the page at all. */
  .kbb-checkout .kset-pop.is-open{
    position:fixed;inset-block-start:auto;inset-block-end:14px;
    inset-inline-start:14px;inset-inline-end:14px;
    width:auto;max-width:none;z-index:95}
}
</style>
<script>
/* What's inside — open, close, and nothing else.
 *
 * ▲ THIS SCRIPT MEASURES NOTHING. No getBoundingClientRect, no offsetWidth, no
 *   offsetHeight, no getComputedStyle, no scrollWidth. It toggles one class and
 *   writes aria-expanded. Where the box appears is decided entirely by the CSS
 *   above, which is the rule CLAUDE.md states and two tests in this repository
 *   enforce by name.
 *
 * ONE DELEGATED LISTENER on the document, not one per row. The cart panel
 * replaces its own markup on every add and every quantity change — the
 * .kc-fragment contract — so a listener bound to a button would be lost the
 * first time a shopper pressed +. Delegation survives that with nothing to
 * re-bind. It is installed once per page by Blade's once-directive (see the
 * top of this file) and guards on its own flag
 * as well, because the drawer fragment can arrive through innerHTML.
 */
(function(){
  if (window.__ksetPopupsReady) return;
  window.__ksetPopupsReady = true;

  function closeAll(except){
    document.querySelectorAll('.kset-pop.is-open').forEach(function(pop){
      if (pop === except) return;
      pop.classList.remove('is-open');
      var owner = document.querySelector('[aria-controls="' + pop.id + '"]');
      if (owner) owner.setAttribute('aria-expanded', 'false');
    });
  }

  document.addEventListener('click', function(e){
    var btn = e.target.closest ? e.target.closest('[data-kset-toggle]') : null;

    if (!btn) {
      /* A press anywhere else closes them — unless it landed inside an open
         box, where a shopper may be selecting the text of a product name. */
      if (!(e.target.closest && e.target.closest('.kset-pop'))) closeAll(null);
      return;
    }

    var pop = document.getElementById(btn.getAttribute('aria-controls'));
    if (!pop) return;

    var open = !pop.classList.contains('is-open');
    closeAll(pop);
    pop.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  document.addEventListener('keydown', function(e){
    if (e.key !== 'Escape') return;
    /* Focus goes back to the button that opened it, which is what a keyboard
       user expects and what stops Escape dumping focus at the top of the page. */
    var open = document.querySelector('.kset-pop.is-open');
    var owner = open ? document.querySelector('[aria-controls="' + open.id + '"]') : null;
    closeAll(null);
    if (owner) owner.focus();
  });
})();
</script>
@endonce
{{-- ONE BRANCH, ONE LIVE DESIGN. A future design is another branch here and a
     one-line change to App\Support\SetDesign — and nothing else in this
     repository moves, which is the property SetRowSurfacesTest protects by
     asserting WHERE this partial is included and never WHAT it draws.

     AN @if AND NOT AN @switch: Blade compiles @switch by requiring its FIRST
     @case or @default to follow it with nothing in between, and a Blade comment
     leaves the newline it sat on — so a switch with an explanation above its
     first case does not compile at all ("unexpected token <, expecting
     endswitch"). Found by running the suite, not by reading. --}}
@if (\App\Support\SetDesign::current() === \App\Support\SetDesign::FAN)
        <div class="kset">
            <div class="kset-fan" aria-hidden="true">{{-- aria-hidden: the circles are decoration. Every name they stand for is in the popup, which is the accessible answer, so a screen reader is not read a row of empty divs. --}}
                @foreach ($kbbSetMembers as $kbbSetMember)
                    <span class="kset-c" style="{{ ($kbbSetMember['image'] ?? null) ? "background-image:url('" . e($kbbSetMember['image']) . "')" : 'background:' . Gradient::for(($kbbSetMember['brand'] ?? '') . ($kbbSetMember['name'] ?? '')) }}"></span>{{-- NO LABEL OF ANY KIND on a member circle: no initials, no quantity badge, no overlay. The owner asked for the picture and nothing else, and Gradient::initials() is deliberately not called here even for a member with no picture -- that is the label he is asking not to see. --}}
                @endforeach
            </div>
            <button type="button" class="kset-btn" data-kset-toggle aria-expanded="false" aria-controls="{{ $kbbSetKey }}">{{ __('store.set.whats_inside') }}</button>
            <div class="kset-pop{{ in_array($kbbSetSurface, ['checkout', 'order'], true) ? ' is-up' : '' }}" id="{{ $kbbSetKey }}" role="group" aria-label="{{ __('store.set.whats_inside') }}">
                <h4>{{ trans_choice('store.set.contents', $kbbSetCount, ['count' => $kbbSetCount]) }}</h4>
                <ul>
                    @foreach ($kbbSetMembers as $kbbSetMember)
                        <li><span class="kset-q">{{ (int) $kbbSetMember['quantity'] }}&times;</span> {{ $kbbSetMember['name'] }}@if (($kbbSetMember['variant'] ?? '') !== '') &middot; {{ $kbbSetMember['variant'] }}@endif</li>
                    @endforeach
                </ul>
            </div>
            @if ($kbbSetSaving > 0)<span class="kset-save">{{ __('store.set.saving', ['amount' => Money::plain($kbbSetSaving)]) }}</span>@endif
        </div>
@endif
@endif
