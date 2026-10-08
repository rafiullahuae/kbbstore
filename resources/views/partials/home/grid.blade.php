{{--
    A skinned product grid — the homepage rails, a curated collection, a
    concern page and the wishlist.

    ── THIS FILE NO LONGER DRAWS A CARD ──────────────────────────────── Lane PG

    It used to carry a complete second copy of the product tile: the same card
    components/product-grid.blade.php drew, with the same class names, and a
    different set of bugs fixed in each. Everything the owner reported about the
    grid came from that split —

      * it printed five empty stars and `(0)` for every product with no
        reviews, unconditionally. That is on every tile of
        docs/OWNER-GRID-REFERENCE.webp, and it is why he asked for it to stop.
      * it priced from `(int) $p->price`, so a VARIABLE parent — whose money
        lives on its variations and whose own column is NULL — advertised AED 0.
      * it bound `data-kbb-add` on every tile including those variable parents,
        so the basket took a line at AED 0 that the checkout would then collect.
      * its NEW badge was gated on `! $p->review_count`, i.e. "nobody has
        reviewed it yet", which is not what new means.

    All four were already fixed in the other copy. The card is
    components/product-card.blade.php now and there is exactly one of it.

    ── WHAT THIS FILE STILL DECIDES ────────────────────────────────────────────

        $items      the collection
        $skin       one of the 28 in kbb-grid-skins.css
        $catLabel   the eyebrow line, the SAME string on every tile of the rail
                    ("SKINCARE SETS" in the owner's reference). Passed to the
                    card, which never reads `categories` itself — see the
                    contract note in that file for the query this saves.
        $rank       the bestsellers rail's #1, #2… numbering

    WHAT IT NEEDS LOADED: `brand`, for the card. Its callers are named in
    tests/Feature/ComponentLoadContractTest.php.
--}}
{{-- `GridSkins::resolve()` AND NOT `$skin ?? 'classic'`.        Lane PG2

     Two things were wrong with the literal. It named the OLD shipped default,
     so a rail whose own skin is not set would have kept drawing the previous
     card while every other grid on the site moved — "everywhere" with one page
     left out. And it was unvalidated: a `grid_skin` row holding a name no skin
     answers to rendered a grid with no card styling at all, where resolve()
     falls back rather than rendering the bare markup.

     It is the same expression store/product.blade.php's related rail already
     used, so the two agree by construction now instead of by coincidence.

     (2.60.370) $trackLabel makes this grid a carousel track for ymal.js — the
     homepage's Big savings bundles. Absent everywhere else, so every other
     grid prints exactly what it did. --}}
@php
    // (Lane LZ) How many of the first cards are in the first viewport, so not
    // lazy: the caller's own count (`aboveFold`, the homepage's first section),
    // or the shop's first row when the caller says nothing sits above the grid.
    $kbbAbove = (int) ($aboveFold ?? (($eagerFirst ?? false) ? app(\App\Services\SiteLayout::class)->aboveFoldCards() : 0));
@endphp
<div class="kbb-pgrid{{ isset($trackLabel) ? ' bndl-track' : '' }}" data-skin="{{ \App\Support\GridSkins::resolve($skin ?? null) }}"@isset($trackLabel) id="bndl-track" data-ymal-track tabindex="0" role="region" aria-label="{{ $trackLabel }}"@endisset>
    @foreach ($items as $i => $p)
        <x-product-card :product="$p" :eager="($eagerFirst ?? false) && $loop->first" :above="$loop->index < $kbbAbove" :cat-label="$catLabel ?? null" :rank="($rank ?? false) ? $i + 1 : null" />
    @endforeach
</div>
