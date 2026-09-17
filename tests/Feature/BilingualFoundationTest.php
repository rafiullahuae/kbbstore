<?php

declare(strict_types=1);

/*
 * The bilingual foundation, end to end (Lane EP).
 *
 * Nothing here asserts that the shop is translated — it is not, and translating
 * it is a later phase. What is asserted is that the machinery under that phase
 * works: a locale is resolved from a URL, a string resolves through the
 * fallback chain, a correction typed into the admin is live on the next
 * request, a machine translation cannot reach a shopper without being approved,
 * and switching the whole thing off leaves the shop exactly as it is today.
 *
 * Every test that could reach a paid API uses NullProvider or a fake. Nothing
 * in this file makes a network call, and running the suite costs nothing.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Translation;
use App\Services\SettingsService;
use App\Services\Translation\DatabaseTranslationLoader;
use App\Services\Translation\GoogleProvider;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\MachineTranslationRunner;
use App\Services\Translation\NullProvider;
use App\Services\Translation\TranslationCredentials;
use App\Services\Translation\TranslationEstimate;
use App\Services\Translation\TranslationProvider;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;
use App\Support\OrderLocale;
use App\Support\Url;
use Illuminate\Support\Facades\DB;

/** Turn Arabic on, the way the admin screen does. */
function epArabicOn(bool $rtl = true): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => $rtl ? '1' : '0', 'autoload' => true]);

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

function epArabic(string $group, int $itemId, string $field, string $value, string $status = Translation::STATUS_PUBLISHED): Translation
{
    return TranslationStore::put('ar', $group, $itemId, $field, $value, $status, Translation::SOURCE_MANUAL);
}

/* ══════════════════ 1. the URL shape ══════════════════ */

it('ships with Arabic off, and /ar is genuinely absent rather than empty', function () {
    // The whole of decision 3 from the coordinator: applying this package
    // changes nothing a shopper can see. No row is seeded, and
    // SettingsService::get() returns its default only when the row is ABSENT —
    // so "absent" has to mean off, and this is what pins that.
    expect(Setting::query()->whereIn('key', [Locale::SETTING_ENABLED, Locale::SETTING_RTL])->count())->toBe(0)
        ->and(Locale::enabled('ar'))->toBeFalse()
        ->and(Locale::enabledCodes())->toBe(['en']);

    // Not "renders but empty" — no route, the same 404 the site gives today.
    test()->get('/ar/my-wishlist/')->assertNotFound();

    // And nothing anywhere points at it.
    expect(Url::to('/shop/'))->toBe('/shop/')
        ->and(Locale::alternatePaths('/shop/'))->toBe([]);
});

it('serves the same page under /ar once Arabic is switched on', function () {
    epArabicOn();

    test()->get('/my-wishlist/')->assertOk();
    test()->get('/ar/my-wishlist/')->assertOk();
});

it('leaves English unprefixed and answers /en with one 301 to it', function () {
    epArabicOn();

    // Decision 1: nothing that is already indexed moves. The English address is
    // the address it has always been.
    test()->get('/my-wishlist/')->assertOk();

    // And the address the owner asked for resolves, as a redirect rather than a
    // second copy of the page — two URLs serving one document is the duplicate
    // content this avoids.
    $response = test()->get('/en/my-wishlist/');
    $response->assertStatus(301);

    /*
     * Asserted WITHOUT the trailing slash, and that is a fact about the test
     * harness rather than about the redirect.
     *
     * MakesHttpRequests::prepareUrlForRequest() does trim(url($uri), '/'), so
     * Laravel's own test client strips a trailing slash off every request it
     * makes: this test asks the application for /en/my-wishlist, not
     * /en/my-wishlist/, and the middleware correctly hands back what it was
     * asked about. In production the slash survives, which the unit-level
     * assertion below pins directly on the splitting rule — the only part of
     * this that the harness cannot reach.
     */
    expect($response->headers->get('Location'))->toEndWith('/my-wishlist')
        ->and($response->headers->get('Location'))->not->toContain('/en/');

    // U-01: a trailing slash is load-bearing on this shop, and the redirect
    // preserves whatever the request carried rather than normalising it away.
    // Through redirect()->to() it did NOT — UrlGenerator::format() rtrims —
    // which would have made the address the owner asked for cost two hops.
    expect(Locale::splitPath('/en/my-wishlist/'))->toBe(['en', '/my-wishlist/'])
        ->and(Locale::splitPath('/ar/shop/'))->toBe(['ar', '/shop/'])
        ->and(Locale::splitPath('/ar'))->toBe(['ar', '/']);
});

it('keeps the query string across the /en redirect', function () {
    epArabicOn();

    $response = test()->get('/en/shop/?orderby=popularity');

    expect($response->headers->get('Location'))->toContain('orderby=popularity')
        ->and($response->headers->get('Location'))->toContain('/shop');
});

it('composes the locale prefix INSIDE the base path, never outside it', function () {
    // /kbb-upgrade/ar/shop/ and never /ar/kbb-upgrade/shop/. The base path is
    // where the application is MOUNTED and the language is a fact about the
    // page, so the deployment prefix has to be outermost or the front
    // controller is never reached.
    config(['kbb.base_path' => '/kbb-upgrade']);
    Url::forgetBase();
    epArabicOn();

    app()->setLocale('ar');

    expect(Url::to('/shop/'))->toBe('/kbb-upgrade/ar/shop/');

    app()->setLocale('en');

    expect(Url::to('/shop/'))->toBe('/kbb-upgrade/shop/');

    config(['kbb.base_path' => '']);
    Url::forgetBase();
});

