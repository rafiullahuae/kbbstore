<?php

declare(strict_types=1);

use App\Http\Middleware\CheckRedirects;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Models\Redirect;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\LegacyCategoryUrls;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;

/**
 * =============================================================================
 * THE FIFTEEN ADDRESSES GOOGLE HOLDS, AND THE 404 AT THE END OF EVERY ONE
 * =============================================================================
 *
 * kbeautybliss.com served its category archives FLAT AT THE SITE ROOT --
 * /toners/, /skincare-sets/, /sunscreens/ -- because `woocommerce_permalinks
 * ['category_base']` was the empty string on that install (the exporter reads
 * that setting and writes it into manifest.json; docs/GE-WP-EXPORTER.md §5).
 * This application serves them nested, at /product-category/{path}/ (U-03).
 * App\Support\LegacyCategoryUrls::PATHS is the closed list of the fifteen,
 * copied off the live navigation.
 *
 * ── WHAT THE DEFECT LOOKED LIKE ON THE SHOP ─────────────────────────────────
 *
 * Measured through this application's own kernel before the fix, with the
 * catalogue present:
 *
 *     GET /toners/          404       GET /skincare-sets/   404
 *     GET /sunscreens/      404       ... all fifteen ...   404
 *
 * They do not even 404 honestly: routes/kbb-brands-blog.php ends in `/{slug}/`,
 * so each one falls through to PageController@post, which looks for an ARTICLE
 * called "toners", finds none, and 404s. The request is spent in the wrong
 * table entirely.
 *
 * And that 404 is the END OF A 301. docs/CUTOVER-EXTRABEAUTY.md §4.1 forwards
 * the retired domain PRESERVING THE PATH, so kbeautybliss.com/toners/ becomes
 * extrabeauty.ae/toners/ becomes a not-found page. The ranking that should have
 * been inherited is dropped instead, silently. Two of the fifteen are confirmed
 * indexed today with their live titles -- /skincare/ and /skincare-sets/ -- see
 * SeoAudit::scanLegacyAddresses, which REPORTS this and, until this lane, was
 * the only thing in the repository that touched it.
 * docs/SEO-SKINCARE-SETS-DUPLICATE.md measured the same thing for one of the
 * fifteen and said in as many words that "nothing in the repo fixes it".
 *
 * ── WHAT EACH BLOCK IS HERE TO CATCH ────────────────────────────────────────
 *
 *  1. The defect itself, in English and in Arabic, asserted as a 404 first on
 *     a slug this shop does NOT carry so the test cannot pass by accident.
 *  2. ONE HOP. The destination is the canonical nested path, not the flat
 *     /product-category/{slug}/ form, which is itself a 301.
 *  3. NEVER INVENT. A legacy address whose category does not exist stays a 404.
 *  4. The shop's own published article wins over a derived redirect.
 *  5. The table wins over the derived rule, in both directions.
 *  6. No loop, and no chain hidden from the loop walk.
 *  7. Zero queries on a warm storefront page.
 *  8. Nothing that already works changing -- the ten shipped journal rows.
 */

/** The real nesting: `toners` under `skincare`, as the import builds it. */
function legCategory(string $slug, string $name, ?int $parentId, string $path, int $depth): Category
{
    $c = Category::query()->firstOrNew(['slug' => $slug]);
    $c->fill(['name' => $name, 'parent_id' => $parentId]);
    $c->forceFill(['path' => $path, 'depth' => $depth])->save();

    return $c;
}

function legTree(): array
{
    // `toners` and `sunscreens` already exist as ROOT placeholders, from
    // DemoCatalogueSeeder, which 2026_08_27_100000_seed_demo_catalogue runs on
    // every install. Re-parenting the real row rather than inserting a second
    // one is both what the WordPress import does and the harder case: the
    // fixture then carries a category whose flat address and canonical address
    // differ, which is where the one-hop rule earns its keep.
    $parent = legCategory('skincare', 'Skincare', null, 'skincare', 0);
    $child = legCategory('toners', 'Toners', $parent->id, 'skincare/toners', 1);
    $sets = legCategory('skincare-sets', 'Skincare Sets', null, 'skincare-sets', 0);

    return [$parent, $child, $sets];
}

function legArabicOn(): void
{
    $s = app(SettingsService::class);
    $s->set(Locale::SETTING_ENABLED, '1');
    $s->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
}

