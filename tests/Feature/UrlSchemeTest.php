<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Models\Redirect;
use App\Http\Middleware\CheckRedirects;
use App\Support\UrlScheme;
use Illuminate\Support\Facades\DB;

/**
 * The address scheme: the four shapes it serves, and the promise that every
 * address it retired reaches its final home in ONE hop.
 *
 * ── WHAT THIS IS FOR ────────────────────────────────────────────────────────
 *
 *   /product-category/{path}/   ->  /collections/{path}/
 *   (a query string on /shop/)  ->  /brands/ and /brands/{slug}/
 *   /product/{slug}/            ->  UNCHANGED
 *   /blog/, /{slug}/  ->  /blog/ and /blog/{slug}/
 *
 * ── THE ASSERTION THAT MATTERS MOST IS `hops()` ─────────────────────────────
 *
 * A URL move is easy to get RIGHT in a way that is still wrong: point the old
 * base at the new base and let the shop's own canonicalisation finish the job.
 * `/product-category/toners/` -> `/collections/toners/` -> (the archive's own
 * 301) -> `/collections/skincare/toners/` is two hops and a browser follows it
 * happily, which is exactly why nobody notices. Google follows a chain
 * grudgingly and consolidates the ranking onto neither end.
 *
 * So every legacy assertion below goes through hops(), which FOLLOWS the chain
 * to its 200 and counts the redirects on the way. Asserting the Location header
 * alone would pass on a two-hop answer.
 */

/**
 * Fetch a path EXACTLY as spelled, trailing slash and all.
 *
 * ── NOT $this->get(), AND THIS COST AN AFTERNOON ────────────────────────────
 *
 * `MakesHttpRequests::prepareUrlForRequest()` does `trim($uri, '/')` before it
 * builds the request, so `$this->get('/old-toner-page/')` asks the application
 * for `/old-toner-page` — WITHOUT the trailing slash. Routing does not care,
 * because Laravel normalises a registered URI the same way, so every
 * route-based assertion in this file passed against it.
 *
 * `redirects.source` does care. `CheckRedirects::findMatch()` compares against
 * `getPathInfo()` with a plain equality, which is the whole reason U-01 and the
 * seeded rows are so careful about the slash — so a row stored for `/foo/` was
 * simply never offered the spelling it matches, and three redirect assertions
 * read "the row did not fire" when what had happened is that the test never
 * sent the address the row is for.
 *
 * tests/Feature/RedirectMiddlewareTest.php's own `rmFetch()` is this, for this
 * reason, and this follows it.
 */
function schemeFetch(string $path): \Symfony\Component\HttpFoundation\Response
{
    return app(\Illuminate\Contracts\Http\Kernel::class)->handle(
        \Illuminate\Http\Request::create('http://localhost'.$path, 'GET'),
    );
}

/**
 * Follow a path to its first non-redirect and report the trail.
 *
 * @return array{hops: int, final: string, status: int, trail: list<string>}
 */
function schemeHops(\Tests\TestCase $test, string $path): array
{
    $trail = [];
    $hops = 0;

    while ($hops < 10) {
        $response = schemeFetch($path);

        if (! in_array($response->getStatusCode(), [301, 302, 307, 308], true)) {
            return ['hops' => $hops, 'final' => $path, 'status' => $response->getStatusCode(), 'trail' => $trail];
        }

        $location = (string) $response->headers->get('Location');
        $next = (string) (parse_url($location, PHP_URL_PATH) ?: $location);
        $query = (string) (parse_url($location, PHP_URL_QUERY) ?: '');

        $trail[] = $next.($query === '' ? '' : '?'.$query);
        $path = $next.($query === '' ? '' : '?'.$query);
        $hops++;
    }

    return ['hops' => $hops, 'final' => $path, 'status' => 0, 'trail' => $trail];
}

