<?php

declare(strict_types=1);

/**
 * PHONES SET TO LARGE TEXT SEE THE NORMAL SHOP — Lane BT.
 *
 * The owner, 17 October: a customer opened a product from Instagram on an
 * Android phone set to large text and every word was huge -- the tagline on
 * three lines, the wordmark over the Arabic chip, the search placeholder, the
 * "-31%" badge, the brand chip, the title -- while pictures and icons kept
 * their size. "can we restrict our site to not zoom and open the normal site
 * which we have in actual" ... "keep that fully separate and the site should
 * auto detect".
 *
 * THE MECHANISM (Chromium 141 source, docs/lane-bt/README.md): Android WebView
 * sets the frame's text zoom to the phone's font scale (AwSettings
 * mTextSizePercent = 100 x fontScale -> SetTextZoomFactor(percent / 100)), and
 * Blink multiplies every computed font-size and every px line-height by it
 * (font_builder.cc, style_builder_converter.cc). text-size-adjust is only read
 * when text AUTOSIZING is on, so the shop's `-webkit-text-size-adjust:100%`
 * cannot stop it and no CSS property or meta tag can. Reproduced faithfully by
 * calling the real blink::LocalFrame::SetTextZoomFactor (tools/bt-shots.cjs).
 *
 * THE ANSWER, in two halves:
 *   partials/big-text-guard.blade.php -- one inline script first in <body>
 *     that reads its OWN computed font-size (it carries font-size:16px and
 *     renders nothing) and, only when that is over 16.64px, document.writes
 *   resources/js/kbb/big-text.js -- the corrections, parser-blocking for that
 *     visitor only, so the page never paints huge first.
 * Appearance → Site layout → Large phone text.
 */

use App\Models\AdminUser;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SiteLayoutAdminRoutes;

const BT_OPEN = '<script style="font-size:16px">';

/** The guard exactly as the shop prints it (rendered, Blade comment gone). */
function btGuard(): string
{
    return view('partials.big-text-guard')->render();
}

/** big-text.js without its comments. */
function btJs(): string
{
    return (string) preg_replace(['#/\*.*?\*/#s', '#(^|\s)//[^\n]*#'], ['', '$1'], (string) file_get_contents(resource_path('js/kbb/big-text.js')));
}

function btSet(string $value): void
{
    app(SettingsService::class)->set('layout_big_text', $value);
    SettingsService::forgetMemo();
}

beforeEach(function () {
    SiteLayoutAdminRoutes::wire($this->app);
    SettingsService::forgetMemo();
});

it('ships ON, as the owner asked, and prints the guard once, first thing in <body>, before the header', function () {
    /*
     * Rule 1 (30 September): he asked for it, so `normal` is the default.
     * First thing in <body>: after the head's stylesheets (an inline script
     * waits for them) and before any shop markup paints, so an enlarged phone
     * never sees a frame of huge text.
     *
     * MUTATION, RUN: default 'normal' -> 'grow' -> red on the first line;
     * move the include below @include('partials.header') -> red on the order.
     */
    expect(SiteLayout::SCHEMA['big_text'][2])->toBe('normal')
        ->and(app(SiteLayout::class)->bigTextNormal())->toBeTrue();

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, BT_OPEN))->toBe(1);

    $body = strpos($html, '<body');
    $guard = strpos($html, BT_OPEN);

    expect($body)->toBeLessThan($guard)
        ->and($guard)->toBeLessThan(strpos($html, '<header'))
        ->and(strpos($html, '</head>'))->toBeLessThan($guard);
});

it('prints nothing at all on "Let the phone enlarge the text": the page is the bytes it was', function () {
    /*
     * The other half of rule 1: switched back, not one byte of any page moves.
     * The guard page minus the guard and its own newline IS the grow page.
     *
     * MUTATION, RUN: put the include on the body tag's own line -> red (the
     * grow page then loses that line's newline).
     */
    $slug = \App\Models\Product::query()->value('slug');
    $on = (string) $this->get('/product/'.$slug.'/')->getContent();
    btSet('grow');
    $off = (string) $this->get('/product/'.$slug.'/')->getContent();

    expect($off)->not->toContain(BT_OPEN)->and($off)->not->toContain('big-text');

    $mask = fn (string $h): string => (string) preg_replace('#(name="csrf-token" content=|"csrf":|value=)"[^"]*"#', '$1""', $h);

    expect(substr_count($on, btGuard()))->toBe(1);
    expect($mask(str_replace(btGuard(), '', $on)))->toBe($mask($off));
})->skip(fn () => \App\Models\Product::query()->count() === 0, 'no product in this database');

