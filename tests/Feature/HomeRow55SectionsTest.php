<?php

declare(strict_types=1);

/**
 * THE HOMEPAGE, SECTION BY SECTION — master plan row 55.          (Lane HA)
 *
 * The owner, 3 October 2026: the picture banner, then 1 Big savings bundles,
 * 2 Best Sellers, 3 Brands, 4 #KBeautyBliss Spotted, 5 Trending, 6 Blog,
 * 7 Under AED 54, 8 a two-column feature, 9 About us — "don't include anything
 * from our existing homepage on extreabeauty, except banner", "the grid cards
 * design must not be changed", "i dont want to repeat anything", and on every
 * section "focus on SEO angle too".
 *
 * Every case below says what the defect would look like on the shop and how
 * to make it go red. Mutation notes marked RUN were made and seen red.
 */

use App\Models\Brand;
use App\Models\Post;
use App\Models\Product;
use App\Services\HomepageContent;
use App\Services\HomepageSections;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Support\GridSkins;
use App\Support\HomeSections;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\HomepageContentAdminRoutes;
use Tests\Support\LegacyHomeSections;

beforeEach(function () {
    app(SettingsService::class)->set('demo_content', false);
});

/** A visible simple product. Prices in fils. */
function r55Product(string $name, ?int $price, array $extra = []): Product
{
    static $n = 0;
    $n++;

    $p = Product::create(array_merge([
        'slug' => 'r55-'.$n.'-'.\Illuminate\Support\Str::slug($name),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'stock_status' => 'instock',
        'price' => $price,
        // Above the demo catalogue the migrations leave behind, so a rail
        // ranked by sales shows these first.
        'total_sales' => 100000 - $n,
    ], $extra));

    return $p;
}

