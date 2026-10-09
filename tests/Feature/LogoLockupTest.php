<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\HeaderSettings;
use App\Services\SettingsService;
use App\Support\LogoLockup;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The lotus lockup — logo option D "Pearl", the owner's choice (Lane LG2).
 *
 * The owner: "option D is fine, but make sure it's crisp clear", "make sure no
 * logo should cut in mobile header ... we can reduce the logo size, it's okay,
 * also give control of size. outer spacing etc etc", and "the animation etc,
 * must not effect the site speed in any case".
 *
 * Appearance → Header → Logo. It ships ON; "Text only (as before)" prints the
 * wordmark the shop printed before, byte for byte. The no-clipping proof is
 * measured in Chromium (tools/lg2-header.cjs, every glyph against the logo box
 * at 320/360/375/390/430); what is pinned here is everything a server can
 * get wrong: the markup, the escaping, the clamping, the cost and the CSS
 * contract the fit relies on.
 */
function lgSet(array $values): void
{
    app(HeaderSettings::class)->save($values);
    SettingsService::forgetMemo();
}

function lgPage(string $path = '/'): string
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    return (string) test()->get($path)->assertOk()->getContent();
}

/**
 * The `.lgx` block of kbb.css: from its banner to the next section's banner
 * (it sits just above the showcase card family, which has to end the file).
 */
function lgCss(): string
{
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $at = strpos($css, 'LOGO · THE LOTUS LOCKUP');

    expect($at)->not->toBeFalse('the lockup block is missing from kbb.css');

    $end = strpos($css, "\n/* ═══", (int) $at);

    return $end === false ? substr($css, (int) $at) : substr($css, (int) $at, $end - $at);
}

/** The header's old wordmark, exactly as partials/header.blade.php printed it. */
const LG_OLD_HEADER = "</button>\n\n      <a class=\"logo\" href=\"/\"><bdi>K-Beauty<span>Bliss</span></bdi></a>\n\n";

const LG_OLD_DRAWER = "<div class=\"mnav-h\">\n        <div class=\"logo\"><bdi>K-Beauty<span>Bliss</span></bdi></div>\n        <button class=\"x\"";

it('draws the lotus lockup by default: header, drawer, nothing else changed around it', function () {
    /*
     * The defect this guards: the owner chose D and the shop still showing the
     * text wordmark, because the switch shipped OFF or the partial was never
     * wired. MUTATION: set logo_style's default back to 'text' and this is red.
     */
    $html = lgPage('/');

    expect(HeaderSettings::SCHEMA['logo_style'][2])->toBe('lotus')
        ->and(substr_count($html, '<a class="logo lgx"'))->toBe(1)
        ->and(substr_count($html, '<div class="logo lgx"'))->toBe(1)
        ->and($html)->toContain('aria-label="K-Beauty Bliss, Korean Skincare &amp; Makeup"')
        ->and($html)->toContain('<bdi class="lgx-w">K-Beauty<em>Bliss</em></bdi><small class="lgx-g">Korean Skincare &amp; Makeup</small>')
        // The header names the artwork once; the drawer points at it.
        ->and(substr_count($html, 'id="'.LogoLockup::ART_ID.'"'))->toBe(1)
        ->and(substr_count($html, '<use href="#'.LogoLockup::ART_ID.'"/>'))->toBe(1)
        // The shine on the header only, and the repainting glow not at all.
        ->and(substr_count($html, '<i class="lgx-lt" aria-hidden="true"></i>'))->toBe(1)
        ->and($html)->not->toContain('lgx-o2')
        ->and($html)->not->toContain('lgx-gl')
        // At the shipped values nothing is printed inline: kbb.css's
        // fallbacks ARE the defaults.
        ->and($html)->not->toMatch('/class="logo lgx[^"]*"[^>]*style=/')
        ->and($html)->not->toContain('<bdi>K-Beauty<span>Bliss</span></bdi>');
});

