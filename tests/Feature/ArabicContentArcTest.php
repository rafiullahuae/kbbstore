<?php

declare(strict_types=1);

/*
 * Lane ARC -- the Arabic CONTENT of the live catalogue, shipped as data.
 *
 * On 2.60.519 the owner opened /ar through his preview link and read English:
 * every product, brand, category, menu label, page and post title, and his
 * own homepage headings ("Best-Sellers", "Glow on the Go!"), because their
 * Arabic lived only in the preview fixture. Three migrations now carry it:
 *
 *   2027_11_03_100000  products / brands / categories / menu / pages / posts
 *   2027_11_03_100100  the homepage's own wording (settings twins, banner cards)
 *   2027_11_03_100200  product short descriptions (phase 2)
 *
 * Each is pinned here: no class of this shop named (the 2.60.512 rule), the
 * live-catalogue guard, published rows only where none existed, keyed by slug,
 * the search index, idempotence, and the storefront readers that print them.
 */

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Translation;
use App\Services\Translation\TranslationStore;
use App\Support\HomeArabic;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;

const ARC_MIGRATIONS = [
    '2027_11_03_100000_arabic_content_catalogue',
    '2027_11_03_100100_arabic_content_homepage_wording',
    '2027_11_03_100200_arabic_content_short_descriptions',
];

function arcMigration(string $name): object
{
    return require database_path('migrations/'.$name.'.php');
}

/** @return array<string, mixed> the migration's own data, as it will run */
function arcData(string $name): array
{
    $m = arcMigration($name);

    return json_decode((new ReflectionClassConstant($m, 'DATA'))->getValue(), true);
}

/** Make the test database look like the live shop: half the guard slugs and one more. */
function arcLiveCatalogue(): array
{
    $guard = arcData(ARC_MIGRATIONS[0])['guard'];
    $now = now();
    $rows = [];

    foreach (array_slice($guard, 0, intdiv(count($guard), 2) + 1) as $slug) {
        $rows[] = ['slug' => $slug, 'name' => 'EN '.$slug, 'price' => 5000, 'status' => 'publish',
            'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple', 'created_at' => $now, 'updated_at' => $now];
    }

    // insertOrIgnore: the demo catalogue the migrations seed already holds a
    // few of the live slugs.
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('products')->insertOrIgnore($chunk);
    }

    return array_column($rows, 'slug');
}

function arcAr(string $group, int $id, string $field): ?object
{
    return DB::table('translations')->where('locale', 'ar')->where('group', $group)
        ->where('item_id', $id)->where('field', $field)->first();
}

/* ═════════════════════════ the migrations themselves ═════════════════════════ */

it('names no class of this shop, so a migration run against last release\'s opcache cannot fatal', function () {
    /*
     * 2.60.512 broke live because a migration called a class its own package
     * added, and the updater ran it while the old classes were still loaded.
     * Every token of each file is read: a qualified name may only be one of
     * the framework's, never App\ or a bare shop class.
     *
     * MUTATION: put `\App\Services\Translation\TranslationStore::flush();` in
     * forgetCaches() of 100000 and this is red on that line.
     */
    foreach (ARC_MIGRATIONS as $name) {
        $file = database_path('migrations/'.$name.'.php');
        expect(is_file($file))->toBeTrue($name.' is missing');

        foreach (token_get_all((string) file_get_contents($file)) as $t) {
            if (! is_array($t) || ! in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                continue;
            }

            $qualified = ltrim($t[1], '\\');

            // A global built-in (\Throwable) is not a class of this shop.
            if (! str_contains($qualified, '\\')) {
                continue;
            }

            expect($qualified)->toStartWith('Illuminate\\', "{$name} line {$t[2]} names {$t[1]}");
        }
    }
});

it('leaves a database that is not the live catalogue exactly as it was', function () {
    /*
     * A fresh install, a preview fixture and this test database hold none of
     * the live slugs, so all three migrations must write nothing at all.
     *
     * MUTATION: return true from isLiveCatalogue() and this is red: the
     * categories this suite seeds by slug (toners...) would get Arabic rows.
     */
    Category::firstOrCreate(['slug' => 'toners'], ['name' => 'Toners']);
    $before = DB::table('translations')->count();
    $settings = DB::table('settings')->count();

    foreach (ARC_MIGRATIONS as $name) {
        arcMigration($name)->up();
    }

    expect(DB::table('translations')->count())->toBe($before)
        ->and(DB::table('settings')->count())->toBe($settings);
});