it('costs no query: one key from the settings map the request already holds', function () {
    /*
     * Speed is frozen (CLAUDE.md, 6 October). The setting is read through
     * SiteLayout::only() from SettingsService's per-request map.
     *
     * MUTATION, RUN: read it with Setting::query() in bigTextNormal() -> red.
     */
    $count = function (): int {
        SettingsService::forgetMemo();
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        $this->get('/')->assertOk();

        return $n;
    };
    btSet('grow');
    $this->get('/');
    $grow = $count();
    btSet('normal');
    $this->get('/');

    expect($count())->toBe($grow);
});

it('gives a normal visitor one style read of itself and nothing else: no layout read, no timer, no request', function () {
    /*
     * CLAUDE.md rule 4: no script that measures layout. getComputedStyle here
     * reads ONE value -- the script element's own font-size, which it declares
     * itself and which renders nothing (a <script> is display:none) -- a style
     * read with no layout behind it. It is the only signal there is: text zoom
     * leaves em media queries alone (measured: (min-width:20em) and
     * (min-width:30em) answer the same at 1.0 and 1.6) and every width.
     * Everything else is behind `r>1.04`, and the only thing there is the
     * document.write of the corrections file.
     *
     * MUTATION, RUN: read getComputedStyle(document.body) -> red; drop the
     * `if(r>1.04)` -> red; add a setInterval -> red.
     */
    $g = btGuard();

    expect(substr_count($g, 'getComputedStyle('))->toBe(1)
        ->and($g)->toContain("var s=d.currentScript,r=s?parseFloat(getComputedStyle(s).fontSize)/16:1,l='\\x3c';if(r>1.04)d.write(")
        ->and(substr_count($g, 'd.write('))->toBe(1)
        ->and(strlen($g))->toBeLessThan(400);

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'scrollWidth', 'innerWidth',
        'ResizeObserver', 'IntersectionObserver', 'setInterval', 'setTimeout', 'requestAnimationFrame', 'fetch(',
        'XMLHttpRequest', 'innerHTML', 'matchMedia', 'localStorage', 'cookie', 'classList', 'MutationObserver'] as $banned) {
        expect(str_contains($g, $banned))->toBeFalse("the inline guard uses {$banned}");
    }
});

it('writes the corrections file by its built address, a classic script with no import or export', function () {
    /*
     * The file is an entry in vite.config.js and the manifest, written as
     * <script src> (parser-blocking, so it runs before the page paints). A
     * module would be deferred, and the page would paint huge first.
     *
     * MUTATION, RUN: drop the entry from vite.config.js -> red on the manifest.
     */
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $entry = $manifest['resources/js/kbb/big-text.js'] ?? null;

    expect($entry)->not->toBeNull()
        ->and((string) file_get_contents(base_path('vite.config.js')))->toContain("'resources/js/kbb/big-text.js',")
        ->and(btGuard())->toContain('script src="'.\Illuminate\Support\Facades\Vite::asset('resources/js/kbb/big-text.js').'" data-kbb-zoom="');

    $built = (string) file_get_contents(public_path('build/'.$entry['file']));
    expect($built)->not->toMatch('/\bimport\b|\bexport\b/')
        ->and($built)->toContain('getAttribute("data-kbb-zoom")');
});

it('carries no "<" in the inline guard, so anything that strips tags from a page reads the page\'s words', function () {
    /*
     * MEASURED by the suite: with `i<t.length` inline, strip_tags() read
     * "<t.length...>" as a tag and swallowed homepage text up to the next ">"
     * -- TrustClaimsAreTheOwnersTest lost "100% original". The tag is written
     * with the string escape \x3c (not String.fromCharCode, which
     * RichTextWritePathTest rightly bans from a product page), and the ratio
     * rides in data-kbb-zoom (data-r is InstantNavTest's to forbid, data-bt is Buy together's).
     *
     * MUTATION, RUN: write '<script src=' literally -> red.
     */
    $body = substr(btGuard(), strlen(BT_OPEN));
    $body = substr($body, 0, (int) strrpos($body, '</script>'));

    expect(substr_count($body, '<'))->toBe(0);
});

