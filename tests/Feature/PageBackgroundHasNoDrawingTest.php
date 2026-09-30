<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\PageWash;
use App\Services\SettingsService;

/**
 * The shop's page background carries no drawing and does not tile. (Lane BG)
 *
 * ── WHY THIS FILE EXISTS RATHER THAN A LINE IN AN EXISTING ONE ─────────────
 *
 * The owner rejected the background in four words that are four different
 * things:
 *
 *   "you put some image on background"  -> var(--bg-botanical) in the body rule
 *   "not continue type"                 -> background-repeat:repeat-y
 *   "from one corner to another"        -> linear-gradient(180deg, …)
 *   "change continues slightly"         -> the wash, which he has asked for twice
 *
 * The first two are decisions and are applied. The third is what he is still
 * CHOOSING — five candidates in docs/BG-BACKGROUND-CANDIDATES.md — so the
 * gradient is a variable here and picking a letter replaces one declaration.
 *
 * ── AND StorefrontEnglishUnchangedTest CANNOT SEE ANY OF IT ────────────────
 *
 * That walk compares RENDERED HTML. This change is entirely in a stylesheet and
 * in a `:root` variable, so every page's markup is byte-identical and the walk
 * stays green whatever happens to the background. It is the right instrument
 * for the markup and the wrong one for this; a stylesheet change needs a test
 * that reads the stylesheet and the rendered `background-*` properties.
 *
 * ── MUTATION NOTES, all four run in this lane's worktree ───────────────────
 *
 *   - put `var(--bg-botanical),` back in kbb.css's body rule
 *       → 'kbb.css draws the botanical on the page background again'
 *   - put `repeat-y` back in that rule
 *       → 'the page background tiles down the page again'
 *   - change the gradient in kbb.css only, not in the partial
 *       → StandaloneDocumentHeadTest names both files
 *   - change SCHEMA['motif']'s default to true
 *       → 'the botanical drawing ships ON'
 */

/** kbb.css's page-background body rule, cut out of the stylesheet. */
function pbBodyRule(): string
{
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $start = strpos($css, 'body{'."\n".'  background-color:#FDEFF3;');

    expect($start)->not->toBeFalse('kbb.css no longer has a page-background body rule at all');

    return substr($css, (int) $start, (int) strpos($css, '}', (int) $start) - (int) $start + 1);
}

it('draws no image on the page background and does not tile it', function () {
    $rule = pbBodyRule();

    expect(str_contains($rule, '--bg-botanical'))->toBeFalse(
        'kbb.css draws the botanical on the page background again. The owner asked for it off:'
        .' "you put some image on background of the whole site, which i don\'t want". It comes'
        ." back through Appearance → Page background → Botanical drawing.\n  rule: ".$rule);

    expect(str_contains($rule, 'data:image'))->toBeFalse(
        'the page background carries an image again, whichever variable it came from');

    expect(str_contains($rule, 'repeat-y'))->toBeFalse(
        'the page background tiles down the page again -- the "not continue type" the owner'
        ." asked to be rid of.\n  rule: ".$rule);

    /*
     * AND SOMETHING STILL PAINTS THE PAGE, so the assertions above are not
     * passing because the background was deleted rather than cleaned up.
     *
     * ▲ IT IS NO LONGER THE BODY RULE THAT PAINTS IT.          (Lane BG)
     * The owner chose "Corner light" and it is painted on `html::before`, a
     * fixed-position layer, so `body` carries a flat colour and
     * `background-image:none`. Asserting the gradient on the body rule was
     * right until that moved and would now be asserting the old structure.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    expect((bool) preg_match('/html::before\{[^}]*var\(--kbb-page-gradient\)[^}]*\}/', $css))->toBeTrue(
        'nothing paints the page background any more, so this file is asserting the absence of'
        .' things from a page that draws nothing. The gradient lives on html::before.');

    /*
     * AND NO LAYER PAINTS A DRAWING EITHER. The three layers are where the page
     * background lives now, so "no image on the background" has to be asked of
     * them and not only of `body` -- otherwise putting --bg-botanical back on
     * html::before is caught only by the assertion above, which then reports
     * "nothing paints the background", which is not what went wrong.
     */
    preg_match_all('/(?:html|body)::(?:before|after)\s*\{[^}]*\}/', $css, $layers);

    foreach ($layers[0] as $layer) {
        expect(str_contains($layer, '--bg-'))->toBeFalse(
            'a page-background layer draws one of the motifs again. The owner asked for the'
            ." drawing off the background of the site.\n  ".$layer);

        expect(str_contains($layer, 'data:image'))->toBeFalse(
            "a page-background layer carries an image again.\n  ".$layer);
    }

    expect(str_contains($rule, 'background-image:none'))->toBeTrue(
        'the page-background body rule no longer says background-image:none, so a body image'
        .' could be reintroduced without this file noticing');
});