beforeEach(function () {
    // The index CheckRedirects builds is a cache entry, and the migration set
    // has already filled it before any row this file writes exists. Every
    // redirect test in this repository drops it first, for the same reason.
    CheckRedirects::flushIndex();

    // A nested category, because a FLAT one cannot tell a one-hop redirect from
    // a two-hop one: /collections/toners/ is already canonical for a top-level
    // `toners`, so the chained answer and the correct answer are the same
    // string. The nesting is what makes the count mean something.
    // updateOrCreate throughout: DemoCatalogueSeeder runs on every install,
    // production included, and already carries a flat `toners`. Re-parenting it
    // is the fixture; a second row with the same slug is a unique violation.
    $this->skincare = Category::updateOrCreate(
        ['slug' => 'skincare'],
        ['name' => 'Skincare', 'parent_id' => null, 'path' => 'skincare', 'depth' => 0],
    );
    $this->toners = Category::updateOrCreate(
        ['slug' => 'toners'],
        ['name' => 'Toners', 'parent_id' => $this->skincare->id, 'path' => 'skincare/toners', 'depth' => 1],
    );

    $this->brand = Brand::updateOrCreate(['slug' => 'round-lab'], ['name' => 'Round Lab']);

    $this->product = Product::updateOrCreate(
        ['slug' => 'dokdo-toner'],
        [
            'name' => 'Dokdo Toner', 'price' => 6900,
            'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
            'brand_id' => $this->brand->id,
        ],
    );

    $this->post = Post::updateOrCreate(
        ['slug' => 'heartleaf-extract-transforming-k-beauty-skincare'],
        [
            'title' => 'Heartleaf extract: transforming K-beauty skincare',
            'excerpt' => 'Why houttuynia cordata turns up in every calming serum.',
            'body' => '<p>Heartleaf is the quiet workhorse of a calming routine.</p>',
            'tag' => 'Ingredients', 'status' => 'published', 'published_at' => now()->subDay(),
        ],
    );
});

/* ═══════════════════════════════════════════ the four shapes are served ══ */

it('serves a category archive at its collections address', function () {
    /*
     * MUTATION NOTE. Point CategoryPath::archivePath() back at
     * '/product-category/' . $path . '/' and this is red: the route registered
     * in routes/kbb-brands-blog.php is /collections/{path}, so nothing answers
     * here and the canonical assertion below has nothing to read.
     */
    $html = $this->get('/collections/skincare/toners/')->assertOk()->getContent();

    expect(str_contains($html, 'rel="canonical"'))->toBeTrue('the archive published no canonical at all');
    expect(str_contains($html, '/collections/skincare/toners/'))
        ->toBeTrue('the archive does not name its own new address anywhere in the document');
    expect(str_contains($html, '/product-category/'))
        ->toBeFalse('the archive still publishes the retired category base — a canonical or a link '
            .'pointing at an address the shop 301s away from is worse than not moving at all');
});

it('serves the brand directory and a brand page at /brands/', function () {
    $this->get('/brands/')->assertOk()->assertSee('<span class="brw-name">Round Lab', escape: false);
    // The heading, not the <title> / og:title / CTA / JSON-LD copies of it.
    // Lane BR2: the Panel header's <h1>, the default brand header.
    $this->get('/brands/round-lab/')->assertOk()->assertSee('<h1 class="brw-ph__name" id="brw-ph-title">Round Lab</h1>', escape: false);
});

it('serves the journal and an article under /blog/', function () {
    $this->get('/blog/')->assertOk()->assertSee('Heartleaf extract: transforming K-beauty skincare');
    $this->get('/blog/heartleaf-extract-transforming-k-beauty-skincare/')
        ->assertOk()
        ->assertSee('Heartleaf is the quiet workhorse of a calming routine.', escape: false);
});

