{{--
 ══════════════════════════════════════════════════════════════════════════════
  WHAT THE BOX IS WORTH — one writer, four drawings. (Lane SF)
 ══════════════════════════════════════════════════════════════════════════════

    ▲ THERE IS EXACTLY ONE PLACE IN THIS APPLICATION THAT DECIDES WHETHER A SET
      CLAIMS TO SAVE ANYBODY ANYTHING, AND ON THE PRODUCT PAGE IT IS THIS FILE.

    Four designs draw a set's contents and every one of them ends in the same
    three figures. Copied into four partials they would be four `> 0` guards,
    and the day one of them was edited the shop would have a design that
    advertises a saving on an unpriced set -- which is not a hypothetical: the
    owner's first set, half filled in, printed "Bought separately: AED 806.00 /
    Set price: AED 0.00 / You save AED 806.00" before Lane SP floored it. See
    App\Support\SetContents::fromProduct(), which is where the flooring lives
    and where the argument for it is written out.

    So each design @includes this, and the guard below is asked once.

    ── THE MARKUP IS LANE SP'S, UNCHANGED, PLUS ONE CLASS ────────────────────

    `$kspFoot` is 'row' (or absent) for the three designs that end in a footing
    line, and 'result' for the stack, where these same three figures ARE the
    right-hand side of the equation the chain above it sets up. It adds a class
    and nothing else -- same spans, same order, same strings, same escaping --
    so the two are one drawing with two stylesheets rather than two drawings.
    With $kspFoot absent the class attribute is the literal `ksp-foot` the page
    has always emitted.

    `ksp-was` is struck through by the panel's own CSS. It is the figure the
    saving is measured FROM, so it is printed whether or not there is a saving
    to print: "bought separately AED 806 / set price AED 0" is an owner's
    half-finished set saying so honestly, which is what it should say.
--}}
@php
    use App\Support\Money;
@endphp
<div class="ksp-foot{{ ($kspFoot ?? 'row') === 'result' ? ' is-result' : '' }}">
    <span class="ksp-f ksp-was">{{ __('store.set.page_separately') }} <b>{!! Money::format((int) $kbbSetPage['partsTotal']) !!}</b></span>
    <span class="ksp-f">{{ __('store.set.page_set_price') }} <b>{!! Money::format((int) $kbbSetPage['setPrice']) !!}</b></span>
    @if ($kbbSetPage['saving'] > 0)<span class="ksp-save">{{ __('store.set.saving', ['amount' => Money::plain((int) $kbbSetPage['saving'])]) }}</span>@endif
</div>
