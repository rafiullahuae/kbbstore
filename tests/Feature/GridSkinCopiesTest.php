<?php

declare(strict_types=1);

/**
 * THE PRODUCT CARD IS WRITTEN TWICE, AND THE TWO COPIES HAVE DRIFTED.
 *                                                        (Lane PLC, round 6)
 *
 * resources/css/kbb/kbb-grid-skins.css and resources/css/kbb/kbb.css both carry
 * the product grid's skin rules. That duplication is not itself the defect —
 * this repository already keeps one deliberate duplicate honest with a test
 * (partials/page-background-css.blade.php and StandaloneDocumentHeadTest), and
 * that is a pattern, not an accident. SILENT DIVERGENCE is the defect, and it
 * is live:
 *
 *   · `/` pushes kbb-grid-skins.css into the styles stack and `/shop` does not.
 *     Measured off document.styleSheets in a real browser, not read off the
 *     markup — a <link> that 404s leaves no rules behind:
 *         /      : kbb-<hash>.css, kbb-grid-skins-<hash>.css
 *         /shop  : kbb-<hash>.css, kbb-shop-<hash>.css
 *   · so a rule that only one copy carries is a rule that only some pages have.
 *
 * ── WHAT THE DRIFT ACTUALLY IS, MEASURED ────────────────────────────────────
 *
 * kbb.css's own comment above the copy calls it "~180 rules". That was an
 * estimate written by eye and it is the wrong shape as well as the wrong
 * number. Parsed properly — brace-depth walk, at-rule stack, comments stripped,
 * quotes tracked (tests/Support/CssRules.php):
 *
 *     selectors the two copies share                    : 225
 *     of those, declarations that DISAGREE on a value   :   0
 *     selectors only kbb-grid-skins.css declares        :  24
 *     selectors only kbb.css declares                   :  34
 *     ── divergent selectors                            :  58
 *        divergent declarations inside them             : 151
 *
 * So it is not 180 rules missing from one side. It is 58 selectors, drifting in
 * BOTH directions, and — the part that matters most — the 225 selectors both
 * copies write agree on every single value. Source order cannot decide anything
 * between them today, which is exactly the property this file exists to keep.
 *
 * ── AND TEN OF THEM REACH THE SHOPPER ───────────────────────────────────────
 *
 * The 151 were taken to a browser (tools/plc-css-measure-drift.cjs generates
 * its probe list FROM the parse, so it can find what nobody suspected) and read
 * with getComputedStyle on a page that loads both sheets and one that loads
 * only kbb.css. Ten render differently. Two of them a shopper sees:
 *
 *     SALE badge   /      rgb(226, 59, 87)
 *                  /shop  linear-gradient(135deg, rgb(255,111,145), rgb(193,62,99))
 *     NEW badge    /      rgb(31, 157, 85)
 *                  /shop  linear-gradient(135deg, rgb(28,195,106), rgb(18,150,90))
 *
 * and four more are the product-name clamp — `display:-webkit-box`,
 * `-webkit-line-clamp:var(--kbb-name-lines,99)`, `-webkit-box-orient:vertical`,
 * `overflow:hidden` — which is in force on the homepage and ABSENT on /shop, so
 * the Appearance → Product styles name-clamp control does nothing there.
 * Pictures and numbers: docs/PLC-GRID-SKIN-DRIFT.md, docs/PLC-css-drift-shots/.
 *
 * ── WHY THIS IS A PIN AND NOT A DE-DUPLICATION ──────────────────────────────
 *
 * Because the brief's own rule decides it: nothing the owner did not ask about
 * may change. Merging the copies — in either direction — moves those ten
 * declarations, and two of them repaint every sale and new badge on /shop.
 * That is a visible change to a page nobody asked about, so it is the owner's
 * to decide and not a lane's to slip in under a tidying commit. The drift is
 * therefore RECORDED, exactly, and held still.
 *
 * MUTATION, run (each of these was actually done, and each goes red):
 *   · delete `.kbb-gridhead{...}` from kbb-grid-skins.css      → first case red,
 *     naming the selector that left the divergent set.
 *   · add `.kbb-card{color:red}` to kbb-grid-skins.css         → second case red
 *     ('.kbb-card' now disagrees: color red vs #2A2228).
 *   · change `.kbb-card .cn{font-size:13px}` to 14px in either copy alone
 *                                                              → second case red.
 *   · make CssRules::parse() return []                         → third case red.
 */
