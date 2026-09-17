<?php

/*
 * The two halves of Phase 13 that the row importer does not cover: the URL
 * redirect map, and whether the pictures the catalogue names actually exist.
 *
 * WHY THESE ARE NOT IN WooImportTest. `kbb:import` reads a CSV and writes rows.
 * Both of these read the rows that import produced and answer a question ABOUT
 * them, so every test here imports the nasty fixture first and then asks its
 * question of the result — which is also the order the owner runs them in.
 *
 * THE FAILURE EACH ONE IS HERE TO CATCH, in the order they would hurt:
 *
 *  1. THE BASE-PATH PREFIX. `redirects.source` is matched against
 *     `getPathInfo()`, which excludes the `/kbb-upgrade` prefix this site is
 *     served under, while `Category::url()` goes through `Url::to()`, which
 *     adds it. Building the map out of the models' own url() methods produces
 *     rows that look perfect in a test that never sets the prefix and match
 *     nothing in production. Pinned with the prefix actually set, because a
 *     guard written against the unprefixed case is the same bug as the `"id"`
 *     quoting guard that shipped here matching nothing on MySQL.
 *
 *  2. A REDIRECT THAT POINTS AT ITSELF. A top-level category's flat address and
 *     its nested address are the same string, and CheckRedirects would serve
 *     that as a loop.
 *
 *  3. AN IMAGE THAT IS STILL ON THE OLD SITE. It renders perfectly while
 *     WooCommerce is up, so nothing catches it until the day the old shop is
 *     switched off.
 *
 * THESE RUN ON BOTH ENGINES, for the reason WooImportTest gives: the fixture
 * and the assertions are about the importer's behaviour, not about an index.
 */

use App\Models\Category;
use App\Models\Product;
use App\Models\Redirect;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Services\Import\MediaAudit;
use App\Services\Import\RedirectMap;

/** The same nasty fixture the row importer is pinned against. */
function urlMapImport(): void
{
    (new ImportRunner)->run(new ImportOptions(
        directory: base_path('tests/Fixtures/woo'),
        // See WooImportTest: the demo catalogue holds real slugs and the
        // genuine terms are refused without this.
        adoptBySlug: true,
    ));
}

/** @return list<array<string, string>> */
function proposalsFor(string $decision, array $proposals): array
{
    return array_values(array_filter(
        $proposals,
        static fn (array $p): bool => $p['decision'] === $decision,
    ));
}

function proposalFor(string $source, array $proposals): ?array
{
    foreach ($proposals as $proposal) {
        if ($proposal['source'] === $source) {
            return $proposal;
        }
    }

    return null;
}

/* ------------------------------------------------------------ the URL map */

it('sends a flat WooCommerce category address to the nested one this shop serves', function () {
    urlMapImport();

    // The fixture nests three deep: skincare > face-cleansers > makeup-removers.
    expect(Category::query()->where('slug', 'makeup-removers')->value('path'))
        ->toBe('skincare/face-cleansers/makeup-removers');

    $proposals = (new RedirectMap)->propose();

    $child = proposalFor('/product-category/face-cleansers/', $proposals);
    $grandchild = proposalFor('/product-category/makeup-removers/', $proposals);

    expect($child)->not->toBeNull()
        ->and($child['decision'])->toBe(RedirectMap::MIGRATE)
        ->and($child['target'])->toBe('/product-category/skincare/face-cleansers/')
        ->and($grandchild)->not->toBeNull()
        ->and($grandchild['decision'])->toBe(RedirectMap::MIGRATE)
        ->and($grandchild['target'])->toBe('/product-category/skincare/face-cleansers/makeup-removers/');
});

it('never writes the base path into a redirect, because getPathInfo strips it', function () {
    /*
     * The trap, set deliberately. env.staging.txt really does set
     * KBB_BASE_PATH=/kbb-upgrade, so this is the production configuration and
     * not a hypothetical one.
     */
    config(['kbb.base_path' => '/kbb-upgrade']);

    urlMapImport();

    $proposals = (new RedirectMap)->propose();

    expect($proposals)->not->toBeEmpty();

    foreach ($proposals as $proposal) {
        expect($proposal['source'])->not->toContain('kbb-upgrade');
        expect($proposal['target'])->not->toContain('kbb-upgrade');
    }

    // And positively: the shape stored is the one CheckRedirects compares.
    $child = proposalFor('/product-category/face-cleansers/', $proposals);

    expect($child)->not->toBeNull()
        ->and($child['target'])->toStartWith('/product-category/');
});

