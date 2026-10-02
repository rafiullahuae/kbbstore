<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use Illuminate\Support\Str;
use Tests\Support\SiteLayoutAdminRoutes;

/**
 * =============================================================================
 * PRESS FEEDBACK — "IT LEAVES A GREY BOX"                              Lane RD
 * =============================================================================
 *
 * The owner: "when u click on any button or icon. it leaves gray square /
 * rectangle box instead of changing the color of button icon itself. it give
 * feeling that we put just png images and clickable."
 *
 * THE DEFECT, MEASURED ON THE SHOP. The storefront never set
 * `-webkit-tap-highlight-color`, so every tap on a header icon, the heart, Add
 * to cart or a pill drew the browser's own box over it -- rgba(51,181,229,.4)
 * in Chromium's phone emulation at 390px, rgba(0,0,0,.18) at 1280px, grey on
 * an iPhone -- and no control had a pressed state of its own to replace it.
 *
 * THE FIX. Appearance → Site layout → Press feedback: Off (the shop as it was),
 * A Soft fill, B Pink pop, C Ripple, D Bounce, E Glow ring. It SHIPS AT C, which
 * the owner chose ("set C · Ripple by default"), in the code default and not
 * only on his shop. The choice reaches the page as ONE constant attribute on
 * <html> -- data-press="c" -- and every rule in kbb.css and every line of
 * resources/js/kbb/press.js is keyed by it. Off prints nothing, so Off is the
 * page byte for byte.
 *
 * Every case below carries the mutation that turns it red, and each one was
 * run.
 */
function rdAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Press owner', 'email' => 'rd-'.Str::random(8).'@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);
}

function rdPress(string $value): void
{
    app(SettingsService::class)->set('layout_press', $value);
    SettingsService::forgetMemo();
}

/**
 * The Lane RD block of kbb.css, from the `/*` that opens its banner to its own
 * end marker. Not to the end of the file: the showcase family must stay the
 * file's tail (DefaultCardStyleTest compares it with kbb-grid-skins.css).
 */
function rdSourceCss(): string
{
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $at = strpos($css, 'PRESS FEEDBACK                                                     Lane RD');
    $end = strpos($css, '/* ── end of PRESS FEEDBACK (Lane RD) ── */');

    expect($at)->not->toBeFalse('the press feedback block is missing from kbb.css')
        ->and($end)->not->toBeFalse('the press feedback block has lost its end marker');

    $start = (int) strrpos(substr($css, 0, (int) $at), '/*');

    return substr($css, $start, (int) $end - $start);
}

/** The built storefront stylesheet the layout actually links. */
function rdBuiltCss(): string
{
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $file = $manifest['resources/css/kbb/kbb.css']['file'] ?? null;

    expect($file)->not->toBeNull('the manifest names no kbb.css bundle');

    return (string) file_get_contents(public_path('build/'.$file));
}

/**
 * Every rule's whole selector in a stylesheet: comments, @property and
 * @keyframes blocks out, an @media's own brace opened so the rules inside it
 * count. Parentheses are kept -- `:is(...)` and `:not(...)` are the selectors.
 */
function rdSelectors(string $css): array
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    $css = (string) preg_replace('#@keyframes[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}#', '', $css);
    $css = (string) preg_replace('#@property[^{]*\{[^{}]*\}#', '', $css);
    $css = (string) preg_replace('#@media[^{]*\{#', '', $css);
    preg_match_all('#([^{}]+)\{#', $css, $m);

    $out = [];
    foreach ($m[1] as $block) {
        $block = trim((string) preg_replace('/\s+/', ' ', $block));
        if ($block !== '') {
            $out[] = $block;
        }
    }

    return $out;
}

beforeEach(function () {
    SiteLayoutAdminRoutes::wire($this->app);
    SettingsService::forgetMemo();
});

/* ═══════════════════════════════════════════════ the default is C ═══ */

it('ships at C in the code, not only through a migration', function () {
    /*
     * The owner, a round earlier, about a default that lived only in a
     * migration: "is still coming same". So the default is the schema's, and a
     * shop that has never saved anything -- a fresh install, a test database --
     * renders C.
     *
     * MUTATION, RUN: change SCHEMA['press'] default 'c' to 'off' -> red on the
     * first line and on the page.
     */
    expect(SiteLayout::SCHEMA['press'][2])->toBe('c')
        ->and(app(SiteLayout::class)->all()['press'])->toBe('c')
        ->and(app(SiteLayout::class)->pressAttribute())->toBe(' data-press="c"');

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, '<html lang="en" dir="ltr" data-press="c">'))->toBe(1);
});