use Tests\Support\CssRules;

/** The two copies, as the shop links them. */
const KBB_SKIN_SHEETS = [
    'grid-skins' => 'resources/css/kbb/kbb-grid-skins.css',
    'kbb' => 'resources/css/kbb/kbb.css',
];

/**
 * The product grid's own selector vocabulary.
 *
 * kbb.css is the WHOLE SHOP, so comparing it wholesale against the card sheet
 * would report 1,481 selectors "missing" and mean nothing. The card's share of
 * it has to be named, and it is named by VOCABULARY rather than by a line
 * range: the first version of this cut kbb.css between its "A SECOND, COMPLETE
 * COPY" banner and the next banner comment and was wrong by about forty,
 * because the page background (`html::before`, the three gradients, the 300s
 * keyframes) and the product-page price merely LANDED between two banners.
 *
 * Where a rule sits in a file is not what makes it part of the card, and a
 * boundary chosen by position is a boundary that silently moves — the same
 * shape as the fixed-width substr() window CLAUDE.md names.
 *
 * `.cb`, `.cn` and `.cp` are card internals and far too generic to list; they
 * only ever appear under a `.kbb-card`/`.kbb-pgrid` ancestor, so the ancestor
 * is matched and they come along with it.
 */
const KBB_CARD_SURFACE = [
    'kbb-pgrid', 'kbb-card', 'kbb-badge', 'kbb-crate', 'kbb-cstar',
    'kbb-tile', 'kbb-gridhead', 'pc-no', 'data-skin', 'qv-btn',
];

/**
 * Selectors kbb-grid-skins.css declares and the kbb.css copy does not.
 *
 * Read as a list of what only the homepage, the wishlist and a collection page
 * get: the whole `showcase` family is absent because it lives under
 * `[data-skin^="showcase"]` on both sides and therefore shares its selectors;
 * what is here is the Appearance → Product styles WIRING (`--kbb-price`,
 * `--kbb-sale`, `--kbb-new`, `--kbb-star`, `--kbb-cart-bg`, `--kbb-ratio`,
 * `--kbb-name-lines`), the seven `.pc-no*` visibility toggles, the section
 * heading, and the equal-height flex stack.
 */
const KBB_ONLY_IN_GRID_SKINS = [
    '|*',
    '|.kbb-gridhead',
    '|.kbb-gridhead .lnk',
    '|.kbb-gridhead h2',
    '|.kbb-gridhead p',
    '|.kbb-pgrid .cn',
    '|.kbb-pgrid .cp,.kbb-pgrid .kbb-card-rate',
    '|.kbb-pgrid .kbb-badge-new',
    '|.kbb-pgrid .kbb-badge-sale',
    '|.kbb-pgrid .kbb-card-brand',
    '|.kbb-pgrid .kbb-card-cart',
    '|.kbb-pgrid .kbb-card-price',
    '|.kbb-pgrid .kbb-card-thumb',
    '|.kbb-pgrid .kbb-cstar.on',
    '|.kbb-pgrid:not([data-skin="horizontal"]) .kbb-card',
    '|.kbb-pgrid:not([data-skin="horizontal"]):not([data-skin="overlay"]):not([data-skin="glass"]):not([data-skin="reveal"]):not([data-skin^="showcase"]) .cb',
    '|.kbb-pgrid:not([data-skin="horizontal"]):not([data-skin="overlay"]):not([data-skin="glass"]):not([data-skin="reveal"]):not([data-skin^="showcase"]) .kbb-card-cart',
    '|.pc-nobrand .kbb-card-brand',
    '|.pc-nocart .kbb-card-cart',
    '|.pc-nocat .kbb-card-cat',
    '|.pc-nodisc .kbb-badge-sale',
    '|.pc-nonew .kbb-badge-new',
    '|.pc-norate .kbb-card-rate',
    '|.pc-nowas .kbb-card-reg',
];

