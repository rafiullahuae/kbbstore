<?php

declare(strict_types=1);

use App\Http\Middleware\CheckRedirects;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Models\Redirect;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;
use Illuminate\Support\Str;

/**
 * =============================================================================
 * ARABIC SLUGS — THE POLICY, AND THE ONE DEFECT THE QUESTION UNCOVERED
 * =============================================================================
 *
 * docs/SEO-ARABIC-SLUGS.md is the decision and carries the numbers. This file
 * is the half of it that can go red: every claim that document makes about how
 * this application behaves TODAY is pinned here, because the document is only
 * worth reading for as long as it is still true.
 *
 * The verdict, in one line: one slug per row, with the language carried by the
 * /ar prefix — which is what `App\Support\HasTranslations`' header argued for
 * before Arabic was built, and what this round re-tested rather than assumed.
 * Nothing in this lane changed how a slug works.
 *
 * WHAT IS ACTUALLY FIXED HERE is a redirect defect that only shows up on a
 * non-ASCII address, and this shop already has those: see section 4.
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const ASP_ARABIC_SLUG = 'العناية-بالبشرة';

function aspSettings(array $values = []): void
{
    foreach (array_merge([
        'site_url' => 'https://kbeautybliss.test',
        'seo_site_name' => 'K-Beauty Bliss',
    ], $values) as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
}

/**
 * One request through the real kernel with the path spelled EXACTLY as given.
 *
 * NOT test()->get(): MakesHttpRequests::prepareUrlForRequest() ends in
 * `trim(url($uri), '/')`, so the harness strips the trailing slash off every
 * URL it is handed — and `redirects.source` is stored WITH it. The identical
 * call, for the identical reason, as RedirectMiddlewareTest::rmFetch() and
 * GpAddressesLandTest::gpFetch().
 *
 * It matters twice over in this file: a percent-escaped path must also reach
 * the kernel byte for byte, and `url()` would not leave one alone.
 */
function aspFetch(string $path): \Symfony\Component\HttpFoundation\Response
{
    return app(\Illuminate\Contracts\Http\Kernel::class)->handle(
        \Illuminate\Http\Request::create('http://localhost'.$path, 'GET'),
    );
}

function aspProduct(string $slug): Product
{
    return Product::create([
        'slug' => $slug, 'name' => 'ASP '.$slug, 'sku' => 'ASP-'.md5($slug), 'status' => 'publish',
        'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock',
        'image' => 'https://cdn.test/asp.jpg',
        'short_description' => 'A toner with comfortably more than eight words in its description.',
    ]);
}

/* ==========================================================================
 * 1. THE LIVE STATE: ENGLISH ONLY, SO THERE IS NOTHING INDEXED TO PROTECT YET
 * ========================================================================== */

it('serves one language until language_ar_enabled is set, so no Arabic address is indexed yet', function () {
    /*
     * The owner's position on 26 September 2026: "for now we are not launching
     * the arabic version immediately". This is what that means in code, and it
     * is why the slug decision is worth taking NOW and costs nothing to take —
     * a retrofit is only expensive once an Arabic URL has been indexed.
     *
     * MUTATION NOTE: make Locale::enabled() return true for a non-default
     * locale without consulting the setting — which is what seeding a
     * `language_ar_enabled` row anywhere in the migration set amounts to — and
     * every expectation in this test goes red, including the 404 on /ar/. There
     * is no such row today and there must not be one. Verified by doing it.
     */
    aspSettings();

    expect(Locale::enabled('ar'))->toBeFalse()
        ->and(Locale::enabledCodes())->toBe(['en'])
        ->and(Locale::segment('ar'))->toBe('')
        // One language is not a set of alternates, so no hreflang at all is
        // emitted and /ar is not a path this shop answers.
        ->and(Locale::alternatePaths('/product/asp-toner/'))->toBe([]);

    aspProduct('asp-toner');

    expect(aspFetch('/product/asp-toner/')->getStatusCode())->toBe(200)
        ->and(aspFetch('/ar/product/asp-toner/')->getStatusCode())->toBe(404);
});

/* ==========================================================================
 * 2. THE MECHANISM THAT MAKES "ONE SLUG" TRUE, AND CAN REGRESS
 * ========================================================================== */

