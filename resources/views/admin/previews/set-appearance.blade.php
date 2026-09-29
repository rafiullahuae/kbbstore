{{--
    Appearance → Set — the live preview's document.                   (Lane SA)

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

    ── THE POPUP REALLY OPENS ─────────────────────────────────────────────────

    The partial ships its own script, guarded by `window.__ksetPopupsReady`.
    This is a fresh window, so the guard passes and the button works: the owner
    presses "What's inside" in the frame and sees the box he just sized, at the
    width the frame is set to. Nothing here simulates an open state with a class
    or a forced display, because a simulated popup is one that can be right in
    the preview and wrong on the shop.

    ── TWO ROWS, AND THE SECOND ONE OPENS UPWARD ──────────────────────────────

    `surface` decides that: the cart surfaces hang the popup below the button
    and the checkout and order surfaces hang it above, because the checkout's
    last line has nothing below it. Both are drawn so that the owner sizing the
    popup sees both directions rather than discovering the second one on a live
    checkout.

    ── ESCAPING ───────────────────────────────────────────────────────────────

    The only {!! !!} below are the two stylesheets this application builds
    itself: SetAppearance::css(), whose every byte is a literal in that class or
    an integer out of a clamped range or a colour that has been through
    Color::isValidHex(), and CartPanel::cssVariables(), which is in a style
    ATTRIBUTE and goes through e() exactly as drawers.blade.php does it.
--}}
<!doctype html>
<html lang="en" dir="ltr">
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
</style>
{{-- The owner's own numbers, from what he has typed. Emitted BEFORE the
     partial's @once block, which is where the shop emits it too — see the note
     at the top of partials/set-appearance-css.blade.php for why that order is
     load-bearing rather than tidy. --}}
<style id="kbb-set">{!! $setCss !!}</style>
</head>
<body>
<div style="{{ $cartVars }}">
    <div class="sap-line">
        <div class="sap-tag">Cart drawer &middot; cart page</div>
        <div class="sap-nm">Glass Skin Starter Set</div>
        @include('partials.set-row', ['contents' => $setContents, 'surface' => 'cart', 'key' => 'preview-cart'])
    </div>
    <div class="sap-line">
        <div class="sap-tag">Checkout summary &middot; order &mdash; the popup opens upward</div>
        <div class="sap-nm">Glass Skin Starter Set</div>
        @include('partials.set-row', ['contents' => $setContents, 'surface' => 'checkout', 'key' => 'preview-checkout'])
    </div>
</div>
</body>
</html>