it('leaves the artwork itself alone, because three other rules still use it', function () {
    /*
     * The four motifs are NOT deleted, and that is deliberate rather than
     * timid: --bg-botanical is still drawn on `.kbb-home .about .im` and
     * `.kbb-home footer::before`, --bg-petals on the section headers and card
     * thumbnails, --bg-corner and --bg-wave on the quiz panel. None of those is
     * "the background of the whole site", which is what he objected to.
     *
     * A lane that reads "he does not want the drawing" and deletes the
     * declarations breaks four home-page rules. This says so.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    foreach (['--bg-botanical', '--bg-petals', '--bg-corner', '--bg-wave'] as $motif) {
        expect(substr_count($css, $motif.':'))->toBe(1,
            $motif.' is no longer declared exactly once in kbb.css');

        expect(substr_count($css, 'var('.$motif.')'))->toBeGreaterThan(0,
            $motif.' is declared but nothing uses it. If the rules that drew it have gone, the'
            .' declaration should go with them rather than sit there as dead weight.');
    }

    // The page background is not among the users any more.
    expect(str_contains(pbBodyRule(), 'var(--bg-'))->toBeFalse(
        'the page background is drawing one of the motifs again');
});

it('ships the botanical control OFF, and emits nothing at all while it is', function () {
    /*
     * CLAUDE.md rule 1, as the owner reversed it on 30 September: a thing he
     * asked for is the shop's new state, "but build the control anyway -- he
     * may want it back, and a change with no way to undo it is worse than no
     * change". This is the half that says the control exists AND that it costs
     * a shop that never touches it exactly nothing.
     */
    expect(PageWash::SCHEMA['motif'][0])->toBe('bool');
    expect(PageWash::SCHEMA['motif'][2])->toBeFalse('the botanical drawing ships ON');

    $wash = app(PageWash::class);

    expect($wash->get('motif'))->toBeFalse()
        ->and($wash->css())->toBe('', 'a shop that has touched nothing emits a stylesheet');
});

it('puts the drawing back when the owner asks for it, without the wash', function () {
    /*
     * The control is not gated on `on`: the drawing and the colour wash are
     * separate questions and he may want one without the other. MEASURED on the
     * preview: with motif on and the wash off, css() is 206 bytes and the page
     * carries `var(--bg-botanical)` again.
     */
    $wash = app(PageWash::class);
    $wash->save(['motif' => true]);

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();

    $css = app(PageWash::class)->css();

    expect($css)->toContain('var(--bg-botanical)')
        ->and($css)->toContain('repeat-y');

    expect(app(PageWash::class)->get('on'))->toBeFalse(
        'turning the drawing on also turned the colour wash on, which is not what it says');
});

it('gives every storefront page one background layer, with no image in it', function () {
    /*
     * Read off the RENDERED page rather than the stylesheet, because that is
     * where the cascade is decided -- kbb-shop.css and kbb-product.css are
     * pushed onto @stack('styles') AFTER kbb.css, which is how /shop/ came to
     * be white for months while the home page was pink.
     *
     * MEASURED in Chromium before and after this change, at 1280 and 390:
     *   before   2 layers, motif present, background-repeat "repeat-y, no-repeat"
     *   after    1 layer,  motif absent,  background-repeat "no-repeat"
     * on the home page, /shop/, a product page, the cart and the journal.
     */
    $pages = ['/', '/shop/', '/skincare-guide/'];

    foreach ($pages as $path) {
        $html = (string) $this->followingRedirects()->get($path)->getContent();

        expect(str_contains($html, 'data:image/svg+xml;utf8,%3Csvg'))->toBeFalse(
            $path.' still ships an inline SVG background image in its head');
    }

    /*
     * The journal carries its own copy of the background and has to follow, or
     * it is the one page still wearing the old one. It is the reason this case
     * asks for /skincare-guide/ as well as the two layout pages.
     */
    $journal = (string) $this->followingRedirects()->get('/skincare-guide/')->getContent();

    expect($journal)->toContain('id="kbb-page-background"')
        ->and($journal)->toContain('var(--kbb-page-gradient)');

    expect(str_contains($journal, 'repeat-y'))->toBeFalse(
        'the journal still tiles its background, so the partial did not follow kbb.css');
});