it('leaves the product address exactly where it was', function () {
    /*
     * THE ONE SHAPE THAT DOES NOT MOVE, and the reason the scheme is worth
     * having: a product page is a DETAIL page and /product/{slug}/ is already
     * the singular form the research says a detail page wants. The largest and
     * most valuable set of addresses this shop owns therefore needs no redirect
     * at all.
     *
     * MUTATION NOTE. Change UrlScheme::PRODUCT_BASE to '/products/' and this is
     * red on the second expectation — 200 becomes a 404 — which is the proof
     * that this test would have caught the catalogue moving by accident.
     */
    expect(UrlScheme::product('dokdo-toner'))->toBe('/product/dokdo-toner/');

    $this->get('/product/dokdo-toner/')->assertOk();

    // And it is not redirected by anything: zero hops, not "one hop that lands
    // back here", which a bad redirect row would also produce.
    expect(schemeHops($this, '/product/dokdo-toner/')['hops'])
        ->toBe(0, 'the product address is being redirected, and the scheme says it does not move');
});

/* ══════════════════════════════════ every old address, in exactly one hop ══ */

it('sends every retired address to its final home in one hop', function () {
    $cases = [
        // The category archive. The destination is the CANONICAL nested path,
        // not /collections/toners/, which would chain through the archive's own
        // 301 for every category that has a parent.
        '/product-category/toners/' => '/collections/skincare/toners/',
        '/product-category/skincare/toners/' => '/collections/skincare/toners/',
        // The flat WooCommerce root address, which is what kbeautybliss.com
        // really served (category_base was the empty string). Derived by
        // CheckRedirects from LegacyCategoryUrls, with no row in the table.
        '/toners/' => '/collections/skincare/toners/',
        // Brands, both retired spellings, neither via the other.
        '/korean-skincare-brands/' => '/brands/',
        '/korean-skincare-brands/round-lab/' => '/brands/round-lab/',
        '/brand/round-lab/' => '/brands/round-lab/',
        // The journal, its two retired article prefixes, and the article's
        // WordPress address at the site root.
        '/skincare-guide/' => '/blog/',
        '/skincare-guide/heartleaf-extract-transforming-k-beauty-skincare/'
            => '/blog/heartleaf-extract-transforming-k-beauty-skincare/',
        '/post/heartleaf-extract-transforming-k-beauty-skincare'
            => '/blog/heartleaf-extract-transforming-k-beauty-skincare/',
        '/heartleaf-extract-transforming-k-beauty-skincare/'
            => '/blog/heartleaf-extract-transforming-k-beauty-skincare/',
    ];

    foreach ($cases as $from => $expected) {
        $walk = schemeHops($this, $from);

        expect($walk['hops'])->toBe(1, $from.' took '.$walk['hops'].' hops: '
            .implode(' -> ', $walk['trail']).'. One move, never two.');
        expect($walk['final'])->toBe($expected, $from.' landed on '.$walk['final'].', not '.$expected);
        expect($walk['status'])->toBe(200, $from.' landed on a '.$walk['status'].', not a page');
    }
});

it('does not redirect a retired address that names nothing', function () {
    /*
     * A blanket 301 from every retired base to the new one would make the whole
     * old namespace a soft 404 wearing a 301 — an unbounded crawlable space,
     * which is the defect CategoryArchiveController was written to end.
     *
     * MUTATION NOTE. Replace CategoryArchiveController::show()'s abort_if with
     * a redirect to UrlScheme::collection($path) and the first line is red: the
     * response is a 301 rather than a 404.
     */
    $this->get('/product-category/nothing-like-this/')->assertNotFound();
    $this->get('/brand/no-such-brand/')->assertNotFound();
    $this->get('/blog/no-such-article/')->assertNotFound();
    $this->get('/no-such-article-at-all/')->assertNotFound();
});

/* ══════════════════════════════════ the canonicals and the crawl surface ══ */