it('never prefixes a media path, because an image has no language', function () {
    epArabicOn();
    app()->setLocale('ar');

    // /wp-content/uploads is served off disk by the web server; PHP never sees
    // the request, so /ar/wp-content/... is a 404 for an image on every Arabic
    // page. This is the single most load-bearing exclusion in the feature.
    expect(Url::media('2024/01/toner.jpg'))->toBe('/wp-content/uploads/2024/01/toner.jpg')
        ->and(Url::media('2024/01/toner.jpg'))->not->toContain('/ar/');

    // And the same for everything else the web server answers itself.
    foreach (['storage/x.css', 'build/assets/app.js', 'fonts/a.woff2'] as $static) {
        expect(Url::to($static))->not->toContain('/ar/');
    }
});

it('never prefixes the admin, so the back office has one address', function () {
    epArabicOn();
    app()->setLocale('ar');

    expect(Url::to('/admin-api/orders/'))->toBe('/admin-api/orders/')
        ->and(Locale::localisable('/admin-api/translations'))->toBeFalse();
});

it('gives machine-facing files exactly one address', function () {
    epArabicOn();
    app()->setLocale('ar');

    // A second sitemap is an SEO defect, not a feature.
    expect(Locale::localisable('/sitemap.xml'))->toBeFalse()
        ->and(Locale::localisable('/robots.txt'))->toBeFalse()
        ->and(Url::to('/sitemap.xml'))->toBe('/sitemap.xml');

    test()->get('/ar/sitemap.xml')->assertNotFound();
});

it('reserves ar and en as root slugs so a post cannot become unreachable', function () {
    // The middleware eats /ar before the router sees it, so a post whose slug
    // was literally "ar" would be published at an address nothing can reach,
    // with nothing anywhere saying why.
    expect(\App\Http\Controllers\Store\PageController::RESERVED_SLUGS)->toContain('ar')
        ->and(\App\Http\Controllers\Store\PageController::RESERVED_SLUGS)->toContain('en');
});

it('does not add the locale segment twice however the path is written', function () {
    epArabicOn();
    app()->setLocale('ar');

    expect(Locale::withSegment('/ar/shop/'))->toBe('/ar/shop/')
        ->and(Locale::withSegment('/shop/'))->toBe('/ar/shop/');
});

/* ══════════════════ 2. html lang, dir and hreflang ══════════════════ */

it('marks an Arabic page as Arabic and right-to-left', function () {
    epArabicOn(rtl: true);

    $html = test()->get('/ar/my-wishlist/')->getContent();

    expect($html)->toContain('<html lang="ar" dir="rtl">');
});

it('keeps Arabic and right-to-left as two separate switches', function () {
    // The awkward combination the owner asked to be able to reach: Arabic live
    // while the mirrored stylesheet is still being built. Legitimate mid-
    // rollout and wrong once it is finished — reported, never prevented.
    epArabicOn(rtl: false);

    $html = test()->get('/ar/my-wishlist/')->getContent();

    expect($html)->toContain('<html lang="ar" dir="ltr">')
        ->and(Locale::isRtl('ar'))->toBeFalse();
});

it('emits hreflang in both directions, plus x-default, only once there are two languages', function () {
    // With one language there is nothing to point at, and a lone hreflang is a
    // documented way to have the tag ignored later.
    expect(test()->get('/my-wishlist/')->getContent())->not->toContain('hreflang');

    epArabicOn();

    $html = test()->get('/my-wishlist/')->getContent();

    // BOTH directions, including this page's own address: a page that lists its
    // alternates without listing itself reads as duplicate content.
    expect($html)->toContain('hreflang="en"')
        ->and($html)->toContain('hreflang="ar"')
        ->and($html)->toContain('hreflang="x-default"')
        ->and($html)->toContain('/ar/my-wishlist/');

    // And the same pair from the Arabic side.
    $arabic = test()->get('/ar/my-wishlist/')->getContent();

    expect($arabic)->toContain('hreflang="en"')->and($arabic)->toContain('hreflang="ar"');
});

it('loads an Arabic typeface only on an Arabic page', function () {
    epArabicOn();

    // Poppins carries no Arabic glyphs at all, so without this the shop renders
    // in whatever the device falls back to. And an extra render-blocking
    // stylesheet on every English page is the defect the account-panel font
    // block was written to fix.
    expect(test()->get('/ar/my-wishlist/')->getContent())->toContain('family=Cairo')
        ->and(test()->get('/my-wishlist/')->getContent())->not->toContain('family=Cairo');
});

/* ══════════════════ 3. interface strings, end to end ══════════════════ */

it('renders the English defaults from code when nothing is translated', function () {
    // The wishlist page, converted end to end. In English this is byte-for-byte
    // the page it was before the conversion.
    $html = test()->get('/my-wishlist/')->getContent();

    expect($html)->toContain('Nothing saved yet')
        ->and($html)->toContain('Tap the heart on any product to keep it here for later.')
        ->and($html)->toContain('Start browsing');
});

