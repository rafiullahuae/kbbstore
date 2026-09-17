<?php

declare(strict_types=1);

use Tests\Support\CssDirection;

/**
 * The guard for T6's MANUAL half — the part that makes the Arabic shop usable.
 *
 * RtlReadinessTest pins the mechanical half: no physical direction property may
 * come back into a converted file except the ones docs/rtl-audit.md documents.
 * It is deliberately blind to the thing this file is about, and has to be:
 * `transform`, `background-position` and `linear-gradient()` carry a direction
 * and have no logical form at all, so a panel can pass that guard with a
 * perfectly logical inset and still slide in from the wrong side — or sit on
 * screen in its closed state, which is what "converting the inset alone" costs.
 *
 * Every rule this file checks was verified in a browser before it was written,
 * and the failure each one describes was photographed first. What is pinned:
 *
 *   1. every off-canvas panel's closed-state translateX has a [dir="rtl"] twin
 *      with the opposite sign;
 *   2. that twin NEVER outranks the rule that opens the panel — the two have
 *      equal specificity, so getting the source order wrong holds the drawer
 *      shut in Arabic while every test that only reads declarations passes;
 *   3. the centring idiom (left:50% + translateX(-50%)) was NOT flipped;
 *   4. the two honeypots and WooCommerce's hidden #place_order park off the
 *      opposite edge in RTL, because -9999px on the wrong side opens ten
 *      thousand pixels of horizontal scroll;
 *   5. the free-shipping bar is mirrored as ONE unit and not sprite by sprite;
 *   6. the country <select>'s chevron and the padding that reserves room for it
 *      name the same side in both directions.
 */
function rtlMirrorDeclarations(string $relative): array
{
    static $cache = [];

    return $cache[$relative] ??= CssDirection::declarationsInFile(base_path($relative));
}

/** Every declaration of one property on one selector, as [line => value]. */
function rtlMirrorLookup(string $relative, string $selector, string $property): array
{
    $out = [];

    foreach (rtlMirrorDeclarations($relative) as $d) {
        if ($d['selector'] === $selector && $d['property'] === $property) {
            $out[$d['line']] = $d['value'];
        }
    }

    return $out;
}

/** The first line in a file at which $selector sets $property, or null. */
function rtlMirrorLine(string $relative, string $selector, string $property): ?int
{
    $hits = rtlMirrorLookup($relative, $selector, $property);

    return $hits === [] ? null : (int) array_key_first($hits);
}

/**
 * The off-canvas panels, their closed position, and the mirror of it.
 *
 * Each row is [file, selector, closed transform, the [dir="rtl"] selector, the
 * mirrored transform, the selector that OPENS the panel]. The opening selector
 * is here so that test 2 can check source order; null where the file has none.
 */
function rtlOffCanvasPanels(): array
{
    return [
        ['resources/css/kbb/kbb.css', '.drawer', 'translateX(100%)', '[dir="rtl"] .drawer', 'translateX(-100%)', '.drawer.on'],
        ['resources/css/kbb/kbb.css', '.mnav', 'translateX(-105%)', '[dir="rtl"] .mnav', 'translateX(105%)', '.mnav.on'],
        ['resources/css/kbb/kbb.css', '.msub', 'translateX(-14px)', '[dir="rtl"] .msub', 'translateX(14px)', '.msub.on'],
        ['resources/css/kbb/kbb-shop.css', '.mnav', 'translateX(-100%)', '[dir="rtl"] .mnav', 'translateX(100%)', '.mnav.on'],
        ['resources/css/kbb/kbb-shop.css', '.dw', 'translateX(100%)', '[dir="rtl"] .dw', 'translateX(-100%)', '.dw.on'],
        ['resources/css/kbb/kbb-shop.css', '.dw.left', 'translateX(-100%)', '[dir="rtl"] .dw.left', 'translateX(100%)', '.dw.left.on'],
        ['resources/css/kbb/kbb-shop.css', '@media(max-width:900px) .filtercol', 'translateX(-100%)', '@media(max-width:900px) [dir="rtl"] .filtercol', 'translateX(100%)', '@media(max-width:900px) .filters-open .filtercol'],
        ['resources/css/kbb/kbb-product.css', '.mnav', 'translateX(-100%)', '[dir="rtl"] .mnav', 'translateX(100%)', '.mnav.on'],
        ['resources/css/kbb/kbb-product.css', '.dw', 'translateX(100%)', '[dir="rtl"] .dw', 'translateX(-100%)', '.dw.on'],
        ['resources/views/store/app.blade.php', '.drawer', 'translateX(100%)', '[dir="rtl"] .drawer', 'translateX(-100%)', '.drawer.on'],
        ['resources/views/store/app.blade.php', '.drawer.left', 'translateX(-100%)', '[dir="rtl"] .drawer.left', 'translateX(100%)', '.drawer.left.on'],
        // store/blog.blade.php and store/post.blade.php carry the same .mnav and
        // are DELIBERATELY ABSENT. They hard-code <html lang="en"> with no dir
        // attribute, so [dir="rtl"] can never match there and converting them
        // buys nothing today — while it does change the English bytes of a
        // shipped page, which trips StorefrontEnglishUnchangedTest and forces a
        // BASE_COMMIT repin that a rebase then invalidates. They belong with
        // whoever gives those views a real <html lang>/<html dir>, in one change
        // that repins that guard once. docs/rtl-audit.md §9.5 and §11.1.
    ];
}

