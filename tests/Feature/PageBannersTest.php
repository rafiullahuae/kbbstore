<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Media;
use App\Models\Page;
use App\Models\Product;
use App\Services\PageBanners;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\SuperSale;
use Illuminate\Support\Facades\DB;
use Tests\Support\PageBannersRoutes;

/**
 * Pages → Page banners: the promo picture and the thin strip beneath it, on
 * the custom pages only. (Lane SS)
 *
 * The owner's picture: a wide pink promo IMAGE (all of its words are in the
 * picture), and under it a deep-rose STRIP of white text with tick-circles —
 * "100% Authentic Products" and "Express Delivery all over UAE" — that he can
 * edit. "such custom banners we will need for custom pages. not for products
 * or category."
 */
function pbAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'PB '.$role, 'email' => 'pb-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => $role,
    ]);
}

/** The banner block a page printed, or ''. */
function pbBlock(string $html): string
{
    return preg_match('#<style id="kbb-pb-css">.*?</ul>\n</div>|<style id="kbb-pb-css">.*?</div>\n</div>#s', $html, $m) ? $m[0] : '';
}

function pbStore(array $value): void
{
    app(SettingsService::class)->set(PageBanners::KEY, $value);
}

function pbBanner(array $over = []): array
{
    return array_replace(PageBanners::blank('promo', 'Promo'), $over);
}

function pbAboutPage(): void
{
    Page::firstOrCreate(['slug' => 'about'], ['title' => 'About', 'content' => '<p>About.</p>', 'status' => 'published']);
}

beforeEach(function () {
    PageBannersRoutes::wire($this->app);
    SettingsService::forgetMemo();
});

/* ═════════════════════════════════════ what ships, because he asked ═══ */

it('ships the strip on /super-sale/ with his two lines, and no picture until one is chosen', function () {
    /*
     * DEFECT THIS CATCHES: a lane shipping the slot empty "to be safe" (he
     * applies the package and sees nothing), or one shipping a stand-in
     * picture that the shop then draws as a broken image.
     * MUTATION: empty defaults()['assign'] -> the first assertion is red.
     */
    $html = $this->get('/super-sale')->assertOk()->getContent();

    expect(substr_count($html, '<style id="kbb-pb-css">'))->toBe(1)
        ->and(substr_count($html, 'class="kbb-pb-strip"'))->toBe(1)
        ->and($html)->toContain('<span>100% Authentic Products</span>')
        ->and($html)->toContain('<span>Express Delivery all over UAE</span>')
        ->and(substr_count($html, 'class="kbb-pb-ic"'))->toBe(2)
        ->and(pbBlock($html))->not->toContain('<img')
        ->and($html)->toContain('--pb-bg:#C8336A;--pb-ink:#FFFFFF;--pb-ic:#FFFFFF;--pb-hd:44px;--pb-hm:36px;--pb-fd:15px;--pb-fm:12px');
});

it('adds nothing at all to a page with no banner', function () {
    /* Rule 1: not one byte on a page nobody asked about. */
    pbAboutPage();

    foreach (['/new-in', '/best-sellers', '/everything-under-54-aed', '/about', '/shop/'] as $path) {
        $html = $this->followingRedirects()->get($path)->assertOk()->getContent();
        expect(str_contains($html, 'kbb-pb'))->toBeFalse($path);
    }
});

it('offers custom pages only — never a product, category or brand page', function () {
    pbAboutPage();
    $keys = array_keys(PageBanners::pageKeys());

    expect($keys)->toContain('collection:super-sale')
        ->and($keys)->toContain('page:about');

    foreach ($keys as $k) {
        expect($k)->toMatch('/^(collection|page):/');
    }

    // And the shop refuses to draw one anywhere else even if a row names it.
    Category::firstOrCreate(['slug' => 'toners'], ['name' => 'Toners', 'path' => 'toners']);
    pbStore(['banners' => [pbBanner()], 'assign' => ['page:toners' => 'promo', 'category:toners' => 'promo']]);
    expect($this->get('/collections/toners/')->getContent())->not->toContain('kbb-pb');
});

/* ══════════════════════════════════════════════════ the picture ═══ */

it('draws a desktop and a phone picture, its size reserved, linked and alt-texted', function () {
    pbAboutPage();
    pbStore(['banners' => [pbBanner([
        'img_d' => 'https://cdn.example.test/wide.webp', 'w_d' => 1920, 'h_d' => 600,
        'img_m' => '/wp-content/uploads/tall.webp', 'w_m' => 1080, 'h_m' => 720,
        'alt' => 'Super Sale — coupon code GLOW', 'link' => '/shop/',
    ])], 'assign' => ['page:about' => 'promo']]);

    $block = pbBlock($this->get('/about')->assertOk()->getContent());

    expect($block)->toContain('<source media="(max-width: 900px)" srcset="/wp-content/uploads/tall.webp" width="1080" height="720">')
        ->and($block)->toContain('<img src="https://cdn.example.test/wide.webp" alt="Super Sale — coupon code GLOW" width="1920" height="600"')
        ->and($block)->toMatch('#<a class="kbb-pb-img" href="[^"]*/shop/">#');
});

