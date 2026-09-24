<?php

declare(strict_types=1);

/**
 * Phase 13's other half — "Media and image paths · URL redirect map" — checked
 * against what the application actually does rather than against what the map
 * says it does.
 *
 * WHAT EACH BLOCK IS HERE TO CATCH, in the order the damage would land:
 *
 *  1. A REDIRECT THAT CANNOT FIRE. The redirects table is consulted ONLY from
 *     the 404 handler (`CheckRedirects` is written as middleware and is not
 *     registered as one — see its own comment, and `AppServiceProvider`). So a
 *     row whose address already answers 200, or which the application already
 *     301s by itself, is dead weight that looks like coverage. The entire
 *     `migrate` bucket of the shipped category rule was exactly that, which no
 *     test noticed because every test asked the map and none asked the shop.
 *
 *  2. A MAP AIMED AT THE WRONG URL SHAPE. kbeautybliss.com published its
 *     category archives FLAT AT THE SITE ROOT — `/toners/` — per
 *     `App\Support\LegacyCategoryUrls` and the seeded live navigation, and its
 *     articles the same way per the owner's own confirmation in
 *     `2026_09_14_160000_seed_phase9_post_url_redirects`. Nothing proposed a
 *     redirect for one of those addresses, and every one of them 404s.
 *
 *  3. "STILL ON THE OLD SITE" COUNTING THE SHOP'S OWN PHOTOGRAPHS. Every image
 *     picked from the Media Library is stored as an absolute URL, so a verdict
 *     that reads "has a host" as "is remote" reports a migration as unfinished
 *     for ever, on a shop that never ran WooCommerce at all.
 *
 *  4. A MEDIA REWRITE THAT RUNS TOO EARLY. Re-pointing a row at a local file
 *     that has not been copied across yet turns a page that renders into a page
 *     of broken frames, with nothing on the shop to say which rows were touched.
 *
 *  5. THE BASE PATH, in both directions. `redirects.source` is matched against
 *     `getPathInfo()`, which STRIPS `/kbb-upgrade`, and `products.image` is
 *     printed straight into a `src`, which does not. One column must never
 *     carry the prefix and the other must always carry it. A guard written
 *     against the unprefixed case only is the same bug twice.
 *
 * THESE RUN ON BOTH ENGINES. Nothing here is about an index or a dialect.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Models\Redirect;
use App\Services\Import\MediaAudit;
use App\Services\Import\MediaRewrite;
use App\Services\Import\RedirectMap;
use App\Services\Import\SourceReachability;
use App\Support\Url;
use Tests\Support\UrlsMediaAdminRoutes;

/* ------------------------------------------------------------------ set-up */

/** A real nested tree: skincare > face-cleansers. */
function gbTree(): array
{
    $parent = Category::query()->create([
        'name' => 'Skincare GB',
        'slug' => 'skincare-gb',
        'parent_id' => null,
    ]);
    $parent->forceFill(['path' => 'skincare-gb', 'depth' => 0])->save();

    $child = Category::query()->create([
        'name' => 'Face Cleansers GB',
        'slug' => 'face-cleansers-gb',
        'parent_id' => $parent->id,
    ]);
    $child->forceFill(['path' => 'skincare-gb/face-cleansers-gb', 'depth' => 1])->save();

    return [$parent, $child];
}

function gbProposalFor(string $source, array $proposals): ?array
{
    foreach ($proposals as $proposal) {
        if ($proposal['source'] === $source) {
            return $proposal;
        }
    }

    return null;
}

/** Point public_path() at a directory this test owns. */
function gbWebRoot(): string
{
    $root = storage_path('framework/testing/gb-webroot');

    if (! is_dir($root)) {
        mkdir($root, 0o777, true);
    }

    app()->usePublicPath($root);

    return $root;
}

function gbPutFile(string $relative): string
{
    $full = gbWebRoot().'/'.ltrim($relative, '/');

    if (! is_dir(dirname($full))) {
        mkdir(dirname($full), 0o777, true);
    }

    file_put_contents($full, 'not really a jpeg');

    return $full;
}

/**
 * Fetch an address the way a browser sends it, TRAILING SLASH AND ALL.
 *
 * $this->get() cannot do this. `MakesHttpRequests::prepareUrlForRequest()`
 * runs `trim($uri, '/')` before building the request, so every address the test
 * client fetches arrives with its trailing slash removed — and
 * `CheckRedirects::findMatch()` compares `source` against `getPathInfo()` with a
 * plain equality, which keeps whatever spelling the client really sent. A row
 * stored as `/toners/` therefore cannot be exercised through $this->get() at
 * all, and a suite written only with it would report the shipped map as working
 * while proving nothing about the form that is actually indexed (U-01 keeps the
 * slash; so does every row the Phase 9 seed wrote).
 *
 * So the request is built and handed to the kernel directly. Verified against a
 * running php -S server as well, which is where this was found: curl of
 * `/toners/` with a row in place answers 301, and the same fetch through the
 * test client answers 404.
 */
