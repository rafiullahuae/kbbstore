{{--
    THE PREVIEW BODY — one design, drawn by the shop's own template. (Lane SF)

    Appearance -> Set contents asks the server for this four times, once per
    design, and drops each answer into an <iframe srcdoc>. What comes back is
    partials/set-contents-panel.blade.php itself: the same file
    store/product.blade.php includes, rendering the same set, through the same
    App\Support\SetContents. A preview that redrew the panel in console CSS
    would be the one thing a preview must never be -- a different picture from
    the page.

    ── WHY AN IFRAME AND NOT A DIV IN THE CONSOLE ────────────────────────────

    Two directions of leakage, both real. The panel ships its own <style> block
    and the console has its own; `h2`, `.sec` and `.eyebrow` are storefront
    selectors that the admin stylesheet also claims, so a panel pasted into
    #content would be drawn in the console's type and not the shop's. And the
    panel's rules would apply to the console around it. A document of its own
    has neither problem, and it is also how the width is set: the frame is
    390px or 1180px wide, so the design's own media queries and auto-fill
    tracks answer the question the owner is actually asking.

    ── THE SHELL BELOW IS THE STOREFRONT'S GROUND AND NOTHING MORE ───────────

    Four custom properties and the type. Every one of them is the value the
    panel's own `var(--x, fallback)` already falls back to, so this shell
    cannot make the preview disagree with the shop: delete it and the colours
    are the same. `.sec`, `.eyebrow` and `h2` are storefront chrome the panel
    does not style itself, given here at the sizes kbb-product.css gives them.

    ── EVERY VALUE IS A CONSTANT ─────────────────────────────────────────────

    Nothing on this page is interpolated from a setting, so there is nothing
    printed unescaped that a settings row could reach. $product and the design
    key arrive from the controller, which took the set id from the database and
    the key from App\Support\SetPanelDesign::DESIGNS.
--}}
<!doctype html>
<html lang="en" dir="{{ $kbbPreviewDir ?? 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--ink:#2A2228;--ink-2:#5E545A;--line:rgba(42,34,40,.10);--line-2:rgba(42,34,40,.06);--surface:#fff}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:#fff;color:var(--ink);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  -webkit-font-smoothing:antialiased}
body{padding:18px 16px 22px}
.sec{min-width:0}
.eyebrow{font-size:11px;letter-spacing:.12em;text-transform:uppercase;font-weight:700;
  color:var(--ink-2);opacity:.75;margin-bottom:6px}
h2{font-size:21px;line-height:1.25;font-weight:700;margin:0 0 8px;color:var(--ink)}
</style>
</head>
<body>
@include('partials.set-contents-panel', ['product' => $product, 'kbbSetDesignOverride' => $kbbSetDesignOverride])
</body>
</html>
