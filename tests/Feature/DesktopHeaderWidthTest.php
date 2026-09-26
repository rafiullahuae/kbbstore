<?php

declare(strict_types=1);

use App\Services\HeaderSettings;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use Tests\Support\CssDirection;

/**
 * THE DESKTOP HEADER: AS WIDE AS THE SITE, ON ONE ROW, SHRINKING RATHER THAN
 * REARRANGING.                                                        Lane H1
 *
 * The owner asked for three things in one sentence — "the header need to be
 * matched the width, and also adjusted as per the screen wihout line breaking
 * etc. it should also capable to ajust / reduce the sizes of the stuff present
 * the same layout in small screens ... just do these for desktops" — and this
 * file pins each of them, plus the fourth thing he asked for by asking for
 * nothing: that the dedicated mobile header does not move.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * DEFECT 1. "HEADER FOLLOWS THE SITE WIDTH" WAS A SWITCH THAT MOVED NOTHING.
 * ═════════════════════════════════════════════════════════════════════════════
 *
 * Appearance → Site layout → Page width has shipped a switch called "Header
 * follows the site width" since Lane W1. Turning it on wrote
 * `:root{--hd-max:var(--site-max)}` out of SiteLayout::cssVariables().
 *
 * HeaderSettings::cssVariables() writes the SAME property into the `style`
 * attribute of the `<header>` element itself, on every request, whether or not
 * anything has ever been saved. An inline declaration on the element beats a
 * `:root` declaration outright — it is not a specificity contest it can lose,
 * it is a stronger origin — and `header .wrap`, the ONLY reader of `--hd-max`
 * in this repository, is a child of `<header>`, so it inherited the inline
 * value and never saw the `:root` one at all.
 *
 * MEASURED IN CHROMIUM ON /shop/, WITH THE SWITCH SAVED ON:
 *
 *      viewport    header .wrap    the page container
 *        1280          1280              1280
 *        1680          1280              1680        <- 400px narrower
 *        1920          1280              1680
 *
 * The admin screen reported the feature as on. Nothing errored, nothing logged,
 * and the header was a 1280px island in the middle of a 1680px page — the logo
 * starting 198px to the right of the "Shop all" heading under it, which is what
 * the owner was looking at when he wrote the sentence above.
 *
 * `--hd-max` has one writer now, at the level that wins, and the switch decides
 * what that writer emits. See HeaderSettings::maxWidthCss().
 *
 * ── AND IT NOW SHIPS ON, WHICH IS A DELIBERATE DEFAULT CHANGE ────────────────
 *
 * Rule 1 says a new setting ships at the value the page already has, and that
 * the one exception is a default the owner asked for in as many words, called
 * out rather than buried. "the header need to be matched the width" is that, in
 * the same voice as the "site width max i need 1680 px" that made `max` 1680.
 * It is called out in the commit, in the migration, in SiteLayout's own header
 * and here. One click on that screen puts it back to 1280px.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * DEFECT 2. THE NAV BAR SPENT A THIRD OF ITS ROW ON WHITESPACE AND PAID FOR IT
 * IN TYPE SIZE.
 * ═════════════════════════════════════════════════════════════════════════════
 *
 * Measured on the twelve-entry menu this shop ships, wrapping suspended and
 * --nav-scale at 1, the row needs 1325px: 979px of words, 312px of `.navlink`
 * side padding, 22px of gaps between the items and 12px inside them.
 *
 * nav-fit.js then multiplies EVERY one of those by one scale until the row
 * fits, so a bar that is short of room shrinks its words and its whitespace by
 * the same proportion. At a 1024px viewport, where the bar has 980px, that
 * meant 9.48px type — with 9.48px of padding on each side of each item, twelve
 * times over, which is 227px of the 980 spent on space between words that were
 * by then too small to read.
 *
 * Whitespace is the cheaper thing to spend. `--nav-pad-x` and `--nav-item-gap`
 * in kbb.css taper it against `--hd-room`, the bar's real content width, in
 * CSS, on one render, with no script and no breakpoint. Measured on /shop/:
 *
 *      viewport    nav font      .navlink padding    rows    documentElement
 *                  before/after     before/after             scrollWidth-clientWidth
 *        1024      9.48 / 10.75    9.48 / 4.96        1 / 1        0 / 0
 *        1180     10.97 / 11.86   10.97 / 7.87        1 / 1        0 / 0
 *        1280     11.95 / 12.52   11.95 / 9.93        1 / 1        0 / 0
 *        1366     11.95 / 13.00   11.95 / 11.76       1 / 1        0 / 0
 *        1440     11.95 / 13.00   11.95 / 13.00       1 / 1        0 / 0
 *        1680     11.95 / 13.00   11.95 / 13.00       1 / 1        0 / 0
 *        1920     11.95 / 13.00   11.95 / 13.00       1 / 1        0 / 0
 *
 * Both tokens reach the design values at a 1440px viewport, so every desktop
 * from there up renders the bar at exactly the sizes it rendered before, and
 * the taper exists only below it — where the alternative was smaller words.
 * From 1366 up nothing has to scale at all any more, because `--hd-max` stopped
 * being 1280. The bar is 45.5px tall at every width, before and after.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * DEFECT 3. THE SUPPORT BLOCK LEFT THE HEADER ON A NARROW DESKTOP.
 * ═════════════════════════════════════════════════════════════════════════════
 *
 * `@media(max-width:1080px){.hinfo{display:none}}` dropped the WhatsApp block —
 * the shop's contact route — between 901px and 1080px, 1024 among them. That is
 * rearranging, which is the thing the owner asked for the opposite of.
 *
 * It was not there for room. Measured on /shop/ at 1024 with the block forced
 * back on: `.hin` is ONE row, 46px tall, documentElement.scrollWidth equal to
 * clientWidth, with logo 163.7 + search 403.9 + icons 132 + support 186.4 and
 * three 24px gaps — 958px inside a 980px content box. `.sbox` is `flex:1 1 auto`
 * and absorbs the difference.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * THE BOUNDARY, AND THE PROOF THE MOBILE HEADER DID NOT MOVE.
 * ═════════════════════════════════════════════════════════════════════════════
 *
 * The dedicated mobile header is everything under `@media (max-width: 900px)`;
 * the desktop header is `@media (min-width: 901px)` and its NAV BAR needs
 * 1001px, because `.mbar{display:none}` lives in `@media(max-width:1000px)`.
 *
 * A screenshot cannot make the "untouched" claim on this shop — the home page's
 * hero carousel and its lazy images mean two runs of the SAME build produce
 * different PNG bytes at 390 and 768, which was measured. So the claim is made
 * on computed styles, by tools/h1-header-styles.cjs, which reads every rendered
 * property of all 24 elements the header is built from at 320, 390, 414, 600,
 * 768, 820 and 900 on /, /shop/ and /ar/shop/ and diffs the two builds:
 *
 *      188,055 properties compared
 *          210 differ
 *            0 rendered rects differ
 *            0 documentElement widths differ
 *
 * and all 210 are one of two harmless kinds — a `max-width` an order of
 * magnitude above the viewport, or `.navlink` padding on an element inside a
 * `display:none` bar. Not one pixel of the mobile header moves. The numbers are
 * in docs/H1-DESKTOP-HEADER-WIDTH.md.
 */

