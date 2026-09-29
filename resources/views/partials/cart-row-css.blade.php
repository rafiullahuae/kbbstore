{{--
    Appearance → Cart page → Product rows · spacing and size — THE ONE PLACE
    THOSE SETTINGS REACH THE SHOP.                                  (Lane CR)

    App\Services\ProductStyles is the cautionary tale this project already paid
    for: twenty of its controls reached no storefront page for releases because
    cssVariables() was called only from the admin. Appearance → Cart page had a
    quieter version of the same fault — `row_h`, `row_font`, `row_bold` and
    `qty_size` are `--cpg-…` properties whose only readers live in
    store/cart-squeeze.blade.php, so on the CLASSIC layout, which is what this
    shop ships, those four sliders save and move nothing.

    So this file exists, it is included ONCE, from store/cart.blade.php's own
    @push('styles'), and there is no second path. It is on the cart page and on
    no other page, because every selector inside it begins `.kbb-cartpage`:
    included from layouts/store.blade.php it would be bytes in the head of the
    homepage, of /shop and of every product page for a rule none of them can
    match.

    ── WHERE IT SITS IN THE HEAD, AND WHY THAT IS ENOUGH ──────────────────────

    Inside the same stack as `@vite('resources/css/kbb/kbb-cart.css')` and
    directly after it, so it is emitted after the sheet it overrides. It does
    not have to be: `.kbb-cartpage .items .ci:not(.ci-set)` is (0,4,0) against
    that sheet's `.kbb-cartpage .ci` at (0,2,0) and the squeezed sheet's
    (0,3,0), so the cascade is decided on specificity and never on order. Order
    is belt and braces.

    ── AND IT EMITS NOTHING AT ALL UNTIL A SLIDER MOVES ───────────────────────

    CartPage::rowCss() answers the empty string while every one of its
    twenty-seven values is still what resources/css/kbb/kbb-cart.css draws,
    exactly as SetAppearance::storefrontCss() does and for the same reason:
    restating the defaults would be correct in pixels and WRONG IN BYTES — a new
    <style> element in the head of every cart page at once, which is what
    StorefrontEnglishUnchangedTest exists to notice, for a render that is
    identical.

    ▲ EVERY LINE OF THIS FILE IS ARRANGED TO EMIT NOTHING, AND THAT IS NOT
      COSMETIC. Written the obvious way — the directives each on their own line
      — it would add blank lines to the <head> of the cart page and the walk
      would report them with the whitespace as the whole diff. Two mechanics
      make it zero: PHP eats a newline immediately after `?>`, so a raw-PHP
      block and a conditional that both CLOSE at the end of a line contribute
      nothing; and the opening directive shares its line with the comment above
      it and with the <style> tag it guards, so no newline is left outside the
      branch. This is partials/set-appearance-css.blade.php's shape, followed
      deliberately.

    ── {!! !!} AND NOT {{ }}, WHICH IS A SECURITY DECISION ────────────────────

    This is a STYLESHEET. Blade's escaper turns an apostrophe into `&#39;`, and
    an HTML entity inside a <style> element is handed to the CSS parser as those
    five characters rather than being decoded — so `{{ }}` here would neither
    remove a quote nor keep one out. What makes the string safe is that it
    CANNOT contain anything else: every selector, property and unit in it is a
    literal in App\Services\CartPage::rowBlock(), and every number is an integer
    out of a clamped `range`. There is no colour and no text field among these
    controls at all. Rule 5.

    ── IT COSTS NO QUERY ──────────────────────────────────────────────────────

    CartPage::all() reads the one App\Models\Setting::map() snapshot the header
    and the cart panel have already warmed on this request, and store/cart.-
    blade.php has already resolved the same singleton for its own layout
    question. StorefrontQueryBudgetTest does not move.
--}}@php
    $kbbCartRowCss = app(\App\Services\CartPage::class)->rowCss();
@endphp
@if ($kbbCartRowCss !== '')<style id="kbb-cartrows">{!! $kbbCartRowCss !!}</style>
@endif
