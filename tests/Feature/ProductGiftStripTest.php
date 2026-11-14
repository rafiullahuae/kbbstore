<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Setting;
use App\Services\ProductGiftStrip;
use App\Services\ProductMobileSections;
use App\Services\SettingsService;
use App\Support\Locale;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The free gifts strip on the product page (Lanes GS and GS2,
 * App\Services\ProductGiftStrip).
 *
 *   "Free gifts with every order. Limited time only! use this text in one
 *    line, the text should adjust as per the screen size, must be in one line.
 *    flowing underline is fine as default, give other options to choose from +
 *    existing colored options we have. keep all options incase we needed later
 *    on. give me the patch"                                  (14 Nov 2026)
 *
 * MUTATION NOTES, RUN:
 *   - ship `style` as 'kl' in SCHEMA → RED "ships on … flowing underline".
 *   - put a style's CSS back in kbb-product.css → RED "carries none of the designs".
 *   - css() returning adminCss() → RED "prints its own CSS and no other style's".
 *   - drop `isset(self::STYLES[$v])` and add a key to the select only → the hostile key case still holds (the select refuses it first).
 *   - drop INSERT_BEFORE → RED "a layout saved before the strip" (it lands last).
 *   - print `$all['c1']` raw in style() → RED on the hostile-colour case.
 *   - `'<b>'.$one.'</b>'` (no e()) in html() → RED on the HTML-in-text case.
 *   - `white-space:nowrap` removed from BASE → RED "one line".
 */
function pgsTAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Strip Owner',
        'email' => 'gs-owner-'.Str::random(6).'@example.test',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);
}

function pgsTProduct(): Product
{
    return Product::create([
        'slug' => 'gs-'.Str::lower(Str::random(8)),
        'name' => 'Heartleaf 77% Soothing Toner',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'sale_price' => 7400,
        'stock_status' => 'instock',
        'short_description' => '<p>A gentle daily toner.</p>',
    ])->fresh();
}

function pgsTSet(array $values): void
{
    foreach ($values as $key => $value) {
        app(SettingsService::class)->set($key, $value);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    app()->forgetInstance(ProductGiftStrip::class);
    app()->forgetInstance(ProductMobileSections::class);
}

function pgsTPage(Product $p, string $prefix = ''): string
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    return (string) test()->get($prefix.'/product/'.$p->slug.'/')->assertOk()->getContent();
}

/** The strip element, or '' when there is none. */
function pgsTStrip(string $html): string
{
    return preg_match('#<div class="pdp-gs pm-sec pm-giftstrip[^"]*"[^>]*>.*?</div><!--/pm-->#s', $html, $m) === 1 ? $m[0] : '';
}

/** The <style> printed beside the strip, or ''. */
function pgsTCss(string $html): string
{
    return preg_match('#<style>(\.pdp-gs\{.*?)</style><div class="pdp-gs #s', $html, $m) === 1 ? $m[1] : '';
}

const PGS_T_HEAD_CSS_BYTES = 148589; // kbb-product.css at the head this lane started from (Lane GS's KL design inside)

it('ships on, directly above Tabby & Tamara, in the flowing underline, with the new words on one line', function () {
    // THE DEFECT: the confetti strip he called "temu fake / scam", or two lines.
    $html = pgsTPage(pgsTProduct());
    $strip = pgsTStrip($html);

    expect($strip)->not->toBe('')
        ->and($strip)->toContain('pgs-g-line pgs-s-underline')
        ->and($strip)->toContain('<span class="pgs-t"><b>Free gifts with every order.</b> <span class="pgs-l">Limited time only!</span></span>')
        ->and($strip)->toContain('<i class="pgs-u" aria-hidden="true"></i>')
        ->and($strip)->not->toContain('class="cf')
        ->and($strip)->toContain('<svg viewBox="0 0 24 24" width="24" height="24"')
        // Only the words' width: every other value is the style's own.
        ->and($strip)->toContain('style="--pgs-w:'.ProductGiftStrip::emWidth('Free gifts with every order.', 'Limited time only!').'"')
        ->and(substr_count($html, 'class="pdp-gs '))->toBe(1);

    $at = strpos($html, '<div class="pdp-gs ');
    $end = $at + strlen($strip);
    $pay = strpos($html, 'pdp-paylater pm-sec pm-paylater');
    expect($pay)->toBeGreaterThan($end);
    expect(substr($html, $end, $pay - $end))->not->toContain('pm-sec');
    expect($html)->toContain('--pm-o-price:4;--pm-o-giftstrip:5;--pm-o-paylater:6;');

    // One line, sized by CSS alone: nowrap, the strip a size container, the
    // container-unit sum inside @supports with a plain-unit one outside.
    $css = pgsTCss($html);
    expect($css)->toBe(ProductGiftStrip::cssFor('underline'))
        ->and($css)->toContain('white-space:nowrap')
        ->and($css)->toContain('container-type:inline-size')
        ->and($css)->toContain('@supports (font-size:1cqi){.pdp-gs .pgs-t{font-size:min(var(--pgs-fs,15px),calc((100cqi');
    $outside = (string) preg_replace('/@supports \(font-size:1cqi\)\{.*?\}\}/s', '', $css);
    expect($outside)->not->toContain('cqi')->not->toContain('clamp(');
});