it('serves the Arabic the owner typed, on the very next request', function () {
    epArabicOn();

    epArabic(Translation::GROUP_UI, 0, 'store.wishlist.empty_title', 'لا شيء محفوظ بعد');

    // No cache clear, no release, no shell. This is the whole requirement:
    // "if we found anything incorrect, we can correct it manually".
    $html = test()->get('/ar/my-wishlist/')->getContent();

    expect($html)->toContain('لا شيء محفوظ بعد')
        ->and($html)->not->toContain('Nothing saved yet');
});

it('falls back to English for a string with no Arabic yet', function () {
    epArabicOn();

    epArabic(Translation::GROUP_UI, 0, 'store.wishlist.empty_title', 'لا شيء محفوظ بعد');

    $html = test()->get('/ar/my-wishlist/')->getContent();

    // The honest degradation, stated in the design: the shop is live while it
    // is being translated, and a half-Arabic page still sells something.
    expect($html)->toContain('Start browsing');
});

it('shows untranslated strings in brackets when the owner is hunting for gaps', function () {
    epArabicOn();

    Setting::query()->updateOrCreate(['key' => 'translation_highlight_missing'], ['value' => '1', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();

    $html = test()->get('/ar/my-wishlist/')->getContent();

    // A review tool, off by default. Finding what is left becomes walking the
    // shop rather than reading a list.
    expect($html)->toContain('⟪Nothing saved yet⟫');
});

it('never serves a draft to a shopper', function () {
    epArabicOn();

    epArabic(Translation::GROUP_UI, 0, 'store.wishlist.empty_title', 'ترجمة آلية', Translation::STATUS_DRAFT);

    $html = test()->get('/ar/my-wishlist/')->getContent();

    expect($html)->not->toContain('ترجمة آلية')
        ->and($html)->toContain('Nothing saved yet');
});

it('lets a correction be typed in English too, without a release', function () {
    // The database beats the code default in BOTH locales. lang/ cannot be
    // shipped to on this host (UpdateGuard::ALLOWED_PREFIXES), so an English
    // wording the owner wants changed would otherwise need a package.
    TranslationStore::put('en', Translation::GROUP_UI, 0, 'store.wishlist.empty_title', 'Nothing here yet', Translation::STATUS_PUBLISHED);

    $html = test()->get('/my-wishlist/')->getContent();

    expect($html)->toContain('Nothing here yet')->and($html)->not->toContain('Nothing saved yet');
});

it('puts the number INSIDE the translation rather than concatenating around it', function () {
    // Concatenation is the thing that cannot be translated: Arabic does not put
    // the count where English puts it.
    expect(__('store.wishlist.saved_count', ['count' => 7]))->toBe('7 saved');

    epArabicOn();
    epArabic(Translation::GROUP_UI, 0, 'store.wishlist.saved_count', 'تم حفظ :count');
    app()->setLocale('ar');

    expect(__('store.wishlist.saved_count', ['count' => 7]))->toBe('تم حفظ 7');
});

it('leaves the framework its own translations', function () {
    // The loader DECORATES the FileLoader rather than replacing it, so
    // validation messages and pagination wording are untouched.
    expect(__('validation.required', ['attribute' => 'email']))->toBe('The email field is required.');
});

/* ══════════════════ 4. content translations, and the N+1 ══════════════════ */

it('translates a product name and falls back per field, not per row', function () {
    epArabicOn();

    $product = Product::create([
        'name' => 'Heartleaf Toner', 'slug' => 'ep-toner-'.uniqid(),
        'sku' => 'EP-1', 'price' => 9900, 'status' => 'publish', 'is_visible' => true,
        'short_description' => 'A gentle daily toner.',
    ]);

    epArabic('products', $product->id, 'name', 'تونر هارتليف');

    app()->setLocale('ar');

    expect($product->t('name'))->toBe('تونر هارتليف')
        // Not translated yet: the English, not a blank and not a placeholder.
        ->and($product->t('short_description'))->toBe('A gentle daily toner.')
        ->and($product->hasTranslation('name'))->toBeTrue()
        ->and($product->hasTranslation('short_description'))->toBeFalse();
});

it('refuses to translate a SKU, a slug or a price', function () {
    $product = Product::create([
        'name' => 'Guard', 'slug' => 'ep-guard-'.uniqid(), 'sku' => 'EP-SKU-9',
        'price' => 100, 'status' => 'publish', 'is_visible' => true,
    ]);

    // Not on the allowlist, so saveTranslations drops them entirely — there is
    // no path from an admin form to a translated identifier.
    $product->saveTranslations(['ar' => ['sku' => 'رمز', 'slug' => 'سلاج', 'price' => '٩٩']]);

    expect(Translation::query()->where('group', 'products')->count())->toBe(0);

    app()->setLocale('ar');

    expect($product->t('sku'))->toBe('EP-SKU-9')->and($product->t('slug'))->toBe($product->slug);
});

it('costs no extra queries to translate a grid of 24 products', function () {
    epArabicOn();

    $ids = [];

    for ($i = 0; $i < 24; $i++) {
        $p = Product::create([
            'name' => "Grid Product {$i}", 'slug' => "ep-grid-{$i}-".uniqid(),
            'sku' => "EP-G{$i}", 'price' => 1000 + $i, 'status' => 'publish', 'is_visible' => true,
        ]);
        $ids[] = $p->id;
        epArabic('products', $p->id, 'name', "منتج {$i}");
    }

    TranslationStore::flush();
    app()->setLocale('ar');

    $rows = Product::query()->whereIn('id', $ids)->get();

    // Warm the one cached map, exactly as a page render would.
    TranslationStore::map('ar');

    DB::enableQueryLog();
    DB::flushQueryLog();

    foreach ($rows as $row) {
        expect($row->t('name'))->toStartWith('منتج');
    }

    // THE MEASUREMENT, not an assertion of intent. Twenty-four rows, twenty-four
    // translations, ZERO queries: the whole published set for a locale is one
    // cached map, so a translation is an array lookup. The textbook N+1 that a
    // translations table invites does not happen.
    expect(DB::getQueryLog())->toHaveCount(0);

    DB::disableQueryLog();
});

it('can still eager-load translations in one query when the map stops being the answer', function () {
    epArabicOn();

    $brand = Brand::create(['name' => 'Anua', 'slug' => 'ep-anua-'.uniqid()]);
    epArabic('brands', $brand->id, 'description', 'وصف');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $loaded = Brand::query()->withTranslations('ar')->whereKey($brand->id)->get();

    // One for the brands, one for all their translations. Written and tested so
    // the swap is a one-line change rather than a redesign.
    expect(DB::getQueryLog())->toHaveCount(2)
        ->and($loaded->first()->relationLoaded('translations'))->toBeTrue();

    DB::disableQueryLog();
});

/* ══════════════════ 5. the editor write path ══════════════════ */

it('stores Arabic from an ordinary editor save, at the moment of creation', function () {
    epArabicOn();

    // The shape an editor posts. One call from whatever controller already
    // saves the English row, in the same request — not a separate screen
    // somebody has to remember to visit.
    $product = Product::create([
        'name' => 'New Product', 'slug' => 'ep-new-'.uniqid(),
        'sku' => 'EP-NEW', 'price' => 5000, 'status' => 'publish', 'is_visible' => true,
    ]);

    $written = $product->saveTranslations(['ar' => ['name' => 'منتج جديد']]);

    expect($written)->toBe(1);

    app()->setLocale('ar');

    expect($product->t('name'))->toBe('منتج جديد');
});

it('treats a blank box as "not translated yet" and never as "same as English"', function () {
    epArabicOn();

    $category = Category::create(['name' => 'Toners', 'slug' => 'ep-toners-'.uniqid()]);

    $category->saveTranslations(['ar' => ['name' => 'تونر']]);
    expect(Translation::query()->where('group', 'categories')->count())->toBe(1);

    // Clearing the box DELETES the row. That is the only reason the progress
    // figure can be trusted: "how much is left" is "how many fields have no
    // row".
    $category->saveTranslations(['ar' => ['name' => '']]);

    expect(Translation::query()->where('group', 'categories')->count())->toBe(0)
        ->and($category->hasTranslation('name', 'ar'))->toBeFalse();
});

it('can say "translated, deliberately identical" as a different state from "blank"', function () {
    epArabicOn();

    $brand = Brand::create(['name' => 'Anua', 'slug' => 'ep-anua2-'.uniqid()]);

    // A Korean brand name an Arabic shopper may well want left as it is. Typing
    // it in means "I have looked at this"; leaving it blank means "I have not".
    $brand->saveTranslations(['ar' => ['name' => 'Anua']]);

    expect($brand->hasTranslation('name', 'ar'))->toBeTrue()
        ->and($brand->t('name', 'ar'))->toBe('Anua');
});

it('publishes manual entry immediately and marks machine output as a draft', function () {
    $product = Product::create([
        'name' => 'Status Check', 'slug' => 'ep-status-'.uniqid(),
        'sku' => 'EP-ST', 'price' => 100, 'status' => 'publish', 'is_visible' => true,
    ]);

    $product->saveTranslations(['ar' => ['name' => 'يدوي']]);

    expect(Translation::query()->where('group', 'products')->first()->status)
        ->toBe(Translation::STATUS_PUBLISHED);
});

it('records what English a translation was made from, so staleness is answerable', function () {
    $product = Product::create([
        'name' => 'Original Name', 'slug' => 'ep-stale-'.uniqid(),
        'sku' => 'EP-STALE', 'price' => 100, 'status' => 'publish', 'is_visible' => true,
    ]);

    $product->saveTranslations(['ar' => ['name' => 'الاسم الأصلي']]);

    $row = Translation::query()->where('group', 'products')->first();

    expect($row->isStaleAgainst('Original Name'))->toBeFalse()
        // A published, confident, now-wrong translation with nothing saying so
        // is worse than no translation at all.
        ->and($row->isStaleAgainst('A Completely Different Name'))->toBeTrue();
});

it('hands the editor its Arabic boxes already filled, drafts included', function () {
    $product = Product::create([
        'name' => 'Editor Product', 'slug' => 'ep-editor-'.uniqid(),
        'sku' => 'EP-ED', 'price' => 100, 'status' => 'publish', 'is_visible' => true,
        'short_description' => 'Short.',
    ]);

    epArabic('products', $product->id, 'name', 'مسودة', Translation::STATUS_DRAFT);

    $boxes = $product->translationsForEditor();

    // A draft has to appear in the box the owner is looking at, or "approve"
    // means approving something he cannot see.
    expect($boxes['ar']['name']['value'])->toBe('مسودة')
        ->and($boxes['ar']['name']['status'])->toBe(Translation::STATUS_DRAFT)
        ->and($boxes['ar']['short_description']['value'])->toBe('')
        ->and($boxes)->not->toHaveKey('en');
});

/* ══════════════════ 6. the cache and the memo ══════════════════ */

it('sees a correction made after the first read, in the same process', function () {
    epArabicOn();
    app()->setLocale('ar');

    epArabic(Translation::GROUP_UI, 0, 'store.wishlist.empty_title', 'الأول');
    expect(TranslationStore::get('ar', Translation::GROUP_UI, 0, 'store.wishlist.empty_title'))->toBe('الأول');

    // The Setting::map() trap, written against. A model hook evicts BOTH the
    // cache and the per-process memo, so every writer evicts — including
    // writers that do not know TranslationStore exists.
    epArabic(Translation::GROUP_UI, 0, 'store.wishlist.empty_title', 'الثاني');

    expect(TranslationStore::get('ar', Translation::GROUP_UI, 0, 'store.wishlist.empty_title'))->toBe('الثاني');
});

it('evicts the cache when a translation is deleted, not only when one is saved', function () {
    epArabicOn();

    epArabic(Translation::GROUP_UI, 0, 'store.wishlist.empty_title', 'قيمة');
    expect(TranslationStore::get('ar', Translation::GROUP_UI, 0, 'store.wishlist.empty_title'))->toBe('قيمة');

    Translation::query()->where('field', 'store.wishlist.empty_title')->first()->delete();

    expect(TranslationStore::get('ar', Translation::GROUP_UI, 0, 'store.wishlist.empty_title'))->toBeNull();
});

it('lowercases every key so a case-insensitive collation cannot split a row', function () {
    // config/database.php sets utf8mb4_unicode_ci, under which 'Name' and
    // 'name' are the SAME value. The unique index would reject the second as a
    // duplicate while an un-normalised reader looked for a key that was never
    // written. Normalising means the index's opinion and the reader's opinion
    // are one opinion, on MySQL and on SQLite alike.
    TranslationStore::put('ar', 'UI', 0, 'Store.Wishlist.Title', 'عنوان', Translation::STATUS_PUBLISHED);

    $row = Translation::query()->first();

    expect($row->group)->toBe('ui')->and($row->field)->toBe('store.wishlist.title')
        ->and(TranslationStore::get('ar', 'ui', 0, 'store.wishlist.title'))->toBe('عنوان');
});

it('never puts a unique index or an equality test on Arabic text', function () {
    // Under utf8mb4_unicode_ci two visibly different Arabic strings — the same
    // letters with and without tashkeel — compare EQUAL. A unique index on
    // `value`, or a where('value', ...) de-duplication, would silently merge or
    // reject legitimate distinct translations. There is neither, and this is
    // what keeps it that way.
    $source = (string) file_get_contents(app_path('Services/Translation/TranslationStore.php'));

    expect($source)->not->toContain("where('value'");

    $migration = (string) file_get_contents(
        base_path('database/migrations/2026_11_10_000000_create_translations_table.php')
    );

    // The only unique index is over the identifier columns.
    expect(substr_count($migration, '$t->unique('))->toBe(1)
        ->and($migration)->toContain("\$t->unique(['locale', 'group', 'item_id', 'field']");
});

it('proves the collation trap on the engine production actually runs', function () {
    /*
     * MEASURED, NOT ASSERTED FROM MEMORY.
     *
     * config/database.php configures utf8mb4 / utf8mb4_unicode_ci, and the
     * table is created with it — SHOW CREATE TABLE translations confirms every
     * text column carries it. Under that collation, on MySQL 8.0:
     *
     *     'كتاب'  =  'كِتاب'   (the same word with a kasra)      → TRUE
     *     'محمد'  =  'محمّد'   (the same name with a shadda)     → TRUE
     *     'Name'  =  'name'                                     → TRUE
     *     'name'  =  'name  '  (PAD SPACE)                       → TRUE
     *
     * So two Arabic strings a reader sees as different are, to a unique index
     * or a WHERE, the same string. That is the whole reason `value` is never
     * indexed, never unique and never compared with = — and the reason the KEY
     * columns are normalised in PHP instead, where the rule is one rule on both
     * engines.
     *
     * Skipped on SQLite, where BINARY is the only collation and the question
     * cannot be asked. It is asked on the MySQL run, which is the engine the
     * shop is served from.
     */
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped('The collation this pins only exists on MySQL.');
    }

    $row = DB::selectOne(
        "SELECT (_utf8mb4'كتاب' COLLATE utf8mb4_unicode_ci) = _utf8mb4'كِتاب' AS diacritic,"
        ." (_utf8mb4'Name' COLLATE utf8mb4_unicode_ci) = _utf8mb4'name' AS letter_case,"
        ." (_utf8mb4'كتاب' COLLATE utf8mb4_bin) = _utf8mb4'كِتاب' AS under_binary"
    );

    expect((int) $row->diacritic)->toBe(1, 'if this is 0 the collation changed; re-read why value is unindexed')
        ->and((int) $row->letter_case)->toBe(1)
        ->and((int) $row->under_binary)->toBe(0);

    // And the table really does carry that collation, rather than this test
    // asking a question about a collation the table does not use.
    $create = (array) DB::selectOne('SHOW CREATE TABLE translations');

    expect(implode(' ', $create))->toContain('utf8mb4_unicode_ci');
});

it('gives interface strings a real uniqueness, because item_id is 0 and not null', function () {
    // NULL is not equal to NULL, on MySQL and on SQLite alike, so a nullable
    // item_id would leave the UI strings — the half with the strongest reason
    // to be unique — with no uniqueness at all.
    TranslationStore::put('ar', Translation::GROUP_UI, 0, 'store.wishlist.title', 'أ', Translation::STATUS_PUBLISHED);
    TranslationStore::put('ar', Translation::GROUP_UI, 0, 'store.wishlist.title', 'ب', Translation::STATUS_PUBLISHED);

    expect(Translation::query()->count())->toBe(1)
        ->and(Translation::query()->first()->value)->toBe('ب');
});

/* ══════════════════ 7. the order's language ══════════════════ */

it('records the language the customer was shopping in, on the order', function () {
    epArabicOn();
    app()->setLocale('ar');

    $order = Order::create(['order_number' => 'EP-'.uniqid(), 'email' => 'a@example.com', 'total' => 100]);

    expect($order->locale)->toBe('ar');
});

it('does not overwrite a language the admin set deliberately', function () {
    app()->setLocale('en');

    // A phone order taken in Arabic and keyed in by an English-speaking admin.
    $order = Order::create([
        'order_number' => 'EP-'.uniqid(), 'email' => 'b@example.com', 'total' => 100, 'locale' => 'ar',
    ]);

    expect($order->locale)->toBe('ar');
});

it('renders a later email in the order language and puts the locale back afterwards', function () {
    epArabicOn();
    epArabic(Translation::GROUP_UI, 0, 'email.order_status.view_order', 'عرض طلبك');

    $order = Order::create([
        'order_number' => 'EP-'.uniqid(), 'email' => 'c@example.com', 'total' => 100, 'locale' => 'ar',
    ]);

    app()->setLocale('en');

    // The half that matters: this email is sent WEEKS later, from a process
    // with no memory of the request.
    $rendered = OrderLocale::render($order, fn (): string => __('email.order_status.view_order'));

    expect($rendered)->toBe('عرض طلبك')
        // And the worker is handed back the language it had.
        ->and(app()->getLocale())->toBe('en');
});

it('restores the locale even when the render throws', function () {
    $order = Order::create([
        'order_number' => 'EP-'.uniqid(), 'email' => 'd@example.com', 'total' => 100, 'locale' => 'ar',
    ]);

    app()->setLocale('en');

    try {
        OrderLocale::render($order, function (): void {
            throw new RuntimeException('transport failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    // Without the finally, one failed email would leave a queue worker set to
    // Arabic for every job after it.
    expect(app()->getLocale())->toBe('en');
});

/* ══════════════════ 8. machine translation: cost, consent, drafts ══════════════════ */

it('works completely with no API key, because the manual path must be free', function () {
    $provider = app(TranslationProvider::class);

    expect($provider)->toBeInstanceOf(NullProvider::class)
        ->and($provider->available())->toBeFalse();

    // And the manual path is entirely unaffected by that.
    $brand = Brand::create(['name' => 'Free', 'slug' => 'ep-free-'.uniqid()]);
    $brand->saveTranslations(['ar' => ['name' => 'مجاني']]);

    expect($brand->t('name', 'ar'))->toBe('مجاني');
});

it('counts characters and quotes a price before anything is spent', function () {
    Product::create([
        'name' => 'Estimate Me', 'slug' => 'ep-est-'.uniqid(), 'sku' => 'EP-EST',
        'price' => 100, 'status' => 'publish', 'is_visible' => true,
        'short_description' => 'Twenty characters ok',
    ]);

    $estimate = TranslationEstimate::forLocale('ar');

    expect($estimate['characters'])->toBeGreaterThan(0)
        ->and($estimate['free_tier'])->toBe(500_000)
        ->and($estimate)->toHaveKey('usd')
        ->and($estimate)->toHaveKey('months_at_free_tier');

    // Nothing here reaches the network: this endpoint is free to run and can be
    // run as often as he likes before he decides.
});

it('excludes work already done from the estimate', function () {
    $product = Product::create([
        'name' => 'Already Translated', 'slug' => 'ep-done-'.uniqid(), 'sku' => 'EP-DONE',
        'price' => 100, 'status' => 'publish', 'is_visible' => true,
    ]);

    $before = TranslationEstimate::forLocale('ar')['characters'];

    epArabic('products', $product->id, 'name', 'مترجم بالفعل');

    // Re-sending text that already has a translation is money spent to
    // overwrite work — and on a shop translated in monthly batches it would be
    // most of the bill.
    expect(TranslationEstimate::forLocale('ar')['characters'])
        ->toBe($before - mb_strlen('Already Translated'));
});

it('counts progress per area, and counts it rather than claiming it', function () {
    Product::create([
        'name' => 'Progress A', 'slug' => 'ep-pa-'.uniqid(), 'sku' => 'EP-PA',
        'price' => 100, 'status' => 'publish', 'is_visible' => true,
    ]);

    $progress = TranslationEstimate::progress('ar');

    expect($progress['areas'])->toHaveKey('products')
        ->and($progress['areas'])->toHaveKey('categories')
        ->and($progress['areas'])->toHaveKey('brands')
        ->and($progress['areas'])->toHaveKey('pages')
        ->and($progress['areas'])->toHaveKey('posts')
        ->and($progress['areas'])->toHaveKey('menu_items')
        ->and($progress['areas'])->toHaveKey('ui')
        ->and($progress['areas']['ui']['total'])->toBe(count(InterfaceStrings::flat()));
});

it('never sends markup to a machine', function () {
    // Every format option a translation API offers mangles either the markup or
    // the words. A mangled description is worse than an English one, and it
    // costs money to produce.
    expect(MachineTranslationRunner::isMachineSafe('<p>Hello</p>'))->toBeFalse()
        ->and(MachineTranslationRunner::isMachineSafe('2024'))->toBeFalse()
        ->and(MachineTranslationRunner::isMachineSafe('   '))->toBeFalse()
        ->and(MachineTranslationRunner::isMachineSafe('A gentle daily toner.'))->toBeTrue();
});

it('lands machine output as a draft that no shopper can see', function () {
    epArabicOn();

    $fake = new class implements TranslationProvider
    {
        public function name(): string
        {
            return 'Fake';
        }

        public function available(): bool
        {
            return true;
        }

        public function translate(array $texts, string $from, string $to): array
        {
            // No network. The suite must never cost money or need a key.
            return array_map(static fn (string $t): string => 'AR::'.$t, $texts);
        }
    };

    $result = (new MachineTranslationRunner($fake))->run('ar', 5, Translation::GROUP_UI);

    expect($result['translated'])->toBeGreaterThan(0);

    $rows = Translation::query()->where('locale', 'ar')->get();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row->status)->toBe(Translation::STATUS_DRAFT)
            ->and($row->source)->toBe(Translation::SOURCE_MACHINE);
    }

    // And the storefront cannot see any of it.
    expect(TranslationStore::map('ar'))->toBe([]);
});

it('never re-translates something that already has a translation', function () {
    epArabic(Translation::GROUP_UI, 0, 'store.wishlist.title', 'قائمة الرغبات');

    $pending = (new MachineTranslationRunner(new NullProvider))->pending('ar', 500, Translation::GROUP_UI);

    expect(collect($pending)->pluck('field'))->not->toContain('store.wishlist.title');
});

it('keeps the owner API key out of the public settings endpoint', function () {
    // CLAUDE.md: /api/* is unauthenticated and this endpoint has leaked three
    // times. A leaked key here is somebody else's translation bill on the
    // owner's card.
    TranslationCredentials::saveApiKey('super-secret-key');

    $body = (string) test()->get('/api/settings')->getContent();

    expect($body)->not->toContain('super-secret-key')
        ->and($body)->not->toContain(TranslationCredentials::SETTING_KEY);
});

it('stores the API key encrypted, so a database dump does not carry it', function () {
    TranslationCredentials::saveApiKey('super-secret-key');

    $stored = (string) Setting::query()->find(TranslationCredentials::SETTING_KEY)->value;

    expect($stored)->not->toContain('super-secret-key')
        ->and(TranslationCredentials::apiKey())->toBe('super-secret-key');
});

it('binds the real provider only once a key is saved', function () {
    expect(app(TranslationProvider::class))->toBeInstanceOf(NullProvider::class);

    TranslationCredentials::saveApiKey('a-key');
    app()->forgetScopedInstances();

    expect(app(TranslationProvider::class))->toBeInstanceOf(GoogleProvider::class)
        ->and(app(TranslationProvider::class)->available())->toBeTrue();
});

/* ══════════════════ 9. the admin API ══════════════════ */

function epAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'EP Owner', 'email' => 'ep-'.uniqid().'@example.com',
        'password' => bcrypt('secret'), 'role' => 'owner',
    ]);
}