it('uses one picture on both when only one is chosen, and no <source> for it', function () {
    $v = PageBanners::view(pbBanner(['img_m' => 'https://cdn.example.test/one.webp']));

    expect($v['img']['d'])->toBe('https://cdn.example.test/one.webp')
        ->and($v['img']['m'])->toBe('https://cdn.example.test/one.webp')
        ->and($v['img']['two'])->toBeFalse();
});

it('draws nothing at all for a banner with no picture and no strip', function () {
    expect(PageBanners::view(pbBanner(['strip' => false])))->toBeNull()
        ->and(PageBanners::view(pbBanner(['items' => []])))->toBeNull();
});

/* ════════════════════════════════════════════ rule 5, on the page ═══ */

it('never prints a javascript: link or picture, even from a row the screen did not write', function () {
    /*
     * DEFECT: a setting becoming an executable href. The read path checks the
     * scheme again, so a row written by an import or a hand edit is as safe
     * as one written by the screen.
     * MUTATION: return $raw from PageBanners::link() or ::picture() -> the view() half is red.
     */
    pbAboutPage();
    DB::table('settings')->updateOrInsert(['key' => PageBanners::KEY], ['value' => json_encode([
        'banners' => [pbBanner(['img_d' => 'javascript:alert(1)', 'img_m' => 'https://ok.test/a.webp', 'link' => 'javascript:alert(2)', 'alt' => '"><script>x</script>'])],
        'assign' => ['page:about' => 'promo'],
    ]), 'autoload' => true]);
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();

    $block = pbBlock($this->get('/about')->getContent());

    expect($block)->not->toContain('javascript:')
        ->and($block)->not->toContain('<script>x')
        ->and($block)->toContain('<div class="kbb-pb-img">')
        ->and($block)->toContain('src="https://ok.test/a.webp"');

    // And view() itself, with nothing cleaned ahead of it: the second lock.
    $v = PageBanners::view(pbBanner(['img_d' => 'javascript:alert(1)', 'img_m' => 'https://ok.test/a.webp', 'link' => 'javascript:alert(2)']));
    expect($v['img']['href'])->toBe('')
        ->and($v['img']['d'])->toBe('https://ok.test/a.webp');
    expect(PageBanners::view(pbBanner(['img_d' => '//evil.test/x.png', 'strip' => false])))->toBeNull();
});

it('escapes every word the owner typed', function () {
    pbStore(['banners' => [pbBanner(['items' => [['en' => '<b>Bold</b> & <img src=x onerror=1>', 'ar' => '']]])], 'assign' => ['collection:super-sale' => 'promo']]);

    $block = pbBlock($this->get('/super-sale')->getContent());

    // strip_tags on write, the escaping echo on print
    expect($block)->toContain('<span>Bold &amp;</span>')
        ->and($block)->not->toContain('onerror');
});

it('uses the Arabic line on the Arabic shop and the English line where there is none', function () {
    $b = pbBanner(['items' => [['en' => 'Authentic', 'ar' => 'أصلي'], ['en' => 'Express', 'ar' => '']]]);

    expect(PageBanners::view($b, true)['items'])->toBe(['أصلي', 'Express'])
        ->and(PageBanners::view($b, false)['items'])->toBe(['Authentic', 'Express']);
});

/* ═══════════════════════════════════════════════════ the endpoint ═══ */

it('maps both verbs to its own capability, held by the Appearance roles', function () {
    foreach (['GET', 'POST'] as $verb) {
        expect(AdminCapabilities::forPath($verb, 'admin-api/page-banners'))->toBe('pagebanners.manage');
    }

    expect(AdminCapabilities::CAPABILITIES['pagebanners.manage'])->toBe(['owner', 'manager', 'editor']);
});

it('refuses both verbs to an account without the capability, and writes nothing', function () {
    $this->actingAs(pbAdmin('support'), 'admin');

    $this->getJson('/admin-api/page-banners')->assertStatus(403);
    $this->postJson('/admin-api/page-banners', ['banners' => [], 'assign' => [], 'super_sale_source' => 'on_sale'])->assertStatus(403);

    expect(DB::table('settings')->whereIn('key', [PageBanners::KEY, SuperSale::KEY])->count())->toBe(0);
});

it('answers the screen with the shipped banner, every custom page and the Super Sale source', function () {
    $this->actingAs(pbAdmin(), 'admin');

    $body = $this->getJson('/admin-api/page-banners')->assertOk()->json();

    expect($body['banners'])->toHaveCount(1)
        ->and($body['banners'][0]['id'])->toBe('super-sale')
        ->and($body['banners'][0]['img_d'])->toBe('')
        ->and($body['assign'])->toBe(['collection:super-sale' => 'super-sale'])
        ->and(array_column($body['pages'], 'key'))->toContain('collection:super-sale')
        ->and($body['css'])->toBe(PageBanners::CSS)
        ->and($body['super_sale']['source'])->toBe('auto')
        ->and($body['super_sale']['falls_back'])->toBeTrue()
        ->and(array_column($body['super_sale']['options'], 'value'))->toContain('on_sale');
});

