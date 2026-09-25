<?php

/*
 * ONE `brands` QUERY PER ROW, ON THE PICKER BEHIND THE RECOMMENDED RAIL.
 *
 * Appearance -> Cart page -> Recommended rail opens a product picker, and its
 * endpoint runs Product::query()->…->get() in BOTH branches -- the search and
 * the resolve-stored-ids one -- with no with('brand'). card() then reads
 * $product->brand?->name for every row, so the picker costs one extra statement
 * per product it draws, PER_PAGE of them.
 *
 * FOUND BY THE LANE THAT BUILT THE COMPONENT LOAD CONTRACT, in the census it ran
 * to price a global preventLazyLoading() switch: 27 lazy Product::$brand reads
 * at this one call site. It could not fix it -- app/Http/Controllers/Admin/ is
 * not that lane's -- so it reported it with the file and the line.
 *
 * WHY A SLOPE AND NOT A NUMBER. This project has now been bitten twice by a
 * budget that caps a total without saying how the total grows: /cart was held to
 * a number for months while it issued one extra statement per basket line,
 * because the budget's own fixture put no variation on any line. The property
 * that matters is that the cost DOES NOT GROW WITH THE NUMBER OF ROWS, so that
 * is what this asserts.
 *
 * THE BLIND SPOT THIS FIXTURE IS SHAPED AROUND. Builder::hydrate() copies the
 * lazy-loading guard onto rows only when the result has more than one
 * (`if (count($items) > 1)`), and more generally a one-row page cannot show a
 * per-row cost at all. Both sizes below therefore draw several rows, and the
 * count is asserted before any statement is compared -- the previous lane found
 * three of its ten fixtures rendering zero of what they varied.
 *
 * MUTATION NOTES, both actually run:
 *   - remove with('brand:id,name,slug') from the SEARCH branch: `it does not
 *     pay a brands query per row it draws` fails alone, reading "eight rows
 *     cost 9 brands statements where three cost 4". The ids case stays green.
 *   - remove it from the IDS branch instead: `it does not pay a brands query
 *     per stored id it resolves` fails alone, "eight ids cost 8 where three
 *     cost 3", and the search case stays green.
 *
 * Each mutation reddens exactly one case, which is the point of there being
 * two: the branches are separate code paths and a with() added to one is not a
 * fix for the other.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An owner to act as.
 *
 * Its own helper rather than CartPageScreenTest's: Pest's function definitions
 * are file-scoped by load order, and a helper borrowed across files is a
 * dependency nothing states. That file also mounts the route group by hand,
 * which this one does not need -- routes/web.php has required
 * cart-page-admin.php since the integrator wired it.
 */
function cppsOwner(): AdminUser
{
    return AdminUser::create([
        'name' => 'Owner',
        'email' => 'cpps-'.Str::random(8).'@example.com',
        'password' => bcrypt('secret'),
        'role' => 'owner',
    ]);
}

/** Products, each with a brand of its own so nothing batches by luck. */
function cppsProducts(int $n, string $term): array
{
    $ids = [];

    foreach (range(1, $n) as $i) {
        $brand = Brand::create([
            'slug' => 'cpps-brand-'.Str::lower(Str::random(8)),
            'name' => 'Brand '.$i.' '.Str::random(4),
        ]);

        $ids[] = Product::create([
            'slug' => 'cpps-'.Str::lower(Str::random(10)),
            'name' => $term.' Toner '.$i,
            'brand_id' => $brand->id,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 5000,
            'stock_status' => 'instock',
        ])->id;
    }

    return $ids;
}

/**
 * Statements naming `brands`, issued answering one picker request.
 *
 * ▲ THE QUERY LOG, NOT DB::listen(), AND THE DIFFERENCE COST AN HOUR.
 * `DB::listen()` registers a listener that is never removed, so calling this
 * helper three times leaves three listeners attached and every one of them
 * keeps counting -- including the fixture inserts made BETWEEN measurements.
 * The earlier calls' counters go on growing after their own request has
 * finished, and the numbers compared are then from different windows. Measured
 * that way, the same request "cost" 3 and 6 with the eager load already in
 * place and demonstrably issuing one `in (...)` statement.
 *
 * The query log is per connection and can be emptied, so each measurement sees
 * exactly its own request and nothing else.
 */
function cppsBrandReads(AdminUser $admin, string $url): array
{
    /*
     * A test does not reboot the container between requests, so a scoped
     * binding answers the second request from the first one's memo and the
     * count measures the memo rather than the query.
     */
    app()->forgetScopedInstances();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $body = test()->actingAs($admin, 'admin')->getJson($url)->assertOk()->json();

    $log = DB::getQueryLog();

    DB::disableQueryLog();
    DB::flushQueryLog();

    return [
        'brands' => count(array_filter($log, fn ($q) => str_contains($q['query'], 'brands'))),
        'total' => count($log),
        'rows' => count($body['products']),
    ];
}

it('does not pay a brands query per row it draws', function () {
    $admin = cppsOwner();
    $term = 'Zqp'.Str::random(6);

    /*
     * Warm-up, discarded: Setting::map() memoises in a process-level static, so
     * the first request of a process is dearer than every later one.
     *
     * A TERM OF ITS OWN, not $term.'warm'. The search is a LIKE '%term%', so a
     * warm-up term that CONTAINS the measured one puts its products into the
     * measured result: three rows became six, and the row-count assertion below
     * is what caught it. That assertion exists for exactly this -- a fixture
     * that did not vary the way its author believed.
     */
    $warmTerm = 'Zqw'.Str::random(6);
    cppsProducts(3, $warmTerm);
    cppsBrandReads($admin, '/admin-api/cart-page/products?q='.$warmTerm);

    cppsProducts(3, $term);
    $three = cppsBrandReads($admin, '/admin-api/cart-page/products?q='.$term);

    cppsProducts(5, $term);
    $eight = cppsBrandReads($admin, '/admin-api/cart-page/products?q='.$term);

    // The fixture really varied. Asserted before any count is compared: a page
    // that drew nothing is flat and cheap and proves nothing.
    expect($three['rows'])->toBe(3)->and($eight['rows'])->toBe(8);

    expect($eight['brands'])->toBe(
        $three['brands'],
        "eight rows cost {$eight['brands']} brands statements where three cost {$three['brands']} "
        .'-- card() is resolving the brand off each product in turn'
    );

    expect($eight['total'])->toBe(
        $three['total'],
        "eight rows cost {$eight['total']} statements where three cost {$three['total']}"
    );
});

it('does not pay a brands query per stored id it resolves', function () {
    /*
     * The other branch, and it is a separate code path: the screen resolves a
     * saved rec_ids list through the same endpoint with ?ids= rather than ?q=,
     * so a with() added to one query does nothing for the other.
     */
    $admin = cppsOwner();
    $term = 'Zqi'.Str::random(6);

    $warm = cppsProducts(3, $term.'warm');
    cppsBrandReads($admin, '/admin-api/cart-page/products?ids='.implode(',', $warm));

    $three = cppsProducts(3, $term);
    $a = cppsBrandReads($admin, '/admin-api/cart-page/products?ids='.implode(',', $three));

    $eight = array_merge($three, cppsProducts(5, $term));
    $b = cppsBrandReads($admin, '/admin-api/cart-page/products?ids='.implode(',', $eight));

    expect($a['rows'])->toBe(3)->and($b['rows'])->toBe(8);

    expect($b['brands'])->toBe(
        $a['brands'],
        "eight ids cost {$b['brands']} brands statements where three cost {$a['brands']}"
    );
});
