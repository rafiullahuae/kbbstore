<?php

declare(strict_types=1);

use App\Services\ProductStyles;
use App\Services\SiteLayout;
use App\Support\GridSkins;

/**
 * Appearance → Product grid: the 32 designs, the right-hand preview and the
 * controls surfaced onto that screen.                              (Lane GRID)
 *
 * ── THE DEFECT THESE WERE WRITTEN AGAINST ───────────────────────────────────
 *
 * On the shop the owner photographed, every one of the 32 swatches on
 * Appearance → Product grid was a BLANK PINK RECTANGLE with a label under it.
 * Not a styling failure: renderLayout() emitted
 *
 *     <span class="skinsw-p" data-skin-preview="${s.key}"></span>
 *
 * — an empty span — and `data-skin-preview` occurred exactly ONCE in the whole
 * repository, right there. Nothing read it and nothing filled it. What showed
 * was `.skinsw-p`'s own placeholder: `height:52px` and
 * `linear-gradient(135deg,#ffe3ec,#ffc6da)`, 32 times over.
 *
 * ── THE FALSE GREEN THIS FILE IS BUILT TO AVOID ─────────────────────────────
 *
 * "The preview renders" is satisfied by an EMPTY element, and "32 designs are
 * listed" passed happily for every release in which all 32 were blank — that
 * assertion was true the whole time the screen was broken. So these cases
 * assert the swatch carries real card CONTENT, and that the content is the
 * storefront's own card rather than a shape re-typed here.
 */
