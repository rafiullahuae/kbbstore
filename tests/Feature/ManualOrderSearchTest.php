<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Tests\ManualOrders;

/*
|------------------------------------------------------------------------------
| Picking a customer, picking a product, and the packing list
|------------------------------------------------------------------------------
*/

beforeEach(function () {
    ManualOrders::registerRoutes();
    ManualOrders::shop();
    $this->admin = ManualOrders::admin();
});

function moAdmin()
{
    return test()->actingAs(test()->admin, 'admin');
}

/* -------------------------------------------------------- customer search */

it('finds a customer by name, email or phone', function () {
    ManualOrders::customer();
    ManualOrders::customer(['name' => 'Fatima Hassan', 'first_name' => 'Fatima',
        'last_name' => 'Hassan', 'email' => 'fatima@example.ae', 'phone' => '+971559999999']);

    expect(moAdmin()->getJson('/admin-api/manual-orders/customers?q=fatima')
        ->assertOk()->json('customers.0.email'))->toBe('fatima@example.ae');

    expect(moAdmin()->getJson('/admin-api/manual-orders/customers?q=layla@example.ae')
        ->assertOk()->json('customers.0.name'))->toBe('Layla Al Mansoori');

    expect(moAdmin()->getJson('/admin-api/manual-orders/customers?q=559999999')
        ->assertOk()->json('customers.0.email'))->toBe('fatima@example.ae');
});

it('escapes the LIKE wildcards in a search term', function () {
    // % and _ are wildcards inside LIKE and a bound parameter does NOT escape
    // them — binding protects against SQL injection, not pattern injection. A
    // search for "%" that matched every customer in the shop would be a quiet,
    // total disclosure of the customer list.
    ManualOrders::customer(['name' => 'Aisha', 'email' => 'aisha@example.ae', 'phone' => null]);
    ManualOrders::customer(['name' => 'Budget 50% Off Club', 'email' => 'fifty@example.ae', 'phone' => null]);
    ManualOrders::customer(['name' => 'under_score', 'email' => 'us@example.ae', 'phone' => null]);

    // A bare % must be treated as the character, not "everything".
    $all = moAdmin()->getJson('/admin-api/manual-orders/customers?q=' . urlencode('%'))->assertOk();
    expect($all->json('total'))->toBe(1)
        ->and($all->json('customers.0.email'))->toBe('fifty@example.ae');

    // A bare _ must not match any single character.
    $underscore = moAdmin()->getJson('/admin-api/manual-orders/customers?q=' . urlencode('_'))->assertOk();
    expect($underscore->json('total'))->toBe(1)
        ->and($underscore->json('customers.0.email'))->toBe('us@example.ae');

    // And the escape character itself is not a wildcard either.
    ManualOrders::customer(['name' => 'Bang! Beauty', 'email' => 'bang@example.ae', 'phone' => null]);
    $bang = moAdmin()->getJson('/admin-api/manual-orders/customers?q=' . urlencode('Bang!'))->assertOk();
    expect($bang->json('total'))->toBe(1)
        ->and($bang->json('customers.0.email'))->toBe('bang@example.ae');
});

it('counts every match, not just the page it returned', function () {
    // The aggregate is taken through App\Support\AggregatesQueries, from a copy
    // of the builder with its select list, ordering and paging stripped.
    // Counting the paginated builder directly is MySQL error 1140 in strict
    // mode, and this repo has shipped that twice.
    for ($i = 0; $i < 25; $i++) {
        ManualOrders::customer([
            'name' => 'Shopper ' . $i,
            'email' => 'shopper' . $i . '@example.ae',
            'phone' => null,
        ]);
    }

    $first = moAdmin()->getJson('/admin-api/manual-orders/customers?q=Shopper')->assertOk();

    expect($first->json('total'))->toBe(25)
        ->and($first->json('customers'))->toHaveCount(20)
        ->and($first->json('per_page'))->toBe(20);

    $second = moAdmin()->getJson('/admin-api/manual-orders/customers?q=Shopper&page=2')->assertOk();

    expect($second->json('total'))->toBe(25)
        ->and($second->json('customers'))->toHaveCount(5);
});

it('returns the customer’s saved address so the form can prefill it', function () {
    $customer = ManualOrders::customer();

    // Through the relation: addresses.customer_id is deliberately not fillable
    // on this model, so Address::create() would silently drop it.
    $customer->addresses()->create([
        'type' => 'shipping',
        'is_default' => true,
        'line1' => 'Villa 9, Jumeirah 1',
        'city' => 'Dubai',
        'state' => 'Dubai',
        'country' => 'AE',
        'phone' => '+971500000009',
    ]);

    $body = moAdmin()->getJson('/admin-api/manual-orders/customers?q=layla')->assertOk();

    expect($body->json('customers.0.address.line1'))->toBe('Villa 9, Jumeirah 1')
        ->and($body->json('customers.0.address.country'))->toBe('AE');
});