/**
 * Selectors the kbb.css copy declares and kbb-grid-skins.css does not.
 *
 * These are on EVERY page, because kbb.css is linked from the layout. Most of
 * it is the `.kbb-tile` component — the storefront card's real markup, which
 * `components/product-card.blade.php` emits as `.kbb-card.kbb-tile` — plus the
 * phone ladder at 900px and the `.ph2` photograph placeholder.
 */
const KBB_ONLY_IN_KBB_CSS = [
    /*
     * Integrator, 2.60.354 — Quick view in the middle of the photograph. In
     * kbb.css ONLY, deliberately: the card is on every page and so is kbb.css.
     * The Showcase pair is said at Showcase's weight plus one so that it beats
     * kbb-grid-skins.css's `transform:none` on the three pages that load both.
     * QuickViewCentredTest has the measurements.
     */
    '|.kbb-tile .kbb-card-thumb>.qv-btn',
    '|.kbb-tile .kbb-card-thumb>.qv-btn:focus-visible,.kbb-tile:hover .kbb-card-thumb>.qv-btn',
    '|.kbb-pgrid[data-skin^="showcase"] .kbb-tile .kbb-card-thumb>.qv-btn',
    '|.kbb-pgrid[data-skin^="showcase"] .kbb-tile .kbb-card-thumb>.qv-btn:focus-visible,.kbb-pgrid[data-skin^="showcase"] .kbb-tile:hover .kbb-card-thumb>.qv-btn',
    '@media(max-width:900px)|.kbb-badge',
    '@media(max-width:900px)|.kbb-card',
    '@media(max-width:900px)|.kbb-card-brand',
    '@media(max-width:900px)|.kbb-card-cart',
    '@media(max-width:900px)|.kbb-card-cat',
    '@media(max-width:900px)|.kbb-card-price',
    '@media(max-width:900px)|.kbb-card-rate',
    '@media(max-width:900px)|.kbb-card-reg',
    '@media(max-width:900px)|.kbb-card-thumb',
    '|#grid,.kbb-pgrid,.rel',
    '|.kbb-badge-best',
    '|.kbb-card-img',
    '|.kbb-card-ph',
    '|.kbb-card-shot',
    '|.kbb-card-thumb .ph2',
    '|.kbb-card-thumb .ph2,.kbb-home .about .im,.kbb-home .ct .im',
    '|.kbb-card-thumb .ph2::after',
    '|.kbb-card-thumb .ph2::before,.kbb-home .ct .im::before',
    '|.kbb-home .kbb-card-thumb',
    '|.kbb-home .kbb-card-thumb .ph2',
    '|.kbb-tile',
    '|.kbb-tile .cn',
    '|.kbb-tile .cn::after',
    '|.kbb-tile .heart',
    '|.kbb-tile .heart svg',
    '|.kbb-tile .heart,.kbb-tile .kbb-card-cart,.kbb-tile .qv-btn',
    '|.kbb-tile .heart.on',
    '|.kbb-tile .heart.on svg',
    '|.kbb-tile .kbb-card-brand',
    '|.kbb-tile .kbb-card-nm',
    '|.kbb-tile .lbl',
    '|.kbb-tile>.cb',
    '|.kbb-tile>.cb>.cp',
    '|.kbb-tile>.kbb-card-thumb',
    /*
     * Lane PR — the phone-hover block. In kbb.css ONLY, deliberately: kbb.css
     * is on every page, so the owner's "no card hover on a phone" reaches
     * /shop/, every category, every brand page and the product page's related
     * row, and not just the three pages kbb-grid-skins.css is on. Each rule
     * is (0,1,1) above the hover rule it answers, in either sheet, so it wins
     * on the three pages that load both. tools/pr-hover-skins.sh tapped a
     * card on all 32 skins at 390: nothing moves.
     */
    '@media (hover:none),(max-width:700px)|body:not(.pc-phonehover) .kbb-pgrid .kbb-card:hover',
    '@media (hover:none),(max-width:700px)|body:not(.pc-phonehover) .kbb-pgrid .kbb-card:hover .kbb-card-thumb img,body:not(.pc-phonehover) .kbb-pgrid[data-skin="editorial"] .kbb-card:hover .im,body:not(.pc-phonehover) .kbb-pgrid[data-skin="editorial"] .kbb-card:hover img',
    '@media (hover:none),(max-width:700px)|body:not(.pc-phonehover) .kbb-pgrid[data-skin="bold"] .kbb-card:hover,body:not(.pc-phonehover) .kbb-pgrid[data-skin="classic"] .kbb-card:hover,body:not(.pc-phonehover) .kbb-pgrid[data-skin="editorial"] .kbb-card:hover,body:not(.pc-phonehover) .kbb-pgrid[data-skin="luxe"] .kbb-card:hover,body:not(.pc-phonehover) .kbb-pgrid[data-skin="minimal"] .kbb-card:hover,body:not(.pc-phonehover) .kbb-pgrid[data-skin^="showcase"] .kbb-card:hover',
    '@media (hover:none),(max-width:700px)|body:not(.pc-phonehover) .kbb-pgrid[data-skin="duotone"] .kbb-card:hover .kbb-card-thumb:after',
    '@media (hover:none),(max-width:700px)|body:not(.pc-phonehover) .kbb-pgrid[data-skin="fab"] .kbb-card:hover .kbb-card-cart',
    '@media (hover:none),(max-width:700px)|body:not(.pc-phonehover) .kbb-pgrid[data-skin="outline"] .kbb-card:hover',
    '@media (hover:none),(max-width:700px)|body:not(.pc-phonehover) .kbb-pgrid[data-skin="petal"] .kbb-card:hover .kbb-card-thumb',
    '@media (hover:none),(max-width:700px)|body:not(.pc-phonehover) .kbb-pgrid[data-skin="polaroid"] .kbb-card:hover',
    '@media (hover:none),(max-width:700px)|body:not(.pc-phonehover) .kbb-pgrid[data-skin="rosegold"] .kbb-card:hover',
    '@media (hover:none),(max-width:700px)|body:not(.pc-phonehover) .kbb-pgrid[data-skin="showcase-airy"] .kbb-card:hover',
    '@media (hover:none),(max-width:700px)|body:not(.pc-phonehover) .kbb-pgrid[data-skin="showcase-airy"] .kbb-card:hover .kbb-card-cart',
    '@media (hover:none),(max-width:700px)|body:not(.pc-phonehover) .kbb-pgrid[data-skin="soft"] .kbb-card:hover',
    '@media (hover:none),(max-width:700px)|body:not(.pc-phonehover) .kbb-pgrid[data-skin="spotlight"] .kbb-card:hover',
    '@media (hover:none),(max-width:700px)|body:not(.pc-phonehover) .kbb-pgrid[data-skin="stacked"] .kbb-card:hover .cb',
];

