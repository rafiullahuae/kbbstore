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
 * The free gifts strip on the product page (Lane GS, App\Services\ProductGiftStrip).
 *
 *   "KL Confetti + Holographic sheen is fine, please give me patch. to control
 *    on off, and change position on the product page, keep above tabby tamara
 *    section by default. also give control of colors, effects, overal height,
 *    font size etc."
 *
 * MUTATION NOTES, RUN:
 *   - ship `on` false in SCHEMA → RED "ships on".
 *   - drop INSERT_BEFORE → RED "a layout saved before the strip" (it lands last).
 *   - print `$all['c1']` raw in style() → RED on the hostile-colour case.
 *   - `{!! $kbbGs1 !!}` in the partial → RED on the HTML-in-text case.
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

it('ships on, directly above Tabby & Tamara, with his default words and no style of its own', function () {
    // THE DEFECT: the strip he chose missing, or anywhere but above Tabby & Tamara.
    $html = pgsTPage(pgsTProduct());
    $strip = pgsTStrip($html);

    expect($strip)->not->toBe('')
        ->and($strip)->toContain('<b>Free gifts with every order</b>')
        ->and($strip)->toContain('<span class="pgs-l">Limited time offer. Don&#039;t miss out!</span>')
        ->and(substr_count($strip, '<i class="cf c'))->toBe(10)
        ->and($strip)->toContain('<span class="pgs-i"><svg viewBox="0 0 24 24" width="24" height="24"')
        ->and($strip)->not->toContain('style=')
        ->and(substr_count($html, 'class="pdp-gs '))->toBe(1);

    $at = strpos($html, '<div class="pdp-gs ');
    $end = $at + strlen($strip);
    $pay = strpos($html, 'pdp-paylater pm-sec pm-paylater');
    expect($pay)->toBeGreaterThan($end);
    // Nothing between the strip and the cards: it is directly above them.
    expect(substr($html, $end, $pay - $end))->not->toContain('pm-sec');

    // The phone order: directly before paylater in Mobile sections.
    expect($html)->toContain('--pm-o-price:4;--pm-o-giftstrip:5;--pm-o-paylater:6;');
});

it('still shows in its place when Tabby & Tamara is switched off', function () {
    pgsTSet(['pdpms_tabby_on' => false, 'pdpms_tamara_on' => false]);
    $html = pgsTPage(pgsTProduct());

    expect($html)->not->toContain('pm-paylater')
        ->and(pgsTStrip($html))->not->toBe('');
});

it('reads Arabic on /ar, from the published strings, right to left', function () {
    pgsTSet([Locale::SETTING_ENABLED => '1', Locale::SETTING_RTL => '1']);
    $html = pgsTPage(pgsTProduct(), '/ar');
    $strip = pgsTStrip($html);

    expect($strip)->toContain('<b>هدايا مجانية مع كل طلب</b>')
        ->and($strip)->toContain('عرض لفترة محدودة. لا تفوّت الفرصة!')
        ->and($strip)->not->toContain('Free gifts')
        ->and($html)->toMatch('/<html[^>]*dir="rtl"/');

    // His own Arabic wins once typed.
    pgsTSet(['pgs_line1_ar' => 'هدية لك']);
    expect(pgsTStrip(pgsTPage(pgsTProduct(), '/ar')))->toContain('<b>هدية لك</b>');
});

it('draws no bytes at all when switched off, and hides on a phone only through its Mobile sections row', function () {
    $p = pgsTProduct();
    pgsTSet(['pgs_on' => false]);
    $html = pgsTPage($p);
    expect($html)->not->toContain('pdp-gs')->and($html)->not->toContain('pgs-i');

    pgsTSet(['pgs_on' => true]);
    $clean = app(ProductMobileSections::class)->validate(['on' => ['giftstrip' => false]]);
    app(ProductMobileSections::class)->saveLayout($clean);
    pgsTSet([]);
    $html = pgsTPage($p);
    expect($html)->toContain('pm-off-giftstrip')->and(pgsTStrip($html))->not->toBe('');
    expect(file_get_contents(resource_path('css/kbb/kbb-product.css')))->toContain('.pm-off-giftstrip .pm-giftstrip{display:none}');
});