it('keeps every colour the shipped background can show above the contrast floor', function () {
    /*
     * THE INVARIANT, ASKED OF WHAT ACTUALLY SHIPS.              (Lane BG)
     *
     * PageWashContrastTest enumerates all 15,876 colours the WASH can produce
     * and requires none darker than PageWash::CONTRAST_FLOOR (#FCE7EE,
     * luminance 0.8402) — the darkest flat background any storefront page
     * renders. The shop's own background is now a gradient the owner chose, and
     * nothing was asking the same question of it.
     *
     * It is a smaller set and can simply be read out of the stylesheet: three
     * moments of four stops, plus the flat colour under them. Every one of the
     * thirteen is checked, so a lane that deepens the pink to "make it show
     * more" — which is exactly the temptation, given that his own panels cover
     * 100% of the home page's first screen — fails here rather than on somebody
     * squinting at a screenshot.
     *
     * THREE COLOURS ALREADY FAILED THIS ONCE, before they shipped: candidate
     * b's #FBE2EE (0.8107) and candidate d's #FCE6F1 (0.8364) and #F8DEEC
     * (0.7826) were walked toward white until they cleared it. The one the
     * owner picked carries the corrected values.
     *
     * MUTATION: change any stop to #FBE2EE → red, naming the stop and both
     * luminances. Run.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $floor = PageWash::luminance(pbHex(PageWash::CONTRAST_FLOOR));

    preg_match_all('/--kbb-page-gradient[a-z-]*:[^;]+;/', $css, $declarations);

    expect($declarations[0])->toHaveCount(3,
        'the shipped background is no longer three gradient moments, so this guard is not'
        .' checking what the shop draws');

    $checked = 0;
    $tooDark = [];

    foreach ($declarations[0] as $declaration) {
        $name = substr($declaration, 0, (int) strpos($declaration, ':'));

        preg_match_all('/#[0-9A-Fa-f]{6}/', $declaration, $stops);

        expect(count($stops[0]))->toBe(4, $name.' no longer has four stops');

        foreach ($stops[0] as $hex) {
            $checked++;
            $luminance = PageWash::luminance(pbHex($hex));

            if ($luminance < $floor) {
                $tooDark[] = sprintf('  %s stop %s has luminance %.4f, below the floor %s (%.4f)',
                    $name, $hex, $luminance, PageWash::CONTRAST_FLOOR, $floor);
            }
        }
    }

    // the flat colour under the layers, which is what shows for the one frame
    // before they paint and on any engine that drops them
    preg_match('/background-color:(#[0-9A-Fa-f]{6});/', $css, $flat);

    expect($flat[1] ?? null)->not->toBeNull('the page background has no flat colour under its layers');

    $checked++;
    $flatLuminance = PageWash::luminance(pbHex($flat[1]));

    if ($flatLuminance < $floor) {
        $tooDark[] = sprintf('  the flat colour %s has luminance %.4f, below the floor',
            $flat[1], $flatLuminance);
    }

    expect($checked)->toBe(13, 'expected twelve stops and one flat colour, checked '.$checked);

    expect($tooDark)->toBe([], "the page background can show a colour darker than the shop's own"
        ." floor:\n".implode("\n", $tooDark)
        ."\n\nEvery text colour on the shop is set against that floor. A background below it costs"
        .' contrast on every page at once, which is the one thing this lane guaranteed the wash'
        .' would never do and the background must not either.');
});

/** '#RRGGBB' as the [r, g, b] triple PageWash::luminance() takes. */
function pbHex(string $hex): array
{
    $hex = ltrim($hex, '#');

    return [
        (int) hexdec(substr($hex, 0, 2)),
        (int) hexdec(substr($hex, 2, 2)),
        (int) hexdec(substr($hex, 4, 2)),
    ];
}