/**
 * One request through the REAL global middleware stack, at the EXACT path
 * spelling given, returning [status, Location].
 *
 * ── WHY NOT test()->get(), WHICH IS WHAT EVERY OTHER TEST HERE USES ─────────
 *
 * Because it cannot express the difference this whole file is about.
 * `Illuminate\Foundation\Testing\Concerns\MakesHttpRequests::
 * prepareUrlForRequest()` does `trim($uri, '/')` before building the request,
 * so `test()->get('/toners/')` and `test()->get('/toners')` produce the SAME
 * request and both arrive at getPathInfo() as `/toners`. The trailing slash —
 * the spelling U-01 fixes, the spelling Google actually holds, and the spelling
 * `redirects.source` is stored in — is unrepresentable through that helper.
 *
 * That is not a theoretical nicety. It is why a row written for `/toners/` and
 * a visitor arriving on `/toners` are a real pair with a real answer, and a
 * test that cannot tell them apart would have pinned the wrong one. Sending the
 * request through the HTTP kernel directly keeps the spelling intact and still
 * runs CanonicalHost → SetLocaleFromPath → CheckRedirects exactly as the server
 * does, which is the only stack worth measuring.
 *
 * docs/GP-ADDRESSES-LAND.md §5.5 found the same class of blindness in an
 * assertion — assertRedirect() builds its expected URL through the same
 * UrlGenerator the redirect went through, so both sides were slash-stripped and
 * the assertion could not see the difference it named. Reading the raw header
 * is the only spelling of this that stays honest.
 *
 * @return array{0:int, 1:?string}
 */
function legFetch(string $path): array
{
    // Accepts a bare path or a whole URL, so the Location header of one fetch
    // can be fed straight into the next — which is how "one hop" is measured
    // rather than asserted.
    $url = str_starts_with($path, 'http') ? $path : 'http://localhost' . $path;

    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle(\Illuminate\Http\Request::create($url, 'GET'));

    return [$response->getStatusCode(), $response->headers->get('Location')];
}

/** The raw Location header for an exact path spelling. */
function legLocation(string $path): ?string
{
    return legFetch($path)[1];
}

/** The status for an exact path spelling. */
function legStatus(string $path): int
{
    return legFetch($path)[0];
}

// ---------------------------------------------------------------------------
// 1 + 2. The defect, and the one-hop rule
// ---------------------------------------------------------------------------

it('lands a legacy flat category address on its canonical archive, in one hop', function () {
    /*
     * RED WITHOUT THE FIX: every expectation below measured 404 with no
     * Location header at all, because /toners/ fell through the root
     * article catch-all to PageController@post.
     *
     * MUTATION NOTE, RUN: returning null from
     * LegacyCategoryUrls::landingPath() unconditionally puts every assertion
     * in this block back to 404 -- 8 failed across the file.
     */
    legTree();

    // The nested leaf. ONE hop, straight to the canonical path -- NOT to
    // /product-category/toners/, which CategoryArchiveController would then
    // 301 again. Two hops leak ranking and burn crawl budget for nothing.
    expect(legLocation('/toners/'))->toBe('http://localhost/product-category/skincare/toners/');

    // MUTATION NOTE, RUN: swapping landingPath()'s `redirect` branch to the
    // flat form -- LegacyCategoryUrls::toCategoryPath() -- makes this Location
    // '/product-category/toners/', which answers 301 and not 200, so the line
    // below is red -- 6 failed across the file.
    $first = legLocation('/toners/');
    expect(legStatus((string) $first))->toBe(200, 'the 301 does not land on a page: it lands on another redirect');

    // A root-level category: the flat and canonical forms are the same string.
    expect(legLocation('/skincare-sets/'))->toBe('http://localhost/product-category/skincare-sets/');
    expect(legLocation('/skincare/'))->toBe('http://localhost/product-category/skincare/');
});

it('uses 301 and not 302, because the old domain is being retired', function () {
    // docs/CUTOVER-EXTRABEAUTY.md §4.1: kbeautybliss.com is forwarded and then
    // retired, and a Change of Address is filed. A 302 asks Google to KEEP the
    // old address indexed, which is the opposite of what that is for.
    legTree();

    expect(legStatus('/toners/'))->toBe(301);
});