it('refuses to point a top-level category at itself', function () {
    urlMapImport();

    $proposals = (new RedirectMap)->propose();

    $top = proposalFor('/product-category/skincare/', $proposals);

    expect($top)->not->toBeNull()
        ->and($top['decision'])->toBe(RedirectMap::DISCARD)
        ->and($top['reason'])->toContain('point at itself');

    // Nothing in the migrate bucket may be a self-redirect, ever.
    foreach (proposalsFor(RedirectMap::MIGRATE, $proposals) as $proposal) {
        expect($proposal['source'])->not->toBe($proposal['target']);
    }
});

it('gives each old address exactly one destination', function () {
    /*
     * The ambiguity worry, settled by the schema rather than by a guard.
     *
     * The flat address is built from the leaf slug, so the question is whether
     * two categories can both claim `/product-category/serums/`. They cannot:
     * `categories.slug` is unique, so the source is unique by construction. A
     * `$leafCounts[$slug] > 1` branch was written in RedirectMap first and the
     * database refuses to produce a row that reaches it — dead code of exactly
     * the kind `Api\ProductController`'s status filter already cost this
     * repository. This pins the invariant that makes the guard unnecessary,
     * which is the thing that would actually break if the unique index were
     * ever dropped.
     */
    urlMapImport();

    // The demo catalogue plus the fixture: a real tree, not two tidy rows.
    expect(Category::query()->count())->toBeGreaterThan(5);

    $proposals = (new RedirectMap)->propose();

    $sources = array_column(proposalsFor(RedirectMap::MIGRATE, $proposals), 'source');

    expect($sources)->not->toBeEmpty()
        ->and(array_values(array_unique($sources)))->toBe($sources);
});

it('sends two rules claiming one address to the owner rather than picking one', function () {
    urlMapImport();

    /*
     * The collision that CAN happen: a permalink export naming an address the
     * category rule also derived, pointing somewhere else. The permalink is a
     * record of what the site really served, so it wins — and the loser is
     * still reported rather than dropped.
     */
    $proposals = (new RedirectMap)->propose([
        [
            'type' => 'category',
            'wc_id' => '15',
            'permalink' => 'https://kbeautybliss.com/product-category/face-cleansers/',
        ],
    ]);

    $claims = array_values(array_filter(
        $proposals,
        static fn (array $p): bool => $p['source'] === '/product-category/face-cleansers/',
    ));

    expect($claims)->toHaveCount(2);

    $decisions = array_column($claims, 'decision');

    sort($decisions);

    expect($decisions)->toBe([RedirectMap::ASK, RedirectMap::MIGRATE]);

    $loser = array_values(array_filter(
        $claims,
        static fn (array $p): bool => $p['decision'] === RedirectMap::ASK,
    ))[0];

    expect($loser['reason'])->toContain('permalink export');
});

it('writes nothing at all unless it is told to', function () {
    urlMapImport();

    $before = Redirect::query()->count();

    $this->artisan('kbb:import-redirects')->assertExitCode(0);

    expect(Redirect::query()->count())->toBe($before);
});

it('is idempotent: a second write creates nothing', function () {
    urlMapImport();

    $this->artisan('kbb:import-redirects --write')->assertExitCode(0);

    $after = Redirect::query()->count();
    $rows = Redirect::query()->orderBy('source')->get(['source', 'target'])->toArray();

    expect($after)->toBeGreaterThan(0);

    $this->artisan('kbb:import-redirects --write')->assertExitCode(0);

    expect(Redirect::query()->count())->toBe($after)
        ->and(Redirect::query()->orderBy('source')->get(['source', 'target'])->toArray())->toBe($rows);
});