it('writes the live catalogue\'s Arabic as published rows, keyed by slug and label', function () {
    $slugs = arcLiveCatalogue();
    $brand = Brand::firstOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
    $cat = Category::firstOrCreate(['slug' => 'toners'], ['name' => 'Toners']);
    $cat->forceFill(['description' => '<p>EN</p>'])->save();
    $menu = \App\Models\Menu::firstOrCreate(['slug' => 'arc-primary'], ['name' => 'Primary', 'location' => 'primary']);
    $item = \App\Models\MenuItem::create(['menu_id' => $menu->id, 'label' => 'Trending Brands', 'url' => '/brands/', 'position' => 0]);
    $post = \App\Models\Post::firstOrCreate(['slug' => 'pdrn-skincare-what-is-it-does-it-actually-work'], ['title' => 'PDRN Skincare', 'status' => 'published']);

    arcMigration(ARC_MIGRATIONS[0])->up();

    $data = arcData(ARC_MIGRATIONS[0])['groups'];
    $p = DB::table('products')->where('slug', $slugs[0])->first();

    expect(arcAr('products', (int) $p->id, 'name'))->not->toBeNull()
        ->and(arcAr('products', (int) $p->id, 'name')->value)->toBe($data['products']['name'][$slugs[0]])
        ->and(arcAr('products', (int) $p->id, 'name')->status)->toBe('published')
        ->and(arcAr('products', (int) $p->id, 'name')->source_hash)->toBe(sha1((string) $p->name))
        ->and(arcAr('brands', $brand->id, 'name')->value)->toBe('أنوا')
        ->and(arcAr('categories', $cat->id, 'name')->value)->toBe('تونر')
        ->and(arcAr('categories', $cat->id, 'description'))->not->toBeNull()
        ->and(arcAr('menu_items', $item->id, 'label')->value)->toBe('العلامات الرائجة')
        ->and(arcAr('posts', $post->id, 'title'))->not->toBeNull();

    // The shop reads it: the product's name on /ar is the Arabic.
    ArabicShop::on();
    app()->setLocale('ar');
    expect(Product::find($p->id)->t('name'))->toBe($data['products']['name'][$slugs[0]]);
});

it('never touches a field that already has an Arabic row, the owner\'s or a draft', function () {
    /*
     * MUTATION: drop the `isset($taken[$id])` check and the owner's own name
     * is replaced -- or, on MySQL, the insert duplicates his row. Red either way.
     */
    $slugs = arcLiveCatalogue();
    $owner = DB::table('products')->where('slug', $slugs[0])->value('id');
    $draft = DB::table('products')->where('slug', $slugs[1])->value('id');
    TranslationStore::put('ar', 'products', (int) $owner, 'name', 'اسم كتبه المالك', Translation::STATUS_PUBLISHED);
    TranslationStore::put('ar', 'products', (int) $draft, 'name', 'مسودة', Translation::STATUS_DRAFT);

    arcMigration(ARC_MIGRATIONS[0])->up();

    expect(arcAr('products', (int) $owner, 'name')->value)->toBe('اسم كتبه المالك')
        ->and(arcAr('products', (int) $draft, 'name')->value)->toBe('مسودة')
        ->and(arcAr('products', (int) $draft, 'name')->status)->toBe('draft')
        ->and(DB::table('translations')->where('group', 'products')->whereIn('item_id', [$owner, $draft])->where('field', 'name')->count())->toBe(2);
});

it('writes nothing more the second time it runs', function () {
    arcLiveCatalogue();
    arcMigration(ARC_MIGRATIONS[0])->up();
    arcMigration(ARC_MIGRATIONS[2])->up();
    $once = DB::table('translations')->count();

    arcMigration(ARC_MIGRATIONS[0])->up();
    arcMigration(ARC_MIGRATIONS[2])->up();

    expect(DB::table('translations')->count())->toBe($once);
});