it('names only the new addresses in the sitemap', function () {
    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    foreach ([
        '/collections/skincare/toners/',
        '/brands/',
        '/brands/round-lab/',
        '/blog/',
        '/blog/heartleaf-extract-transforming-k-beauty-skincare/',
        '/product/dokdo-toner/',
    ] as $wanted) {
        expect(str_contains($xml, $wanted))->toBeTrue('the sitemap does not list '.$wanted);
    }

    /*
     * AND NOT THE OLD ONES. Submitting a redirect asks Google to fetch a URL it
     * is then sent away from, which is the defect this file was corrected for
     * twice before.
     *
     * MUTATION NOTE. Put '/blog/' back into sitemap() and this is red.
     */
    foreach ([
        '/product-category/',
        '/korean-skincare-brands/',
        '/skincare-guide/',
    ] as $retired) {
        expect(str_contains($xml, $retired))->toBeFalse('the sitemap still submits '.$retired
            .', which is an address the shop 301s away from');
    }

    // The article's WordPress address is a 301 too, and listing it would be the
    // same defect in the one shape a substring check cannot see — the slug on
    // its own at the site root.
    expect(str_contains($xml, '<loc>http://localhost/heartleaf-extract-transforming-k-beauty-skincare/</loc>'))
        ->toBeFalse('the sitemap still submits the article at the site root, which now 301s');
});

it('canonicalises the article and the brand page to their new addresses', function () {
    $article = $this->get('/blog/heartleaf-extract-transforming-k-beauty-skincare/')->assertOk()->getContent();

    expect(str_contains($article, '/blog/heartleaf-extract-transforming-k-beauty-skincare/'))
        ->toBeTrue('the article does not name its own new address');
    expect(str_contains($article, 'rel="canonical" href="http://localhost/heartleaf-extract-transforming-k-beauty-skincare/"'))
        ->toBeFalse('the article still canonicalises to the site-root address it 301s away from');

    $brand = $this->get('/brands/round-lab/')->assertOk()->getContent();

    expect(str_contains($brand, '/brands/round-lab/'))->toBeTrue('the brand page does not name its own address');
    expect(str_contains($brand, '/korean-skincare-brands/'))
        ->toBeFalse('the brand page still publishes the retired directory address');
});

it('publishes the brand page as a brand url rather than a shop filter', function () {
    /*
     * THE BIGGEST WIN IN THE SCHEME, and the one that is new work rather than a
     * rename. Brand::url() returned /shop/?filter_brands={slug} — not an
     * indexable page — so a brand archive arriving from the old WordPress
     * install was pointed at a listing that canonicalises to /shop/, telling
     * Google the brand page does not exist.
     *
     * MUTATION NOTE. Point Brand::url() back at Url::to('/shop/') .
     * '?filter_brands=' and the first expectation is red.
     */
    expect($this->brand->url())->toBe('/brands/round-lab/');

    // U-05 is untouched: the filterable LISTING is still the query string, and
    // the landing page links onward to it.
    expect($this->brand->filterUrl())->toBe('/shop/?filter_brands=round-lab');
    // 2.60.376: the link is the "Shop all" button, which ships off because the
    // owner asked; with it on, it is still the U-05 listing.
    app(\App\Services\SiteLayout::class)->save(['brand_cta' => true]);
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();
    expect(str_contains($this->get('/brands/round-lab/')->getContent(), '/shop/?filter_brands=round-lab'))
        ->toBeTrue('the brand page no longer links on to its filterable listing');
});

/* ═════════════════════════════════════ the rows, and the loop they avoid ══ */

