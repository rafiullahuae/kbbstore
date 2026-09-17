<?php

declare(strict_types=1);

/**
 * Phase 15 — the Desktop/Mobile switches on the delivery strip and the promo
 * ticker, which the hero's own switches silently overrode. (Lane FW)
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────────
 *
 * The hero band is ONE `<section>`. The delivery strip and the promo ticker are
 * `<div>`s inside it, under its `.wrap`, because the band is one visual unit.
 * That wrapper carried `$sections->classFor('hero')` — the HERO's own d-off /
 * m-off — and `.d-off{display:none !important}` takes the whole subtree with
 * it. So:
 *
 *   - hero Desktop off, delivery and ticker Desktop ON  →  both gone on
 *     desktop, their own switches overridden with nothing said;
 *   - hero off on BOTH devices  →  `@unless ($sections->hidden('hero'))` fell
 *     through and took two switched-ON sections off the page entirely.
 *
 * Lane FR measured the first of those and wrote the repair out in
 * docs/FR-HOMEPAGE-ORDER.md rather than applying it. This lane verified the
 * repair, applied it, and measured all of it again in Chromium; the numbers are
 * in docs/FW-APPEARANCE-LEFTOVERS.md. The second case above is one FR's costing
 * named in passing and did not measure — it is the worse of the two, because
 * nothing at all is drawn rather than something being drawn on one device.
 *
 * ── WHAT IS ASSERTED, AND WHY IT IS ASSERTED THIS WAY ───────────────────────
 *
 * Off the RENDERED PAGE, never off the template. A regex over a Blade file
 * reads that file's own comments as code, and store/home.blade.php is more
 * comment than markup; a guard on this project went red on its own comment this
 * week. Everything below reads the document the shopper gets.
 *
 * The browser is not in this suite, so what is asserted here is the pair of
 * facts the browser turns into a picture: which element carries d-off / m-off,
 * and which elements are on the page at all. The computed-display measurements
 * that prove those two facts become the right picture are in the lane report.
 *
 * ── EVERY TEST BELOW WAS RUN AGAINST THE UNFIXED TREE ───────────────────────
 *
 * The mutations and what each one caught are listed in
 * docs/FW-APPEARANCE-LEFTOVERS.md. §1 is the one that passes before AND after
 * and says so: it is the promise that a shop which has not used these switches
 * renders the identical page, which was measured by diffing two fetches as well.
 */

use App\Services\HomepageSections;
use App\Services\SettingsService;

/**
 * Save a visibility payload: every section on, except what is named.
 *
 * @param  array<string, array{desktop?: bool, mobile?: bool}>  $spec
 */
function fwVisibility(array $spec): void
{
    $payload = [];
    $order = 0;

    foreach (HomepageSections::REGISTRY as $key => $row) {
        $payload[$key] = [
            'desktop' => $spec[$key]['desktop'] ?? true,
            'mobile' => $spec[$key]['mobile'] ?? true,
            'order' => $order++,
            'skin' => $row[3],
        ];
    }

    app(HomepageSections::class)->save($payload);
    SettingsService::forgetMemo();
}

function fwHome(): string
{
    SettingsService::forgetMemo();

    return test()->get('/')->getContent();
}

/**
 * The opening tag of the hero BAND — the `<section>` the three rows share.
 *
 * Found by its inline style, which is unique to it in this template and is not
 * something this lane introduced, so the helper cannot be quietly satisfied by
 * the thing it is measuring.
 */
function fwBandTag(string $html): string
{
    $at = strpos($html, 'padding-top:14px');

    if ($at === false) {
        return '';
    }

    $open = strrpos(substr($html, 0, $at), '<');

    return substr($html, $open, strpos($html, '>', $at) + 1 - $open);
}

/** The opening tag of an element by id, or '' when it is not on the page. */
function fwTagById(string $html, string $id): string
{
    $at = strpos($html, 'id="' . $id . '"');

    if ($at === false) {
        return '';
    }

    $open = strrpos(substr($html, 0, $at), '<');

    return substr($html, $open, strpos($html, '>', $at) + 1 - $open);
}

