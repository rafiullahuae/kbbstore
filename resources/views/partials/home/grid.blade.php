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
<div class="kbb-pgrid" data-skin="{{ $skin ?? 'classic' }}">
    @foreach ($items as $i => $p)
        <x-product-card :product="$p" :cat-label="$catLabel ?? null" :rank="($rank ?? false) ? $i + 1 : null" />
    @endforeach
</div>