it('reads rules the mirror depends on, so the rest of this file is not vacuous', function () {
    // Same lesson as RtlReadinessTest's first test: if the reader stops seeing
    // [dir="rtl"] rules — a Blade <style> block restructured, a file renamed —
    // then "the mirror is present" becomes trivially true everywhere.
    $mirrored = 0;

    foreach (CssDirection::SCOPE as $rel) {
        foreach (rtlMirrorDeclarations($rel) as $d) {
            if (str_contains($d['selector'], '[dir="rtl"]')) {
                $mirrored++;
            }
        }
    }

    expect($mirrored)->toBeGreaterThanOrEqual(20,
        "The reader finds only {$mirrored} declarations under a [dir=\"rtl\"] selector in the storefront stylesheets. Every other assertion in this file would pass against nothing.");

    // And the panel table must describe rules that are really there, or test 1
    // would be checking the mirror of a selector nobody uses.
    $missing = [];

    foreach (rtlOffCanvasPanels() as [$file, $selector, $closed]) {
        if (rtlMirrorLookup($file, $selector, 'transform') === []) {
            $missing[] = "$file | $selector";
        }
    }

    expect($missing)->toBe([], "These off-canvas panels are in this test's table but no longer in the stylesheets:\n".implode("\n", $missing));
});

it('gives every off-canvas panel a [dir="rtl"] transform with the opposite sign', function () {
    // translateX has no logical form. An inset converted to inset-inline-*
    // without this flips which edge the panel is anchored to WITHOUT flipping
    // the direction it slides from, so the panel sits on screen when closed.
    $wrong = [];

    foreach (rtlOffCanvasPanels() as [$file, $selector, $closed, $rtlSelector, $mirrored]) {
        $base = rtlMirrorLookup($file, $selector, 'transform');
        $rtl = rtlMirrorLookup($file, $rtlSelector, 'transform');

        if (! in_array($closed, $base, true)) {
            $wrong[] = sprintf('%s | %s: closed position is now [%s], this test expects %s — if the panel was redesigned, update the table.',
                $file, $selector, implode(', ', $base), $closed);

            continue;
        }

        if ($rtl === []) {
            $wrong[] = sprintf('%s | %s: no `%s { transform: %s }`. In Arabic this panel slides in from the wrong side.',
                $file, $selector, $rtlSelector, $mirrored);

            continue;
        }

        if (! in_array($mirrored, $rtl, true)) {
            $wrong[] = sprintf('%s | %s: `%s` sets transform to [%s], expected %s (the same distance, the other way).',
                $file, $selector, $rtlSelector, implode(', ', $rtl), $mirrored);
        }
    }

    expect($wrong)->toBe([], "Off-canvas panels that do not mirror:\n".implode("\n", $wrong));
});