it('puts back the old wordmark byte for byte on "Text only (as before)"', function () {
    /*
     * The way back has to be the shop he had, not an approximation of it. The
     * exact bytes around the header's and the drawer's wordmark, newlines and
     * indentation included -- a Blade directive at a line's end swallows its
     * newline, so an @if around a line is exactly how bytes go missing.
     * StorefrontEnglishUnchangedTest compares every page whole; this pins the
     * two lines and that no trace of the lockup is left.
     *
     * MUTATION: join the old line to its @endif in partials/header
     * (`</a>@endif`, which swallows the newline) and this is red.
     */
    lgSet(['logo_style' => 'text']);
    $html = lgPage('/');

    expect($html)->toContain(LG_OLD_HEADER)
        ->and($html)->toContain(LG_OLD_DRAWER)
        ->and($html)->not->toContain('lgx')
        ->and($html)->not->toContain('--lg-');

    // And the lockup's own settings, moved, still change nothing.
    lgSet(['logo_name_m' => 20, 'logo_glow' => 'on', 'logo_tag_text' => 'Anything', 'logo_petal' => '#000000']);
    expect(lgPage('/'))->toBe($html);
});

it('clamps every number, keeps every select to its options and prints only numbers and hex colours', function () {
    /*
     * The defect: a size out of range drawing a 900px logo, or a stored
     * string reaching a style attribute. MUTATION: take `'clamp' => true` out
     * of HeaderSettings::POLICY and the first expectation is red.
     */
    lgSet([
        'logo_name_m' => 999, 'logo_size' => -4, 'logo_icon_d' => 500, 'logo_icon_m' => -1,
        'logo_tag_d' => 2, 'logo_tag_m' => 99, 'logo_gap_d' => 99, 'logo_gap_m' => -9,
        'logo_pad_x_d' => 999, 'logo_pad_y_m' => 999, 'logo_shine' => 9999,
        'logo_style' => 'evil', 'logo_anim' => 'sometimes', 'logo_glow' => '1',
        'logo_petal' => 'red;}</style><script>', 'logo_line' => '#12', 'logo_colour' => 'url(x)',
    ]);
    $c = app(HeaderSettings::class)->all();

    expect([$c['logo_name_m'], $c['logo_size'], $c['logo_icon_d'], $c['logo_icon_m'], $c['logo_tag_d'], $c['logo_tag_m'],
        $c['logo_gap_d'], $c['logo_gap_m'], $c['logo_pad_x_d'], $c['logo_pad_y_m'], $c['logo_shine']])
        ->toBe([24, 16, 64, 0, 8, 14, 24, 0, 32, 12, 30])
        ->and([$c['logo_style'], $c['logo_anim'], $c['logo_glow']])->toBe(['lotus', 'on', 'off'])
        ->and([$c['logo_petal'], $c['logo_line'], $c['logo_colour']])->toBe(['#D94A76', '#E5567E', '#2A2228']);

    $style = app(HeaderSettings::class)->lockup()['style'];
    expect($style)->not->toBe('')
        ->and($style)->toMatch('/^(--lg-[a-z0-9]+:(#[0-9A-Fa-f]{6}|\d+(\.\d+)?))(;--lg-[a-z0-9]+:(#[0-9A-Fa-f]{6}|\d+(\.\d+)?))*$/');

    $html = lgPage('/');
    expect($html)->toContain('style="'.$style.'"')
        ->and($html)->not->toContain('red;}</style><script>');
});

it('escapes the tagline and the label, and caps the tagline at 40 characters', function () {
    /*
     * The defect: a tagline typed with markup in it running as markup on
     * every page. MUTATION: print the tagline with {!! !!} in
     * partials/logo-lockup and this is red.
     */
    lgSet(['logo_tag_text' => '<img src=x onerror=alert(1)>"&\' Korean skincare and makeup, since 2019']);
    $tag = app(HeaderSettings::class)->get('logo_tag_text');
    $html = lgPage('/');

    expect(mb_strlen($tag))->toBe(HeaderSettings::TAGLINE_MAX)
        ->and($html)->not->toContain('<img src=x onerror')
        ->and($html)->toContain('<small class="lgx-g">&lt;img src=x onerror=alert(1)&gt;&quot;&amp;&#039; Korean s</small>')
        ->and($html)->toContain('aria-label="K-Beauty Bliss, &lt;img src=x onerror=alert(1)&gt;&quot;&amp;&#039; Korean s"');

    // The tagline off leaves no empty element behind and no comma in the label.
    lgSet(['logo_tag_on' => false]);
    $html = lgPage('/');
    expect($html)->not->toContain('lgx-g')->and($html)->toContain('aria-label="K-Beauty Bliss"');
});

