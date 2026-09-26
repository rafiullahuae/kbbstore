<?php

declare(strict_types=1);

use App\Services\SiteLayout;

/**
 * The column arithmetic, in three places, agreeing.                   Lane W1
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────────
 *
 * The number of product columns a row holds is computed THREE TIMES on this
 * shop, by three different things, and all three have to agree or one of them
 * is lying to somebody:
 *
 *   1. resources/css/kbb/kbb.css   the browser, from the one
 *                                  `repeat(auto-fill, minmax(min(…), 1fr))`
 *                                  rule. This is the truth.
 *   2. the admin preview           resources/views/admin/partials/
 *                                  site-layout-screen.blade.php, which shows the
 *                                  owner the count at seventeen screen widths as
 *                                  he moves a slider.
 *   3. this file                   the same arithmetic in PHP, so the other two
 *                                  can be checked against each other without a
 *                                  browser.
 *
 * (2) is the one that can lie without anybody noticing: it is a copy, the
 * console does not load the storefront stylesheet, and a preview that disagrees
 * with the page is worse than no preview. So the formula is written once here,
 * the expected counts below are the ones REAL CHROMIUM produced (see
 * docs/W1-SITE-WIDTH.md for the run), and the screen's copy is pinned against
 * the same numbers.
 *
 * ── THE MEASURED TRUTH THESE NUMBERS COME FROM ──────────────────────────────
 *
 * tests/browser/lane-w1-width-sweep.mjs, driven against a seeded shop in real
 * Chromium at all seventeen widths, reading each grid's computed
 * `grid-template-columns` — the resolved track list, not a breakpoint anybody
 * guessed at. The rows below are that run's rail and shop counts.
 */

/** The seventeen widths this lane's evidence is measured at. */
const W1_WIDTHS = [320, 360, 390, 414, 480, 600, 768, 834, 1024, 1180, 1280, 1366, 1440, 1536, 1680, 1920, 2560];

/**
 * `repeat(auto-fill, minmax(min(100%, floorShare, max(tile, capShare)), 1fr))`,
 * in PHP. Every term is the same term kbb.css's one grid rule uses.
 */
function w1Columns(float $row, float $tile, float $gap, int $floor = 2, int $cap = 8): int
{
    if ($row <= 0) {
        return 0;
    }

    $track = min(
        $row,
        ($row - ($floor - 1) * $gap) / $floor,
        max($tile, ($row - ($cap - 1) * $gap) / $cap),
    );

    return max(1, min((int) floor(($row + $gap) / ($track + $gap)), $cap));
}

/** The page container: min(screen, site width) less a gutter each side. */
function w1Container(int $screen, float $max = 1680, float $lo = 22, float $hi = 22): float
{
    $gutter = max($lo, min($screen * 0.022, max($lo, $hi)));

    return min($screen, $max) - 2 * $gutter;
}

/** The homepage section card's row, which is where the rails sit. */
function w1RailRow(int $screen, float $max = 1680): float
{
    $outer = min($screen - 24, $max);

    return max(0, $outer - 2 * max(18, min($screen * 0.02, 28)));
}

/** The /shop listing's row: the container, less the filter rail above 900px. */
function w1ShopRow(int $screen, float $max = 1680): float
{
    $row = w1Container($screen, $max);

    if ($screen >= 901) {
        $row -= (250 + 28);
    }

    return max(0, $row);
}

it('reproduces the column count real Chromium rendered, at all seventeen widths', function () {
    /*
     * THE OWNER'S REQUEST, AS A TABLE. "on 1680px the grid products will show 1
     * column extra, and in low, one less and so on."
     *
     * Left column: the homepage rails, a category, the wishlist, a brand page
     * and the [kbb_products] shortcode — all `.kbb-pgrid`, tile minimum 260px,
     * gap 16px. Right column: the /shop listing — `#grid`, tile minimum 220px,
     * gap 18px above 680 and 12px below, and a 250px filter rail beside it above
     * 900px.
     *
     * Both reach FIVE at 1680 where they showed four, and both are unchanged at
     * 390 and 1280 — the two widths this project screenshots.
     *
     * MUTATION: change w1Columns' `floor(...)` to `ceil(...)` and this is red at
     * eleven of the seventeen widths. Change the shop tile to 260 and it is red
     * at 1280, which is the case that found the sidebar problem in the first
     * place.
     */
    $expected = [
        //  screen => [rails, shop]
        320 => [2, 2],
        360 => [2, 2],
        390 => [2, 2],
        414 => [2, 2],
        480 => [2, 2],
        600 => [2, 2],
        768 => [2, 3],
        834 => [2, 3],
        1024 => [3, 3],
        1180 => [4, 3],
        1280 => [4, 4],
        1366 => [4, 4],
        1440 => [4, 4],
        1536 => [5, 5],
        1680 => [5, 5],
        1920 => [5, 5],
        2560 => [5, 5],
    ];

    expect(array_keys($expected))->toBe(W1_WIDTHS);

    foreach ($expected as $screen => [$rails, $shop]) {
        expect(w1Columns(w1RailRow($screen), 260, 16))
            ->toBe($rails, "rails at {$screen}px");

        expect(w1Columns(w1ShopRow($screen), 220, $screen <= 680 ? 12 : 18))
            ->toBe($shop, "shop at {$screen}px");
    }
});