it('sits on its own tab in Appearance → Site layout, named Press feedback', function () {
    /*
     * Rule 3: the owner should never have to hunt for it. The screen draws a
     * tab per TABS entry and the field under it; the "Try it" samples are the
     * tab's own card.
     *
     * MUTATION, RUN: drop 'press' from TABS -> red (and SiteLayoutScreenTest's
     * tab order with it).
     */
    $this->actingAs(rdAdmin(), 'admin');

    $tabs = collect($this->getJson('/admin-api/site-layout')->assertOk()->json('tabs'));
    $press = $tabs->firstWhere('key', 'press');

    expect($press)->not->toBeNull()
        ->and($press['label'])->toBe('Press feedback')
        ->and(collect($press['fields'])->pluck('key')->all())->toBe(['press'])
        ->and($press['fields'][0]['value'])->toBe('c')
        ->and(array_keys($press['fields'][0]['options']))->toBe(['off', 'a', 'b', 'c', 'd', 'e']);

    $screen = (string) file_get_contents(resource_path('views/admin/partials/site-layout-screen.blade.php'));

    expect(substr_count($screen, "if (key === 'press') return pressHTML();"))->toBe(1);
});

/* ═════════════════════════════════ the select stores only its options ═══ */

it('stores each of its own six options and refuses anything else', function () {
    /*
     * Rule 5: a select stores one of its own options or nothing. A refusal is a
     * 422 naming the field, and the stored value is untouched by it.
     *
     * MUTATION, RUN: give `press` the type 'text' instead of 'select' -> the
     * "<script>" save answers 200 and this is red.
     */
    $this->actingAs(rdAdmin(), 'admin');

    foreach (array_keys(SiteLayout::PRESS_OPTIONS) as $letter) {
        $this->postJson('/admin-api/site-layout', ['settings' => ['press' => $letter]])
            ->assertOk()->assertJson(['ok' => true]);
        SettingsService::forgetMemo();

        expect(app(SiteLayout::class)->press())->toBe($letter);
    }

    /* ' c' is not here on purpose: the TrimStrings middleware hands the
       screen 'c', a real option, before this field ever sees it. */
    foreach (['z', 'C', 'cc', '"><script>', '', 'data-press'] as $bad) {
        $this->postJson('/admin-api/site-layout', ['settings' => ['press' => 'b']])->assertOk();
        $response = $this->postJson('/admin-api/site-layout', ['settings' => ['press' => $bad]]);
        SettingsService::forgetMemo();

        /* '' reaches the screen as null (ConvertEmptyStringsToNull); whatever
           the answer, it must not change what is stored. */
        if ($bad === '') {
            expect(app(SiteLayout::class)->press())->toBe('b');

            continue;
        }

        $response->assertStatus(422)->assertJson(['ok' => false, 'rejected' => ['press']]);
        expect(app(SiteLayout::class)->press())->toBe('b', "the refused value {$bad} changed the stored one");
    }
});

it('renders the default for a stored value that is not one of its options', function () {
    /*
     * A row written behind the screen's back -- a raw UPDATE, an import -- must
     * still render a style the stylesheet has, and must never reach the page as
     * markup: the attribute is a constant from PRESS_ATTR, chosen by key.
     *
     * MUTATION, RUN: make pressAttribute() print the stored row,
     * ' data-press="'.$this->settings->get('layout_press', 'c').'"' -> red:
     * the page carries the junk. (Printing $this->get('press') instead stays
     * green, and rightly: all() has already refused the junk by then.)
     */
    rdPress('"><script>alert(1)</script>');

    expect(app(SiteLayout::class)->press())->toBe('c');

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('<html lang="en" dir="ltr" data-press="c">')
        ->and($html)->not->toContain('alert(1)');

    foreach (SiteLayout::PRESS_ATTR as $letter => $attr) {
        expect($attr)->toMatch($letter === 'off' ? '/^$/' : '/^ data-press="[a-e]"$/');
    }
});

/* ═══════════════════════════════════════ the token reaches the page ═══ */

it('puts the chosen letter on <html> of the storefront, and nowhere else on it', function () {
    /*
     * One attribute, on the layout's <html>, for every letter.
     *
     * MUTATION, RUN: delete `{!! $kbbPress !!}` from layouts/store.blade.php ->
     * red for every letter.
     */
    foreach (['a', 'b', 'c', 'd', 'e'] as $letter) {
        rdPress($letter);

        $html = (string) $this->get('/')->assertOk()->getContent();

        expect(substr_count($html, '<html lang="en" dir="ltr" data-press="'.$letter.'">'))->toBe(1, "letter {$letter}")
            ->and(substr_count($html, 'data-press'))->toBe(1, "letter {$letter}");
    }
});