it('starts nothing in the corrections file until it has checked the ratio it was given', function () {
    /*
     * The file also returns at once without a ratio over 1.04, so a stray
     * request for it (a prefetch, a cache warm-up) changes nothing.
     *
     * MUTATION, RUN: move h.classList.add above the return -> red.
     */
    $js = btJs();
    $return = strpos($js, "if (!(r > 1.04)) return;");

    expect($return)->not->toBeFalse();

    foreach (['classList', 'createElement', 'insertBefore', 'setProperty', 'MutationObserver', 'addEventListener'] as $write) {
        $at = strpos($js, $write);
        expect($at)->not->toBeFalse("big-text.js has no {$write}")
            ->and($at)->toBeGreaterThan($return, "{$write} runs before the return");
    }

    expect(substr_count($js, "h.classList.add('kbb-bt')"))->toBe(1);

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'scrollWidth', 'ResizeObserver',
        'setInterval', 'setTimeout', 'requestAnimationFrame', 'fetch(', 'XMLHttpRequest', 'getComputedStyle'] as $banned) {
        expect(str_contains($js, $banned))->toBeFalse("big-text.js uses {$banned}");
    }
});

it('divides absolute font sizes and line-heights, and multiplies back the em lengths that division would shrink', function () {
    /*
     * MEASURED in Chromium 141 with the real text zoom at 1.6: font-size and a
     * px line-height grow (ConvertLineHeight is the only other converter that
     * multiplies by TextZoomFactor); width, padding, `padding:1em`,
     * `letter-spacing:.1em`, rem lengths and pictures do NOT. Once a specified
     * size is divided, an em/rem/ic length on any OTHER property shrinks with
     * it (1em padding 10px -> 6.25px; -.02em spacing -0.48px -> -0.3px while
     * the minus sign was not matched), so those are multiplied back.
     * ch/ex/cap/lh follow the real font (10ch 50px -> 80px -> 50px).
     *
     * Custom properties are never changed: no custom property on this shop
     * holds an em length, and one used as a size must not be multiplied twice.
     *
     * MUTATION, RUN: scale(v, E, r) -> '' -> red; drop the relative-unit check
     * on var() sizes -> red; drop the `--` branch -> red.
     */
    $js = btJs();

    expect($js)->toContain("n = p === 'font-size' ? size(v) : p === 'line-height' ? scale(v, A, k) : scale(v, E, r);")
        ->and($js)->toContain("if (p.indexOf('--') === 0) { D[p] = (D[p] | 0) | (L.test(v) ? 1 : 2); continue; }")
        ->and($js)->toContain('/\\d(r?em|ex|ch|lh|%)/.test(w)')
        ->and($js)->toContain('(px|pt|pc|cm|mm|q|in|[sld]?v(?:w|h|i|b|min|max)|cq(?:w|h|i|b|min|max))(?![\\w-])/gi')
        ->and($js)->toContain('var E = /(^|[^\\w.#-])(-?\\d*\\.?\\d+)(r?em|ic)(?![\\w-])/gi;')
        ->and(substr_count($js, '(^|[^\\w.#-])(-?\\d*\\.?\\d+)'))->toBe(2);

    foreach (glob(resource_path('css/kbb/*.css')) as $file) {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file));
        expect(preg_match('/--[\w-]+\s*:[^;{}]*\d(r?em|ic)\b/', $css))->toBe(0, basename($file).' has a custom property in em');
    }
});

it('never skips a property that can hold an em length', function () {
    /*
     * MEASURED at 1.6: skipping `mask` and `background` (to save reads) drew
     * the product description's fade one line short and moved a band of the
     * cart -- both use em inside a gradient. The skip list is properties that
     * cannot hold a length at all.
     *
     * MUTATION, RUN: add |mask| to SKIP -> red.
     */
    preg_match('#var SKIP = /\^\((.*)\)/;#', btJs(), $m);
    expect($m)->not->toBeEmpty();
    expect(btGuard())->not->toContain('fromCharCode')->and(btGuard())->not->toContain('data-r=')->and(btGuard())->not->toContain('data-bt=');
    // And the attribute it uses is nobody else's (data-bt was Buy together's:
    // ProductBuyTogetherRightColumnTest counted two).
    $views = shell_exec('grep -rl "data-kbb-zoom" '.escapeshellarg(resource_path('views')).' '.escapeshellarg(resource_path('js')));
    expect(array_values(array_filter(array_map('basename', explode("\n", (string) $views)))))->toBe(['big-text-guard.blade.php', 'big-text.js']);

    // The JS pattern, run as PCRE against real longhand names.
    $skip = '/^('.$m[1].')/';
    foreach (['mask-image', 'background-image', 'background-position-x', 'background-size', 'filter', 'clip-path', 'transform',
        'box-shadow', 'border-top-left-radius', 'margin-top', 'padding-left', 'width', 'max-height', 'column-gap',
        'letter-spacing', 'text-indent', 'vertical-align', 'line-height', 'font-size', 'border-top-width', 'outline-offset'] as $kept) {
        expect(preg_match($skip, $kept))->toBe(0, "SKIP skips {$kept}");
    }
    foreach (['color', 'display', 'font-weight', 'z-index'] as $skipped) {
        expect(preg_match($skip, $skipped))->toBe(1, "SKIP reads {$skipped}");
    }
});