function gbFetch(string $path): \Symfony\Component\HttpFoundation\Response
{
    return app(\Illuminate\Contracts\Http\Kernel::class)->handle(
        \Illuminate\Http\Request::create('http://localhost'.$path, 'GET'),
    );
}

function gbAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'URL Owner',
        'email' => 'gb-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/* ============================================================== reachability */

it('reads the shop back, not the map: a nested category already 301s its own flat address', function () {
    [, $child] = gbTree();

    /*
     * This is the fact the whole lane turns on, and it is asserted through the
     * REAL ROUTER rather than by calling CategoryPath directly, because the
     * question is what a visitor gets.
     */
    $this->get('/product-category/'.$child->slug.'/')
        ->assertStatus(301)
        ->assertRedirectContains('/product-category/skincare-gb/face-cleansers-gb');

    expect((new SourceReachability)->verdict('/product-category/'.$child->slug.'/')['status'])
        ->toBe(SourceReachability::MOVED);
});

it('proves a redirect row for an address the shop already moves is never read', function () {
    [, $child] = gbTree();

    Redirect::query()->create([
        'source' => '/product-category/'.$child->slug.'/',
        'target' => '/a-place-no-row-should-reach/',
        'code' => 301,
        'enabled' => true,
        'auto_created' => true,
    ]);

    /*
     * The row exists, is enabled, and matches getPathInfo() exactly. It still
     * changes nothing, because CategoryArchiveController answers first and the
     * 404 handler — the only place the table is consulted — is never reached.
     */
    $response = $this->get('/product-category/'.$child->slug.'/');

    $response->assertStatus(301);

    expect($response->headers->get('Location'))
        ->toContain('/product-category/skincare-gb/face-cleansers-gb')
        ->not->toContain('a-place-no-row-should-reach');
});

it('proves a redirect row for an address that 404s IS read', function () {
    [, $child] = gbTree();

    // The shape kbeautybliss.com really served: flat, at the root.
    $this->get('/'.$child->slug.'/')->assertStatus(404);

    Redirect::query()->create([
        'source' => '/'.$child->slug.'/',
        'target' => '/product-category/skincare-gb/face-cleansers-gb/',
        'code' => 301,
        'enabled' => true,
        'auto_created' => true,
    ]);

    $moved = gbFetch('/'.$child->slug.'/');

    /*
     * NO TRAILING SLASH ON THE LOCATION, and that is not this lane's doing.
     * `CheckRedirects` hands the stored target to Laravel's `redirect()`, whose
     * UrlGenerator strips a trailing slash — the same behaviour
     * `docs/FQ-ROUTES.md` records for `route()`. So a row stored as
     * `/product-category/skincare-gb/face-cleansers-gb/` sends the visitor to
     * the slash-less spelling, which answers 200 and is not the canonical form
     * U-01 defines. Asserted as it really is rather than as it ought to be;
     * `docs/GB-MEDIA-AND-REDIRECTS.md` carries the one-line fix and why this
     * lane did not apply it to shared middleware.
     */
    expect($moved->getStatusCode())->toBe(301)
        ->and($moved->headers->get('Location'))
        ->toContain('/product-category/skincare-gb/face-cleansers-gb');
});

it('tells served, moved and not-found apart on the addresses that decide the map', function () {
    [, $child] = gbTree();

    $reach = new SourceReachability;

    expect($reach->verdict('/shop/')['status'])->toBe(SourceReachability::SERVED)
        ->and($reach->verdict('/product-category/skincare-gb/face-cleansers-gb/')['status'])
            ->toBe(SourceReachability::SERVED)
        ->and($reach->verdict('/product-category/'.$child->slug.'/')['status'])
            ->toBe(SourceReachability::MOVED)
        ->and($reach->verdict('/product-category/no-such-leaf-at-all/')['status'])
            ->toBe(SourceReachability::NOT_FOUND)
        ->and($reach->verdict('/'.$child->slug.'/')['status'])
            ->toBe(SourceReachability::NOT_FOUND);
});

it('calls a root address served once a post is published at it', function () {
    $reach = new SourceReachability;

    expect($reach->verdict('/gb-published-article/')['status'])->toBe(SourceReachability::NOT_FOUND);

    Post::query()->create([
        'title' => 'GB published article',
        'slug' => 'gb-published-article',
        'status' => 'published',
        'published_at' => now(),
    ]);

    /*
     * The interaction with the blog's root catch-all, stated as a test because
     * it is the one way a redirect this map writes can go quiet later: publish
     * an article at a slug a category used to hold and the article wins, with
     * the row still sitting in the table looking correct.
     */
    expect($reach->verdict('/gb-published-article/')['status'])->toBe(SourceReachability::SERVED);
});

