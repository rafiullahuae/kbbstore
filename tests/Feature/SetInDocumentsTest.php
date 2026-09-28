<?php

declare(strict_types=1);

use App\Mail\NewOrderAlert;
use App\Mail\OrderConfirmation;
use App\Mail\OrderInvoice;
use App\Mail\OrderStatusChanged;
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Invoices\InvoiceDocument;
use Illuminate\Support\Str;
use Tests\Support\InvoiceAdminRoutes;

/**
 * EVERY DOCUMENT THE SHOP SENDS OR PRINTS LISTS WHAT WAS IN THE BOX. (Lane SE)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * A set is one line on an order. Without the member list, that line is an
 * anonymous name — "Glow Set" — and the two people who most need to know what
 * is inside it are exactly the two who only ever see these documents: the
 * customer reading their receipt, and whoever picks the order off a shelf.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT THIS FILE EXISTS AGAINST ────────────────────────────────────
 *
 * `emails/order-invoice.blade.php` and its plain-text twin are the ONLY two
 * order documents in this application that do not `@include` a shared item
 * partial — they draw their own table off the same `$doc['items']` array. So
 * when the member list was added to `emails/partials/items.blade.php`,
 * `body-text.blade.php` and the three `invoices/partials/sheet-*.blade.php`, the
 * emailed invoice was the one document it did not reach. The presenter was
 * already handing it `setContents`; nothing read the key.
 *
 * That is the worst one to miss. The emailed invoice is the document a customer
 * keeps, forwards to an employer, and files for a return — and it is the one
 * this shop's operators send BY HAND from the order screen, which means a human
 * chose to send it and got a document that answered less than the automatic
 * confirmation had.
 *
 * MUTATION NOTE — RUN, both directions, on 2026-09-28:
 *
 *   Delete the `$item['setContents']` raw-PHP block from
 *   resources/views/emails/order-invoice.blade.php and
 *   `order-invoice (html) is missing member: Heartleaf Toner` fails.
 *   Delete the `@foreach ($item['setContents'] ...)` from
 *   order-invoice-text.blade.php and `order-invoice (text)` fails the same way.
 *   Restore either and it is green again.
 *
 * ── WHY THIS ASSERTS EVERY SURFACE AND NOT ONLY THE TWO THAT WERE BROKEN ───
 *
 * The five surfaces that already print the member list have no test that says
 * they must keep doing so — SetCheckoutSnapshotTest renders exactly one of
 * them (`sheet-invoice`) and only to prove the SNAPSHOT is not the live pivot.
 * A partial can stop being included, and a raw-PHP block written hard against
 * an `@endif` is one character away from not compiling at all (CLAUDE.md names
 * that failure mode by hand). So the list below is the whole set of order
 * documents, and adding a document to the shop means adding it here.
 *
 * ── THE FIXTURE IS ONE ORDER WITH BOTH KINDS OF LINE ───────────────────────
 *
 * A set AND an ordinary product, bought together through the real checkout. One
 * order proves both halves at once: the member names are printed under the set,
 * and the ordinary line does NOT sprout a member list — which is the assertion
 * that a document with no set in it is the document it always was.
 */
beforeEach(function () {
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
});

function setDocProduct(string $name, int $fils, ?Brand $brand = null): Product
{
    return Product::create([
        'slug' => 'doc-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
        'brand_id' => $brand?->id,
        'image' => '/img/'.Str::slug($name).'.jpg',
    ]);
}

/**
 * One order holding a set line and an ordinary line, placed through the real
 * checkout so `order_items.set_contents` is written by the code that writes it
 * in production rather than by this file.
 *
 * @return array{order: Order, set: Product, plain: Product}
 */