it('divides a var() line-height only when every property it names is defined as a length', function () {
    /*
     * MEASURED on the homepage at 1.6 before this existed: the card's price row
     * and its Add to cart button kept 33.6px / 70.4px line-heights (21px / 44px
     * on a normal phone): `line-height:var(--sc-btn-h)`, the px is in the
     * DEFINITION. --sc-name-lh is 1.32, a number, which must not be divided.
     *
     * MUTATION, RUN: treat a mixed definition (D 3) as a length -> red.
     */
    $js = btJs();

    expect($js)->toContain("if (p === 'line-height' && v.indexOf('var(') > -1) { U.push([t, v]); continue; }")
        ->and($js)->toContain('if (x > 1) { ok = -1; break; }')
        ->and($js)->toContain("addEventListener('load', function () { sweep(); lineHeights(1); });");

    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    expect($css)->toContain('line-height:var(--sc-btn-h)')
        ->and(preg_match('/--sc-btn-h:\s*\d+px/', $css))->toBe(1);
});

it('leaves SVG text alone, which Blink never enlarges', function () {
    /*
     * MEASURED at 1.6: the product page's "FAST DELIVERY" badge (SVG <text>)
     * draws at its normal size under text zoom -- Blink sizes SVG text from its
     * SPECIFIED size -- and a first version that divided it drew the badge at
     * 62%. The homepage slider's ring text is `.hb-stk text{font:600 10.5px}`.
     *
     * MUTATION, RUN: drop the SVG_TEXT test in rules() -> red.
     */
    $js = btJs();

    expect($js)->toContain('var SVG_TEXT = /(^|[\\s>+~,(])(text|tspan|textpath)\\b/i;')
        ->and($js)->toContain('if (rule && props.length && rule.selectorText && SVG_TEXT.test(rule.selectorText)) return;')
        ->and($js)->toContain('function one(e) { if (e.style && !e.ownerSVGElement) fix(e.style); }');

    expect((string) file_get_contents(resource_path('views/partials/home/slider-text-box-css.blade.php')))
        ->toContain('.hb-stk text{fill:#A82F53;font:600 10.5px');
});

it('solves each px size in float32 so it multiplies back to the shop\'s own size', function () {
    /*
     * MEASURED at 1.3: with a plain n*(1/1.3), "Shop Medicube" measured
     * 145.81px against 145.91px on a normal phone -- Blink multiplies in
     * float32 and 13px landed a hair under 13. After ex() and the 3e-7-high
     * multiplier for var()/calc sizes, the product page at 1.3 matched a
     * normal phone in every element's size but one line-height (0.0001px).
     *
     * MUTATION, RUN: return n * m for px in scale() -> the drift is back.
     */
    $js = btJs();

    expect($js)->toContain('var F = Math.fround(Math.round(r * 100) / 100);')
        ->and($js)->toContain('if (p >= t && p < over) { over = p; best = B[0]; }')
        ->and($js)->toContain("m === k && u.toLowerCase() === 'px' ? ex(+n) : n * (m === k ? kUp : m)")
        ->and($js)->toContain('var kUp = k * (1 + 3e-7);');
});