it('animates only on the compositor, never with a script, and stops for reduced motion', function () {
    /*
     * The owner: "the animation etc, must not effect the site speed in any
     * case". Measured (tools/lg2-trace.cjs, homepage at 390, 5 s, every other
     * animation paused): the shine adds 0 Paint events and no main-thread
     * time; the petal glow and colour drift add ~580 Paint events and ~1.0 s of
     * main thread, which is why they ship off.
     *
     * MUTATION: animate the shine's `left` instead of its transform, drop the
     * reduced-motion block, or ship logo_glow on, and this is red.
     */
    $css = lgCss();

    preg_match_all('/@keyframes\s+(lgx-[a-z]+)\s*\{(.*?\})\s*\}/s', $css, $m, PREG_SET_ORDER);
    expect(count($m))->toBe(3);

    foreach ($m as [, $name, $body]) {
        preg_match_all('/([a-z-]+)\s*:/', $body, $props);
        $allowed = $name === 'lgx-sh' ? ['transform'] : ['transform', 'opacity', 'background-position'];
        expect(array_diff(array_unique($props[1]), $allowed))->toBe([], "@keyframes {$name} animates a property that is not composited");
    }

    expect($css)->toMatch('/@media \(prefers-reduced-motion:reduce\)\{\s*\.lgx \.lgx-lt\{display:none\}\s*\.lgx,\.lgx \*\{animation:none!important\}\s*\}/')
        ->and(HeaderSettings::SCHEMA['logo_glow'][2])->toBe('off');

    // The animation switch removes the shine from the page, not just hides it.
    lgSet(['logo_anim' => 'off']);
    expect(lgPage('/'))->not->toContain('lgx-lt');
    lgSet(['logo_anim' => 'on', 'logo_shine' => 0]);
    expect(lgPage('/'))->not->toContain('lgx-lt');
    lgSet(['logo_shine' => 12, 'logo_glow' => 'on']);
    $html = lgPage('/');
    expect($html)->toContain('lgx-lt')->and($html)->toContain('class="logo lgx lgx-gl"')
        ->and($html)->toContain('--lg-sh:12')->and($html)->toContain('lgx-o2');
});

it('adds no script, no request and no query, and reads the settings map no more often', function () {
    /*
     * MUTATION: read a setting outside HeaderSettings::all() in the partial
     * (a fresh Setting::query()), or add a <script> to it, and this is red.
     */
    test()->seed(\Database\Seeders\DatabaseSeeder::class);
    $product = '/product/'.Product::query()->visible()->firstOrFail()->slug.'/';

    $cost = function (string $path): array {
        $reads = 0;
        Event::listen([CacheHit::class, CacheMissed::class], static function ($e) use (&$reads): void {
            if ($e->key === 'kbb.settings') {
                $reads++;
            }
        });
        lgPage($path); // warm
        $reads = 0;
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = lgPage($path);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [
            'queries' => $queries, 'reads' => $reads,
            'scripts' => substr_count($html, '<script'),
            'requests' => preg_match_all('/<(?:link|img|source|iframe)\b[^>]*(?:href|src|srcset)=/', $html),
        ];
    };

    foreach (['/', $product] as $path) {
        lgSet(['logo_style' => 'text']);
        $before = $cost($path);
        lgSet(['logo_style' => 'lotus']);
        $after = $cost($path);

        expect($after)->toBe($before, "{$path}: the lockup changed the page's cost");
    }

    $partial = (string) file_get_contents(resource_path('views/partials/logo-lockup.blade.php'));
    expect($partial)->not->toContain('<script')->and($partial)->not->toMatch('/\son[a-z]+=/');
});