/** Every rule in a sheet the repository ships, parsed. */
function kbbSkinRules(string $path): array
{
    return CssRules::parse((string) file_get_contents(base_path($path)));
}

function kbbIsCardSelector(string $selector): bool
{
    foreach (KBB_CARD_SURFACE as $token) {
        if (str_contains($selector, $token)) {
            return true;
        }
    }

    return false;
}

/**
 * context|selector => rules, for one copy of the card.
 *
 * Every rule in kbb-grid-skins.css is the card by construction; kbb.css is cut
 * to the surface.
 */
function kbbCardCopy(string $which): array
{
    $out = [];

    foreach (kbbSkinRules(KBB_SKIN_SHEETS[$which]) as $rule) {
        if ($which === 'kbb' && ! kbbIsCardSelector($rule['selector'])) {
            continue;
        }

        /*
         * Lane RD's press feedback names the card's controls (.heart,
         * .kbb-card-cart) among every other button in the shop, but it is not a
         * copy of the card: it is keyed by html[data-press], lives only in
         * kbb.css ON PURPOSE because it must reach every page, and is pinned by
         * PressFeedbackTest. Counting it here would record forty "divergences"
         * that are one site-wide rule set.
         */
        if ($which === 'kbb' && str_contains($rule['selector'], 'html[data-press')) {
            continue;
        }

        $out[$rule['context'].'|'.$rule['selector']][] = $rule;
    }

    return $out;
}