it('reads a literal page route back off the pages table, not off the route alone', function () {
    /*
     * The seven WordPress pages are LITERAL routes carrying
     * `->defaults('slug', 'about')`, so `parameterNames()` is empty for them and
     * the obvious reading is "a route matches, therefore this is served". It
     * 404s all the same if the `pages` row has gone, and a redirect for it would
     * then have worked — so the branch that stops at the route would have put a
     * usable redirect into the ask bucket.
     *
     * Checked in both directions, because a branch that can only be observed in
     * one state is a branch that can be wrong in the other.
     */
    $reach = new SourceReachability;

    expect(\App\Models\Page::query()->where('slug', 'about')->where('status', 'published')->exists())->toBeTrue()
        ->and($reach->verdict('/about/')['status'])->toBe(SourceReachability::SERVED);

    \App\Models\Page::query()->where('slug', 'about')->delete();

    $verdict = $reach->verdict('/about/');

    expect($verdict['status'])->toBe(SourceReachability::NOT_FOUND)
        ->and($verdict['why'])->toContain('defaults to');

    // And the route still matches — this is not "the route went away".
    $this->get('/about/')->assertStatus(404);
});

/* ==================================================================== the map */

it('discards every category-nesting row, because the shop already serves that move itself', function () {
    gbTree();

    $proposals = (new RedirectMap)->propose();

    $nesting = array_values(array_filter(
        $proposals,
        static fn (array $p): bool => $p['rule'] === 'category-nesting',
    ));

    expect($nesting)->not->toBeEmpty();

    $migrating = array_values(array_filter(
        $nesting,
        static fn (array $p): bool => $p['decision'] === RedirectMap::MIGRATE,
    ));

    // array_column, not ->not->toContain(): toContain is variadic, so a
    // ->not->toContain($needle, $message) call passes vacuously.
    expect(array_column($migrating, 'source'))->toBe([]);

    $child = gbProposalFor('/product-category/face-cleansers-gb/', $proposals);

    /*
     * PIN ADVANCED TWICE, AND THE TEST'S OWN NAME HAS BEEN RIGHT ALL ALONG.
     *
     * FIRST it asserted DISCARD with a reason containing "404 handler" — right
     * while the redirects table was read only from the 404 handler:
     *
     *     ->and($child['decision'])->toBe(RedirectMap::DISCARD)
     *     ->and($child['reason'])->toContain('404 handler');
     *
     * THEN Lane GP registered CheckRedirects globally, a row began to fire, and
     * a silent discard became the dangerous answer, so it asked:
     *
     *     ->and($child['decision'])->toBe(RedirectMap::ASK)
     *     ->and($child['reason'])->toContain('consulted before the router');
     *
     * NOW IT DISCARDS AGAIN, on the ground neither earlier version had: the
     * shop sends this address to the SAME destination this rule proposes, so
     * there is no question in it. Lane SEO round 2 compares the two before
     * deciding, rather than reading "the shop already moves this" as an answer
     * in itself — docs/GP-ADDRESSES-LAND.md §13.7 left exactly that open.
     *
     * The ASK is still reachable and is still what this question is for: it
     * fires when the shop's destination and the proposal's DIFFER. See
     * tests/Feature/SeoImportSensesUrlsTest.php, which builds that out of a
     * category_redirects merge row.
     */
    expect($child)->not->toBeNull()
        ->and($child['decision'])->toBe(RedirectMap::DISCARD)
        ->and($child['reason'])->toContain('already sends this address to exactly this destination');
});

it('proposes the flat root address the old site really published, and it is one that 404s', function () {
    [$parent, $child] = gbTree();

    $proposals = (new RedirectMap)->propose();

    $flat = gbProposalFor('/face-cleansers-gb/', $proposals);

    expect($flat)->not->toBeNull()
        ->and($flat['decision'])->toBe(RedirectMap::MIGRATE)
        ->and($flat['rule'])->toBe('legacy-root-category')
        ->and($flat['target'])->toBe('/product-category/skincare-gb/face-cleansers-gb/');

    // Even the top-level one moves under this rule: /skincare-gb/ is not
    // /product-category/skincare-gb/, so it is a real redirect and not a loop.
    $top = gbProposalFor('/skincare-gb/', $proposals);

    expect($top)->not->toBeNull()
        ->and($top['decision'])->toBe(RedirectMap::MIGRATE)
        ->and($top['target'])->toBe('/product-category/'.$parent->slug.'/');

    // And the addresses it proposes really do 404 before it writes anything.
    $this->get('/'.$child->slug.'/')->assertStatus(404);
    $this->get('/'.$parent->slug.'/')->assertStatus(404);
});

