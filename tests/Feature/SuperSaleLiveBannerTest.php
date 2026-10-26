<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Setting;
use App\Services\FreeGifts;
use App\Services\PageHeaders;
use App\Services\SettingsService;
use App\Support\Locale;
use App\Support\SuperSaleBanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\PageBannersRoutes;

/**
 * The live Super Sale banner (Lane SSB).
 *
 * The owner approved variant D of the banner study for /super-sale/: one
 * compact banner -- tabby + tamara, "SUPER SALE" on one line, coupon GLOW, the
 * lady on the side -- over a free-gifts row, live HTML rather than a picture,
 * desktop and phone, English and Arabic, with the gifts picked automatically
 * from Marketing -> Free gifts. It replaces the PICTURE in the Super Sale header
 * and keeps the page's trust strip.
 *
 * Mutation notes, each a line a case below goes red without:
 *   - collection.blade.php's `$phParts['live'] = $ssb;`            -> case 1
 *   - SuperSaleBanner::defaults() 'on' => false                      -> case 1
 *   - page-header.blade.php's `@if (isset($ph['live']))` branch      -> case 1, 2
 *   - FreeGifts::bannerLines() dropping its visibleTo() check        -> case 5
 *   - bannerLines() calling linesFor($r) without $products (a query
 *     per range)                                                     -> case 7
 *   - the srcset / variantUrl on a gift photo                        -> case 6
 *   - SuperSaleBanner::sanitize() accepting any band / model         -> case 9
 */
function ssbAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'SSB '.$role, 'email' => 'ssb-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

function ssbFlush(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app()->forgetInstance(FreeGifts::class);
    app()->forgetInstance(PageHeaders::class);
}

function ssbGift(string $name, string $kind = 'mask', int $stock = 50, ?string $image = null): Product
{
    return Product::create([
        'slug' => Str::slug($name).'-'.Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => false, 'gift_only' => true,
        'type' => 'simple', 'price' => 2500, 'stock_status' => $stock === 0 ? 'outofstock' : 'instock', 'manage_stock' => true, 'stock' => $stock,
        'image' => $image ?? '/uploads/ssbt/'.Str::slug($name).'.webp',
    ]);
}

/** @param list<array{0:Product,1:int,2:string,3?:string}> $gifts */
function ssbRange(int $minAed, ?int $maxAed, array $gifts): int
{
    $id = DB::table('free_gift_ranges')->insertGetId([
        'min_fils' => $minAed * 100, 'max_fils' => $maxAed === null ? null : $maxAed * 100 + 99, 'label_en' => 'Range '.$minAed, 'label_ar' => 'نطاق',
        'sort' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($gifts as $i => $g) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $g[0]->id, 'quantity' => $g[1], 'kind' => $g[2], 'role' => $g[3] ?? 'primary', 'sort' => $i]);
    }

    return $id;
}

function ssbGifts(string $mode = 'live'): void
{
    app(SettingsService::class)->set(FreeGifts::KEY, ['mode' => $mode]);
    ssbFlush();
    app(FreeGifts::class)->compile();
    ssbFlush();
}

function ssbSet(array $over): void
{
    app(SettingsService::class)->set(SuperSaleBanner::KEY, $over + SuperSaleBanner::defaults());
    ssbFlush();
}

/** The banner block the page printed, or ''. */
function ssbBlock(string $html): string
{
    return preg_match('#<div class="kbb-ssb".*?</div></div>\n</div>\n#s', $html, $m) ? $m[0] : '';
}

beforeEach(function () {
    PageBannersRoutes::wire($this->app);
    DB::table('free_gift_ranges')->delete();
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    // The live shop as it is: GLOW is a real coupon, and the banner is as
    // shipped (migration 2027_12_26_100001 found GLOW and wrote nothing).
    \App\Models\Coupon::create(['code' => 'GLOW', 'type' => 'fixed_cart', 'amount' => 1000]);
    DB::table('settings')->where('key', SuperSaleBanner::KEY)->delete();
    \Illuminate\Support\Facades\Cache::forget('kbb.settings');
    ssbFlush();
    Product::create(['slug' => 'ssb-sale-1', 'name' => 'SSB Sale 1', 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => 2000, 'sale_price' => 1000, 'stock_status' => 'instock']);
});

afterEach(function () {
    File::deleteDirectory(public_path('uploads/ssbt'));
    File::deleteDirectory(public_path('img-cache/200/uploads/ssbt'));
    File::deleteDirectory(public_path('img-cache/400/uploads/ssbt'));
});