it('changes nothing else on the page: Off is the page as it was, byte for byte', function () {
    /*
     * "Off means today's behaviour exactly." The page under C, with its one
     * attribute taken out, must be the page under Off; and Off prints no
     * attribute at all, so no press rule can match and press.js adds no
     * listener.
     *
     * MUTATION, RUN: make PRESS_ATTR['off'] ' data-press="off"' -> red.
     * MUTATION, RUN: add a second token, e.g. a `kbb-press` class on <body>
     * from the layout -> red on the byte comparison.
     */
    foreach (['/', '/cart/', '/my-account/'] as $path) {
        rdPress('off');
        $off = (string) $this->get($path)->assertOk()->getContent();

        rdPress('c');
        $c = (string) $this->get($path)->assertOk()->getContent();

        expect($off)->not->toContain('data-press')
            ->and($off)->toContain('<html lang="en" dir="ltr">')
            ->and(str_replace(' data-press="c"', '', $c))->toBe($off, "{$path}: Off differs from C by more than the token");
    }

    expect(app(SiteLayout::class)->pressAttribute())->toBe(' data-press="c"');
});

it('is not a stylesheet value: choosing a letter puts no :root block on the page', function () {
    /*
     * SiteLayout::css() is the block every storefront page carries once a
     * width or grid slider moves. `press` is an attribute, so choosing A must
     * not make the screen think a slider moved.
     *
     * MUTATION, RUN: remove PRESS_KEYS from isDefault()'s skip list -> red:
     * css() becomes a full :root block on every page.
     */
    rdPress('a');

    expect(app(SiteLayout::class)->isDefault())->toBeTrue()
        ->and(app(SiteLayout::class)->css())->toBe('');
});

/* ═══════════════════════════════ each option's CSS, keyed by the token ═══ */

it('styles every letter, in the source and in the bundle the shop is served', function () {
    /*
     * Each of A–E has a pressed rule, C has its wave, D its spring; Off has no
     * rule of its own because Off prints no token.
     *
     * MUTATION, RUN: rename every `html[data-press="e"]` in kbb.css to
     * `html[data-press="x"]` -> red on "e" in the source half before any
     * rebuild, and in the bundle half once it is rebuilt.
     */
    $source = rdSourceCss();
    $built = rdBuiltCss();

    foreach (['a', 'b', 'c', 'd', 'e'] as $letter) {
        expect($source)->toMatch('/html\[data-press="'.$letter.'"\][^{]*\.kbb-(pressed|rip)/', "source: {$letter}")
            ->and($built)->toMatch('/html\[data-press='.$letter.'\][^{]*\.kbb-(pressed|rip)/', "bundle: {$letter}");
    }

    expect($source)->not->toContain('data-press="off"');

    // C: the wave is a background layer drawn from two registered properties.
    foreach ([$source, $built] as $css) {
        expect($css)->toContain('@property --kbb-rip{')
            ->and($css)->toContain('@property --kbb-rip-o{')
            ->and($css)->toMatch('/html\[data-press="?c"?\] \.kbb-rip\{animation:kbb-press-rip /')
            ->and($css)->toMatch('/@keyframes kbb-press-rip\{/')
            ->and($css)->toMatch('/@keyframes kbb-press-pop\{/')
            ->and($css)->toContain('radial-gradient(circle farthest-corner at 50% 50%,rgb(var(--kbb-wave) / var(--kbb-rip-o)) var(--kbb-rip)');
    }
});

it('keys every press rule by the token, so Off can match none of them', function () {
    /*
     * The property that makes Off safe: a rule in this block that is NOT under
     * html[data-press] would reach the shop whatever the owner chose.
     *
     * MUTATION, RUN: add `.kbb-pressed{opacity:.5}` to the block -> red.
     */
    $selectors = rdSelectors(rdSourceCss());

    expect(count($selectors))->toBeGreaterThan(20);

    foreach ($selectors as $selector) {
        expect(str_starts_with($selector, 'html[data-press'))->toBeTrue("unkeyed press rule: {$selector}");
    }

    // And in the bundle: every rule that names a press class is keyed too.
    foreach (rdSelectors(rdBuiltCss()) as $selector) {
        if (preg_match('/kbb-(pressed|rip|pop)\b/', $selector)) {
            expect(str_starts_with($selector, 'html[data-press'))->toBeTrue("unkeyed press rule in the bundle: {$selector}");
        }
    }
});