it('does not leave a stored row looping through the new article address', function () {
    /*
     * ── THE DEFECT THIS WOULD HAVE SHIPPED ─────────────────────────────────
     *
     * 2026_09_14_160000_seed_phase9_post_url_redirects seeded ten rows shaped
     * /blog/{slug}/ -> /{slug}/, from when /blog/ was a dead prefix. /blog/…
     * is the article's canonical address now and CheckRedirects runs BEFORE the
     * router, so the row sends the canonical address to the site root and
     * PageController::rootArticle() sends it straight back:
     *
     *     /blog/x/ --301(row)--> /x/ --301(route)--> /blog/x/ -- for ever
     *
     * CheckRedirects::loops() cannot see it: it walks the TABLE, and the second
     * hop is a route.
     *
     * MUTATION NOTE. Delete deleteSeededArticleLoops() from
     * 2027_04_05_000100_url_scheme_redirect_rows and this test is red with
     * hops = 10 — the walk gives up rather than reaching a page.
     */
    $slug = 'heartleaf-extract-transforming-k-beauty-skincare';

    // Exactly the row the seed writes, re-created here because the migration
    // set has already run and removed it.
    Redirect::create([
        'source' => '/blog/'.$slug.'/',
        'target' => '/'.$slug.'/',
        'code' => 301, 'enabled' => true, 'auto_created' => false,
    ]);

    (require base_path('database/migrations/2027_04_05_000100_url_scheme_redirect_rows.php'))->up();

    expect(Redirect::query()->where('source', '/blog/'.$slug.'/')->exists())
        ->toBeFalse('the looping row survived the migration');

    $walk = schemeHops($this, '/blog/'.$slug.'/');

    expect($walk['hops'])->toBe(0, 'the article canonical redirects: '.implode(' -> ', $walk['trail']));
    expect($walk['status'])->toBe(200);
});

it('collapses a stored row that would chain through a moved address', function () {
    /*
     * A row pointing at an address the scheme moved still resolves — through a
     * SECOND hop. RedirectManager collapses chains at write time for exactly
     * this reason, and a migration that moves destinations without collapsing
     * what points at them writes the chain it exists to prevent.
     *
     * MUTATION NOTE. Delete repointPrefix() from the migration and both
     * expectations are red: the first because the target is unchanged, the
     * second because the walk takes two hops.
     */
    Redirect::create([
        'source' => '/old-toner-page/',
        'target' => '/product-category/skincare/toners/',
        'code' => 301, 'enabled' => true, 'auto_created' => true,
    ]);
    Redirect::create([
        'source' => '/our-brands/',
        'target' => '/korean-skincare-brands/',
        'code' => 301, 'enabled' => true, 'auto_created' => true,
    ]);
    Redirect::create([
        'source' => '/guide/',
        'target' => '/skincare-guide/',
        'code' => 301, 'enabled' => true, 'auto_created' => true,
    ]);

    (require base_path('database/migrations/2027_04_05_000100_url_scheme_redirect_rows.php'))->up();

    expect(Redirect::query()->where('source', '/old-toner-page/')->value('target'))
        ->toBe('/collections/skincare/toners/');
    expect(Redirect::query()->where('source', '/our-brands/')->value('target'))->toBe('/brands/');
    expect(Redirect::query()->where('source', '/guide/')->value('target'))->toBe('/blog/');

    foreach (['/old-toner-page/', '/our-brands/', '/guide/'] as $source) {
        $walk = schemeHops($this, $source);

        expect($walk['hops'])->toBe(1, $source.' chained: '.implode(' -> ', $walk['trail']));
        expect($walk['status'])->toBe(200, $source.' ended on a '.$walk['status']);
    }
});

it('leaves a root-slug row alone when no article carries the slug', function () {
    /*
     * The migration owns DESTINATIONS the scheme moved. `/about-us/` looks
     * exactly like an article address and is a WordPress page; rewriting it to
     * /blog/about-us/ would send a visitor to a 404.
     *
     * MUTATION NOTE. Change articleSlugs() to select every `posts` row rather
     * than only published ones, or drop its `posts` join and derive the slug
     * from the path, and this is red.
     */
    Redirect::create([
        'source' => '/old-about/',
        'target' => '/about-us/',
        'code' => 301, 'enabled' => true, 'auto_created' => false,
    ]);

    (require base_path('database/migrations/2027_04_05_000100_url_scheme_redirect_rows.php'))->up();

    expect(Redirect::query()->where('source', '/old-about/')->value('target'))->toBe('/about-us/');
});

