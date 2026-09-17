<?php

declare(strict_types=1);

/*
 * THE STOREFRONT READS A CONTENT TRANSLATION (Lane FP), AND THE MAP IS NO
 * LONGER CARRYING THE OWNER'S PROSE TO DO IT.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────────
 *
 * Lane FN proved, with a sentinel rather than a grep, that NOTHING on the
 * storefront read a content translation. With the whole catalogue translated
 * and Arabic switched on, /ar/shop rendered "Hydrating Serum No. 360" and ZERO
 * occurrences of that product's Arabic name. Every Arabic word on an Arabic
 * page was an interface string coming through __(), and the owner's ~55 hours
 * of catalogue typing would have landed in a table no page reads.
 *
 * So the assertions here are RENDERED BYTES OUT OF A REAL HTTP REQUEST, in both
 * directions, for the same reason FN's finding was a measurement and not a
 * grep: a template can call t() and still print the column two lines down, and
 * `grep t(` cannot tell the difference. A regex over a Blade file reads that
 * file's own comments as code; this file reads the page.
 *
 * ── THE SENTINEL, AND WHY IT IS SPELLED LIKE THAT ───────────────────────────
 *
 * ZZSENTINEL… strings are ASCII and are impossible in real copy, so an
 * occurrence in the HTML is this test's row and nothing else — a genuinely
 * Arabic fixture would be indistinguishable from the Arabic INTERFACE strings
 * that are already all over an /ar page, which is exactly the confusion that
 * let "the Arabic page is in Arabic" stand in for "the Arabic page reads the
 * catalogue" for a whole phase.
 *
 * ── AND WHY NOT expect(...)->not->toContain($needle, $message) ──────────────
 *
 * Pest's toContain() is VARIADIC. A "message" passed as a second argument
 * becomes a second NEEDLE, and `not->toContain(a, b)` passes as soon as either
 * is absent — so eight guards on this project have been caught asserting
 * nothing. Every negative below is expect(str_contains(...))->toBeFalse($why).
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Translation;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const FP_NAME_EN = 'Hydrating Serum No. 360';
const FP_NAME_AR = 'ZZSENTINELNAME';
const FP_BLURB_EN = 'A gentle daily serum for tired skin.';
const FP_BLURB_AR = 'ZZSENTINELBLURB';
const FP_DESC_EN = '<p>The long English description nobody translated yet.</p>';
const FP_DESC_AR = '<p>ZZSENTINELDESC</p>';
const FP_INCI_AR = '<p>ZZSENTINELINCI</p>';
const FP_HOWTO_AR = '<p>ZZSENTINELHOWTO</p>';

function fpArabicOn(): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

/** One visible product with every translatable field filled in English. */
function fpProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'name' => FP_NAME_EN,
        'slug' => 'fp-serum-' . Str::random(8),
        'sku' => 'FP-' . Str::random(4),
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'short_description' => FP_BLURB_EN,
        'description' => FP_DESC_EN,
        'ingredients' => '<p>Water, Glycerin, Houttuynia Cordata Extract.</p>',
        'how_to_use' => '<p>Sweep over the face with a cotton pad.</p>',
    ], $overrides));
}

function fpPublish(string $group, int $id, string $field, string $value): void
{
    TranslationStore::put('ar', $group, $id, $field, $value, Translation::STATUS_PUBLISHED, Translation::SOURCE_MANUAL);
}

/** Count the queries one request runs, the way StorefrontQueryBudgetTest does. */
function fpQueries(string $path): int
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    /*
     * AND THE TRANSLATION STORE'S PROCESS-LEVEL MEMOS, for the reason
     * StorefrontQueryBudgetTest's header gives about the other two: under
     * PHP-FPM a request is a process, so every static starts empty and the
     * CACHE — which is shared, and which forgetMemo() deliberately leaves
     * alone — starts warm. A test process keeps the statics, so a second
     * render measures a state no visitor is ever in.
     *
     * This line is why the guard below is worth reading. Without it, an N+1
     * deliberately added to the product card measured 6 queries against 6 and
     * 24 against 24 — because the first pass had already filled the long-prose
     * memo for every row, and the measured pass answered from it. The test
     * passed against code that runs one query per card on every real request.
     */
    TranslationStore::forgetMemo();

    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->get($path)->assertSuccessful();

    $n = count(DB::getQueryLog());

    DB::disableQueryLog();
    DB::flushQueryLog();

    return $n;
}