it('keeps slug off every model translatable allowlist, which is what one address per row rests on', function () {
    /*
     * `saveTranslations()` will not write a field that is not on the model's own
     * $translatable allowlist, so this list IS the policy — not a comment about
     * it. A lane that adds 'slug' to one of these arrays gives that model a
     * second address in Arabic, and the hreflang layer (section 3) would go on
     * emitting the first one.
     *
     * MUTATION NOTE: add 'slug' to App\Models\Product::$translatable and this
     * is red on the products line. Verified by doing it.
     */
    foreach ([
        Product::class, Category::class, Brand::class, Post::class, \App\Models\Page::class,
    ] as $class) {
        $model = new $class();

        /*
         * ▲ NOT `expect($model->translatable())->not->toContain('slug', $msg)`,
         * AND THAT IS NOT A STYLE CHOICE — IT IS THE SAME VACUOUS ASSERTION
         * docs/SEO-ARABIC-PARITY.md §5 AND §7 ALREADY RECORD TWICE.
         *
         * `toContain()` takes VARIADIC NEEDLES, not a needle and a failure
         * message, so the message becomes a second thing the array is asked
         * about — and under `not` the pair passes whatever the array holds.
         * Found here the only way it can be found: by adding 'slug' to
         * App\Models\Product::$translatable and watching the suite stay GREEN.
         * Written this way it is red.
         */
        expect(in_array('slug', $model->translatable(), true))
            ->toBeFalse($class.' must not translate its slug');

        // The identifiers beside it, for the same reason the trait's header
        // gives: a translated SKU is a SKU nobody can look up.
        expect(in_array('sku', $model->translatable(), true))
            ->toBeFalse($class.' must not translate its SKU');
    }
});

it('builds every hreflang by swapping the prefix on ONE path, so a second slug would break reciprocity', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * THE STRUCTURAL COST OF A TRANSLATED SLUG, PINNED AS A FACT
     * ════════════════════════════════════════════════════════════════════════
     *
     * Locale::alternatePaths() is the ONLY producer of an alternate address in
     * this application. Seo::alternateLinks(), the <head> in
     * layouts/store.blade.php, the sitemap's <xhtml:link> block and llms.txt
     * all call it, and it takes a PATH and no row. It cannot know that a
     * product has a different slug in Arabic.
     *
     * So while the slug is shared this is exactly right and every cluster is
     * reciprocal — which is what SeoBilingualTest measures. The moment a row
     * carries two slugs, every English page would advertise an Arabic twin at
     * the English slug and every Arabic page an English twin at the Arabic one:
     * hreflang broken on every page of the shop at once, in the sitemap too,
     * and not fixable without making this function row-aware at six call sites
     * in four files.
     *
     * MUTATION NOTE: change Locale::alternatePaths() to return anything but the
     * same bare path per locale — for instance append '-ar' to the Arabic one —
     * and this is red. Verified.
     */
    aspSettings(['language_ar_enabled' => '1']);

    foreach ([
        '/product/asp-toner/',
        '/product-category/skincare/toners/',
        '/korean-skincare-brands/anua/',
        '/asp-article/',
    ] as $path) {
        $alternates = Locale::alternatePaths($path);

        expect($alternates)->toHaveKeys(['en', 'ar'])
            ->and($alternates['en'])->toBe($path)
            ->and($alternates['ar'])->toBe('/ar'.$path, 'the Arabic address is the English one plus four characters');
    }
});

/* ==========================================================================
 * 3. WHAT A NON-ASCII SLUG WOULD ACTUALLY DO, PER PAGE SHAPE
 * ========================================================================== */

it('can route an Arabic product and category slug, and cannot route an Arabic brand or article slug', function () {
    /*
     * The census docs/SEO-ARABIC-SLUGS.md §3 reports, measured rather than read
     * off the route file. Two of the four shapes the owner was asked about
     * cannot carry an Arabic slug at all today, and that is not a bug — it is
     * `PageController::slugPattern()`'s character class and the brand route's
     * own `[A-Za-z0-9\-_]+`, both of which are load-bearing:
     * RootSlugCollisionTest and RESERVED_SLUGS rest on the root catch-all NOT
     * matching arbitrary UTF-8.
     *
     * Pinned so that a future lane widening either regex finds out here that it
     * has also widened what the site-root catch-all will swallow.
     *
     * MUTATION NOTE: relax slugPattern()'s `[a-z0-9]` class to `[^\/]` and the
     * article expectation flips to 200. Verified.
     */
    aspSettings();

    $slug = ASP_ARABIC_SLUG;
    $encoded = rawurlencode($slug);

    aspProduct($slug);
    Category::create(['name' => 'تونر', 'slug' => $slug, 'path' => $slug]);
    Brand::create(['name' => 'انوا', 'slug' => $slug]);
    Post::create([
        'title' => 'Arabic titled article', 'slug' => $slug, 'status' => 'published',
        'body' => 'Body copy long enough to render.', 'published_at' => now(),
    ]);

    // Reachable — neither route constrains its parameter.
    expect(aspFetch('/product/'.$slug.'/')->getStatusCode())->toBe(200)
        ->and(aspFetch('/product/'.$encoded.'/')->getStatusCode())->toBe(200)
        ->and(aspFetch('/product-category/'.$slug.'/')->getStatusCode())->toBe(200)
        ->and(aspFetch('/product-category/'.$encoded.'/')->getStatusCode())->toBe(200);

    // Unreachable — the route regex refuses it, in either spelling.
    expect(aspFetch('/korean-skincare-brands/'.$slug.'/')->getStatusCode())->toBe(404)
        ->and(aspFetch('/korean-skincare-brands/'.$encoded.'/')->getStatusCode())->toBe(404)
        ->and(aspFetch('/'.$slug.'/')->getStatusCode())->toBe(404)
        ->and(aspFetch('/'.$encoded.'/')->getStatusCode())->toBe(404);

    // And the Latin control, so the four 404s above are the regex and not a
    // broken fixture.
    Brand::create(['name' => 'Anua', 'slug' => 'asp-anua']);
    Post::create([
        'title' => 'Latin article', 'slug' => 'asp-latin', 'status' => 'published',
        'body' => 'Body copy long enough to render.', 'published_at' => now(),
    ]);

    expect(aspFetch('/korean-skincare-brands/asp-anua/')->getStatusCode())->toBe(200)
        ->and(aspFetch('/asp-latin/')->getStatusCode())->toBe(200);
});