it('removes the grey box under every letter from A to E', function () {
    /*
     * The complaint itself. `-webkit-tap-highlight-color` is inherited, so one
     * declaration on html[data-press] reaches every control under every letter;
     * the attribute is on the page for each of them (the case above), and a
     * control that sets its own highlight colour must set it transparent.
     *
     * MUTATION, RUN: key the rule html[data-press="c"] instead -> red ("a").
     */
    $source = rdSourceCss();
    $built = rdBuiltCss();

    expect($source)->toContain('html[data-press]{-webkit-tap-highlight-color:transparent}')
        ->and($built)->toContain('html[data-press]{-webkit-tap-highlight-color:transparent}');

    foreach (['a', 'b', 'c', 'd', 'e'] as $letter) {
        expect(SiteLayout::PRESS_ATTR[$letter])->toStartWith(' data-press=');
    }

    // Nothing anywhere in the shop's stylesheets brings a coloured box back.
    foreach (glob(resource_path('css/kbb/*.css')) ?: [] as $file) {
        preg_match_all('/-webkit-tap-highlight-color:\s*([^;}]+)/', (string) file_get_contents($file), $m);
        foreach ($m[1] as $value) {
            expect(trim($value))->toBe('transparent', basename($file).' sets a visible tap highlight');
        }
    }
});

it('never adds position, overflow, transform or a pseudo-element to a control', function () {
    /*
     * The card's stretched link is .kbb-tile .cn::after; the heart and the
     * slider arrows are positioned, the arrows by transform. A press style that
     * set any of these would move or break them -- so the block uses the
     * individual `scale`/`translate` properties, `outline` and a background
     * layer, and nothing else that touches a box.
     *
     * MUTATION, RUN: write D's squeeze as `transform:scale(.86)` -> red.
     */
    $css = (string) preg_replace('#/\*.*?\*/#s', '', rdSourceCss());

    expect($css)->not->toMatch('/[{;]\s*(position|overflow|transform|inset|top|left|right|bottom|margin[a-z-]*|width|height|padding[a-z-]*)\s*:/')
        ->and($css)->not->toMatch('/::?(before|after)/');
});

/* ═════════════════════════════════════════════ the script never measures ═══ */

it('toggles classes and nothing else, and reads no layout', function () {
    /*
     * Rule 4: no JavaScript that measures layout. The ripple spreads from the
     * centre precisely so nothing has to know where the finger landed.
     *
     * MUTATION, RUN: add `el.getBoundingClientRect();` to press() -> red.
     */
    $js = (string) file_get_contents(resource_path('js/kbb/press.js'));
    $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);

    foreach ([
        'getBoundingClientRect', 'getClientRects', 'offsetWidth', 'offsetHeight', 'offsetTop', 'offsetLeft',
        'clientWidth', 'clientHeight', 'clientTop', 'clientLeft', 'scrollWidth', 'scrollHeight', 'scrollTop',
        'scrollLeft', 'getComputedStyle', 'ResizeObserver', 'IntersectionObserver', 'innerWidth', 'innerHeight',
    ] as $api) {
        expect(str_contains($js, $api))->toBeFalse("press.js measures layout: {$api}");
    }

    expect($code)->not->toContain('.style.')
        ->and($code)->not->toContain('innerHTML')
        ->and($code)->not->toContain('createElement');

    // Off: it returns before it listens to anything.
    $guard = strpos($code, "if (!document.documentElement.hasAttribute('data-press')) return;");
    $listen = strpos($code, 'addEventListener');

    expect($guard)->not->toBeFalse()
        ->and($listen)->not->toBeFalse()
        ->and($guard)->toBeLessThan($listen);

    // Held long enough for a quick phone tap to be seen.
    expect($code)->toMatch('/const HOLD = (1[89]\d|[2-9]\d\d);/');
});

it('is one step of the storefront bundle, and is in the bundle the shop is served', function () {
    /*
     * Wired exactly once: zero is "built, never wired up"; two would add every
     * listener twice.
     *
     * MUTATION, RUN: remove `initPress,` from STEPS -> red (and
     * MobileMenuAndAssetOriginTest's count with it).
     */
    $app = (string) file_get_contents(resource_path('js/kbb/app.js'));

    expect(substr_count($app, "import { initPress } from './press.js';"))->toBe(1)
        ->and(substr_count($app, "    initPress,\n"))->toBe(1);

    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $bundle = (string) file_get_contents(public_path('build/'.$manifest['resources/js/kbb/app.js']['file']));

    expect($bundle)->toContain('kbb-pressed')
        ->and($bundle)->toContain('kbb-press-');
});