/* ══════════════ 1. the render — the finding this lane acts on ══════════════ */

it('prints the Arabic product name on /ar/shop, where the English one used to be', function () {
    fpArabicOn();

    $product = fpProduct();
    fpPublish('products', $product->id, 'name', FP_NAME_AR);

    TranslationStore::flush();

    $arabic = (string) test()->get('/ar/shop/')->assertOk()->getContent();

    // FN's measurement, run again and now the other way round: the sentinel is
    // on the page and the English name is not.
    expect(substr_count($arabic, FP_NAME_AR))->toBeGreaterThan(0)
        ->and(str_contains($arabic, FP_NAME_EN))->toBeFalse(
            'The Arabic shop still prints the English product name, which is the '
            . 'defect Lane FN measured: the card reads the column instead of t().'
        );
});

it('prints the Arabic name, blurb and all three tab bodies on /ar/product', function () {
    fpArabicOn();

    $product = fpProduct();

    foreach ([
        'name' => FP_NAME_AR,
        'short_description' => FP_BLURB_AR,
        'description' => FP_DESC_AR,
        'ingredients' => FP_INCI_AR,
        'how_to_use' => FP_HOWTO_AR,
    ] as $field => $value) {
        fpPublish('products', $product->id, $field, $value);
    }

    TranslationStore::flush();

    $html = (string) test()->get('/ar/product/' . $product->slug . '/')->assertOk()->getContent();

    // The <h1>, the blurb, and the three tabs Store\ProductController::tabs()
    // builds — which is where `ingredients` and `how_to_use` land, the two
    // columns Lane FN put on the allowlist.
    expect($html)->toContain(FP_NAME_AR)
        ->toContain(FP_BLURB_AR)
        ->toContain('ZZSENTINELDESC')
        ->toContain('ZZSENTINELINCI')
        ->toContain('ZZSENTINELHOWTO');

    // Including the <title>, which is what an Arabic search result shows.
    expect(str_contains($html, FP_NAME_EN))->toBeFalse(
        'The Arabic product page still carries the English name somewhere — '
        . 'check the <title>, the breadcrumb, the sticky bar and the JSON-LD.'
    );
});

it('falls back to English per field, and never renders a blank where a name goes', function () {
    /*
     * THE CONTRACT THE PROGRESS COUNTER DEPENDS ON. A blank box deletes the row
     * (see HasTranslations::saveTranslations), so "untranslated" is "no row" —
     * and the page has to read that as English, not as an empty string. A card
     * with an empty <a class="cname"> would be a shop nobody can navigate, and
     * the owner would be halfway through his 55 hours when he found out.
     */
    fpArabicOn();

    $product = fpProduct();

    // The name only. The blurb and the description are deliberately left with
    // no Arabic row at all.
    fpPublish('products', $product->id, 'name', FP_NAME_AR);

    TranslationStore::flush();

    $html = (string) test()->get('/ar/product/' . $product->slug . '/')->assertOk()->getContent();

    expect($html)->toContain(FP_NAME_AR)
        // Per FIELD, not per row: the untranslated blurb and description are
        // the English ones, in full.
        ->toContain(FP_BLURB_EN)
        ->toContain('The long English description nobody translated yet.');

    // And the heading is not empty. Asserted on the markup rather than by
    // eyeballing the absence of the English: an empty <h1> contains every
    // string you care to look for the absence of.
    expect($html)->toContain('<h1 class="bb-title" id="bbTitle">' . FP_NAME_AR . '</h1>');
});