function setDocOrder(): array
{
    $toner = setDocProduct('Heartleaf Toner', 9000);
    $serum = setDocProduct('Azelaic Serum', 5000);

    $set = Product::create([
        'slug' => 'glow-set-'.Str::random(6),
        'name' => 'Glow Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12000,
        'stock_status' => 'instock',
    ]);

    ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $toner->id, 'quantity' => 1, 'position' => 0]);
    ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $serum->id, 'quantity' => 2, 'position' => 1]);

    // The ordinary line. Its name is deliberately nothing like a member's, so
    // "the plain line grew a member list" cannot pass by accident.
    $plain = setDocProduct('Rice Cleanser', 7000);

    $cart = Cart::create([
        'token' => Str::random(32),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);
    $cart->items()->create(['product_id' => $set->id, 'quantity' => 1, 'unit_price' => 12000]);
    $cart->items()->create(['product_id' => $plain->id, 'quantity' => 1, 'unit_price' => 7000]);

    test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', [
            'billing_email' => 'buyer@example.com',
            'billing_phone' => '+971500000000',
            'billing_first_name' => 'Aisha',
            'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk',
            'billing_city' => 'Dubai',
            'billing_state' => 'Dubai',
            'billing_country' => 'AE',
            'payment_method' => 'cod',
        ])->assertRedirect();

    return ['order' => Order::latest('id')->first()->fresh(['items']), 'set' => $set, 'plain' => $plain];
}

/**
 * Every order document this shop produces, rendered.
 *
 * BOTH HALVES OF EVERY EMAIL. An email is two documents and the plain-text twin
 * is the half nobody looks at, which is why it is the half that rots — the same
 * reasoning PrintedEnglishUnchangedTest states for the same reason.
 *
 * @return array<string, string>
 */
function setDocRenderAll(\Tests\TestCase $test, Order $order, AdminUser $admin): array
{
    $out = [];

    $mailables = [
        'order-confirmation' => new OrderConfirmation($order),
        'new-order-alert' => new NewOrderAlert($order),
        'order-status (shipped)' => new OrderStatusChanged($order, 'shipped'),
        'order-invoice' => new OrderInvoice($order),
    ];

    foreach ($mailables as $name => $mailable) {
        $out[$name.' (html)'] = (string) $mailable->render();

        $content = $mailable->content();

        if ($content->text !== null) {
            $out[$name.' (text)'] = (string) view($content->text, array_merge(
                $mailable->buildViewData(),
                $content->with,
            ))->render();
        }
    }

    foreach (['invoice', 'packing-slip', 'delivery-note'] as $doc) {
        $out['printed: '.$doc] = (string) $test->actingAs($admin, 'admin')
            ->get('/admin-api/orders/'.$order->id.'/'.$doc)
            ->getContent();
    }

    return $out;
}

