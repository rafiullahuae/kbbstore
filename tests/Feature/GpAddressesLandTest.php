<?php

declare(strict_types=1);

/**
 * The "Addresses and pictures" group, and whether an old URL actually lands.
 *
 * WHAT EACH BLOCK IS HERE TO CATCH, in the order the damage lands:
 *
 *  1. THE TWO FILES BEING REFUSED. Lane GK's export screen offers a group
 *     called "Addresses and pictures"; it holds `permalinks.csv` and
 *     `media.csv`; and Store → Import refused both by name, because neither is
 *     an entity. The owner downloaded a group that told him twice it had not
 *     imported. `ImportWorkspace::COMPANIONS` is the answer and these tests are
 *     what stop it being undone.
 *
 *  2. THE FILE LANDING AND CHANGING NOTHING. Worse than the refusal, because it
 *     is silent. `RedirectMap::fromPermalinks()` had read this shape since the
 *     day it was written and NOTHING WITH A SCREEN HAD EVER HANDED IT A FILE —
 *     `UrlsMediaApiController` called `propose()` with no arguments. A test
 *     that only asserts the upload is accepted would pass against that.
 *
 *  3. AN ENTITY BEING STOLEN. The companion table is consulted only after the
 *     entity filename table declines, so the change can turn a refusal into an
 *     acceptance and must never turn one entity into another.
 *
 *  4. A COLUMN GUARD THAT DOES NOT GUARD. Neither companion is imported, so
 *     neither produces a report anybody reads: a `permalinks.csv` the map
 *     cannot use proposes nothing, which looks exactly like not having
 *     uploaded it — the broken-filter shape CLAUDE.md names.
 *
 *  5. THE REDIRECT LANDING SOMEWHERE ELSE. Every 301 this table serves went
 *     through `redirect($target)`, which strips the trailing slash and drops
 *     the reader's language. Measured: 15 of 15 rows landed on an address
 *     whose own canonical pointed somewhere else.
 *
 *  6. THE ASK BUCKET FILLING WITH NOISE. The map could not resolve a post or a
 *     page, so every blog row in a real permalink file landed in the list the
 *     owner is asked to work through, with a reason that was false.
 *
 * THESE RUN ON BOTH ENGINES. Nothing here is about an index or a dialect.
 */

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Redirect;
use App\Services\Import\MediaAudit;
use App\Services\Import\MediaIndex;
use App\Services\Import\RedirectMap;
use App\Services\ImportConsole\ImportWorkspace;
use App\Support\AdminCapabilities;
use Illuminate\Http\UploadedFile;
use Tests\Support\ImportAdminRoutes;
use Tests\Support\UrlsMediaAdminRoutes;

/* ------------------------------------------------------------------ set-up */

beforeEach(function () {
    gpPurge(storage_path('app/import'));
});