it('leaves the English page exactly as it was, with the catalogue fully translated', function () {
    /*
     * The reverse of the sentinel, and the half that makes the first half safe.
     * Arabic is ON and every field is translated; the unprefixed page must not
     * have heard about any of it.
     */
    fpArabicOn();

    $product = fpProduct();

    foreach ([
        'name' => FP_NAME_AR,
        'short_description' => FP_BLURB_AR,
        'description' => FP_DESC_AR,
        'ingredients' => FP_INCI_AR,
        'how_to_use' => FP_HOWTO_AR,
    ] as $field => $value) {
        fpPublish('products', $product->id, $field, $value);
    }

    TranslationStore::flush();

    foreach (['/shop/', '/product/' . $product->slug . '/'] as $path) {
        $html = (string) test()->get($path)->assertOk()->getContent();

        expect($html)->toContain(FP_NAME_EN);

        foreach (['ZZSENTINELNAME', 'ZZSENTINELBLURB', 'ZZSENTINELDESC', 'ZZSENTINELINCI', 'ZZSENTINELHOWTO'] as $needle) {
            expect(str_contains($html, $needle))->toBeFalse(
                "The English page {$path} printed the Arabic {$needle}. t() must return "
                . 'the column unchanged for the default locale.'
            );
        }
    }
});

it('translates a category, a brand, a page, an article and a menu label too', function () {
    /*
     * Lane FN named five call sites. They were not the whole list — a confident
     * report is a hypothesis, and this project has a risk-register entry for
     * exactly that. Every model with a $translatable allowlist gets a rendered
     * assertion here, because a model that is translatable and unread is the
     * same defect in a quieter place.
     */
    fpArabicOn();

    $category = Category::create(['name' => 'Toners', 'slug' => 'fp-toners', 'description' => 'English category standfirst.']);
    $brand = Brand::create(['name' => 'Anua', 'slug' => 'fp-anua', 'description' => 'English brand standfirst.']);
    // `about` rather than a slug of this test's own: content pages are served
    // by FIXED routes (routes/web.php keeps them last so a page cannot shadow a
    // functional path), so an invented slug is a 404 and would prove nothing.
    $page = Page::updateOrCreate(
        ['slug' => 'about'],
        ['title' => 'About Us', 'content' => '<p>English policy body.</p>', 'status' => 'published'],
    );
    $post = Post::create([
        'slug' => 'fp-article', 'title' => 'English article title', 'excerpt' => 'English teaser.',
        'body' => '<p>English article body.</p>', 'status' => 'published', 'published_at' => now()->subDay(),
    ]);

    // The migration set seeds a menu that already holds the desktop slot, and
    // NavigationService::menu() takes the FIRST menu with the column set — so
    // without this the tree under test is never the one built below.
    Menu::query()->update(['show_desktop' => false, 'show_mobile' => false, 'show_footer' => false]);

    $menu = Menu::create([
        'name' => 'FP primary',
        'slug' => 'fp-primary-' . Str::random(6),
        'show_desktop' => true,
        'show_mobile' => true,
    ]);
    $item = MenuItem::create(['menu_id' => $menu->id, 'label' => 'English menu label', 'url' => '/shop/', 'position' => 0]);

    fpPublish('categories', $category->id, 'name', 'ZZSENTINELCAT');
    fpPublish('categories', $category->id, 'description', 'ZZSENTINELCATDESC');
    fpPublish('brands', $brand->id, 'name', 'ZZSENTINELBRAND');
    fpPublish('brands', $brand->id, 'description', 'ZZSENTINELBRANDDESC');
    fpPublish('pages', $page->id, 'title', 'ZZSENTINELPAGE');
    fpPublish('pages', $page->id, 'content', '<p>ZZSENTINELPAGEBODY</p>');
    fpPublish('posts', $post->id, 'title', 'ZZSENTINELPOST');
    fpPublish('posts', $post->id, 'body', '<p>ZZSENTINELPOSTBODY</p>');
    fpPublish('menu_items', $item->id, 'label', 'ZZSENTINELMENU');

    TranslationStore::flush();
    app('cache')->forget('kbb.nav.primary');

    $expectations = [
        '/ar/product-category/fp-toners/' => ['ZZSENTINELCAT', 'ZZSENTINELCATDESC'],
        '/ar/korean-skincare-brands/fp-anua/' => ['ZZSENTINELBRAND', 'ZZSENTINELBRANDDESC'],
        '/ar/about/' => ['ZZSENTINELPAGE', 'ZZSENTINELPAGEBODY'],
        '/ar/fp-article/' => ['ZZSENTINELPOST', 'ZZSENTINELPOSTBODY'],
    ];

    foreach ($expectations as $path => $needles) {
        $html = (string) test()->get($path)->assertOk()->getContent();

        foreach ($needles as $needle) {
            expect($html)->toContain($needle);
        }
    }

    /*
     * The menu label is asserted on the pages that draw the SHARED header, and
     * the Journal is deliberately not one of them.
     *
     * store/post.blade.php and store/blog.blade.php open with
     * `@verbatim<!DOCTYPE html><html lang="en">` and carry their own hard-coded
     * nav. They do not extend layouts.store, so they have no shared header to
     * translate, no dir="rtl" and no hreflang — the article's WORDS reach
     * Arabic (asserted above) and its document does not. That is a real gap and
     * it is named in this lane's report rather than fixed here: rebuilding two
     * standalone documents onto the shared layout is a change to the RTL and
     * SEO lanes' surface, not a line in this diff.
     */
    foreach (['/ar/product-category/fp-toners/', '/ar/korean-skincare-brands/fp-anua/', '/ar/about/'] as $path) {
        expect((string) test()->get($path)->getContent())->toContain('ZZSENTINELMENU');
    }

    expect((string) test()->get('/ar/fp-article/')->getContent())->toContain('<html lang="en">');

    /*
     * The journal INDEX, which reads its tiles from a narrowed select. `id` had
     * to be added to it: a translation is looked up by (group, item_id, field),
     * so a row loaded without its key cannot find its own Arabic and prints the
     * English for ever on a page that is translated everywhere else. It is the
     * quietest way for this whole lane to be wrong, because nothing errors.
     */
    $index = (string) test()->get('/ar/skincare-guide/')->assertOk()->getContent();

    expect($index)->toContain('ZZSENTINELPOST')
        ->and(str_contains($index, 'English article title'))->toBeFalse(
            'The Arabic journal index printed the English title — check that the '
            . 'select in PageController::blog() still carries `id`.'
        );

    // And the English side of all five is untouched.
    $english = (string) test()->get('/about/')->assertOk()->getContent();

    expect($english)->toContain('About Us')
        ->and(str_contains($english, 'ZZSENTINELPAGE'))->toBeFalse('The English page read the Arabic title.')
        ->and(str_contains($english, 'ZZSENTINELMENU'))->toBeFalse(
            'The English header read the Arabic menu label. NavigationService caches ONE '
            . 'tree for five minutes across every visitor, so a label translated inside '
            . 'that cached closure is one visitor\'s language served to everybody.'
        );
});

