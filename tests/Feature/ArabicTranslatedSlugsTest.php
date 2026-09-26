<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\Redirect;
use App\Models\Setting;
use App\Services\Seo\ArabicSlugRetrofit;
use App\Services\SettingsService;
use App\Support\LocaleSlugs;
use Tests\Support\LocaleSlugMiddleware;

/**
 * =============================================================================
 * THE ALTERNATIVE, BUILT AND MEASURED — NOT ARGUED FROM A DOCBLOCK
 * =============================================================================
 *
 * `HasTranslations`' header said a translated slug was a deliberate no. The owner
 * asked for the choice rather than the conclusion, so the choice is built: a
 * second Arabic address per row, served, canonicalised, advertised in hreflang,
 * with the redirects the change needs written in both directions.
 *
 * docs/SEO-ARABIC-SLUGS.md carries the costs and the recommendation, which is
 * still `shared` — and now rests on this file rather than on an opinion.
 *
 * THE RULE-1 HALF IS SECTION 1 AND IT IS THE ONE THAT MATTERS MOST: with the
 * policy at its shipped value, every address, canonical and hreflang on this shop
 * is what it was before any of this existed.
 */

const ATS_BASE = 'https://kbeautybliss.test';
const ATS_AR_TONER = 'مرطب-الوجه';
const ATS_AR_CAT = 'منظفات-الوجه';