it('matches the address without its trailing slash as well', function () {
    /*
     * U-01 keeps the trailing slash and that is the spelling Google holds, but
     * a link pasted into Instagram without one arrives as `/toners` and
     * getPathInfo() reports it verbatim. The shipped article seed made the
     * same call for the same reason; this follows it rather than inventing a
     * second policy for the same table.
     *
     * RED WITHOUT THE FIX: 404.
     */
    legTree();

    expect(legLocation('/toners'))->toBe('http://localhost/product-category/skincare/toners/');
});

// ---------------------------------------------------------------------------
// 3. Never invent a destination
// ---------------------------------------------------------------------------

it('leaves a legacy address whose category this shop does not carry as an honest 404', function () {
    /*
     * THE RULE THIS BLOCK EXISTS FOR: a confident 301 to the wrong page is
     * worse than a 404. A 404 the owner can see; a wrong 301 transfers a
     * ranking to the wrong URL and nobody ever finds out.
     *
     * /lip-care/ is one of the fifteen and this fixture has no such category,
     * which is the state of a shop whose import has not run yet.
     *
     * MUTATION NOTE, RUN: making landingPath() fall back to the flat form
     * when resolve() answers `notfound` turns this into a 301 to
     * /product-category/lip-care/ -- which is itself a 404, a redirect ending
     * on a not-found page -- and this block goes red on both lines -- 2
     * failed.
     */
    legTree();

    expect(Category::query()->where('slug', 'lip-care')->exists())->toBeFalse();
    expect(legStatus('/lip-care/'))->toBe(404);
    expect(legLocation('/lip-care/'))->toBeNull();
});

it('never redirects an address that is not one of the fifteen', function () {
    // The root namespace is shared with articles. The closed list is the whole
    // blast radius, and a category existing is not by itself a licence to
    // claim its root slug.
    legCategory('ampoules', 'Ampoules', null, 'ampoules', 0);

    expect(LegacyCategoryUrls::isLegacy('/ampoules/'))->toBeFalse();
    expect(legLocation('/ampoules/'))->toBeNull();
});

// ---------------------------------------------------------------------------
// 4. The shop's own page wins
// ---------------------------------------------------------------------------

it('serves a published article at a legacy slug instead of redirecting it away', function () {
    /*
     * THE COLLISION THIS WOULD OTHERWISE CAUSE. Articles live at the site root
     * -- routes/kbb-brands-blog.php `/{slug}/` -- which is the same namespace
     * the fifteen occupy. CheckRedirects now runs in the GLOBAL pipeline,
     * before the router, so a derived redirect would shadow the article
     * outright: the owner publishes a piece at "hair-care" and its own address
     * 301s away to a category archive, for ever, with the article reachable at
     * no URL at all. docs/GP-ADDRESSES-LAND.md §10.4 names this from the other
     * side -- a redirect is not entitled to take the address of a page the
     * owner published. Before the middleware was registered it was harmless,
     * because the table was read only on a 404. It is not harmless now.
     *
     * MUTATION NOTE, RUN: deleting the Post guard from landingPath() makes
     * this a 301 to /product-category/hair-care/, so the article is reachable
     * at no address at all -- 2 failed.
     */
    legCategory('hair-care', 'Hair Care', null, 'hair-care', 0);

    Post::query()->create([
        'title' => 'Hair Care, the K-beauty way',
        'slug' => 'hair-care',
        'status' => 'published',
        'body' => 'Body copy.',
    ]);

    expect(legStatus('/hair-care/'))->toBe(200);
    expect(legLocation('/hair-care/'))->toBeNull();
});

it('goes back to redirecting once that article is unpublished', function () {
    // The derived answer is re-derived per request, which is the whole reason
    // it is not a written row: it is correct the moment the data moves.
    legCategory('hair-care', 'Hair Care', null, 'hair-care', 0);

    $post = Post::query()->create([
        'title' => 'Hair Care', 'slug' => 'hair-care', 'status' => 'published', 'body' => 'x',
    ]);

    expect(legLocation('/hair-care/'))->toBeNull();

    $post->update(['status' => 'draft']);

    expect(legLocation('/hair-care/'))->toBe('http://localhost/product-category/hair-care/');
});

// ---------------------------------------------------------------------------
// 5. The table wins
// ---------------------------------------------------------------------------