/** kbb.css, with its comments masked, as a list of declarations-in-context. */
function h1Declarations(): array
{
    static $cache = null;

    return $cache ??= CssDirection::declarationsInFile(
        base_path('resources/css/kbb/kbb.css')
    );
}

/**
 * Every rule that declares one property on one selector, as the FULL
 * selector-in-context string CssDirection builds from the nesting stack —
 * `@media(max-width:900px) .hinfo` for a rule inside a media query, `.hinfo`
 * for one outside it.
 *
 * THE CONTEXT IS THE WHOLE POINT OF THIS HELPER. The mobile/desktop boundary
 * in this file is entirely a question of WHICH at-rule a rule sits in, and
 * `str_contains($css, '.hinfo{display:none}')` cannot tell the desktop rule
 * this lane deleted from the phone rule it must not touch.
 *
 * ▲ AND IT RETURNS THE FULL STRING RATHER THAN THE PRELUDE, because splitting
 * the prelude off is not reliably possible from the squashed form and the
 * first draft got it wrong twice. Splitting at the first space read
 * `@media (min-width: 901px) .hin` as the context `@media`. Splitting from the
 * end on ' .hinfo' then matched `@media(max-width:900px) .kbb-home .hinfo` as
 * well, and reported the phone's rule for the home page as a desktop rule that
 * had to go. Comparing whole strings against an expected SET has neither
 * failure mode and says more when it is red.
 *
 * @return list<string> sorted, so the assertion does not depend on file order
 */