function atsSettings(array $values = []): void
{
    foreach (array_merge([
        'site_url' => ATS_BASE,
        'seo_site_name' => 'K-Beauty Bliss',
        'language_ar_enabled' => '1',
    ], $values) as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
    LocaleSlugs::flush();
}

/** One request through the real kernel, path spelled exactly as given. */
function atsFetch(string $path): \Symfony\Component\HttpFoundation\Response
{
    return app(\Illuminate\Contracts\Http\Kernel::class)->handle(
        \Illuminate\Http\Request::create('http://localhost'.$path, 'GET'),
    );
}

function atsCanonical(string $path): string
{
    preg_match(
        '#<link rel="canonical" href="([^"]+)"#',
        (string) atsFetch($path)->getContent(),
        $m,
    );

    return $m[1] ?? '(none)';
}

/** @return array<string, string> hreflang => href */
function atsAlternates(string $path): array
{
    preg_match_all(
        '#<link rel="alternate" hreflang="([a-z-]+)" href="([^"]+)"#',
        (string) atsFetch($path)->getContent(),
        $m,
        PREG_SET_ORDER,
    );

    $out = [];

    foreach ($m as $row) {
        $out[$row[1]] = $row[2];
    }

    return $out;
}

function atsCatalogue(): array
{
    $product = Product::create([
        'slug' => 'ats-heartleaf-toner', 'name' => 'ATS Heartleaf Toner', 'sku' => 'ATS-1',
        'status' => 'publish', 'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock',
        'image' => 'https://cdn.test/ats.jpg',
        'short_description' => 'A toner with comfortably more than eight words in its description.',
    ]);

    $parent = Category::create(['name' => 'ATS Skincare', 'slug' => 'ats-skincare', 'path' => 'ats-skincare']);
    $child = Category::create([
        'name' => 'ATS Face Cleansers', 'slug' => 'ats-face-cleansers',
        'path' => 'ats-skincare/ats-face-cleansers', 'parent_id' => $parent->id,
    ]);

    return [$product, $child];
}

/* ==========================================================================
 * 1. RULE 1 — THE SHIPPED POLICY IS TODAY'S SHOP, TO THE BYTE
 * ========================================================================== */

it('ships at shared, and an Arabic slug on the row changes nothing while it does', function () {
    /*
     * The strongest form of the rule-1 pin available here: the rows are filled in
     * — an operator has typed Arabic addresses — and the policy has not been
     * moved, so NOTHING may differ. Not the addresses served, not the canonical,
     * not the cluster.
     *
     * MUTATION NOTE: change the default in LocaleSlugs::policy() from SHARED to
     * TRANSLATED and this test and the plan() test go red — two of the twelve,
     * which is what it should be: the other ten set the policy deliberately.
     * Verified. That is the whole reason the default lives in one method rather
     * than in each reader.
     */
    atsSettings();
    LocaleSlugMiddleware::wire();

    [$product, $category] = atsCatalogue();

    LocaleSlugs::put('ar', 'products', $product->id, ATS_AR_TONER);
    LocaleSlugs::put('ar', 'categories', $category->id, ATS_AR_CAT);

    expect(LocaleSlugs::policy())->toBe(LocaleSlugs::SHARED)
        ->and(LocaleSlugs::translating())->toBeFalse();

    // The shared address serves, in both languages, and says so about itself.
    expect(atsFetch('/product/ats-heartleaf-toner/')->getStatusCode())->toBe(200)
        ->and(atsFetch('/ar/product/ats-heartleaf-toner/')->getStatusCode())->toBe(200)
        ->and(atsCanonical('/ar/product/ats-heartleaf-toner/'))
        ->toBe(ATS_BASE.'/ar/product/ats-heartleaf-toner/');

    // The Arabic address is not a second address yet.
    expect(atsFetch('/ar/product/'.ATS_AR_TONER.'/')->getStatusCode())->toBe(404);

    // And the cluster is the four-character one the shop has always published.
    expect(atsAlternates('/product/ats-heartleaf-toner/'))->toBe([
        'en' => ATS_BASE.'/product/ats-heartleaf-toner/',
        'ar' => ATS_BASE.'/ar/product/ats-heartleaf-toner/',
        'x-default' => ATS_BASE.'/product/ats-heartleaf-toner/',
    ]);
});

/* ==========================================================================
 * 2. THE POLICY ON — SERVED, CANONICALISED, ADVERTISED
 * ========================================================================== */

it('serves the Arabic address and declares it canonical once the policy is on', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * THE TEST THAT IS RED WITHOUT ResolveLocaleSlugs AND Seo::displayPath()
     * ════════════════════════════════════════════════════════════════════════
     *
     * BOTH halves or neither, and the failure of doing one is worse than doing
     * nothing: a shop that SERVES the Arabic address and declares the English one
     * canonical is a page telling Google to index a different page.
     *
     * The escaped spelling is checked beside the readable one because it is the
     * only one a real client sends — a browser, Search Console and WhatsApp all
     * percent-encode before the request leaves.
     *
     * MUTATION NOTE: remove the ResolveLocaleSlugs registration from the harness
     * and the three 200s read 404; leave it and revert Seo::localise() to
     * `Locale::withSegment($path)` and the canonicals read the shared slug. Both
     * verified.
     */
    atsSettings();
    LocaleSlugMiddleware::wire();

    [$product, $category] = atsCatalogue();

    LocaleSlugs::put('ar', 'products', $product->id, ATS_AR_TONER);
    LocaleSlugs::put('ar', 'categories', $category->id, ATS_AR_CAT);

    ArabicSlugRetrofit::apply(LocaleSlugs::TRANSLATED);

    expect(LocaleSlugs::translating())->toBeTrue();

    $encoded = rawurlencode(ATS_AR_TONER);

    expect(atsFetch('/ar/product/'.ATS_AR_TONER.'/')->getStatusCode())->toBe(200)
        ->and(atsFetch('/ar/product/'.$encoded.'/')->getStatusCode())->toBe(200)
        ->and(atsFetch('/ar/product/'.strtolower($encoded).'/')->getStatusCode())->toBe(200);

    // And it declares itself, not the shared address.
    expect(atsCanonical('/ar/product/'.ATS_AR_TONER.'/'))
        ->toBe(ATS_BASE.'/ar/product/'.ATS_AR_TONER.'/');

    // The nested category keeps its ancestry and translates only the leaf, which
    // is the only segment that has a row.
    expect(atsFetch('/ar/product-category/ats-skincare/'.ATS_AR_CAT.'/')->getStatusCode())->toBe(200)
        ->and(atsCanonical('/ar/product-category/ats-skincare/'.ATS_AR_CAT.'/'))
        ->toBe(ATS_BASE.'/ar/product-category/ats-skincare/'.ATS_AR_CAT.'/');
});