it('keeps kbb.css\'s fallbacks and the server\'s defaults the same numbers', function () {
    /*
     * The page prints a --lg-* property only when it differs from the
     * fallback in kbb.css, so the two have to agree or a shop at its defaults
     * draws the wrong size with nothing printed to say so. MUTATION: change
     * `var(--lg-sm,14)` to 15 in kbb.css, or logo_name_m's default to 15, and
     * this is red.
     */
    $css = lgCss();
    preg_match_all('/var\((--lg-[a-z0-9]+),([^)]+)\)/', $css, $m, PREG_SET_ORDER);

    $seen = [];
    foreach ($m as [, $name, $fallback]) {
        $seen[$name][] = $fallback;
    }

    foreach (HeaderSettings::LOCKUP_CSS_DEFAULTS as $name => $value) {
        expect($seen)->toHaveKey($name);
        foreach (array_unique($seen[$name]) as $fallback) {
            expect(is_numeric($value) ? (float) $fallback : strtoupper($fallback))
                ->toBe(is_numeric($value) ? (float) $value : strtoupper($value), "{$name} falls back to {$fallback} in kbb.css, the server's default is {$value}");
        }
    }

    // And the defaults are what the server works out: no style at all.
    expect(app(HeaderSettings::class)->lockup()['style'])->toBe('');
});

it('fits the default lockup into the phone row the way Chromium measured it', function () {
    /*
     * The arithmetic behind the no-clipping proof. Measured in Chromium with
     * the default header (tools/lg2-header.cjs): the room the row leaves the
     * logo is 98px at 320, 138 at 360, 153 at 375, 168 at 390 and 208 at 430.
     * The lockup must be one line from 360 up, stack at 320, and carry its
     * tagline only where it fits at full size (430).
     *
     * MUTATION: drop SAFETY to 1.0 or the stacking threshold to 0.5 and the
     * widths below move out of their bands.
     */
    $fit = LogoLockup::fit('K-Beauty', 'Bliss', 'Korean Skincare & Makeup', true, 14, 24, 5, 8);
    $one = 100 / $fit['c1'];

    foreach ([320 => 98, 360 => 138, 375 => 153, 390 => 168, 430 => 208] as $width => $room) {
        $stacked = $room < $fit['r'];
        $scale = min(1, $room * ($stacked ? $fit['c2'] : $fit['c1']) / 100);
        $tag = $room >= $fit['wf'];

        expect($stacked)->toBe($width === 320, "{$width}px")
            ->and($tag)->toBe($width === 430, "{$width}px")
            ->and(min($room, $one * $scale))->toBeLessThanOrEqual($room);
    }

    // No glyph clipped means no overflow:hidden anywhere but the shine's layer.
    $css = (string) preg_replace('#/\*.*?\*/#s', '', lgCss());
    preg_match_all('/([^{}]+)\{[^{}]*overflow:hidden/', $css, $clips);
    expect(array_map('trim', $clips[1]))->toBe(['.lgx .lgx-lt']);

    // And nothing inside the lockup is a <span>: `.logo span` would colour it.
    $partial = (string) file_get_contents(resource_path('views/partials/logo-lockup.blade.php'));
    expect(preg_replace('/\{\{--.*?--\}\}/s', '', $partial))->not->toContain('<span');
});

it('draws the icon at whole pixels on a computer', function () {
    /*
     * Crisp: a 37.84px-tall icon is smeared across a pixel row. The server
     * rounds "auto" and prints an integer; a phone rounds its fit in CSS.
     * MUTATION: drop the round() in LogoLockup::iconPx() and this is red.
     */
    expect(LogoLockup::iconPx(0, 22))->toBe(38)
        ->and(LogoLockup::iconPx(0, 23))->toBe(40)
        ->and(LogoLockup::iconPx(0, 14))->toBe(24)
        ->and(LogoLockup::iconPx(31, 22))->toBe(31);

    lgSet(['logo_size' => 23]);
    expect(app(HeaderSettings::class)->lockup()['style'])->toContain('--lg-id:40')
        ->and(lgCss())->toContain('round(nearest,calc(var(--lg-im,24) * var(--u)),1px)');
});