it('lets a stored row override the derived answer', function () {
    /*
     * A row is a decision somebody made and can see on Store → SEO & Meta →
     * Redirects & 404s. A derived answer is one this application worked out.
     * When they disagree the visible one wins, or switching a row off would
     * change nothing and no screen would explain why.
     *
     * MUTATION NOTE, RUN: asking derived() before the table inside step()
     * sends this to the category archive instead of the product -- 1 failed.
     */
    legTree();

    Product::query()->create([
        'name' => 'Toner Pick', 'slug' => 'toner-pick', 'sku' => 'LEG-1', 'status' => 'publish',
        'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock', 'image' => 'https://cdn.test/a.jpg',
    ]);

    Redirect::query()->create([
        'source' => '/toners/', 'target' => '/product/toner-pick/', 'code' => 301,
        'enabled' => true, 'auto_created' => false,
    ]);

    expect(legLocation('/toners/'))->toBe('http://localhost/product/toner-pick/');
});

it('lets that row govern the slashless spelling of its own address too', function () {
    /*
     * REPORTED RATHER THAN HIDDEN: this block exists because the guard it
     * covers SURVIVED the first mutation pass. Removing the other-spelling
     * deferral from CheckRedirects::derived() left all eighteen tests green —
     * the guard was correct and UNREACHED, which is the shape this repository
     * has paid for twice (Api\ProductController's dead status filter, and Lane
     * GP's M1). The input that reaches it is this one.
     *
     * WHAT IT WOULD LOOK LIKE ON THE SHOP. The owner decides on Store → SEO &
     * Meta → Redirects & 404s that /toners/ should go to a product. A visitor
     * follows a link somebody pasted without the trailing slash. getPathInfo()
     * reports `/toners` verbatim, the table has no row under that spelling, and
     * the derived rule answers instead — so ONE old address lands in TWO
     * different places depending on a character nobody typed on purpose, and
     * the decision a human made is the one that loses.
     *
     * MUTATION NOTE, RUN: deleting the `$other` block from derived() makes
     * this line red — the Location becomes the category archive — 1 failed.
     */
    legTree();

    Product::query()->create([
        'name' => 'Toner Pick', 'slug' => 'toner-pick', 'sku' => 'LEG-4', 'status' => 'publish',
        'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock', 'image' => 'https://cdn.test/a.jpg',
    ]);

    Redirect::query()->create([
        'source' => '/toners/', 'target' => '/product/toner-pick/', 'code' => 301,
        'enabled' => true, 'auto_created' => false,
    ]);

    // The slashed spelling is the row's own and answers it.
    expect(legLocation('/toners/'))->toBe('http://localhost/product/toner-pick/');

    // The slashless one must NOT be claimed by the derived rule behind the
    // owner's back. Widening what the TABLE matches is a separate change and
    // is deliberately not made here, so this 404s exactly as it does today —
    // what matters is that it does not 301 somewhere else.
    expect(legLocation('/toners'))->not->toBe(
        'http://localhost/product-category/skincare/toners/',
        'the derived rule overrode a decision the owner made about this address'
    );
});

it('falls back to the derived answer when the owner switches that row off', function () {
    // A disabled row is not an instruction to 404 -- it is the absence of an
    // instruction, and the derived rule is what "no instruction" means here.
    legTree();

    Redirect::query()->create([
        'source' => '/toners/', 'target' => '/shop/', 'code' => 301,
        'enabled' => false, 'auto_created' => false,
    ]);

    expect(legLocation('/toners/'))->toBe('http://localhost/product-category/skincare/toners/');
});

// ---------------------------------------------------------------------------
// 6. Loops
// ---------------------------------------------------------------------------

it('refuses a cycle that runs through the derived rule', function () {
    /*
     * THE FAILURE MODE THE GLOBAL REGISTRATION INTRODUCED. A row pointing the
     * canonical archive back at /toners/ closes a cycle with the derived hop:
     * /toners/ → /product-category/skincare/toners/ → /toners/ → … on a page
     * the shop was serving correctly a moment ago, for ever.
     *
     * MUTATION NOTE, RUN: putting loops()'s walk back to
     * `claimed($next)` + `row($next)` -- which sees stored rows only -- does
     * NOT make this red, because this particular cycle's first hop IS a row.
     * The block below it is the one that catches that, and it is red on the
     * old walk.
     */
    legTree();

    Redirect::query()->create([
        'source' => '/product-category/skincare/toners/', 'target' => '/toners/',
        'code' => 301, 'enabled' => true, 'auto_created' => false,
    ]);

    expect(CheckRedirects::lookup('/toners/'))->toBeNull('a cycle through the derived rule was followed');
    expect(legStatus('/toners/'))->toBe(404);
});