it('keeps the hreflang cluster reciprocal across two different slugs', function () {
    /*
     * The cost the whole decision turns on, and the reason `Locale::alternatePaths()`
     * could not be left alone. It derives every language's address by swapping the
     * prefix on ONE path, so with two slugs the English page would advertise
     * `hreflang="ar"` at the ENGLISH slug and the Arabic page `hreflang="en"` at
     * the ARABIC one. Google drops a cluster that is not reciprocal, so BOTH
     * languages lose their alternate at once — every page, and the sitemap too.
     *
     * Reciprocal means: what the English page says the Arabic address is, is the
     * address the Arabic page calls its own canonical, and vice versa. Asserted
     * that way round rather than against two literals, because two literals can
     * both be wrong together.
     *
     * MUTATION NOTE: revert alternateLinks() to `Locale::alternatePaths($path)`
     * and this is red on the very first comparison. Verified.
     */
    atsSettings();
    LocaleSlugMiddleware::wire();

    [$product] = atsCatalogue();
    LocaleSlugs::put('ar', 'products', $product->id, ATS_AR_TONER);
    ArabicSlugRetrofit::apply(LocaleSlugs::TRANSLATED);

    $englishPage = '/product/ats-heartleaf-toner/';
    $arabicPage = '/ar/product/'.ATS_AR_TONER.'/';

    $fromEnglish = atsAlternates($englishPage);
    $fromArabic = atsAlternates($arabicPage);

    // Each page's own canonical is what the other page names for that language.
    expect($fromEnglish['ar'])->toBe(atsCanonical($arabicPage))
        ->and($fromArabic['en'])->toBe(atsCanonical($englishPage))
        // And each page names itself, or the pair reads as duplicates.
        ->and($fromEnglish['en'])->toBe(atsCanonical($englishPage))
        ->and($fromArabic['ar'])->toBe(atsCanonical($arabicPage))
        // x-default is the default language in both directions.
        ->and($fromEnglish['x-default'])->toBe(atsCanonical($englishPage))
        ->and($fromArabic['x-default'])->toBe(atsCanonical($englishPage));

    // The English address did not move. This is the sentence the owner cares
    // about most: his English rankings are the only ones he has.
    expect($fromEnglish['en'])->toBe(ATS_BASE.$englishPage)
        ->and(atsFetch($englishPage)->getStatusCode())->toBe(200);
});

/* ==========================================================================
 * 3. THE RETROFIT, AND WHETHER IT CAN BE UNDONE
 * ========================================================================== */

it('forwards the old Arabic address in Arabic only, leaving the English one alone', function () {
    /*
     * `redirects.locale` measured rather than described. ONE row per address, with
     * locale `ar`, so:
     *
     *     /ar/product/ats-heartleaf-toner/   301 → the Arabic address
     *     /product/ats-heartleaf-toner/      200, untouched
     *
     * Without the column the only row that could be written would have moved the
     * English page too. That is not a hypothetical about a future feature; it is
     * why the column is in the same migration.
     *
     * MUTATION NOTE: drop the locale clause from CheckRedirects::row() and the
     * English 200 below becomes a 301 — the English product page starts
     * redirecting to an Arabic address. Verified.
     */
    atsSettings();
    LocaleSlugMiddleware::wire();

    [$product] = atsCatalogue();
    LocaleSlugs::put('ar', 'products', $product->id, ATS_AR_TONER);

    $result = ArabicSlugRetrofit::apply(LocaleSlugs::TRANSLATED);

    expect($result['written'])->toBe(1);

    $old = atsFetch('/ar/product/ats-heartleaf-toner/');

    expect($old->getStatusCode())->toBe(301)
        ->and($old->headers->get('Location'))->toBe('http://localhost/ar/product/'.ATS_AR_TONER.'/')
        // Both slash spellings, because getPathInfo() reports whichever the
        // client sent and this table compares byte for byte.
        ->and(atsFetch('/ar/product/ats-heartleaf-toner')->getStatusCode())->toBe(301);

    expect(atsFetch('/product/ats-heartleaf-toner/')->getStatusCode())->toBe(200)
        ->and(atsFetch('/product/ats-heartleaf-toner')->getStatusCode())->toBe(200);
});