afterEach(function () {
    gpPurge(storage_path('app/import'));

    foreach (glob(sys_get_temp_dir().'/kbb-gp-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

function gpPurge(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;
        is_dir($path) ? gpPurge($path) : @unlink($path);
    }

    @rmdir($dir);
}

function gpAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Addresses Owner',
        'email' => 'gp-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** Upload one file through the real endpoint, the way the screen does. */
function gpUpload(string $name, string $body, ?string $entity = null): \Illuminate\Testing\TestResponse
{
    $temp = sys_get_temp_dir().'/kbb-gp-'.bin2hex(random_bytes(6)).'-'.$name;
    file_put_contents($temp, $body);

    $payload = ['file' => new UploadedFile($temp, $name, null, null, true)];

    if ($entity !== null) {
        $payload['entity'] = $entity;
    }

    return test()->postJson('/admin-api/import/upload', $payload);
}

/**
 * The permalink file as the plugin writes it (docs/GE-WP-EXPORTER.md §4),
 * carrying the addresses kbeautybliss.com really served: categories FLAT at the
 * site root, because `woocommerce_permalinks['category_base']` is the empty
 * string on that site.
 *
 * @param  list<array{0: string, 1: int, 2: string, 3: string}>  $rows
 */
function gpPermalinks(array $rows): string
{
    $out = "type,wc_id,slug,permalink,status,source,note\n";

    foreach ($rows as [$type, $id, $slug, $url]) {
        $out .= $type.','.$id.','.$slug.','.$url.",publish,wp,\n";
    }

    return $out;
}

/** A nested tree whose leaf the old site published flat at the root. */
function gpTree(): array
{
    $parent = Category::query()->create(['name' => 'Skincare GP', 'slug' => 'skincare-gp', 'parent_id' => null]);
    $parent->forceFill(['path' => 'skincare-gp', 'depth' => 0, 'source_term_id' => 7701])->save();

    $child = Category::query()->create(['name' => 'Toners GP', 'slug' => 'toners-gp', 'parent_id' => $parent->id]);
    $child->forceFill(['path' => 'skincare-gp/toners-gp', 'depth' => 1, 'source_term_id' => 7702])->save();

    return [$parent, $child];
}

/* ==========================================================================
 * 1. THE TWO FILES LAND
 * ========================================================================== */

it('takes permalinks.csv, which Store → Import used to refuse by name', function () {
    ImportAdminRoutes::wire($this->app);
    $this->actingAs(gpAdmin(), 'admin');

    [$parent, $child] = gpTree();

    $body = gpUpload('permalinks.csv', gpPermalinks([
        ['product_cat', 7702, 'toners-gp', 'https://kbeautybliss.com/toners-gp/'],
    ]))->assertOk()->json();

    expect($body['refused'])->toBe([]);
    expect($body['accepted'][0]['entity'])->toBe('permalinks');
    expect($body['accepted'][0]['rows'])->toBe(1);

    // Beside the export's own CSVs, under its own literal name.
    expect(is_file(storage_path('app/import/woo/permalinks.csv')))->toBeTrue();

    // And named on the payload, because a file that lands without a trace is
    // not much better than one that is turned away.
    $companions = collect($body['status']['companions'])->keyBy('key');
    expect($companions['permalinks']['present'])->toBeTrue();
    expect($companions['permalinks']['rows'])->toBe(1);
    expect($companions['media']['present'])->toBeFalse();
});

it('takes media.csv, the other half of the same download', function () {
    ImportAdminRoutes::wire($this->app);
    $this->actingAs(gpAdmin(), 'admin');

    $body = gpUpload('media.csv',
        "url,attachment_id,size,path,exists,bytes,referenced_by,referenced_id,field\n"
        ."https://kbeautybliss.com/wp-content/uploads/2019/03/a.jpg,9,full,2019/03/a.jpg,1,120,product,4021,_thumbnail_id\n"
    )->assertOk()->json();

    expect($body['refused'])->toBe([]);
    expect($body['accepted'][0]['entity'])->toBe('media');
    expect(is_file(storage_path('app/import/woo/media.csv')))->toBeTrue();
});

it('never lets a companion become an entity the drive loop would have to step', function () {
    /*
     * The whole reason these are a second table. ImportWorkspace::ENTITIES and
     * ImportRunner::entities() are two hand-maintained lists that MUST agree —
     * the note on `seo` records what it cost the last time they did not — and
     * an entry here with no importer behind it is that drift, deliberately.
     *
     * array_diff rather than ->not->toContain: CLAUDE.md, that assertion passes
     * vacuously when a message is given.
     */
    expect(array_diff(ImportWorkspace::companionKeys(), ImportWorkspace::entities()))
        ->toBe(ImportWorkspace::companionKeys());

    expect(array_intersect(ImportWorkspace::entities(), ImportWorkspace::companionKeys()))->toBe([]);
});

it('does not name either companion in the list of files no importer opens', function () {
    /*
     * ImportRunner::reportUnreadFiles() globs the export directory and names
     * everything nothing reads, in the discard list the owner is asked to
     * approve. Both of these are read — by the redirect map and by the picture
     * audit — so naming them would be false, and one false line is what teaches
     * him to skim the whole list. permalinks.csv was already exempt; media.csv
     * only reaches that directory now that it is accepted.
     */
    $source = (string) file_get_contents(base_path('app/Services/Import/ImportRunner.php'));

    foreach (ImportWorkspace::companionKeys() as $key) {
        $file = ImportWorkspace::companionMeta($key)['file'];

        expect(str_contains($source, "'".$file."' => true"))
            ->toBeTrue($file.' is not claimed in reportUnreadFiles(), so it lands in the discard list');
    }
});

/* ==========================================================================
 * 2. THE FILE ACTUALLY REACHES THE MAP
 * ========================================================================== */

it('feeds permalinks.csv to the redirect map, which had never been handed one', function () {
    UrlsMediaAdminRoutes::wire($this->app);
    $this->actingAs(gpAdmin(), 'admin');

    [$parent, $child] = gpTree();

    // Without the file: the map proposes what it can DERIVE, and says so.
    $before = $this->getJson('/admin-api/urls-media/status')->assertOk()->json();

    expect($before['sources']['permalinks']['present'])->toBeFalse();
    expect($before['sources']['permalinks']['rows'])->toBe(0);

    $beforeSources = gpSources($before);

    // An address NO derivation could reach: a category the old site published
    // under a slug this shop does not have. Only the file can know it.
    (new ImportWorkspace)->accept(gpFile('permalinks.csv', gpPermalinks([
        ['product_cat', 7702, 'toners-gp', 'https://kbeautybliss.com/old-toner-aisle/'],
    ])));

    $after = $this->getJson('/admin-api/urls-media/status')->assertOk()->json();

    expect($after['sources']['permalinks']['present'])->toBeTrue();
    expect($after['sources']['permalinks']['rows'])->toBe(1);

    $afterSources = gpSources($after);

    expect(array_diff($afterSources, $beforeSources))->toContain('/old-toner-aisle/');
});

it('gives the same permalinks to the map, the CSV download and the write', function () {
    /*
     * Three endpoints call propose(). A version of this that fed the file to
     * one of them would show the owner a map and then write a different one —
     * and the CSV is the thing Phase 13 has him APPROVE from.
     */
    UrlsMediaAdminRoutes::wire($this->app);
    $this->actingAs(gpAdmin(), 'admin');

    gpTree();

    (new ImportWorkspace)->accept(gpFile('permalinks.csv', gpPermalinks([
        ['product_cat', 7702, 'toners-gp', 'https://kbeautybliss.com/old-toner-aisle/'],
    ])));

    $csv = $this->get('/admin-api/urls-media/map.csv')->assertOk()->getContent();

    expect($csv)->toContain('/old-toner-aisle/');

    $this->postJson('/admin-api/urls-media/redirects', ['action' => 'write'])->assertOk();

    expect(Redirect::query()->where('source', '/old-toner-aisle/')->exists())->toBeTrue();
});

/** Every source address the status payload names, across all three buckets. */
function gpSources(array $payload): array
{
    $out = [];

    foreach ($payload['urls']['buckets'] as $bucket) {
        foreach ($bucket['rows'] ?? [] as $row) {
            $out[] = $row['source'];
        }
    }

    return $out;
}

/**
 * Fetch an address the way a browser sends it, TRAILING SLASH AND ALL.
 *
 * $this->get() cannot: MakesHttpRequests::prepareUrlForRequest() runs
 * trim($uri, '/') before building the request, and findMatch() compares against
 * getPathInfo(), which keeps whatever the client really sent. A row stored as
 * `/toners/` cannot be exercised through the test client at all. Lane GB found
 * this and its gbFetch() is the same helper; copied rather than shared, because
 * that file is another lane's.
 */
function gpFetch(string $path): \Symfony\Component\HttpFoundation\Response
{
    return app(\Illuminate\Contracts\Http\Kernel::class)->handle(
        \Illuminate\Http\Request::create('http://localhost'.$path, 'GET'),
    );
}

/** An UploadedFile for the workspace's own door, without going through HTTP. */
function gpFile(string $name, string $body): UploadedFile
{
    $temp = sys_get_temp_dir().'/kbb-gp-'.bin2hex(random_bytes(6)).'-'.$name;
    file_put_contents($temp, $body);

    return new UploadedFile($temp, $name, null, null, true);
}

/* ==========================================================================
 * 3. NOTHING ELSE CHANGED
 * ========================================================================== */

it('still files products.csv as products and not as a companion', function () {
    ImportAdminRoutes::wire($this->app);
    $this->actingAs(gpAdmin(), 'admin');

    $body = gpUpload('products.csv', "id,name\n4021,A serum\n")->assertOk()->json();

    expect($body['accepted'][0]['entity'])->toBe('products');
});

it('still honours the dropdown over the file name', function () {
    /*
     * "This file is the categories export" is a statement about this file and
     * it wins, exactly as it does for an entity. Without this the companion
     * table could overrule what the owner said.
     */
    ImportAdminRoutes::wire($this->app);
    $this->actingAs(gpAdmin(), 'admin');

    $body = gpUpload('permalinks.csv', "term_id,name\n5,Serums\n", 'categories')->assertOk()->json();

    expect($body['accepted'][0]['entity'])->toBe('categories');
    expect(is_file(storage_path('app/import/woo/categories.csv')))->toBeTrue();
    expect(is_file(storage_path('app/import/woo/permalinks.csv')))->toBeFalse();
});

it('lets an entity keep a name a companion would also answer to', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * THE MUTATION THAT SURVIVED THE FIRST PASS, AND THE INPUT THAT CLOSES IT
     * ════════════════════════════════════════════════════════════════════════
     *
     * companionFor() asks entityFromFilename() first and yields to it, so this
     * change can only ever turn a refusal into an acceptance and can never turn
     * one entity into another. Deleting that check left the whole suite GREEN,
     * because no entity file name contains "permalinks" or "media" — the guard
     * was correct and unreached, which is the shape this repository has paid
     * for twice (Api\ProductController's dead status filter, and Lane GF's
     * second mismatch check in ImportDriver::status()).
     *
     * The input that reaches it is a name BOTH tables answer to. Both lookups
     * are str_contains over a normalised basename — the entity table's spelling
     * since before this lane — so `products-permalinks.csv` matches the
     * `products` stem and the `permalinks` stem at once. That is not a
     * contrived string: it is what a browser writes when the owner has renamed
     * a download, and a shop that filed it as a permalink file would have
     * silently dropped the products export.
     *
     * The rule is that the entity wins, because that is what happened before
     * this lane existed.
     */
    ImportAdminRoutes::wire($this->app);
    $this->actingAs(gpAdmin(), 'admin');

    $body = gpUpload('products-permalinks.csv', "id,name\n4021,A serum\n")->assertOk()->json();

    expect($body['accepted'][0]['entity'])->toBe('products');
    expect(is_file(storage_path('app/import/woo/products.csv')))->toBeTrue();
    expect(is_file(storage_path('app/import/woo/permalinks.csv')))->toBeFalse();
});

it('still refuses a file that is neither an entity nor a companion', function () {
    ImportAdminRoutes::wire($this->app);
    $this->actingAs(gpAdmin(), 'admin');

    $body = gpUpload('whatever.csv', "a,b\n1,2\n")->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('Which export is this?');
});

/* ==========================================================================
 * 4. THE COLUMN GUARD
 * ========================================================================== */

it('refuses a permalinks.csv the redirect map could not read a single row of', function () {
    ImportAdminRoutes::wire($this->app);
    $this->actingAs(gpAdmin(), 'admin');

    $body = gpUpload('permalinks.csv', "old_url,new_url\n/a/,/b/\n")->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('"wc_id" or "id" column');
    expect($body['refused'][0]['message'])->toContain('looks exactly like not having uploaded it');
    expect(is_file(storage_path('app/import/woo/permalinks.csv')))->toBeFalse();
});

it('refuses a permalinks.csv with ids but no address column', function () {
    ImportAdminRoutes::wire($this->app);
    $this->actingAs(gpAdmin(), 'admin');

    $body = gpUpload('permalinks.csv', "type,wc_id,slug\nproduct_cat,7,toners\n")->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('"permalink" or "url" or "old_url" column');
});

it('takes a permalinks.csv that is a header and nothing else', function () {
    /*
     * A shop whose plugin found nothing to report writes a header and no rows,
     * exactly as a delta export of a week with no new customers does. Refusing
     * it would make the owner delete a file to prove there is nothing in it.
     */
    ImportAdminRoutes::wire($this->app);
    $this->actingAs(gpAdmin(), 'admin');

    $body = gpUpload('permalinks.csv', "type,wc_id,slug,permalink,status,source,note\n")->assertOk()->json();

    expect($body['accepted'][0]['entity'])->toBe('permalinks');
    expect($body['accepted'][0]['rows'])->toBe(0);
});

it('lets the owner remove a companion, because he can upload one', function () {
    /*
     * A permalinks.csv from the wrong export silently changes every redirect
     * this shop proposes, and "delete it off the server" is not an instruction
     * anyone with no shell can follow.
     */
    ImportAdminRoutes::wire($this->app);
    $this->actingAs(gpAdmin(), 'admin');

    gpUpload('permalinks.csv', gpPermalinks([
        ['product_cat', 7702, 'toners-gp', 'https://kbeautybliss.com/toners-gp/'],
    ]))->assertOk();

    expect(is_file(storage_path('app/import/woo/permalinks.csv')))->toBeTrue();

    $body = $this->postJson('/admin-api/import/forget', ['entity' => 'permalinks'])->assertOk()->json();

    expect(is_file(storage_path('app/import/woo/permalinks.csv')))->toBeFalse();
    expect(collect($body['status']['companions'])->keyBy('key')['permalinks']['present'])->toBeFalse();
});

/* ==========================================================================
 * 5. THE MAP KNOWS WHAT AN ARTICLE AND A PAGE ARE
 * ========================================================================== */

it('resolves a post and a page instead of calling them un-imported', function () {
    /*
     * Every post and page row in a real permalink file used to land in the ASK
     * bucket — the list Phase 13 has the owner work through by hand — with the
     * reason "nothing in this shop carries post id 501", about an address the
     * shop was already answering 200 on. The reason was false and the list was
     * mostly noise, which docs/FV-IMPORT-AT-VOLUME.md §10 is explicit is a list
     * nobody finishes.
     */
    Post::query()->create([
        'source_post_id' => 811,
        'slug' => 'gp-heartleaf-extract',
        'title' => 'Heartleaf',
        'body' => '<p>x</p>',
        'status' => 'published',
        'published_at' => now()->subMonth(),
    ]);

    Page::query()->create([
        'source_post_id' => 822,
        'slug' => 'gp-delivery',
        'title' => 'Delivery',
        'content' => '<p>x</p>',
        'status' => 'published',
    ]);

    $proposals = (new RedirectMap)->propose([
        ['type' => 'post', 'wc_id' => '811', 'permalink' => 'https://kbeautybliss.com/gp-heartleaf-extract/'],
        ['type' => 'page', 'wc_id' => '822', 'permalink' => 'https://kbeautybliss.com/gp-delivery/'],
        ['type' => 'post', 'wc_id' => '899', 'permalink' => 'https://kbeautybliss.com/never-imported/'],
    ]);

    $by = [];

    foreach ($proposals as $p) {
        if ($p['rule'] === 'permalink') {
            $by[$p['source']] = $p;
        }
    }

    // Same address here as there: nothing to redirect, and that is an ANSWER.
    expect($by['/gp-heartleaf-extract/']['decision'])->toBe(RedirectMap::DISCARD);
    expect($by['/gp-delivery/']['decision'])->toBe(RedirectMap::DISCARD);

    // And a genuinely unknown id is still a question, so this did not turn the
    // guard off on the way past.
    expect($by['/never-imported/']['decision'])->toBe(RedirectMap::ASK);
    expect($by['/never-imported/']['reason'])->toContain('nothing in this shop carries post id 899');
});

it('writes a redirect for an article whose slug changed on import', function () {
    /*
     * The half of the above that is worth the owner's attention, and the reason
     * resolving posts is not the same as discarding them.
     */
    Post::query()->create([
        'source_post_id' => 833,
        'slug' => 'gp-renamed-on-import',
        'title' => 'Renamed',
        'body' => '<p>x</p>',
        'status' => 'published',
        'published_at' => now()->subMonth(),
    ]);

    $proposals = (new RedirectMap)->propose([
        ['type' => 'post', 'wc_id' => '833', 'permalink' => 'https://kbeautybliss.com/the-old-slug/'],
    ]);

    $row = collect($proposals)->firstWhere('source', '/the-old-slug/');

    expect($row['decision'])->toBe(RedirectMap::MIGRATE);
    expect($row['target'])->toBe('/gp-renamed-on-import/');
});

/* ==========================================================================
 * 6. THE REDIRECT LANDS ON THE CANONICAL ADDRESS
 * ========================================================================== */

it('sends a visitor to the canonical address, trailing slash and all', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * ASSERTED AS IT NOW IS, AND IT USED TO BE THE OTHER WAY
     * ════════════════════════════════════════════════════════════════════════
     *
     * docs/GB-MEDIA-AND-REDIRECTS.md §6.4 measured this and left it, because
     * changing it changes the destination of EVERY 301 the site serves. This
     * lane took it, because the same document's §2.4 and this lane's own
     * fetches make it the difference between "the old URL resolves" and "the
     * old URL resolves at the address the page itself says is canonical".
     *
     * Measured before the change, against a running server: 15 of 15 rows
     * landed on a slash-less address whose own <link rel="canonical"> pointed
     * at the slashed one. docs/GP-ADDRESSES-LAND.md carries both tables.
     */
    Redirect::query()->create([
        'source' => '/gp-old-address/',
        'target' => '/product-category/skincare-gp/toners-gp/',
        'code' => 301,
        'enabled' => true,
        'auto_created' => true,
    ]);

    gpTree();

    $response = gpFetch('/gp-old-address/');

    expect($response->getStatusCode())->toBe(301);

    $location = (string) $response->headers->get('Location');

    expect(str_ends_with($location, '/product-category/skincare-gp/toners-gp/'))
        ->toBeTrue('the 301 landed on '.$location.', which is not the canonical spelling');
});

it('makes the unregistered middleware copy answer the same, so the two cannot drift', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * THE MUTATION THAT COULD NOT GO RED, AND WHY THAT IS THE FINDING ITSELF
     * ════════════════════════════════════════════════════════════════════════
     *
     * Breaking `CheckRedirects::handle()` — putting its redirect back to the
     * bare target — left the whole suite green, and it would leave a running
     * server green too. THE METHOD IS NEVER CALLED. The class is written as
     * middleware and is registered as one nowhere: `bootstrap/app.php` does not
     * mention it, and the only live reader of the redirects table is the
     * NotFoundHttpException closure in AppServiceProvider, which calls the
     * static findMatch() and issues its own redirect.
     *
     * Re-established by fetching, not taken on trust: three rows pointing at
     * /PROOF-INERT/ were written for /shop/, /product-category/toners/ and a
     * product address, all enabled, all matching getPathInfo() byte for byte.
     * /shop/ still answered 200, the category still 301'd to its own nested
     * path, the product still answered 200. Not one of the three fired.
     * docs/GP-ADDRESSES-LAND.md has the transcript.
     *
     * So this test calls the method directly. That is the only way a mutation
     * of it can be caught at all, and it is worth catching: the class stays as
     * where the logic is defined, so the day somebody does register it the two
     * copies must already agree. Asserting them against EACH OTHER rather than
     * against a literal is the point — a change applied to one of them is the
     * failure this catches.
     */
    Redirect::query()->create([
        'source' => '/gp-two-copies/',
        'target' => '/shop/',
        'code' => 301,
        'enabled' => true,
        'auto_created' => true,
    ]);

    $request = \Illuminate\Http\Request::create('http://localhost/gp-two-copies/', 'GET');

    $viaMiddleware = (new \App\Http\Middleware\CheckRedirects)->handle(
        $request,
        static fn (): \Symfony\Component\HttpFoundation\Response => new \Illuminate\Http\Response('not reached'),
    );

    $viaHandler = gpFetch('/gp-two-copies/');

    expect($viaMiddleware->getStatusCode())->toBe(301);
    expect($viaHandler->getStatusCode())->toBe(301);

    expect((string) $viaMiddleware->headers->get('Location'))
        ->toBe((string) $viaHandler->headers->get('Location'));

    expect((string) $viaHandler->headers->get('Location'))->toEndWith('/shop/');
});