function h1RulesTargeting(string $selector, string $property, ?string $value = null): array
{
    $out = [];

    foreach (h1Declarations() as $d) {
        if ($d['property'] !== strtolower($property)) {
            continue;
        }

        if ($d['selector'] !== $selector && ! str_ends_with($d['selector'], ' '.$selector)) {
            continue;
        }

        if ($value !== null && $d['value'] !== $value) {
            continue;
        }

        $out[] = $d['selector'];
    }

    sort($out);

    return array_values(array_unique($out));
}

/**
 * A shorthand value split into its components, respecting parentheses, so that
 * `13px calc(var(--nav-pad-x, 13px) * var(--nav-scale))` is TWO components and
 * not five. Written here rather than borrowed from another test file: this one
 * asserts about padding's vertical pair, and a helper that arrives by Pest's
 * autoload order is a dependency that can vanish when a file is renamed.
 *
 * @return list<string>
 */
function h1Components(string $value): array
{
    $parts = [];
    $current = '';
    $depth = 0;

    foreach (str_split($value) as $ch) {
        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
        }

        if ($depth === 0 && ($ch === ' ' || $ch === "\t" || $ch === "\n")) {
            if ($current !== '') {
                $parts[] = $current;
                $current = '';
            }

            continue;
        }

        $current .= $ch;
    }

    if ($current !== '') {
        $parts[] = $current;
    }

    return $parts;
}

/** The one value of one property on one selector, or null. */
function h1Value(string $selector, string $property): ?string
{
    foreach (h1Declarations() as $d) {
        if ($d['selector'] === $selector && $d['property'] === strtolower($property)) {
            return $d['value'];
        }
    }

    return null;
}

/* ═════════════════════════════════ 1. the width reaches the page ═══ */

it('writes the header width where an inline style cannot beat it', function () {
    /*
     * THE ASSERTION THAT WOULD HAVE CAUGHT THE DEAD SWITCH, and it is made on a
     * RENDERED PAGE rather than on either service, because the defect was
     * neither service being wrong: both emitted exactly what they meant to, at
     * origins that never met.
     *
     * MUTATION 1: put `'--hd-max:' . $c['max_width'] . 'px'` back in
     * HeaderSettings::cssVariables() and move the emission back to
     * SiteLayout::cssVariables(). Red here — the rendered header carries
     * `--hd-max:1280px` — and the shop goes back to a 1280px header on a 1680px
     * page. RUN AND CONFIRMED.
     * MUTATION 2: leave HeaderSettings alone and ALSO emit `--hd-max` from
     * SiteLayout. Red on the last expectation, which is the one that says the
     * property has a single writer.
     */
    $html = (string) $this->get('/cart/')->assertOk()->getContent();

    expect(preg_match('/<header[^>]*\sstyle="([^"]*)"/', $html, $m))
        ->toBe(1, 'no <header> with a style attribute on the rendered page');

    $inline = $m[1];

    /*
     * str_contains() rather than expect()->toContain(): Pest's toContain takes
     * a LIST OF NEEDLES, so the explanation passed as a second argument becomes
     * a second thing the string must contain and the case fails with its own
     * message quoted back at it. This file's first run did exactly that on four
     * of eleven cases, which is a green-looking red and worth the note.
     */
    expect(str_contains($inline, '--hd-max:var(--site-max)'))->toBeTrue(
        'the header still writes its own number inline, so Appearance → Site layout → '
        .'Page width → "Header follows the site width" cannot reach the page: measured at '
        .'header .wrap = 1280 against a page container of 1680 at a 1680px viewport. Got: '.$inline
    );

    /*
     * AND THE TOKEN, NOT THE NUMBER. SiteWidthSystemTest found this by mutation:
     * `--hd-max:1680px` looks identical the day it is saved and then freezes, so
     * moving Site width afterwards leaves the header behind with nothing
     * reporting it. The trap moved here with the emission.
     */
    expect($inline)->not->toMatch(
        '/--hd-max:\s*\d/',
        'the header width is a literal number again, so moving Site width will silently leave the header behind'
    );

    /*
     * ONE WRITER, AND IT HAS TO BE ASKED ON A SHOP THAT HAS SAVED SOMETHING.
     *
     * This assertion was written against the page above and a mutation run
     * found it GREEN with the old `:root` emission put back beside the new
     * inline one — the exact two-writer state this lane removed. The reason is
     * that SiteLayout::cssVariables() returns '' the moment isDefault() is
     * true, so on a shop at its shipped values the second writer emits nothing
     * and there is nothing to count. The owner's shop is not at its shipped
     * values, and neither was it when the switch was found dead.
     *
     * So the second writer is made possible before it is ruled out: move Site
     * width, which makes SiteLayout non-default and its `<style id="kbb-layout">`
     * real, and only then count.
     *
     * MUTATION: emit `--hd-max:var(--site-max)` from SiteLayout::cssVariables()
     * as well. Red here, naming both. Without the save above it is GREEN, which
     * is what this note is for. RUN AND CONFIRMED, both ways.
     */
    app(SettingsService::class)->set('layout_max', '1600');
    SettingsService::forgetMemo();

    $saved = (string) $this->get('/cart/')->assertOk()->getContent();

    expect($saved)->toContain('<style id="kbb-layout">');

    expect(substr_count($saved, '--hd-max'))->toBe(
        1,
        '--hd-max is declared more than once on a shop that has saved a width. It had two writers before this '
        .'lane and the weaker one — a :root declaration, against an inline style on the element itself — moved '
        .'nothing at any width while the admin screen reported it as on.'
    );
});