it('rolls back exactly what it wrote and leaves an admin\'s own redirect alone', function () {
    urlMapImport();

    $this->artisan('kbb:import-redirects --write')->assertExitCode(0);

    $written = Redirect::query()->where('auto_created', true)->count();

    expect($written)->toBeGreaterThan(0);

    // An admin's own row, on a source this map does not touch.
    Redirect::query()->create([
        'source' => '/an-admin-decision/',
        'target' => '/shop/',
        'code' => 301,
        'enabled' => true,
        'auto_created' => false,
    ]);

    // And one the map DID write, which an admin has since re-pointed.
    $edited = Redirect::query()->where('source', '/product-category/face-cleansers/')->firstOrFail();
    $edited->update(['target' => '/somewhere-a-person-chose/']);

    $this->artisan('kbb:import-redirects --rollback')->assertExitCode(0);

    expect(Redirect::query()->where('source', '/an-admin-decision/')->exists())->toBeTrue()
        ->and(Redirect::query()->where('source', '/product-category/face-cleansers/')->value('target'))
        ->toBe('/somewhere-a-person-chose/')
        ->and(Redirect::query()->where('source', '/product-category/makeup-removers/')->exists())->toBeFalse();
});

it('does not overrule a redirect an admin created by hand', function () {
    urlMapImport();

    Redirect::query()->create([
        'source' => '/product-category/makeup-removers/',
        'target' => '/a-landing-page/',
        'code' => 301,
        'enabled' => true,
        'auto_created' => false,
    ]);

    $map = new RedirectMap;
    $diff = $map->diff($map->propose());

    $sources = array_column($diff['conflict'], 'source');

    expect($sources)->toContain('/product-category/makeup-removers/');

    $this->artisan('kbb:import-redirects --write')->assertExitCode(1);

    expect(Redirect::query()->where('source', '/product-category/makeup-removers/')->value('target'))
        ->toBe('/a-landing-page/');
});

it('cannot redirect an address that lives in a query string, and says so', function () {
    urlMapImport();

    $proposals = (new RedirectMap)->propose([
        ['type' => 'product', 'wc_id' => '4021', 'permalink' => 'https://kbeautybliss.com/?p=4021'],
    ]);

    $asked = proposalsFor(RedirectMap::ASK, $proposals);
    $reasons = implode(' | ', array_column($asked, 'reason'));

    expect($reasons)->toContain('getPathInfo');
});

it('asks rather than guesses when a permalink names something that was not imported', function () {
    urlMapImport();

    $proposals = (new RedirectMap)->propose([
        ['type' => 'product', 'wc_id' => '999999', 'permalink' => 'https://kbeautybliss.com/product/long-gone/'],
    ]);

    $proposal = proposalFor('/product/long-gone/', $proposals);

    expect($proposal)->not->toBeNull()
        ->and($proposal['decision'])->toBe(RedirectMap::ASK)
        ->and($proposal['reason'])->toContain('never imported');
});

it('discards a permalink whose address did not change', function () {
    urlMapImport();

    $product = Product::query()->where('wc_id', 4021)->firstOrFail();

    $proposals = (new RedirectMap)->propose([
        ['type' => 'product', 'wc_id' => '4021', 'permalink' => 'https://kbeautybliss.com/product/'.$product->slug.'/'],
    ]);

    $proposal = proposalFor('/product/'.$product->slug.'/', $proposals);

    expect($proposal)->not->toBeNull()
        ->and($proposal['decision'])->toBe(RedirectMap::DISCARD);
});

it('classifies every proposal into exactly one of the three buckets', function () {
    urlMapImport();

    $proposals = (new RedirectMap)->propose();

    expect($proposals)->not->toBeEmpty();

    foreach ($proposals as $proposal) {
        expect([RedirectMap::MIGRATE, RedirectMap::DISCARD, RedirectMap::ASK])
            ->toContain($proposal['decision']);

        // Every row carries something a person can act on.
        expect($proposal['reason'])->not->toBe('');
        expect($proposal['subject'])->not->toBe('');
    }
});

/* -------------------------------------------------------- the media audit */

it('splits a comma-separated gallery, which is the shape WooCommerce exports', function () {
    /*
     * THE REGRESSION THIS EXISTS FOR, found by kbb:import-media and invisible
     * to every row count.
     *
     * ProductImporter split the gallery on "|" and fell back to "," only when
     * the result was empty — but Row::list() returns the whole unsplit string
     * as one element, which is never empty, so the comma branch was
     * unreachable. WooCommerce's own exporter comma-separates, so every
     * multi-image product imported with its entire gallery as a single entry
     * that is not a URL, and the product page rendered one broken image
     * instead of the real ones.
     */
    urlMapImport();

    $product = Product::query()->where('wc_id', 4021)->firstOrFail();
    $images = (array) $product->images;

    expect($images)->toHaveCount(2)
        ->and($images[0])->toBe('https://kbeautybliss.com/wp-content/uploads/2019/03/ginseng-serum-2.jpg')
        ->and($images[1])->toBe('https://kbeautybliss.com/wp-content/uploads/2019/03/ginseng-serum-3.jpg');

    // The failure mode itself, stated: no entry may still be two URLs.
    foreach ($images as $image) {
        expect($image)->not->toContain(',');
    }
});