it('marks the corroborated fifteen apart from the ones inferred from the same setting', function () {
    /*
     * `hair-care` is one of LegacyCategoryUrls::PATHS, copied off the live
     * navigation — and unlike `toners` it is NOT one of the six slugs
     * DemoCatalogueSeeder holds on every install, which would make this a
     * unique-constraint failure rather than a test.
     */
    $hair = Category::query()->create(['name' => 'Hair Care', 'slug' => 'hair-care', 'parent_id' => null]);
    $hair->forceFill(['path' => 'hair-care', 'depth' => 0])->save();

    [, $child] = gbTree();

    $proposals = (new RedirectMap)->propose();

    expect(gbProposalFor('/hair-care/', $proposals)['reason'])->toContain('LegacyCategoryUrls::PATHS')
        ->and(gbProposalFor('/'.$child->slug.'/', $proposals)['reason'])->toContain('inferred');
});

it('asks rather than writes when the old address is one this shop still serves', function () {
    /*
     * A category whose slug collides with a live storefront route.
     *
     * PIN ADVANCED, DELIBERATELY. This used to read "The row cannot work — the
     * redirect table is only read on a 404 — and pointing /shop/ elsewhere is a
     * routing change, not a redirect", and asserted a reason containing
     * 'routing change'. The row CAN work now: `CheckRedirects` is registered in
     * the global pipeline and runs before the router, so a row for /shop/ moves
     * /shop/.
     *
     * The DECISION is unchanged and that is the point — it was ASK before and
     * it is ASK now, for a better reason. Before, it asked because a row here
     * would do nothing. Now it asks because a row here would do something
     * rather large: 301 away a page that answers today. Same bucket, opposite
     * cause, and the owner is still the one who decides.
     */
    $shop = Category::query()->create(['name' => 'Shop GB', 'slug' => 'shop', 'parent_id' => null]);
    $shop->forceFill(['path' => 'shop', 'depth' => 0])->save();

    $proposals = (new RedirectMap)->propose();
    $clash = gbProposalFor('/shop/', $proposals);

    expect($clash)->not->toBeNull()
        ->and($clash['decision'])->toBe(RedirectMap::ASK)
        ->and($clash['reason'])->toContain('WILL move it');
});

it('never writes the base path into a redirect, in either column', function () {
    config(['kbb.base_path' => '/kbb-upgrade']);
    Url::forgetBase();

    gbTree();

    $proposals = (new RedirectMap)->propose();

    expect($proposals)->not->toBeEmpty();

    $offenders = [];

    foreach ($proposals as $proposal) {
        foreach (['source', 'target'] as $column) {
            if (str_contains($proposal[$column], 'kbb-upgrade')) {
                $offenders[] = $column.' '.$proposal[$column];
            }
        }
    }

    // str_contains and an explicit list, for the reason the header gives:
    // expect(...)->not->toContain($needle, $message) passes vacuously.
    expect($offenders)->toBe([]);

    expect(gbProposalFor('/face-cleansers-gb/', $proposals)['target'])
        ->toBe('/product-category/skincare-gb/face-cleansers-gb/');
});

it('writes, is idempotent, and rolls back only its own rows', function () {
    gbTree();

    $map = new RedirectMap;
    $proposals = $map->propose();

    $this->artisan('kbb:import-redirects --write')->assertExitCode(0);

    $written = Redirect::query()->where('auto_created', true)->count();
    $rows = Redirect::query()->orderBy('source')->get(['source', 'target'])->toArray();

    expect($written)->toBeGreaterThan(0);

    $this->artisan('kbb:import-redirects --write')->assertExitCode(0);

    expect(Redirect::query()->orderBy('source')->get(['source', 'target'])->toArray())->toBe($rows);

    // And the rows it wrote are the ones that actually fire — in BOTH
    // spellings, which is why the map proposes both.
    foreach (['/face-cleansers-gb/', '/face-cleansers-gb'] as $spelling) {
        $moved = gbFetch($spelling);

        expect($moved->getStatusCode())->toBe(301)
            ->and($moved->headers->get('Location'))
            ->toContain('/product-category/skincare-gb/face-cleansers-gb');
    }

    $this->artisan('kbb:import-redirects --rollback')->assertExitCode(0);

    expect(Redirect::query()->where('source', '/face-cleansers-gb/')->exists())->toBeFalse();
});

/* ================================================================== the media */

