<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Order;
use App\Support\AdminCapabilities;
use App\Support\OrderProductSearch;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnerAppRoutes as OA;

/**
 * Lane OQ. The owner: "if we search any product on the orders page, the list
 * of orders should come up which ever has that product in order ... and when i
 * open the order detail ... the Quantity should be in red circle ... only if
 * the quantity is greater than 1."
 *
 * Store → Orders (the search box) and Store → Orders → an order (the Qty
 * column); the owner app's order screen. Each case says what it would catch on
 * the shop and how to make it go red.
 */

function oqOrder(string $status = 'processing', array $lines = [], array $attrs = []): Order
{
    static $n = 0;
    $n++;

    $order = Order::query()->create(array_merge([
        'order_number' => 'OQ-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'email' => 'oq-'.$n.'@example.test',
        'phone' => '+97150111'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'status' => $status,
        'total' => 10000,
        'subtotal' => 10000,
        'currency' => 'AED',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'billing_address' => ['first_name' => 'Buyer', 'last_name' => (string) $n],
    ], $attrs));

    foreach ($lines as $l) {
        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'name' => $l['name'],
            'sku' => $l['sku'] ?? null,
            'variant_attributes' => isset($l['variant']) ? json_encode($l['variant']) : null,
            'quantity' => $l['qty'] ?? 1,
            'is_gift' => $l['gift'] ?? false,
            'unit_price' => 5000, 'subtotal' => 5000, 'total' => 5000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $order;
}

function oqAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'OQ '.$role,
        'email' => 'oq-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

/** @return array<string, mixed> the whole response */
function oqList(string $query): array
{
    return test()->getJson('/admin-api/orders-list?'.$query)->assertOk()->json();
}

/** @return list<int> */
function oqIds(array $body): array
{
    return array_map(fn ($o) => (int) $o['id'], $body['orders']);
}

function oqJelly(): array
{
    return ['name' => 'Medicube PDRN Pink Peptide Jelly Cream', 'sku' => 'MED-PDRN-JC', 'variant' => ['Size' => '50ml']];
}

/* ------------------------------------------------------------ the search */

it('finds every order with the product in it, by name word for word, by SKU and by option', function () {
    /*
     * DEFECT: the owner types a product into Store → Orders and gets nothing,
     * because the box only looked at order number, name, email and phone.
     * MUTATION: drop the orWhereIn() in OrdersApiController::baseQuery() and
     * every expectation below that is not the toner goes red.
     */
    test()->actingAs(oqAdmin(), 'admin');

    $a = oqOrder('processing', [oqJelly() + ['qty' => 2]]);
    $b = oqOrder('completed', [oqJelly(), ['name' => 'Anua Heartleaf Toner', 'sku' => 'ANU-77T']]);
    $c = oqOrder('processing', [['name' => 'Tirtir Mask Fit Cushion', 'sku' => 'TT-MF-21N', 'variant' => ['Shade' => '21N Ivory']]]);
    $none = oqOrder('processing', [['name' => 'Round Lab Sunscreen']]);

    // Words in any order, any case, not contiguous: how he remembers it.
    expect(oqIds(oqList('search='.urlencode('medicube jelly'))))->toEqualCanonicalizing([$a->id, $b->id])
        ->and(oqIds(oqList('search='.urlencode('JELLY medicube'))))->toEqualCanonicalizing([$a->id, $b->id])
        ->and(oqIds(oqList('search=MED-PDRN')))->toEqualCanonicalizing([$a->id, $b->id])
        // The option snapshot: "who bought this shade?"
        ->and(oqIds(oqList('search='.urlencode('21N Ivory'))))->toBe([$c->id])
        ->and(oqIds(oqList('search='.urlencode('cushion ivory'))))->toBe([$c->id])
        // Every word must be on the SAME line: "jelly toner" is no one line.
        ->and(oqIds(oqList('search='.urlencode('jelly sunscreen'))))->toBe([]);

    expect(oqIds(oqList('search=toner')))->toBe([$b->id])
        ->and(oqIds(oqList('search=toner')))->not->toContain($none->id);
});

it('shows on each row the line that matched, with its quantity, and nothing on a row that matched by name', function () {
    /*
     * DEFECT: a long list with no way to see WHY an order is in it.
     * MUTATION: return [] from OrderProductSearch::matchedLines() and `matched`
     * is null on every row.
     */
    test()->actingAs(oqAdmin(), 'admin');

    $a = oqOrder('processing', [oqJelly() + ['qty' => 3], ['name' => 'Anua Toner']]);
    $byName = oqOrder('processing', [['name' => 'Anua Toner']], ['billing_address' => ['first_name' => 'Jelly', 'last_name' => 'Smith']]);

    $rows = collect(oqList('search=jelly')['orders'])->keyBy('id');

    expect($rows[$a->id]['matched']['lines'])->toBe([[
        'name' => 'Medicube PDRN Pink Peptide Jelly Cream',
        'variant' => 'Size: 50ml',
        'sku' => 'MED-PDRN-JC',
        'qty' => 3,
        'gift' => false,
    ]])
        ->and($rows[$a->id]['matched']['more'])->toBe(0)
        // Matched by the billing name, not a line: no chip invented for it.
        ->and($rows[$byName->id]['matched'])->toBeNull();

    // No search: the row is exactly the shape it was, and no summary.
    $plain = oqList('per_page=5');
    expect($plain['orders'][0])->not->toHaveKey('matched')
        ->and($plain['product_match'])->toBeNull();
});

it('caps the chips per row and counts the rest', function () {
    test()->actingAs(oqAdmin(), 'admin');

    $o = oqOrder('processing', [
        ['name' => 'Jelly Cream A'], ['name' => 'Jelly Cream B'], ['name' => 'Jelly Cream C'], ['name' => 'Jelly Cream D'],
    ]);

    $row = collect(oqList('search=jelly')['orders'])->firstWhere('id', $o->id);

    expect($row['matched']['lines'])->toHaveCount(OrderProductSearch::PER_ORDER)
        ->and($row['matched']['more'])->toBe(4 - OrderProductSearch::PER_ORDER);
});

it('sums the product over the orders in the view: orders, pieces and free gifts apart', function () {
    /*
     * "Medicube PDRN Jelly Cream — in 3 orders · 6 pieces". A gift line is not
     * a piece sold; it is counted on its own, as the list's Items column does.
     * MUTATION: drop the CASE on is_gift in summary() and pieces reads 7.
     */
    test()->actingAs(oqAdmin(), 'admin');

    oqOrder('processing', [oqJelly() + ['qty' => 2]]);
    oqOrder('completed', [oqJelly() + ['qty' => 3]]);
    oqOrder('processing', [oqJelly(), oqJelly() + ['gift' => true]]);
    oqOrder('processing', [['name' => 'Something Else']]);

    $pm = oqList('search='.urlencode('jelly cream'))['product_match'];

    expect($pm['products'])->toBe([[
        'name' => 'Medicube PDRN Pink Peptide Jelly Cream',
        'orders' => 3,
        'pieces' => 6,
        'gift_pieces' => 1,
    ]])->and($pm['more'])->toBeFalse();
});

it('works together with the status chip: the list and the summary both narrow to it', function () {
    /*
     * MUTATION: build the summary from $base instead of $query in listing()
     * and the completed order is counted under Processing.
     */
    test()->actingAs(oqAdmin(), 'admin');

    $p = oqOrder('processing', [oqJelly() + ['qty' => 2]]);
    $done = oqOrder('completed', [oqJelly() + ['qty' => 5]]);

    $body = oqList('search=jelly&filter=processing');

    expect(oqIds($body))->toBe([$p->id])
        ->and($body['product_match']['products'][0]['orders'])->toBe(1)
        ->and($body['product_match']['products'][0]['pieces'])->toBe(2)
        // The chip counts still describe the search across every status.
        ->and($body['counts']['completed'])->toBe(1);

    expect(oqIds(oqList('search=jelly&filter=completed')))->toBe([$done->id]);
});

it('exports exactly the orders the product search shows', function () {
    test()->actingAs(oqAdmin(), 'admin');

    $hit = oqOrder('processing', [oqJelly()]);
    $miss = oqOrder('processing', [['name' => 'Round Lab Sunscreen']]);

    $csv = test()->get('/admin-api/orders-export?search=jelly')->assertOk()->streamedContent();

    expect($csv)->toContain($hit->order_number)->not->toContain($miss->order_number);
});

it('treats % and _ as the letters typed, never as wildcards', function () {
    /*
     * DEFECT: "100%" matching every product with "100" in it, "_" matching
     * any one character. MUTATION: drop escape() in constrainLines().
     */
    test()->actingAs(oqAdmin(), 'admin');

    $pct = oqOrder('processing', [['name' => 'Toner 100% Pure']]);
    $num = oqOrder('processing', [['name' => 'Toner 1000 Pure']]);
    $und = oqOrder('processing', [['name' => 'Serum', 'sku' => 'SR_01']]);
    $any = oqOrder('processing', [['name' => 'Serum', 'sku' => 'SRX01']]);

    expect(oqIds(oqList('search='.urlencode('100%'))))->toBe([$pct->id])
        ->and(oqIds(oqList('search='.urlencode('SR_01'))))->toBe([$und->id])
        ->and(oqIds(oqList('search='.urlencode('%%'))))->toBe([]);

    expect([$num->id, $any->id])->each->toBeInt();
});

it('finds an imported line whose name WooCommerce stored HTML-encoded', function () {
    test()->actingAs(oqAdmin(), 'admin');

    $o = oqOrder('processing', [['name' => 'SKIN&amp;LAB Vitamin C Serum']]);

    $body = oqList('search='.urlencode('skin&lab'));

    expect(oqIds($body))->toBe([$o->id])
        // Read back as text, the way every other screen prints it.
        ->and($body['orders'][0]['matched']['lines'][0]['name'])->toBe('SKIN&LAB Vitamin C Serum');
});

it('caps the term, so a pasted paragraph is not a bigger statement', function () {
    test()->actingAs(oqAdmin(), 'admin');
    oqOrder('processing', [oqJelly()]);

    expect(mb_strlen(OrderProductSearch::term(str_repeat('jelly ', 200))))->toBeLessThanOrEqual(OrderProductSearch::MAX_TERM)
        ->and(OrderProductSearch::words(implode(' ', range(1, 40))))->toHaveCount(OrderProductSearch::MAX_WORDS)
        ->and(OrderProductSearch::applies('j'))->toBeFalse();

    test()->getJson('/admin-api/orders-list?search='.urlencode(str_repeat('jelly ', 200)))->assertOk();
});

/* ------------------------------------------------------------- the guard */

it('answers only an admin who may see orders', function () {
    /*
     * The search reads every buyer's lines. MUTATION: take orders-list out of
     * AdminCapabilities and the editor gets a 200.
     */
    oqOrder('processing', [oqJelly()]);

    test()->getJson('/admin-api/orders-list?search=jelly')->assertUnauthorized();

    expect(AdminCapabilities::roleCan('editor', 'orders.view'))->toBeFalse();
    test()->actingAs(oqAdmin('editor'), 'admin')
        ->getJson('/admin-api/orders-list?search=jelly')->assertForbidden();

    // And nothing of it is reachable under the unauthenticated /api.
    test()->getJson('/api/orders-list?search=jelly')->assertNotFound();
});

/* ---------------------------------------------------------- the cost */

it('costs the same number of statements for 3 matching orders as for 40', function () {
    /*
     * Rule 4: flat as the shop grows. MUTATION: look the chips up per row
     * (matchedLines() with one id at a time) and the 40 run costs 37 more.
     */
    test()->actingAs(oqAdmin(), 'admin');

    $measure = function (): int {
        test()->getJson('/admin-api/orders-list?per_page=50&search=jelly')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson('/admin-api/orders-list?per_page=50&search=jelly')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    foreach (range(1, 3) as $i) {
        oqOrder('processing', [oqJelly() + ['qty' => $i], ['name' => 'Toner']]);
    }
    $three = $measure();

    foreach (range(1, 37) as $i) {
        oqOrder(['processing', 'completed', 'shipped'][$i % 3], [oqJelly() + ['qty' => 1 + $i % 4], ['name' => 'Toner']]);
    }
    $forty = $measure();

    expect(count(oqList('per_page=50&search=jelly')['orders']))->toBe(40)
        ->and($forty)->toBe($three);

    // And the search itself adds exactly two statements to the plain list.
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->getJson('/admin-api/orders-list?per_page=50')->assertOk();
    $plain = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($forty - $plain)->toBe(2);
});

/* --------------------------------------------------------- the owner app */

it('gives the owner app search the same product rule, options included', function () {
    /*
     * MUTATION: put the owner app back on its own name/SKU EXISTS and the
     * shade search finds nothing.
     */
    OA::wire($this->app);
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
    OA::member(OA::admin());
    [$c] = OA::enrol($this);

    $shade = oqOrder('processing', [['name' => 'Tirtir Mask Fit Cushion', 'variant' => ['Shade' => '21N Ivory']]]);
    $jelly = oqOrder('processing', [oqJelly()]);

    $ids = fn (string $q) => array_map(fn ($o) => (int) $o['id'], OA::get($this, 'orders?q='.urlencode($q), $c)->assertOk()->json('orders'));

    expect($ids('21N Ivory'))->toBe([$shade->id])
        ->and($ids('medicube jelly'))->toBe([$jelly->id])
        ->and($ids('100%'))->toBe([]);
});

/* ------------------------------------------------------- the red circle */

it('draws the quantity in a red circle only when it is more than one, on the admin order screen', function () {
    /*
     * DEFECT: "2 x Medicube" read exactly like "1 x Medicube"; a multi-piece
     * line was missed at packing. And quantity 1 must stay byte-identical.
     * MUTATION: change `n <= 1` to `n < 1` in kqBadge() and a single piece
     * wears a badge; drop the ternary and qty 1 loses its "× 1".
     */
    $app = file_get_contents(base_path('resources/views/admin/app.blade.php'));

    expect($app)->toContain("function kqBadge(n, cls){\n    n = +n || 0;\n    if (n <= 1) return '';")
        ->and($app)->toContain('aria-label="Quantity \'+n+\'"')
        // Read-only lines and free gifts: badge over 1, the old "× 1" at 1.
        ->and($app)->toContain(": (it.quantity > 1 ? kqBadge(it.quantity) : '\\u00d7 '+it.quantity);")
        ->and($app)->toContain("<td>AED 0</td><td>'+(g.quantity > 1 ? kqBadge(g.quantity) : '\\u00d7 '+g.quantity)+'</td>")
        // The editable box at 1 is the exact markup it always was.
        ->and($app)->toContain(": '<input class=\"odinp odqty\" data-itemid=\"'+it.id+'\" type=\"number\" min=\"1\" value=\"'+it.quantity+'\" style=\"width:60px;padding:6px 7px\">')")
        ->and($app)->toContain('.kqb{display:inline-grid;place-items:center;min-width:26px;height:26px;')
        ->and($app)->toContain('border-radius:999px;background:#e5383b;color:#fff;font-weight:800');
});

it('draws the same red circle in the owner app, and the plain photo at one piece', function () {
    $js = file_get_contents(base_path('resources/js/owner-app/orders.js'));
    $css = file_get_contents(base_path('resources/css/owner-app/owner-app.css'));

    expect($js)->toContain("if (n <= 1) return th(src);")
        ->and($js)->toContain("thq(i.thumb, i.qty)")
        ->and($js)->toContain("thq(g.thumb, g.qty)")
        ->and($js)->toContain('aria-label="Quantity \' + n + \'"')
        // Follows the page into Arabic: the inline-END corner, never "right".
        ->and($css)->toContain('.qb { position: absolute; top: -7px; inset-inline-end: -7px;')
        ->and($css)->toContain('background: #E5383B; color: #fff;');

    // And the built bundle the server actually sends carries it.
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $built = file_get_contents(public_path('build/'.$manifest['resources/js/owner-app/owner-app.js']['file']));
    expect($built)->toContain('Quantity ');
    $builtCss = file_get_contents(public_path('build/'.$manifest['resources/css/owner-app/owner-app.css']['file']));
    expect($builtCss)->toContain('.qb{');
});

it('labels the orders search for products and highlights by escaping every piece', function () {
    $app = file_get_contents(base_path('resources/views/admin/app.blade.php'));

    expect($app)->toContain('placeholder="Search order, customer, phone, product or SKU…"')
        // Split on the words, escape each piece: nothing typed becomes markup.
        ->and($app)->toContain("return text.split(re).map(function(part, i){ return i % 2 ? '<mark class=\"olhi\">' + sesc(part) + '</mark>' : sesc(part); }).join('');")
        // A search typed while the last one answers is queued, not dropped.
        ->and($app)->toContain('if(OL.busy){ OL.again = true; return; }');
});