it('still splits a pipe-separated gallery, which other exporters write', function () {
    urlMapImport();

    Product::query()->where('wc_id', 4021)->update(['images' => []]);

    $dir = sys_get_temp_dir().'/kbb-gallery-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    try {
        file_put_contents($dir.'/products.csv', implode("\n", [
            'id,name,slug,sku,status,type,regular_price,stock_status,images',
            '4021,Ginseng Serum,serum-4021,KBB-4021,publish,simple,99.50,instock,"/a.jpg|/b.jpg|/c.jpg"',
        ])."\n");

        (new ImportRunner)->run(new ImportOptions(directory: $dir, adoptBySlug: true));

        expect((array) Product::query()->where('wc_id', 4021)->firstOrFail()->images)
            ->toBe(['/a.jpg', '/b.jpg', '/c.jpg']);
    } finally {
        @unlink($dir.'/products.csv');
        @rmdir($dir);
    }
});

it('names an image that is still served by the old shop', function () {
    urlMapImport();

    Product::query()->where('wc_id', 4021)->update([
        'image' => 'https://kbeautybliss.com/wp-content/uploads/2021/03/ginseng.jpg',
    ]);

    $rows = (new MediaAudit)->audit();

    $remote = array_values(array_filter(
        $rows,
        static fn (array $r): bool => $r['verdict'] === MediaAudit::REMOTE,
    ));

    expect($remote)->not->toBeEmpty();

    $urls = array_column($remote, 'url');

    expect($urls)->toContain('https://kbeautybliss.com/wp-content/uploads/2021/03/ginseng.jpg');

    $first = $remote[0];

    expect($first['decision'])->toBe(RedirectMap::ASK)
        ->and($first['reason'])->toContain('switched off');
});

it('names an image the catalogue points at and the disk does not have', function () {
    urlMapImport();

    Product::query()->where('wc_id', 4021)->update([
        'image' => '/uploads/2021/03/definitely-not-here.jpg',
        'images' => ['/uploads/2021/03/also-not-here.jpg'],
    ]);

    $rows = (new MediaAudit)->audit();

    $missing = array_values(array_filter(
        $rows,
        static fn (array $r): bool => $r['verdict'] === MediaAudit::MISSING,
    ));

    $paths = array_column($missing, 'path');

    expect($paths)->toContain('/uploads/2021/03/definitely-not-here.jpg')
        ->and($paths)->toContain('/uploads/2021/03/also-not-here.jpg');

    // The owner has to be able to tell WHICH product, not just how many.
    $owners = array_column($missing, 'owner');

    expect(implode(' ', $owners))->toContain('serum-4021');
});

it('accepts an image that really is on disk', function () {
    urlMapImport();

    $relative = 'uploads/kbb-media-audit-'.bin2hex(random_bytes(4)).'.png';
    $full = public_path($relative);

    @mkdir(dirname($full), 0777, true);
    file_put_contents($full, 'not really a png, but it is a file');

    try {
        Product::query()->where('wc_id', 4021)->update(['image' => '/'.$relative, 'images' => []]);

        $rows = (new MediaAudit)->audit();

        $mine = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['path'] === '/'.$relative,
        ));

        expect($mine)->toHaveCount(1)
            ->and($mine[0]['verdict'])->toBe(MediaAudit::PRESENT)
            ->and($mine[0]['decision'])->toBe(RedirectMap::MIGRATE);
    } finally {
        @unlink($full);
    }
});

it('reads a product with no images at all without inventing one', function () {
    urlMapImport();

    Product::query()->where('wc_id', 4021)->update(['image' => null, 'images' => []]);

    $rows = (new MediaAudit)->audit();

    $owners = array_column($rows, 'owner');

    expect(implode(' ', $owners))->not->toContain('serum-4021');
});

it('reports the audit as a failure while anything would break, so a script notices', function () {
    urlMapImport();

    Product::query()->where('wc_id', 4021)->update([
        'image' => 'https://kbeautybliss.com/wp-content/uploads/gone.jpg',
    ]);

    $this->artisan('kbb:import-media')->assertExitCode(1);
});