it('lists every member of a set in every order document, and leaves an ordinary line alone', function () {
    InvoiceAdminRoutes::wire(app());

    $fixture = setDocOrder();

    $admin = AdminUser::create([
        'name' => 'Doc Owner',
        'email' => 'set-documents@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    $documents = setDocRenderAll($this, $fixture['order'], $admin);

    // Eleven: four emails, three of them with a plain-text twin, and three
    // printed pages. A drop here means a document stopped rendering, and a
    // document that renders nothing contains every member name vacuously.
    expect(count($documents))->toBe(11);

    $thin = array_keys(array_filter($documents, fn (string $b): bool => strlen($b) < 250));
    expect($thin)->toBe([], 'These documents rendered almost nothing: '.implode(', ', $thin));

    foreach ($documents as $name => $body) {
        // The set's own line is on every one of them already — if it is not,
        // the document is not showing the order and the rest means nothing.
        expect(str_contains($body, 'Glow Set'))->toBeTrue("{$name} does not show the set line at all");

        foreach (['Heartleaf Toner', 'Azelaic Serum'] as $member) {
            expect(str_contains($body, $member))->toBeTrue("{$name} is missing member: {$member}");
        }

        // AND THE QUANTITY. "Azelaic Serum" alone does not tell a picker to put
        // two of them in the box; SetContents::lines() puts the quantity first
        // for exactly that reason, and a document that printed only the names
        // would pass the loop above while still mis-picking the order.
        expect(str_contains($body, '2 × Azelaic Serum'))->toBeTrue("{$name} does not print the member quantity");

        // The ordinary line is present and did NOT grow a member list of its
        // own: it is the proof that the change is scoped to set lines.
        expect(str_contains($body, 'Rice Cleanser'))->toBeTrue("{$name} does not show the ordinary line");
        expect(substr_count($body, '1 × Rice Cleanser'))->toBe(0, "{$name} drew a member list on an ordinary line");
    }
});

it('prints what was in the box on the day, not what is in the set now', function () {
    /*
     * ── THE RULE THE WHOLE FEATURE RESTS ON ────────────────────────────────
     *
     * An order is a record. SetContents::fromOrderItem() reads
     * `order_items.set_contents` and touches no relation, so a set edited or
     * deleted after the sale cannot rewrite a document that was already sent.
     * SetCheckoutSnapshotTest proves that for the PRESENTER and for one sheet;
     * this proves it for the two documents this lane wired, which are the ones
     * that could have been written to look the set up live.
     *
     * MUTATION NOTE — RUN. Change the emailed invoice to read
     * `SetContents::fromProduct($item->product)` instead of the `setContents`
     * the presenter hands it and this is red: the emailed invoice starts
     * printing "Rice Cleanser" for an order that never contained one, and stops
     * printing the serum that did. It also goes red if the set is DELETED, with
     * a document that lists nothing at all.
     */
    InvoiceAdminRoutes::wire(app());

    $fixture = setDocOrder();
    $set = $fixture['set'];

    // The shop moves on: the serum comes out, a cleanser goes in.
    ProductSetItem::where('set_product_id', $set->id)
        ->whereHas('member', fn ($q) => $q->where('name', 'Azelaic Serum'))
        ->delete();
    ProductSetItem::create([
        'set_product_id' => $set->id,
        'member_product_id' => setDocProduct('Snail Mucin Essence', 8000)->id,
        'quantity' => 1,
        'position' => 9,
    ]);

    $admin = AdminUser::create([
        'name' => 'Doc Owner',
        'email' => 'set-documents-snapshot@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    foreach (setDocRenderAll($this, $fixture['order']->fresh(['items']), $admin) as $name => $body) {
        expect(str_contains($body, 'Azelaic Serum'))->toBeTrue("{$name} forgot what was actually in the box");
        expect(str_contains($body, 'Snail Mucin Essence'))->toBeFalse("{$name} re-read the live set and printed today's contents on last month's order");
    }
});

it('renders every order document for a set whose product has since been deleted', function () {
    /*
     * The harder half of the same promise. A snapshot that is only read while
     * the set still exists is not a snapshot; and a set is a `products` row,
     * which an owner can delete from the catalogue screen at any time.
     *
     * The order line keeps its own `product_id` foreign key, so the thing under
     * test is that no document reaches through it. If one did, this would not
     * be a wrong member list — it would be a 500 on the invoice the operator
     * was trying to send, and a queue worker failing on the confirmation.
     *
     * MUTATION NOTE — RUN. Point any of the six documents at the live pivot and
     * this fails: without the `?->` it is a TypeError on a null product, and
     * with one it prints an empty member list and the first expectation fails.
     */
    InvoiceAdminRoutes::wire(app());

    $fixture = setDocOrder();

    // Gone from the catalogue entirely, memberships cascaded with it.
    Product::whereKey($fixture['set']->id)->delete();

    $admin = AdminUser::create([
        'name' => 'Doc Owner',
        'email' => 'set-documents-deleted@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    foreach (setDocRenderAll($this, $fixture['order']->fresh(['items']), $admin) as $name => $body) {
        expect(str_contains($body, '2 × Azelaic Serum'))->toBeTrue("{$name} lost the member list when the set was deleted");
        expect(str_contains($body, 'Glow Set'))->toBeTrue("{$name} lost the set line when the set was deleted");
    }
});

it('prints nothing at all for an order placed before sets existed', function () {
    /*
     * `order_items.set_contents` is NULL on every row this shop took before the
     * column was added. SetContents::fromOrderItem() answers NONE for it and
     * lines() answers [], so every such document is byte-for-byte the document
     * it was — which is CLAUDE.md rule 1, measured rather than asserted.
     *
     * The comparison is against the SAME order with the column nulled, so the
     * only difference between the two renders is the snapshot itself.
     *
     * MUTATION NOTE — RUN, 2026-09-28. Change the emailed invoice's guard from
     * `($item['setContents'] ?? []) !== []` to `true`, so the wrapper is emitted
     * on every line including the ordinary ones, and the last two expectations
     * below fail: the empty inset div and the bare "  * " bullet appear on a
     * document that has no snapshot to draw. Restore the guard and it is green.
     *
     * ▲ AND THE FIRST EXPECTATION DOES NOT CATCH THAT — measured, not assumed.
     *   An empty wrapper carries no text, so strip_tags() erases it and the
     *   visible-text comparison passes right through it. That is exactly why the
     *   markup markers are asserted separately rather than trusted to fall out
     *   of the text comparison. (tests/Feature/PrintedEnglishUnchangedTest.php
     *   also goes red on that mutation, byte-for-byte, which is the shop-wide
     *   instrument; this file says it locally so the failure names the cause.)
     */
    InvoiceAdminRoutes::wire(app());

    $fixture = setDocOrder();
    $order = $fixture['order'];

    $admin = AdminUser::create([
        'name' => 'Doc Owner',
        'email' => 'set-documents-legacy@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    /*
     * ONE WARM-UP RENDER FIRST, and it is not superstition. Fetching the
     * printed invoice ISSUES this order's invoice number, so the very first
     * render of a document set shows no reference where every later one shows
     * "Invoice 01000 / Issued 28 September 2026". Comparing render #1 against
     * render #2 would therefore report a difference that has nothing to do with
     * sets and would send the next reader hunting through this lane's diff for
     * it. Warmed once, both sides below are the same order in the same state
     * and the ONLY variable left is the snapshot column.
     */
    setDocRenderAll($this, $order->fresh(['items']), $admin);

    $withSet = setDocRenderAll($this, $order->fresh(['items']), $admin);

    // The pre-sets shape of the same row: a set line with no snapshot on it.
    $order->items()->update(['set_contents' => null]);

    $withoutSet = setDocRenderAll($this, $order->fresh(['items']), $admin);

    /*
     * THE VISIBLE TEXT OF EVERY DOCUMENT, OUTSIDE THE MEMBER LINES, IS THE
     * SAME TEXT. Not a byte budget with a number somebody guessed -- an exact
     * comparison. Tags are stripped and whitespace collapsed, so what is
     * compared is what a reader actually reads, and then the member strings
     * themselves are removed from the with-snapshot side. Whatever is left must
     * match character for character: an invoice reference, a total, a date, a
     * sign-off, a separator, an "each".
     *
     * The six templates emit their member lists in two shapes -- one per line
     * (the emails) and joined with " · " (the printed sheets) -- and the
     * text parts prefix a bullet, so all three forms are removed, longest
     * first, before the bare names.
     */
    $readable = static function (string $document): string {
        $text = strip_tags($document);

        foreach ([
            '* 1 × Heartleaf Toner',
            '* 2 × Azelaic Serum',
            '1 × Heartleaf Toner · 2 × Azelaic Serum',
            '1 × Heartleaf Toner',
            '2 × Azelaic Serum',
        ] as $line) {
            $text = str_replace($line, '', $text);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    };

    foreach ($withoutSet as $name => $body) {
        expect(str_contains($body, 'Heartleaf Toner'))->toBeFalse("{$name} invented a member list for a line that has no snapshot");

        expect($readable($withSet[$name]))->toBe(
            $readable($body),
            "{$name} changed somewhere other than the member lines"
        );
    }

    /*
     * AND NOT AN EMPTY WRAPPER EITHER. The two templates this lane wired are
     * asserted by the markup they emit, because an empty container is invisible
     * to every text comparison above and would still put a stray inset box on
     * the invoice of every order this shop has ever taken.
     */
    expect(str_contains($withoutSet['order-invoice (html)'], 'border-inline-start:2px solid #eceff3'))
        ->toBeFalse('the emailed invoice drew an empty member box on a line with no snapshot');

    expect(str_contains($withoutSet['order-invoice (text)'], "\n  * "))
        ->toBeFalse('the emailed invoice text part drew a bare bullet on a line with no snapshot');

    // The same two markers ARE there when there is something to draw, so the
    // assertions above are measuring the guard and not a typo in a needle.
    expect(str_contains($withSet['order-invoice (html)'], 'border-inline-start:2px solid #eceff3'))->toBeTrue();
    expect(str_contains($withSet['order-invoice (text)'], "\n  * "))->toBeTrue();
});

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * THE ABANDONED-BASKET REMINDER. (Lane SE)
 *
 * Not an order document — a BASKET one, and the last message in this shop that
 * described a Set as a single anonymous name. It is also the only surface in
 * this sweep that correctly reads the LIVE set rather than a snapshot, because
 * it is built at send time in order to describe the basket as it is now.
 * ═══════════════════════════════════════════════════════════════════════════
 */

/** A live basket holding a set and an ordinary product. */
function setDocBasket(): array
{
    $toner = setDocProduct('Heartleaf Toner', 9000);
    $serum = setDocProduct('Azelaic Serum', 5000);

    $set = Product::create([
        'slug' => 'basket-set-'.Str::random(6),
        'name' => 'Glow Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12000,
        'stock_status' => 'instock',
    ]);

    ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $toner->id, 'quantity' => 1, 'position' => 0]);
    ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $serum->id, 'quantity' => 2, 'position' => 1]);

    $cart = Cart::create([
        'token' => Str::random(32),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);
    $cart->items()->create(['product_id' => $set->id, 'quantity' => 1, 'unit_price' => 12000]);
    $cart->items()->create(['product_id' => setDocProduct('Rice Cleanser', 7000)->id, 'quantity' => 1, 'unit_price' => 7000]);

    return ['cart' => $cart, 'set' => $set];
}

/** Render both halves of the reminder for a basket. */
function setDocReminder(array $items): array
{
    $mailable = new \App\Mail\CartRecoveryReminder(
        'Your basket is waiting',
        'You left something behind.',
        $items,
        'https://kbeautybliss.test/cart/',
        'https://kbeautybliss.test/mail-preferences/cart/1',
    );

    $content = $mailable->content();

    return [
        'cart-recovery (html)' => (string) $mailable->render(),
        'cart-recovery (text)' => (string) view($content->text, array_merge($mailable->buildViewData(), $content->with))->render(),
    ];
}

it('tells an abandoned shopper what is inside the set they left behind', function () {
    /*
     * MUTATION NOTE — RUN, both directions, 2026-09-28. Delete the
     * `'setContents' => $sets[...] ?? []` line from CartRecovery::basket() and
     * both halves fail with "is missing member"; delete only the raw-PHP block
     * from emails/cart-recovery.blade.php and the (html) half fails alone;
     * delete only the @foreach from cart-recovery-text.blade.php and the (text)
     * half fails alone. Restore each and it is green.
     */
    $fixture = setDocBasket();

    $items = app(\App\Services\CartRecovery::class)->basket((int) $fixture['cart']->id);

    expect($items)->toHaveCount(2)
        ->and($items[0]['setContents'])->toBe(['1 × Heartleaf Toner', '2 × Azelaic Serum'])
        // The ordinary line carries the empty list, which is what keeps every
        // reminder this shop has already sent byte-identical.
        ->and($items[1]['setContents'])->toBe([]);

    foreach (setDocReminder($items) as $name => $body) {
        expect(str_contains($body, 'Glow Set'))->toBeTrue("{$name} does not show the set line at all");
        expect(str_contains($body, '1 × Heartleaf Toner'))->toBeTrue("{$name} is missing member: Heartleaf Toner");
        expect(str_contains($body, '2 × Azelaic Serum'))->toBeTrue("{$name} is missing member: Azelaic Serum");
        expect(str_contains($body, 'Rice Cleanser'))->toBeTrue("{$name} does not show the ordinary line");
        expect(str_contains($body, '1 × Rice Cleanser'))->toBeFalse("{$name} drew a member list on an ordinary line");
    }
});

it('reads the set as it is now, because a basket is not a record', function () {
    /*
     * The one place in this sweep where the LIVE pivot is the right answer, and
     * a test that says so — otherwise the next reader, having seen five
     * surfaces insist on the snapshot, would "fix" this one into reading a
     * snapshot that does not exist for a basket at all.
     *
     * MUTATION NOTE — RUN. Point CartRecovery::basket() at a stored list
     * instead of SetContents::fromProduct() and this is red: the reminder keeps
     * chasing the shopper about a serum the set no longer contains.
     */
    $fixture = setDocBasket();

    ProductSetItem::where('set_product_id', $fixture['set']->id)
        ->whereHas('member', fn ($q) => $q->where('name', 'Azelaic Serum'))
        ->delete();

    $items = app(\App\Services\CartRecovery::class)->basket((int) $fixture['cart']->id);

    expect($items[0]['setContents'])->toBe(['1 × Heartleaf Toner']);
});

it('costs a basket with no set in it not one extra query', function () {
    /*
     * CLAUDE.md rule 1 and rule 4 together: a shop that has never created a set
     * must pay nothing for this feature. The Eloquent lookup is behind a check
     * on ids gathered from the statement that was already running, so a basket
     * of ordinary products reaches the database exactly ONCE — the number it
     * reached before this lane touched the method.
     *
     * MEASURED, NOT ASSERTED, and the set basket is measured beside it so the
     * figure below is a real ceiling rather than a tautology.
     *
     * MUTATION NOTE — RUN. Hoist the `Product::whereKey($setIds)->get()` out
     * from behind `if ($setIds !== [])` and the plain basket costs 2 queries
     * and this is red; batch the members with a `foreach` calling
     * SetEagerLoad::on() per product instead of once for the collection and the
     * set basket's count climbs with every member.
     */
    $plainCart = Cart::create([
        'token' => Str::random(32), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);
    $plainCart->items()->create(['product_id' => setDocProduct('Plain Toner', 9000)->id, 'quantity' => 1, 'unit_price' => 9000]);
    $plainCart->items()->create(['product_id' => setDocProduct('Plain Serum', 5000)->id, 'quantity' => 1, 'unit_price' => 5000]);

    $recovery = app(\App\Services\CartRecovery::class);

    $count = static function (callable $fn): int {
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $fn();
        $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        return $n;
    };

    $plain = $count(fn () => $recovery->basket((int) $plainCart->id));

    expect($plain)->toBe(1, 'a basket with no set in it must still cost exactly the one statement it always cost');

    // And a basket WITH a set is flat in the number of members: the ceiling is
    // the seven statements SetEagerLoad documents, not one per member.
    $setCart = setDocBasket()['cart'];

    expect($count(fn () => $recovery->basket((int) $setCart->id)))
        ->toBeLessThanOrEqual(8, 'the set lookup is not batched — this is the N+1 SetEagerLoad exists to stop');
});