it('keeps a redirect row prefix-free even though its Location is not', function () {
    /*
     * The pair this cannot be allowed to break. `redirects.source` is compared
     * against getPathInfo(), which STRIPS the base path, so the stored row must
     * never carry it — while the Location header is a URL a browser follows and
     * must. Url::redirect() is what holds the two apart, and a change that made
     * the row absolute would break every match on staging.
     */
    Redirect::query()->create([
        'source' => '/gp-prefix-check/',
        'target' => '/shop/',
        'code' => 301,
        'enabled' => true,
        'auto_created' => true,
    ]);

    $row = Redirect::query()->where('source', '/gp-prefix-check/')->first();

    expect(str_starts_with((string) $row->source, '/'))->toBeTrue();
    expect(str_contains((string) $row->source, 'kbb-upgrade'))->toBeFalse();

    $response = gpFetch('/gp-prefix-check/');

    expect($response->getStatusCode())->toBe(301);

    $location = (string) $response->headers->get('Location');

    // Absolute, so a browser can follow it; the row it came from is not.
    expect(str_starts_with($location, 'http'))->toBeTrue();
});

/* ==========================================================================
 * 7. media.csv: THE ONE COLUMN THIS SHOP CANNOT DERIVE
 * ========================================================================== */

it('tells a picture that has not been copied yet from one the old site had lost', function () {
    $index = new MediaIndex([
        ['url' => 'https://kbeautybliss.com/wp-content/uploads/2019/03/here.jpg', 'exists' => '1'],
        ['url' => 'https://kbeautybliss.com/wp-content/uploads/2019/03/gone.jpg', 'exists' => '0'],
    ]);

    expect($index->existedAtSource('https://kbeautybliss.com/wp-content/uploads/2019/03/here.jpg'))->toBeTrue();
    expect($index->existedAtSource('https://kbeautybliss.com/wp-content/uploads/2019/03/gone.jpg'))->toBeFalse();

    /*
     * THREE ANSWERS, NOT TWO. "This file says nothing about that picture" is
     * not "that picture was gone" — folding them together reports every
     * photograph added since the export as missing at source.
     */
    expect($index->existedAtSource('https://kbeautybliss.com/wp-content/uploads/2020/01/new.jpg'))->toBeNull();
});