it('does not bake one visitor\'s language into the menu cache for the next five minutes', function () {
    /*
     * The trap tree()'s own comment records about `visibility`, one field
     * along. The cached nav tree is shared; the swap happens per request.
     * Ordering matters here — Arabic FIRST, so the cache is warmed by an
     * Arabic visitor and the English request that follows has to be the one
     * that puts it right.
     */
    fpArabicOn();

    // The migration set seeds a menu that already holds the desktop slot, and
    // NavigationService::menu() takes the FIRST menu with the column set — so
    // without this the tree under test is never the one built below.
    Menu::query()->update(['show_desktop' => false, 'show_mobile' => false, 'show_footer' => false]);

    $menu = Menu::create([
        'name' => 'FP primary',
        'slug' => 'fp-primary-' . Str::random(6),
        'show_desktop' => true,
        'show_mobile' => true,
    ]);
    $item = MenuItem::create(['menu_id' => $menu->id, 'label' => 'English menu label', 'url' => '/shop/', 'position' => 0]);

    fpPublish('menu_items', $item->id, 'label', 'ZZSENTINELMENU');

    TranslationStore::flush();
    app('cache')->forget('kbb.nav.primary');

    expect(test()->get('/ar/my-wishlist/')->getContent())->toContain('ZZSENTINELMENU');

    $english = (string) test()->get('/my-wishlist/')->getContent();

    expect($english)->toContain('English menu label')
        ->and(str_contains($english, 'ZZSENTINELMENU'))->toBeFalse(
            'The English header served the Arabic label out of the shared five-minute nav cache.'
        );
});

