{{--
    Appearance -> Page background: the soft multi-colour wash.        (Lane BG)

    ONE INCLUDE, AND IT EMITS ZERO BYTES UNTIL THE OWNER SAYS YES.
    App\Services\PageWash ships `on` FALSE and css() returns the empty string
    while it is, so a shop that applies this package renders every page
    byte-identical to the one it rendered before -- the same guarantee, made the
    same way, as the brand accent and the site-layout blocks in
    layouts/store.blade.php.

    THE WHITESPACE IS THE WHOLE CARE IN THIS FILE, and the site-layout block in
    that layout carries the long version of why. Written with the directives
    each on their own line this adds blank lines to the <head> of every
    storefront page, and StorefrontEnglishUnchangedTest reports every one of
    them for a change that renders nothing. Two mechanics make it zero instead:
    PHP eats the newline immediately after `?>`, so a raw-PHP block and a
    conditional that both CLOSE at the end of a line contribute nothing; and
    this file ends at `@endif` WITH NO TRAILING NEWLINE, so the include itself
    contributes nothing either. Adding one back costs a blank line on forty
    pages.

    WHY IT IS A PARTIAL AND NOT A BLOCK IN THE LAYOUT, which is where it
    started. FIVE STOREFRONT PAGES DO NOT EXTEND layouts/store.blade.php AT ALL
    -- store/blog, store/post, store/review-wall, store/skin-quiz and store/app
    each carry their own <html>, their own <head> and their own inline
    stylesheet, and none of them loads kbb.css. Measured rather than assumed:
    the journal index rendered BYTE-IDENTICALLY under all four treatments, and
    the contact-sheet builder refused to arrange the row. Anything that rides
    that layout has therefore never reached those five pages -- which is also
    true of the brand accent and the site width, and is reported rather than
    fixed here. The owner asked for "the whole background", so the wash is
    included in each of the six documents instead, and this file is the one copy
    of it.

    {!! !!} rather than {{ }}: this is a stylesheet. Every selector, property,
    unit and piece of punctuation in it is a literal in PageWash; the only
    things a saved value can influence are integers clamped to their own
    slider's range and colours that have been through Color::isValidHex().
--}}@php
    $kbbWashCss = app(\App\Services\PageWash::class)->css();
@endphp
@if ($kbbWashCss !== '')<style id="kbb-page-wash">{!! $kbbWashCss !!}</style>
@endif