it('refuses every translation endpoint to a signed-out caller', function () {
    // /translations/machine/run SPENDS MONEY and /translations/settings WRITES
    // THE API KEY. The admin guard is the only thing between those and the
    // internet.
    \Tests\Support\TranslationAdminRoutes::mount();

    test()->get('/admin-api/translations/settings')->assertStatus(302);
    test()->post('/admin-api/translations/machine/run', [
        'locale' => 'ar', 'confirm_characters' => 0,
    ])->assertStatus(302);
});

it('reports both switches, and says plainly when the combination is awkward', function () {
    \Tests\Support\TranslationAdminRoutes::mount();

    epArabicOn(rtl: false);

    $body = test()->actingAs(epAdmin(), 'admin')
        ->getJson('/admin-api/translations/settings')->json();

    expect($body['arabic_enabled'])->toBeTrue()
        ->and($body['rtl_enabled'])->toBeFalse()
        ->and($body['has_api_key'])->toBeFalse()
        ->and($body['provider_available'])->toBeFalse()
        // Reported, never prevented: he asked for the control.
        ->and($body['warning'])->toContain('left-to-right');
});

it('turns Arabic on and off from the screen, with no release', function () {
    \Tests\Support\TranslationAdminRoutes::mount();

    test()->actingAs(epAdmin(), 'admin')
        ->postJson('/admin-api/translations/settings', ['arabic_enabled' => true, 'rtl_enabled' => true])
        ->assertOk();

    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    Setting::flushMap();

    expect(Locale::enabled('ar'))->toBeTrue();

    test()->actingAs(epAdmin(), 'admin')
        ->postJson('/admin-api/translations/settings', ['arabic_enabled' => false])
        ->assertOk();

    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    Setting::flushMap();

    // The route table never knew about /ar, so switching it off needs no cache
    // clear and therefore no package.
    expect(Locale::enabled('ar'))->toBeFalse();
    test()->get('/ar/my-wishlist/')->assertNotFound();
});

