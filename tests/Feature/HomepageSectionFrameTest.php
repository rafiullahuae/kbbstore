<?php

declare(strict_types=1);

/**
 * THE HOMEPAGE'S SECTION PANELS COME OFF, AND EACH ONE CAN HAVE ITS OWN
 * BACK.                                                             (Lane BG)
 *
 * The owner, on the home page, in his own words:
 *
 *   "on homepage i want to remove the sections backgrounds by default, and if
 *    i need it for any section, i can put it myself., rest, any section i can
 *    make full width upto 1920x, give this option and it must be auto adjusted
 *    to the screen sizes below 1920px width, and by default, make the main
 *    images banner full width, and remove the corner radius etc."
 *
 * ── WHAT THE DEFECT LOOKED LIKE ON THE SHOP ─────────────────────────────────
 *
 * Every section of the home page drew a white panel — `rgba(255,255,255,.94)`,
 * 22px of radius, a border and a shadow, with a pink gradient on the four the
 * template marks `tinted`. This lane MEASURED those panels covering 100% of
 * the first screenful at 1280 and 75% at 390 (docs/BG-BACKGROUND-CANDIDATES.md),
 * which is why the page background he had just chosen barely showed on the page
 * he looks at most. He read that measurement and asked for the panels to come
 * off — and for a way to put one back on any single section.
 *
 * ── WHAT IS ASSERTED, AND WHERE FROM ────────────────────────────────────────
 *
 * Two places, because the change is in two places and either half alone is a
 * false green.
 *
 *   THE DOCUMENT   which sections carry `kbb-secbg-on` / `kbb-secw-*`, read
 *                  off the page the shopper gets rather than off the template
 *                  — store/home.blade.php is more comment than markup and a
 *                  guard on this project has already gone red on its own
 *                  comment.
 *
 *   THE STYLESHEET which declarations those classes gate. A class on an
 *                  element with no rule behind it is the "control that does
 *                  nothing" fault CLAUDE.md names three times, and a test that
 *                  asserted only the class would be satisfied by it. The
 *                  browser is not in this suite; the computed colours, widths
 *                  and radii at 320, 390, 1280, 1440, 1920 and 2200 are in
 *                  docs/home-width-shots/.
 *
 * ── THE FALSE GREEN THIS FILE IS WRITTEN AGAINST ────────────────────────────
 *
 * A width assertion passes trivially on a section that rendered no children,
 * and a "no panel" assertion passes trivially on a rule that never applied. So
 * every case that asserts a section's frame ALSO asserts the section is on the
 * page and is not empty — `frameSection()` returns the whole element and the
 * cases check its contents.
 */

use App\Services\HomepageSections;
use App\Services\SettingsService;

/** The home page, with the settings memo dropped first. */
function bgfHome(): string
{
    SettingsService::forgetMemo();

    return test()->get('/')->getContent();
}

/**
 * Save a section payload built from the current configuration, with overrides.
 *
 * Built from all() rather than from the registry so that a case which changes
 * ONE section's background is not also silently resetting the other eighteen —
 * which is the shape of test that passes for the wrong reason.
 *
 * @param  array<string, array<string, mixed>>  $spec
 */
function bgfSave(array $spec): void
{
    $sections = app(HomepageSections::class);
    $payload = [];

    foreach ($sections->all() as $key => $row) {
        $payload[$key] = [
            'desktop' => $row['desktop'],
            'mobile' => $row['mobile'],
            'skin' => $row['skin'],
            'background' => $row['background'],
            'width' => $row['width'],
            'order' => $row['order'],
        ] + ($spec[$key] ?? []);

        foreach ($spec[$key] ?? [] as $k => $v) {
            $payload[$key][$k] = $v;
        }
    }

    $sections->save($payload);
    SettingsService::forgetMemo();
}

/**
 * The whole `<section class="sec …">` element at index $i of the home page.
 *
 * The ELEMENT and not the tag, deliberately: the cases assert on its class
 * attribute AND on what is inside it, because a width or a background read off
 * a section that rendered nothing is the false green named in the header.
 */
function bgfSection(string $html, int $i): string
{
    $at = 0;

    for ($n = 0; $n <= $i; $n++) {
        $at = strpos($html, '<section class="sec ', $at === 0 ? 0 : $at + 1);

        if ($at === false) {
            return '';
        }
    }

    $end = strpos($html, '</section>', $at);

    return $end === false ? '' : substr($html, $at, $end + 10 - $at);
}

/** The class attribute of that element. */
function bgfClass(string $section): string
{
    preg_match('/^<section class="([^"]*)"/', $section, $m);

    return $m[1] ?? '';
}

/** The source stylesheet, as one string. */
function bgfCss(): string
{
    return (string) file_get_contents(base_path('resources/css/kbb/kbb.css'));
}