it('measures the words the way the CSS divides them: Outfit widths, bold, and Arabic a little wide', function () {
    // Measured in Chromium on the product page: 20.38 em for the English line
    // (bold + regular), 19.55 em for the Arabic. The estimate may be a little
    // wide (the words then sit a touch smaller), never narrow (they would clip).
    $en = ProductGiftStrip::emWidth('Free gifts with every order.', 'Limited time only!');
    $ar = ProductGiftStrip::emWidth('هدايا مجانية مع كل طلب.', 'لفترة محدودة فقط!');
    expect($en)->toBeGreaterThanOrEqual(2038)->toBeLessThan(2038 * 1.05)
        ->and($ar)->toBeGreaterThanOrEqual(1955)->toBeLessThan(1955 * 1.25)
        ->and(ProductGiftStrip::emWidth('', ''))->toBe(100);
});

it('still shows in its place when Tabby & Tamara is switched off', function () {
    pgsTSet(['pdpms_tabby_on' => false, 'pdpms_tamara_on' => false]);
    $html = pgsTPage(pgsTProduct());

    expect($html)->not->toContain('pm-paylater')
        ->and(pgsTStrip($html))->not->toBe('');
});

it('reads the new Arabic on /ar, from the published strings, right to left', function () {
    pgsTSet([Locale::SETTING_ENABLED => '1', Locale::SETTING_RTL => '1']);
    $html = pgsTPage(pgsTProduct(), '/ar');
    $strip = pgsTStrip($html);

    expect($strip)->toContain('<b>هدايا مجانية مع كل طلب.</b> <span class="pgs-l">لفترة محدودة فقط!</span>')
        ->and($strip)->not->toContain('Free gifts')
        ->and($strip)->not->toContain('لا تفوّت')
        ->and($strip)->toContain('--pgs-w:'.ProductGiftStrip::emWidth('هدايا مجانية مع كل طلب.', 'لفترة محدودة فقط!'))
        ->and($html)->toMatch('/<html[^>]*dir="rtl"/');

    pgsTSet(['pgs_line1_ar' => 'هدية لك']);
    expect(pgsTStrip(pgsTPage(pgsTProduct(), '/ar')))->toContain('<b>هدية لك</b>');
});

it('moves an Arabic row Lane GS published to the new words, and leaves one he typed alone', function () {
    $mig = require database_path('migrations/2028_01_25_100000_product_gift_strip_one_line_arabic.php');
    $q = fn (string $f) => DB::table('translations')->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)->where('field', $f);

    $q('store.product.gift_strip_1')->update(['value' => 'هدايا مجانية مع كل طلب']); // Lane GS's wording
    $q('store.product.gift_strip_2')->update(['value' => 'نص كتبه المالك']);          // his own
    $mig->up();

    expect($q('store.product.gift_strip_1')->value('value'))->toBe('هدايا مجانية مع كل طلب.')
        ->and($q('store.product.gift_strip_2')->value('value'))->toBe('نص كتبه المالك');

    $q('store.product.gift_strip_2')->delete();
    $mig->up();
    expect($q('store.product.gift_strip_2')->value('value'))->toBe('لفترة محدودة فقط!');
});

it('draws no bytes at all when switched off — no strip and no <style> — and hides on a phone only through its Mobile sections row', function () {
    $p = pgsTProduct();
    pgsTSet(['pgs_on' => false]);
    $html = pgsTPage($p);
    expect($html)->not->toContain('pdp-gs')->and($html)->not->toContain('pgs-');

    pgsTSet(['pgs_on' => true]);
    $clean = app(ProductMobileSections::class)->validate(['on' => ['giftstrip' => false]]);
    app(ProductMobileSections::class)->saveLayout($clean);
    pgsTSet([]);
    $html = pgsTPage($p);
    expect($html)->toContain('pm-off-giftstrip')->and(pgsTStrip($html))->not->toBe('');
    expect(file_get_contents(resource_path('css/kbb/kbb-product.css')))->toContain('.pm-off-giftstrip .pm-giftstrip{display:none}');
});

