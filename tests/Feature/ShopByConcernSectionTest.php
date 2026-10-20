<?php

declare(strict_types=1);

/**
 * Shop by Concern — "Content 1" of docs/sbc-preview/template-v3.html. (Lane SBC)
 *
 * Concern tabs, a tip panel (the owner's photo per tab, or the concern's
 * gradient and two product photos) and the tab's products as list cards with
 * a cart-plus. Each case names the defect it catches on the shop and the
 * mutation that turns it red.
 */

use App\Models\Category;
use App\Models\Product;
use App\Services\HomepageContent;
use App\Services\HomepageSections;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Support\HomeSections;
use App\Support\ImageVariants;
use App\Support\ShopByConcern;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;
use Tests\Support\HomepageContentAdminRoutes;

beforeEach(function () {
    app(SettingsService::class)->set('demo_content', false);
});

function sbcTabWrite(array $values): void
{
    ModuleSchema::write(app(SettingsService::class), 'homepage_content', HomepageContent::SCHEMA, $values);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

function sbcTabHome(string $path = '/'): string
{
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    Cache::flush();

    return test()->get($path)->assertOk()->getContent();
}

/** Appearance → Homepage's Laptop / Phone switches for the section. */
function sbcTabSwitch(bool $on): void
{
    $rows = [];
    foreach (app(HomepageSections::class)->all() as $key => $row) {
        $mine = $key === 'sbc';
        $rows[$key] = ['desktop' => $mine ? $on : $row['desktop'], 'mobile' => $mine ? $on : $row['mobile'], 'skin' => $row['skin'], 'order' => $row['order']];
    }
    app(SettingsService::class)->set('homepage_sections', $rows);
    SettingsService::forgetMemo();
}

/** The eight concern collections, each with $per in-stock products (and one sold out). */
function sbcTabCatalogue(int $per = 6): array
{
    $slugs = ['acne' => 'acne-blemish-care', 'hydration' => 'hydration-glow', 'spots' => 'pigmentation', 'ageing' => 'anti-aging',
        'sensitive' => 'redness-sensitive-skin', 'pores' => 'pore-care', 'dull' => 'glow', 'sun' => 'sun-care'];
    $brand = \App\Models\Brand::create(['name' => 'Sbc Brand', 'slug' => 'sbc-brand']);
    $cats = [];
    $n = 0;

    foreach ($slugs as $k => $slug) {
        $cat = Category::create(['name' => 'Col '.$k, 'slug' => $slug, 'path' => $slug]);
        $cats[$k] = $cat;

        for ($i = 0; $i < $per; $i++) {
            $n++;
            $p = Product::create(['slug' => 'sbc-'.$k.'-'.$i, 'name' => 'Sbc '.$k.' product '.$i, 'type' => 'simple', 'status' => 'publish', 'brand_id' => $brand->id,
                'is_visible' => true, 'stock_status' => 'instock', 'price' => 10000 + $n, 'sale_price' => $i === 0 ? 7800 : null, 'total_sales' => 1000 - $n]);
            $p->categories()->attach($cat->id);
        }

        $gone = Product::create(['slug' => 'sbc-'.$k.'-gone', 'name' => 'Sbc '.$k.' sold out', 'type' => 'simple', 'status' => 'publish',
            'is_visible' => true, 'stock_status' => 'outofstock', 'price' => 9000, 'total_sales' => 99999]);
        $gone->categories()->attach($cat->id);
    }

    return $cats;
}

function sbcTabSection(string $html): string
{
    return preg_match('#<section class="sec sbc\b.*?</div></section>#s', $html, $m) === 1 ? $m[0] : '';
}

/** The partial's own <style> block. */
function sbcTabCss(): string
{
    $src = (string) file_get_contents(resource_path('views/partials/home/hs-sbc.blade.php'));

    return preg_match('#<style>(.*?)</style>#s', $src, $m) === 1 ? $m[1] : '';
}

it('ships OFF, directly under Skin concerns, and leaves the homepage untouched until switched on', function () {
    /*
     * THE DEFECT: the brief asked for the section ON directly under Trending —
     * but that place is Skin concerns' (the owner's, pinned by Lane CX), so
     * taking it would move his section; the brief's rule for that case is OFF.
     * Off must mean off: no markup, no stylesheet, no script, and no query.
     * MUTATION: drop 'sbc' from OFF_BY_DEFAULT → red on the first assertion;
     * drop the @unless ($sections->hidden('sbc')) → red on the query count.
     */
    sbcTabCatalogue(2);
    $html = sbcTabHome();
    expect($html)->not->toContain('id="sbc"')->and($html)->not->toContain('.sbc-tab')->and($html)->not->toContain('Shop by Concern');

    $n = 0;
    DB::listen(function ($q) use (&$n) { $n++; });
    Cache::flush();
    SettingsService::forgetMemo();
    test()->get('/')->assertOk();
    $off = $n;

    // The same page with every tab removed draws nothing either -- and costs
    // the one collections query the section makes when it is on.
    sbcTabSwitch(true);
    sbcTabWrite(['home_sbc_tabs' => '[]']);
    expect(sbcTabHome())->not->toContain('id="sbc"');

    expect(HomepageSections::OFF_BY_DEFAULT)->toContain('sbc')
        ->and(array_search('sbc', array_keys(HomepageSections::REGISTRY), true))
        ->toBe(array_search('concerns', array_keys(HomepageSections::REGISTRY), true) + 1)
        ->and(HomepageSections::FOLLOWS['concerns'])->toBe('trending')
        ->and($off)->toBeGreaterThan(0);

    // On: drawn, and in page order Trending → Skin concerns → Shop by Concern.
    sbcTabWrite(['home_sbc_tabs' => '']);
    $on = sbcTabHome();
    preg_match_all('#<section class="sec ([^"]*)"#', $on, $m);
    $keys = array_values(array_filter(array_map(fn ($c) => preg_match('/\b(hs-trending|hs-concerns|sbc|hs-blog)\b/', $c, $k) ? $k[1] : null, $m[1])));
    expect($keys)->toContain('sbc')
        ->and(array_search('sbc', $keys, true))->toBeGreaterThan((int) array_search('hs-concerns', $keys, true));
});

it('renders the eight tabs with the owner\'s exact tips, in English and in Arabic', function () {
    /*
     * THE DEFECT: the preview's short placeholder tips shipped instead of the
     * owner's words, or /ar showing English.
     * MUTATION: change one character of a tip in DEFAULT_TABS → red; ignore
     * $arabic in ShopByConcern::tab() → red on /ar.
     */
    sbcTabCatalogue();
    sbcTabSwitch(true);
    $en = sbcTabSection(sbcTabHome());

    foreach (ShopByConcern::DEFAULT_TABS as $t) {
        expect($en)->toContain('<span>'.e($t['l']).'</span>')
            ->and($en)->toContain('<h3>'.e($t['h']).'</h3>')
            ->and($en)->toContain('<p>'.e($t['t']).'</p>');
    }

    expect($en)->toContain('<h2 id="sbc-h">Shop by Concern</h2>')
        ->and($en)->toContain('<span class="sbc-tip">TIP</span>')
        ->and($en)->toContain('View all <span aria-hidden="true">›</span>')
        ->and(ShopByConcern::DEFAULT_TABS[0]['t'])->toBe('Cleanse gently, twice a day. Use a 2% BHA (salicylic acid) three or four nights a week to keep pores clear, calm breakouts with centella, and never skip a light, oil-free moisturiser — dehydrated skin makes more oil.')
        ->and(ShopByConcern::DEFAULT_TABS[7]['ta'])->toBe('استخدمي واقي شمس SPF 50+ PA++++ كل صباح — حتى داخل المنزل قرب النافذة. ضعي ما يعادل طول إصبعين للوجه والرقبة، وجدّديه كل ساعتين في الخارج تحت شمس الإمارات.');

    ArabicShop::on();
    $ar = sbcTabSection(sbcTabHome('/ar/'));

    foreach (ShopByConcern::DEFAULT_TABS as $t) {
        expect($ar)->toContain('<span>'.e($t['la']).'</span>')
            ->and($ar)->toContain('<h3>'.e($t['ha']).'</h3>')
            ->and($ar)->toContain('<p>'.e($t['ta']).'</p>');
    }

    expect($ar)->toContain('تسوّقي حسب مشكلة البشرة')
        ->and($ar)->toContain('<span class="sbc-tip">نصيحة</span>')
        ->and($ar)->toContain('عرض الكل')
        // Each View all is the tab's own collection, under /ar.
        ->and($ar)->toContain('href="/ar/collections/acne-blemish-care/"')
        ->and($en)->toContain('href="/collections/acne-blemish-care/"');
});

it('keeps the heading icon and the line under the heading off by default, and draws them when switched on', function () {
    /*
     * THE DEFECT: the owner asked for both OFF; and the line must still work,
     * translated, the day he switches it on.
     * MUTATION: default home_sbc_icon to true → red; print the sub without
     * reading home_sbc_sub_on → red on the first block.
     */
    sbcTabCatalogue(2);
    sbcTabSwitch(true);
    $sec = sbcTabSection(sbcTabHome());
    expect($sec)->not->toContain('sbc-hi')
        ->and($sec)->not->toContain(ShopByConcern::SUB_EN)
        ->and(ShopByConcern::SCHEMA['home_sbc_icon']['default'])->toBeFalse()
        ->and(ShopByConcern::SCHEMA['home_sbc_sub_on']['default'])->toBeFalse();

    sbcTabWrite(['home_sbc_icon' => true, 'home_sbc_sub_on' => true]);
    $sec = sbcTabSection(sbcTabHome());
    expect($sec)->toContain('<span class="sbc-hi"><svg viewBox="0 0 24 24" width="34" height="34"')
        ->and($sec)->toContain('<p>'.e(ShopByConcern::SUB_EN).'</p>');

    ArabicShop::on();
    expect(sbcTabSection(sbcTabHome('/ar/')))->toContain('<p>'.e(ShopByConcern::SUB_AR).'</p>');
});

it('draws the tab\'s photo with srcset and alt when one is set, and the gradient and two product circles when not', function () {
    /*
     * THE DEFECT: a bare full-size photo (CLAUDE.md: every shop picture offers
     * its img-cache copies), a photo with no alt, or a panel left blank when
     * no photo is chosen.
     * MUTATION: drop the srcset attribute from hs-sbc → red; print the shots
     * even with a photo → red on `sbc-shot`; drop the gradient style → red.
     */
    sbcTabCatalogue(3);
    sbcTabSwitch(true);
    \Illuminate\Support\Facades\DB::table('products')->where('slug', 'like', 'sbc-acne-%')->update(['image' => '/uploads/sbc-test/p.jpg']);

    $rel = 'uploads/sbc-test/acne-bg.jpg';
    @mkdir(dirname(public_path($rel)), 0775, true);
    $im = imagecreatetruecolor(1600, 1200);
    imagefill($im, 0, 0, imagecolorallocate($im, 240, 200, 210));
    imagejpeg($im, public_path($rel), 70);
    imagedestroy($im);
    ImageVariants::generate('/'.$rel);

    try {
        // No photo: the concern's gradient and two product circles.
        $sec = sbcTabSection(sbcTabHome());
        preg_match('#<div class="sbc-pn" role="tabpanel" id="sbc-p0".*?<div class="sbc-rail">#s', $sec, $p0);
        expect($p0[0])->toContain('class="sbc-hero" style="background:linear-gradient(135deg,#FFE3E8,#FFF4EC)"')
            ->and(substr_count($p0[0], 'class="sbc-shot '))->toBe(2)
            ->and($p0[0])->not->toContain('sbc-bgi');

        $rows = ShopByConcern::defaults();
        $rows[0]['img'] = '/'.$rel;
        sbcTabWrite(['home_sbc_tabs' => json_encode($rows)]);

        $sec = sbcTabSection(sbcTabHome());
        preg_match('#<div class="sbc-pn" role="tabpanel" id="sbc-p0".*?<div class="sbc-rail">#s', $sec, $p0);
        preg_match('#<img class="sbc-bgi"[^>]*>#', $p0[0], $img);
        expect($p0[0])->toContain('class="sbc-hero sbc-photo"')
            ->and($p0[0])->not->toContain('sbc-shot')
            ->and($img[0])->toContain('src="/'.$rel.'"')
            ->and($img[0])->toContain('alt="Acne &amp; Blemishes"')
            ->and($img[0])->toMatch('#srcset="[^"]*/img-cache/[^"]* \d+w#')
            ->and($img[0])->toContain('sizes="(max-width:900px) 100vw, 480px"')
            ->and($img[0])->toContain('loading="lazy"');

        // A javascript: "photo" never reaches the page.
        $rows[0]['img'] = 'javascript:alert(1)';
        sbcTabWrite(['home_sbc_tabs' => json_encode($rows)]);
        expect(sbcTabSection(sbcTabHome()))->not->toContain('javascript:')->not->toContain('sbc-bgi');
    } finally {
        foreach (array_merge([public_path($rel)], glob(public_path('img-cache/*/uploads/sbc-test/*')) ?: []) as $f) {
            @unlink($f);
        }
    }
});

it('marks the tabs up as a real tablist and switches them with no request', function () {
    /*
     * THE DEFECT: tabs that fetch their products (a request per click), panels
     * a screen reader cannot pair with their tab, or a timer in the page.
     * MUTATION: render only the active panel → red on the panel count; add a
     * fetch() to the script → red on the script check.
     */
    sbcTabCatalogue(2);
    sbcTabSwitch(true);
    $html = sbcTabHome();
    $sec = sbcTabSection($html);

    expect(substr_count($sec, 'role="tab"'))->toBe(8)
        ->and(substr_count($sec, 'role="tabpanel"'))->toBe(8)
        ->and(substr_count($sec, ' hidden '))->toBe(7)
        ->and($sec)->toContain('<div class="sbc-tabs" role="tablist" aria-labelledby="sbc-h">')
        ->and($sec)->toContain('id="sbc-t0" aria-controls="sbc-p0" aria-selected="true" tabindex="0"')
        ->and($sec)->toContain('id="sbc-t1" aria-controls="sbc-p1" aria-selected="false" tabindex="-1"')
        ->and($sec)->toContain('role="tabpanel" id="sbc-p1" aria-labelledby="sbc-t1"');

    preg_match('#</section>\s*<script>(.*?)</script>#s', $html, $js);
    expect($js[1] ?? '')->toContain("'ArrowRight'")->toContain("'Home'")->toContain('IntersectionObserver');

    // No less-than sign in it: strip_tags() read `j<d.length` as a tag and
    // swallowed the rest of the homepage, trust row included
    // (TrustClaimsAreTheOwnersTest). MUTATION: write `j<d.length` back → red.
    expect(substr_count($js[1] ?? '<', '<'))->toBe(0);

    foreach (['fetch(', 'XMLHttpRequest', 'setTimeout', 'setInterval', 'requestAnimationFrame', 'getBoundingClientRect', 'offsetWidth', 'offsetLeft', 'scrollWidth', 'clientWidth', 'getComputedStyle'] as $api) {
        expect($js[1] ?? '')->not->toContain($api);
    }

    // Every inline <svg> carries its own size (the header lock's rule).
    preg_match_all('#<svg\b[^>]*>#', $sec, $svgs);
    foreach ($svgs[0] as $svg) {
        expect($svg)->toMatch('/\bwidth="\d+"/')->toMatch('/\bheight="\d+"/');
    }
});

it('shows six in-stock products per tab, priced like the grid card, each with the shop\'s own add to cart', function () {
    /*
     * THE DEFECT: a sold-out product on the homepage, a price that disagrees
     * with the grid card, or a cart button that is not the shop's (no
     * data-kbb-add: cart.js would never see it).
     * MUTATION: drop inStock() from GridSections::idsForMany() → the sold-out
     * best seller is first, red; drop data-kbb-add → red.
     */
    sbcTabCatalogue(8);
    sbcTabSwitch(true);
    $sec = sbcTabSection(sbcTabHome());
    preg_match('#id="sbc-p0".*?<div class="sbc-rail">(.*?)</div>\s*(?:<div class="sbc-dots"|</div>)#s', $sec, $rail);

    expect(substr_count($rail[1], 'class="sbc-it"'))->toBe(6)
        ->and($sec)->not->toContain('sold out')
        ->and($rail[1])->toContain('data-kbb-add="')
        ->and($rail[1])->toContain('aria-label="'.e(__('store.product_card.add_to_cart')).': Sbc acne product 0"')
        // The owner, 20 Oct 2026: the cut price as the grid card prints it,
        // not the discount %. MUTATION: print 'off' again instead of 'was' → red.
        ->and($rail[1])->toContain('<span class="sbc-was">'.\App\Support\Money::format(10000).'</span>')
        ->and($rail[1])->not->toContain('class="sbc-off"')
        ->and($rail[1])->toContain(\App\Support\Money::format(7800));

    // The section's number, then a tab's own.
    $rows = ShopByConcern::defaults();
    $rows[1]['n'] = 3;
    sbcTabWrite(['home_sbc_count' => '4', 'home_sbc_tabs' => json_encode($rows)]);
    $sec = sbcTabSection(sbcTabHome());
    preg_match('#id="sbc-p0".*?<div class="sbc-rail">(.*?)</div>\s*(?:<div class="sbc-dots"|</div>)#s', $sec, $r0);
    preg_match('#id="sbc-p1".*?<div class="sbc-rail">(.*?)</div>\s*(?:<div class="sbc-dots"|</div>)#s', $sec, $r1);
    expect(substr_count($r0[1], 'class="sbc-it"'))->toBe(4)->and(substr_count($r1[1], 'class="sbc-it"'))->toBe(3);

    // A variable product is bought by its option: the button opens its page.
    $v = Product::where('slug', 'sbc-acne-0')->first();
    $v->type = 'variable';
    $v->save();
    $sec = sbcTabSection(sbcTabHome());
    expect($sec)->toContain('<a class="sbc-cp" href="'.$v->url().'" aria-label="'.e(__('store.product_card.view_product')).': Sbc acne product 0">');
});

it('keeps the homepage query count flat however many tabs and products it shows', function () {
    /*
     * THE DEFECT: a query (or two) per tab — the N+1 the speed rules forbid —
     * so eight tabs cost more than three, and twelve products more than six.
     * MUTATION: call GridSections::pool() per tab in ShopByConcern::fetch()
     * → the eight-tab count is higher, red.
     */
    sbcTabCatalogue(12);
    sbcTabSwitch(true);
    $count = function (array $values): int {
        sbcTabWrite($values);
        Cache::flush();
        SettingsService::forgetMemo();
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        test()->get('/')->assertOk();
        return $n;
    };

    $three = array_slice(ShopByConcern::defaults(), 0, 3);
    $eight = ShopByConcern::defaults();

    // One request first: the process's own first-request reads (a translations
    // map, a settings key) belong to no section and must not land on one side.
    $count(['home_sbc_tabs' => json_encode($eight), 'home_sbc_count' => '6']);
    $a = $count(['home_sbc_tabs' => json_encode($three), 'home_sbc_count' => '6']);
    $b = $count(['home_sbc_tabs' => json_encode($eight), 'home_sbc_count' => '6']);
    $c = $count(['home_sbc_tabs' => json_encode($eight), 'home_sbc_count' => '12']);

    expect($b)->toBe($a)->and($c)->toBe($a);

    // And the section's own build is three round trips, cold, whatever the tabs.
    $n = 0;
    DB::listen(function () use (&$n) { $n++; });
    $settings = HomeSections::settings();
    Cache::flush();
    $n = 0;
    ShopByConcern::data($settings);
    expect($n)->toBe(4); // collections, the UNION of every tab's ids, products, brands

    // Warm, it costs nothing.
    $n = 0;
    ShopByConcern::data($settings);
    expect($n)->toBe(0);
});

it('mirrors on /ar with Arabic product names and logical properties only', function () {
    /*
     * THE DEFECT: an English product name on /ar when an Arabic one exists, or
     * a physical left/right rule that does not mirror.
     * MUTATION: print $p->name instead of $p->t('name') → red; write
     * `left:36px` for the View all button → red on the CSS check.
     */
    sbcTabCatalogue(2);
    sbcTabSwitch(true);
    ArabicShop::on();
    $p = Product::where('slug', 'sbc-acne-0')->first();
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $p->id, 'field' => 'name', 'value' => 'منتج حب الشباب', 'status' => 'published']);

    $html = sbcTabHome('/ar/');
    expect($html)->toContain('dir="rtl"')->and(sbcTabSection($html))->toContain('<span class="sbc-n">منتج حب الشباب</span>');
    // "View all" points the way Arabic reads (‹) once the layout is mirrored,
    // as it is on the live shop; English keeps ›.
    \App\Models\Setting::updateOrCreate(['key' => \App\Support\Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);
    $html = sbcTabHome('/ar/');
    expect(sbcTabSection($html))->toContain('<span aria-hidden="true">‹</span>')->not->toContain('<span aria-hidden="true">›</span>');

    $css = sbcTabCss();
    expect($css)->not->toBe('')
        ->and($css)->not->toMatch('/(margin|padding|border)-(left|right)\b/')
        ->and($css)->not->toMatch('/(?<![-\w])(left|right)\s*:/')
        ->and($css)->not->toMatch('/text-align\s*:\s*(left|right)/')
        ->and($css)->not->toMatch('/\bfloat\s*:/')
        ->and($css)->not->toMatch('/\b(round|color-mix)\(/');
});

