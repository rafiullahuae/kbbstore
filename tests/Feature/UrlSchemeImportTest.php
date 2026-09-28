<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Models\Redirect;
use App\Services\Import\RedirectMap;
use App\Support\UrlScheme;

/**
 * The second half of the address scheme: an address a visitor arrives on from
 * Google lands on the right page, and the import is what writes the rows the
 * shop cannot derive for itself.
 *
 * ── WHAT THE IMPORT WRITES, AND WHAT IT DELIBERATELY DOES NOT ───────────────
 *
 * The scheme shipped a forwarding ROUTE for every retired address it created —
 * /collections/…, /brands/…, /brand/{slug}/,
 * /blog/… and /{slug}/ at the site root. Where a route already makes
 * the hop, `RedirectMap::reachable()` sees MOVED, compares the destination with
 * its own, finds them identical and DISCARDS with the reason on the row. That is
 * the settled decision this repository already carries (docs/GP-ADDRESSES-LAND
 * §13.7): a written row restates a hop the application makes for itself, and
 * unlike the application's hop a row does not follow a rename — it goes on
 * pointing at import-day's path after that path has become a 404.
 *
 * What the routes CANNOT derive is what the map has to write, and it is the
 * whole of the migrate bucket:
 *
 *   a category whose flat WooCommerce root address is not one of the fifteen in
 *   LegacyCategoryUrls::PATHS;
 *
 *   an article whose slug CHANGED on import — a SlugGuard collision with a
 *   reserved slug is the real case, and it is exactly the address the root
 *   forwarding route 404s;
 *
 *   a product, on a shop whose WooCommerce product base was not `/product`.
 *
 * Every test below is one of those three, or the proof that a re-run adds none.
 */
function schemeMapProposals(): array
{
    return (new RedirectMap)->propose(schemePermalinks());
}

/** @return list<array<string, string>> */
function schemePermalinks(): array
{
    return [
        // A product at WooCommerce's default base — the same shape this shop
        // serves, which is the finding the scheme rests on.
        ['type' => 'product', 'wc_id' => 9001, 'permalink' => 'https://kbeautybliss.com/product/schemed-serum/'],
        // A category at the flat root, which is what kbeautybliss.com served
        // (`category_base` was the empty string).
        ['type' => 'category', 'wc_id' => 9101, 'permalink' => 'https://kbeautybliss.com/schemed-cat/'],
        // An article at the site root, whose slug changed on the way in.
        ['type' => 'post', 'wc_id' => 9201, 'permalink' => 'https://kbeautybliss.com/schemed-article-old/'],
        // A brand archive the old site really published.
        ['type' => 'brand', 'wc_id' => 9301, 'permalink' => 'https://kbeautybliss.com/product-brand/schemed-brand/'],
    ];
}

/**
 * The proposal for one old address.
 *
 * A MIGRATE one wins when there is one, because two rules can legitimately
 * describe the same address from two directions — the permalink export and the
 * derived flat-root rule both cover an imported category — and resolve()
 * collapses the agreement by keeping one and discarding the other. Returning
 * whichever came first would make this helper's answer depend on rule order.
 */
function schemeProposalFor(string $source, array $proposals): ?array
{
    $found = null;

    foreach ($proposals as $proposal) {
        if ($proposal['source'] !== $source) {
            continue;
        }

        if ($proposal['decision'] === RedirectMap::MIGRATE) {
            return $proposal;
        }

        $found ??= $proposal;
    }

    return $found;
}

/** How many rules ended up proposing a row for one address. */
function schemeMigrateCountFor(string $source, array $proposals): int
{
    return count(array_filter(
        $proposals,
        static fn (array $p): bool => $p['source'] === $source && $p['decision'] === RedirectMap::MIGRATE,
    ));
}

beforeEach(function () {
    Product::updateOrCreate(
        ['slug' => 'schemed-serum'],
        ['name' => 'Schemed Serum', 'wc_id' => 9001, 'price' => 9900, 'status' => 'publish', 'is_visible' => true],
    );

    Category::updateOrCreate(
        ['slug' => 'schemed-cat'],
        ['name' => 'Schemed Cat', 'source_term_id' => 9101, 'path' => 'schemed-cat', 'depth' => 0],
    );

    // The slug MOVED on import: WordPress served /schemed-article-old/ and this
    // shop carries the article as `schemed-article-new`.
    Post::updateOrCreate(
        ['slug' => 'schemed-article-new'],
        [
            'source_post_id' => 9201, 'title' => 'Schemed article', 'excerpt' => 'x',
            'body' => '<p>x</p>', 'status' => 'published', 'published_at' => now()->subDay(),
        ],
    );

    Brand::updateOrCreate(['slug' => 'schemed-brand'], ['name' => 'Schemed Brand', 'source_term_id' => 9301]);
});

/* ═════════════════════════════════════════ where each kind of row points ══ */

