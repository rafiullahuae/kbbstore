<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Review;
use App\Support\ProductVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Four costs the storefront was paying that nothing was watching, each pinned
 * here at the level of its cause rather than as "the page got slower".
 *
 * StorefrontQueryBudgetTest already guards the TOTAL per page and that the
 * total does not grow with the catalogue. A budget cannot say WHY a page costs
 * what it does, and three of the four things below are invisible to it:
 *
 *   - a query count that is right but spent on the wrong thing (four of the
 *     brand index's six queries were MySQL describing its own columns),
 *   - a query that reads columns the page never renders and must not publish,
 *   - a third-party stylesheet on the critical path, which is not a query at
 *     all,
 *   - an index, which changes rows examined and not query count.
 *
 * Measured on MySQL 8.0 against a production-shaped fixture — 671 products, 93
 * brands, 8,000 reviews, 1,724 orders over four years — through the real HTTP
 * kernel, one fresh application per request, file cache, which is what this
 * host runs.
 */

/**
 * ONE SCHEMA READ PER CALL, NOT FOUR.
 *
 * ProductVisibility::raw() guards each of its four conditions on the column
 * existing, and each guard was its own Schema::hasColumn() — four separate
 * round trips to information_schema for four columns of one table, on every
 * call.
 *
 * That is not a theoretical cost. /korean-skincare-brands is in the main
 * navigation and ran SIX queries, of which FOUR were
 * `select column_name ... from information_schema.columns where table_name =
 * 'products'`: two thirds of the page's database work was the database
 * describing itself. /sitemap.xml, which a crawler fetches far more often than
 * any shopper fetches anything, ran eighteen and twelve were introspection.
 *
 * information_schema is also the worst possible place to spend a query on
 * shared hosting. It is not a table but a view assembled from server-wide
 * metadata, and on a host where one MySQL instance carries every tenant's
 * schemas, the cost scales with the NEIGHBOURS' tables rather than with this
 * shop's. 1ms on a quiet developer box is not 1ms there.
 *
 * Read fresh on every call, deliberately NOT memoised in a static. The doc
 * comment on raw() says it is callable from a migration and against a
 * partially-migrated schema, and that is precisely the situation where a
 * process-level memo would answer with the shape the table had before the
 * ALTER — the exact trap CLAUDE.md records against Setting::map(). One read per
 * call is correct under every caller, including a migration that just added a
 * column, and it is still a quarter of the queries.
 */
it('asks the schema once per call, not once per column', function () {
    $seen = [];

    DB::listen(function ($query) use (&$seen) {
        $sql = strtolower($query->sql);

        // MySQL answers Schema::hasColumn from information_schema; SQLite
        // answers it with a pragma. Count either, so this test means the same
        // thing on both engines the suite runs.
        if (str_contains($sql, 'information_schema') || str_contains($sql, 'pragma_table_info')
            || str_contains($sql, 'pragma table_info')) {
            $seen[] = $query->sql;
        }
    });

    ProductVisibility::raw(DB::table('products'));

    expect(count($seen))->toBeLessThanOrEqual(1,
        'ProductVisibility::raw() guards four columns and asked the database about its own '
        . 'schema ' . count($seen) . ' times. One read of the column list serves all four. '
        . "Statements:\n  " . implode("\n  ", $seen));
});

/**
 * The same thing measured where a shopper actually pays it.
 *
 * The assertion above is about the helper; this one is about the page, and it
 * is the one that fails if some other caller starts introspecting per column
 * again. The brand index is chosen because it is a main-navigation page whose
 * entire server cost is one aggregate query plus this.
 */
it('does not spend the brand index on schema introspection', function () {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);

    // Warm first: the page's own caches are not what is being measured.
    $this->get('/korean-skincare-brands')->assertSuccessful();

    $introspection = 0;

    DB::listen(function ($query) use (&$introspection) {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'information_schema') || str_contains($sql, 'pragma_table_info')
            || str_contains($sql, 'pragma table_info')) {
            $introspection++;
        }
    });

    $this->get('/korean-skincare-brands')->assertSuccessful();

    expect($introspection)->toBeLessThanOrEqual(1,
        "The brand index ran {$introspection} schema-introspection queries. It ran four before "
        . 'ProductVisibility::raw() read the column list once, and four of its six queries were '
        . 'the database describing itself.');
});