it('keeps the two copies of the product card diverging in exactly the recorded places', function () {
    /*
     * The drift is allowed to EXIST — merging it repaints /shop's badges, which
     * is the owner's call — but it is not allowed to MOVE. A lane that edits one
     * copy and not the other lands here with the selector it added or removed
     * named, rather than shipping a rule that silently applies on three pages
     * out of the shop.
     *
     * Pinned as a SET and not as a count, because a count of 58 is green after
     * a lane removes one divergence and introduces another.
     */
    $skins = kbbCardCopy('grid-skins');
    $kbb = kbbCardCopy('kbb');

    $onlySkins = array_keys(array_diff_key($skins, $kbb));
    $onlyKbb = array_keys(array_diff_key($kbb, $skins));
    sort($onlySkins);
    sort($onlyKbb);

    $expectedSkins = KBB_ONLY_IN_GRID_SKINS;
    $expectedKbb = KBB_ONLY_IN_KBB_CSS;
    sort($expectedSkins);
    sort($expectedKbb);

    expect($onlySkins)->toBe($expectedSkins,
        'kbb-grid-skins.css now declares a different set of selectors from the kbb.css copy than '
        .'the one recorded here. If you added a card rule, add it to BOTH copies or record it in '
        .'KBB_ONLY_IN_GRID_SKINS and say in the commit which pages it therefore reaches — '
        .'kbb-grid-skins.css is only on /, /wishlist and a collection page.');

    expect($onlyKbb)->toBe($expectedKbb,
        'the kbb.css copy of the card now declares a different set of selectors from '
        .'kbb-grid-skins.css than the one recorded here. kbb.css is on EVERY page, so a rule '
        .'added only here applies everywhere and a rule removed from here disappears everywhere.');
});

it('never lets the two copies disagree about a selector they both declare', function () {
    /*
     * ▲ THIS IS THE CASE THAT MATTERS.
     *
     * Two copies with different selector sets are a documented gap. Two copies
     * that declare THE SAME SELECTOR with DIFFERENT VALUES is the live
     * correctness bug the brief names: the shop loads both sheets on three
     * pages, so which value wins there is decided by source order, while on
     * every other page only kbb.css is present and its value wins outright.
     * The same card would then be two different cards depending on the page,
     * and nothing in the repository would say so.
     *
     * It is zero today — measured, over 225 shared selectors and 755 shared
     * declarations — and this keeps it zero.
     *
     * The LAST declaration of a property within a sheet is what that sheet
     * contributes, which is what the cascade uses at equal specificity, so that
     * is what is compared.
     */
    $skins = kbbCardCopy('grid-skins');
    $kbb = kbbCardCopy('kbb');
    $shared = array_intersect_key($skins, $kbb);

    $index = static function (array $groups): array {
        $out = [];

        foreach ($groups as $key => $rules) {
            foreach ($rules as $rule) {
                foreach ($rule['decls'] as [$prop, $value]) {
                    $out[$key.'|'.$prop] = $value;
                }
            }
        }

        return $out;
    };

    $ia = $index($shared);
    $ib = $index(array_intersect_key($kbb, $skins));

    $conflicts = [];

    foreach ($ia as $key => $value) {
        if (array_key_exists($key, $ib) && $ib[$key] !== $value) {
            [$ctx, $sel, $prop] = explode('|', $key, 3);
            $conflicts[] = ($ctx === '' ? '' : $ctx.' :: ').$sel.' { '.$prop.' } is '
                .var_export($value, true).' in kbb-grid-skins.css and '
                .var_export($ib[$key], true).' in kbb.css';
        }
    }

    expect($conflicts)->toBe([],
        'the two copies of the product card now declare the same selector with different values. '
        .'Which one the shopper gets depends on which page they are on, because /shop does not '
        .'load kbb-grid-skins.css at all. Make the two agree, or move the rule to one sheet.');

    // Anti-vacuity: a comparison over an empty intersection reports no conflicts.
    expect(count($shared))->toBeGreaterThan(200,
        'the two copies share fewer than two hundred selectors, which means the parse or the '
        .'card-surface list has stopped matching rather than that the sheets agree');
});