it('indexes each new Arabic name for the Arabic search, and forgets the cached maps', function () {
    /*
     * A raw insert fires no model hook, so without its own index step an
     * Arabic name would be printed and never found by an Arabic search.
     *
     * MUTATION: remove $this->indexSearch($names) and this is red.
     */
    $slugs = arcLiveCatalogue();
    Cache::put('kbb.translations.ar', ['stale' => 'x'], 300);

    arcMigration(ARC_MIGRATIONS[0])->up();

    $id = (int) DB::table('products')->where('slug', $slugs[0])->value('id');

    expect(DB::table('search_texts_ar')->where('product_id', $id)->value('text'))->not->toBeEmpty()
        ->and(Cache::has('kbb.translations.ar'))->toBeFalse();
});

it('gives back only what it wrote when rolled back', function () {
    $slugs = arcLiveCatalogue();
    $id = (int) DB::table('products')->where('slug', $slugs[2])->value('id');
    arcMigration(ARC_MIGRATIONS[0])->up();
    DB::table('translations')->where('group', 'products')->where('item_id', $id)->where('field', 'name')->update(['value' => 'عدّلها المالك']);

    arcMigration(ARC_MIGRATIONS[0])->down();

    expect(arcAr('products', $id, 'name')->value)->toBe('عدّلها المالك')
        ->and(DB::table('translations')->where('group', 'products')->where('field', 'name')->count())->toBe(1);
});