/**
 * THE REVIEW WALL READS THE COLUMNS IT RENDERS, AND NO OTHERS.
 *
 * The home page's review wall was `Review::query()->approved()->...->get()`
 * with no column list, so every one of those rows arrived carrying
 * `author_email` and `ip`.
 *
 * CLAUDE.md names those two columns specifically, and ApiSecurityTest exists
 * because each of them leaked in production. Nothing on the home page prints
 * them — the card renders author_name, rating, verified, content, created_at,
 * helpful and the product's name — so the widest thing the page could do with
 * them is the only thing it could do: hand them to a template and hope. An
 * explicit list means a column added to `reviews` later is private on this page
 * until someone decides otherwise, which is the same rule Product::toApi()
 * follows one controller along.
 *
 * It is a cost as well as a surface. `select *` on this table pulls `content`
 * and `body` for every row it touches.
 */
it('reads only the review columns the home page renders', function () {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    $this->seed(\Database\Seeders\DemoReviewsSeeder::class);

    $wide = [];

    DB::listen(function ($query) use (&$wide) {
        $sql = strtolower($query->sql);

        // A star-bar/aggregate query names its own expressions and is fine; the
        // wall's row fetch is the one that must not be a wildcard.
        if (preg_match('/^select \*\s+from [`"]?reviews[`"]?/', $sql) === 1) {
            $wide[] = $query->sql;
        }
    });

    $this->get('/')->assertSuccessful();

    expect($wide)->toBe([],
        "The home page fetched whole `reviews` rows:\n  " . implode("\n  ", $wide)
        . "\nThat row carries author_email and ip. Name the columns the card renders.");
});

/**
 * A GUEST DOES NOT DOWNLOAD THE ACCOUNT PANEL'S TYPEFACE.
 *
 * layouts/store.blade.php emitted AccountPanel::fontHref() unconditionally, so
 * every page of the storefront carried a SECOND render-blocking Google Fonts
 * stylesheet — Cormorant Garamond by default, since that is what
 * AccountPanel::SCHEMA ships as `welcome_font` — plus the WOFF2 files it pulls
 * in.
 *
 * The only thing that face ever styles is `.ap-greet`, and
 * partials/account-panel.blade.php renders that element inside `@auth`. A
 * signed-out visitor could never see a glyph of it. Storefront traffic is
 * overwhelmingly signed out, and /shop, /product, /cart and /checkout are all
 * signed-out pages for most of the people who reach them.
 *
 * Measured in Chromium at 1280 and 390, same script before and after, it is one
 * fewer render-blocking request in <head> on every guest page: home 15 -> 14,
 * /shop 28 -> 27, product 11 -> 10, /cart 7 -> 6, /checkout 8 -> 7. It is NOT a
 * saved origin — Poppins comes from the same fonts.googleapis.com — and it is
 * not the WOFF2 files either, since a browser fetches a face only when a glyph
 * needs it and on a guest page none does. One blocking request all the same: a
 * <head> stylesheet must be fetched and parsed before the first paint, this host
 * has no CDN in front of it, and the request buys a signed-out visitor nothing.
 *
 * The gate is deliberately WIDER than the element it protects: it passes if
 * EITHER guard is signed in, while .ap-greet needs the default guard and the
 * account menu to be switched on as well. A gate that is too wide leaves an
 * unused stylesheet on some signed-in pages; one that is too narrow takes the
 * font off a page that renders the greeting. Only the second is a defect.
 */