it('goes back to shared without orphaning the Arabic addresses it had published', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * REVERSIBILITY, WHICH IS THE FACT THE OWNER MOST NEEDS BEFORE CHOOSING
     * ════════════════════════════════════════════════════════════════════════
     *
     * Switching back writes the opposite rows, whose SOURCES are Arabic — and this
     * is exactly where the other half of this round is load-bearing.
     * `CheckRedirects::spellings()` decodes the incoming path, so one row answers
     * the readable spelling AND both hex spellings a real client sends. Before
     * that fix a reverse row could only have been matched by a client sending raw
     * UTF-8 bytes, which none does: the switch would not have been reversible at
     * all, and nobody would have found out until after flipping it back.
     *
     * The stale forward rows are deleted rather than left, because two rows
     * pointing at each other are a loop CheckRedirects refuses to follow — which
     * would leave BOTH addresses dead with nothing on any screen saying why.
     *
     * MUTATION NOTE: remove the stale-row delete from ArabicSlugRetrofit::apply()
     * and the first two expectations read 200 rather than 301 — the loop guard
     * declines both rows and serves the page. Verified.
     */
    atsSettings();
    LocaleSlugMiddleware::wire();

    [$product] = atsCatalogue();
    LocaleSlugs::put('ar', 'products', $product->id, ATS_AR_TONER);

    ArabicSlugRetrofit::apply(LocaleSlugs::TRANSLATED);
    $back = ArabicSlugRetrofit::apply(LocaleSlugs::SHARED);

    expect($back['written'])->toBe(1)
        ->and(LocaleSlugs::policy())->toBe(LocaleSlugs::SHARED);

    $encoded = rawurlencode(ATS_AR_TONER);

    foreach (['readable' => ATS_AR_TONER, 'UPPER hex' => $encoded, 'lower hex' => strtolower($encoded)] as $label => $spelling) {
        $response = atsFetch('/ar/product/'.$spelling.'/');

        expect($response->getStatusCode())->toBe(301, $label.' spelling of the retired Arabic address')
            ->and($response->headers->get('Location'))
            ->toBe('http://localhost/ar/product/ats-heartleaf-toner/', $label.' lands on the shared address');
    }

    // And the shared address is serving again, in both languages.
    expect(atsFetch('/ar/product/ats-heartleaf-toner/')->getStatusCode())->toBe(200)
        ->and(atsFetch('/product/ats-heartleaf-toner/')->getStatusCode())->toBe(200);
});

it('never overwrites a redirect the owner wrote himself', function () {
    /*
     * `redirects.source` is unique, so a generated row would silently replace a
     * decision made on Store → SEO & Meta → Redirects & 404s. His wins — the same
     * rule CheckRedirects::lookup() already follows at read time, applied at write
     * time, or the screen he can see is the one that loses.
     *
     * MUTATION NOTE: delete the `! $existing->auto_created` guard in
     * ArabicSlugRetrofit::write() and the owner's target is replaced. Verified.
     */
    atsSettings();
    LocaleSlugMiddleware::wire();

    [$product] = atsCatalogue();
    LocaleSlugs::put('ar', 'products', $product->id, ATS_AR_TONER);

    Redirect::create([
        'source' => '/product/ats-heartleaf-toner/', 'target' => '/shop/',
        'code' => 301, 'enabled' => true, 'auto_created' => false,
    ]);

    ArabicSlugRetrofit::apply(LocaleSlugs::TRANSLATED);

    expect(Redirect::query()->where('source', '/product/ats-heartleaf-toner/')->value('target'))
        ->toBe('/shop/');
});

it('reports what would move before anything is written', function () {
    /*
     * plan() is read-only, and that is what lets a screen say "671 products and 59
     * categories will change address, and 730 redirects will be written" BEFORE
     * the owner commits. A switch that has already done it is not a decision he
     * was offered.
     */
    atsSettings();

    [$product, $category] = atsCatalogue();
    LocaleSlugs::put('ar', 'products', $product->id, ATS_AR_TONER);
    LocaleSlugs::put('ar', 'categories', $category->id, ATS_AR_CAT);

    // The shipped seed migration writes ten rows of its own, so the assertion is
    // that plan() adds none rather than that the table is empty.
    $before = Redirect::query()->count();

    $plan = ArabicSlugRetrofit::plan(LocaleSlugs::TRANSLATED);

    expect($plan)->toHaveCount(2)
        ->and(Redirect::query()->count())->toBe($before, 'plan() writes nothing')
        ->and(LocaleSlugs::policy())->toBe(LocaleSlugs::SHARED, 'plan() moves no setting');

    $byGroup = collect($plan)->keyBy('group');

    expect($byGroup['products']['from'])->toBe('/product/ats-heartleaf-toner/')
        ->and($byGroup['products']['to'])->toBe('/product/'.ATS_AR_TONER.'/')
        // The nested category's ancestry is kept and only the leaf moves.
        ->and($byGroup['categories']['from'])->toBe('/product-category/ats-skincare/ats-face-cleansers/')
        ->and($byGroup['categories']['to'])->toBe('/product-category/ats-skincare/'.ATS_AR_CAT.'/');
});

/* ==========================================================================
 * 4. THE UNIQUENESS SPACE, AND THE COLLATION BUG NO SQLITE TEST CAN SEE
 * ========================================================================== */