beforeEach(function () {
    SettingsService::forgetMemo();
});

/* ══════════════════════════════════════════════════════════════════════════
   1. THE PANELS ARE OFF, AND THE STYLESHEET IS WHY
   ══════════════════════════════════════════════════════════════════════════ */

it('draws no section panel on the home page until one is asked for', function () {
    $html = bgfHome();

    expect($html)->not->toContain('kbb-secbg-on');

    /*
     * AND THE PAGE IS REALLY THERE. Without this the assertion above is
     * satisfied by a home page that 500'd, by one that rendered no sections,
     * and by a typo in the class name.
     *
     * MUTATION NOTE: put `'background' => ['default' => 'panel']` back — or
     * simply change SECTION_SCHEMA's `background` default from 'off' to
     * 'panel' — and this goes red on the first expectation, with every section
     * carrying `kbb-secbg-on`.
     */
    $first = bgfSection($html, 0);

    expect($first)->not->toBe('')
        ->and(substr_count($html, '<section class="sec '))->toBeGreaterThan(8)
        ->and(strlen($first))->toBeGreaterThan(200);
});

it('gates every panel declaration behind the class, and nothing else', function () {
    $css = bgfCss();

    /*
     * THE FOUR DECLARATIONS THAT MAKE THE CARD, all on the gated rule and none
     * of them on the base one. This is the half that a class-only assertion
     * cannot see: `kbb-secbg-on` could be emitted perfectly and the panel still
     * paint on every section, because the rule that paints it was left
     * ungated.
     *
     * MUTATION NOTE: move `background:rgba(255,255,255,.94)` from
     * `.kbb-home .sec.kbb-secbg-on > .wrap` back up to
     * `.kbb-home .sec > .wrap` and this goes red — the gated rule no longer
     * carries it and the ungated one does.
     */
    expect($css)->toContain('.kbb-home .sec.kbb-secbg-on > .wrap{')
        ->and($css)->toContain('.kbb-home .sec.tinted.kbb-secbg-on > .wrap{')
        ->and($css)->toContain('.kbb-home .sec.tinted.kbb-secbg-on{');

    // The base rule keeps the block padding — the page's vertical rhythm — and
    // carries none of the paint.
    preg_match('/\.kbb-home \.sec > \.wrap\{\s*padding-top:[^}]*\}/', $css, $base);

    expect($base)->not->toBeEmpty()
        ->and($base[0])->not->toContain('background:')
        ->and($base[0])->not->toContain('border-radius')
        ->and($base[0])->not->toContain('box-shadow')
        ->and($base[0])->not->toContain('border:');

    /*
     * AND THE PANEL'S OWN RULE CARRIES ALL FOUR, so this case cannot be
     * satisfied by deleting the panel outright — which would pass every
     * assertion above and leave the owner with no way back at all.
     */
    preg_match('/\.kbb-home \.sec\.kbb-secbg-on > \.wrap\{[^}]*\}/', $css, $panel);

    expect($panel[0])->toContain('background:rgba(255,255,255,.94)')
        ->and($panel[0])->toContain('border-radius:22px')
        ->and($panel[0])->toContain('box-shadow:')
        ->and($panel[0])->toContain('border:1px solid');
});

it('puts one section’s panel back without touching the rest', function () {
    bgfSave(['newsletter' => ['background' => 'panel']]);

    $html = bgfHome();

    /*
     * EXACTLY ONE. Two would mean the setting is being read for the wrong
     * section as well as the right one; zero would mean the way back does not
     * work, which is the half of his instruction that is not a default move.
     *
     * MUTATION NOTE: change frameClass()'s `=== 'panel'` to `=== 'off'` and
     * this goes red at 18 rather than 1.
     */
    expect(substr_count($html, 'kbb-secbg-on'))->toBe(1);

    $sections = app(HomepageSections::class)->all();

    expect($sections['newsletter']['background'])->toBe('panel')
        ->and($sections['hero']['background'])->toBe('off')
        ->and($sections['bestsellers']['background'])->toBe('off');
});

it('keeps the panel setting when a layout preset is applied', function () {
    bgfSave(['newsletter' => ['background' => 'panel'], 'quiz' => ['width' => 'full']]);

    app(\App\Services\HomepageLayouts::class)->apply('editorial', app(HomepageSections::class));
    SettingsService::forgetMemo();

    /*
     * A PRESET IS "order, visibility and grid styles in one move" — the screen
     * says so — and it has no opinion about either of these. save() REPLACES
     * the stored payload, so without HomepageLayouts::apply() carrying the two
     * values across, pressing Apply on an unrelated preset silently threw away
     * every panel the owner had put back.
     *
     * MUTATION NOTE: delete the foreach in HomepageLayouts::apply() and this
     * goes red — both values fall back to their defaults.
     */
    $sections = app(HomepageSections::class)->all();

    expect($sections['newsletter']['background'])->toBe('panel')
        ->and($sections['quiz']['width'])->toBe('full');
});

