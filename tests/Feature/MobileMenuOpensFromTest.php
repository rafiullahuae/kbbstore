<?php

declare(strict_types=1);

/**
 * =============================================================================
 * THE MOBILE MENU OPENS FROM THE LEFT, AS A GLASS PANEL — AND BOTTOM UNDOES IT
 * =============================================================================
 *
 * The owner, 6 October: "i need the same dual columns design which we have it
 * already and live, we just need to open from left side, that's it, and adjust
 * everything as per the user screen size without broken, bugs or delays etc."
 * and "also the panel design i need glossy glass type, which we have in the
 * footer for app install capsule."
 *
 * Appearance → Mobile menu → Panel → "Menu opens from": Left (the default, as he
 * asked) or Bottom (the sheet he had). What these cases hold:
 *
 *   - Left and Bottom render the SAME page but for one class, `mm-left`, on the
 *     <nav class="mmenu">. Together with StorefrontEnglishUnchangedTest (which
 *     approves exactly that insertion against the old views) this is what says
 *     Bottom is today's markup byte for byte.
 *   - every rule the side panel adds is keyed off `.mm-left`, so not one of them
 *     can reach the bottom sheet;
 *   - it comes in from the inline START: logical properties and a direction
 *     sign, so Arabic gets the right edge without a second rule set;
 *   - focus goes in and comes back, Tab stays inside, Esc, the backdrop and a
 *     swipe close it — and none of it measures the page;
 *   - reduced motion fades instead of sliding; reduced transparency and a
 *     browser without backdrop-filter get a solid panel.
 *
 * The browser half (where it actually is, at 320–768, both directions) is
 * tests/browser/mobile-menu-parity.mjs and tools/mn-behave.cjs.
 */

use App\Services\MobileMenu;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function mnOpenFrom(string $value): void
{
    app(MobileMenu::class)->save(['open_from' => $value]);
    Cache::flush();
}

function mnHome(): string
{
    $html = test()->get('/')->assertOk()->getContent();

    // Two renders differ by their CSRF token and nothing else that matters here.
    return (string) preg_replace('/(name="_token" value="|name="csrf-token" content=")[^"]+/', '$1X', $html);
}

function mnNavTag(string $html): string
{
    expect(preg_match('/<nav class="mmenu[^>]*>/', $html, $m))->toBe(1, 'the mobile menu is not on the page');

    return $m[0];
}

/** The Lane MN block of kbb.css, from its banner to the next banner. */
function mnCss(): string
{
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $banner = strpos($css, 'MOBILE MENU · THE SIDE PANEL (Lane MN');
    expect($banner)->not->toBeFalse('the side panel block is gone from kbb.css');
    // From the opening of the banner's own comment, so comments strip cleanly.
    $start = strrpos(substr($css, 0, $banner), '/*');
    $end = strpos($css, '/* ═══ Menu icon ═══', $start);

    return substr($css, $start, $end - $start);
}

function mnJs(): string
{
    $js = (string) file_get_contents(resource_path('js/kbb/home.js'));
    $start = strpos($js, 'function initMobileChrome()');
    $end = strpos($js, 'function initMenuFilter(');

    return substr($js, $start, $end - $start);
}

it('opens from the left by default: the side panel class is on the menu', function () {
    // Mutation: default 'bottom' in MobileMenu::SCHEMA, or drop the mm-left arm
    // of bodyClass(), and this is red — the shop would still rise from below.
    expect(MobileMenu::SCHEMA['open_from'][2])->toBe('left')
        ->and(mnNavTag(mnHome()))->toContain('class="mmenu mm-card-cream mm-rule-children mm-left"');
});

it('Bottom puts back the sheet: the same page, without the one class', function () {
    $left = mnHome();

    mnOpenFrom('bottom');
    $bottom = mnHome();

    // Today's class list exactly — mm-left was appended last for this reason.
    expect(mnNavTag($bottom))->toContain('class="mmenu mm-card-cream mm-rule-children"')
        ->and($bottom)->not->toContain('mm-left');

    // And NOTHING else on the page differs. Mutation: print any other
    // attribute only for Left (a role, a tabindex, a wrapper) and this is red,
    // because Bottom would no longer be the markup it was.
    expect(str_replace(' mm-left"', '"', $left))->toBe($bottom);
});