/* ══════════════════════════════════════════ what ships, because he asked ═══ */

it('draws the approved banner on /super-sale/, in the header picture\'s place, with the trust strip kept', function () {
    /*
     * DEFECT IT GUARDS: the banner built and never reaching the page -- or
     * reaching it beside the old picture, two banners stacked. The page had a
     * picture banner (the owner's Super Sale image); with the live banner on,
     * that picture is not drawn and its bytes are not fetched.
     */
    $all = PageHeaders::defaults();
    $all['pages']['collection:super-sale']['img'] = 'https://kbeautybliss.com/uploads/appearance/kbb-super-sale-desktop.webp';
    app(SettingsService::class)->set(PageHeaders::KEY, $all);
    $mask = ssbGift('Tea Tree Sheet Mask');
    ssbRange(100, null, [[$mask, 2, 'mask']]);
    ssbGifts();

    $html = $this->get('/super-sale/')->assertOk()->getContent();
    $block = ssbBlock($html);

    expect(substr_count($html, 'class="kbb-ssb"'))->toBe(1)
        ->and($block)->toContain('SUPER SALE')
        ->and($block)->toContain('<b>GLOW</b>')
        ->and($block)->toContain('COUPON CODE:')
        ->and($block)->toContain('viewBox="0 0 71 28"')->toContain('viewBox="0 0 85 28"')->not->toContain('<script')   // tabby, tamara
        ->and($block)->toContain('Free gifts with every order')
        ->and($block)->toContain('alt="Tea Tree Sheet Mask"')
        ->and($html)->not->toContain('kbb-super-sale-desktop.webp')      // the picture it replaces
        ->and($html)->toContain('<div class="kbb-ph-i"><style id="kbb-ssb-css">')
        ->and($html)->toContain('class="ktr');                               // the trust strip stays

    // No pink ribbon on top, no trust bar of its own: hero + gifts, nothing else.
    expect($block)->not->toContain('ribbon')->not->toContain('trustbar');
});

it('puts the picture back, byte for byte, when switched off', function () {
    $all = PageHeaders::defaults();
    $all['pages']['collection:super-sale']['img'] = 'https://kbeautybliss.com/uploads/appearance/kbb-super-sale-desktop.webp';
    app(SettingsService::class)->set(PageHeaders::KEY, $all);
    ssbSet(['on' => false]);

    $html = $this->get('/super-sale/')->assertOk()->getContent();

    expect($html)->not->toContain('kbb-ssb')
        ->and($html)->toContain('<picture class="kbb-ph-i"><img src="https://kbeautybliss.com/uploads/appearance/kbb-super-sale-desktop.webp"');
});

it('is on /super-sale/ only', function () {
    ssbRange(100, null, [[ssbGift('Mask A'), 1, 'mask']]);
    ssbGifts();

    foreach (['/new-in/', '/best-sellers/', '/everything-under-54-aed/', '/'] as $path) {
        expect($this->get($path)->getContent())->not->toContain('kbb-ssb');
    }
});

it('speaks Arabic, right to left, on /ar/super-sale/', function () {
    ssbRange(100, null, [[ssbGift('Mask A'), 1, 'mask']]);
    ssbGifts();

    $block = ssbBlock($this->get('/ar/super-sale/')->assertOk()->getContent());

    expect($block)->toStartWith('<div class="kbb-ssb" dir="rtl"')
        ->and($block)->toContain('تخفيضات كبرى')
        ->and($block)->toContain('كود الخصم:')
        ->and($block)->toContain('هدايا مجانية مع كل طلب')
        ->and($block)->toContain('مجاناً')
        ->and($block)->not->toContain('SUPER SALE');
});

/* ════════════════════════════════ the gifts are the box's own gifts ═══ */