it('ships the switch ON, and says so where a reader will find it', function () {
    /*
     * RULE 1's exception, as an assertion. Both halves matter: the default is
     * `true`, AND a shop that has saved nothing renders the follow — because
     * SiteLayout emits no stylesheet at all while everything is default, so a
     * default that only worked through that stylesheet would be a default that
     * did nothing.
     *
     * MUTATION: set the SCHEMA default back to false. Red on both expectations.
     * RUN AND CONFIRMED.
     */
    expect(SiteLayout::SCHEMA['header_follows'][2])
        ->toBeTrue('"Header follows the site width" no longer ships on');

    // Nothing saved: no layout stylesheet, and the follow anyway.
    expect(app(SiteLayout::class)->css())
        ->toBe('', 'a shop at its defaults must still gain no bytes on any page');

    expect(app(HeaderSettings::class)->cssVariables())
        ->toContain('--hd-max:var(--site-max)');
});

it('gives the header its own width back the moment the switch is turned off', function () {
    /*
     * The switch is reversible and the number it reverts to is Appearance →
     * Header → Bar → Content width, not a literal in the stylesheet. A default
     * the owner did not want has to be one click, or shipping it on was not a
     * defensible choice.
     *
     * MUTATION: make maxWidthCss() ignore the setting and always answer
     * 'var(--site-max)'. Red. RUN AND CONFIRMED.
     */
    $settings = app(SettingsService::class);

    $settings->set('layout_header_follows', '0');
    SettingsService::forgetMemo();

    expect(app(HeaderSettings::class)->cssVariables())
        ->toContain('--hd-max:1280px')
        ->not->toContain('--hd-max:var(--site-max)');

    // And the header's OWN slider is what it reverts to.
    app(HeaderSettings::class)->save(['max_width' => 1440]);
    SettingsService::forgetMemo();

    expect(app(HeaderSettings::class)->cssVariables())->toContain('--hd-max:1440px');
});

it('keeps the stylesheet default and the shipped default saying the same thing', function () {
    /*
     * The defaults have one home — kbb.css — and PHP emits overrides. That only
     * holds while the two agree: kbb.css declaring 1280px under a PHP default of
     * "follow" is a shop that renders one width with the stylesheet and another
     * with the style attribute, and the difference only shows on a page that
     * somehow renders a header without one.
     *
     * MUTATION: change kbb.css back to `--hd-max:1280px`. Red. RUN AND CONFIRMED.
     */
    expect(h1Value('header', '--hd-max'))
        ->toBe('var(--site-max)', 'kbb.css no longer defaults the header to the site width');

    // The single reader is still the one being aimed at.
    expect(h1Value('header .wrap', 'max-width'))->toBe('var(--hd-max)');
});

/* ═══════════════════ 2. the row shrinks itself, in CSS, on one render ═══ */