it('renders an Arabic page with demo content on, where a stand-in has no row to translate', function () {
    /*
     * App\Services\DemoContent::fill() concatenates ANONYMOUS FIXTURE OBJECTS
     * onto a collection of real models, and the home rails render both from one
     * expression. The moment those templates started reading catalogue text
     * through t(), a stand-in that answers only ->name became a fatal
     * "Call to undefined method" on the home page — which is the exact failure
     * the fixture's own `cover`/`body` comment already records, one method
     * along. Pinned with the switch ON, because a preview aid that 500s the
     * shop is the defect and not the mitigation.
     */
    fpArabicOn();

    /*
     * THE CATALOGUE IS EMPTIED FIRST, and without that this test asserts
     * nothing. DemoContent::fill() substitutes only when the REAL collection is
     * short of what the rail wants, and the migration set seeds 24 products,
     * 20 categories and 93 brands — so with them in place the switch is on, the
     * fixtures are built, and not one of them reaches a template. Proven by
     * mutation: with the seed present, deleting t() from a stand-in left this
     * test green.
     */
    DB::table('products')->delete();
    DB::table('categories')->delete();
    DB::table('brands')->delete();
    DB::table('posts')->delete();

    Setting::query()->updateOrCreate(['key' => 'demo_content'], ['value' => '1', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();

    foreach (['kbb.home.cats', 'kbb.home.posts', 'kbb.home.reviews'] as $key) {
        app('cache')->forget($key);
    }

    $english = (string) test()->get('/')->assertOk()->getContent();
    $arabic = (string) test()->get('/ar/')->assertOk()->getContent();

    // Both pages really did draw the fixtures, rather than 200ing on an empty
    // shelf — otherwise a stand-in with no t() would never have been asked.
    expect($english)->toContain('Beauty of Joseon')
        ->and($arabic)->toContain('Beauty of Joseon');
});

/* ══════════════ 2. the map split ══════════════ */

it('keeps the five long-prose columns out of the map every Arabic page loads', function () {
    /*
     * MEASURED BY LANE FN AT THE OWNER'S REAL VOLUME, on MySQL at 696 products
     * with the catalogue fully translated (docs/fn-translation-at-scale.md):
     *
     *     map('ar') with the long prose:     4,467 entries, +4.74 MB resident
     *     map('ar') without it:              2,446 entries,  0.17 MB
     *
     * Nineteen times smaller, on EVERY Arabic request — including /ar/cart,
     * which prints no prose at all and was paying the whole 4.74 MB because
     * __() loads the map to answer for an interface string.
     *
     * A fixture cannot assert megabytes. What it CAN assert, and what actually
     * decides the megabytes, is which slots are in the array.
     */
    fpArabicOn();

    $product = fpProduct();

    foreach (['name', 'short_description', 'description', 'ingredients', 'how_to_use'] as $field) {
        fpPublish('products', $product->id, $field, 'AR ' . $field);
    }

    TranslationStore::flush();

    $map = TranslationStore::map('ar');

    // The short ones are in it: they are read on every grid and an array lookup
    // is what keeps a page of cards off an N+1.
    expect($map)->toHaveKey('products.' . $product->id . '.name')
        ->toHaveKey('products.' . $product->id . '.short_description');

    foreach (TranslationStore::LONG_FIELDS as $long) {
        expect(array_key_exists('products.' . $product->id . '.' . $long, $map))->toBeFalse(
            "map('ar') is still carrying `{$long}`. That column is 97% of the bytes "
            . 'and is read on at most one page at a time.'
        );
    }

    // Not lost — read the other way, by the page that prints them.
    $long = TranslationStore::longFor('ar', 'products', [$product->id]);

    expect($long[$product->id]['description'])->toBe('AR description')
        ->and($long[$product->id]['ingredients'])->toBe('AR ingredients')
        ->and($long[$product->id]['how_to_use'])->toBe('AR how_to_use');
});

it('reads a page of long prose in one query, not one per row', function () {
    fpArabicOn();

    $ids = [];

    for ($i = 0; $i < 25; $i++) {
        $p = fpProduct(['slug' => 'fp-long-' . $i, 'sku' => 'FP-L' . $i]);
        fpPublish('products', $p->id, 'description', '<p>AR ' . $i . '</p>');
        $ids[] = $p->id;
    }

    TranslationStore::flush();
    app()->setLocale('ar');

    // Warm the map, exactly as __() does before any template runs.
    TranslationStore::map('ar');

    $products = Product::query()->whereIn('id', $ids)->orderBy('id')->get();

    DB::flushQueryLog();
    DB::enableQueryLog();

    Product::primeTranslations($products);

    $primed = count(DB::getQueryLog());

    foreach ($products as $product) {
        expect($product->t('description'))->toStartWith('<p>AR ');
    }

    $afterReads = count(DB::getQueryLog());

    DB::disableQueryLog();

    // ONE query for the whole page — FN measured this shape at 53 rows,
    // 0.9–1.1 ms and 0.01 MB for a page of 25 — and then twenty-five reads
    // that cost nothing, which is the claim that matters.
    expect($primed)->toBe(1)
        ->and($afterReads)->toBe(1);
});

it('does not fetch long prose for a page that only prints a name', function () {
    /*
     * The other half of the split, and the one an eager load bolted onto every
     * query would have got wrong. A grid asks for `name`; `name` is in the map;
     * nothing about a description may be touched.
     */
    fpArabicOn();

    $product = fpProduct();
    fpPublish('products', $product->id, 'name', FP_NAME_AR);
    fpPublish('products', $product->id, 'description', FP_DESC_AR);

    TranslationStore::flush();
    app()->setLocale('ar');

    TranslationStore::map('ar');

    $fresh = Product::query()->whereKey($product->id)->first();

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($fresh->t('name'))->toBe(FP_NAME_AR);

    $n = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($n)->toBe(0, 'Reading a short field must stay an array lookup against the cached map.');
});

it('costs no query per card on an Arabic grid, whatever the catalogue holds', function () {
    /*
     * FLATNESS, not a budget — the distinction StorefrontQueryBudgetTest's
     * header makes, applied to the language that file does not measure. A page
     * running one query per card passes any ceiling you like on a small enough
     * fixture. A count that does not move when the catalogue quadruples is the
     * only evidence that it batches.
     *
     * This is the N+1 the master plan warned about, asked on the side where it
     * could actually happen now: /shop never reads a long field, so the split
     * must cost it nothing at all.
     */
    fpArabicOn();

    /*
     * THE MIGRATION SET SEEDS 24 DEMO PRODUCTS AND /shop PAGES AT 24, so a
     * fixture added on top of it does not change the number of CARDS on page
     * one at all — the page is already full. Measured directly while writing
     * this: six extra products and thirty extra products both rendered exactly
     * 24 cards and exactly the same query count, and the guard passed against
     * a card that had been deliberately given a per-row query. A flatness test
     * that cannot see the catalogue grow is a flatness test that asserts
     * nothing, which is this file's whole subject.
     *
     * So the catalogue is emptied first and the two measurements are 6 cards
     * against 24 — a fourfold difference, both inside one page.
     */
    DB::table('products')->delete();

    $brand = Brand::create(['name' => 'Anua', 'slug' => 'fp-flat-anua']);

    $seed = function (int $from, int $to) use ($brand): void {
        for ($i = $from; $i < $to; $i++) {
            $p = fpProduct(['slug' => 'fp-flat-' . $i, 'sku' => 'FP-F' . $i, 'brand_id' => $brand->id]);
            fpPublish('products', $p->id, 'name', 'ZZFLAT' . $i);
            fpPublish('products', $p->id, 'description', '<p>ZZFLATDESC' . $i . '</p>');
        }
    };

    $cards = static function (): int {
        return substr_count((string) test()->get('/ar/shop/')->getContent(), 'class="cname"');
    };

    $seed(0, 6);
    TranslationStore::flush();

    // A warm-up pass, for the process-level memos StorefrontQueryBudgetTest's
    // header explains; then the real measurement.
    fpQueries('/ar/shop/');
    $small = fpQueries('/ar/shop/');
    $smallCards = $cards();

    $seed(6, 24);
    TranslationStore::flush();

    fpQueries('/ar/shop/');
    $large = fpQueries('/ar/shop/');
    $largeCards = $cards();

    // The measurement is only worth reading if the page really did grow.
    expect($smallCards)->toBe(6)->and($largeCards)->toBe(24);

    expect($large)->toBe(
        $small,
        "The Arabic shop ran {$small} queries for 6 cards and {$large} for 24. "
        . 'That is a query per card: something on the grid is reading a field '
        . 'that is not in the cached map.'
    );
});

it('adds no query to an English page, with the whole catalogue translated', function () {
    /*
     * The budgets in StorefrontQueryBudgetTest and PageCostBudgetTest are all
     * English, and none of them was raised for this lane. This asserts the
     * reason directly rather than by not breaking them: t() returns before it
     * touches map(), longFor() or anything else when the locale is the default,
     * so an English page with a fully translated catalogue behind it costs what
     * an English page with an empty translations table costs.
     */
    fpArabicOn();

    $product = fpProduct(['slug' => 'fp-en-cost', 'sku' => 'FP-ENC']);

    fpQueries('/shop/');
    fpQueries('/product/' . $product->slug . '/');

    $bareShop = fpQueries('/shop/');
    $bareProduct = fpQueries('/product/' . $product->slug . '/');

    foreach (['name', 'short_description', 'description', 'ingredients', 'how_to_use'] as $field) {
        fpPublish('products', $product->id, $field, 'AR ' . $field);
    }

    TranslationStore::flush();

    fpQueries('/shop/');
    fpQueries('/product/' . $product->slug . '/');

    expect(fpQueries('/shop/'))->toBe($bareShop)
        ->and(fpQueries('/product/' . $product->slug . '/'))->toBe($bareProduct);
});

it('sees a long translation corrected in the same process, like the map does', function () {
    /*
     * The Setting::map() trap this whole subsystem is written against, asked of
     * the NEW memo. longFor() keeps a per-process array as well as answering
     * from the database, so without flush() clearing it a correction approved
     * in a queue worker — which is where the order emails render — would keep
     * serving the old paragraph for the life of the process.
     */
    fpArabicOn();

    $product = fpProduct();
    fpPublish('products', $product->id, 'description', '<p>first</p>');

    TranslationStore::flush();
    app()->setLocale('ar');

    expect($product->t('description'))->toBe('<p>first</p>');

    // Through the model, so the saved hook fires and evicts — which is the
    // mechanism, not a flush() written into the test to make it pass.
    fpPublish('products', $product->id, 'description', '<p>second</p>');

    expect($product->t('description'))->toBe('<p>second</p>');
});

it('never serves a draft translation to a shopper, long fields included', function () {
    /*
     * The draft/approve cycle exists so a MACHINE cannot put words in the
     * shop's mouth. map() filters on `published()`; longFor() is a second
     * reader of the same table and had to be given the same filter, or the
     * split would have opened a hole in the one guarantee the accelerator
     * rests on — on the exact fields the machine translates in bulk.
     */
    fpArabicOn();

    $product = fpProduct();

    TranslationStore::put('ar', 'products', $product->id, 'description', '<p>ZZDRAFTDESC</p>', Translation::STATUS_DRAFT);
    TranslationStore::put('ar', 'products', $product->id, 'name', 'ZZDRAFTNAME', Translation::STATUS_DRAFT);

    TranslationStore::flush();

    $html = (string) test()->get('/ar/product/' . $product->slug . '/')->assertOk()->getContent();

    expect(str_contains($html, 'ZZDRAFTDESC'))->toBeFalse('A machine draft of a description reached a shopper.')
        ->and(str_contains($html, 'ZZDRAFTNAME'))->toBeFalse('A machine draft of a name reached a shopper.');

    // And the page still shows the English, rather than a hole where the draft was.
    expect($html)->toContain(FP_NAME_EN);
});