it('puts the strip above Tabby & Tamara in a phone layout saved before it existed', function () {
    // THE DEFECT: the live shop has a saved Mobile sections layout; a new key
    // appended to it would put the strip at the foot of the phone page.
    $old = array_values(array_diff(array_keys(ProductMobileSections::SECTIONS), ['giftstrip']));
    pgsTSet([ProductMobileSections::LAYOUT_KEY => ['order' => $old, 'on' => [], 'space' => []]]);

    $order = app(ProductMobileSections::class)->layout()['order'];
    expect(array_search('giftstrip', $order, true) + 1)->toBe(array_search('paylater', $order, true));

    // And a POSTed list without it does the same.
    $clean = app(ProductMobileSections::class)->validate(['order' => ['related', 'paylater', 'title']]);
    expect(array_slice($clean['order'], 0, 3))->toBe(['related', 'giftstrip', 'paylater']);
});

it('moves on a laptop to the anchor he picks, and hides there on "Not shown"', function () {
    $p = pgsTProduct();

    foreach (['price' => 'pm-sec pm-price', 'cart' => 'pm-sec pm-cart'] as $desk => $before) {
        pgsTSet(['pgs_desk' => $desk]);
        $html = pgsTPage($p);
        $at = strpos($html, 'class="pdp-gs ');
        expect($at)->toBeLessThan(strpos($html, $before))
            ->and(pgsTStrip($html))->toContain('pgs-d-'.$desk);
        // still exactly one strip
        expect(substr_count($html, 'class="pdp-gs '))->toBe(1);
    }

    pgsTSet(['pgs_desk' => 'aftercart']);
    $html = pgsTPage($p);
    expect(strpos($html, 'class="pdp-gs '))->toBeGreaterThan(strpos($html, 'pm-sec pm-cart'));

    pgsTSet(['pgs_desk' => 'off']);
    expect(pgsTStrip(pgsTPage($p)))->toContain('pgs-d-off');
    expect(file_get_contents(resource_path('css/kbb/kbb-product.css')))->toContain('.pdp .pm-giftstrip.pgs-d-off{display:none}');
});