it('puts the strip above Tabby & Tamara in a phone layout saved before it existed', function () {
    $old = array_values(array_diff(array_keys(ProductMobileSections::SECTIONS), ['giftstrip']));
    pgsTSet([ProductMobileSections::LAYOUT_KEY => ['order' => $old, 'on' => [], 'space' => []]]);

    $order = app(ProductMobileSections::class)->layout()['order'];
    expect(array_search('giftstrip', $order, true) + 1)->toBe(array_search('paylater', $order, true));

    $clean = app(ProductMobileSections::class)->validate(['order' => ['related', 'paylater', 'title']]);
    expect(array_slice($clean['order'], 0, 3))->toBe(['related', 'giftstrip', 'paylater']);
});

it('moves on a laptop to the anchor he picks, and hides there on "Not shown"', function () {
    $p = pgsTProduct();

    foreach (['price' => 'pm-sec pm-price', 'cart' => 'pm-sec pm-cart'] as $desk => $before) {
        pgsTSet(['pgs_desk' => $desk]);
        $html = pgsTPage($p);
        expect(strpos($html, 'class="pdp-gs '))->toBeLessThan(strpos($html, $before))
            ->and(pgsTStrip($html))->toContain('pgs-d-'.$desk);
        expect(substr_count($html, 'class="pdp-gs '))->toBe(1)
            ->and(substr_count($html, '<style>.pdp-gs{'))->toBe(1);
    }

    pgsTSet(['pgs_desk' => 'aftercart']);
    $html = pgsTPage($p);
    expect(strpos($html, 'class="pdp-gs '))->toBeGreaterThan(strpos($html, 'pm-sec pm-cart'));

    pgsTSet(['pgs_desk' => 'off']);
    expect(pgsTStrip(pgsTPage($p)))->toContain('pgs-d-off');
    expect(file_get_contents(resource_path('css/kbb/kbb-product.css')))->toContain('.pdp .pm-giftstrip.pgs-d-off{display:none}');
});

it('prints, for every style, that style\'s own constant CSS and markup and no other style\'s', function () {
    // THE DEFECT the speed rule exists for: a page shipping 27 designs to show one.
    $p = pgsTProduct();
    $keys = array_keys(ProductGiftStrip::STYLES);
    expect($keys)->toHaveCount(27)
        ->and(array_keys(ProductGiftStrip::SCHEMA['style'][4]))->toBe($keys);

    foreach ($keys as $key) {
        pgsTSet(['pgs_style' => $key]);
        $html = pgsTPage($p);
        $css = pgsTCss($html);
        $strip = pgsTStrip($html);

        expect($css)->toBe(ProductGiftStrip::cssFor($key), $key)
            ->and($strip)->toMatch('/ pgs-s-'.$key.'[ "]/')
            ->and($strip)->toContain('<span class="pgs-t"><b>Free gifts with every order.</b>');

        foreach ($keys as $other) {
            if ($other !== $key) {
                expect(preg_match('/\\.pgs-s-'.$other.'[{ .:]/', $css))->toBe(0, "$key carries $other");
            }
        }

        // The confetti only on the confetti styles; the ticker's copies hidden from a reader.
        expect(str_contains($strip, '<i class="cf c1">'))->toBe(str_starts_with($key, 'k'));
        if ($key === 'd') {
            expect(substr_count($strip, 'class="pgs-t"'))->toBe(4)->and(substr_count($strip, 'class="pgs-t" aria-hidden="true"'))->toBe(3);
        }
    }

    // A key that is not one of its own → the default, never a class or CSS from the value.
    pgsTSet(['pgs_style' => 'x"><script>alert(1)</script>']);
    $html = pgsTPage($p);
    expect(pgsTStrip($html))->toContain('pgs-s-underline')->and($html)->not->toContain('<script>alert(1)')
        ->and(pgsTCss($html))->toBe(ProductGiftStrip::cssFor('underline'));
    pgsTSet(['pgs_style' => 'adminCss']);
    expect(pgsTCss(pgsTPage($p)))->toBe(ProductGiftStrip::cssFor('underline'));
});