/* --------------------------------------------------------- product search */

it('searches the catalogue by name and by SKU', function () {
    $toner = ManualOrders::product('Heartleaf 77% Soothing Toner', 8900);

    expect(moAdmin()->getJson('/admin-api/manual-orders/products?q=heartleaf')
        ->assertOk()->json('products.0.id'))->toBe($toner->id);

    expect(moAdmin()->getJson('/admin-api/manual-orders/products?q=' . $toner->sku)
        ->assertOk()->json('products.0.id'))->toBe($toner->id);
});

it('escapes LIKE wildcards in a product search too', function () {
    // "77%" is a real product name on this store, which is exactly how a
    // trailing % gets into a search box by accident. The decoy is named so
    // that an UNESCAPED "77%" — where % is the wildcard — matches it too: a
    // test whose only candidate is the right answer cannot tell the two apart.
    ManualOrders::product('Heartleaf 77% Soothing Toner', 8900);
    ManualOrders::product('Ceramide 77 Barrier Cream', 7900);
    ManualOrders::product('Snail Mucin Essence', 7900);

    $hit = moAdmin()->getJson('/admin-api/manual-orders/products?q=' . urlencode('77%'))->assertOk();

    expect($hit->json('total'))->toBe(1)
        ->and($hit->json('products.0.name'))->toBe('Heartleaf 77% Soothing Toner');

    // And an underscore is the character, not "any one character".
    ManualOrders::product('Dew_Drop Serum', 5000);
    ManualOrders::product('Dewy Drop Serum', 5000);

    $underscore = moAdmin()->getJson('/admin-api/manual-orders/products?q=' . urlencode('Dew_'))->assertOk();

    expect($underscore->json('total'))->toBe(1)
        ->and($underscore->json('products.0.name'))->toBe('Dew_Drop Serum');
});

it('offers products hidden from the shop grid, but never drafts', function () {
    // Staff take orders for things that are not on the storefront. A draft is
    // a different matter: it is unfinished, not merely unlisted.
    //
    // The vocabulary here is products.status = publish | draft | private.
    // NOT active/archived, which belong to no column in this schema.
    $live = ManualOrders::product('Live product', 1000);
    $hidden = ManualOrders::product('Hidden product', 1000, ['is_visible' => false]);
    $private = ManualOrders::product('Private product', 1000, ['status' => 'private']);
    ManualOrders::product('Draft product', 1000, ['status' => 'draft']);

    $found = collect(moAdmin()->getJson('/admin-api/manual-orders/products?q=product')
        ->assertOk()->json('products'))->pluck('id')->all();

    expect($found)->toContain($live->id)
        ->and($found)->toContain($hidden->id)
        ->and($found)->toContain($private->id)
        ->and($found)->toHaveCount(3);
});

it('prices a search hit at the effective price, sale window and all', function () {
    ManualOrders::product('On sale now', 8900, ['sale_price' => 6900]);
    ManualOrders::product('Sale not started', 8900, [
        'sale_price' => 6900, 'sale_starts_at' => now()->addWeek(),
    ]);

    $rows = collect(moAdmin()->getJson('/admin-api/manual-orders/products?q=sale')
        ->assertOk()->json('products'))->keyBy('name');

    expect($rows['On sale now']['price_fils'])->toBe(6900)
        ->and($rows['Sale not started']['price_fils'])->toBe(8900);
});

it('counts every catalogue match, not just the page', function () {
    for ($i = 0; $i < 23; $i++) {
        ManualOrders::product('Widget number ' . $i, 1000);
    }

    $page = moAdmin()->getJson('/admin-api/manual-orders/products?q=Widget')->assertOk();

    expect($page->json('total'))->toBe(23)
        ->and($page->json('products'))->toHaveCount(20);
});

/* ------------------------------------------------------------ bootstrap */

it('offers only countries the shop actually delivers to', function () {
    $body = moAdmin()->getJson('/admin-api/manual-orders/bootstrap')->assertOk();

    expect($body->json('countries'))->toBe(['AE' => 'United Arab Emirates'])
        ->and($body->json('statuses'))->toBe([
            'pending', 'processing', 'onhold', 'shipped', 'completed', 'cancelled',
        ])
        ->and($body->json('default_status'))->toBe('processing')
        ->and($body->json('currency'))->toBe('AED');
});

/* ------------------------------------------------------------ what is NOT here

   No packing list, and no CSV, from this lane. The store already has real
   invoices and packing slips — App\Services\Invoices, mounted at
   routes/invoices-admin.php — and a second document rendered from the same
   order_items rows would be one more thing to keep in step with the first.
   The screen links to that one.
*/