it('stores one of its own two options, and anything else is Left', function () {
    $mm = app(MobileMenu::class);

    expect($mm->cast('open_from', 'bottom'))->toBe('bottom')
        ->and($mm->cast('open_from', 'left'))->toBe('left')
        ->and($mm->cast('open_from', 'right"><script>'))->toBe('left')
        ->and($mm->cast('open_from', ''))->toBe('left')
        ->and(array_keys(MobileMenu::SCHEMA['open_from'][4]))->toBe(['left', 'bottom']);

    // Appearance → Mobile menu → Panel, first control in the card.
    expect(MobileMenu::TABS['panel'][2][0])->toBe('open_from');
});

it('costs the page nothing: the same queries with Left as with Bottom', function () {
    // The switch lives in the mobile_menu map the sheet already reads, so it
    // adds no query and no settings read. Mutation: read it with its own
    // SettingsService::get('mobile_menu_open_from') and the counts part.
    $count = function (): int {
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        test()->get('/')->assertOk();

        return $n;
    };

    mnHome();                 // warm
    $left = $count();
    mnOpenFrom('bottom');
    mnHome();
    $bottom = $count();

    expect($left)->toBe($bottom);
});

it('keys every side-panel rule off .mm-left, so none of them can touch the bottom sheet', function () {
    $css = (string) preg_replace('#/\*.*?\*/#s', '', mnCss());

    preg_match_all('/([^{}@]+)\{[^{}]*\}/', $css, $m);
    $selectors = array_map('trim', $m[1]);

    expect(count($selectors))->toBeGreaterThan(15);

    // Mutation: write `.mm-srch input{padding-inline:32px 11px}` without the
    // .mm-left prefix and the bottom sheet's search field moves; this is red.
    foreach ($selectors as $sel) {
        foreach (explode(',', $sel) as $one) {
            expect(str_contains($one, 'mm-left'))->toBeTrue("\"{$one}\" in the side panel block would also style the bottom sheet");
        }
    }
});

it('comes in from the inline start, so Arabic gets the right edge from the same rules', function () {
    $css = mnCss();

    expect($css)->toContain('inset-inline-start:0;inset-inline-end:auto;')
        ->and($css)->toContain('border-start-end-radius:var(--mm-radius);border-end-end-radius:var(--mm-radius)')
        ->and($css)->toContain('transform:translateX(calc(-100% * var(--mm-dir)))')
        ->and($css)->toContain('[dir="rtl"] .mmenu.mm-left{--mm-dir:-1;')
        ->and($css)->toContain('.mmenu.mm-left.on{transform:none;visibility:visible;');

    // Parked off screen AND hidden, the sheet's own landmine (kbb.css explains
    // the white bar): a transform alone would let it paint during a URL-bar
    // resize.
    expect($css)->toContain('visibility 0s linear var(--mm-side-speed)');

    // No physical side anywhere in the block. Mutation: `left:0` instead of
    // inset-inline-start and Arabic opens from the wrong edge; red here.
    $code = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    // And no reserved scrollbar gutter: measured, it shifted the page and the
    // burger 15px sideways the moment the panel opened.
    expect($code)->not->toContain('scrollbar-gutter');
    expect($code)->not->toMatch('/(?<![-\w])(left|right)\s*:/')
        ->and($code)->not->toMatch('/margin-(left|right)|padding-(left|right)|border-(left|right)/');
});

it('scales with the screen in CSS: a capped width and clamped rows, text and padding', function () {
    $css = mnCss();

    expect($css)->toContain('width:min(88vw,420px)')
        ->and($css)->toContain('min-height:clamp(44px,')
        ->and($css)->toContain('font-size:clamp(13.5px,')
        ->and($css)->toContain('font-size:clamp(12px,')
        ->and($css)->toContain('container:mmenu / inline-size')
        ->and($css)->toContain('@container mmenu (max-width:300px)')
        // A long name wraps inside its column rather than being cut off.
        ->and($css)->toContain('white-space:normal;overflow-wrap:break-word');
});