it('sees a derived hop while walking a chain for a loop', function () {
    /*
     * RED ON THE OLD WALK. loops() used to step with claimed()+row(), so it
     * could only see STORED rows. A cycle whose middle hop is derived --
     * /leg-a/ (row) → /toners/ (derived) → /product-category/skincare/toners/
     * (row) → /leg-a/ -- was invisible to it and the visitor bounced.
     *
     * MUTATION NOTE, RUN: replacing self::step($next) with
     * `self::claimed($next) ? self::row($next) : null` inside loops() -- the
     * walk as it stood before this lane -- makes lookup() return the row
     * instead of null, and the visitor bounces -- 1 failed.
     */
    legTree();

    Redirect::query()->create([
        'source' => '/leg-a/', 'target' => '/toners/', 'code' => 301,
        'enabled' => true, 'auto_created' => false,
    ]);
    Redirect::query()->create([
        'source' => '/product-category/skincare/toners/', 'target' => '/leg-a/',
        'code' => 301, 'enabled' => true, 'auto_created' => false,
    ]);

    expect(CheckRedirects::lookup('/leg-a/'))->toBeNull('a cycle with a derived hop in the middle was followed');
});

it('does not try to count a hit against a row that does not exist', function () {
    /*
     * derived() answers with an UNSAVED model, so its key is null. Without the
     * guard in recordHit() the UPDATE would run `where('id', null)` -- matching
     * nothing in SQLite and in MySQL alike, so nothing would look broken while
     * a write query was spent on every hit of an old address for ever.
     *
     * MUTATION NOTE, RUN: removing the `! $redirect->exists` guard from
     * recordHit() takes the write count from 0 to 1 -- 1 failed.
     */
    legTree();

    $writes = 0;
    DB::listen(function ($q) use (&$writes) {
        if (str_starts_with(strtolower(trim($q->sql)), 'update')) {
            $writes++;
        }
    });

    expect(legStatus('/toners/'))->toBe(301);

    expect($writes)->toBe(0, 'a derived redirect spent a write query on a hit counter it has no row for');
});

// ---------------------------------------------------------------------------
// 7. Cost
// ---------------------------------------------------------------------------

it('costs a warm storefront page nothing at all', function () {
    /*
     * CheckRedirects runs on EVERY request. The in_array() against the fifteen
     * literals in PATHS answers in memory and answers first, so no storefront
     * URL ever reaches a query through the derived rule.
     *
     * MUTATION NOTE, RUN: dropping the isLegacy() guard from landingPath(),
     * so every path in the shop is resolved against the categories table,
     * takes a warm page off zero and takes several other blocks with it --
     * 7 failed across the file.
     */
    legTree();

    Product::query()->create([
        'name' => 'Budget Serum', 'slug' => 'budget-serum', 'sku' => 'LEG-2', 'status' => 'publish',
        'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock', 'image' => 'https://cdn.test/a.jpg',
    ]);

    // Warm the caches the first request builds, exactly as the budget test does.
    test()->get('/shop/');

    foreach (['/shop/', '/product-category/skincare/toners/', '/product/budget-serum/'] as $page) {
        $seen = 0;
        DB::listen(function ($q) use (&$seen) {
            if (str_contains(strtolower($q->sql), 'from "redirects"') || str_contains(strtolower($q->sql), 'from "categories"')) {
                $seen++;
            }
        });

        $before = $seen;
        test()->get($page);

        // Categories are queried by the page itself; what must be zero is the
        // REDIRECTS table, which is what this middleware owns.
        $redirectQueries = 0;
        DB::listen(function ($q) use (&$redirectQueries) {
            if (str_contains(strtolower($q->sql), 'from "redirects"')) {
                $redirectQueries++;
            }
        });

        test()->get($page);

        expect($redirectQueries)->toBe(0, $page . ' queried the redirects table on a warm page');
    }
});

// ---------------------------------------------------------------------------
// 8. Arabic
// ---------------------------------------------------------------------------