it('shows the gifts the cart box would give: nothing sold out, a backup for a missing main, nothing when gifts are off', function () {
    /*
     * DEFECT IT GUARDS: a banner promising a gift the bag will not give -- a
     * sold-out product, or gifts at all while Free gifts is Off or only in
     * Testing for somebody else.
     */
    $full = ssbGift('The Glow Set', 'full');
    $gone = ssbGift('Gone Mask', 'mask', 0);
    $backup = ssbGift('Backup Mask', 'mask');
    ssbRange(100, null, [[$gone, 1, 'mask'], [$full, 1, 'full'], [$backup, 1, 'mask', 'backup']]);
    DB::table('free_gift_ranges')->update(['need_mask' => 1, 'need_full' => 1]);
    ssbGifts();

    $block = ssbBlock($this->get('/super-sale/')->getContent());
    expect($block)->toContain('alt="The Glow Set"')->toContain('alt="Backup Mask"')->not->toContain('Gone Mask')
        // full size first, as the homepage section orders its photos
        ->and(strpos($block, 'The Glow Set'))->toBeLessThan(strpos($block, 'Backup Mask'));

    ssbGifts('off');
    $block = ssbBlock($this->get('/super-sale/')->getContent());
    expect($block)->toContain('SUPER SALE')->not->toContain('ssb-gifts');

    ssbGifts('testing'); // no testers: nobody sees them
    expect(ssbBlock($this->get('/super-sale/')->getContent()))->not->toContain('ssb-gifts');
});

it('picks the band he chose: every range, the first, the top one, or one by id', function () {
    $a = ssbGift('Small Band Mask');
    $b = ssbGift('Top Band Set', 'full');
    ssbRange(100, 299, [[$a, 1, 'mask']]);
    $top = ssbRange(300, null, [[$b, 1, 'full']]);
    ssbGifts();

    $shown = fn () => ssbBlock($this->get('/super-sale/')->getContent());

    expect($shown())->toContain('Small Band Mask')->toContain('Top Band Set');
    ssbSet(['band' => 'first']);
    expect($shown())->toContain('Small Band Mask')->not->toContain('Top Band Set');
    ssbSet(['band' => 'last']);
    expect($shown())->not->toContain('Small Band Mask')->toContain('Top Band Set');
    ssbSet(['band' => 'range:'.$top]);
    expect($shown())->not->toContain('Small Band Mask')->toContain('Top Band Set');
    ssbSet(['gifts_on' => false]);
    expect($shown())->not->toContain('ssb-gifts');
});

it('hands each gift photo its 200px copy and srcset, never the full-size original', function () {
    $rel = 'uploads/ssbt/tea-mask.webp';
    foreach (['', 'img-cache/200/', 'img-cache/400/'] as $dir) {
        File::ensureDirectoryExists(dirname(public_path($dir.$rel)));
        file_put_contents(public_path($dir.$rel), 'x');
    }
    ssbRange(100, null, [[ssbGift('Tea Mask', 'mask', 50, '/'.$rel), 1, 'mask']]);
    ssbGifts();

    $block = ssbBlock($this->get('/super-sale/')->getContent());

    expect($block)->toContain('<img src="/img-cache/200/'.$rel.'" srcset="')
        ->and($block)->toContain('/img-cache/400/'.$rel.' 400w')
        ->and($block)->toContain('sizes="(max-width: 900px) 36px, 62px"')
        ->and($block)->not->toContain('src="/'.$rel.'"');
});

/* ═══════════════════════════════════════════════════════ rule 4: flat ═══ */

it('costs one query for its gifts, the same with 3 gifts and 40 or 3 products and 40', function () {
    /*
     * The settings are the page's own map; the gifts are ONE query for every
     * range together. MUTATION: linesFor($r) without the shared $products
     * (a query per range), or rows() lazy-loading a brand per gift -> red.
     */
    $measure = function (bool $on = true): int {
        $this->get('/super-sale/');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->get('/super-sale/')->getContent();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect(str_contains($html, 'class="kbb-ssb"'))->toBe($on);

        return $n;
    };

    ssbSet(['on' => false]);
    $off = $measure(false);
    ssbSet(['on' => true, 'gifts_on' => false]);
    $noGifts = $measure();

    ssbRange(100, 299, [[ssbGift('G1'), 1, 'mask'], [ssbGift('G2'), 1, 'mask'], [ssbGift('G3', 'full'), 1, 'full']]);
    ssbGifts();
    ssbSet(['on' => true]);
    $three = $measure();

    $many = [];
    for ($i = 1; $i <= 40; $i++) {
        $many[] = [ssbGift('Many '.$i), 1, 'mask'];
    }
    ssbRange(300, 999, array_slice($many, 0, 20));
    ssbRange(1000, null, array_slice($many, 20));
    // The listing's own cards carry a brand, as the shop's do, so the grid's
    // brand query is there on both sides and only the banner is compared.
    $brand = \App\Models\Brand::create(['name' => 'SSB Brand', 'slug' => 'ssb-brand']);
    for ($i = 1; $i <= 40; $i++) {
        Product::create(['slug' => "ssb-more-{$i}", 'name' => "SSB More {$i}", 'brand_id' => $brand->id, 'type' => 'simple', 'status' => 'publish',
            'is_visible' => true, 'price' => 2000, 'sale_price' => 1000, 'stock_status' => 'instock']);
    }
    ssbGifts();
    $forty = $measure();

    expect($off)->toBeGreaterThan(0)
        ->and($noGifts)->toBe($off)
        ->and($three)->toBe($off + 1)
        ->and($forty)->toBe($three);
});