it('strips the marks MySQL ignores, so two addresses cannot mean one on the live shop', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * A DEFECT THAT WOULD ONLY HAVE APPEARED IN PRODUCTION
     * ════════════════════════════════════════════════════════════════════════
     *
     * config/database.php declares `utf8mb4_unicode_ci`, and under it — and under
     * MySQL 8's own default `utf8mb4_0900_ai_ci` — Arabic combining marks and the
     * tatweel are IGNORED at the primary level. Measured against the real server:
     *
     *     'مرطب' = 'مُرَطِب'   → 1   (unicode_ci, 0900_ai_ci)
     *     'مرطبـــ' = 'مرطب'   → 1   (tatweel ignored)
     *     the same two          → 0   (general_ci, and SQLite)
     *
     * So two Arabic addresses differing by a harakat or a kashida are the SAME
     * address on the live shop and DIFFERENT addresses in this suite: a unique
     * index that accepts both here rejects the second there, and a lookup could
     * return the other row. normalise() removes them at the door so the two
     * engines cannot disagree — the comparison is settled in PHP before either
     * the index or the query sees it.
     *
     * MUTATION NOTE: delete the diacritic/tatweel class from
     * LocaleSlugs::normalise() and this is red on the first expectation, on
     * SQLite, which is the point — the guard is testable here precisely because
     * it does not depend on the engine.
     */
    atsSettings();

    expect(LocaleSlugs::normalise('مُرَطِب'))->toBe('مرطب')
        ->and(LocaleSlugs::normalise('مرطبـــ'))->toBe('مرطب')
        // And the ordinary tidying, so an address is one address however typed.
        ->and(LocaleSlugs::normalise('  مرطب الوجه  '))->toBe('مرطب-الوجه')
        ->and(LocaleSlugs::normalise('ATS_Heartleaf  Toner'))->toBe('ats-heartleaf-toner');
});

it('refuses a second row the same address, and refuses one the shop could not serve', function () {
    /*
     * One address per row and one row per address, per language. The uniqueness is
     * the thing `translations` has no space for, and is why this is a table of its
     * own rather than a field on that one.
     *
     * The unservable cases are the ones that would 404 or worse: a reserved first
     * segment is answered by the storefront and never by a row, a slash or a dot
     * changes what the address IS, and a percent cannot be told apart from an
     * escape once it comes back off the wire.
     */
    atsSettings();

    [$product] = atsCatalogue();

    $other = Product::create([
        'slug' => 'ats-other', 'name' => 'ATS Other', 'sku' => 'ATS-2', 'status' => 'publish',
        'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock',
        'image' => 'https://cdn.test/ats.jpg',
        'short_description' => 'A toner with comfortably more than eight words in its description.',
    ]);

    expect(LocaleSlugs::put('ar', 'products', $product->id, ATS_AR_TONER))->toBeTrue()
        ->and(LocaleSlugs::put('ar', 'products', $other->id, ATS_AR_TONER))->toBeFalse()
        // The marks are stripped BEFORE uniqueness is asked, so a kashida is not
        // a way past it.
        ->and(LocaleSlugs::put('ar', 'products', $other->id, 'مرطب-الوجهـ'))->toBeFalse();

    foreach (['cart', 'shop', 'ar', 'a/b', 'a.b', 'a%20b', ''] as $bad) {
        expect(LocaleSlugs::put('ar', 'products', $other->id, $bad))
            ->toBe($bad === '', $bad === '' ? 'blank clears the row' : '"'.$bad.'" is not servable');
    }

    // Blank cleared it rather than storing an empty address — the same rule
    // HasTranslations follows, so "how much is left" can be counted.
    expect(LocaleSlugs::slugFor('ar', 'products', $other->id))->toBeNull();
});

it('does not offer a policy for the two page shapes whose routes refuse Arabic', function () {
    /*
     * `brands` and `posts` are deliberately absent from ADDRESSABLE.
     * `/korean-skincare-brands/{slug}/` is constrained to `[A-Za-z0-9\-_]+` and the
     * site-root article route to PageController::slugPattern(), whose character
     * class is `[a-z0-9]` — both 404 an Arabic slug in either spelling
     * (ArabicSlugPolicyTest measures it). Offering the owner a box whose value
     * produces a 404 is worse than not offering it, and widening either regex also
     * widens what the root catch-all swallows, which is what RESERVED_SLUGS and
     * RootSlugCollisionTest rest on.
     */
    expect(array_keys(LocaleSlugs::ADDRESSABLE))->toBe(['products', 'categories'])
        ->and(LocaleSlugs::put('ar', 'brands', 1, ATS_AR_TONER))->toBeFalse()
        ->and(LocaleSlugs::put('ar', 'posts', 1, ATS_AR_TONER))->toBeFalse();
});