it('does not count this shop\'s own uploads as still being on the old site', function () {
    gbWebRoot();
    gbPutFile('uploads/products/own-photo.png');

    config(['app.url' => 'https://shop.example']);

    Product::query()->create([
        'name' => 'Own photo product',
        'slug' => 'gb-own-photo',
        'price' => 1000,
        'status' => 'publish',
        // Exactly what Media::urlFor() produces for an admin upload, which is
        // what ProductEditorApiController stores.
        'image' => 'https://shop.example/uploads/products/own-photo.png',
    ]);

    $audit = new MediaAudit;
    $rows = $audit->audit();

    $own = array_values(array_filter(
        $rows,
        static fn (array $r): bool => str_contains($r['url'], 'own-photo.png'),
    ));

    expect($own)->toHaveCount(1)
        ->and($own[0]['verdict'])->toBe(MediaAudit::PRESENT);

    expect($audit->summarise($rows)['remote'])->toBe(0);
});

it('still calls a genuinely foreign host remote', function () {
    gbWebRoot();
    config(['app.url' => 'https://shop.example']);

    Product::query()->create([
        'name' => 'Hot-linked product',
        'slug' => 'gb-hotlinked',
        'price' => 1000,
        'status' => 'publish',
        'image' => 'https://kbeautybliss.com/wp-content/uploads/2019/03/x.jpg',
    ]);

    $audit = new MediaAudit;
    $rows = array_values(array_filter(
        $audit->audit(),
        static fn (array $r): bool => str_contains($r['url'], 'kbeautybliss.com'),
    ));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['verdict'])->toBe(MediaAudit::REMOTE)
        ->and($rows[0]['reason'])->toContain('kbeautybliss.com');
});

it('finds this shop\'s own image even when site_url carries a subfolder', function () {
    $root = gbWebRoot();
    gbPutFile('uploads/products/subfolder-photo.png');

    config(['app.url' => 'https://shop.example/kbb-upgrade']);

    Product::query()->create([
        'name' => 'Subfolder product',
        'slug' => 'gb-subfolder',
        'price' => 1000,
        'status' => 'publish',
        'image' => 'https://shop.example/kbb-upgrade/uploads/products/subfolder-photo.png',
    ]);

    $rows = array_values(array_filter(
        (new MediaAudit)->audit(),
        static fn (array $r): bool => str_contains($r['url'], 'subfolder-photo.png'),
    ));

    /*
     * public_path() IS the subfolder on disk, so the URL's /kbb-upgrade has to
     * come back off before the file is looked for. Without that this reads
     * "missing" on every shop served from a subfolder, which is every shop this
     * application currently runs on.
     */
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['verdict'])->toBe(MediaAudit::PRESENT)
        ->and(is_file($root.'/uploads/products/subfolder-photo.png'))->toBeTrue();
});

it('refuses to re-point a row at a file that has not been copied across yet', function () {
    gbWebRoot();

    Product::query()->create([
        'name' => 'Not copied yet',
        'slug' => 'gb-not-copied',
        'price' => 1000,
        'status' => 'publish',
        'image' => 'https://kbeautybliss.com/wp-content/uploads/2020/01/absent.jpg',
    ]);

    $rewrite = new MediaRewrite;
    $proposals = $rewrite->propose(['kbeautybliss.com']);

    expect($proposals)->toHaveCount(1)
        ->and($proposals[0]['decision'])->toBe(MediaRewrite::ABSENT);

    expect($rewrite->apply($proposals))->toBe(0)
        ->and(Product::query()->where('slug', 'gb-not-copied')->value('image'))
        ->toBe('https://kbeautybliss.com/wp-content/uploads/2020/01/absent.jpg');
});