/* ═════════════════════════════════════════════ rule 5 and the landmines ═══ */

it('escapes every word he typed and prints no script', function () {
    ssbSet(['title_en' => '<img src=x onerror=alert(1)>SALE', 'code' => '"><b>X', 'pay_en' => 'a&b']);

    $block = ssbBlock($this->get('/super-sale/')->getContent());

    expect($block)->not->toContain('onerror')->not->toContain('"><b>X')->not->toContain('<script')
        ->and($block)->toContain('SALE')
        ->and($block)->toContain('a&amp;b');
});

it('saves from Pages → Page banners → Super Sale banner, refuses a bad band or picture, and the shop follows', function () {
    $this->actingAs(ssbAdmin(), 'admin');

    $payload = $this->getJson('/admin-api/page-banners')->assertOk()->json();
    expect($payload['sale_banner']['config']['on'])->toBeTrue()
        ->and($payload['sale_banner']['config']['code'])->toBe('GLOW')
        ->and(array_column($payload['sale_banner']['bands'], 'value'))->toContain('all', 'first', 'last');

    $base = ['banners' => $payload['banners'], 'assign' => [], 'super_sale_source' => 'auto'];

    foreach ([['band' => 'newest'], ['model' => 'javascript:alert(1)'], ['model' => 'https://x.test/a.webp" onload="x']] as $bad) {
        $before = DB::table('settings')->where('key', SuperSaleBanner::KEY)->value('value');
        $this->postJson('/admin-api/page-banners', $base + ['super_sale_banner' => $bad + $payload['sale_banner']['config']])->assertStatus(422);
        expect(DB::table('settings')->where('key', SuperSaleBanner::KEY)->value('value'))->toBe($before);
    }

    \App\Models\Coupon::create(['code' => 'SALE20', 'type' => 'fixed_cart', 'amount' => 2000]);
    $cfg = ['code' => 'SALE20', 'title_en' => 'MEGA SALE', 'model' => 'https://kbeautybliss.com/uploads/appearance/me.webp'] + $payload['sale_banner']['config'];
    $this->postJson('/admin-api/page-banners', $base + ['super_sale_banner' => $cfg])->assertOk();
    ssbFlush();

    $block = ssbBlock($this->get('/super-sale/')->getContent());
    expect($block)->toContain('<b>SALE20</b>')->toContain('MEGA SALE')
        ->and($block)->toContain('src="https://kbeautybliss.com/uploads/appearance/me.webp"');

    // An older screen that does not send the banner still saves, and leaves it alone.
    $this->postJson('/admin-api/page-banners', $base)->assertOk();
    ssbFlush();
    expect(ssbBlock($this->get('/super-sale/')->getContent()))->toContain('<b>SALE20</b>');
});

it('refuses the save to a role without Page banners', function () {
    $this->actingAs(ssbAdmin('support'), 'admin');
    $this->postJson('/admin-api/page-banners', ['banners' => [], 'assign' => [], 'super_sale_source' => 'auto', 'super_sale_banner' => ['on' => false]])->assertStatus(403);
    expect(DB::table('settings')->where('key', SuperSaleBanner::KEY)->exists())->toBeFalse();
});

it('reads a damaged row as the shipped banner, never a 500', function () {
    app(SettingsService::class)->set(SuperSaleBanner::KEY, ['band' => ['x'], 'model' => 'javascript:x', 'on' => 'yes', 'title_en' => ['a']]);
    ssbFlush();

    $block = ssbBlock($this->get('/super-sale/')->assertOk()->getContent());
    expect($block)->toContain('kbb-ssb')->not->toContain('javascript:');
});