it('keeps an Arabic visitor in Arabic, with the prefix exactly once', function () {
    /*
     * THE THREE WAYS THIS GOES WRONG, and each is a defect in its own right:
     * the prefix DROPPED (an Arabic reader following an old link is dumped on
     * the English page), the prefix DUPLICATED (/ar/ar/…, a 404), or the row
     * not matching at all (/ar/toners/ 404s while /toners/ works).
     *
     * The rule, established by measuring the pipeline rather than reasoning
     * about it: SetLocaleFromPath runs BEFORE CheckRedirects in the global
     * stack (AppServiceProvider prepends CheckRedirects, then
     * SetLocaleFromPath, then CanonicalHost -- prepend order is the reverse of
     * execution order, so execution is CanonicalHost → SetLocaleFromPath →
     * CheckRedirects). So /ar is off the path before the fifteen are matched,
     * and LegacyCategoryUrls::PATHS needs no Arabic spelling. Url::redirect()
     * → Url::to() then puts the segment back on the way out. ONE list, BOTH
     * languages, and the ordering is asserted below rather than assumed.
     *
     * RED WITHOUT THE FIX: 404.
     *
     * MUTATION NOTE, RUN: returning the bare target from
     * CheckRedirects::handle() instead of Url::redirect($redirect->target)
     * drops BOTH the /ar segment and the trailing slash -- 7 failed across the
     * file, and the Arabic assertions here are among them. That is the exact
     * defect docs/GP-ADDRESSES-LAND.md §5.3 measured on a running server.
     */
    legTree();
    legArabicOn();

    expect(legLocation('/ar/toners/'))->toBe('http://localhost/ar/product-category/skincare/toners/');
    expect(legLocation('/ar/toners'))->toBe('http://localhost/ar/product-category/skincare/toners/');
    expect(legLocation('/ar/skincare-sets/'))->toBe('http://localhost/ar/product-category/skincare-sets/');

    // The English answer is unchanged by Arabic being switched on.
    expect(legLocation('/toners/'))->toBe('http://localhost/product-category/skincare/toners/');

    // And the destination is a real Arabic page, not a second redirect.
    expect(legStatus('/ar/product-category/skincare/toners/'))->toBe(200);
});

it('keeps the locale on an Arabic address whose category does not exist as a 404, not an English page', function () {
    // The failure to avoid: /ar/lip-care/ quietly becoming an English 404 page
    // or, worse, an English archive. No category means no destination in EITHER
    // language.
    legTree();
    legArabicOn();

    expect(legStatus('/ar/lip-care/'))->toBe(404);
    expect(legLocation('/ar/lip-care/'))->toBeNull();
});

it('runs the locale middleware before the redirect check, which is what makes one list serve both languages', function () {
    /*
     * Measured against the real kernel rather than reasoned about, because the
     * ordering is the thing that would silently invert on a Laravel upgrade or
     * a careless prepend. If CheckRedirects ran FIRST it would see
     * "/ar/toners/", which is in no list and matches no row, and every Arabic
     * legacy address would 404 while its English twin worked.
     */
    $stack = app(\Illuminate\Contracts\Http\Kernel::class);
    $ref = new ReflectionClass($stack);
    $prop = $ref->getProperty('middleware');
    $prop->setAccessible(true);
    $global = $prop->getValue($stack);

    $locale = array_search(\App\Http\Middleware\SetLocaleFromPath::class, $global, true);
    $redirects = array_search(CheckRedirects::class, $global, true);

    expect($locale)->not->toBeFalse('SetLocaleFromPath is not in the global stack');
    expect($redirects)->not->toBeFalse('CheckRedirects is not in the global stack');
    expect($locale)->toBeLessThan($redirects, 'CheckRedirects runs before the locale is stripped, so /ar/toners/ can never match');
});

// ---------------------------------------------------------------------------
// 9. Nothing that already works may change
// ---------------------------------------------------------------------------

it('leaves the ten shipped journal redirects exactly as they were', function () {
    /*
     * The pin for rule 7. These ten rows are the entire redirects table as it
     * ships -- five articles, both slash spellings -- and they were measured
     * at 301 with these exact Location headers before this lane touched
     * anything.
     */
    $rows = Redirect::query()->orderBy('source')->get();

    expect($rows)->toHaveCount(10, 'the shipped redirects table is no longer ten rows');

    foreach ($rows as $row) {
        expect(str_starts_with((string) $row->source, '/blog/'))->toBeTrue('a shipped row is not a journal row');
        expect($row->code)->toBe(301);

        $location = legLocation((string) $row->source);

        expect($location)->toBe('http://localhost' . $row->target, (string) $row->source . ' no longer lands where it did');
    }
});