it('derives the bar\'s own room from the settings rather than from a number', function () {
    /*
     * --hd-room is `min(100vw, --hd-max) - 2 * --site-gutter`, which is exactly
     * `.wrap`'s content box at every width, with no breakpoint to keep in step.
     *
     * IT MUST REFERENCE THE TOKENS. Written with 1680 and 22 in it, it is right
     * on the day it is written and wrong the moment the owner moves Site width
     * or Side gutter — and wrong SILENTLY, since the bar would go on tapering
     * against a width it no longer has. This is the same trap, on the same
     * screen, that SiteWidthSystemTest records for --hd-max itself.
     *
     * MUTATION: rewrite --hd-room as `calc(min(100vw,1680px) - 44px)`. Red on
     * both references. RUN AND CONFIRMED.
     */
    $room = h1Value('header', '--hd-room');

    expect($room)->not->toBeNull('kbb.css no longer declares --hd-room');
    expect(str_contains((string) $room, 'var(--hd-max)'))
        ->toBeTrue('--hd-room must follow the header width setting, not a number: '.$room);
    expect(str_contains((string) $room, 'var(--site-gutter)'))
        ->toBeTrue('--hd-room must follow the gutter setting, not a number: '.$room);
    expect(str_contains((string) $room, '100vw'))
        ->toBeTrue('--hd-room must fall back to the viewport below the cap: '.$room);
    expect(preg_match('/\d{3,}px/', (string) $room))
        ->toBe(0, '--hd-room carries a hard-coded width: '.$room);
});

it('tapers the whitespace and lets nav-fit keep the last word on the fit', function () {
    /*
     * The two tokens exist, .navlink spends them, and --nav-scale still
     * multiplies them — that last part is what keeps nav-fit.js able to close
     * the remaining few per cent on a menu nobody measured. A taper that
     * REPLACED the scale would be a rendered-once answer that silently wraps the
     * first time an owner adds a thirteenth entry.
     *
     * MUTATION 1: drop `* var(--nav-scale)` from .navlink's padding. Red.
     * MUTATION 2: put the literal `13px` back in .navlink's padding-inline and
     * the literal `6px` back in its gap. Red, and 1024 goes back to 9.48px type.
     * Both RUN AND CONFIRMED.
     */
    foreach (['--nav-pad-x' => 'clamp(', '--nav-item-gap' => 'clamp('] as $token => $needle) {
        $value = h1Value('header', $token);

        expect($value)->not->toBeNull("kbb.css no longer declares {$token}");
        expect(str_contains((string) $value, $needle))->toBeTrue(
            "{$token} must clamp, so the ends are held by the declaration rather than by a breakpoint: ".$value
        );
        expect(str_contains((string) $value, 'var(--hd-room)'))->toBeTrue(
            "{$token} must taper against the bar's own room: ".$value
        );
    }

    $padding = h1Value('.navlink', 'padding');
    $gap = h1Value('.navlink', 'gap');

    expect($padding)->toContain('var(--nav-pad-x')->toContain('var(--nav-scale)');
    expect($gap)->toContain('var(--nav-item-gap')->toContain('var(--nav-scale)');

    /*
     * The hover underline is inset by the link's own side padding so it spans
     * the words and not the box. The day the two disagree it is drawn under the
     * padding on one side and inside the text on the other.
     *
     * MUTATION: leave ::after on the literal 13px while the padding tapers. Red,
     * and at 1024 the rule overhangs the word by 8px on each side.
     */
    foreach (['inset-inline-start', 'inset-inline-end'] as $side) {
        expect(str_contains((string) h1Value('.navlink::after', $side), 'var(--nav-pad-x'))->toBeTrue(
            "the hover underline no longer follows the link's side padding ({$side})"
        );
    }
});