it('sizes with CSS alone, and keeps min() and container units behind @supports with a plain value outside', function () {
    /*
     * The 2.60.492 landmine: a declaration with var() has no fallback in a
     * browser that cannot read its value. --u is a plain 3.9px first; the
     * min()/cqw forms are each inside @supports.
     */
    $css = SuperSaleBanner::CSS;
    $outside = (string) preg_replace('/@supports \((?:[^()]|\([^()]*\))*\)\{(?:[^{}]|\{[^{}]*\}|\{(?:[^{}]|\{[^{}]*\})*\})*\}/', '', $css);

    expect($css)->toContain('.kbb-ssb{container-type:inline-size;--u:3.9px;')
        ->and($outside)->not->toContain('min(')->not->toContain('cqw')
        ->and($css)->not->toContain('round(')->not->toContain('color-mix(')->not->toContain('<');

    // Every inline SVG it prints carries its own size.
    $block = ssbBlock($this->get('/super-sale/')->getContent());
    preg_match_all('#<svg[^>]*>#', $block, $m);
    expect($m[0])->not->toBeEmpty();
    foreach ($m[0] as $tag) {
        expect($tag)->toMatch('/ width="\d+" /')->toMatch('/ height="\d+"/');
    }
});

it('ships the lady from the current banner and puts her in the Media Library', function () {
    $dest = public_path(SuperSaleBanner::MODEL_REL);
    $existed = is_file($dest);

    (require database_path('migrations/2027_12_26_100000_super_sale_live_banner_model.php'))->up();

    try {
        expect(is_file($dest))->toBeTrue()
            ->and(getimagesize(resource_path('banners/'.SuperSaleBanner::MODEL_FILE))[0])->toBe(288)
            ->and(filesize(resource_path('banners/'.SuperSaleBanner::MODEL_FILE)))->toBeLessThan(20 * 1024)
            ->and(DB::table('media')->where('path', SuperSaleBanner::MODEL_REL)->count())->toBe(1);

        ssbFlush();
        $block = ssbBlock($this->get('/super-sale/')->getContent());
        expect($block)->toContain('class="ssb-model"><img src="')
            ->and($block)->toContain(SuperSaleBanner::MODEL_FILE);

        ssbSet(['model_on' => false]);
        expect(ssbBlock($this->get('/super-sale/')->getContent()))->not->toContain('ssb-model');
    } finally {
        if (! $existed) {
            @unlink($dest);
            File::deleteDirectory(public_path('img-cache/200/uploads/appearance'));
        }
    }
});

it('never offers a coupon code the shop has not got: off at apply time, refused on save', function () {
    /*
     * DEFECT IT GUARDS: "COUPON CODE: GLOW" printed on a public page of a shop
     * whose coupons table has no GLOW (PublicPagesQuoteRealPricesTest). The
     * check is at apply time and on save, so the page spends no query on it.
     * MUTATION: return early in the migration, or drop the controller's
     * Coupon check -> red.
     */
    $run = function (): void {
        (require database_path('migrations/2027_12_26_100001_super_sale_live_banner_coupon_check.php'))->up();
        \Illuminate\Support\Facades\Cache::forget('kbb.settings');
        ssbFlush();
    };

    // GLOW exists: nothing written, the banner says GLOW.
    $run();
    expect(DB::table('settings')->where('key', SuperSaleBanner::KEY)->exists())->toBeFalse()
        ->and(ssbBlock($this->get('/super-sale/')->getContent()))->toContain('<b>GLOW</b>');

    // No GLOW: the banner ships without its coupon line, the rest as approved.
    \App\Models\Coupon::query()->delete();
    $run();
    $block = ssbBlock($this->get('/super-sale/')->getContent());
    expect($block)->toContain('SUPER SALE')->not->toContain('ssb-coupon')->not->toContain('<b>GLOW</b>');

    // A banner he saved is never rewritten.
    ssbSet(['code' => 'GLOW', 'title_en' => 'MINE']);
    $run();
    expect(ssbBlock($this->get('/super-sale/')->getContent()))->toContain('MINE');

    // And the admin refuses a code that is not a coupon, writing nothing.
    $this->actingAs(ssbAdmin(), 'admin');
    $payload = $this->getJson('/admin-api/page-banners')->assertOk()->json();
    $before = DB::table('settings')->where('key', SuperSaleBanner::KEY)->value('value');
    $this->postJson('/admin-api/page-banners', ['banners' => $payload['banners'], 'assign' => [], 'super_sale_source' => 'auto',
        'super_sale_banner' => ['code' => 'NOPE50'] + $payload['sale_banner']['config']])
        ->assertStatus(422)->assertJsonPath('rejected', ['super_sale_banner.code']);
    expect(DB::table('settings')->where('key', SuperSaleBanner::KEY)->value('value'))->toBe($before);
});
