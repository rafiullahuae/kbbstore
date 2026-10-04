<?php

declare(strict_types=1);

use App\Services\ProductStyles;
use App\Services\SettingsService;

/*
 * Appearance → Product grid → "Space inside each card".            (2.60.371)
 *
 * THE OWNER, 3 October, with a screenshot of this screen's Spacing card: "i
 * asked you several times to give me spacing controls for grid card, spacing
 * between image, title, pricing, rating, add to cart. but i didn't receive
 * any. please give me on the grid page setting as marked." The controls lived
 * on Appearance → Product styles → Spacing & type, which is not where he
 * looks, and the brand line and the stars had none at all.
 */

function pgcsScreen(): string
{
    return (string) file_get_contents(base_path('resources/views/admin/app.blade.php'));
}

it('puts a phone and a desktop slider for all six gaps on the Product grid screen', function () {
    /*
     * The defect on the shop: the Product grid screen had two spacing sliders,
     * both BETWEEN cards, and nothing for the space INSIDE one. MUTATION: drop
     * the card_gap_rate row from PG_CARD_SPACE and this is red naming it.
     */
    $html = pgcsScreen();

    expect(preg_match('/const PG_CARD_SPACE = \[(.*?)\n\];/s', $html, $m))->toBe(1);

    preg_match_all("/'(card_[a-z_]+_[md])'/", $m[1], $keys);

    expect($keys[1])->toBe([
        'card_pad_m', 'card_pad_d', 'card_gap_img_m', 'card_gap_img_d',
        'card_gap_brand_m', 'card_gap_brand_d', 'card_gap_rate_m', 'card_gap_rate_d',
        'card_gap_price_m', 'card_gap_price_d', 'card_gap_cart_m', 'card_gap_cart_d',
    ]);

    // Every one is a REAL ProductStyles range, so this screen surfaces a
    // setting rather than inventing a second copy of it.
    foreach ($keys[1] as $key) {
        expect(ProductStyles::SCHEMA[$key][0] ?? null)->toBe('range', $key);
    }

    // Drawn once, saved through the endpoint that owns the schema.
    expect(substr_count($html, 'id="pgCardSpace"'))->toBe(1)
        ->and($html)->toContain("PG_CARD_SPACE.forEach(([m, d]) => [m, d].forEach(k => {");
});

it('moves the brand line and the stars, and keeps the Showcase reservation in step', function () {
    /*
     * Showcase reserves the brand and rating rows from --sc-brand-mb and
     * --sc-rate-mt. A margin moved without its custom property would make the
     * row overflow its reserved track and the cards in a row unequal — the
     * defect the owner had fixed once already ("i need all equal height").
     * MUTATION: delete the `--sc-rate-mt` line from cardCss() and this is red.
     */
    $settings = app(SettingsService::class);
    $settings->set('card_gap_brand_d', 10);
    $settings->set('card_gap_rate_m', 0);

    $css = app(ProductStyles::class)->cardCss();

    expect($css)->toContain('@media (min-width:701px){.kbb-pgrid.kbb-pgrid[data-skin]{--sc-brand-mb:10px}')
        ->and($css)->toContain('.kbb-pgrid[data-skin] .kbb-tile .kbb-card-brand{margin-bottom:10px}')
        ->and($css)->toContain('--sc-rate-mt:0px')
        ->and($css)->toContain('.kbb-pgrid[data-skin] .kbb-tile .kbb-card-rate{margin-top:0px}');
});

it('prints nothing at the defaults, so the shop does not move until he moves a slider', function () {
    expect(ProductStyles::SCHEMA['card_gap_brand_m'][2])->toBe(3)
        ->and(ProductStyles::SCHEMA['card_gap_rate_d'][2])->toBe(8)
        ->and(app(ProductStyles::class)->cardCss())->toBe('');
});

it('spaces the price row with margins on capsule designs, so the capsule never grows and the button gap is the owner\'s number', function () {
    /*
     * 2.60.374. The owner, with Price → Add to cart at 24px and the pink price
     * capsule touching the button: "the spacing between the elements inside
     * grid card, is absolutely not applying on front-end, also the price row
     * above space is actually increase the height of row pink capsule. i needed
     * the spacing, not padding."
     *
     * Measured on the Spotlight card before the fix: "Above the price" 13 grew
     * the capsule from 29.5px; "Price → Add to cart" 24 left a gap of 8, because
     * the button's margin lost to kbb-grid-skins.css's (0,7,0) `margin-top:auto`.
     * After: capsule 29.5, above 13, gap exactly 24, on Spotlight, Classic,
     * Split, Pastel, Luxe and Round alike.
     *
     * MUTATION (RUN): put `padding-top` back on the non-Showcase `.cp` and the
     * second expectation is red; drop the `.cp` margin-bottom and the third is.
     */
    $settings = app(SettingsService::class);
    $settings->set('card_gap_price_d', 13);
    $settings->set('card_gap_cart_d', 24);

    $css = app(ProductStyles::class)->cardCss();
    $ns = '.kbb-pgrid[data-skin]:not([data-skin^="showcase"]) .kbb-tile';
    $sc = '.kbb-pgrid[data-skin^="showcase"] .kbb-tile';

    expect($css)->toStartWith('@media (min-width:701px){')
        ->and($css)->toContain($ns.' .cp{margin-top:13px;margin-bottom:24px}')
        ->and($css)->toContain($ns.' .kbb-card-cart{margin-top:0}')
        ->and($css)->not->toContain($ns.' .cp{padding-top')
        // Showcase draws `.cp` with no background and reserves its rows from padding.
        ->and($css)->toContain($sc.' .cp{padding-top:13px}')
        ->and($css)->toContain($sc.' .kbb-card-cart{margin-top:24px}');
});

it('previews ONE card on Appearance → Product grid, with the same margins the shop gets', function () {
    // The owner: "please give me only one card preview on backend".
    $html = pgcsScreen();

    expect($html)->toContain("grid.style.setProperty('--pg-cols', '1');")
        ->and($html)->not->toContain('const want = Math.max(2, pgNum(PGCOLS, 1, 6, 4) * 2);')
        ->and($html)->toContain('${NS} .cp{margin-bottom:${ca}px}${NS} .kbb-card-cart{margin-top:0}')
        ->and(substr_count($html, '.cp{padding-top:${pr}px;margin-top:0}`);'))->toBe(0);
});