it('keeps the bar exactly as tall at every width the taper touches', function () {
    /*
     * GridPhotoLoadingTest pins that nothing deciding .navlink's HEIGHT follows
     * --nav-scale, because font-size once dragged the line box down with it and
     * scored a failing 0.236 Cumulative Layout Shift on the home page. This
     * lane added two more horizontal tokens, and putting either of them in a
     * vertical slot is that defect again by another name — so they are named
     * here rather than left to the older test, which cannot know about them.
     *
     * MUTATION: change .navlink's padding to
     * `calc(var(--nav-pad-x) * var(--nav-scale)) calc(...)` — the same value in
     * both components. Red, and the bar changes height as the window resizes.
     * RUN AND CONFIRMED.
     */
    $vertical = ['height', 'min-height', 'max-height', 'padding-top', 'padding-bottom', 'line-height'];
    $wrong = [];

    foreach ($vertical as $property) {
        $value = h1Value('.navlink', $property);

        if ($value !== null && preg_match('/--nav-(pad-x|item-gap|scale)/', $value)) {
            $wrong[] = ".navlink {$property} follows a fit token: {$value}";
        }
    }

    // padding's shorthand: components one and three are the vertical pair.
    $padding = (string) h1Value('.navlink', 'padding');
    $parts = h1Components($padding);

    foreach ([0 => 'top', 2 => 'bottom'] as $index => $edge) {
        $part = $parts[$index] ?? $parts[0] ?? '';

        if (preg_match('/--nav-(pad-x|item-gap|scale)/', $part)) {
            $wrong[] = ".navlink padding-{$edge} follows a fit token: {$part}";
        }
    }

    expect($wrong)->toBe([], implode("\n", $wrong));

    // And the line box is still a fixed length, which is what makes the above
    // sufficient rather than merely necessary.
    expect(h1Value('.navlink', 'line-height'))->toMatch('/^\d+(\.\d+)?px$/');
});

/* ══════════════════════════ 3. the desktop keeps its layout ═══ */

it('stops dropping the support block on a narrow desktop', function () {
    /*
     * The 1080px rule is gone and nothing replaced it above the mobile
     * boundary. Read as declarations-in-context rather than as a substring,
     * because the phone's own `.hinfo{display:none}` is a different rule that
     * must stay.
     *
     * MUTATION: put `@media(max-width:1080px){.hinfo{display:none}}` back. Red,
     * naming the width. RUN AND CONFIRMED.
     */
    expect(h1RulesTargeting('.hinfo', 'display', 'none'))->toBe(
        [
            // The dedicated mobile header, and the home page's copy of it. Both
            // stay: below 901px the support block is not this lane's to render.
            '@media(max-width:900px) .hinfo',
            '@media(max-width:900px) .kbb-home .hinfo',
        ],
        'the set of rules that hide the support block has changed. The desktop header must SHRINK rather than drop it — '
        .'measured on /shop/ at a 1024px viewport with it on, .hin is one 46px row with 958px of content in a 980px box '
        .'and documentElement.scrollWidth equal to clientWidth. A rule outside the 900px query puts the 1080px defect back; '
        .'losing one of the two above changes the phone, which the owner said is fine as it is.'
    );
});

it('leaves the mobile boundary exactly where it was', function () {
    /*
     * THE BLAST RADIUS, PINNED. This lane was given the DESKTOP header and the
     * owner said the mobile one is fine. The boundary is two numbers and both
     * of them are somebody else's:
     *
     *   900px   the dedicated mobile header takes over (burger, stacked search)
     *  1000px   `.mbar` hides, which NavBarContainsItsOwnOverflowTest also pins
     *
     * MUTATION: move either number, or reveal `.kbbmi` outside the 900px query.
     * Red, naming which. RUN AND CONFIRMED.
     */
    $wrong = [];

    if (h1RulesTargeting('.mbar', 'display', 'none') !== ['@media(max-width:1000px) .mbar']) {
        $wrong[] = 'the 1000px breakpoint that hides the desktop nav bar has moved or gained a second home.';
    }

    if (h1RulesTargeting('.kbbmi', 'display', 'flex') !== ['@media (max-width: 900px) .kbbmi']) {
        $wrong[] = 'the menu icon is revealed somewhere other than the 900px mobile query.';
    }

    // The desktop side of the boundary, which is where this lane's work lives.
    expect(h1RulesTargeting('.hin', 'gap', '24px'))
        ->toBe(['@media (min-width: 901px) .hin'], 'the desktop header row no longer has a min-width:901px home');

    expect($wrong)->toBe([], implode("\n", $wrong));
});