it('transliterates Arabic into Latin nobody can read, which is option 2 measured rather than imagined', function () {
    /*
     * `Str::slug()` is the only transliterator in this dependency set and is
     * what ProductImporter and PostImporter already use — PostImporter's own
     * comment records the result as "a valid address, and one no human will
     * ever recognise". Arabic script omits short vowels, so the romanisation
     * drops them too: "موقي الشمس" (sunscreen) is not "waqi-al-shams" but
     * `oaky-alshms`.
     *
     * This is pinned because "transliterate" was one of the three options the
     * owner was asked to choose between, and its whole premise — an
     * Arabic-readable Latin slug — is false with this toolchain. A dependency
     * bump that changed these strings should surface as a red test beside the
     * document that quotes them.
     */
    expect(Str::slug('واقي الشمس'))->toBe('oaky-alshms')
        ->and(Str::slug('مرطب الوجه'))->toBe('mrtb-alogh')
        ->and(Str::slug(ASP_ARABIC_SLUG))->toBe('alaanay-balbshr');

    // And the collision that makes it worse than unreadable: two different
    // Arabic words reduce to one Latin slug, so the second product would be
    // refused by the unique index or silently suffixed.
    expect(Str::slug('مرطب'))->toBe(Str::slug('مُرَطِب'));
});

it('cannot express an Arabic-only move, because one redirect row serves both languages', function () {
    /*
     * SetLocaleFromPath strips /ar BEFORE CheckRedirects sees the path, and
     * `redirects.source` is therefore stored with no locale segment — which is
     * the design docs/SEO-URL-MAP.md §3 describes and is exactly right for an
     * old address that moved in both languages.
     *
     * It also means the redirects table cannot say "this address moved in
     * Arabic only". That is the sentence that decides the retrofit question, so
     * it is measured here rather than reasoned about: a retrofit of Arabic
     * slugs cannot use this table at all without a `locale` column on it and a
     * middleware that reads the bound locale.
     *
     * MUTATION NOTE: register CheckRedirects before SetLocaleFromPath and the
     * Arabic Location below becomes the English one. RedirectMiddlewareTest
     * pins that ordering; this pins what it buys.
     */
    aspSettings(['language_ar_enabled' => '1']);

    Redirect::create(['source' => '/product/asp-moved/', 'target' => '/shop/', 'code' => 301, 'enabled' => true]);

    $english = aspFetch('/product/asp-moved/');
    $arabic = aspFetch('/ar/product/asp-moved/');

    expect($english->getStatusCode())->toBe(301)
        ->and($english->headers->get('Location'))->toBe('http://localhost/shop/')
        ->and($arabic->getStatusCode())->toBe(301)
        ->and($arabic->headers->get('Location'))->toBe('http://localhost/ar/shop/');
});

/* ==========================================================================
 * 4. THE DEFECT THIS ROUND FIXES
 * ========================================================================== */