it('reads the stylesheets as rules rather than as lines', function () {
    /*
     * The guard above is only worth anything if the parser is. This asserts the
     * three things it has to get right, each of which the sheets actually
     * contain: an at-rule context, a `url("data:…")` value carrying the
     * characters a naive split would choke on, and a comment that quotes CSS.
     *
     * MUTATION, run: make CssRules::stripComments() a no-op → the third
     * expectation is red, because kbb.css's own comments name `.kbb-card`
     * selectors that are not rules.
     */
    $rules = CssRules::parse(<<<'CSS'
        /* .commented-out{color:red} */
        .a{color:red}
        @media(max-width:900px){.b,.a{background:url("data:image/svg+xml;utf8,a;b/*c*/");color:blue}}
        CSS);

    $byKey = [];

    foreach ($rules as $rule) {
        $byKey[$rule['context'].'|'.$rule['selector']] = $rule['decls'];
    }

    // The at-rule is context, and the selector's comma parts are normalised.
    expect(array_keys($byKey))->toBe(['|.a', '@media(max-width:900px)|.a,.b']);

    // The data URI survives the declaration split intact, semicolons and all.
    expect($byKey['@media(max-width:900px)|.a,.b'][0][1])
        ->toBe('url("data:image/svg+xml;utf8,a;b/*c*/")');

    // The commented-out rule is not a rule.
    expect($byKey)->not->toHaveKey('|.commented-out');
});

it('still links kbb-grid-skins.css from exactly the three storefront pages the drift is scoped to', function () {
    /*
     * The numbers above are only a live risk because the two sheets do not
     * appear together everywhere. If a later lane adds the @vite line to the
     * layout, the drift stops mattering and this file should be revisited
     * rather than left asserting a hazard that has gone; if a lane REMOVES one,
     * a page silently loses the whole `--kbb-*` wiring.
     *
     * Pinned as the FINISHED state — a count per file — and not as an absence,
     * for the reason CLAUDE.md gives: an assertion that something is not wired
     * up goes red the day somebody wires it.
     *
     * MUTATION, run: delete the @vite line from store/collection.blade.php →
     * red, naming that file.
     */
    $expected = [
        'resources/views/store/home.blade.php' => 1,
        'resources/views/store/wishlist.blade.php' => 1,
        'resources/views/store/collection.blade.php' => 1,
        // The layout links kbb.css for every page, and does NOT link the skins.
        'resources/views/layouts/store.blade.php' => 0,
    ];

    $needle = "'resources/css/kbb/kbb-grid-skins.css'";
    $wrong = [];

    foreach ($expected as $file => $times) {
        $source = (string) file_get_contents(base_path($file));
        $found = substr_count($source, $needle);

        if ($found !== $times) {
            $wrong[] = $file.' names kbb-grid-skins.css '.$found.' times, recorded as '.$times;
        }
    }

    expect($wrong)->toBe([]);

    // And kbb.css is linked once from the layout, which is why it is everywhere.
    expect(substr_count((string) file_get_contents(base_path('resources/views/layouts/store.blade.php')), "'resources/css/kbb/kbb.css'"))
        ->toBe(1);
});