it('matches a picture across every spelling the migration gives it', function () {
    /*
     * One photograph is written three ways as the migration proceeds: the old
     * absolute URL in the export and after the import, and a local path under
     * the shop's base after MediaRewrite::apply(). The key cuts at the uploads
     * root, which is the one part that does not move.
     */
    $index = new MediaIndex([
        ['url' => 'https://kbeautybliss.com/wp-content/uploads/2019/03/x.jpg', 'exists' => '0'],
    ]);

    foreach ([
        'https://kbeautybliss.com/wp-content/uploads/2019/03/x.jpg',
        'http://kbeautybliss.com/wp-content/uploads/2019/03/x.jpg',
        '/kbb-upgrade/wp-content/uploads/2019/03/x.jpg',
        '/wp-content/uploads/2019/03/x.jpg',
    ] as $spelling) {
        expect($index->existedAtSource($spelling))->toBeFalse($spelling.' did not match the index');
    }

    // Under neither upload root: a supplier's photograph, skipped not guessed.
    expect(MediaIndex::key('https://cdn.example.test/banner.jpg'))->toBeNull();
});

it('reads an unrecognised exists value as present, not as gone', function () {
    /*
     * The only thing this index is used for is telling the owner a picture will
     * never arrive. Saying that wrongly sends him to re-photograph a product he
     * already has, so an unknown value takes the harmless answer.
     */
    $index = new MediaIndex([
        ['url' => 'https://kbeautybliss.com/wp-content/uploads/a.jpg', 'exists' => 'TRUE'],
        ['url' => 'https://kbeautybliss.com/wp-content/uploads/b.jpg', 'exists' => 'whatever'],
        ['url' => 'https://kbeautybliss.com/wp-content/uploads/c.jpg', 'exists' => 'false'],
    ]);

    expect($index->existedAtSource('https://kbeautybliss.com/wp-content/uploads/a.jpg'))->toBeTrue();
    expect($index->existedAtSource('https://kbeautybliss.com/wp-content/uploads/b.jpg'))->toBeTrue();
    expect($index->existedAtSource('https://kbeautybliss.com/wp-content/uploads/c.jpg'))->toBeFalse();
});