it('saves through the homepage content endpoint, cleans every tab, and refuses a role without the capability', function () {
    /*
     * THE DEFECT: a hand-made POST putting markup in a tip, an unknown icon or
     * source in the page, a javascript: link behind View all, a non-colour in
     * the style attribute — or an admin without Appearance rights saving it.
     * MUTATION: drop strip_tags from ShopByConcern::text() → red on <script>;
     * drop the scheme check in link() → red on javascript:.
     */
    sbcTabCatalogue(2);
    sbcTabSwitch(true);
    HomepageContentAdminRoutes::wire(app());

    // No new endpoint: the section saves through Homepage content's own
    // (content.manage) and the manual pick searches through the hub's
    // typeahead (homepagehub.search). Both fail closed for a role without them.
    $cap = \App\Support\AdminCapabilities::class;
    expect($cap::forPath('POST', 'admin-api/homepage/content'))->toBe('content.manage')
        ->and($cap::forPath('GET', 'admin-api/homepage-hub/products'))->toBe('homepagehub.search')
        ->and($cap::roleCan('customer-support', 'content.manage'))->toBeFalse()
        ->and($cap::roleCan('customer-support', 'homepagehub.search'))->toBeFalse()
        ->and($cap::roleCan('owner', 'content.manage'))->toBeTrue();
    // Signed out: refused.
    expect($this->postJson('/admin-api/homepage/content', ['slides' => [], 'copy' => ['home_sbc_title' => 'Hacked']])->status())->toBeIn([401, 403]);
    expect(HomeSections::text(HomeSections::settings(), 'home_sbc_title'))->toBe('Shop by Concern');

    test()->actingAs(\App\Models\AdminUser::create(['name' => 'O', 'email' => 'o-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']), 'admin');
    $json = $this->getJson('/admin-api/homepage/content')->assertOk()->json();
    $tabs = array_column($json['tabs'], 'key');
    expect(array_slice($tabs, array_search('concerns', $tabs, true), 2))->toBe(['concerns', 'sbc']);

    $evil = [['k' => 'acne', 'on' => true, 'i' => 'nope', 'l' => '<script>x</script>Acne', 'h' => '<b>Bold</b>', 't' => "Tip\x07 one", 'src' => 'evil', 'sort' => 'DROP', 'n' => 99,
        'u' => 'javascript:alert(1)', 'img' => 'javascript:alert(2)', 'cats' => ['1', 'x', -3]]];
    $this->postJson('/admin-api/homepage/content', ['slides' => [], 'copy' => [
        'home_sbc_tabs' => json_encode($evil), 'home_sbc_accent' => 'red;}body{display:none', 'home_sbc_count' => '99',
    ]])->assertOk();

    $c = HomeSections::settings();
    $row = json_decode($c['home_sbc_tabs'], true)[0];
    expect($row['l'])->toBe('xAcne')->and($row['h'])->toBe('Bold')->and($row['t'])->toBe('Tip one')
        ->and($row['i'])->toBe('acne')->and($row['src'])->toBe('auto')->and($row['sort'])->toBe('bestselling')
        ->and($row['n'])->toBe(0)->and($row['u'])->toBe('')->and($row['img'])->toBe('')->and($row['cats'])->toBe([1])
        ->and($c['home_sbc_accent'])->toBe('#E0567B')
        ->and(HomeSections::pick($c, 'home_sbc_count'))->toBe('6');

    $sec = sbcTabSection(sbcTabHome());
    expect($sec)->not->toContain('<script>x')->not->toContain('javascript:')
        ->and($sec)->toContain('--sbc-pk:#E0567B');

    // A good save sticks, and a typed View all goes where it says.
    $rows = ShopByConcern::defaults();
    $rows[0]['u'] = '/brands/sbc-brand/';
    $rows[0]['l'] = 'Breakouts';
    sbcTabWrite(['home_sbc_tabs' => json_encode($rows), 'home_sbc_accent' => '#123abc']);
    $sec = sbcTabSection(sbcTabHome());
    expect($sec)->toContain('<span>Breakouts</span>')->and($sec)->toContain('<a class="sbc-go" href="/brands/sbc-brand/">')
        ->and($sec)->toContain('--sbc-pk:#123ABC');
});