it('never returns the saved API key, only whether there is one', function () {
    \Tests\Support\TranslationAdminRoutes::mount();

    TranslationCredentials::saveApiKey('key-abc-123');

    $response = test()->actingAs(epAdmin(), 'admin')->get('/admin-api/translations/settings');

    expect((string) $response->getContent())->not->toContain('key-abc-123')
        ->and($response->json('has_api_key'))->toBeTrue();
});

it('writes one string from the standalone screen, and clears it with a blank', function () {
    \Tests\Support\TranslationAdminRoutes::mount();

    $admin = epAdmin();

    test()->actingAs($admin, 'admin')->postJson('/admin-api/translations', [
        'locale' => 'ar', 'group' => 'ui', 'item_id' => 0,
        'field' => 'store.wishlist.title', 'value' => 'قائمة الرغبات',
    ])->assertOk();

    expect(TranslationStore::get('ar', 'ui', 0, 'store.wishlist.title'))->toBe('قائمة الرغبات');

    test()->actingAs($admin, 'admin')->postJson('/admin-api/translations', [
        'locale' => 'ar', 'group' => 'ui', 'item_id' => 0,
        'field' => 'store.wishlist.title', 'value' => '',
    ])->assertOk();

    expect(Translation::query()->count())->toBe(0);
});