it('saves a banner, its page and the source, and the shop shows them', function () {
    $this->actingAs(pbAdmin(), 'admin');
    pbAboutPage();
    $cat = Category::create(['name' => 'Super Sale', 'slug' => 'super-sale', 'path' => 'super-sale']);
    $url = Media::query()->create(['filename' => 'pb-wide.webp', 'path' => 'uploads/2026/10/pb-wide.webp', 'width' => 1920, 'height' => 600])->url();

    $b = pbBanner(['img_d' => $url, 'items' => [['en' => 'Free gift', 'ar' => '']], 'bg' => '#112233', 'sh_d' => 50]);

    $this->postJson('/admin-api/page-banners', [
        'banners' => [$b], 'assign' => ['page:about' => 'promo'], 'super_sale_source' => 'category:'.$cat->id,
    ])->assertOk()->assertJsonPath('ok', true);

    SettingsService::forgetMemo();
    $about = pbBlock($this->get('/about')->getContent());

    expect($about)->toContain('<span>Free gift</span>')
        ->and($about)->toContain('--pb-bg:#112233')
        ->and($about)->toContain('--pb-hd:50px')
        ->and($about)->toContain('width="1920" height="600"')        // read off the Media Library row on save
        ->and($this->get('/super-sale')->getContent())->not->toContain('kbb-pb')   // no longer assigned
        ->and(app(SettingsService::class)->get(SuperSale::KEY))->toBe('category:'.$cat->id);
});

it('refuses a bad link, colour, size, page or banner id, and writes nothing', function () {
    /*
     * All or nothing, as every screen here saves. Each case alone is a 422.
     * MUTATION: skip linkIsSafe() in sanitize() -> the first case saves, red.
     */
    $this->actingAs(pbAdmin(), 'admin');

    $cases = [
        'link' => [[pbBanner(['link' => 'javascript:alert(1)'])], []],
        'protocol-relative link' => [[pbBanner(['link' => '//evil.test/'])], []],
        'picture' => [[pbBanner(['img_d' => 'data:image/png;base64,AAAA'])], []],
        'colour' => [[pbBanner(['bg' => 'red;background:url(x)'])], []],
        'size' => [[pbBanner(['fs_m' => 400])], []],
        'unknown page' => [[pbBanner()], ['product:anything' => 'promo']],
        'unknown banner' => [[pbBanner()], ['collection:super-sale' => 'nope']],
        'bad id' => [[pbBanner(['id' => '../x'])], []],
    ];

    foreach ($cases as $name => [$banners, $assign]) {
        $this->postJson('/admin-api/page-banners', ['banners' => $banners, 'assign' => $assign, 'super_sale_source' => 'auto'])
            ->assertStatus(422, $name);
    }

    $this->postJson('/admin-api/page-banners', ['banners' => [], 'assign' => [], 'super_sale_source' => 'category:999999'])->assertStatus(422);
    $this->postJson('/admin-api/page-banners', ['banners' => [], 'assign' => [], 'super_sale_source' => 'newest'])->assertStatus(422);

    expect(DB::table('settings')->whereIn('key', [PageBanners::KEY, SuperSale::KEY])->count())->toBe(0);
});

/* ════════════════════════════════════════════════════════ rule 4 ═══ */

it('costs the page no query at all, with 3 products or 40', function () {
    /*
     * The banner is read from the settings map the page has already loaded.
     * Measured against the same page with the banner switched off, at two
     * catalogue sizes. MUTATION: look the banner up with a query of its own
     * in forPage() -> on is dearer than off, red.
     */
    $measure = function (int $n, bool $on): int {
        Product::query()->delete();
        for ($i = 1; $i <= $n; $i++) {
            Product::create(['slug' => "pb-q{$n}-".($on ? 'on' : 'off')."-{$i}", 'name' => "PB {$i}", 'type' => 'simple', 'status' => 'publish',
                'is_visible' => true, 'price' => 2000, 'sale_price' => 1000, 'stock_status' => 'instock']);
        }
        pbStore($on ? PageBanners::defaults() : ['banners' => [], 'assign' => []]);
        SettingsService::forgetMemo();
        $this->get('/super-sale');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->get('/super-sale')->getContent();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect(str_contains($html, 'kbb-pb-strip'))->toBe($on);

        return $q;
    };

    $off3 = $measure(3, false);
    $on3 = $measure(3, true);
    $on40 = $measure(40, true);

    expect($on3)->toBe($off3)->and($on40)->toBe($on3);
});

it('measures nothing in the browser and loads no script on the shop', function () {
    $partial = (string) file_get_contents(resource_path('views/partials/page-banner.blade.php'));
    $screen = (string) file_get_contents(resource_path('views/admin/partials/page-banners-screen.blade.php'));

    expect($partial)->not->toContain('<script')
        ->and(PageBanners::CSS)->not->toContain('<');

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'scrollWidth', 'ResizeObserver', 'setInterval'] as $api) {
        expect($screen)->not->toContain($api);
    }
});
