<?php

declare(strict_types=1);

use App\Services\HeaderSettings;
use App\Services\MobileHeader;
use App\Services\PageWash;
use App\Services\ProductLayout;
use App\Services\ProductStyles;
use App\Services\SettingsService;
use App\Support\BrandAccent;
use App\Support\ContrastPairs;

/**
 * THE APPROVED CONTRAST COLOURS, AND THE PHONE FOOTER'S TAP AREAS.  (Lane CT)
 *
 * The owner approved docs/contrast-preview/ on 5 October — "okay proceed, but
 * we don't have golden color at all ... we didn't enabled that golden grid
 * style" — so every row shipped except the gold card. What each defect looked
 * like on the shop, measured with axe in Chromium on the fixture and named in
 * Google's report for extrabeauty.ae:
 *
 *   white on the Add to cart pink #E0567B          3.62  (every product card)
 *   secondary grey #8C828A on white / the page     3.70 / 3.31
 *   the struck-through price, #B3AAB0 / #A2959B    2.25 / 2.87
 *   the PDP's struck price, --muted at .75 opacity 2.34 on the page
 *   the phone footer's account links              ~16px tall, 4px apart
 *
 * Every ratio below is computed HERE with the WCAG 2.1 formula, independently
 * of App\Support\ContrastPairs, so the test does not grade the code with the
 * code's own arithmetic.
 */