it('prints only validated values: hostile colours, numbers, selects and HTML are defaulted, clamped or escaped', function () {
    pgsTSet([
        'pgs_c1' => 'red;}body{display:none',
        'pgs_c2' => '#abc',
        'pgs_ink' => 'javascript:alert(1)',
        'pgs_accent' => '#c8365e',
        'pgs_icon_bg' => '#000000',
        'pgs_height' => 99999,
        'pgs_radius' => -40,
        'pgs_fs' => '1e9',
        'pgs_above' => 'lots',
        'pgs_speed' => 'warp" onload="x',
        'pgs_desk' => '<script>',
        'pgs_line1' => '<img src=x onerror=alert(1)>Gifts & more',
        'pgs_line2' => '"><script>alert(1)</script>',
    ]);
    $html = pgsTPage(pgsTProduct());
    $strip = pgsTStrip($html);

    expect($strip)->not->toContain('<img')->and($strip)->not->toContain('<script')
        ->and($strip)->not->toContain('onerror')->and($strip)->not->toContain('body{');

    preg_match('/<div class="pdp-gs pm-sec pm-giftstrip([^"]*)" style="([^"]*)"/', $strip, $m);
    expect($m[1] ?? null)->toBe(' pgs-g-line pgs-s-underline');   // unknown speed / anchor → the defaults
    $style = $m[2] ?? '';
    expect($style)->toMatch('/^--pgs-w:\d+;--pgs-ac:#C8365E;--pgs-c2:#AABBCC;--pgs-ib:rgba\(0,0,0,\.55\);--pgs-h:120px;--pgs-fs:24px$/');
    foreach (explode(';', $style) as $decl) {
        expect($decl)->toMatch('/^--pgs-[a-z0-9]+:(\d+|#[0-9A-F]{6}|\d+(\.5)?px|rgba\(\d{1,3},\d{1,3},\d{1,3},\.55\))$/');
    }
});

it('switches the icon, confetti, movement and speed with literal classes and no extra markup', function () {
    $p = pgsTProduct();
    pgsTSet(['pgs_style' => 'kl', 'pgs_confetti' => false, 'pgs_icon' => false, 'pgs_speed' => 'slow']);
    $strip = pgsTStrip(pgsTPage($p));
    expect($strip)->not->toContain('class="cf')->and($strip)->not->toContain('pgs-i')
        ->and($strip)->toContain('pgs-noicon')->and($strip)->toContain('pgs-sp-slow');

    pgsTSet(['pgs_shine' => false]);
    expect(pgsTStrip(pgsTPage($p)))->toContain('pgs-still')->not->toContain('pgs-sp-');
});

it('adds no query and no settings-map read to the product page', function () {
    $p = pgsTProduct();
    pgsTPage($p);

    $count = static function () use ($p): array {
        $reads = 0;
        Event::listen([CacheHit::class, CacheMissed::class], static function ($e) use (&$reads): void {
            if ($e->key === 'kbb.settings') {
                $reads++;
            }
        });
        DB::flushQueryLog();
        DB::enableQueryLog();
        pgsTPage($p);
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$q, $reads];
    };

    pgsTSet(['pgs_on' => false]);
    pgsTPage($p);
    [$off] = $count();
    pgsTSet(['pgs_on' => true, 'pgs_c1' => '#112233', 'pgs_desk' => 'cart', 'pgs_style' => 'm']);
    pgsTPage($p);
    [$on, $reads] = $count();

    expect($on)->toBe($off)->and($reads)->toBeLessThanOrEqual(1);
});

it('saves through the product page endpoint, refusing unknown keys, and hands the admin every style', function () {
    test()->actingAs(pgsTAdmin(), 'admin');

    $body = test()->getJson('/admin-api/product-page')->assertOk()->json();
    expect($body['giftstrip']['phone'])->toBeTrue()
        ->and(array_column($body['giftstrip']['options'][0]['fields'], 'key'))->toBe(ProductGiftStrip::TABS['giftstrip'][2])
        ->and(array_keys($body['giftstrip']['look']['styles']))->toBe(array_keys(ProductGiftStrip::STYLES))
        ->and($body['giftstrip']['look']['css'])->toBe(ProductGiftStrip::adminCss())
        ->and($body['giftstrip']['look']['styles']['underline']['inner'])->toContain('%T%');

    test()->postJson('/admin-api/product-page', ['giftstrip' => ['options' => ['nope' => 1]]])->assertStatus(422);
    test()->postJson('/admin-api/product-page', ['giftstrip' => ['options' => ['fs1' => 15]]])->assertStatus(422);
    test()->postJson('/admin-api/product-page', ['giftstrip' => ['bogus' => 1]])->assertStatus(422);

    $r = test()->postJson('/admin-api/product-page', ['giftstrip' => [
        'options' => ['style' => 'c', 'c1' => '#00ff00', 'ink' => '', 'height' => 500, 'speed' => 'fast', 'desk' => 'nowhere'],
        'phone' => false,
    ]])->assertOk()->json();

    expect($r['ok'])->toBeTrue()->and($r['giftstrip']['phone'])->toBeFalse();
    pgsTSet([]);
    $all = app(ProductGiftStrip::class)->all();
    expect($all['style'])->toBe('c')->and($all['c1'])->toBe('#00FF00')->and($all['ink'])->toBe('')
        ->and($all['height'])->toBe(120)->and($all['speed'])->toBe('fast')->and($all['desk'])->toBe('paylater');

    test()->postJson('/admin-api/product-page', ['giftstrip' => ['options' => ['style' => 'nope']]])->assertOk();
    pgsTSet([]);
    expect(app(ProductGiftStrip::class)->styleKey())->toBe('underline');
});