it('takes the catalogue off the old host once the uploads folder is there, and puts it back', function () {
    gbWebRoot();
    gbPutFile('wp-content/uploads/2019/03/main.jpg');
    gbPutFile('wp-content/uploads/2019/03/gallery.jpg');
    gbPutFile('wp-content/uploads/2019/03/logo.png');

    $product = Product::query()->create([
        'name' => 'Imported product',
        'slug' => 'gb-imported',
        'price' => 1000,
        'status' => 'publish',
        'image' => 'https://kbeautybliss.com/wp-content/uploads/2019/03/main.jpg',
        'images' => ['https://kbeautybliss.com/wp-content/uploads/2019/03/gallery.jpg'],
    ]);

    $brand = Brand::query()->create([
        'name' => 'Imported brand',
        'slug' => 'gb-imported-brand',
        'logo' => 'https://kbeautybliss.com/wp-content/uploads/2019/03/logo.png',
    ]);

    $rewrite = new MediaRewrite;

    expect($rewrite->hostsSeen())->toHaveCount(1)
        ->and($rewrite->hostsSeen()[0]['host'])->toBe('kbeautybliss.com')
        ->and($rewrite->hostsSeen()[0]['own'])->toBeFalse();

    $proposals = $rewrite->propose(['kbeautybliss.com']);

    expect($rewrite->summarise($proposals))->toBe(['rewrite' => 3, 'same' => 0, 'absent' => 0])
        ->and($rewrite->apply($proposals))->toBe(3);

    expect($product->fresh()->image)->toBe('/wp-content/uploads/2019/03/main.jpg')
        ->and($product->fresh()->images)->toBe(['/wp-content/uploads/2019/03/gallery.jpg'])
        ->and($brand->fresh()->logo)->toBe('/wp-content/uploads/2019/03/logo.png');

    // Nothing is remote any more, which is the number the runbook says to watch.
    expect((new MediaAudit)->summarise((new MediaAudit)->audit())['remote'])->toBe(0);

    // A second pass has nothing left to do.
    expect($rewrite->propose(['kbeautybliss.com']))->toBe([]);

    // And it is exactly invertible, with no ledger table to disagree with.
    expect($rewrite->restore('kbeautybliss.com')['restored'])->toBe(3)
        ->and($product->fresh()->image)
        ->toBe('https://kbeautybliss.com/wp-content/uploads/2019/03/main.jpg');
});

it('writes an image path that carries the base path, because the storefront prints it raw', function () {
    gbWebRoot();
    gbPutFile('wp-content/uploads/2019/03/prefixed.jpg');

    config(['kbb.base_path' => '/kbb-upgrade']);
    Url::forgetBase();

    $product = Product::query()->create([
        'name' => 'Prefixed product',
        'slug' => 'gb-prefixed',
        'price' => 1000,
        'status' => 'publish',
        'image' => 'https://kbeautybliss.com/wp-content/uploads/2019/03/prefixed.jpg',
    ]);

    $rewrite = new MediaRewrite;
    $proposals = $rewrite->propose(['kbeautybliss.com']);

    /*
     * The OPPOSITE rule from the redirect half, and the pair of them is why
     * both are pinned here rather than in two files. products.image is printed
     * straight into a src with no helper, so it MUST carry /kbb-upgrade; a
     * redirect's source is compared against getPathInfo(), which strips it, so
     * that one must NOT.
     */
    expect($proposals)->toHaveCount(1)
        ->and($proposals[0]['decision'])->toBe(MediaRewrite::REWRITE)
        ->and($proposals[0]['to'])->toBe('/kbb-upgrade/wp-content/uploads/2019/03/prefixed.jpg');

    $rewrite->apply($proposals);

    expect($product->fresh()->image)->toBe('/kbb-upgrade/wp-content/uploads/2019/03/prefixed.jpg');

    // And a row already carrying the prefix is not proposed a second time.
    expect($rewrite->propose(['kbeautybliss.com']))->toBe([]);
});

it('asks rather than redirects into a 404 when the destination does not exist', function () {
    /*
     * REACHABLE, and checked to be — a branch that no input can enter is the
     * dead filter `Api\ProductController` already cost this repository once.
     *
     * `categories.path` is a cached column. `CategoryImporter::recomputeTree()`
     * fills it and a row that missed a recompute carries a path whose leaf is
     * not a category at all. The legacy-root rule builds its DESTINATION from
     * that column, so the proposal would be a 301 from an address that 404s to
     * another address that 404s — which tells a search engine the page was
     * replaced by nothing, and is worse than leaving the 404 alone.
     */
    $stale = Category::query()->create(['name' => 'Stale GB', 'slug' => 'stale-gb', 'parent_id' => null]);
    $stale->forceFill(['path' => 'ghost-parent/ghost-leaf', 'depth' => 1])->save();

    $proposals = (new RedirectMap)->propose();
    $row = gbProposalFor('/stale-gb/', $proposals);

    expect($row)->not->toBeNull()
        ->and($row['decision'])->toBe(RedirectMap::ASK)
        ->and($row['reason'])->toContain('replaced by nothing');
});

it('will not put a row back on the old host once somebody has re-pointed it', function () {
    gbWebRoot();
    gbPutFile('wp-content/uploads/kept.jpg');

    $product = Product::query()->create([
        'name' => 'Re-pointed product',
        'slug' => 'gb-repointed',
        'price' => 1000,
        'status' => 'publish',
        'image' => 'https://kbeautybliss.com/wp-content/uploads/kept.jpg',
    ]);

    $rewrite = new MediaRewrite;
    $rewrite->apply($rewrite->propose(['kbeautybliss.com']));

    // An admin picks a different photograph from the Media Library.
    $product->forceFill(['image' => '/uploads/products/a-person-chose-this.png'])->save();

    $result = $rewrite->restore('kbeautybliss.com');

    expect($result['restored'])->toBe(0)
        ->and($product->fresh()->image)->toBe('/uploads/products/a-person-chose-this.png')
        ->and(implode(' ', $result['kept']))->toContain('somebody else set it');
});