it('names the catalogue rows no amount of copying will produce', function () {
    UrlsMediaAdminRoutes::wire($this->app);
    $this->actingAs(gpAdmin(), 'admin');

    Product::query()->create([
        'name' => 'GP Serum',
        'slug' => 'gp-serum',
        'status' => 'publish',
        'price' => 1000,
        'image' => 'https://kbeautybliss.com/wp-content/uploads/2019/03/gone.jpg',
    ]);

    $before = $this->getJson('/admin-api/urls-media/status')->assertOk()->json();

    // No file: nothing is claimed, because nothing is known.
    expect($before['media']['gone_at_source']['count'])->toBe(0);
    expect($before['sources']['media']['present'])->toBeFalse();

    (new ImportWorkspace)->accept(gpFile('media.csv',
        "url,attachment_id,size,path,exists,bytes,referenced_by,referenced_id,field\n"
        ."https://kbeautybliss.com/wp-content/uploads/2019/03/gone.jpg,9,full,2019/03/gone.jpg,0,0,product,1,_thumbnail_id\n"
    ));

    $after = $this->getJson('/admin-api/urls-media/status')->assertOk()->json();

    expect($after['sources']['media']['present'])->toBeTrue();
    expect($after['media']['gone_at_source']['count'])->toBe(1);
    expect($after['media']['gone_at_source']['rows'][0]['url'])
        ->toBe('https://kbeautybliss.com/wp-content/uploads/2019/03/gone.jpg');
});