it('never lets the [dir="rtl"] override outrank the rule that opens the panel', function () {
    // `[dir="rtl"] .drawer` and `.drawer.on` both score (0,2,0). Source order
    // decides, so an override written BELOW the .on rule re-applies the closed
    // transform to an open drawer and the panel never appears in Arabic. This
    // is not hypothetical: it is the first way this lane wrote these rules.
    $wrong = [];

    foreach (rtlOffCanvasPanels() as [$file, $selector, $closed, $rtlSelector, $mirrored, $openSelector]) {
        if ($openSelector === null) {
            continue;
        }

        $openLine = rtlMirrorLine($file, $openSelector, 'transform');
        $rtlLine = rtlMirrorLine($file, $rtlSelector, 'transform');

        if ($openLine === null || $rtlLine === null) {
            continue;    // covered by the test above
        }

        if ($rtlLine > $openLine) {
            $wrong[] = sprintf('%s: `%s` is at line %d, BELOW `%s` at line %d. Equal specificity, so the later rule wins — in Arabic this panel stays shut when it is opened. Move the override above the open rule.',
                $file, $rtlSelector, $rtlLine, $openSelector, $openLine);
        }
    }

    expect($wrong)->toBe([], implode("\n", $wrong));
});

it('does not flip the centring idiom', function () {
    // left:50% + translateX(-50%) states a position, not a direction: the
    // transform is physical in both directions, so the inset has to be too.
    // Converting it would resolve to right:50% in RTL while translateX still
    // pulled left, putting the toast half its own width off centre. MEASURED in
    // Chromium at 900px wide: the toast spans 397.5..502.5, centre 450.0.
    //
    // This is the one group where the correct action was to do nothing, so what
    // is pinned is the nothing: no [dir="rtl"] rule may target any of them.
    $centred = [];

    foreach (CssDirection::markdownTable((string) file_get_contents(base_path('docs/rtl-audit.md')), 'rtl-audit:physical') as $row) {
        [$file, $selector, , $why] = $row;

        if (trim($why) === 'centre') {
            $centred[trim($file, '` ')][] = trim($selector, '` ');
        }
    }

    expect($centred)->not->toBeEmpty('docs/rtl-audit.md no longer records any centring declarations, so this test guards nothing.');

    $flipped = [];

    foreach ($centred as $file => $selectors) {
        foreach (rtlMirrorDeclarations($file) as $d) {
            if (! str_contains($d['selector'], '[dir="rtl"]')) {
                continue;
            }

            $bare = trim(str_replace('[dir="rtl"]', '', $d['selector']));

            if (in_array($bare, $selectors, true)) {
                $flipped[] = sprintf('%s | %s sets %s. Centring is not a direction — see the measurement in this test.',
                    $file, $d['selector'], $d['property']);
            }
        }
    }

    expect($flipped)->toBe([], implode("\n", $flipped));
});

it('parks the off-screen honeypots on the opposite edge in RTL', function () {
    // MEASURED: in a right-to-left document the scrollable overflow region
    // extends LEFT, so `left:-9999px` gives documentElement scrollWidth 10899
    // against clientWidth 900 and a scrollLeft range of -9999..0. A shopper gets
    // ten thousand pixels of blank page they can scroll into. Parking it off the
    // inline START edge instead (the right, in RTL) is the side that is
    // discarded: scrollWidth == clientWidth, scrollLeft range 0..0.
    $parked = [];

    foreach (CssDirection::SCOPE as $rel) {
        foreach (rtlMirrorDeclarations($rel) as $d) {
            if ($d['property'] === 'left' && str_starts_with($d['value'], '-9999px')) {
                $parked[] = [$rel, $d['selector']];
            }
        }
    }

    expect($parked)->not->toBeEmpty('No off-screen-parked element found at all; this test has stopped guarding anything.');

    $unfixed = [];

    foreach ($parked as [$rel, $selector]) {
        $start = rtlMirrorLookup($rel, '[dir="rtl"] '.$selector, 'inset-inline-start');
        $end = rtlMirrorLookup($rel, '[dir="rtl"] '.$selector, 'inset-inline-end');

        if (! in_array('-9999px', $start, true) || ! in_array('auto', $end, true)) {
            $unfixed[] = sprintf('%s | %s is parked at left:-9999px with no `[dir="rtl"] %s { inset-inline-start:-9999px; inset-inline-end:auto }`. In Arabic that is a ten-thousand-pixel horizontal scroll.',
                $rel, $selector, $selector);
        }
    }

    expect($unfixed)->toBe([], implode("\n", $unfixed));
});