it('leaves every address that is not one of the fifteen untouched', function () {
    legTree();

    Product::query()->create([
        'name' => 'Untouched', 'slug' => 'untouched', 'sku' => 'LEG-3', 'status' => 'publish',
        'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock', 'image' => 'https://cdn.test/a.jpg',
    ]);

    test()->get('/shop/')->assertStatus(200);
    test()->get('/product/untouched/')->assertStatus(200);
    test()->get('/product-category/skincare/toners/')->assertStatus(200);
    test()->get('/skincare-guide/')->assertStatus(200);

    // The curated listings that ARE flat root addresses and are deliberately
    // not in the list -- rewriting one of these would break a live page.
    foreach (['/new-in', '/best-sellers', '/super-sale', '/everything-under-54-aed'] as $collection) {
        expect(LegacyCategoryUrls::isLegacy($collection))->toBeFalse($collection . ' has been pulled into the legacy list');
        test()->get($collection)->assertStatus(200);
    }
});

// ---------------------------------------------------------------------------
// 10. The audit and the middleware answer the same question the same way
// ---------------------------------------------------------------------------

it('makes the batched resolver agree with the per-path one on all fifteen', function () {
    /*
     * TWO READERS OF ONE DECISION, WHICH IS AN ARRANGEMENT THIS REPOSITORY HAS
     * HAD TO FIX BEFORE. CheckRedirects asks landingPath() per request;
     * SeoAudit asks landingPaths() for all fifteen at once, because its own
     * comment fixes a one-query budget that fifteen separate calls would blow.
     * Two copies of "where does this old address go" that can answer
     * differently is a shop that redirects while its audit says it does not.
     * docs/GP-ADDRESSES-LAND.md §13.6 is the same problem between CanonicalHost
     * and CheckRedirects, and the same answer: assert them against EACH OTHER,
     * never against a literal.
     *
     * The fixture deliberately covers all four shapes at once: a nested
     * category, a root category, a slug with no category, and a slug an
     * article has taken.
     *
     * MUTATION NOTE, RUN: making landingPaths() skip its published-post check
     * makes this red on /hair-care/ -- 1 failed.
     */
    legTree();
    legCategory('hair-care', 'Hair Care', null, 'hair-care', 0);
    Post::query()->create([
        'title' => 'Hair Care', 'slug' => 'hair-care', 'status' => 'published', 'body' => 'x',
    ]);

    $batch = LegacyCategoryUrls::landingPaths();

    foreach (LegacyCategoryUrls::PATHS as $path) {
        expect($batch[$path] ?? null)->toBe(
            LegacyCategoryUrls::landingPath($path),
            $path . ' is answered differently by the audit and by the middleware'
        );
    }

    // And the fixture really did exercise all four shapes, so the loop above
    // cannot pass by comparing fifteen nulls.
    expect($batch['/toners/'])->toBe('/product-category/skincare/toners/');
    expect($batch['/skincare-sets/'])->toBe('/product-category/skincare-sets/');
    expect($batch['/lip-care/'])->toBeNull();
    expect($batch['/hair-care/'])->toBeNull();
});

it('keeps the audit to a bounded number of queries however many paths there are', function () {
    /*
     * SeoAudit::scanLegacyAddresses()'s own comment sets this budget: "ONE
     * query, thirty bound values, two columns, however many legacy paths there
     * are. Not one query per path -- this file is read on an admin screen that
     * already scans the whole catalogue and it does not need a fifteen-query
     * loop on top."
     *
     * Asking landingPath() fifteen times would have made it thirty to
     * forty-five and nobody would have noticed, because the screen would still
     * work. This is the line that notices.
     *
     * WHAT THIS PINS AND WHAT IT DOES NOT. It measures landingPaths() itself,
     * which is the thing with a budget; that SeoAudit calls it once rather
     * than fifteen times is a single line in scanLegacyAddresses() and is kept
     * honest by the drift test above, which would fail the moment the two
     * answers parted company.
     *
     * MUTATION NOTE, RUN: rewriting landingPaths() as a loop over
     * landingPath() -- the obvious and wrong implementation -- takes the count
     * from 3 to 41 and this is red -- 1 failed.
     */
    legTree();

    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });

    LegacyCategoryUrls::landingPaths();

    // Measured at 3 on this fixture (posts, categories, category_redirects).
    // The ceiling is 4 so a fourth batched query is allowed without a test
    // edit; a per-path implementation measures 41 and cannot hide under it.
    expect($count)->toBeLessThanOrEqual(4, 'the batched resolver is querying per path');
});