function ctLum(string $hex): float
{
    $hex = ltrim($hex, '#');
    $c = [];
    foreach ([0, 2, 4] as $i) {
        $s = hexdec(substr($hex, $i, 2)) / 255;
        $c[] = $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
    }

    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function ctRatio(string $a, string $b): float
{
    [$x, $y] = [ctLum($a), ctLum($b)];

    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

function ctCss(string $file): string
{
    return (string) file_get_contents(resource_path('css/kbb/'.$file));
}

/** The built stylesheet the shop actually downloads, via the committed manifest. */
function ctBuilt(string $src): string
{
    $m = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    return (string) file_get_contents(public_path('build/'.$m['resources/css/kbb/'.$src]['file']));
}

/** For each occurrence of $needle, the `@media` query that encloses it (null if none). */
function ctMediaBlocksContaining(string $css, string $needle): array
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    $out = [];
    $at = 0;
    while (($p = strpos($css, $needle, $at)) !== false) {
        $depth = 0;
        $query = null;
        for ($i = $p - 1; $i >= 0; $i--) {
            if ($css[$i] === '}') {
                $depth++;
            } elseif ($css[$i] === '{' && $depth-- === 0) {
                if (preg_match('/@media[^{};]*$/', substr($css, 0, $i), $m)) {
                    $query = preg_replace('/\s+/', '', $m[0]);
                }
                break;
            }
        }
        $out[] = $query;
        $at = $p + 1;
    }

    return $out;
}

it('ships every approved colour in the source stylesheets and in the built ones', function () {
    /*
     * MUTATION: put `--pink:#E0567B` back on kbb.css's :root -> red twice
     * (source, then build). Rebuild without committing public/build -> red on
     * the built half, which is the half the shop serves.
     */
    foreach (['kbb.css' => ctCss('kbb.css'), 'built kbb.css' => ctBuilt('kbb.css')] as $label => $css) {
        foreach (['--pink:#C6395F', '--muted:#756C74', '--kbb-was:#796C75', '--kbb-save:#1F7A50', '--kbb-wa-foot:#27865B'] as $decl) {
            expect(str_contains($css, $decl))->toBeTrue("$label lost $decl");
        }
        expect($css)->toContain('.kbb-card-reg{color:var(--kbb-was,#796C75);')
            ->toMatch('/\.kbb-home \.rall span\{background:#E9F6EF;color:var\(--kbb-save,#1F7A50\);/i')
            ->toContain('.fcol a.wa{background:var(--kbb-wa-foot,#27865B);color:#fff}')
            ->not->toContain('--sc-was:#A2959B')
            ->not->toContain('--sc-muted:#9A8D94')
            ->not->toContain('var(--kbb-cart-bg,#E0567B)')
            // The PDP struck price was --muted blended to 2.34:1 by this opacity.
            ->not->toContain('.pdp .bb-price s{font-size:clamp(15px,1.6vw,18px);opacity:.75}');
    }

    foreach (['kbb-grid-skins.css' => ctCss('kbb-grid-skins.css'), 'built grid skins' => ctBuilt('kbb-grid-skins.css')] as $label => $css) {
        expect($css)->toContain('var(--kbb-sale,#D22B47)')
            ->toContain('var(--kbb-new,#1A7F45)')
            ->toContain('.kbb-card-reg{color:var(--kbb-was,#796C75);')
            ->not->toContain('#E0567B;color:#fff')
            ->not->toMatch('/\.kbb-card-(cat|brand|sdesc|rc)\{[^}]*#8C828A/i');
    }

    // The documents that declare their own :root carry the same two tokens.
    // (store/blog and store/post left this list with Lane BH: they extend
    // layouts/store.blade.php now and take both tokens from kbb.css's :root,
    // declaring neither -- JournalSharedHeaderTest pins that they stay off.)
    foreach (['css/kbb/kbb-product.css', 'css/kbb/kbb-shop.css', 'css/kbb/kbb-checkout.css',
        'views/store/review-wall.blade.php',
        'views/store/skin-quiz.blade.php'] as $f) {
        // Comments stripped: several of these files quote the old value in prose.
        $src = (string) preg_replace(['#/\*.*?\*/#s', '#\{\{--.*?--\}\}#s'], '', (string) file_get_contents(resource_path($f)));
        expect($src)->toMatch('/--pink:\s?#C6395F/', $f)->toMatch('/--muted:\s?#756C74/', $f)
            ->not->toMatch('/--pink:\s?#E0567B/', $f)->not->toMatch('/--muted:\s?#8C828A/', $f);
    }
});

it('ships every approved colour as the DEFAULT of the control that owns it', function () {
    /*
     * MUTATION: set ProductStyles 'sale_colour' back to #E23B57 -> red here and
     * on the contrast case below (4.20).
     */
    $ps = ProductStyles::SCHEMA;
    expect($ps['sale_colour'][2])->toBe('#D22B47')
        ->and($ps['new_colour'][2])->toBe('#1A7F45')
        ->and($ps['cart_bg'][2])->toBe('#C6395F')
        ->and($ps['muted_colour'][2])->toBe('#756C74')
        ->and($ps['was_colour'][2])->toBe('#796C75')
        ->and($ps['save_colour'][2])->toBe('#1F7A50')
        ->and($ps['wa_foot_colour'][2])->toBe('#27865B')
        ->and(BrandAccent::DEFAULT)->toBe('#C6395F')
        ->and(MobileHeader::SCHEMA['search_text'][2])->toBe('#C6395F')
        ->and(MobileHeader::SCHEMA['search_icon'][2])->toBe('#C6395F')
        ->and(HeaderSettings::SCHEMA['badge_bg'][2])->toBe('#C6395F')
        // The header wordmark's accent, so it matches the footer's (var(--pink)).
        ->and(HeaderSettings::SCHEMA['logo_accent_col'][2])->toBe('#C6395F')
        ->and(HeaderSettings::SCHEMA['search_style_accent'][2])->toBe('#C6395F')
        ->and(ProductLayout::SCHEMA['badge_bg'][2])->toBe('#1A7F45')
        ->and(PageWash::TEXT_TOKENS['--muted'])->toBe('#756C74')
        ->and(PageWash::TEXT_TOKENS['--pink'])->toBe('#C6395F');

    // Each new control is on the Colour tab, so the screen draws it.
    foreach (array_keys(ProductStyles::TEXT_COLOUR_VARS) as $k) {
        expect(ProductStyles::TABS['colour'][2])->toContain($k);
    }

    expect((string) file_get_contents(database_path('seeders/SettingsSeeder.php')))
        ->toContain("'brand_accent' => '#C6395F',");
});

it('clears 4.5:1 for every shipped default against every background it is drawn on', function () {
    /*
     * The page itself (#FDEFF3) is on the list because that is the colour
     * Lighthouse reads under anything sitting on the page, and it is why
     * --muted is #756C74 and not the proposal's #766D75 (4.46 there).
     * MUTATION: set muted_colour's default to #766D75 -> red, "4.46 on #FDEFF3".
     */
    $defaults = array_map(fn ($d) => $d[2], ProductStyles::SCHEMA) + ['set_brand_accent' => BrandAccent::DEFAULT];

    foreach (ContrastPairs::PAIRS as $key => $pair) {
        $hex = $defaults[$key];
        $against = $pair['kind'] === 'fill'
            ? [isset($pair['fill_fg']) ? $defaults[$pair['fill_fg']] : $pair['fg']]
            : $pair['against'];

        foreach ($against as $bg) {
            $r = ctRatio($hex, $bg);
            expect($r)->toBeGreaterThanOrEqual(4.5, sprintf('%s %s is %.2f:1 on %s', $key, $hex, $r, $bg));
            // And the code's own arithmetic agrees with this one.
            expect(abs(ContrastPairs::ratio($hex, $bg) - $r))->toBeLessThan(0.0001);
        }
    }

    // The two pairs that are not settings: the footer button's white label, and
    // the PDP's badge, white on ProductLayout's green.
    expect(ctRatio('#FFFFFF', '#27865B'))->toBeGreaterThanOrEqual(4.5)
        ->and(ctRatio('#FFFFFF', ProductLayout::SCHEMA['badge_bg'][2]))->toBeGreaterThanOrEqual(4.5);
});

it('gives the phone footer links a 24px tap area, and only on a phone', function () {
    /*
     * DEFECT: Google's target-size audit named nav.kft-col > ul > li > a — a
     * ~16px inline link 4px from the next. MUTATION: move the rule out of
     * `@media (max-width:900px)` -> red ("desktop unchanged" is the brief).
     * Delete it -> red on the count.
     */
    foreach (['source' => ctCss('kbb.css'), 'built' => ctBuilt('kbb.css')] as $label => $css) {
        $rule = $label === 'source'
            ? 'nav.kft-col > ul > li > a{display:inline-block;padding:4px 0;min-height:24px}'
            : 'nav.kft-col>ul>li>a{display:inline-block;padding:4px 0;min-height:24px}';
        $gap = $label === 'source' ? 'nav.kft-col > ul{gap:0}' : 'nav.kft-col>ul{gap:0}';

        expect(substr_count($css, $rule))->toBe(1, "$label: the tap rule is not there exactly once")
            ->and(substr_count($css, $gap))->toBe(1, "$label: the gap rule is not there exactly once")
            ->and(ctMediaBlocksContaining($css, $rule))->toBe(['@media(max-width:900px)'])
            ->and(ctMediaBlocksContaining($css, $gap))->toBe(['@media(max-width:900px)']);
    }
});

it('leaves the gold card skin exactly as it was', function () {
    /*
     * The owner: "we don't have golden color at all ... we didn't enabled
     * that golden grid style". Every `luxe` rule is pinned byte for byte, in
     * both copies of the skins. MUTATION: change the luxe brand's #B8942E -> red.
     */
    $pins = [
        'kbb.css' => 'ff6adbbcacd37234fe6a38b96c62f980e30f95e1',
        'kbb-grid-skins.css' => 'dc23de09bb5e159da74c5a992b0f3d120d3ff1a3',
    ];

    foreach ($pins as $file => $sha) {
        $lines = array_values(array_filter(explode("\n", ctCss($file)), fn ($l) => str_contains($l, '[data-skin="luxe"]')));
        expect(sha1(implode("\n", $lines)))->toBe($sha, "$file: a luxe (gold) rule changed")
            ->and(implode("\n", $lines))->toContain('color:#B8942E')->toContain('color:#9A7B1F');
    }
});

it('puts a moved text colour on the page, and nothing at all while it is on its default', function () {
    /*
     * Rule 1: at the defaults the four new controls add NOT ONE BYTE to any
     * page (kbb.css's :root already says it). MUTATION: drop the default check
     * in movedTextColours() -> red on the first expectation.
     */
    $css = fn () => app(ProductStyles::class)->cssVariables();

    expect($css())->not->toContain('--muted:')->not->toContain('--kbb-was:')
        ->not->toContain('--kbb-save:')->not->toContain('--kbb-wa-foot:');

    app(SettingsService::class)->set('muted_colour', '#5E545A');
    app(SettingsService::class)->set('wa_foot_colour', '#c6395f');
    app()->forgetInstance(ProductStyles::class);

    expect($css())->toContain('--muted:#5E545A')->toContain('--kbb-wa-foot:#c6395f')
        ->not->toContain('--kbb-was:');

    // A value the strict cast refuses never reaches the attribute.
    app(SettingsService::class)->set('save_colour', 'red;}body{display:none');
    app()->forgetInstance(ProductStyles::class);
    expect($css())->not->toContain('display:none')->not->toContain('--kbb-save:');
});

it('moves a stored OLD default to the new one, and leaves a colour the owner chose', function () {
    /*
     * DEFECT this prevents: Product styles saves every field on Save, so a shop
     * that once flipped a toggle holds sale_colour = #E23B57 it never chose,
     * and the approved change would do nothing there. MUTATION: delete the
     * PLAIN loop in the migration -> red on sale_colour.
     */
    $s = app(SettingsService::class);
    $s->set('sale_colour', '#E23B57');      // the old default, as the screen stores it
    $s->set('cart_bg', 'e0567b');           // no hash, lower case: still the old default
    $s->set('brand_accent', '#E0567B');     // what SettingsSeeder used to write
    $s->set('new_colour', '#1F9D56');       // one digit off: his own choice
    $s->set('header_settings', ['badge_bg' => '#E0567B', 'search_style_accent' => '#123456', 'icon_size' => 22]);

    (require database_path('migrations/2027_08_27_100000_contrast_colours_leave_the_old_defaults.php'))->up();
    SettingsService::forgetMemo();

    $rows = \Illuminate\Support\Facades\DB::table('settings')->pluck('value', 'key');
    expect($rows->has('sale_colour'))->toBeFalse()
        ->and($rows->has('cart_bg'))->toBeFalse()
        ->and($rows->has('brand_accent'))->toBeFalse()
        ->and($rows['new_colour'])->toBe('#1F9D56');

    $header = json_decode((string) $rows['header_settings'], true);
    expect($header)->not->toHaveKey('badge_bg')
        ->and($header['search_style_accent'])->toBe('#123456')
        ->and($header['icon_size'])->toBe(22);

    // And with the row gone, the shop is on the approved colour.
    app()->forgetInstance(ProductStyles::class);
    expect(app(ProductStyles::class)->cssVariables())->toContain('--kbb-sale:#D22B47')
        ->and(BrandAccent::css())->toBe('');
});

it('warns beside a colour control that fails 4.5, from the same list the tests use', function () {
    /*
     * The partial is the console's half of "the control warns when a chosen
     * colour fails". MUTATION: drop @json(PAIRS) -> red; print it with {!! !!}
     * and a label containing </script> -> the escaping case goes red.
     */
    $html = view('admin.partials.colour-contrast-guard')->render();

    foreach (array_keys(ContrastPairs::PAIRS) as $key) {
        expect($html)->toContain('"'.$key.'"');
    }
    expect($html)->toContain('var AA = 4.5;')
        ->toContain("new MutationObserver(all).observe(content, { childList: true })")
        ->not->toContain('setInterval')->not->toContain('fetch(')
        ->not->toContain('getBoundingClientRect')->not->toContain('offsetHeight');
});

it('is mounted on the console exactly once, riding on reset-guard', function () {
    /*
     * Pinned at the FINISHED state (CLAUDE.md). The guard is included from the
     * end of reset-guard, which admin/app.blade.php already mounts once, so no
     * edit to the console file is needed. MUTATION: delete the include line ->
     * red here and in EverythingIsMountedOnceTest; paste it twice -> red.
     */
    $line = "@include('admin.partials.colour-contrast-guard')";
    $guard = (string) file_get_contents(resource_path('views/admin/partials/reset-guard.blade.php'));
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($guard, $line))->toBe(1)
        ->and(substr_count($app, "@include('admin.partials.reset-guard')"))->toBe(1)
        ->and(substr_count($app, $line))->toBe(0, 'mounted twice: once directly and once through reset-guard');
});
