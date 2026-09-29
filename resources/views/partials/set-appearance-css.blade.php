{{--
    Appearance → Set — THE ONE PLACE THE SET BOX'S SETTINGS REACH THE SHOP.
                                                                     (Lane SA)

    App\Services\ProductStyles is the cautionary tale this project already paid
    for: twenty of its controls reached no storefront page for releases because
    cssVariables() was called only from the admin. So this file exists, it is
    included ONCE, and there is no second path.

    ── WHERE THE INTEGRATOR PUTS THE ONE LINE ─────────────────────────────────

    resources/views/layouts/store.blade.php, in the <head>, IMMEDIATELY AFTER
    the `kbb-layout` block — which is to say after @vite, after @stack('styles')
    and after the accent and site-layout blocks:

        @if ($kbbLayoutCss !== '')<style id="kbb-layout">{!! $kbbLayoutCss !!}</style>
        @endif
        @include('partials.set-appearance-css')

    That position is not "somewhere in the head". Both halves of it matter:

      AFTER @stack('styles') and the two blocks above it, so a page sheet that
      declares its own `.kset` rules cannot outrank the owner's numbers.

      BEFORE THE BODY, which is the half that is easy to get wrong. The set
      partial carries its own @once <style> block, and inside it
      `.kbb-checkout .kset-pop.is-open{position:fixed;…max-width:none}` — the
      rule that lets the popup escape the checkout summary's `overflow:hidden`
      on a phone. That rule is (0,3,0). The popup rules below are (0,2,0) and
      (0,3,0), so where they tie the DOCUMENT ORDER decides, and the head is
      before the body. Emitted after the partial instead, the width cap would
      have won over `max-width:none` and sliced the popup back to one visible
      line on the one screen that was already fixed for exactly that once.

    ── AND IT EMITS NOTHING AT ALL UNTIL A SLIDER MOVES ───────────────────────

    SetAppearance::storefrontCss() answers the empty string while every one of
    its settings is at the value the sheet already draws, exactly as
    App\Services\SiteLayout::css() does two blocks above and for the same
    reason: restating the defaults would be correct in pixels and WRONG IN
    BYTES — a new <style> element in the head of every storefront page at once,
    which is what StorefrontEnglishUnchangedTest exists to notice, for a render
    that is identical. A shop that applies this package and touches nothing
    gains not one byte on any page.

    ▲ EVERY LINE OF THIS FILE IS ARRANGED TO EMIT NOTHING, AND THAT IS NOT
      COSMETIC. Written the obvious way — the directives each on their own line
      — this block would add blank lines to the <head> of every storefront page
      and the walk would report all of them, with the whitespace as the whole
      diff. Two mechanics make it zero instead: PHP eats a newline immediately
      after `?>`, so a raw-PHP block and a conditional that both CLOSE at the
      end of a line contribute nothing; and the opening directive shares its
      line with the comment above it and with the <style> tag it guards, so no
      newline is left outside the branch. Rearranging it back costs blank lines
      on every page of the shop. (Lane W1 measured that one; this file follows
      its shape deliberately.)

    ── {!! !!} AND NOT {{ }}, WHICH IS A SECURITY DECISION AND NOT A SHORTCUT ─

    This is a STYLESHEET. Blade's escaper turns an apostrophe into `&#39;`, and
    an HTML entity inside a <style> element is handed to the CSS parser as those
    five characters rather than being decoded — so `{{ }}` here would neither
    remove a quote nor keep one out, and would corrupt a legitimate value on the
    way past. What makes the string safe is that it CANNOT contain anything
    else: every selector, property, unit and piece of punctuation in it is a
    literal in App\Services\SetAppearance::css(), every number is an integer out
    of a clamped `range`, and every colour has been through
    App\Support\Color::isValidHex(). Rule 5, and the class's own docblock says
    so at the two boundaries.

    ── IT COSTS NO QUERY ──────────────────────────────────────────────────────

    Every value is a row of `settings`, read through the one Setting::map()
    snapshot the header and the cart panel have already warmed on this request.
    all() runs once per page here and never per set or per member, so
    StorefrontQueryBudgetTest does not move.
--}}@php
    $kbbSetCss = app(\App\Services\SetAppearance::class)->storefrontCss();
@endphp
@if ($kbbSetCss !== '')<style id="kbb-set">{!! $kbbSetCss !!}</style>
@endif