it('offers the section on the All sections list with its own editor, and the editor on both screens', function () {
    /*
     * MUTATION: drop 'sbc' from HomepageHub::EDITORS → no content editor;
     * drop the sbc-tabs branch from either screen → the raw JSON textarea.
     */
    expect(\App\Services\HomepageHub::EDITORS['sbc'])->toBe(['kind' => 'content', 'tab' => 'sbc'])
        ->and(HomeSections::TABS['sbc'][2])->toContain('home_sbc_tabs')
        ->and(HomepageContent::SCHEMA['home_sbc_tabs']['options']['picker'])->toBe('sbc-tabs');

    $screen = (string) file_get_contents(resource_path('views/admin/partials/homepage-content-screen.blade.php'));
    $hub = (string) file_get_contents(resource_path('views/admin/partials/homepage-hub.blade.php'));
    expect(substr_count($screen, "@include('admin.partials.sbc-tabs-editor')"))->toBe(1)
        ->and(substr_count($screen, "f.options.picker === 'sbc-tabs' && window.kbbSbc"))->toBe(1)
        ->and(substr_count($hub, "f.options.picker === 'sbc-tabs' && window.kbbSbc"))->toBe(1);

    // The editor's vocabulary is constants: eight icons, each sized.
    $v = ShopByConcern::editorVocab();
    expect(count($v['icons']))->toBe(8)->and(count($v['defaults']))->toBe(8);
    foreach ($v['icons'] as $icon) {
        expect($icon['svg'])->toStartWith('<svg viewBox="0 0 24 24" width="20" height="20"');
    }
});