/* ══════════════════════════════════════════════════════════════════════════
   2. THE PER-SECTION WIDTH, CAPPED AT 1920 AND FLUID BELOW IT
   ══════════════════════════════════════════════════════════════════════════ */

it('caps a full-width section at 1920px without using vw', function () {
    $css = bgfCss();

    preg_match('/\.kbb-home \.sec\.kbb-secw-full > \.wrap,\s*\.kbb-home \.sec\.kbb-secw-bleed > \.wrap\{([^}]*)\}/', $css, $m);

    expect($m)->not->toBeEmpty();

    /*
     * `width:100%` + `max-width:1920px` IS the min(), and the absence of `vw`
     * is the assertion that matters rather than a stylistic preference:
     * `min(100vw, 1920px)` counts the desktop scrollbar, so a section written
     * that way is wider than the page and grows a HORIZONTAL scrollbar — which
     * narrows the page, which is the oscillation. The measured proof is
     * `documentElement.scrollWidth` equalling the viewport at 320, 390, 1280,
     * 1440, 1920 and 2200 in docs/home-width-shots/after-measurements.json.
     *
     * MUTATION NOTE: write the rule as `width:min(100vw,1920px)` and this goes
     * red on the `vw` expectation.
     */
    expect($m[1])->toContain('width:100%')
        ->and($m[1])->toContain('max-width:1920px')
        ->and($m[1])->not->toContain('vw');

    /*
     * `bleed` is `full` with the PADDING off — both axes, and the block half is
     * the "etc." the owner asked for rather than a flourish. Measured at 1280:
     * with only the side gutter removed the banner still sat 41px below the
     * header, 33 of them the wrap's own `padding-top:clamp(22px,2.6vw,34px)`.
     * With the block padding gone it is 8, which is the section's own rhythm.
     *
     * MUTATION NOTE: drop `padding-top:0;padding-bottom:0` and this goes red;
     * on the shop the banner drops back to a 41px gap under the header, which
     * is docs/home-width-shots/'s "the gap above the banner" paragraph.
     */
    /*
     * ALL the bleed rules joined, not the first one: `bleed` appears twice —
     * once in the selector list it shares with `full` for the cap, and once
     * alone for the padding. A `preg_match` takes the first, which is the cap
     * rule, and this case would then be asserting padding on a rule that has
     * none. (It did, on the first run of this file.)
     */
    preg_match_all('/\.kbb-home \.sec\.kbb-secw-bleed > \.wrap\{([^}]*)\}/', $css, $bleed);

    expect($bleed[1])->toHaveCount(2);

    $bleedDecls = implode(';', $bleed[1]);

    expect($bleedDecls)->toContain('padding-inline-start:0')
        ->and($bleedDecls)->toContain('padding-inline-end:0')
        ->and($bleedDecls)->toContain('padding-top:0')
        ->and($bleedDecls)->toContain('padding-bottom:0');

    // And `full` does NOT take the gutter off, which is the whole difference
    // between the two tokens: a section of text set to full width keeps its
    // words off the glass.
    preg_match('/\.kbb-home \.sec\.kbb-secw-full > \.wrap\{([^}]*)\}/', $css, $fullOnly);

    expect($fullOnly)->toBeEmpty();
});

it('marks only the section that was widened, and it is not empty', function () {
    bgfSave(['quiz' => ['width' => 'full']]);

    $html = bgfHome();

    expect(substr_count($html, 'kbb-secw-full'))->toBe(1);

    /*
     * THE FALSE GREEN, NAMED AND CLOSED. A width class on a section that
     * rendered nothing is a passing assertion about an invisible element, so
     * the element is fetched whole and its contents are asserted: the skin quiz
     * draws a `.wrap` with real markup in it.
     *
     * MUTATION NOTE: change frameClass()'s width arm to
     * `$s['width'] === 'normal' ? 'kbb-secw-full ' : ''` and this goes red at
     * 18 — every section but the one that asked for it.
     */
    $at = strpos($html, 'kbb-secw-full');
    $open = strrpos(substr($html, 0, $at), '<section');
    $end = strpos($html, '</section>', $at);
    $section = substr($html, $open, $end + 10 - $open);

    expect($section)->toContain('<div class="wrap"')
        ->and(strlen(strip_tags($section)))->toBeGreaterThan(40);
});

