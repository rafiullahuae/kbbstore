{{--
    THE OWNER'S APPEARANCE SETTINGS, FOR ANY DOCUMENT THAT CARRIES ITS OWN <head>.

    ── WHAT THIS IS FOR ─────────────────────────────────────────────────────

    FIVE storefront views do not extend layouts/store.blade.php — store/blog,
    store/post, store/review-wall, store/skin-quiz and store/app each carry
    their own <html>, <head> and inline stylesheet. Anything the layout emits
    has therefore never reached them, and two of the things it emits are
    settings the owner moves from the admin:

      the BRAND COLOUR  never reached ANY of the five. All five hard-code
                        `--pink:#E0567B` on their own `:root`; the skin quiz
                        uses it 12 times and `--pink-deep` 14, the review wall
                        8 and 5. So a shop that changes its brand colour
                        changes /shop/, the home page, the cart and the
                        checkout, and the journal, an article, the review wall
                        and the skin quiz keep the old pink. Four live URLs.

      the SITE WIDTH    reached store/blog and store/post, which each carried
                        their own copy of the layout's block, and neither of
                        the other three. Those two copies are gone; this file
                        is the one writer now.

    ── WHAT IS DELIBERATELY NOT HERE ────────────────────────────────────────

    Not everything the layout puts in its <head> belongs to every document, and
    the four that are left out were each checked rather than skipped:

      Seo::render          already emitted for all five, by their controllers.
      the page wash        its own partial, included beside this one.
      set-appearance-css   styles `.kset-*` only, and no set is rendered on any
                           of the five.
      the account-panel
        webfont            gated on a signed-in visitor AND on the account menu,
                           and none of the five renders the greeting it styles.
      MarketingPixels
        ::addToCart()      a JavaScript helper for an add-to-cart button. There
                           is no add-to-cart button on any of the five.

    THE SELF-HOSTED LATIN WEBFONT IS NOT HERE EITHER, and that is the one real
    gap this file does not close. The layout serves Poppins from this shop
    (App\Support\WebFonts, Lane PERF); all five documents still link
    fonts.googleapis.com, which is a render-blocking third-party stylesheet on
    four pages a shopper reads. It is not converted here because
    WebFonts::POPPINS_FACES carries weights 400, 600, 700 and 800 only, while
    these documents ask for 500 (blog, post, skin quiz) and 300 (skin quiz) —
    so converting them silently drops two weights on pages that use them.
    Adding those faces is an asset change and belongs to the lane that owns the
    font pipeline. docs/BG-STANDALONE-DOCUMENTS.md carries the arithmetic.

    ── ZERO BYTES AT THE SHIPPED SETTINGS ───────────────────────────────────

    Both writers answer the empty string while nothing has been moved, so a
    shop that applies this package gains NOT ONE BYTE on any of the six
    documents that include this. That is rule 1, and it is also why this is
    safe to add to five pages that work today: nothing changes until the owner
    changes something, and then all six change together.

    THE WHITESPACE IS ARRANGED THE WAY partials/page-wash-css.blade.php IS
    ARRANGED and for the same reason: PHP eats the newline after `?>`, so a
    raw-PHP block and a conditional that both CLOSE at the end of a line
    contribute nothing, and this file ends at `@endif` with NO TRAILING
    NEWLINE. Adding one back costs a blank line on forty pages.

    ── WHERE IT GOES IN A DOCUMENT ──────────────────────────────────────────

    LAST, or as near to last as the document allows, and after that document's
    own <style>. The accent rule is `:root` (0,1,0) and so is every one of the
    five documents' own `--pink` declaration, so the two tie on specificity and
    SOURCE ORDER is what decides. Emitted before their stylesheet, this file
    would lose and the owner's colour would still not reach the page.

    {!! !!} rather than {{ }}: this is a stylesheet. Every selector, property
    and piece of punctuation in it is a literal in App\Support\BrandAccent and
    App\Services\SiteLayout; the only things a saved value can influence are a
    colour that has been through Color::isValidHex() and integers clamped to
    their own slider's range.
--}}@php
    $kbbAccentCss = \App\Support\BrandAccent::css();
    $kbbLayoutCss = app(\App\Services\SiteLayout::class)->css();
@endphp
@if ($kbbAccentCss !== '')<style id="kbb-brand-accent">{!! $kbbAccentCss !!}</style>
@endif
@if ($kbbLayoutCss !== '')<style id="kbb-layout">{!! $kbbLayoutCss !!}</style>
@endif