it('is the footer capsule\'s glass, with a solid panel wherever glass is not wanted or not there', function () {
    $css = mnCss();
    $all = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    // The capsule it was asked to match.
    expect($all)->toContain('.kfa-g{')->and($all)->toContain('backdrop-filter:blur(12px) saturate(1.4)');

    expect($css)->toContain('-webkit-backdrop-filter:blur(12px) saturate(1.5) brightness(1.25)')
        ->and($css)->toContain('backdrop-filter:blur(12px) saturate(1.5) brightness(1.25)')
        ->and($css)->toContain('border-inline-end:1px solid rgba(255,255,255,.9)')
        ->and($css)->toContain('inset 0 1px 0 #fff');

    // Mutation: drop either fallback and a phone set to reduce transparency, or
    // a browser without backdrop-filter, gets text on a see-through panel.
    expect($css)->toMatch('/@supports not \(\(backdrop-filter:blur\(1px\)\) or \(-webkit-backdrop-filter:blur\(1px\)\)\)\{\s*\.mmenu\.mm-left\{background:#fff\}/')
        ->and($css)->toMatch('/@media \(prefers-reduced-transparency:reduce\)\{\s*\.mmenu\.mm-left\{background:#fff;-webkit-backdrop-filter:none;backdrop-filter:none\}/');

    // The two secondary inks go darker on the glass: AA over a black photo.
    expect($css)->toContain('--muted:#5E545A; --sale:#B0182F;');
});

it('fades instead of sliding under reduced motion', function () {
    // Mutation: delete the block and a phone set to reduce motion still gets
    // the slide; red.
    expect(mnCss())->toMatch('/@media \(prefers-reduced-motion:reduce\)\{\s*\.mmenu\.mm-left\{transform:none;opacity:0;transition:opacity \.15s linear,visibility 0s linear \.15s\}\s*\.mmenu\.mm-left\.on\{opacity:1;/');
});

it('takes focus in, keeps Tab inside, closes on Esc, the backdrop or a swipe, and gives focus back', function () {
    $js = mnJs();

    // Side-only behaviour is gated on the class, so Bottom runs as it did.
    expect($js)->toContain("const side = menu.classList.contains('mm-left');")
        ->and($js)->toContain("if (side) (document.getElementById('mmx') || menu).focus({ preventScroll: true });")
        ->and($js)->toContain('if (side && was) burger?.focus({ preventScroll: true });')
        ->and($js)->toContain('if (side) initSidePanel(menu, shut);');

    // Esc and the backdrop: the handlers the sheet already had, still there.
    expect($js)->toContain("document.addEventListener('keydown', (e) => e.key === 'Escape' && shut());")
        ->and($js)->toContain("scrim.addEventListener('click', shut);")
        ->and($js)->toContain("document.getElementById('mmx')?.addEventListener('click', shut);");

    // The trap and the swipe.
    expect($js)->toContain("if (e.key !== 'Tab' || !menu.classList.contains('on')) return;")
        ->and($js)->toContain("menu.addEventListener('pointerdown'")
        ->and($js)->toContain("menu.addEventListener('pointercancel', end);")
        ->and($js)->toContain('(rtl() ? -1 : 1)')
        ->and($js)->toContain('if (dx < -60 || dx / Math.max(1, e.timeStamp - t0) < -0.45) shut();');

    // Body scroll is locked while it is open, as the sheet always did.
    expect($js)->toContain("document.body.style.overflow = 'hidden';");
});

it('measures nothing: no layout reads anywhere in the menu script', function () {
    // CLAUDE.md rule 4. Mutation: size the drag from menu.offsetWidth and this
    // is red.
    expect(mnJs())->not->toMatch('/getBoundingClientRect|offsetWidth|offsetHeight|offsetLeft|clientWidth|clientHeight|scrollWidth|scrollHeight|getComputedStyle|ResizeObserver|IntersectionObserver|innerWidth|innerHeight/');
});