it('gains exactly one column at 1680 and never loses one as the screen grows', function () {
    /*
     * THE TWO HALVES OF THE OWNER'S SENTENCE, asserted as properties rather than
     * as a table — so they hold for any tile he sets, not only for the shipped
     * one.
     *
     * The second half is the defect the first draft of this lane shipped and
     * measured its way out of. A tile minimum written in `vw` — `calc(100px +
     * 10vw)` — reproduces today's counts better in the middle of the range and
     * then FALLS from five columns to four between 1680 and 2560, because the
     * viewport keeps growing while the container is capped at --site-max. A wider
     * screen showing FEWER products is precisely the thing being fixed.
     *
     * MUTATION: put a `vw` term back into the tile (multiply it by
     * $screen / 1680, say) and the monotonic case below is red at 1920.
     */
    expect(w1Columns(w1RailRow(1680), 260, 16))
        ->toBe(w1Columns(w1RailRow(1280), 260, 16) + 1);

    expect(w1Columns(w1ShopRow(1680), 220, 18))
        ->toBe(w1Columns(w1ShopRow(1280), 220, 18) + 1);

    $previousRails = 0;
    $previousShop = 0;

    foreach (W1_WIDTHS as $screen) {
        $rails = w1Columns(w1RailRow($screen), 260, 16);
        $shop = w1Columns(w1ShopRow($screen), 220, $screen <= 680 ? 12 : 18);

        expect($rails)->toBeGreaterThanOrEqual($previousRails, "rails fell at {$screen}px");
        expect($shop)->toBeGreaterThanOrEqual($previousShop, "shop fell at {$screen}px");

        $previousRails = $rails;
        $previousShop = $shop;
    }
});

it('holds the column floor on the narrowest phone, where the tile does not fit twice', function () {
    /*
     * --kbb-cols-floor, and the reason the `min()` has three terms rather than
     * two. At 320px the rail's row is 296px and the tile minimum is 260px: one
     * tile plus a 16px gap plus another tile is 536px, so a tile-driven answer
     * alone is ONE column — full-width cards on the narrowest phone, which is a
     * change nobody asked for. The floor term caps the track at a
     * floor-th of the row, so two always fit.
     *
     * MUTATION: drop the floorShare term from w1Columns and this is red at 320
     * and 360.
     */
    expect(w1Columns(296, 260, 16))->toBe(2);
    expect(w1Columns(296, 260, 16, floor: 1))->toBe(1);

    // And the floor is honoured however absurd the tile.
    expect(w1Columns(296, 4000, 16))->toBe(2);
    expect(w1Columns(140, 4000, 16))->toBe(2);
});

it('honours the cap, and the cap does nothing at its shipped value', function () {
    /*
     * --kbb-cols-cap ships at 8, which is more columns than a 260px tile will
     * ever allow inside 1680px, so it is INERT until the owner lowers it — which
     * is rule 1: a new control that changes nothing until somebody moves it.
     *
     * MUTATION: change the cap term to use `$cap + 1` and the third expectation
     * is red.
     */
    $row = w1RailRow(1680);

    expect(w1Columns($row, 260, 16, cap: 8))->toBe(5);
    expect(w1Columns($row, 260, 16, cap: 3))->toBe(3);
    expect(w1Columns($row, 60, 16, cap: 8))->toBe(8);
});

it('keeps the admin preview arithmetic identical to this file\'s', function () {
    /*
     * (2) IS THE COPY THAT CAN LIE. The console does not load the storefront
     * stylesheet, so the screen recomputes the count in JavaScript; a preview
     * that disagrees with the page is worse than no preview at all.
     *
     * This does not run the JavaScript — Pest has no engine for it — so it pins
     * the four expressions that could drift, by their exact text. Each one is a
     * line in site-layout-screen.blade.php and each is the same term as the PHP
     * above.
     *
     * MUTATION: change the screen's `Math.floor` to `Math.ceil`, or its 250 rail
     * to 240, and this is red.
     */
    $screen = (string) file_get_contents(
        base_path('resources/views/admin/partials/site-layout-screen.blade.php')
    );

    // The rail geometry, which is what makes the shop row different.
    expect($screen)->toContain('var SHOP_RAIL = 250, SHOP_RAIL_GAP = 28, SHOP_RAIL_FROM = 901;');

    // The three terms of the track, in the same order as w1Columns().
    expect($screen)->toContain('(row - (floorN - 1) * gap) / floorN');
    expect($screen)->toContain('Math.max(tile, (row - (capN - 1) * gap) / capN)');
    expect($screen)->toContain('Math.floor((row + gap) / (track + gap))');

    // The container, and the gutter's clamp spelled out.
    expect($screen)->toContain("Math.min(w, num('max', 1680)) - 2 * gutter(w)");
    expect($screen)->toContain("Math.max(lo, Math.min(w * 0.022, Math.max(lo, hi)))");

    // The two tile defaults, so the table cannot quietly start showing a
    // different shop than the stylesheet does.
    expect($screen)->toContain("num('tile', 260)");
    expect($screen)->toContain("num('tile_shop', 220)");

    // And those two defaults are the schema's, not a second opinion.
    $fields = SiteLayout::SCHEMA;

    expect($fields['tile'][2])->toBe(260);
    expect($fields['tile_shop'][2])->toBe(220);
});