it('puts back the browser\'s own em margins and the root size in rules the shop\'s own rules still beat', function () {
    /*
     * The UA stylesheet is not in document.styleSheets, so its em margins
     * (p 1em, h1 .67em ...) would shrink with the divided size. One <style>
     * inserted FIRST in <head>, the margins at :where() specificity 0, so any
     * shop rule -- a `*{margin:0}` reset included -- still wins.
     *
     * MUTATION, RUN: drop :where( from ua() -> red.
     */
    $js = btJs();

    expect($js)->toContain("function ua(x, m) { return ':where(' + x + '){margin-block:' + m * r + 'em}'; }")
        ->and($js)->toContain("ua('p,blockquote,figure,dl,ol,ul,menu,pre', 1)")
        ->and($js)->toContain("'html{font-size:' + ex(16) + 'px}input,select,textarea,button{font-size:' + 13.3333 * k + 'px}'")
        ->and($js)->toContain('d.head.insertBefore(st, d.head.firstChild);');
});

it('leaves pinch-to-zoom alone: the viewport tag is the one the shop always had', function () {
    /*
     * A shopper who needs bigger text can still pinch. Restricting zoom
     * (user-scalable=no, maximum-scale) would fail WCAG 1.4.4.
     *
     * MUTATION, RUN: add maximum-scale=1 to the layout's viewport tag -> red.
     */
    $html = (string) $this->get('/')->getContent();

    expect(substr_count($html, '<meta name="viewport" content="width=device-width,initial-scale=1">'))->toBe(1)
        ->and($html)->not->toMatch('/user-scalable\s*=\s*(no|0)|maximum-scale/i')
        ->and(btJs())->not->toContain('viewport');
});

it('adds no stylesheet rule a normal visitor could match', function () {
    /*
     * `kbb-bt` is a hook only. Any rule a later lane writes for it must start
     * with html.kbb-bt. Today there are none.
     *
     * MUTATION, RUN: add `.kbb-bt .x{}` to kbb.css -> red.
     */
    foreach (glob(resource_path('css/kbb/*.css')) as $file) {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file));
        preg_match_all('/[^{}]*kbb-bt[^{}]*\{/', $css, $m);
        foreach ($m[0] as $selector) {
            foreach (explode(',', rtrim(trim($selector), '{')) as $one) {
                expect(trim($one))->toStartWith('html.kbb-bt', basename($file).': '.trim($one));
            }
        }
    }
});

it('sits on its own tab in Appearance → Site layout and stores only its own two options', function () {
    /*
     * Rule 3: Appearance → Site layout → Large phone text → "Phones set to large
     * text". Rule 5: a select stores one of its own options or nothing.
     *
     * MUTATION, RUN: drop 'big_text' from BIG_TEXT_KEYS -> red on the tab.
     */
    $this->actingAs(AdminUser::create([
        'name' => 'BT owner', 'email' => 'bt-'.Str::random(8).'@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]), 'admin');

    $tab = collect($this->getJson('/admin-api/site-layout')->assertOk()->json('tabs'))->firstWhere('key', 'bigtext');
    $field = collect($tab['fields'])->firstWhere('key', 'big_text');

    expect(collect($tab['fields'])->pluck('key')->all())->toBe(['big_text'])
        ->and($field['label'])->toBe('Phones set to large text')
        ->and($field['value'])->toBe('normal')
        ->and(array_keys($field['options']))->toBe(['normal', 'grow']);

    $this->postJson('/admin-api/site-layout', ['settings' => ['big_text' => '<script>']])
        ->assertStatus(422)
        ->assertJson(['rejected' => ['big_text']]);

    $this->postJson('/admin-api/site-layout', ['settings' => ['big_text' => 'grow']])->assertOk();
    SettingsService::forgetMemo();

    expect(app(SiteLayout::class)->bigTextNormal())->toBeFalse()
        ->and((string) $this->get('/')->getContent())->not->toContain(BT_OPEN);
});

it('carries the measured mechanism, so the next lane does not reach for text-size-adjust', function () {
    /*
     * The integrator's first two emulations (text autosizing with
     * accessibilityFontScaleFactor, with and without TextSizeAdjustImprovements)
     * both rendered the shop normal, because -webkit-text-size-adjust:100%
     * already stops that path. The path the customer hit is the frame's text
     * zoom; the partial says so with the source files that prove it.
     */
    $src = (string) file_get_contents(resource_path('views/partials/big-text-guard.blade.php'));

    foreach (['SetTextZoomFactor', 'fontScale', 'font_builder.cc', 'style_builder_converter.cc', 'text-size-adjust', 'document.write'] as $fact) {
        expect($src)->toContain($fact);
    }
});