it('keeps the migration, the drafts and the interface strings saying the same words', function () {
    $mig = (string) file_get_contents(database_path('migrations/2028_01_25_100000_product_gift_strip_one_line_arabic.php'));
    $strings = \App\Services\Translation\InterfaceStrings::flat();
    $drafts = (string) file_get_contents((new ReflectionClass(\App\Services\Translation\ArabicInterfaceDrafts::class))->getFileName());

    expect($strings['store.product.gift_strip_1'])->toBe('Free gifts with every order.')
        ->and($strings['store.product.gift_strip_2'])->toBe('Limited time only!')
        ->and(ProductGiftStrip::defaults()['line1'])->toBe($strings['store.product.gift_strip_1'])
        ->and(ProductGiftStrip::defaults()['line2'])->toBe($strings['store.product.gift_strip_2']);

    foreach (['store.product.gift_strip_1', 'store.product.gift_strip_2'] as $k) {
        expect($mig)->toContain(var_export($strings[$k], true));
    }

    foreach (['هدايا مجانية مع كل طلب.', 'لفترة محدودة فقط!'] as $ar) {
        expect($mig)->toContain("'$ar'")->and($drafts)->toContain("'$ar'");
    }
});

it('keeps the designs out of the render-blocking kbb-product.css, which is smaller than before', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));

    expect(strlen($css))->toBeLessThan(PGS_T_HEAD_CSS_BYTES)
        ->and($css)->not->toContain('.pdp-gs')
        ->and($css)->not->toContain('@keyframes pgs')
        ->and($css)->not->toContain('pgs-s-')
        ->and($css)->not->toContain('#FFD43B');
});

it('animates only transform and opacity (bar the two letter-clipped shines), and stops under reduced motion', function () {
    $all = ProductGiftStrip::adminCss();
    preg_match_all('/@keyframes (pgs\w+)\{(.*?)\}\}/s', $all, $m, PREG_SET_ORDER);
    expect(count($m))->toBeGreaterThan(20);

    foreach ($m as [, $name, $frames]) {
        preg_match_all('/([a-z-]+):/', $frames, $props);
        $allowed = $name === 'pgsTextShine' ? ['background-position'] : ['transform', 'opacity'];
        expect(array_diff(array_unique($props[1]), $allowed))->toBe([], $name);
    }

    // Which styles use the exception: options only, never the default.
    foreach (ProductGiftStrip::STYLES as $key => $s) {
        expect(str_contains($s[6], 'pgsTextShine'))->toBe(in_array($key, ['rose', 'c'], true), $key);
    }

    expect(ProductGiftStrip::BASE)->toContain('@media (prefers-reduced-motion:reduce){.pdp-gs,.pdp-gs *,.pdp-gs::before,.pdp-gs::after,.pdp-gs *::before,.pdp-gs *::after{animation:none!important}}');
    // No newer CSS function beside var() (CLAUDE.md landmine), and nothing physical to flip on /ar.
    expect($all)->not->toContain('color-mix(')->not->toContain('round(')
        ->and(preg_match('/(?<![-\w])(left|right)\s*:/', $all))->toBe(0);
    // Container units and clamp() only inside @supports (font-size:1cqi).
    $outside = (string) preg_replace('/@supports \(font-size:1cqi\)\{(?:[^{}]*\{[^{}]*\})*\}/', '', $all);
    $outside = (string) preg_replace('/@container[^{]*\{(?:[^{}]*\{[^{}]*\})*\}/', '', $outside);
    expect($outside)->not->toContain('cqi')->not->toContain('clamp(');
});