it('points an imported category at its collections address', function () {
    /*
     * MUTATION NOTE. Point RedirectMap::categoryPath() back at
     * '/product-category/'.$path.'/' and this is red: the target is the retired
     * base, which would 301 again through CategoryArchiveController::show().
     */
    $proposal = schemeProposalFor('/schemed-cat/', schemeMapProposals());

    expect($proposal)->not->toBeNull('the map proposed nothing for the category\'s old flat address');
    expect($proposal['target'])->toBe('/collections/schemed-cat/');
    expect($proposal['decision'])->toBe(RedirectMap::MIGRATE, $proposal['reason']);

    // ONE row, not two. The permalink export and the derived flat-root rule both
    // describe this address; resolve() keeps the permalink one and discards the
    // other, because two identical rows are not a question for anybody.
    expect(schemeMigrateCountFor('/schemed-cat/', schemeMapProposals()))->toBe(1);
});

it('points an imported article at its blog address', function () {
    /*
     * The slug moved on import, so the old root address names no published post
     * and PageController::rootArticle() 404s it. Nothing the shop can derive
     * covers this — it is exactly the row the map exists to write.
     *
     * MUTATION NOTE. Make currentPathFor() return '/'.$post->slug.'/' for a
     * post, as it did before the scheme, and the target assertion is red.
     */
    $proposal = schemeProposalFor('/schemed-article-old/', schemeMapProposals());

    expect($proposal)->not->toBeNull('the map proposed nothing for the article\'s WordPress address');
    expect($proposal['target'])->toBe('/blog/schemed-article-new/');
    expect($proposal['decision'])->toBe(RedirectMap::MIGRATE, $proposal['reason']);
});

it('points an imported brand archive at a real brand page', function () {
    /*
     * ── THE BIGGEST WIN, AND IT IS NEW WORK RATHER THAN A RENAME ───────────
     *
     * currentPathFor() answered a brand with '/shop/?filter_brands='.$slug,
     * under a note saying U-05 left this shop with no brand archive at all. A
     * 301 onto a filtered shop listing lands on a page that canonicalises to
     * /shop/, which tells Google the brand page does not exist — so every brand
     * archive the old install had was being redirected into nothing.
     *
     * MUTATION NOTE. Restore the query-string target and this is red on both
     * lines: the target, and the decision, because a query string cannot be a
     * canonical destination the way a page can.
     */
    $proposal = schemeProposalFor('/product-brand/schemed-brand/', schemeMapProposals());

    expect($proposal)->not->toBeNull('the map proposed nothing for the brand archive');
    expect($proposal['target'])->toBe('/brands/schemed-brand/');
    expect($proposal['decision'])->toBe(RedirectMap::MIGRATE, $proposal['reason']);
});

/* ══════════════════════════════════════════ products: proved, not assumed ══ */

it('finds that a product on the default base needs no row', function () {
    /*
     * THE SCHEME'S CENTRAL CLAIM, checked against the export rather than argued
     * from the shape of the address. permalinks.csv records what
     * get_permalink() really answered, and manifest.json carries the setting
     * that produced it.
     *
     * MUTATION NOTE. Change UrlScheme::PRODUCT_BASE to '/shop/' and this is red:
     * the proposal becomes a MIGRATE with a target under /shop/, which is the
     * catalogue moving — the one thing this scheme promises it does not do.
     */
    $proposal = schemeProposalFor('/product/schemed-serum/', schemeMapProposals());

    expect($proposal)->not->toBeNull('the map said nothing at all about the product');
    expect($proposal['decision'])->toBe(RedirectMap::DISCARD, $proposal['reason']);
    expect($proposal['reason'])->toContain('identical');
});

it('reads the product base off the export rather than assuming it', function () {
    $manifest = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/kbb-export/manifest.json')),
        true,
    );

    // The reference export: the base is /product, which is this shop's own.
    expect($manifest['source']['woocommerce_permalinks']['product_base'])->toBe('/product');
    expect(UrlScheme::productBaseMoved($manifest))->toBeFalse();

    /*
     * AND A SHOP WHOSE BASE WAS NOT /product DOES NEED ROWS. This is the half
     * that cannot be read off the reference export, so it is constructed.
     *
     * MUTATION NOTE. Make productBaseMoved() return false unconditionally and
     * this line is red.
     */
    $moved = $manifest;
    $moved['source']['woocommerce_permalinks']['product_base'] = '/shop';

    expect(UrlScheme::productBaseMoved($moved))->toBeTrue();

    // And the map writes the row without anything in it changing, because it
    // compares the exported permalink with the address this shop serves.
    $proposals = (new RedirectMap)->propose([
        ['type' => 'product', 'wc_id' => 9001, 'permalink' => 'https://kbeautybliss.com/shop/schemed-serum/'],
    ]);

    $proposal = schemeProposalFor('/shop/schemed-serum/', $proposals);

    expect($proposal)->not->toBeNull('a moved product base produced no proposal at all');
    expect($proposal['target'])->toBe('/product/schemed-serum/');
});