function r55Write(array $values): void
{
    ModuleSchema::write(app(SettingsService::class), 'homepage_content', HomepageContent::SCHEMA, $values);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

function r55Home(): string
{
    SettingsService::forgetMemo();
    Cache::flush();

    return test()->get('/')->assertOk()->getContent();
}

/** The opening tags of the homepage's <section> elements, in document order. */
function r55Sections(string $html): array
{
    preg_match_all('#<section class="sec ([^"]*)"#', $html, $m);

    return $m[1];
}

/** One section's whole element, by its hs-<key> class. */
function r55Section(string $html, string $key): string
{
    return preg_match('#<section class="sec hs (?:hs-rail )?hs-'.$key.'\b.*?</div></section>#s', $html, $m) === 1 ? $m[0] : '';
}

function r55Catalogue(int $n = 12): void
{
    for ($i = 1; $i <= $n; $i++) {
        r55Product('Catalogue product '.$i, 3000 + $i * 1000);
    }
}

/* ═══ 1. ORDER, AND NOTHING ELSE FROM THE OLD PAGE ═══════════════════════ */

it('draws the nine sections in the owner\'s order, About us last', function () {
    /*
     * THE DEFECT: a section in the wrong place — Trending above the brands, or
     * About us somewhere other than the end ("About us — last on the page").
     * MUTATION (RUN): move the About us include above the feature in
     * store/home.blade.php → red on the last-section assertion.
     */
    r55Catalogue();
    Post::create(['slug' => 'r55-post', 'title' => 'A routine', 'status' => 'published', 'published_at' => now()->subDay()]);

    $keys = array_values(array_filter(array_map(function (string $cls) {
        foreach (['bndl' => 'bundles', 'hs-bestselling' => 'bestselling', 'hs-brands' => 'brands', 'hs-trending' => 'trending',
            'hs-blog' => 'blog', 'hs-under54' => 'under54', 'hs-feature' => 'feature', 'hs-about' => 'about'] as $needle => $key) {
            if (preg_match('/(^| )'.preg_quote($needle, '/').'( |$)/', $cls)) {
                return $key;
            }
        }

        return null;
    }, r55Sections(r55Home()))));

    expect($keys)->toBe(['bundles', 'bestselling', 'brands', 'trending', 'blog', 'under54', 'feature', 'about']);

    // And About us is the LAST section element in .kbb-home.
    $sections = r55Sections(r55Home());
    expect(end($sections))->toContain('hs-about');

    // The registry agrees, so Appearance → Homepage paints the order the shop draws.
    $registry = array_keys(HomepageSections::REGISTRY);
    $pos = fn (string $k) => array_search($k, $registry, true);
    expect($pos('bundles'))->toBeLessThan($pos('bestselling'))
        ->and($pos('bestselling'))->toBeLessThan($pos('brands'))
        ->and($pos('brands'))->toBeLessThan($pos('spotted'))
        ->and($pos('spotted'))->toBeLessThan($pos('trending'))
        ->and($pos('trending'))->toBeLessThan($pos('blog'))
        ->and($pos('blog'))->toBeLessThan($pos('under54'))
        ->and($pos('under54'))->toBeLessThan($pos('feature'))
        ->and($pos('feature'))->toBeLessThan($pos('about'));
});

it('includes Lane HB\'s Spotted partial exactly once, between Brands and Trending', function () {
    /*
     * THE DEFECT: the Instagram section built and never drawn (zero), or drawn
     * twice beside the old strip. Pinned on the FINISHED state, which is the
     * one that can regress — CLAUDE.md's "do not pin that your own work is NOT
     * wired up yet".
     * MUTATION: delete the @includeIf line, or move it under the trending
     * include → red.
     */
    $tpl = (string) file_get_contents(resource_path('views/store/home.blade.php'));

    expect(substr_count($tpl, "@includeIf('partials.home.spotted')"))->toBe(1);

    $at = strpos($tpl, "@includeIf('partials.home.spotted')");
    expect($at)->toBeGreaterThan(strpos($tpl, "partials.home.hs-brands"))
        ->and($at)->toBeLessThan(strpos($tpl, "'key' => 'trending'"));

    // The old strip stands down the moment the partial exists, so the page
    // never draws two Spotted sections.
    expect($tpl)->toContain("@unless (\$sections->hidden('spotted') || view()->exists('partials.home.spotted'))");
});

it('switches every other old section off — and keeps each one a switch away', function () {
    /*
     * THE DEFECT: the old page still standing around the new one ("don't
     * include anything from our existing homepage ... except banner"), or an
     * old section deleted rather than switched off.
     * MUTATION (RUN): take 'quiz' out of HomepageSections::OFF_BY_DEFAULT →
     * the skin quiz form is on the page and this is red.
     */
    r55Catalogue();

    // Above the footer only (2.60.372): the new site footer carries its own
    // "Your email for offers" box, which uses the same data-kbb-subscribe hook
    // as the old newsletter band and is on every page by design.
    $html = strstr(r55Home(), '<footer', true) ?: r55Home();

    foreach ([
        'class="tick ' => 'promo ticker', 'class="delivery ' => 'delivery strip', '<div class="cats">' => 'category circles',
        'Recommended for you' => 'Recommended', '<div class="rsteps">' => 'Build your routine', '<div class="quiz">' => 'skin quiz',
        '<div class="rgrid">' => 'reviews', '<div class="trust">' => 'trust row', 'data-kbb-subscribe' => 'newsletter',
        "Flash sale" => 'flash sale',
    ] as $needle => $what) {
        expect(str_contains($html, $needle))->toBeFalse("the {$what} is still on the homepage");
    }

    foreach (HomepageSections::OFF_BY_DEFAULT as $key) {
        $row = app(HomepageSections::class)->all()[$key];
        expect($row['desktop'])->toBeFalse()->and($row['mobile'])->toBeFalse();
    }

    // A switch away: the quiz comes back exactly as it was.
    LegacyHomeSections::on(['quiz']);
    expect(r55Home())->toContain('<div class="quiz">');
});

it('writes the owner\'s page into a SAVED layout too, and drops the old positions', function () {
    /*
     * THE DEFECT: the live shop has saved Appearance → Homepage, so a default
     * alone would leave every old section on there and the new ones placed by
     * an order saved before they existed.
     * MUTATION: remove the `unset($section['order'])` in the migration → the
     * saved order survives and orderIsDefault() is false → red.
     */
    app(SettingsService::class)->set('homepage_sections', [
        'quiz' => ['desktop' => true, 'mobile' => true, 'order' => 0, 'skin' => null],
        'hero' => ['desktop' => true, 'mobile' => false, 'order' => 5, 'skin' => null],
    ]);
    app(SettingsService::class)->set('about_text', 'An older paragraph.');

    (require database_path('migrations/2027_07_27_000100_clear_caches_home_row55_sections.php'))->up();
    SettingsService::forgetMemo();

    $all = app(HomepageSections::class)->all();
    expect($all['quiz']['desktop'])->toBeFalse()
        ->and($all['quiz']['mobile'])->toBeFalse()
        // What he did NOT ask about is kept: the hero's own phone switch.
        ->and($all['hero']['mobile'])->toBeFalse()
        ->and(app(HomepageSections::class)->orderIsDefault())->toBeTrue()
        ->and(app(HomepageContent::class)->aboutText())->toBe(HomeSections::ABOUT_DEFAULT);
});

/* ═══ 2. HEADINGS, WORDS AND THE ONE H1 ═══════════════════════════════════ */

it('ships the owner\'s headings, and no heading or description repeats another', function () {
    /*
     * THE DEFECT: two sections saying the same thing ("i dont want to repeat
     * anything") or a heading other than the search-written one he was given.
     * MUTATION (RUN): set the Trending heading default to the Best Sellers one
     * → red on the uniqueness assertion.
     */
    r55Catalogue();
    Post::create(['slug' => 'r55-post', 'title' => 'A routine', 'status' => 'published', 'published_at' => now()->subDay()]);

    $html = r55Home();

    foreach ([
        'Best-Selling Korean Skincare in the UAE', 'Shop Top Korean Beauty Brands', 'Trending K-Beauty This Week',
        'Korean Skincare Tips &amp; Guides', 'K-Beauty Under AED 54', 'About K-Beauty Bliss UAE',
        'Embrace the Sunshine!', 'Top K-Beauty Picks',
    ] as $h) {
        expect(substr_count($html, '>'.$h.'</h2>'))->toBe(1, $h);
    }

    preg_match_all('#<h2[^>]*>(.*?)</h2>#s', $html, $h2);
    $texts = array_map(fn ($t) => trim(strip_tags($t)), $h2[1]);
    expect(array_unique($texts))->toBe($texts);

    preg_match_all('#<div class="hs-head[^"]*"><div class="hs-ht">.*?<p>(.*?)</p>#s', $html, $subs);
    expect(count($subs[1]))->toBeGreaterThanOrEqual(5)
        ->and(array_unique($subs[1]))->toBe($subs[1]);

    // And the defaults themselves, not only this render.
    $words = [];
    foreach (HomepageContent::SCHEMA as $key => $f) {
        if (preg_match('/^home_(bs|br|tr|bl|u54|ab)_(title|sub)$|^home_ft_[lr]_(title|text)$/', $key)) {
            $words[$key] = $f['default'];
        }
    }
    expect(array_unique($words))->toBe($words);
});

it('prints the owner\'s four About us paragraphs verbatim, as four paragraphs of real text', function () {
    /*
     * MUTATION: drop the blank-line split in HomeSections::about() → one <p>
     * and this is red.
     */
    $about = r55Section(r55Home(), 'about');

    expect(substr_count($about, '<p>'))->toBe(4)
        ->and($about)->toContain('<p>At K-Beauty Bliss UAE, we are passionate about bringing the best of Korean beauty to skincare enthusiasts across the UAE.')
        ->and($about)->toContain('including 1-3 day delivery across the UAE and free shipping on orders over 199 AED')
        ->and($about)->toContain('Your journey to flawless, radiant skin starts here!</p>');
});

it('keeps exactly one h1 on the page', function () {
    // MUTATION: make a section heading an <h1> → 2, red.
    r55Catalogue();

    expect(substr_count(r55Home(), '<h1'))->toBe(1);
});

/* ═══ 3. THE CARDS ARE THE SHOP'S OWN ═════════════════════════════════════ */

it('draws every product grid through the shop\'s own card, at the shop\'s own skin', function () {
    /*
     * THE DEFECT: a forked or restyled card on the homepage — "the grid cards
     * design must not be changed as we have finalized that and that's live on
     * all categories etc pages".
     * MUTATION: pass 'skin' => 'luxe' in hs-rail, or print a card by hand →
     * red.
     */
    $rail = (string) file_get_contents(resource_path('views/partials/home/hs-rail.blade.php'));
    expect(substr_count($rail, "@include('partials.home.grid'"))->toBe(1)
        ->and($rail)->toContain("'skin' => null")
        ->and($rail)->not->toContain('kbb-card');

    $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb.css')));
    $from = (int) strpos($css, '.kbb-home .sec.hs{');
    $mine = substr($css, $from, (int) strpos($css, '.kbb-home .util{', $from) - $from);
    preg_match_all('#[^{}]*\{#', $mine, $selectors);
    expect(count($selectors[0]))->toBeGreaterThan(40);
    foreach ($selectors[0] as $sel) {
        expect($sel)->not->toContain('kbb-card');
    }

    r55Catalogue();
    $section = r55Section(r55Home(), 'bestselling');
    expect($section)->toContain('<div class="kbb-pgrid" data-skin="'.GridSkins::resolve(null).'">')
        ->and(substr_count($section, 'class="kbb-card kbb-tile"'))->toBe(8);
});

it('fetches the larger of the two counts and hides the rest per device in CSS', function () {
    /*
     * 8 on a laptop as 4 × 2 and 6 on a phone as 2 × 3; Trending 8/6; Under
     * AED 54 10 (5 per row) and 6 (2 per row).
     * MUTATION: drop the `hs-mc-6` rule from kbb.css, or fetch min() instead
     * of max() → red.
     */
    r55Catalogue(14);
    $html = r55Home();

    expect(r55Section($html, 'bestselling'))->toContain('hs-dc-8 hs-mc-6')->toContain('--hs-cols-d:4;--hs-cols-m:2')
        ->and(substr_count(r55Section($html, 'bestselling'), 'class="kbb-card kbb-tile"'))->toBe(8)
        ->and(r55Section($html, 'trending'))->toContain('hs-dc-8 hs-mc-6')->toContain('--hs-cols-d:4;--hs-cols-m:2');

    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $from = (int) strpos($css, '.kbb-home .sec.hs{');
    $mine = substr($css, $from, (int) strpos($css, '.kbb-home .util{', $from) - $from);
    $desk = substr($mine, (int) strpos($mine, '@media (min-width:901px){'));
    $desk = substr($desk, 0, (int) strpos($desk, '@media (max-width:900px){'));
    $phone = substr($mine, (int) strpos($mine, '@media (max-width:900px){'));
    expect($desk)->toContain('.kbb-home .hs-dc-8 .hs-grid>*>:nth-child(n+9)')
        ->and($desk)->toContain('.kbb-home .hs-dc-10 .hs-grid>*>:nth-child(n+11)')
        ->and($phone)->toContain('.kbb-home .hs-mc-6 .hs-grid>*>:nth-child(n+7)')
        ->and($phone)->toContain('.kbb-home .hs-mc-10 .hs-brandlist>:nth-child(n+11)')
        ->and($desk)->toContain('grid-template-columns:repeat(var(--hs-cols-d),minmax(0,1fr))')
        ->and($phone)->toContain('grid-template-columns:repeat(var(--hs-cols-m),minmax(0,1fr))');

    // Under AED 54: 10 / 6, 5 / 2.
    $u = HomeSections::rail(HomeSections::settings(), 'under54');
    expect($u['count_d'])->toBe(10)->and($u['count_m'])->toBe(6)->and($u['fetch'])->toBe(10)
        ->and($u['style'])->toContain('--hs-cols-d:5;--hs-cols-m:2');

    // Every count a select offers has its rule, on both devices.
    foreach (['home_bs_count_d', 'home_br_count_d'] as $key) {
        foreach (array_keys(HomepageContent::SCHEMA[$key]['options']) as $n) {
            expect($desk)->toContain('.kbb-home .hs-dc-'.$n.' .hs-grid>*>:nth-child(n+'.($n + 1).')')
                ->and($phone)->toContain('.kbb-home .hs-mc-'.$n.' .hs-brandlist>:nth-child(n+'.($n + 1).')');
        }
    }
});

/* ═══ 4. UNDER AED 54: THE PRICE THE SHOPPER PAYS ═════════════════════════ */

it('keeps under the ceiling exactly the products a shopper can buy at or under AED 54', function () {
    /*
     * THE DEFECT, BOTH WAYS: comparing the raw `price` column keeps a product
     * whose sale is over and drops one on sale under the line — and drops
     * every VARIABLE product, whose own price is NULL (its money lives on its
     * variations), because NULL compares as nothing.
     * MUTATION (RUN): replace EffectivePrice::whereRange() in
     * GridSections::fetchPool() with ->where('price', '<=', $maxFils) → the
     * on-sale toner and the variable cushion vanish and this is red.
     */
    r55Product('Cheap Simple Cleanser', 5000);
    r55Product('Exactly Fifty Four Mask', 5400);
    r55Product('Over The Line Serum', 8000);
    r55Product('On Sale Toner', 6000, ['sale_price' => 4500]);
    r55Product('Sale Ended Cream', 6000, ['sale_price' => 4500, 'sale_ends_at' => now()->subDay()]);
    $cushion = r55Product('Variable Cushion', null, ['type' => 'variable']);
    $pricey = r55Product('Variable Pricey Kit', null, ['type' => 'variable']);

    foreach ([[$cushion, [4500, 6900]], [$pricey, [6000, 7000]]] as [$p, $prices]) {
        foreach ($prices as $j => $fils) {
            DB::table('product_variants')->insert(['product_id' => $p->id, 'sku' => 'R55-'.$p->id.'-'.$j, 'price' => $fils,
                'stock_status' => 'instock', 'position' => $j, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    $under = r55Section(r55Home(), 'under54');

    foreach (['Cheap Simple Cleanser', 'Exactly Fifty Four Mask', 'On Sale Toner', 'Variable Cushion'] as $in) {
        expect($under)->toContain($in);
    }

    foreach (['Over The Line Serum', 'Sale Ended Cream', 'Variable Pricey Kit'] as $out) {
        expect($under)->not->toContain($out);
    }

    // The Best Sellers row has no ceiling and carries the expensive ones.
    expect(r55Section(r55Home(), 'bestselling'))->toContain('Over The Line Serum');
});

/* ═══ 5. TRENDING: WHAT IT COUNTS ═════════════════════════════════════════ */

it('ranks Trending by what was ordered and viewed in the last seven days, not by lifetime sales', function () {
    /*
     * The definition (GridSections::trendingScores): 3 × units ordered in the
     * last 7 days on a real order status + 1 × product-page views in the same
     * window; anything else falls through to best sellers.
     * MUTATION (RUN): drop the whereIn('o.status', REAL_STATUSES) → the
     * cancelled order counts and 'Cancelled Toner' climbs → red.
     */
    // Every total above the demo catalogue's, so the fall-through order is
    // these five by lifetime sales.
    $lifetime = r55Product('Lifetime Best Seller', 5000, ['total_sales' => 99999]);
    $hot = r55Product('Ordered This Week', 5000, ['total_sales' => 90001]);
    $viewed = r55Product('Viewed This Week', 5000, ['total_sales' => 90002]);
    $cancelled = r55Product('Cancelled Toner', 5000, ['total_sales' => 90003]);
    $old = r55Product('Ordered Last Month', 5000, ['total_sales' => 90004]);

    $order = function (Product $p, string $status, $when, int $qty) {
        $id = DB::table('orders')->insertGetId(['order_number' => 'R55'.uniqid(), 'email' => 'x@example.test', 'status' => $status,
            'currency' => 'AED', 'created_at' => $when, 'updated_at' => $when]);
        DB::table('order_items')->insert(['order_id' => $id, 'product_id' => $p->id, 'name' => $p->name, 'quantity' => $qty,
            'created_at' => $when, 'updated_at' => $when]);
    };
    $order($hot, 'processing', now()->subDays(2), 3);
    $order($cancelled, 'cancelled', now()->subDay(), 50);
    $order($old, 'completed', now()->subDays(30), 50);
    DB::table('product_view_days')->insert(['product_id' => $viewed->id, 'day' => now()->subDay()->toDateString(), 'views' => 4]);

    $scores = \App\Services\GridSections::trendingScores();
    expect($scores)->toBe([$hot->id => 9, $viewed->id => 4]);

    $section = r55Section(r55Home(), 'trending');
    $at = fn (string $name) => strpos($section, $name);
    expect($at('Ordered This Week'))->toBeLessThan($at('Viewed This Week'))
        ->and($at('Viewed This Week'))->toBeLessThan($at('Lifetime Best Seller'))
        ->and($at('Lifetime Best Seller'))->toBeLessThan($at('Cancelled Toner'));
});

/* ═══ 6. BRANDS: ONE LIST, TWO LOOKS ══════════════════════════════════════ */

it('prints each brand link once — photo card on a laptop, name tile on a phone — with no product count', function () {
    /*
     * THE DEFECT: two copies of every brand link (one grid per device), which
     * is content shown twice to Google; or the product count the owner turned
     * down ("design A WITHOUT the product count").
     * MUTATION: render a second, phone-only list → the href count is 2, red.
     *
     * (Lane BS, 5 October) ADVANCED: the phone's default is Text only and the
     * laptop's "image + name, no logo", both as the owner asked — so no brand
     * carries `has-logo` by default; a pictured one carries `has-img`. The
     * three looks are pinned in HomeBrandLooksTest.
     */
    $withLogo = Brand::create(['slug' => 'r55-logo', 'name' => 'Logo Brand', 'logo' => '/uploads/r55/logo.png',
        'banner' => ['enabled' => false, 'image' => '/uploads/r55/photo.webp', 'image_alt' => 'Logo Brand serums on a shelf']]);
    $plain = Brand::create(['slug' => 'r55-plain', 'name' => 'Plain Brand']);
    $evil = Brand::create(['slug' => 'r55-evil', 'name' => 'Evil Brand', 'logo' => 'javascript:alert(1)']);
    foreach ([$withLogo, $plain, $evil] as $b) {
        r55Product($b->name.' Toner', 3000, ['brand_id' => $b->id]);
    }

    $section = r55Section(r55Home(), 'brands');

    expect(substr_count($section, 'href="'.$withLogo->url().'"'))->toBe(1)
        ->and(substr_count($section, 'href="'.$plain->url().'"'))->toBe(1)
        ->and($section)->toContain('class="hs-brand has-img" href="'.$withLogo->url().'"')
        ->and($section)->not->toContain('has-logo')
        ->and($section)->toContain('class="hs-brand" href="'.$plain->url().'"')
        ->and($section)->toContain('alt="Logo Brand serums on a shelf"')
        ->and($section)->toContain('<span class="hs-blb"><b>Plain Brand</b></span>')
        ->and($section)->not->toContain('javascript:')
        ->and($section)->not->toContain('products</span>')
        ->and($section)->toContain('>Shop all brands<');
});

/* ═══ 7. BLOG ═════════════════════════════════════════════════════════════ */

it('draws the latest three articles, or the owner\'s three in his order', function () {
    // MUTATION: drop the keyBy/map re-ordering in fetchPosts() → the pick
    // comes back in id order and the order assertion is red.
    $posts = [];
    foreach (['First guide', 'Second guide', 'Third guide', 'Fourth guide'] as $i => $t) {
        $posts[] = Post::create(['slug' => 'r55-'.$i, 'title' => $t, 'status' => 'published', 'published_at' => now()->subDays(10 - $i)]);
    }

    $blog = r55Section(r55Home(), 'blog');
    expect(substr_count($blog, 'class="hs-post"'))->toBe(3)
        ->and($blog)->not->toContain('First guide');

    r55Write(['home_bl_source' => 'manual', 'home_bl_picks' => $posts[2]->id.','.$posts[0]->id.','.$posts[3]->id]);
    $blog = r55Section(r55Home(), 'blog');
    expect(strpos($blog, 'Third guide'))->toBeLessThan(strpos($blog, 'First guide'))
        ->and(strpos($blog, 'First guide'))->toBeLessThan(strpos($blog, 'Fourth guide'))
        ->and($blog)->not->toContain('Second guide');
});

/* ═══ 8. SEO ══════════════════════════════════════════════════════════════ */

it('gives every new image its alt, width, height and lazy loading, and every rail and the blog an ItemList', function () {
    /*
     * MUTATION: drop loading="lazy" from hs-blog, or the itemList() call from
     * hs-rail → red.
     */
    r55Catalogue();
    // (Lane BS) With a banner photo: the laptop card no longer falls back to
    // the logo ("image + name, no logo!"), so a logo alone draws no <img>.
    $b = Brand::create(['slug' => 'r55-b', 'name' => 'B Brand', 'logo' => '/uploads/r55/b.png', 'banner' => ['image' => '/uploads/r55/b-photo.webp']]);
    r55Product('B toner', 3000, ['brand_id' => $b->id]);
    Post::create(['slug' => 'r55-p', 'title' => 'P guide', 'status' => 'published', 'published_at' => now()->subDay(), 'cover' => '/uploads/r55/c.webp']);
    r55Write(['home_ft_l_img' => '/uploads/r55/sun.webp']);

    $html = r55Home();

    foreach (['brands', 'blog', 'feature'] as $key) {
        preg_match_all('#<img [^>]*>#', r55Section($html, $key), $imgs);
        expect($imgs[0])->not->toBeEmpty();
        foreach ($imgs[0] as $img) {
            expect($img)->toMatch('/ alt="[^"]+"/')->toMatch('/ width="\d+"/')->toMatch('/ height="\d+"/')->toContain('loading="lazy"');
        }
    }

    foreach (['bestselling', 'trending', 'under54', 'blog'] as $key) {
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', r55Section($html, $key), $m);
        $ld = json_decode($m[1] ?? '', true);
        expect($ld['@type'] ?? null)->toBe('ItemList', $key)
            ->and($ld['itemListElement'][0]['position'])->toBe(1)
            ->and($ld['itemListElement'][0]['url'])->toStartWith('http');
    }

    // Organization and WebSite are still in the head.
    expect($html)->toContain('"@type":"WebSite"');
});

it('cannot be made to close its own script tag from a product name', function () {
    // MUTATION: drop JSON_HEX_TAG from HomeSections::itemList() → red.
    $json = HomeSections::itemList('x', [['url' => '/p/', 'name' => '</script><script>alert(1)</script>']]);

    expect(substr_count($json, '</script>'))->toBe(1)
        ->and($json)->toContain('\\u003C\\/script\\u003E');
});

/* ═══ 9. SECURITY: URLS AND SELECTS ═══════════════════════════════════════ */

it('scheme-checks every link and picture a setting supplies', function () {
    /*
     * MUTATION (RUN): return $url unchecked from HomeSections::url() → the
     * javascript: and //evil cases come back as links, red.
     */
    expect(HomeSections::url('javascript:alert(1)', '/x'))->toBe('/x')
        ->and(HomeSections::url('//evil.test/a', '/x'))->toBe('/x')
        ->and(HomeSections::url("java\nscript:alert(1)", '/x'))->toBe('/x')
        ->and(HomeSections::url('/best-sellers"><b>', '/x'))->toBe('/x')
        ->and(HomeSections::url('data:text/html,hi', '/x'))->toBe('/x')
        ->and(HomeSections::url('/collections/sunscreens/', '/x'))->toBe('/collections/sunscreens/')
        ->and(HomeSections::url('https://example.test/a?b=1', '/x'))->toBe('https://example.test/a?b=1')
        ->and(HomeSections::image('javascript:alert(1)'))->toBe('')
        ->and(HomeSections::image('/uploads/a.webp'))->toBe('/uploads/a.webp');

    r55Catalogue();
    r55Write(['home_bs_url' => 'javascript:alert(1)', 'home_ft_l_url' => 'javascript:alert(2)', 'home_ft_l_img' => 'javascript:alert(3)']);
    $html = r55Home();

    expect($html)->not->toContain('javascript:alert')
        // A refused button link draws no button; the feature falls back to the Sunscreens category.
        ->and(r55Section($html, 'bestselling'))->not->toContain('hs-btn')
        ->and(r55Section($html, 'feature'))->toContain('href="/collections/sunscreens/"');
});

it('stores a select only as one of its own options, so no setting reaches a class or a style', function () {
    // MUTATION: read the raw value in HomeSections::pick() → `hs-bg-red;x` is printed, red.
    r55Catalogue();
    app(SettingsService::class)->set('home_bs_bg', 'red" onmouseover="x');
    app(SettingsService::class)->set('home_bs_count_d', '999');
    app(SettingsService::class)->set('home_bs_pt_d', '1px;background:url(x)');

    $section = r55Section(r55Home(), 'bestselling');

    expect($section)->toContain('hs-bg-none')->toContain('hs-dc-8')->toContain('--hs-pt-d:40px')
        ->and($section)->not->toContain('onmouseover')
        ->and($section)->not->toContain('url(x)');
});

/* ═══ 10. THE ADMIN ═══════════════════════════════════════════════════════ */

it('puts every control on Appearance → Homepage content, one tab per section, saved by the existing endpoint', function () {
    /*
     * MUTATION: drop `...HomeSections::TABS` from HomepageContent::TABS → the
     * tabs are missing from the payload, red.
     */
    HomepageContentAdminRoutes::wire(app());
    test()->actingAs(\App\Models\AdminUser::create(['name' => 'O', 'email' => 'o-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']), 'admin');

    r55Product('Picked One', 3000);
    $json = $this->getJson('/admin-api/homepage/content')->assertOk()->json();
    $tabs = array_column($json['tabs'], 'label', 'key');

    expect($tabs)->toMatchArray(['bestselling' => 'Best Sellers', 'brands' => 'Brands', 'trending' => 'Trending', 'blog' => 'Blog',
        'under54' => 'Under AED 54', 'feature' => 'Two-column feature', 'about' => 'About us'])
        ->and(array_column($json['picks']['products'], 'name'))->toContain('Picked One');

    $this->postJson('/admin-api/homepage/content', ['slides' => [], 'copy' => [
        'home_tr_title' => 'What is hot in Dubai', 'home_u54_max' => '49', 'home_bs_bg' => 'not-a-colour',
    ]])->assertOk();

    \App\Models\Setting::flushMap();
    $c = HomeSections::settings();
    expect($c['home_tr_title'])->toBe('What is hot in Dubai')
        ->and(HomeSections::rail($c, 'under54')['max_fils'])->toBe(4900)
        ->and(HomeSections::pick($c, 'home_bs_bg'))->toBe('none');
});

it('refuses the controls to a signed-in role without the homepage capability', function () {
    // Fails closed: `support` has admin access and not content.manage.
    HomepageContentAdminRoutes::wire(app());
    test()->actingAs(\App\Models\AdminUser::create(['name' => 'S', 'email' => 's-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'support']), 'admin');

    $this->getJson('/admin-api/homepage/content')->assertForbidden();
    $this->postJson('/admin-api/homepage/content', ['slides' => [], 'copy' => ['home_tr_title' => 'x']])->assertForbidden();
});