it('mirrors the free-delivery bar as one unit rather than sprite by sprite', function () {
    // .ffill is an ordinary in-flow block, so it starts at the track's right
    // edge and grows LEFTWARD the moment <html dir> is rtl, with no CSS change.
    // Everything pinned to it — the jade pulse dot, the comet rider, the burst
    // anchor — is positioned physically against the fill's leading edge, and
    // stayed at the far right: MEASURED, the comet sat at [866.8, 883] while the
    // fill's leading edge was at 732. A comet flying backwards.
    //
    // The fix is one rule on .ftrack: direction:ltr puts the track back into the
    // coordinate system its 16 declarations were written for, and scaleX(-1)
    // mirrors the finished picture. So what is pinned here is both halves of
    // that rule AND the absence of per-sprite overrides, which would mirror
    // twice and put every sprite back where it started.
    $file = 'resources/css/kbb/kbb-checkout.css';
    $track = '[dir="rtl"] .kbb-checkout .ftrack';

    $half = [];

    if (! in_array('ltr', rtlMirrorLookup($file, $track, 'direction'), true)) {
        $half[] = "$track must set direction:ltr — without it the fill still grows from the inline-start edge and scaleX(-1) mirrors it straight back to where it started.";
    }

    if (! in_array('scaleX(-1)', rtlMirrorLookup($file, $track, 'transform'), true)) {
        $half[] = "$track must set transform:scaleX(-1) — direction:ltr on its own un-mirrors the bar completely, which is worse than leaving it alone.";
    }

    expect($half)->toBe([], implode("\n", $half));

    // No sprite may carry its own [dir="rtl"] rule: the unit is the track.
    $doubled = [];

    foreach (CssDirection::markdownTable((string) file_get_contents(base_path('docs/rtl-audit.md')), 'rtl-audit:physical') as $row) {
        [$rowFile, $selector, , $why] = $row;

        if (trim($why) !== 'fill-bar') {
            continue;
        }

        $bare = trim($selector, '` ');

        foreach (rtlMirrorDeclarations(trim($rowFile, '` ')) as $d) {
            if ($d['selector'] === '[dir="rtl"] '.$bare) {
                $doubled[] = sprintf('%s | [dir="rtl"] %s sets %s. The track is mirrored whole; a sprite override on top of it mirrors twice.',
                    trim($rowFile, '` '), $bare, $d['property']);
            }
        }
    }

    expect($doubled)->toBe([], implode("\n", $doubled));
});

it('keeps the country select chevron and its padding on the same side', function () {
    // background-position has no logical keyword, so the image cannot follow the
    // padding on its own. Padding on one side and the chevron on the other puts
    // the chevron on top of the country name.
    $file = 'resources/css/kbb/kbb-checkout.css';
    $selector = '#billing_country_field select.input-text';

    $wrong = [];

    if (! in_array('36px', rtlMirrorLookup($file, $selector, 'padding-inline-end'), true)) {
        $wrong[] = "$selector must reserve the chevron's room with padding-inline-end, or in Arabic the room stays on the left while the chevron moves.";
    }

    if (! in_array('right 12px center', rtlMirrorLookup($file, $selector, 'background-position'), true)) {
        $wrong[] = "$selector no longer draws its chevron at `right 12px center`; if the control was restyled, restate both sides of this pair.";
    }

    if (! in_array('left 12px center', rtlMirrorLookup($file, '[dir="rtl"] '.$selector, 'background-position'), true)) {
        $wrong[] = "[dir=\"rtl\"] $selector must draw the chevron at `left 12px center`: padding-inline-end resolves to the left edge in RTL, so the image has to be there too.";
    }

    expect($wrong)->toBe([], implode("\n", $wrong));
});
