<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * A set is findable, and readable as a set, in Catalog → Products. (Lane SP)
 *
 * Lane SET's report: `CatalogProductsApiController`'s `type` filter is a
 * pass-through, so a set is listed among the ordinary products with nothing to
 * mark it. The lane left it deliberately — "a set IS a product" — and offered a
 * chip. This is the chip, its count, its list, and the marker on the row.
 *
 * THE ENDPOINT IS ALREADY MOUNTED. /admin-api/catalog-products-list is required
 * from routes/web.php today, so unlike the Sets endpoints there is nothing to
 * wire and nothing to mount in a harness: these are real requests through the
 * real route.
 */
function chipAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Chip owner',
        'email' => 'chip-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/**
 * ▲ EVERY FIXTURE CARRIES A TOKEN AND EVERY REQUEST SEARCHES FOR IT.
 *
 * The migration set seeds a demo catalogue, so this database is NOT empty and
 * an assertion on a bare `total` is an assertion about the seeder. Searching
 * narrows the base query to this case's own rows -- and the chip counts are
 * computed over that SAME narrowed query (chipCounts() takes baseQuery()),
 * which is the property being relied on and, incidentally, tested.
 */
function chipToken(): string
{
    static $token = null;

    return $token ??= 'kspchip'.Str::lower(Str::random(8));
}

function chipProduct(string $name, string $type): Product
{
    return Product::create([
        'slug' => 'chip-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name.' '.chipToken(),
        'type' => $type,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
    ]);
}

it('counts the sets in the catalogue and lists exactly those when the chip is pressed', function () {
    /*
     * ── WHAT IT LOOKED LIKE ────────────────────────────────────────────────
     *
     * Before this, `filter=set` fell through applyFilter()'s last line, which
     * reads anything it does not recognise as a `products.status` — so the chip
     * asked for products whose STATUS is 'set', matched nothing, and showed the
     * owner an empty list. There was no count beside it either, because `set`
     * was not in DERIVED_FILTERS.
     *
     * MUTATION NOTE. Delete the `if ($filter === 'set')` branch from
     * CatalogProductsApiController::applyFilter() and the second half is red:
     * the list comes back empty while the count beside it still says 2. RUN.
     *
     * ▲ AND THE COUNT IS A SEPARATE MUTATION. Remove the `as set_type` line
     *   from chipCounts()'s derived aggregate and the first expectation is red
     *   — the chip draws with a 0 next to it while the list under it has two
     *   rows in it. RUN.
     */
    chipProduct('Ordinary Toner', 'simple');
    chipProduct('Ordinary Serum', 'simple');
    $setA = chipProduct('Glow Starter Set', 'set');
    $setB = chipProduct('Night Repair Set', 'set');

    $this->actingAs(chipAdmin(), 'admin');

    $all = $this->getJson('/admin-api/catalog-products-list?search='.chipToken())->assertOk()->json();

    expect($all['counts']['set'] ?? null)->toBe(2, 'The Sets chip must carry a count.')
        ->and($all['total'])->toBe(4);

    $sets = $this->getJson('/admin-api/catalog-products-list?filter=set&search='.chipToken())->assertOk()->json();

    expect($sets['total'])->toBe(2);
    expect(array_column($sets['products'], 'id'))
        ->toEqualCanonicalizing([$setA->id, $setB->id]);
});

it('marks each row so the operator can tell what they are looking at', function () {
    /*
     * `type` has always been on this row and the screen has never drawn it.
     * `is_set` is derived server-side rather than compared in the console's
     * JavaScript, so the string that means "set" is decided in ONE place in
     * this application.
     *
     * MUTATION NOTE. Delete the `'is_set' => ...` line from rowToApi() and
     * this is red — and on the screen every set looks exactly like a toner.
     * RUN.
     */
    chipProduct('Ordinary Toner', 'simple');
    $set = chipProduct('Glow Starter Set', 'set');

    $this->actingAs(chipAdmin(), 'admin');

    $rows = collect($this->getJson('/admin-api/catalog-products-list?search='.chipToken())->assertOk()->json('products'))
        ->keyBy('id');

    expect($rows[$set->id]['is_set'])->toBeTrue()
        ->and($rows[$set->id]['type'])->toBe('set');

    foreach ($rows as $id => $row) {
        if ($id === $set->id) {
            continue;
        }

        expect($row['is_set'])->toBeFalse('Only a set may be marked as one.');
    }
});

it('still filters by an ordinary type, and drops one that is not a type at all', function () {
    /*
     * The `type` query parameter stays a pass-through — `products.type` stores
     * an unknown value verbatim (docs/PRODUCT-FIELD-PARITY.md row 6), so an
     * allowlist of the four WooCommerce types would HIDE whatever the importer
     * actually wrote. What it is now is bounded in SHAPE: a short identifier.
     *
     * This is not an injection fix — the value was always a bound parameter —
     * it is CLAUDE.md rule 5 applied to a filter on a console endpoint.
     *
     * MUTATION NOTE. Remove the preg_match() guard from baseQuery()'s `type`
     * branch and the last expectation is red: a 300-character request value
     * reaches the WHERE clause and the endpoint answers an empty list, which
     * reads as a fact about the catalogue rather than as a rejected request.
     * RUN.
     */
    chipProduct('Ordinary Toner', 'simple');
    chipProduct('Glow Starter Set', 'set');
    chipProduct('Imported Oddity', 'grouped');

    $this->actingAs(chipAdmin(), 'admin');

    $ask = fn (string $type) => $this->getJson(
        '/admin-api/catalog-products-list?search='.chipToken().'&type='.$type
    )->assertOk()->json('total');

    expect($ask('simple'))->toBe(1);
    expect($ask('set'))->toBe(1);

    // A value the importer really could have written is still honoured.
    expect($ask('grouped'))->toBe(1);

    // And something that is not an identifier is dropped rather than answered.
    expect($ask(str_repeat('a', 300)))
        ->toBe(3, 'A request value that cannot be a type is dropped, not answered with an empty catalogue.');
});