it('refuses to store a translation of an English string that does not exist', function () {
    \Tests\Support\TranslationAdminRoutes::mount();

    // Without this guard the endpoint is an unbounded admin-writable key-value
    // store that the storefront reads and renders.
    test()->actingAs(epAdmin(), 'admin')->postJson('/admin-api/translations', [
        'locale' => 'ar', 'group' => 'ui', 'item_id' => 0,
        'field' => 'made.up.key', 'value' => 'أي شيء',
    ])->assertStatus(422);

    expect(Translation::query()->count())->toBe(0);
});

it('approves a draft, and only then does a shopper see it', function () {
    \Tests\Support\TranslationAdminRoutes::mount();
    epArabicOn();

    epArabic(Translation::GROUP_UI, 0, 'store.wishlist.empty_title', 'مسودة آلية', Translation::STATUS_DRAFT);

    expect(test()->get('/ar/my-wishlist/')->getContent())->not->toContain('مسودة آلية');

    test()->actingAs(epAdmin(), 'admin')
        ->postJson('/admin-api/translations/publish', ['locale' => 'ar'])
        ->assertOk()
        ->assertJsonPath('published', 1);

    expect(test()->get('/ar/my-wishlist/')->getContent())->toContain('مسودة آلية');
});

it('refuses to spend money on a stale estimate', function () {
    \Tests\Support\TranslationAdminRoutes::mount();

    TranslationCredentials::saveApiKey('a-key');
    app()->forgetScopedInstances();

    // A tab that opened the estimate an hour ago must not be able to authorise
    // today's much larger run. "I saw the number before I pressed it" has to be
    // true rather than claimed.
    test()->actingAs(epAdmin(), 'admin')->postJson('/admin-api/translations/machine/run', [
        'locale' => 'ar', 'limit' => 50, 'group' => 'ui', 'confirm_characters' => 999_999,
    ])->assertStatus(409);

    expect(Translation::query()->count())->toBe(0);
});