it('prints only validated values: hostile colours, numbers, selects and HTML are clamped, defaulted or escaped', function () {
    pgsTSet([
        'pgs_c1' => 'red;}body{display:none',
        'pgs_c2' => '#abc',
        'pgs_ink' => 'javascript:alert(1)',
        'pgs_icon_bg' => '#000000',
        'pgs_height' => 99999,
        'pgs_radius' => -40,
        'pgs_fs1' => '1e9',
        'pgs_fs2' => '13.26',
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
    expect($m[1] ?? null)->toBe('');        // unknown speed / anchor → the defaults, no class
    expect($m[2] ?? null)->toBe('--pgs-c2:#AABBCC;--pgs-ib:rgba(0,0,0,.55);--pgs-h:120px;--pgs-r:0px;--pgs-fs1:24px'); // fs2 13.26 → 13.5, the design's own: not printed
    // Everything printed into the style is a var name, a #hex, an integer px or the rgba.
    foreach (explode(';', $m[2]) as $decl) {
        expect($decl)->toMatch('/^--pgs-[a-z0-9]+:(#[0-9A-F]{6}|\d+(\.5)?px|rgba\(\d{1,3},\d{1,3},\d{1,3},\.55\))$/');
    }
});

it('switches confetti, shine, speed and icon with literal classes and no extra markup', function () {
    $p = pgsTProduct();
    pgsTSet(['pgs_confetti' => false, 'pgs_icon' => false, 'pgs_speed' => 'slow']);
    $strip = pgsTStrip(pgsTPage($p));
    expect($strip)->not->toContain('class="cf')->and($strip)->not->toContain('pgs-i')->and($strip)->toContain('pgs-sp-slow');

    pgsTSet(['pgs_shine' => false]);
    expect(pgsTStrip(pgsTPage($p)))->toContain('pgs-noshine')->not->toContain('pgs-sp-');
});

it('adds no query and no settings-map read to the product page', function () {
    // THE DEFECT: a strip that asks the database, or re-reads the settings map.
    $p = pgsTProduct();
    pgsTPage($p); // warm

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
    pgsTSet(['pgs_on' => true, 'pgs_c1' => '#112233', 'pgs_desk' => 'cart']);
    pgsTPage($p);
    [$on, $reads] = $count();

    expect($on)->toBe($off)->and($reads)->toBeLessThanOrEqual(1);
});

it('saves through the product page endpoint, refusing unknown keys, and writes the phone switch to Mobile sections', function () {
    test()->actingAs(pgsTAdmin(), 'admin');

    $body = test()->getJson('/admin-api/product-page')->assertOk()->json();
    expect($body['giftstrip']['phone'])->toBeTrue()
        ->and(array_column($body['giftstrip']['options'][0]['fields'], 'key'))->toBe(ProductGiftStrip::TABS['giftstrip'][2]);

    test()->postJson('/admin-api/product-page', ['giftstrip' => ['options' => ['nope' => 1]]])->assertStatus(422);
    test()->postJson('/admin-api/product-page', ['giftstrip' => ['bogus' => 1]])->assertStatus(422);
    test()->postJson('/admin-api/product-page', ['giftstrip' => ['phone' => 'maybe']])->assertStatus(422);

    $r = test()->postJson('/admin-api/product-page', ['giftstrip' => [
        'options' => ['c1' => '#00ff00', 'height' => 500, 'speed' => 'fast', 'desk' => 'nowhere'],
        'phone' => false,
    ]])->assertOk()->json();

    expect($r['ok'])->toBeTrue()->and($r['giftstrip']['phone'])->toBeFalse();
    pgsTSet([]);
    $all = app(ProductGiftStrip::class)->all();
    expect($all['c1'])->toBe('#00FF00')->and($all['height'])->toBe(120)->and($all['speed'])->toBe('fast')->and($all['desk'])->toBe('paylater');
    expect(app(ProductMobileSections::class)->sectionOn('giftstrip'))->toBeFalse();
});

it('keeps the migration, the drafts and the interface strings saying the same words', function () {
    $mig = (string) file_get_contents(database_path('migrations/2028_01_24_100700_arabic_product_gift_strip.php'));
    $strings = \App\Services\Translation\InterfaceStrings::flat();
    $drafts = (new ReflectionClass(\App\Services\Translation\ArabicInterfaceDrafts::class))->getFileName();
    $drafts = (string) file_get_contents($drafts);

    foreach (['store.product.gift_strip_1', 'store.product.gift_strip_2'] as $k) {
        expect($strings)->toHaveKey($k);
        expect($mig)->toContain(var_export($strings[$k], true));
    }

    foreach (['هدايا مجانية مع كل طلب', 'عرض لفترة محدودة. لا تفوّت الفرصة!'] as $ar) {
        expect($mig)->toContain($ar)->and($drafts)->toContain($ar);
    }
});

it('animates only transform, and stops under reduced motion', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));
    preg_match_all('/@keyframes pgs\w+\{(.*?)\}\}/s', $css, $m);
    expect($m[1])->toHaveCount(2);
    foreach ($m[1] as $frames) {
        preg_match_all('/([a-z-]+):/', $frames, $props);
        expect(array_unique($props[1]))->each->toBeIn(['transform']); // compositor only
    }
    expect($css)->toContain('@media (prefers-reduced-motion:reduce){.pdp-gs::after,.pdp-gs .cf{animation:none}}');
    // No newer CSS function beside var() (CLAUDE.md landmine).
    $block = substr($css, (int) strpos($css, 'THE FREE GIFTS STRIP.'));
    expect($block)->not->toContain('color-mix(')->not->toContain('round(');
});