/* ══════════════════════════════════════════════════ the write, and re-runs ══ */

it('writes the migrate bucket and adds nothing on a second run', function () {
    /*
     * THE IMPORT RUNS MANY TIMES BY DESIGN and a re-run must add no duplicate
     * rows. `diff()` keys on `source` and `updateOrCreate` writes on it, so the
     * second pass finds every row unchanged and creates none.
     *
     * MUTATION NOTE. Change diff() to push every proposal into `create` rather
     * than comparing against the existing row, and the second expectation is
     * red — `create` is non-empty on the second pass.
     */
    $map = new RedirectMap;

    $write = static function (array $diff) use (&$written): void {
        foreach (array_merge($diff['create'], $diff['update']) as $proposal) {
            Redirect::query()->updateOrCreate(
                ['source' => $proposal['source']],
                ['target' => $proposal['target'], 'code' => 301, 'enabled' => true, 'auto_created' => true],
            );
        }
    };

    $first = $map->diff($map->propose(schemePermalinks()));
    expect($first['create'])->not->toBeEmpty('the map wrote nothing at all');
    $write($first);

    $countAfterFirst = Redirect::query()->count();
    $rowsAfterFirst = Redirect::query()->orderBy('source')->pluck('target', 'source')->all();

    $second = $map->diff($map->propose(schemePermalinks()));

    expect($second['create'])->toBe([], 'a re-run proposes rows that already exist');
    expect($second['update'])->toBe([], 'a re-run proposes to change rows it has just written');

    $write($second);

    expect(Redirect::query()->count())->toBe($countAfterFirst, 'a re-run added rows');
    expect(Redirect::query()->orderBy('source')->pluck('target', 'source')->all())->toEqual($rowsAfterFirst);
});

it('writes no row whose target is itself another row\'s source', function () {
    /*
     * NO CHAINS. A row pointing at an address another row moves is two hops, and
     * resolve()'s collapsing pass exists to stop it. This asserts the PROPERTY
     * over the whole migrate bucket rather than over one hand-picked pair, so a
     * rule added later cannot reintroduce a chain unnoticed.
     */
    $migrate = array_values(array_filter(
        schemeMapProposals(),
        static fn (array $p): bool => $p['decision'] === RedirectMap::MIGRATE,
    ));

    $sources = array_column($migrate, 'source');

    foreach ($migrate as $proposal) {
        expect(in_array($proposal['target'], $sources, true))
            ->toBeFalse($proposal['source'].' points at '.$proposal['target']
                .', which is another row\'s source — that is a chain, and one move means one hop');
    }

    // And no source is claimed twice, which would be two rows racing for one
    // address.
    expect(count(array_unique($sources)))->toBe(count($sources), 'two rows claim the same old address');
});

it('never writes a row whose target is the retired spelling of an address', function () {
    /*
     * The cheapest way to get a URL move wrong is to point the old base at the
     * new base and let the shop's own canonicalisation finish the job. Every
     * target in the bucket is checked against the three retired bases, so a rule
     * that reached for a literal instead of App\Support\UrlScheme is red here.
     *
     * MUTATION NOTE. Point RedirectMap::categoryPath() at legacyCategoryPath()
     * and this is red on the category row.
     */
    foreach (schemeMapProposals() as $proposal) {
        if ($proposal['decision'] !== RedirectMap::MIGRATE) {
            continue;
        }

        foreach ([
            UrlScheme::LEGACY_COLLECTION_BASE,
            UrlScheme::LEGACY_BRAND_INDEX,
            UrlScheme::LEGACY_BLOG_INDEX,
        ] as $retired) {
            expect(str_starts_with($proposal['target'], $retired))
                ->toBeFalse($proposal['source'].' points at '.$proposal['target']
                    .', which is an address this shop 301s away from');
        }
    }
});

it('discards rather than writes an address the shop already forwards', function () {
    /*
     * The other half of "no chains", and the reason the migrate bucket is
     * SMALLER after the scheme rather than bigger — which reads like a
     * regression until the reason is on the row.
     *
     * /collections/{leaf}/ is forwarded by CategoryArchiveController::show()
     * to exactly the address this map would write. A row would restate it, and
     * unlike the shop's own answer a row does not follow a rename.
     *
     * MUTATION NOTE. Delete legacyCategoryVerdict() from SourceReachability so
     * /collections/… falls through to the router, and this is red: the
     * proposal becomes MIGRATE and the shop grows a row that can rot.
     */
    $proposal = schemeProposalFor('/product-category/schemed-cat/', schemeMapProposals());

    expect($proposal)->not->toBeNull('the nesting rule proposed nothing for the retired base');
    expect($proposal['decision'])->toBe(RedirectMap::DISCARD, $proposal['reason']);
    expect($proposal['reason'])->toContain('already sends this address');
});