it('tells the owner how to proceed for free when no provider is connected', function () {
    \Tests\Support\TranslationAdminRoutes::mount();

    $response = test()->actingAs(epAdmin(), 'admin')->postJson('/admin-api/translations/machine/field', [
        'text' => 'Add to bag', 'locale' => 'ar',
    ]);

    $response->assertStatus(409);

    expect($response->json('message'))->toContain('by hand');
});

/* ══════════════════ 10. the model allowlists agree with each other ══════════════════ */

it('keeps every model allowlist and the estimate in step', function () {
    // Two lists of translatable columns is two lists to get out of step, and
    // the estimate silently counting zero work for a renamed column is the kind
    // of defect nobody notices until the progress bar is wrong.
    foreach (TranslationEstimate::CONTENT as $class => $columns) {
        $model = new $class;

        expect($model->translatable())->toBe($columns, $class.' disagrees with TranslationEstimate::CONTENT');

        foreach ($columns as $column) {
            expect(\Illuminate\Support\Facades\Schema::hasColumn($model->getTable(), $column))
                ->toBeTrue($class.' has no column '.$column);
        }
    }
});

it('never lets an identifier onto a translatable list', function () {
    // SKUs, coupon codes, order numbers, currency codes and slugs. A translated
    // SKU is a SKU nobody can look up; a translated slug doubles the URL surface
    // and breaks the redirect map.
    foreach (TranslationEstimate::CONTENT as $class => $columns) {
        foreach (['sku', 'slug', 'code', 'order_number', 'currency', 'price', 'url'] as $forbidden) {
            /*
             * in_array() inside toBeFalse(), not ->not->toContain($needle,
             * $message): toContain() is VARIADIC, so the message was a second
             * needle and `not` passed because no column list contains the
             * sentence. Measured with each forbidden name pushed onto
             * $columns -- the old form stayed green.
             */
            expect(in_array($forbidden, (array) $columns, true))
                ->toBeFalse($class.' would translate '.$forbidden);
        }
    }
});

it('registers its process-level memo so the suite cannot depend on test order', function () {
    expect(\Tests\Support\StaticMemos::resets())->toHaveKey(TranslationStore::class);
});

it('decorates the framework loader rather than replacing it', function () {
    expect(app('translation.loader'))->toBeInstanceOf(DatabaseTranslationLoader::class);
});