/** The opening tag of the first element carrying this class, or ''. */
function fwTagByClass(string $html, string $class): string
{
    if (preg_match('/<[a-z]+ class="' . preg_quote($class, '/') . '[ "][^>]*>/', $html, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return '';
    }

    return $m[0][0];
}

/* ─────────────────────────── §1 a shop that has not used these switches */

it('renders the hero band byte for byte as it did, with all three rows on', function () {
    // The default: nothing saved at all.
    $html = fwHome();

    // The wrapper's class attribute is UNCHANGED, trailing space and all. It
    // is `class="sec "` because classFor() returns '' for the first section
    // with no divider above it, and bandClassFor() has to return the same ''
    // rather than a tidier answer — a tidier one is a changed page for every
    // shop on earth.
    expect(fwBandTag($html))->toBe('<section class="sec " style="padding-top:14px">');

    // And the slider carries NO class beyond its own. Composed in PHP rather
    // than interpolated into the attribute precisely so this stays true.
    expect(fwTagById($html, 'slider'))->toBe('<div class="slider" id="slider">');

    // All three rows on the page.
    expect(fwTagByClass($html, 'delivery'))->not->toBe('');
    expect(fwTagByClass($html, 'tick'))->not->toBe('');
});

it('still applies the hero row\'s own ordering class and divider mark to the band', function () {
    // The band's class is not just the visibility flags: the wrapper is also
    // the flex child CSS `order` moves and the element the divider mark hangs
    // off. Taking the union must not cost either of those, or this repair
    // silently un-ships Lane FR's.
    $payload = [];
    $order = 0;

    // Hero second, so it is neither the first section (no mark) nor at its
    // template position (so an ordering class is due).
    foreach (['categories', 'hero', 'delivery', 'ticker'] as $key) {
        $payload[$key] = ['desktop' => true, 'mobile' => true, 'order' => $order++, 'skin' => null];
    }

    foreach (HomepageSections::REGISTRY as $key => $row) {
        if (! isset($payload[$key])) {
            $payload[$key] = ['desktop' => true, 'mobile' => true, 'order' => $order++, 'skin' => $row[3]];
        }
    }

    app(HomepageSections::class)->save($payload);
    SettingsService::forgetMemo();

    $sections = app(HomepageSections::class);
    $band = fwBandTag(fwHome());

    expect($band)->toContain('kbb-ord-' . $sections->all()['hero']['order']);
    expect($band)->toContain('dv');
    expect($sections->bandClassFor('hero'))->toBe($sections->classFor('hero'));
});

/* ─────────────────────────── §2 the switch that was being overridden */

it('keeps the delivery strip and the ticker on desktop when only the hero is off there', function () {
    fwVisibility(['hero' => ['desktop' => false]]);

    $html = fwHome();
    $band = fwBandTag($html);

    // The wrapper is the union of the three, so it is NOT hidden on desktop:
    // two of the rows it carries are switched on for desktop.
    expect(str_contains($band, 'd-off'))->toBeFalse(
        'The band carries d-off, so display:none takes the delivery strip and the ticker with it — the defect.'
    );
    expect(str_contains($band, 'm-off'))->toBeFalse('Nothing is switched off for mobile here.');

    // The hero's own switch has to land somewhere, and it lands on the hero's
    // own content. Without this half the union would make the hero unhideable.
    expect(fwTagById($html, 'slider'))->toBe('<div class="slider d-off" id="slider">');

    // And the two rows carry their OWN switches, which are on.
    expect(str_contains(fwTagByClass($html, 'delivery'), 'd-off'))->toBeFalse('The delivery strip is switched on for desktop.');
    expect(str_contains(fwTagByClass($html, 'tick'), 'd-off'))->toBeFalse('The ticker is switched on for desktop.');
});

it('gives the slider the hero\'s two visibility flags and nothing else', function () {
    // The slider is NOT a child of .kbb-home, so an ordering class on it is a
    // declaration the browser accepts and ignores — this file's neighbour
    // HomepageSectionOrderTest makes the same argument about the delivery strip
    // and the ticker. And the divider mark belongs above the WRAPPER: a second
    // one inside it draws the separator twice, inside the card.
    //
    // Neither shows up while the order is the template's own and the hero is
    // the first section, which is why this test moves the hero down the page
    // and turns the divider on above everything first.
    $payload = [];
    $order = 0;

    foreach (['categories', 'hero', 'delivery', 'ticker'] as $key) {
        $payload[$key] = ['desktop' => $key === 'hero' ? false : true, 'mobile' => true, 'order' => $order++, 'skin' => null];
    }

    foreach (HomepageSections::REGISTRY as $key => $row) {
        if (! isset($payload[$key])) {
            $payload[$key] = ['desktop' => true, 'mobile' => true, 'order' => $order++, 'skin' => $row[3]];
        }
    }

    app(HomepageSections::class)->save($payload);
    SettingsService::forgetMemo();

    $html = fwHome();

    // The band takes both, because it is the flex child and the marked element.
    expect(fwBandTag($html))->toContain('kbb-ord-');
    expect(fwBandTag($html))->toContain('dv');

    // The slider takes neither. Asserted as the whole attribute rather than as
    // two absences, because an exact string cannot be satisfied by a typo.
    expect(fwTagById($html, 'slider'))->toBe('<div class="slider d-off" id="slider">');
});

it('does the same on mobile, which is a separate switch and a separate class', function () {
    fwVisibility(['hero' => ['mobile' => false]]);

    $html = fwHome();

    expect(str_contains(fwBandTag($html), 'm-off'))->toBeFalse('The band must survive for the two rows still on for mobile.');
    expect(fwTagById($html, 'slider'))->toBe('<div class="slider m-off" id="slider">');
});

it('hides the delivery strip on the device its own switch is off for, and no other', function () {
    // The other direction: a nested row's own switch still SUBTRACTS. The union
    // decides the wrapper; it must not decide what is inside it.
    fwVisibility(['delivery' => ['desktop' => false]]);

    $html = fwHome();

    expect(str_contains(fwBandTag($html), 'd-off'))->toBeFalse('The hero and the ticker are both on for desktop.');
    expect(fwTagByClass($html, 'delivery'))->toContain('d-off');
    expect(str_contains(fwTagByClass($html, 'tick'), 'd-off'))->toBeFalse('The ticker was not switched off.');
});

/* ─────────────────────────── §3 the harder half: the hero off on both */

it('draws the band for the delivery strip alone when the hero is off on both devices', function () {
    // This is the case that took two switched-ON sections off the page
    // completely: @unless ($sections->hidden('hero')) dropped the wrapper and
    // everything drawn inside it.
    fwVisibility(['hero' => ['desktop' => false, 'mobile' => false]]);

    $html = fwHome();

    expect(fwBandTag($html))->not->toBe('');
    expect(fwTagByClass($html, 'delivery'))->not->toBe('');
    expect(fwTagByClass($html, 'tick'))->not->toBe('');

    // The band is not hidden on either device — both of its remaining rows are
    // on for both.
    expect(str_contains(fwBandTag($html), 'off'))->toBeFalse('Nothing the band still carries is switched off.');

    // The SLIDER is not rendered at all rather than rendered with two hiding
    // classes, because it carries the page's <h1> and a hidden <h1> plus the
    // quiet fallback would be two.
    expect(fwTagById($html, 'slider'))->toBe('');
});

it('drops the band entirely only when all three of its rows are off', function () {
    fwVisibility([
        'hero' => ['desktop' => false, 'mobile' => false],
        'delivery' => ['desktop' => false, 'mobile' => false],
        'ticker' => ['desktop' => false, 'mobile' => false],
    ]);

    $html = fwHome();

    expect(fwBandTag($html))->toBe('');
    expect(fwTagByClass($html, 'delivery'))->toBe('');
    expect(fwTagByClass($html, 'tick'))->toBe('');
    expect(fwTagById($html, 'slider'))->toBe('');

    // Not rendering it also skips its work, which is the rule the rest of this
    // service already follows.
    expect(app(HomepageSections::class)->bandHidden('hero'))->toBeTrue();
});

it('still hides the hero alone when the two rows nested in it are off', function () {
    // The reverse of the case above: nothing nested is left to keep the band
    // alive, so the hero's own switches decide it exactly as before.
    fwVisibility([
        'delivery' => ['desktop' => false, 'mobile' => false],
        'ticker' => ['desktop' => false, 'mobile' => false],
        'hero' => ['desktop' => false],
    ]);

    $html = fwHome();

    expect(fwBandTag($html))->toContain('d-off');
    expect(fwTagByClass($html, 'delivery'))->toBe('');
    expect(fwTagByClass($html, 'tick'))->toBe('');
});

/* ─────────────────────────── §4 exactly one <h1>, in every combination */

it('puts exactly one h1 on the page whatever the three switches say', function () {
    $combinations = [
        [],
        ['hero' => ['desktop' => false]],
        ['hero' => ['mobile' => false]],
        ['hero' => ['desktop' => false, 'mobile' => false]],
        ['hero' => ['desktop' => false, 'mobile' => false], 'delivery' => ['desktop' => false, 'mobile' => false], 'ticker' => ['desktop' => false, 'mobile' => false]],
        ['delivery' => ['desktop' => false, 'mobile' => false], 'ticker' => ['desktop' => false, 'mobile' => false]],
    ];

    foreach ($combinations as $i => $spec) {
        fwVisibility($spec);
        $html = fwHome();

        expect(substr_count($html, '<h1'))->toBe(1, 'combination ' . $i . ' put a different number of <h1>s on the page');
    }
});

it('gives the quiet h1 back when the hero band still renders but the slider does not', function () {
    // $heroCarriesH1 is unchanged by this lane and the slider's own condition
    // is now that same flag rather than a second copy of it, so the two cannot
    // drift apart into two <h1>s or none.
    fwVisibility(['hero' => ['desktop' => false, 'mobile' => false]]);

    $html = fwHome();

    expect($html)->toContain('<h1 class="kbb-h1-quiet">');
    expect(fwTagById($html, 'slider'))->toBe('');
});

/* ─────────────────────────── §5 the service, and the sentence on the screen */

it('takes the union of the three rows for the band and the row\'s own for everything else', function () {
    fwVisibility(['hero' => ['desktop' => false], 'delivery' => ['mobile' => false]]);

    $sections = app(HomepageSections::class);

    // hero desktop off, delivery+ticker desktop on  -> band shows on desktop
    // hero mobile on                                -> band shows on mobile
    expect($sections->bandClassFor('hero'))->toBe('');
    expect($sections->classFor('hero'))->toBe('d-off');
    expect($sections->deviceClassFor('hero'))->toBe('d-off');
    expect($sections->bandHidden('hero'))->toBeFalse();

    // A section with nothing nested in it is the general case, not a special
    // one: its band class is its own class.
    expect($sections->bandClassFor('categories'))->toBe($sections->classFor('categories'));
    expect($sections->bandHidden('categories'))->toBe($sections->hidden('categories'));
});

it('no longer tells the owner that the hero overrides these two rows\' switches', function () {
    // The note is printed verbatim on the row by the console. It used to end
    // "and is hidden on any device the hero itself is switched off for", which
    // described this defect; a screen that keeps saying so after the repair is
    // as wrong as one that never said it.
    $note = HomepageSections::NESTED_NOTE;

    expect(str_contains($note, 'switched off for'))->toBeFalse(
        'The note still describes the override this lane removed.'
    );

    // What remains true, and is what the row still has to say, is that CSS
    // `order` cannot move these two away from their host.
    expect($note)->toContain('hero band');
    expect($note)->toContain('cannot be placed elsewhere');

    // And every nested row is still carrying it.
    foreach (array_keys(HomepageSections::NESTED) as $key) {
        expect(app(HomepageSections::class)->all()[$key]['note'])->toBe($note);
    }
});