/* ==========================================================================
 * 5. THE POLICY VALUE ITSELF
 * ========================================================================== */

it('falls back to shared for any value that is not a policy', function () {
    /*
     * Rule 5. This value decides behaviour on every storefront request, so a row
     * written by an older build or edited by hand has to fall to the safe answer.
     */
    atsSettings(['seo_arabic_slugs' => 'transliterated']);

    expect(LocaleSlugs::policy())->toBe(LocaleSlugs::SHARED);

    atsSettings(['seo_arabic_slugs' => LocaleSlugs::TRANSLATED]);

    expect(LocaleSlugs::policy())->toBe(LocaleSlugs::TRANSLATED);
});

it('puts the new middleware after the two it has to follow', function () {
    /*
     * The order is not a preference. AFTER SetLocaleFromPath because it needs the
     * bound locale and /ar off the path; AFTER CheckRedirects because running it
     * first rewrites the NEW Arabic address back to the shared slug, the retrofit
     * row then fires, and the visitor bounces between the two for ever.
     *
     * This is the property the integrator has to preserve, so it is asserted
     * against the stack this harness builds rather than described in a comment.
     */
    $stack = LocaleSlugMiddleware::wire();

    $resolve = array_search(\App\Http\Middleware\ResolveLocaleSlugs::class, $stack, true);
    $locale = array_search(\App\Http\Middleware\SetLocaleFromPath::class, $stack, true);
    $redirects = array_search(\App\Http\Middleware\CheckRedirects::class, $stack, true);

    expect($resolve)->not->toBeFalse()
        ->and($locale)->not->toBeFalse('SetLocaleFromPath must be in the global stack')
        ->and($redirects)->not->toBeFalse('CheckRedirects must be in the global stack')
        ->and($resolve)->toBeGreaterThan($locale)
        ->and($resolve)->toBeGreaterThan($redirects);
});

/* ==========================================================================
 * 6. THE NUMBER THE DECISION TURNS ON
 * ========================================================================== */

it('writes exactly two redirect rows per address that moves', function () {
    /*
     * ═══════════════════════════════════════════════════════════════════════
     * WHAT A RETROFIT COSTS, MEASURED, SO THE DOC'S ARITHMETIC IS NOT A GUESS
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Two, not one: `getPathInfo()` reports whichever trailing slash the client
     * sent and this table compares byte for byte, so a row written for only the
     * slashed spelling leaves the other 404ing — the gap docs/SEO-URL-MAP.md §6.4
     * reports for hand-written rows.
     *
     * NOT two per LANGUAGE-SPELLING, and that is the saving the encoding fix in
     * this same lane buys. `CheckRedirects::spellings()` decodes the incoming
     * path, so one row answers the readable spelling and both hex spellings. Per
     * moved address that is 2 rows instead of 6.
     *
     * docs/SEO-ARABIC-SLUGS.md multiplies this by the real census
     * (671 products + 59 categories) and reports 1,460. The multiplication is
     * arithmetic; this is the measurement it rests on.
     */
    atsSettings();
    LocaleSlugMiddleware::wire();

    [$product, $category] = atsCatalogue();
    LocaleSlugs::put('ar', 'products', $product->id, ATS_AR_TONER);
    LocaleSlugs::put('ar', 'categories', $category->id, ATS_AR_CAT);

    $before = Redirect::query()->count();

    $on = ArabicSlugRetrofit::apply(LocaleSlugs::TRANSLATED);

    expect($on['written'])->toBe(2, 'two addresses moved')
        ->and(Redirect::query()->count() - $before)->toBe(4, 'two rows each');

    /*
     * AND SWITCHING BACK DOES NOT ACCUMULATE. The previous generation is deleted
     * before the reverse rows are written, so the table holds one generation at a
     * time however often the owner changes his mind — which is the difference
     * between a reversible switch and a table that grows by 1,460 rows per flip.
     */
    ArabicSlugRetrofit::apply(LocaleSlugs::SHARED);

    expect(Redirect::query()->count() - $before)->toBe(4, 'one generation, not two');

    ArabicSlugRetrofit::apply(LocaleSlugs::TRANSLATED);

    expect(Redirect::query()->count() - $before)->toBe(4, 'still one generation after three flips');
});