it('fires a redirect the owner wrote for an Arabic address, whichever way the client escapes it', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * THE TEST THAT IS RED ON THE OLD CODE
     * ════════════════════════════════════════════════════════════════════════
     *
     * WHAT IT LOOKED LIKE ON THE SHOP. The old WordPress site published
     * Arabic-titled articles at percent-encoded permalinks — `%d8%a7%d9%84…`,
     * out of the real export, docs/GJ-POSTS-AND-VERDICT.md §"the fixture in
     * full". The importer refuses to publish such an article at that address
     * and renames it (`/العناية-بالبشرة/` became
     * `/skin-care-in-the-gulf-summer/`), then hands the owner a list at
     * Store → Import so he can write a redirect for each old address. That list
     * shows the address DECODED, deliberately — docs/GB-MEDIA-AND-REDIRECTS.md:
     * "because that is the address a person reads and the one Search Console
     * shows".
     *
     * So he pastes `/العناية-بالبشرة/` into Store → SEO & Meta → Redirects &
     * 404s, and it is stored in that spelling. `redirects.source` is compared
     * against `getPathInfo()`, WHICH IS NOT URLDECODED, and no client on earth
     * sends those bytes raw. Measured through this kernel before the fix:
     *
     *     request `/العناية-بالبشرة/`   301   (only curl and this test)
     *     request `/%D8%A7%D9%84…/`     404   (browsers, Search Console)
     *     request `/%d8%a7%d9%84…/`     404   (the old WordPress permalink)
     *
     * The owner does exactly what the import screen told him to do and the
     * address 404s for every real visitor, with nothing anywhere saying why.
     *
     * MUTATION NOTE: make CheckRedirects::spellings() return [$path] always —
     * which is the old behaviour — and the two escaped expectations below read
     * 404. Verified by doing it.
     */
    aspSettings();

    $old = '/'.ASP_ARABIC_SLUG.'/';
    $upper = '/'.rawurlencode(ASP_ARABIC_SLUG).'/';
    $lower = strtolower($upper);

    Post::create([
        'title' => 'Skin care in the Gulf summer', 'slug' => 'skin-care-in-the-gulf-summer',
        'status' => 'published', 'body' => 'Body copy long enough to render.', 'published_at' => now(),
    ]);

    Redirect::create([
        'source' => $old, 'target' => '/skin-care-in-the-gulf-summer/', 'code' => 301, 'enabled' => true,
    ]);

    foreach (['readable' => $old, 'UPPER hex' => $upper, 'lower hex' => $lower] as $label => $path) {
        $response = aspFetch($path);

        expect($response->getStatusCode())->toBe(301, $label.' spelling must reach the row')
            ->and($response->headers->get('Location'))
            ->toBe('http://localhost/skin-care-in-the-gulf-summer/', $label.' spelling lands on the article');
    }
});

it('counts the hit on the row that answered, whichever spelling arrived', function () {
    /*
     * The Redirects screen prints a hit counter per row, and the point of the
     * counter is to tell the owner whether an address he wrote a row for is
     * still being asked for. A row that fires through the escaped spelling and
     * does not count is a row he would conclude is dead.
     */
    aspSettings();

    $row = Redirect::create([
        'source' => '/'.ASP_ARABIC_SLUG.'/', 'target' => '/shop/', 'code' => 301, 'enabled' => true,
    ]);

    expect((int) $row->fresh()->hits)->toBe(0);

    aspFetch('/'.rawurlencode(ASP_ARABIC_SLUG).'/');

    expect((int) $row->fresh()->hits)->toBe(1);
});

it('refuses a decode that changes the address rather than its spelling', function () {
    /*
     * `%2F` decodes to a slash, so without this guard a row written for
     * `/asp-a/asp-b/` would claim `/asp-a%2Fasp-b/` — a DIFFERENT address, one
     * segment long, which a future route could legitimately serve. The
     * segment-count check is what separates "another spelling of this address"
     * from "another address", and it disposes of an escaped `..` by the same
     * arithmetic.
     *
     * MUTATION NOTE: delete the substr_count() guard in
     * CheckRedirects::spellings() and the first expectation below reads 301.
     * Verified.
     */
    aspSettings();

    Redirect::create(['source' => '/asp-a/asp-b/', 'target' => '/shop/', 'code' => 301, 'enabled' => true]);

    expect(aspFetch('/asp-a%2Fasp-b/')->getStatusCode())->not->toBe(301)
        // The honest spelling of the same row still works, so the guard has not
        // simply switched the feature off.
        ->and(aspFetch('/asp-a/asp-b/')->getStatusCode())->toBe(301);
});

it('leaves every ASCII address answering exactly as it did', function () {
    /*
     * Rule 1. `rawurldecode()` is the identity function on a path with no
     * escapes, and spellings() returns early before it is even called, so no
     * address that redirects today can have moved. This is the assertion that
     * says so rather than the paragraph.
     */
    aspSettings();

    Redirect::create(['source' => '/asp-old/', 'target' => '/shop/', 'code' => 301, 'enabled' => true]);

    $response = aspFetch('/asp-old/');

    expect($response->getStatusCode())->toBe(301)
        ->and($response->headers->get('Location'))->toBe('http://localhost/shop/')
        // A row nobody wrote still 404s, and the derived rule still answers for
        // the fifteen legacy category paths it owns.
        ->and(aspFetch('/asp-never-written/')->getStatusCode())->toBe(404)
        ->and(CheckRedirects::lookup('/asp-never-written/'))->toBeNull();
});