it('does not put the account-panel typeface on a guest page', function () {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);

    $html = $this->get('/')->assertSuccessful()->getContent();

    expect(str_contains($html, 'fonts.googleapis.com/css2?family=Cormorant'))->toBeFalse(
        'A signed-out home page requested the account panel welcome typeface. Nothing on a '
        . 'guest page can render .ap-greet, and that stylesheet is a render-blocking request '
        . 'to a third-party origin on the critical path.');
});

it('still loads the account-panel typeface for a signed-in shopper', function () {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);

    $customer = Customer::create([
        'name' => 'Ada Shopper',
        'email' => 'cost@example.com',
        'password' => 'password123',
    ]);

    // The session key the customer guard reads — not actingAs(), which changes
    // the application's default guard. Same approach as AccountAreaTest.
    $html = $this
        ->withSession(['login_customer_' . sha1(\Illuminate\Auth\SessionGuard::class) => $customer->id])
        ->get('/')
        ->assertSuccessful()
        ->getContent();

    expect(str_contains($html, 'fonts.googleapis.com/css2?family=Cormorant'))->toBeTrue(
        'A signed-in shopper can see the welcome greeting, so the typeface that styles it '
        . 'must still be fetched. The guest gate has been drawn too tight.');
});

/**
 * THE TWO INDEXES THE SHOP-WIDE REVIEW QUERIES SORT AND GROUP ON.
 *
 * Three queries read the whole `reviews` table filtered only by status: the
 * home page's review wall (the latest four), its star distribution, and the
 * COUNT/AVG behind both that wall and the checkout's trust line. `status` was
 * indexed on its own, which locates the approved rows and then leaves MySQL to
 * sort or group all of them.
 *
 * Measured on MySQL 8.0, 8,000 reviews of which 7,650 approved, through the
 * real kernel with a cold cache:
 *
 *   latest four        41.1ms -> 0.3ms    filesort -> backward index scan
 *   star distribution  28.3ms -> 2.0ms    Using temporary -> Using index
 *
 * The first was the single most expensive query anywhere on the storefront.
 *
 * Rows examined, not query count, is what moves — which is exactly why this
 * belongs here and not in the budget file: StorefrontQueryBudgetTest counts
 * three queries before and three after, and is right to.
 *
 * The COUNT/AVG is deliberately left alone. Averaging a rating over every
 * approved review means reading every approved review; MySQL picks
 * reviews_status_index or the new composite and either way examines the same
 * rows. It is cached for fifteen minutes in both places that ask for it. A
 * figure a shopper reads beside a pay button has to come from the reviews
 * table, and making it cheaper by making it approximate is the one thing that
 * must not happen to it.
 */
it('indexes the shop-wide review queries on status with their sort key', function () {
    foreach ([['status', 'created_at'], ['status', 'rating']] as $columns) {
        expect(indexCovers('reviews', $columns))->toBeTrue(
            'reviews (' . implode(', ', $columns) . ') is not indexed. The home page review '
            . 'wall sorts and groups every approved review in the table.');
    }
});

/** Is there any index whose LEADING columns are these, in this order? */
function indexCovers(string $table, array $columns): bool
{
    if (Schema::getConnection()->getDriverName() === 'sqlite') {
        foreach (DB::select("PRAGMA index_list('{$table}')") as $index) {
            $names = array_map(
                static fn ($row) => $row->name,
                DB::select("PRAGMA index_info('{$index->name}')")
            );

            if (array_slice($names, 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    $rows = DB::table('information_schema.STATISTICS')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', $table)
        ->where('SEQ_IN_INDEX', '<=', count($columns))
        ->orderBy('INDEX_NAME')
        ->orderBy('SEQ_IN_INDEX')
        ->get(['INDEX_NAME', 'COLUMN_NAME']);

    $byIndex = [];

    foreach ($rows as $row) {
        $byIndex[$row->INDEX_NAME][] = $row->COLUMN_NAME;
    }

    foreach ($byIndex as $names) {
        if ($names === $columns) {
            return true;
        }
    }

    return false;
}