it('says nothing about a picture that has already arrived', function () {
    /*
     * A picture that is PRESENT on this shop has arrived, whatever the old
     * site's disk looked like on the day of the export. Reporting it would be
     * reporting a problem that has been solved.
     */
    $index = new MediaIndex([
        ['url' => 'https://kbeautybliss.com/wp-content/uploads/2019/03/gone.jpg', 'exists' => '0'],
    ]);

    expect($index->goneAtSource())->toBe(['wp-content/uploads/2019/03/gone.jpg']);

    // PRESENT rows are filtered before the index is consulted at all — see
    // UrlsMediaApiController::goneAtSource(). Pinned through the payload in
    // the test above; this is the unit half.
    expect(MediaAudit::PRESENT)->toBe('present');
});

/* ==========================================================================
 * 8. NO NEW ROUTE, SO NO NEW RULE AND NO CACHE MIGRATION
 * ========================================================================== */

it('adds no route, so it needs no capability rule and no clear_caches migration', function () {
    /*
     * CLAUDE.md: a new admin route needs a rule UNLESS a wildcard covers it,
     * and any new route needs a clear_caches_* migration to be reachable on a
     * host with a compiled route cache. This lane added none: the two files
     * arrive at POST /admin-api/import/upload, the endpoint that already
     * existed, and are read by GET /admin-api/urls-media/status, which already
     * existed too.
     *
     * Checked THROUGH THE RESOLVER rather than by reading the table, so a rule
     * shadowed by an earlier wildcard shows up as the capability the earlier
     * one grants.
     */
    expect(AdminCapabilities::forPath('POST', 'admin-api/import/upload'))->toBe('data.import');
    expect(AdminCapabilities::forPath('POST', 'admin-api/import/forget'))->toBe('data.import');
    expect(AdminCapabilities::forPath('GET', 'admin-api/urls-media/status'))->toBe('data.import');
    expect(AdminCapabilities::forPath('GET', 'admin-api/urls-media/map.csv'))->toBe('data.import');

    /*
     * ── ADVANCED BY LANE A, ROUND 2, AND ONLY FOR ITS OWN ROUTE ──────────
     *
     * This lane DID add one: POST /admin-api/urls-media/decisions, which
     * records the owner's answer to a question from the redirect map. The guard
     * is advanced rather than relaxed — it now asserts, for that route, the two
     * things this test's title says a new route needs, so the list below is
     * still a list nothing can be added to silently.
     *
     *   1. A CAPABILITY. Covered by the existing wildcard rule
     *      ['*', 'admin-api/urls-media/**', 'data.import'], checked through the
     *      resolver exactly as the four above are.
     *   2. A clear_caches MIGRATION. The route file was already required from
     *      routes/web.php, which is the trap: `route:cache` compiled the routes
     *      it found AT THE TIME, so a route added to an already-wired file is a
     *      route the server answers 404 for, with nothing in any log to say so.
     *
     * MUTATION: take '/urls-media/decisions' out of the list below and this is
     * red, which is what says the list is still a guard and not a record.
     */
    expect(AdminCapabilities::forPath('POST', 'admin-api/urls-media/decisions'))->toBe('data.import');

    $clears = glob(database_path('migrations/*clear_caches_redirect_decisions*.php')) ?: [];

    expect($clears)->toHaveCount(
        1,
        'POST /admin-api/urls-media/decisions is in routes/urls-media-admin.php with no clear_caches '
            .'migration beside it, so it does not exist on a host with a compiled route cache'
    );

    $registered = [];

    foreach (['import-admin.php', 'urls-media-admin.php'] as $file) {
        $source = (string) file_get_contents(base_path('routes/'.$file));
        preg_match_all("/Route::(get|post|put|delete)\(\s*'([^']+)'/", $source, $m);
        $registered = array_merge($registered, $m[2]);
    }

    sort($registered);

    expect($registered)->toBe([
        '/import/forget',
        '/import/rejects',
        '/import/reset',
        '/import/start',
        '/import/status',
        '/import/step',
        '/import/stop',
        '/import/upload',
        // Lane A, round 2 — the ask bucket becomes answerable. Asserted above
        // to carry both a capability and a clear_caches migration.
        '/urls-media/decisions',
        '/urls-media/map.csv',
        '/urls-media/media',
        '/urls-media/redirects',
        '/urls-media/status',
    ]);
});