it('ships every short description as the simple markup the product page prints raw', function () {
    /*
     * RICH_FIELDS are printed unescaped. These rows bypass TranslationStore's
     * RichText::clean() (a migration may not call it), so the data itself is
     * held to the allowlist: p, br, strong, b, em, i, ul, ol, li, h3, h4, span,
     * and no attribute at all.
     */
    $values = arcData(ARC_MIGRATIONS[2])['groups']['products']['short_description'];

    expect(count($values))->toBeGreaterThan(0);

    foreach ($values as $slug => $html) {
        preg_match_all('/<\/?([a-z0-9]+)([^>]*)>/i', $html, $m, PREG_SET_ORDER);

        foreach ($m as [$all, $tag, $attrs]) {
            expect(in_array(strtolower($tag), ['p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'h3', 'h4', 'span'], true))->toBeTrue("{$slug}: <{$tag}>")
                ->and(trim($attrs, " /"))->toBe('', "{$slug}: attributes on <{$tag}>");
        }
    }
});

it('holds the brand spellings the owner chose: kbeautyarabia\'s, and sound for its mistranslations', function () {
    /*
     * kbeautyarabia.com spells most brands by sound and is followed; for the
     * few it machine-translated ("ROUND LAB" as مختبر الجولة, "a lab of the
     * round"; NIDA as a performing-arts institute; FULLY as "completely";
     * NINELESS as "nine less") the sound spelling of the glossary is used.
     */
    $names = arcData(ARC_MIGRATIONS[0])['groups']['brands']['name'];

    expect($names['anua'])->toBe('أنوا')
        ->and($names['medicube'])->toBe('ميديكوب')
        ->and($names['dr-althea'])->toBe('الدكتورة ألثيا')
        ->and($names['round-lab'])->toBe('راوند لاب')
        ->and($names['nida'])->toBe('نيدا')
        ->and($names['fully'])->toBe('فولي');

    foreach (arcData(ARC_MIGRATIONS[0])['groups'] as $group => $fields) {
        foreach ($fields as $field => $values) {
            foreach ($values as $key => $value) {
                $latin = preg_replace('/#KBeautyBliss/', '', strip_tags($value));
                expect(preg_match('/[A-Za-z]{2,}/', $latin))->toBe(0, "{$group}.{$field}.{$key} still has Latin: {$value}");
            }
        }
    }
});

/* ═════════════════════════ the homepage's own wording ═════════════════════════ */

it('writes an Arabic twin only for the owner\'s own English it knows, and never over his Arabic', function () {
    /*
     * MUTATION: drop `array_key_exists($key.'_ar', $stored)` and the owner's
     * own home_br_title_ar is overwritten -- red on the last expectation.
     */
    arcLiveCatalogue();
    DB::table('settings')->insert([
        ['key' => 'home_bs_title', 'value' => 'Best-Sellers', 'autoload' => 1],
        ['key' => 'home_hb_sub', 'value' => 'Your beauty essentials, better together.', 'autoload' => 1],
        ['key' => 'home_tr_sub', 'value' => 'Something nobody translated', 'autoload' => 1],
        ['key' => 'home_br_title', 'value' => 'Top Brands', 'autoload' => 1],
        ['key' => 'home_br_title_ar', 'value' => 'علاماتي', 'autoload' => 1],
    ]);
    $set = BannerSet::create(['name' => 'Cards banner', 'slug' => 'cards-banner', 'status' => 'publish']);
    $card = BannerCard::create(['banner_set_id' => $set->id, 'heading' => 'Glow on the Go!', 'button_label' => 'Shop Now', 'body' => 'Mine', 'status' => 'publish']);
    $card->forceFill(['body_ar' => 'نصّي'])->save();

    arcMigration(ARC_MIGRATIONS[1])->up();

    $v = fn (string $k) => DB::table('settings')->where('key', $k)->value('value');
    $card->refresh();

    expect($v('home_bs_title_ar'))->toBe('الأكثر مبيعًا')
        ->and($v('home_hb_sub_ar'))->toBe('أساسيات جمالكِ، أجمل معًا.')
        ->and($v('home_tr_sub_ar'))->toBeNull()
        ->and($card->heading_ar)->toBe('إشراقة أينما ذهبتِ!')
        ->and($card->button_label_ar)->toBe('تسوّقي الآن')
        ->and($card->body_ar)->toBe('نصّي')
        ->and($v('home_br_title_ar'))->toBe('علاماتي');

    /*
     * And it rolls back. The *_ar card columns are NOT NULL DEFAULT '', and
     * down() used to write null into them: "NOT NULL constraint failed:
     * banner_cards.heading_ar" on the preview, so the rollback threw half way.
     *
     * MUTATION: put `=> null` back in down() and this is red.
     */
    arcMigration(ARC_MIGRATIONS[1])->down();
    $card->refresh();

    expect($card->heading_ar)->toBe('')
        ->and($card->button_label_ar)->toBe('')
        ->and($card->body_ar)->toBe('نصّي')
        ->and($v('home_bs_title_ar'))->toBeNull()
        ->and($v('home_br_title_ar'))->toBe('علاماتي');
});

it('prints the bundles row and the Spotted line in Arabic on /ar, and nothing new in English', function () {
    /*
     * The defect on the live /ar: "Your beauty essentials, better together."
     * and "See what everyone's loving from K-Beauty Bliss" in English under
     * Arabic headings -- neither row had an Arabic twin at all.
     *
     * MUTATION: remove the HomeArabic::on() block in HomeBundles::config() and
     * the first expectation is red.
     */
    foreach (['home_hb_sub' => 'Your beauty essentials, better together.', 'home_hb_sub_ar' => 'أساسيات جمالكِ، أجمل معًا.',
        'spotted_home_sub' => 'See it', 'spotted_home_sub_ar' => 'شاهديها'] as $k => $val) {
        DB::table('settings')->insert(['key' => $k, 'value' => $val, 'autoload' => 1]);
    }
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();

    app()->setLocale('ar');
    expect(\App\Support\HomeBundles::config()['sub'])->toBe('أساسيات جمالكِ، أجمل معًا.')
        ->and(app(\App\Services\SpottedSettings::class)->section()['sub'])->toBe('شاهديها');

    app()->setLocale('en');
    expect(\App\Support\HomeBundles::config()['sub'])->toBe('Your beauty essentials, better together.')
        ->and(app(\App\Services\SpottedSettings::class)->section()['sub'])->toBe('See it');
});

it('keeps an emptied English line hidden in Arabic too', function () {
    DB::table('settings')->insert(['key' => 'home_hb_sub_ar', 'value' => 'سطر', 'autoload' => 1]);
    app()->setLocale('ar');

    expect(\App\Support\HomeBundles::config()['sub'])->toBe('');
});

it('prints the homepage quiet heading in Arabic on /ar, and the same English as before', function () {
    app()->setLocale('en');
    expect(HomeArabic::quietH1(null))->toBe('K-Beauty Bliss — authentic Korean skincare in the UAE')
        ->and(HomeArabic::quietH1('My shop'))->toBe('My shop');

    app()->setLocale('ar');
    expect(HomeArabic::quietH1(null))->toBe(HomeArabic::QUIET_H1_AR)
        ->and(HomeArabic::quietH1('My shop'))->toBe('My shop');
});

/* ═════════════════════════ approve all, and speed ═════════════════════════ */

it('approves every Arabic draft in one press, Lane AR\'s round-2 wording included', function () {
    /*
     * The owner presses Translation -> Progress -> "Approve all N drafts". The
     * rows 2027_11_02_100000 seeded (and 100100 corrected) must be among what
     * that publishes: same locale, group `ui`, item 0, status `draft`.
     */
    $laneAr = DB::table('translations')->where('locale', 'ar')->where('group', 'ui')->where('status', 'draft')
        ->whereIn('field', array_keys((new ReflectionClassConstant(require database_path('migrations/2027_11_02_100000_seed_lane_ar2_wording_arabic_drafts.php'), 'ROWS'))->getValue()))
        ->count();
    expect($laneAr)->toBeGreaterThan(0);

    $admin = \App\Models\AdminUser::create(['name' => 'ARC owner', 'email' => 'arc-'.uniqid().'@example.com',
        'password' => bcrypt('secret'), 'role' => 'owner']);

    $this->actingAs($admin, 'admin')->postJson('/admin-api/translations/publish', ['locale' => 'ar'])->assertOk();

    expect(DB::table('translations')->where('locale', 'ar')->where('status', 'draft')->count())->toBe(0);
});

it('costs the same queries on an Arabic brand page with 3 products or 40, every one translated', function () {
    /*
     * The translations are read in batches -- the short map once per request,
     * long fields once per page -- so Arabic names and short lines on every
     * card must not add a query per product.
     *
     * MUTATION: read `$p->translations()->get()` per card and this is red.
     */
    ArabicShop::on();
    $brand = Brand::create(['slug' => 'arc-brand', 'name' => 'Arc']);
    TranslationStore::put('ar', 'brands', $brand->id, 'name', 'آرك', Translation::STATUS_PUBLISHED);

    $make = function (int $from, int $n) use ($brand): void {
        for ($i = $from; $i < $from + $n; $i++) {
            $p = Product::create(['slug' => "arc-p-{$i}", 'name' => "Arc {$i}", 'brand_id' => $brand->id, 'price' => 1000 + $i,
                'short_description' => 'EN line', 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple']);
            TranslationStore::put('ar', 'products', $p->id, 'name', "منتج {$i}", Translation::STATUS_PUBLISHED);
            TranslationStore::put('ar', 'products', $p->id, 'short_description', 'سطر', Translation::STATUS_PUBLISHED);
        }
    };

    $make(1, 3);
    $this->get('/ar/brands/arc-brand/')->assertOk();
    DB::enableQueryLog();
    $this->get('/ar/brands/arc-brand/')->assertOk()->assertSee('منتج 1');
    $small = count(DB::getQueryLog());

    $make(4, 37);
    $this->get('/ar/brands/arc-brand/')->assertOk();
    DB::flushQueryLog();
    $this->get('/ar/brands/arc-brand/')->assertOk()->assertSee('منتج 40');
    $big = count(DB::getQueryLog());

    expect($big)->toBe($small);
});

it('sets the sold-out badge in Cairo on /ar, and leaves the English page without it', function () {
    /*
     * On the preview's /ar/brands/anua/ the "نفدت الكمية" badge over a
     * sold-out card photo was painted in DejaVu Sans (Chromium's own report
     * of the font per text node): kbb.css gives `.kbb-tile .kbb-soldout`
     * `font:700 10px 'Outfit',sans-serif`, Outfit has no Arabic, and the
     * Arabic face block in layouts/store restated every hard-coded card stack
     * except this one.
     *
     * MUTATION: drop `html[lang="ar"] .kbb-tile .kbb-soldout` from the
     * #kbb-arabic-face block and the first expectation is red.
     */
    ArabicShop::on();
    $ar = $this->get('/ar/')->assertOk()->getContent();
    expect($ar)->toMatch('#html\[lang="ar"\] \.kbb-tile \.kbb-soldout[^{]*\{font-family:\'Outfit\',\'Cairo\',sans-serif\}#');

    app()->setLocale('en');
    $en = $this->get('/')->assertOk()->getContent();
    expect($en)->not->toContain('kbb-arabic-face');
});