it('puts nothing the phone would have to undo into a custom property', function () {
    /*
     * A custom property cannot be reverted to its inherited value at a
     * breakpoint: `initial` on one is the guaranteed-invalid value, which turned
     * `repeat(auto-fill, var(--kbb-track))` into a single full-width column on
     * every phone — the trap recorded at the top of kbb.css.
     *
     * So the three tokens this lane adds must live OUTSIDE every media query,
     * and be inert below the boundary by construction rather than by being
     * undone. They are: `.mbar` is display:none under 1001px and `.navlink`,
     * their only consumer, exists nowhere else in this repository.
     *
     * MUTATION: wrap the `header{--hd-room:…}` block in
     * `@media (min-width: 1001px)`. Red — and the phone then has a --nav-pad-x
     * that nothing can take back if a later rule ever needs to.
     */
    foreach (['--hd-room', '--nav-pad-x', '--nav-item-gap'] as $token) {
        expect(h1RulesTargeting('header', $token))
            ->toBe(['header'], "{$token} is declared inside an at-rule; a custom property cannot be undone at a breakpoint");
    }

    // The premise: one consumer, inside the bar the breakpoint hides.
    $views = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('resources/views')));

    foreach ($it as $file) {
        if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), 'class="navlink')) {
            $views[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($views)->toBe(
        ['resources/views/partials/nav-bar.blade.php'],
        '.navlink is rendered somewhere other than the desktop nav bar, so the tokens are no longer inert below 1001px'
    );
});

/* ════════════════════════════════ 4. the shape of the answer ═══ */

it('leaves the nav bar able to catch a menu the stylesheet never measured', function () {
    /*
     * RULE 4 SAYS TO PREFER A RENDERED-ONCE CSS ANSWER, AND THIS LANE WAS SENT
     * TO MAKE ONE. Most of the fit is one now. The last step is not, and this
     * case exists so that the reason is a decision on the record rather than an
     * oversight somebody later "fixes" by deleting the script.
     *
     * Fitting a row to its text requires knowing how wide the text is, and CSS
     * cannot ask: there is no length meaning "the width of this element's
     * content", and container query units measure the CONTAINER — the space
     * available, never the space needed. The alternatives were costed in
     * nav-fit.js's own header; the one that matters here is that a server-side
     * estimate of the label widths cannot be made for the Arabic storefront,
     * whose menu renders in a fallback face this application has no metrics for,
     * and an estimate six pixels light costs a whole second row.
     *
     * So both halves must be present: the CSS taper AND the measurement.
     *
     * MUTATION: delete initNavFit's call site from app.js. Red here, and the
     * shipped twelve-entry menu wraps to two rows at 1280 the day an owner adds
     * one more.
     */
    /*
     * COMMENTS ARE STRIPPED BEFORE COUNTING, and a mutation run is why.
     * Commenting the call out — `/*` + ` initNavFit(); ` + `*` + `/`, the
     * likeliest way for it to die, since somebody "replacing it with CSS"
     * comments it out to check — left `initNavFit()` in the file and this case
     * GREEN while the bar had stopped fitting itself. The same reader is used
     * in NavBarContainsItsOwnOverflowTest, for the same reason.
     *
     * MUTATION: delete line 82 of app.js outright, and separately comment it
     * out. Red both ways now; the second was green before this note existed.
     * RUN AND CONFIRMED.
     */
    $app = (string) file_get_contents(base_path('resources/js/kbb/app.js'));
    $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $app);

    expect(substr_count($code, 'initNavFit()'))->toBe(
        1,
        'the nav bar no longer measures itself, and CSS alone cannot fit a row to text it cannot measure: '
        .'there is no length meaning "the width of this element\'s content", and a container query unit '
        .'measures the space AVAILABLE, never the space needed.'
    );

    // And it is imported from the file that does the measuring, not shadowed
    // by a local stub with the same name.
    expect($code)->toContain("import { initNavFit } from './nav-fit.js';");

    // And the CSS half is the one that renders first, so nothing depends on the
    // script for a bar that already fits.
    expect(h1Value('header', '--nav-pad-x'))->not->toBeNull();

    /*
     * The bar still WRAPS rather than pushing the page sideways when a menu
     * genuinely does not belong on one row. NavBarContainsItsOwnOverflowTest
     * owns that rule; it is named here because this lane's whole subject is
     * "without line breaking", and it would have been easy to read that as
     * permission to delete the only thing standing between an oversized menu
     * and a shop that scrolls horizontally.
     */
    expect(h1Value('.mbar .wrap', 'flex-wrap'))->toBe('wrap');
});