it('is idempotent — a second run of the row migration changes nothing', function () {
    Redirect::create([
        'source' => '/old-toner-page/',
        'target' => '/product-category/skincare/toners/',
        'code' => 301, 'enabled' => true, 'auto_created' => true,
    ]);

    $migration = require base_path('database/migrations/2027_04_05_000100_url_scheme_redirect_rows.php');

    $migration->up();
    $after = DB::table('redirects')->orderBy('id')->get(['source', 'target'])->toArray();

    $migration->up();

    expect(DB::table('redirects')->orderBy('id')->get(['source', 'target'])->toArray())->toEqual($after);
});

/* ═══════════════════════════════════════════════ the routes stay mounted ══ */

it('keeps the scheme\'s route file required from web.php exactly once', function () {
    /*
     * ── THE FINISHED STATE, NOT THE ABSENCE OF ONE ────────────────────────
     *
     * CLAUDE.md is explicit that a lane must not pin "my work is not wired up
     * yet": that assertion goes red the moment the integrator does the one
     * thing the lane asked for, and the only way to green it is to unmount the
     * feature. This pins the other end, which is also the thing that can
     * actually regress.
     *
     * ZERO is the "built, never wired up" shape this repository keeps finding.
     * TWO registers every route in the file twice, and for this file that is
     * not merely untidy: the last route matches a single path segment at the
     * site root, and Laravel keys its collection on method+uri, so a second
     * registration REPLACES the first — the duplicate would silently decide
     * which copy of every scheme route the shop serves.
     *
     * The address scheme needs no OTHER edit to web.php, which is why there is
     * only one line to count: the two registrations that point at this lane's
     * controllers (/product-category/{path} and /skincare-guide/) are correct
     * as they stand, because the controller METHODS were renamed rather than
     * the routes. routes/kbb-brands-blog.php's header sets that out in full.
     */
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect(substr_count($web, "require __DIR__ . '/kbb-brands-blog.php';"))
        ->toBe(1, 'routes/web.php does not require the address scheme\'s route file exactly once');

    expect(substr_count($web, "require __DIR__ . '/kbb-journal-legacy.php';"))
        ->toBe(1, 'routes/web.php does not require the retired journal prefix exactly once');
});

it('registers each scheme address exactly once in the real router', function () {
    /*
     * The same invariant read off the ROUTER rather than off the file, because
     * a second registration of one of these URIs from anywhere at all — another
     * lane's route file, a stray require — is invisible in web.php and decides
     * which controller answers.
     *
     * MUTATION NOTE. Require routes/kbb-brands-blog.php a second time in a test
     * and this stays green (Laravel replaces rather than appends), while the
     * substr_count pin above goes red. The two together are what cover it,
     * which is why both are here.
     */
    $wanted = [
        'collections/{path}' => 'CategoryArchiveController@collection',
        'product-category/{path}' => 'CategoryArchiveController@show',
        'brands' => 'BrandController@index',
        'brands/{slug}' => 'BrandController@show',
        'korean-skincare-brands' => 'BrandController@legacyIndex',
        'blog' => 'PageController@journal',
        'blog/{slug}' => 'PageController@post',
        'skincare-guide' => 'PageController@blog',
        '{slug}' => 'PageController@rootArticle',
    ];

    $byUri = [];

    foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $byUri[$route->uri()][] = (string) $route->getActionName();
    }

    foreach ($wanted as $uri => $action) {
        expect($byUri)->toHaveKey($uri);
        expect(count($byUri[$uri]))->toBe(1, $uri . ' is registered ' . count($byUri[$uri] ?? []) . ' times');
        expect($byUri[$uri][0])->toContain($action);
    }
});