function pgpConsole(): string
{
    return (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

/**
 * The lane's own region of the console script, WITH ITS COMMENTS REMOVED.
 *
 * ── WHY THE COMMENTS COME OUT, AND IT IS NOT TIDINESS ───────────────────────
 *
 * The first run of this file had two cases fail, and both were right to. The
 * region's own header QUOTES the defect it fixes -- the empty
 * `data-skin-preview` span -- and names the forbidden measuring APIs out loud
 * to say the screen uses none of them. So an absence assertion was red because
 * of prose, and, far worse, every PRESENCE assertion here could have been
 * satisfied by a comment MENTIONING the code rather than by the code. That is
 * the same false-green class as "the preview element exists": true while the
 * screen is broken. Stripping the comments first is what makes these cases
 * assert the screen instead of its documentation.
 */
function pgpRegion(): string
{
    $src = pgpConsole();
    $from = strpos($src, '/* ---------- Appearance · Product grid ---');
    expect($from)->not->toBeFalse();
    $to = strpos($src, '/* ---------- Appearance · Quantity bundles ---', (int) $from);
    expect($to)->not->toBeFalse();

    $region = substr($src, (int) $from, (int) $to - (int) $from);

    return (string) preg_replace('~/\*.*?\*/~s', ' ', $region);
}

it('puts a real card in every skin swatch instead of an empty placeholder', function () {
    $region = pgpRegion();

    /*
     * MUTATION NOTE — RUN. Put the old markup back:
     *
     *     <span class="skinsw-p" data-skin-preview="${s.key}"></span>
     *
     * and the first two expectations go red together, which is exactly the
     * screen in the owner's screenshot: 32 pink blocks. Re-adding skinCard()
     * greens them again.
     */
    expect($region)->toContain('class="skinsw-p skinprev"')
        ->and($region)->toContain('${skinCard(s.key)}</span>');

    /*
     * AND THE SWATCH IS NOT SELF-CLOSING ON AN EMPTY SPAN. The defect shape was
     * an open tag immediately closed; this pins that the card sits BETWEEN the
     * tags. `></span>` directly after the attribute list is the broken shape.
     */
    expect($region)->not->toContain('data-skin-preview="${s.key}"></span>');
});

it('renders card content, not an empty box, because skinCard draws the real tile', function () {
    $src = pgpConsole();
    $at = strpos($src, 'function skinCard(skin, cartLabel)');
    expect($at)->not->toBeFalse();
    $body = substr($src, (int) $at, 1600);

    /*
     * THE ANTI-FALSE-GREEN CASE. A swatch that calls skinCard() proves nothing
     * if skinCard() returns a husk, and "the preview element exists" is the
     * assertion that stayed green through the entire defect. So the parts the
     * owner named in his screenshot are pinned by name.
     *
     * MUTATION NOTE — RUN. Deleting the `kbb-card-cart` span from skinCard()
     * reds the last expectation here; deleting the whole `.cb` block reds four
     * of them at once.
     */
    expect($body)->toContain('kbb-card-thumb')     // the picture
        ->and($body)->toContain('kbb-badge-new')   // NEW
        ->and($body)->toContain('kbb-badge-sale')  // -30%
        ->and($body)->toContain('kbb-card-brand')  // brand
        ->and($body)->toContain('kbb-card-nm')     // name
        ->and($body)->toContain('kbb-crate')       // stars
        ->and($body)->toContain('kbb-card-price')  // price
        ->and($body)->toContain('kbb-card-cart');  // Add to cart
});

it('lets the card out of the 52px pink placeholder the swatch used to be', function () {
    $src = pgpConsole();

    /*
     * `.skinsw-p` is still `height:52px` + the pink gradient at ~line 703, and
     * `.skinprev` is still `width:132px` (~1779) and `transform:scale(.86)`
     * (~1853). A real card inside the swatch needs all three undone, and the
     * override has to come LATER in the sheet to win at equal specificity.
     *
     * MUTATION NOTE — RUN. Move this rule above `.skinprev{width:132px}` and
     * the swatches render at a fixed 132px inside their own column, clipped to
     * 52px tall with pink showing through — visibly the old bug. The rule is
     * still present, so only the ordering case below goes red.
     */
    expect($src)->toContain('.skinsw-p.skinprev{height:auto;width:100%;background:none;transform:none;');

    $override = strpos($src, '.skinsw-p.skinprev{');
    $pin132 = strpos($src, '.skinprev{width:132px;display:block}');
    $scale = strpos($src, '.skinprev{display:block;transform:scale(.86)');

    expect($pin132)->not->toBeFalse()
        ->and($scale)->not->toBeFalse()
        ->and($override)->toBeGreaterThan((int) $pin132)
        ->and($override)->toBeGreaterThan((int) $scale);
});

it('makes the seven card-content switches reach a preview at all', function () {
    $src = pgpConsole();

    /*
     * A SECOND LIVE DEFECT, found while fixing the first and fixed with it.
     *
     * The admin rules are `.skinprev .pc-nobrand .kbb-card-brand` — a DESCENDANT
     * combinator. psPreview() on Appearance → Product styles sets
     * `el.className='skinprev '+…`, putting `pc-nobrand` on the `.skinprev`
     * element ITSELF, and both classes on one element never match a descendant
     * combinator. So switching Brand name, Category label, Stars, Was price,
     * Discount badge, New badge or Add to cart off moved NOTHING in the preview
     * beside the switches — and since 2.60.330 ships show_brand and
     * show_category OFF, that preview has been drawing a brand line and a
     * category eyebrow the shop does not draw.
     *
     * The storefront writes these unprefixed (`.pc-nobrand .kbb-card-brand`,
     * kbb-grid-skins.css:421): an ANCESTOR class, which `.skinprev` is.
     *
     * MUTATION NOTE — RUN. Delete any one of these seven rules and that switch
     * silently stops reaching both previews while every other assertion in this
     * file stays green — which is precisely how the defect survived.
     */
    foreach ([
        'pc-nobrand' => 'kbb-card-brand',
        'pc-nocat' => 'kbb-card-cat',
        'pc-norate' => 'kbb-card-rate',
        'pc-nowas' => 'kbb-card-reg',
        'pc-nodisc' => 'kbb-badge-sale',
        'pc-nonew' => 'kbb-badge-new',
        'pc-nocart' => 'kbb-card-cart',
    ] as $flag => $target) {
        expect($src)->toContain(".skinprev.{$flag} .{$target}{display:none}");
    }
});

it('shows a grid of cards on the right, sized by CSS and never by measurement', function () {
    $src = pgpConsole();
    $region = pgpRegion();

    // The panel, and that it is filled from the storefront's own card.
    expect($region)->toContain('id="pgPrev"')
        ->and($region)->toContain('class="skinprev pgprev"')
        ->and($region)->toContain('host.innerHTML = skinCard(skin)')
        ->and($region)->toContain('card.cloneNode(true)');

    /*
     * A GRID, NOT ONE CARD. `.skinprev` collapses its grid to `display:block`
     * for the 132px popup thumbnail; the panel undoes exactly that, and the
     * column count and gap arrive as custom properties.
     *
     * MUTATION NOTE — RUN. Drop `--pg-cols` from pgPaint() and the preview
     * falls back to the sheet's `repeat(var(--pg-cols,4),…)`, so it stops
     * following the Columns select — the fourth expectation here goes red.
     */
    /*
     * AND THE CARD MUST GIVE UP ITS 132px, which is a separate rule and was a
     * separate bug. `.skinprev .kbb-card{width:132px}` (~1781) pins the card to
     * the popup thumbnail's width, so the tracks were right and the CARDS
     * overflowed them: measured in Chromium at 1280, four tracks of 85.5px
     * carrying four cards of 132px, `.pgpv-in` scrolling 451px inside a 418px
     * box and the fourth card running off the panel. With the rule, 100.5px
     * tracks carrying 100.5px cards and scrollWidth === clientWidth === 478.
     *
     * The swatch needs the same release for the same reason, at 100%: without
     * it all 32 sit at 132px in a 147px cell with a ragged strip down the right.
     *
     * MUTATION NOTE -- RUN. Deleting either rule leaves every other assertion
     * in this file green -- which is how the overflow reached a screenshot the
     * first time.
     */
    expect($src)->toContain('.pgprev.skinprev .kbb-card{width:auto}')
        ->and($src)->toContain('.skinsw-p.skinprev .kbb-card{width:100%}')
        ->and($src)->toContain('.pgprev.skinprev .kbb-pgrid{display:grid!important;')
        ->and($src)->toContain('grid-template-columns:repeat(var(--pg-cols,4),minmax(0,1fr))!important;')
        ->and($src)->toContain('gap:var(--pg-gap,16px)!important}')
        ->and($region)->toContain("'--pg-cols:' + pgNum(PGCOLS, 1, 6, 4)")
        ->and($region)->toContain("'--pg-gap:' + pgNum(PGSL.gap, 6, 32, 16)");

    /*
     * RULE 4. This project sizes with calc() and custom properties; two tests
     * already forbid the element-measuring APIs by name, and this screen adds
     * none of them.
     */
    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight'] as $api) {
        expect($region)->not->toContain($api);
    }
});

it('surfaces settings that already exist and defines not one of its own', function () {
    $region = pgpRegion();

    /*
     * THE OWNER ASKED FOR CONTROLS THAT ALL ALREADY EXISTED.
     *
     *   what a card shows   Appearance → Product styles → Card content
     *   spacing             Appearance → Site layout → Product grid
     *
     * A second key for one question is what ProductStyles::SCHEMA's own header
     * calls "the thing this shop keeps paying for", and `grid_gap` was DELETED
     * from that schema for exactly this reason. So this screen POSTs the SAME
     * keys to the SAME two endpoints, and this case is what stops a later edit
     * quietly inventing `pg_gap` or `grid_show_brand` beside them.
     *
     * MUTATION NOTE — RUN. Rename any entry of PG_CONTENT to a key that is not
     * in ProductStyles::SCHEMA — `show_brands`, say — and this goes red naming
     * it, while the screen still draws a switch that saves nothing.
     */
    foreach (['show_new', 'show_discount', 'show_category', 'show_brand',
              'show_rating', 'show_was_price', 'show_cart'] as $key) {
        expect(ProductStyles::SCHEMA)->toHaveKey($key)
            ->and($region)->toContain("'{$key}'");
    }

    foreach (['gap', 'tile'] as $key) {
        expect(SiteLayout::SCHEMA)->toHaveKey($key);
    }

    // It talks to the endpoints that own those schemas, not to one of its own.
    expect($region)->toContain("'/admin-api/product-styles'")
        ->and($region)->toContain("'/admin-api/site-layout'")
        ->and($region)->toContain("'/admin-api/layout'");
});

it('ships every surfaced control at the value the shop already renders', function () {
    /*
     * CLAUDE.md rule 1. The owner asked for CONTROLS, not for a new look, so
     * applying this package must move nothing until he moves something. These
     * are the shipped defaults as they stood BEFORE this lane; the screen reads
     * them from the two endpoints and writes back only what the owner changes,
     * so there is no third place for a default to drift to.
     *
     * MUTATION NOTE — RUN. Flip ProductStyles::SCHEMA['show_brand'] default
     * from false to true and this goes red on that key — which is the shape of
     * "a lane moved the shop without being asked".
     */
    $expected = [
        'show_brand' => false,   // off since 2.60.330, the owner asked for it
        'show_category' => false,
        'show_rating' => true,
        'show_was_price' => true,
        // Off since Lane PR, 2 October 2026 — the owner asked in as many
        // words: "Turn off by default on the product grid card, new and
        // discount tag." GridCardOwnerAsksTest pins why and where.
        'show_discount' => false,
        'show_new' => false,
        'show_cart' => true,
    ];

    foreach ($expected as $key => $value) {
        expect(ProductStyles::SCHEMA[$key][2])->toBe($value, "default for {$key} moved");
    }

    expect(SiteLayout::SCHEMA['gap'][2])->toBe(16)
        ->and(SiteLayout::SCHEMA['tile'][2])->toBe(220)
        ->and(GridSkins::DEFAULT)->toBe('showcase');
});

it('leaves no settings row behind for a control the owner never touched', function () {
    $region = pgpRegion();

    /*
     * MEASURED, THEN FIXED. Pressing Save after switching ONE thing POSTed all
     * seven card-content keys and both spacing keys, each carrying the value it
     * already had. Nothing on the shop moved, so no test saw it -- but writing
     * a key at its current value turns "never saved, follows the default" into
     * a STORED ROW, and a stored row is exactly what stops a later default from
     * being seen. ProductStyles' own 2.60.330 migration exists to delete rows
     * of that kind. Confirmed against the running console: the same edit now
     * POSTs `saved: 1` where it used to POST `saved: 7`.
     *
     * MUTATION NOTE -- RUN. Drop the `!== pgBool(PGS0[k])` comparison and this
     * goes red; the console then reports "saved 7" for a single switch again.
     */
    expect($region)->toContain('PGS0 = Object.assign({}, PGS)')
        ->and($region)->toContain('PGSL0 = Object.assign({}, PGSL)')
        ->and($region)->toContain('pgBool(PGS[k]) !== pgBool(PGS0[k])')
        ->and($region)->toContain('if (v !== pgNum(PGSL0[k], min, max, min)) s[k] = v;')
        // and a request is not sent at all when the diff is empty
        ->and(substr_count($region, 'if (Object.keys(s).length) {'))->toBe(2);
});

it('only ever hands skinCard a skin the server listed', function () {
    $region = pgpRegion();

    /*
     * RULE 5, secure by construction. skinCard() interpolates its argument
     * straight into `data-skin="${skin}"` with no escaping, so the defence is
     * MEMBERSHIP of the list the server sent rather than escaping — a select
     * stores one of its own options or the default.
     *
     * MUTATION NOTE — RUN. Replace the pgSkinOk() guard in pgPaint() with a
     * bare `PGSKIN` and this goes red; the click handler's guard is the second
     * half and reds the same case.
     */
    expect($region)->toContain('function pgSkinOk(k){ return !!(PGL && PGL.skins && PGL.skins.some(s => s.key === k)); }')
        ->and($region)->toContain('const skin = pgSkinOk(PGSKIN) ? PGSKIN : (PGL && PGL.current)')
        ->and($region)->toContain('if (!pgSkinOk(k)) return;');
});

it('lists all 32 designs and each one names a real skin', function () {
    /*
     * The count on its own is the assertion that passed through the entire
     * defect, so it is here only BESIDE the content cases above — never as the
     * proof that the screen works.
     */
    expect(GridSkins::ALL)->toHaveCount(32)
        ->and(GridSkins::ALL)->toHaveKey('classic')
        ->and(GridSkins::ALL)->toHaveKey('showcase-airy');
});
