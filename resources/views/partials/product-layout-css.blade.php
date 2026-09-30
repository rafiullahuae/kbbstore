{{--
    Appearance → Product page → Layout, as one <style> block.   (Lane PDP2, R4)

    ── IT EMITS NOTHING AT ALL WHILE NOTHING HAS MOVED ────────────────────────

    App\Services\ProductLayout::storefrontCss() answers the EMPTY STRING while
    every one of its thirty values is at the number kbb-product.css already
    draws, and the @if below then emits no element, no attribute and no
    whitespace. That is the whole of "applying this package moves not one pixel"
    on the markup side: the owner asked for THE CONTROLS, not for a new look, so
    StorefrontEnglishUnchangedTest compares this page byte for byte against the
    one that shipped and needs no approved rule to do it.

    Restating today's numbers here instead would be correct in pixels and wrong
    in bytes — the same trap SetAppearance::storefrontCss() carries a note
    about, and this file is deliberately the same shape as
    partials/set-appearance-css.blade.php.

    ── WHY THE INCLUDE IS ON THE END OF THE LINE ABOVE IT ─────────────────────

    In resources/views/store/product.blade.php this is @included on the SAME
    SOURCE LINE as the review stylesheet, inside @push('styles'). On its own
    line the four spaces of indentation and the newline after it would be pushed
    into <head> whatever this file emitted, so the "no bytes" guarantee above
    would be false by two characters on every product page. The same trick, for
    the same reason, is already used a few hundred lines further down that
    template.

    ── IT IS A STYLESHEET, WHICH DECIDES HOW THE VALUE IS PRINTED ─────────────

    {!! !!} and not {{ }}. Blade's escaper turns an apostrophe into `&#39;`, and
    an HTML entity inside a <style> element is handed to the CSS parser as those
    five characters rather than being decoded — so escaping here would neither
    remove a quote nor keep one out, and would corrupt a legitimate value on the
    way past. What makes the string safe is that it CANNOT contain anything
    else: every selector, property and unit in it is a literal in
    ProductLayout::css(), every number is an integer out of a clamped `range`,
    and the three weights go through ProductLayout::weight(), which returns one
    of five digits or the shipped default. There is no text field on that screen
    and its POLICY docblock says there must not be. Rule 5.

    ── AND IT COSTS NO QUERY ──────────────────────────────────────────────────

    Every value is a row of `settings`, read through the one Setting::map()
    snapshot the header has already warmed on this request. all() runs once per
    page, so StorefrontQueryBudgetTest does not move.
--}}@php
    $kbbPdpLayoutCss = app(\App\Services\ProductLayout::class)->storefrontCss();
@endphp@if ($kbbPdpLayoutCss !== '')<style id="kbb-pdp-layout">{!! $kbbPdpLayoutCss !!}</style>@endif