it('offers neither control on the two sections drawn inside the hero', function () {
    /*
     * `delivery` and `ticker` are `<div>`s inside the hero's own `<section>`.
     * They have no `.wrap` of their own, so neither rule can reach them and a
     * dropdown on those rows would be a control that does nothing — the fault
     * CLAUDE.md names three times and the reason NESTED_NOTE exists one row
     * along.
     *
     * MUTATION NOTE: drop the `isset(self::NESTED[$key])` arms from castRow()
     * and this goes red on the nulls; drop the unset() from sectionTabs() and
     * it goes red on the field counts.
     */
    $rows = app(HomepageSections::class)->all();

    expect($rows['delivery']['background'])->toBeNull()
        ->and($rows['delivery']['width'])->toBeNull()
        ->and($rows['ticker']['background'])->toBeNull()
        ->and($rows['ticker']['width'])->toBeNull();

    // ModuleSchema::tabs() returns `fields` as a LIST of field arrays, each
    // carrying its own `key` — not a map. Reading it as a map is how a test of
    // this shape passes on an empty intersection.
    $names = fn (string $key) => collect(HomepageSections::sectionTabs($key, $rows[$key]))
        ->flatMap(fn ($t) => array_column($t['fields'] ?? [], 'key'))->all();

    expect($names('ticker'))->not->toContain('background')
        ->and($names('ticker'))->not->toContain('width')
        ->and($names('delivery'))->not->toContain('background')
        // the row still carries the controls it always had, so this is not
        // satisfied by sectionTabs() returning nothing at all
        ->and($names('ticker'))->toContain('desktop');

    // And a section that DOES have a wrapper is offered both, so this case
    // cannot be satisfied by dropping the fields for everybody.
    expect($names('hero'))->toContain('background')->toContain('width');
});

it('stores one of its own options or the default, and never a stray token', function () {
    /*
     * Rule 5. The class name is built from the stored value, so a settings row
     * edited by hand to `width => "x{color:red}"` would otherwise print into a
     * class attribute. SECTION_POLICY's `invalid => default` is the door and
     * this is the test of it — from the READ side, which is the side furthest
     * from the validator and the one the storefront goes through.
     *
     * MUTATION NOTE: change SECTION_POLICY's `invalid` from 'default' to
     * 'keep' (or take the `options` key off the two fields) and this goes red.
     */
    app(SettingsService::class)->set('homepage_sections', [
        'quiz' => ['desktop' => true, 'mobile' => true, 'order' => 0,
            'background' => 'panel"><script>', 'width' => 'full;}'],
    ]);
    SettingsService::forgetMemo();

    $rows = app(HomepageSections::class)->all();

    expect($rows['quiz']['background'])->toBe('off')
        ->and($rows['quiz']['width'])->toBe('normal');

    /*
     * READ OFF THE SECTION'S OWN CLASS ATTRIBUTE, not off the whole document:
     * the home page carries several legitimate `<script>` elements, so
     * `expect($html)->not->toContain('<script>')` is a test that can only ever
     * be red. What is actually at stake is whether a stored token can reach a
     * class attribute, and that is what this reads.
     */
    $html = bgfHome();

    preg_match_all('/<section class="([^"]*)"/', $html, $m);

    expect($m[1])->not->toBeEmpty();

    foreach ($m[1] as $class) {
        expect($class)->not->toContain('script')
            ->and($class)->not->toContain('}')
            ->and($class)->not->toContain(';');
    }
});

/* ══════════════════════════════════════════════════════════════════════════
   3. NOTHING OUTSIDE THE HOME PAGE MOVES
   ══════════════════════════════════════════════════════════════════════════ */

it('changes no other page, because no other page draws a .sec', function () {
    /*
     * `.kbb-home` is on four templates — home, collection, page and wishlist —
     * but only store/home.blade.php draws `.sec` elements, and every rule this
     * round adds is `.kbb-home .sec…`. So /shop/, a product page, the cart and
     * the journal cannot be reached by any of it. Asserted rather than argued,
     * because "the selector is narrow" is exactly the claim that turns out to
     * be wrong.
     *
     * MUTATION NOTE: write the panel rule as `.kbb-home > .wrap` and this stays
     * green — which is why the CASE is the class count on those pages and the
     * SELECTOR SHAPE is asserted separately, below.
     */
    foreach (['/shop/', '/cart/'] as $uri) {
        $html = test()->get($uri)->getContent();

        expect($html)->not->toContain('kbb-secbg-on')
            ->and($html)->not->toContain('kbb-secw-')
            // and the page really rendered
            ->and(strlen($html))->toBeGreaterThan(2000);
    }

    $css = bgfCss();

    foreach (['kbb-secbg-on', 'kbb-secw-full', 'kbb-secw-bleed'] as $class) {
        preg_match_all('/[^{}]*\.' . preg_quote($class, '/') . '[^{}]*\{/', $css, $m);

        expect($m[0])->not->toBeEmpty();

        foreach ($m[0] as $selector) {
            expect($selector)->toContain('.kbb-home')
                ->and($selector)->toContain('.sec');
        }
    }
});