it('re-spells local image paths when the shop moves out of its subfolder', function () {
    gbWebRoot();
    gbPutFile('wp-content/uploads/moved.jpg');

    config(['kbb.base_path' => '/kbb-upgrade']);
    Url::forgetBase();

    $product = Product::query()->create([
        'name' => 'Moved shop product',
        'slug' => 'gb-moved',
        'price' => 1000,
        'status' => 'publish',
        'image' => 'https://kbeautybliss.com/wp-content/uploads/moved.jpg',
    ]);

    $rewrite = new MediaRewrite;
    $rewrite->apply($rewrite->propose(['kbeautybliss.com']));

    expect($product->fresh()->image)->toBe('/kbb-upgrade/wp-content/uploads/moved.jpg')
        ->and($rewrite->proposeRebase())->toBe([]);

    // Staging goes live: the same shop, now at the domain root.
    config(['kbb.base_path' => '']);
    Url::forgetBase();

    /*
     * THE HOST REWRITE CANNOT SEE THIS. The row carries no host any more, so
     * propose() filters it out — which is why proposeRebase() exists as its own
     * entry point rather than as a flag. Without it every photograph on the
     * shop is a broken frame the day the base path changes, and nothing in the
     * catalogue says why.
     */
    expect($rewrite->propose(['kbeautybliss.com']))->toBe([]);

    $rebase = $rewrite->proposeRebase();

    expect($rebase)->toHaveCount(1)
        ->and($rebase[0]['decision'])->toBe(MediaRewrite::REWRITE)
        ->and($rebase[0]['to'])->toBe('/wp-content/uploads/moved.jpg')
        ->and($rewrite->apply($rebase))->toBe(1)
        ->and($product->fresh()->image)->toBe('/wp-content/uploads/moved.jpg')
        ->and($rewrite->proposeRebase())->toBe([]);
});

it('restores a row that was localised under a subfolder', function () {
    gbWebRoot();
    gbPutFile('wp-content/uploads/under-base.jpg');

    config(['kbb.base_path' => '/kbb-upgrade']);
    Url::forgetBase();

    $product = Product::query()->create([
        'name' => 'Under base product',
        'slug' => 'gb-under-base',
        'price' => 1000,
        'status' => 'publish',
        'image' => 'https://kbeautybliss.com/wp-content/uploads/under-base.jpg',
    ]);

    $rewrite = new MediaRewrite;
    $rewrite->apply($rewrite->propose(['kbeautybliss.com']));

    expect($product->fresh()->image)->toBe('/kbb-upgrade/wp-content/uploads/under-base.jpg');

    /*
     * The inverse has to take the subfolder back off before rebuilding the old
     * URL, or it puts the shop's own base path into an address on somebody
     * else's host — kbeautybliss.com/kbb-upgrade/wp-content/… , which is a file
     * that has never existed anywhere.
     */
    expect($rewrite->restore('kbeautybliss.com')['restored'])->toBe(1)
        ->and($product->fresh()->image)
        ->toBe('https://kbeautybliss.com/wp-content/uploads/under-base.jpg');
});

it('will not re-spell an address that is not under one of this shop\'s upload roots', function () {
    gbWebRoot();
    gbPutFile('brand-assets/hand-typed.png');

    config(['kbb.base_path' => '/kbb-upgrade']);
    Url::forgetBase();

    Brand::query()->create([
        'name' => 'Hand typed brand',
        'slug' => 'gb-hand-typed',
        'logo' => '/brand-assets/hand-typed.png',
    ]);

    // Not under wp-content/uploads or uploads, so it is not this class's to
    // correct — an admin typed it and meant it.
    expect((new MediaRewrite)->proposeRebase())->toBe([]);
});

it('reports "same" rather than rewriting when the named host is this shop\'s own', function () {
    /*
     * REACHABLE, and checked to be — the screen lets the owner tick any host
     * the catalogue points at, including this shop's own, and the command only
     * MARKS its own host rather than refusing it. Without a branch for it, a row
     * that already says exactly what a rewrite would write would be counted as
     * a rewrite and saved for no change.
     */
    gbWebRoot();
    gbPutFile('uploads/products/already-ours.png');

    config(['app.url' => 'https://shop.example']);

    Product::query()->create([
        'name' => 'Already ours',
        'slug' => 'gb-already-ours',
        'price' => 1000,
        'status' => 'publish',
        'image' => 'https://shop.example/uploads/products/already-ours.png',
    ]);

    $rewrite = new MediaRewrite;
    $proposals = $rewrite->propose(['shop.example']);

    expect($proposals)->toHaveCount(1)
        ->and($proposals[0]['decision'])->toBe(MediaRewrite::SAME)
        ->and($rewrite->apply($proposals))->toBe(0);
});

it('leaves alone a host the owner did not name', function () {
    gbWebRoot();
    gbPutFile('wp-content/uploads/cdn.jpg');

    Product::query()->create([
        'name' => 'CDN product',
        'slug' => 'gb-cdn',
        'price' => 1000,
        'status' => 'publish',
        'image' => 'https://cdn.somebody-else.example/wp-content/uploads/cdn.jpg',
    ]);

    expect((new MediaRewrite)->propose(['kbeautybliss.com']))->toBe([])
        ->and((new MediaRewrite)->propose([]))->toBe([]);
});

/* ============================================== the screen, which is the point */

it('is unreachable without an admin session, on every route in the file', function () {
    UrlsMediaAdminRoutes::wire($this->app);

    $routes = UrlsMediaAdminRoutes::registered();

    /*
     * A FLOOR, NOT AN EXACT COUNT, and the change is deliberate rather than a
     * guard being loosened to make a merge go green.
     *
     * registered() filters by URI PREFIX, not by which file registered them, so
     * it picks up every route mounted under admin-api/urls-media/ whoever wrote
     * it. That is the property worth having: when Lane GD added the sideloader
     * and the live progress page under the same prefix, this test immediately
     * covered all four of them too and they all answered 401. What broke was
     * only the literal `4`.
     *
     * The real protection is the LOOP BELOW, which asserts on every route it
     * finds -- a new one added ungarded fails here by its own URI. The count is
     * only here to stop the loop being vacuous if the filter ever matches
     * nothing, so a floor does that job and an exact number does not do a
     * better one; it just fails every time the screen legitimately grows.
     */
    expect(count($routes))->toBeGreaterThanOrEqual(
        4,
        'no routes matched admin-api/urls-media/ at all, so the loop below asserts nothing'
    );

    foreach ($routes as $route) {
        $method = in_array('GET', $route->methods(), true) ? 'getJson' : 'postJson';

        // json, because auth:admin answers a browser navigation with a 302 to
        // the login form and only an API-shaped request with a 401. Both are
        // refusals; asserting the 401 is asserting the one an automated caller
        // would get, which is the caller this guard exists for.
        $this->{$method}('/'.$route->uri())->assertStatus(401);
    }
});

it('gives the owner the whole picture in one call, and it agrees with the services', function () {
    gbWebRoot();
    gbTree();

    Product::query()->create([
        'name' => 'Screen product',
        'slug' => 'gb-screen',
        'price' => 1000,
        'status' => 'publish',
        'image' => 'https://kbeautybliss.com/wp-content/uploads/2019/03/screen.jpg',
    ]);

    UrlsMediaAdminRoutes::wire($this->app);

    $body = $this->actingAs(gbAdmin(), 'admin')
        ->getJson('/admin-api/urls-media/status')
        ->assertOk()
        ->json();

    expect($body['ok'])->toBeTrue()
        ->and($body['urls']['buckets'][RedirectMap::MIGRATE]['count'])->toBeGreaterThan(0)
        ->and($body['media']['summary']['remote'])->toBe(1)
        ->and(array_column($body['media']['hosts'], 'host'))->toContain('kbeautybliss.com');

    $sources = array_column($body['urls']['buckets'][RedirectMap::MIGRATE]['rows'], 'source');

    expect($sources)->toContain('/face-cleansers-gb/');
});

it('writes the redirects from the screen and they answer for a real request', function () {
    gbTree();

    UrlsMediaAdminRoutes::wire($this->app);

    $this->actingAs(gbAdmin(), 'admin')
        ->postJson('/admin-api/urls-media/redirects', ['action' => 'write'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $moved = gbFetch('/face-cleansers-gb/');

    expect($moved->getStatusCode())->toBe(301)
        ->and($moved->headers->get('Location'))
        ->toContain('/product-category/skincare-gb/face-cleansers-gb');

    $this->actingAs(gbAdmin(), 'admin')
        ->postJson('/admin-api/urls-media/redirects', ['action' => 'rollback'])
        ->assertOk();

    expect(gbFetch('/face-cleansers-gb/')->getStatusCode())->toBe(404);
});

it('will not rewrite images from the screen without being told which host', function () {
    UrlsMediaAdminRoutes::wire($this->app);

    $this->actingAs(gbAdmin(), 'admin')
        ->postJson('/admin-api/urls-media/media', ['action' => 'apply'])
        ->assertStatus(422);
});
