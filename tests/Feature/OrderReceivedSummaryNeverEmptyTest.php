<?php

declare(strict_types=1);

/**
 * The order-received page's "Your order" block is never empty. (Lane TY)
 *
 * THE DEFECT, on the owner's phone (Chrome Android, card order #56181, free
 * delivery): the page drew "☰ Your order" and under it an empty white strip
 * about 40px tall -- no product lines, no Delivery row, no Total -- while the
 * facts above it, "Finish your account" and "Delivering to" below it were
 * whole, and the admin and the order emails listed every product.
 *
 * THE CAUSE is not the order and not the view's logic. Laravel 11's
 * BladeCompiler writes a compiled view by truncating the file and then filling
 * it; a request that includes it in that window renders the partial as
 * NOTHING (an empty PHP file includes fine), or as half a file (a parse error,
 * "Server Error"). Every package applied through Core Updates clears the
 * compiled views, and the Site App's service worker makes Chrome request the
 * order-received page twice, ~8ms apart (navigation preload + the network
 * request for a bypassed path) -- two cold compiles of the same views at once.
 * Measured on the preview, both requests sent together after a view:clear, 400
 * rounds: 148 of 800 bodies wrong before the fix (21 "Server Error", 6 the
 * owner's exact page), 0 of 800 after. App\View\AtomicViewFiles is the fix.
 *
 * Three things are pinned here:
 *   1. a compiled view is never readable half-written (the fix);
 *   2. the block renders lines, Delivery and Total for every shape of order
 *      the shop writes, including the owner's;
 *   3. a card payment that is still in flight is never released by Lane BK's
 *      UnfinishedPayment, which runs on /cart and /checkout in the same window.
 */

use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/* ═══════════════════════════ 1. the compiled-view race ═══════════════════════ */

it('never lets a request read a compiled view half-written', function () {
    /*
     * A real race, two processes: the child compiles one large view over and
     * over (what a second request does after a view:clear), the parent reads
     * the compiled file as fast as it can (what the first request's include
     * does). Every read must be the whole compiled view.
     *
     * Large on purpose: the bigger the file, the wider Laravel's
     * truncate-then-write window, so the unfixed compiler is caught on every
     * run rather than some of them. Measured with the fix removed: thousands
     * of torn reads per run.
     *
     * MUTATION: delete ViewServiceProvider::register() (the stock compiler's
     * put() comes back) and this is red with torn reads > 0.
     */
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('needs pcntl and posix to run two processes');
    }

    $dir = storage_path('framework/testing/ty-'.getmypid().'-'.Str::random(6));
    mkdir($dir, 0777, true);
    $source = $dir.'/big.blade.php';
    file_put_contents($source, str_repeat("<p class=\"ci\">{{ \$line ?? 'Line' }} &middot; AED 99</p>\n", 10000));

    $blade = app('blade.compiler');
    $blade->compile($source);
    $compiled = $blade->getCompiledPath($source);
    $whole = file_get_contents($compiled);
    expect(strlen($whole))->toBeGreaterThan(250_000);

    $pid = pcntl_fork();

    if ($pid === 0) {
        // The child: recompile, again and again, then die without running
        // the parent's shutdown handlers (they own the test database).
        for ($i = 0; $i < 4; $i++) {
            $blade->compile($source);
        }
        posix_kill(posix_getpid(), SIGKILL);
    }

    $reads = 0;
    $torn = 0;

    do {
        clearstatcache(true, $compiled);
        $now = @file_get_contents($compiled);
        $reads++;

        if ($now !== $whole) {
            $torn++;
        }

        $done = pcntl_waitpid($pid, $status, WNOHANG) !== 0;
    } while (! $done);

    foreach (glob($dir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
    foreach (glob(dirname($compiled).'/.'.basename($compiled).'.*.tmp') ?: [] as $f) {
        @unlink($f);
    }

    expect($reads)->toBeGreaterThan(10)
        ->and($torn)->toBe(0);
});

it('leaves no temporary file behind and answers put() as Laravel did', function () {
    $dir = storage_path('framework/testing/ty-put-'.getmypid());
    @mkdir($dir, 0777, true);
    $target = $dir.'/view.php';

    $files = new \App\View\AtomicViewFiles;

    expect($files->put($target, 'first'))->toBe(5)
        ->and($files->put($target, 'second, longer'))->toBe(14)
        ->and(file_get_contents($target))->toBe('second, longer')
        ->and(glob($dir.'/.*.tmp') ?: [])->toBe([])
        // Readable by the next PHP process, not 0600 as tempnam() leaves it.
        ->and(fileperms($target) & 0044)->toBe(0044 & ~umask());

    @unlink($target);
    @rmdir($dir);
});

it('is the writer the Blade compiler actually uses', function () {
    // MUTATION: delete ViewServiceProvider::register() and this is red.
    $files = (fn () => $this->files)->call(app('blade.compiler'));

    expect($files)->toBeInstanceOf(\App\View\AtomicViewFiles::class);
});

/* ═══════════════════ 2. every shape of order renders its block ══════════════ */

function tyOrder(array $attributes, array $lines): Order
{
    static $n = 0;
    $n++;

    $address = [
        'first_name' => 'Rafi', 'last_name' => 'Ullah', 'line1' => 'Villa 12',
        'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000',
    ];

    $order = Order::create(array_merge([
        'order_number' => 'TY'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'email' => 'owner@example.com', 'status' => 'processing', 'currency' => 'AED',
        'subtotal' => 0, 'discount_total' => 0, 'bundle_discount' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'tax_total' => 0, 'gift_fee' => 0, 'total' => 0,
        'shipping_method' => 'Free delivery', 'payment_method' => 'stripe',
        'payment_method_title' => 'Credit / Debit Card', 'transaction_id' => 'pi_ty_'.$n, 'paid_at' => now(),
        'billing_address' => $address, 'shipping_address' => $address,
    ], $attributes));

    $subtotal = 0;

    foreach ($lines as $i => $line) {
        $product = array_key_exists('product_id', $line) ? null : Product::create([
            'slug' => 'ty-'.$n.'-'.$i, 'name' => 'P'.$i, 'status' => 'publish', 'is_visible' => true,
            'price' => 100, 'stock_status' => 'instock',
        ]);

        $order->items()->create($line + [
            'product_id' => $product?->id, 'quantity' => 1, 'unit_price' => 9900,
            'subtotal' => 9900, 'total' => 9900,
        ]);
        $subtotal += 9900;
    }

    $order->forceFill([
        'subtotal' => $subtotal,
        'total' => $order->total ?: $subtotal - (int) $order->discount_total + (int) $order->shipping_total + (int) $order->fee_total,
    ])->save();

    return $order->fresh();
}

/** The "Your order" section, from its heading to the next section. */
function tySummary(string $html): string
{
    $start = strpos($html, '<span class="n">☰</span>');
    expect($start)->not->toBeFalse();
    $end = strpos($html, '<span class="n">⌂</span>', $start);

    return substr($html, $start, $end - $start);
}

dataset('order shapes', [
    'the owner\'s: several brands, two lines of one, quantity 2, names with "&"' => [[], [
        ['name' => 'NIDA Cream', 'brand' => 'NIDA'],
        ['name' => 'Arencia Fresh Green Rice Mochi Cleanser', 'brand' => 'Arencia'],
        ['name' => 'Arencia Holy Hyssop Serum &amp; Toner', 'brand' => 'Arencia'],
        ['name' => 'Rohto Mentholatum Melano CC', 'brand' => 'Rohto Mentholatum'],
        ['name' => 'Senka Perfect Whip', 'brand' => 'Shiseido', 'quantity' => 2, 'total' => 19800, 'subtotal' => 19800],
    ], 5],
    'a set, with what was in the box' => [[], [
        ['name' => 'Glow Starter Set', 'brand' => 'SKIN 1004', 'set_contents' => [
            ['name' => 'Centella Ampoule', 'brand' => 'SKIN 1004', 'quantity' => 1, 'unit' => 8500],
            ['name' => 'Cotton Soft Sun Stick &amp; Co', 'brand' => 'TOCOBO', 'quantity' => 1, 'unit' => 7500],
        ]],
    ], 1],
    'a "Buy these together" bundle' => [['discount_total' => 2000, 'bundle_discount' => 2000], [
        ['name' => 'Medicube Zero Pore Pad', 'brand' => 'Medicube', 'bundle_group' => 'bt1', 'bundle_discount' => 1000, 'bundle_percent' => 10],
        ['name' => 'TOCOBO Sun Stick', 'brand' => 'TOCOBO', 'bundle_group' => 'bt1', 'bundle_discount' => 1000, 'bundle_percent' => 10],
    ], 2],
    'a product deleted since (product_id null)' => [[], [
        ['product_id' => null, 'name' => 'JUMISO Vitamin Serum', 'brand' => 'JUMISO'],
    ], 1],
    'a variant' => [[], [
        ['name' => 'Lip Tint', 'brand' => 'rom&amp;nd', 'variant_attributes' => ['06 Fig Fig', '5g']],
    ], 1],
    'a gift-wrapped order' => [['is_gift' => true, 'gift_fee' => 1500, 'fee_total' => 1500, 'gift_note' => 'Happy birthday'], [
        ['name' => 'NIDA Cream'],
    ], 1],
    'a card still in flight (not paid yet: the page can open before /checkout/card/paid answers)' => [['paid_at' => null, 'status' => 'pending'], [
        ['name' => 'NIDA Cream'], ['name' => 'Medicube Zero Pore Pad'],
    ], 2],
    'a long order: lines past the third behind <details>' => [[], array_map(fn ($i) => ['name' => 'Line '.$i], range(1, 9)), 9],
    'an imported line with no brand and an empty name' => [[], [
        ['name' => '', 'brand' => null], ['name' => 'Line &#8211; two'],
    ], 2],
]);

it('renders every line, the Delivery row and the Total for each shape of order', function (array $attributes, array $lines, int $expected) {
    $order = tyOrder($attributes, $lines);

    $page = $this->withSession(['kbb_last_order' => $order->order_number])
        ->get('/checkout/success?order='.$order->order_number)
        ->assertOk();

    $summary = tySummary($page->getContent());

    expect(substr_count($summary, '<div class="ci">'))->toBe($expected)
        ->and($summary)->toContain('<div class="co-lines">')
        ->and($summary)->toContain('<div class="co-totals">')
        ->and($summary)->toContain('<div class="sumrow tot"><span>Total</span>')
        ->and($summary)->toContain(\App\Support\Money::amount((int) $order->total))
        ->and($summary)->toContain('Delivery · Free delivery')
        // Balanced: what opens in the section closes in it, so nothing after
        // it can be swallowed (the "unclosed tag above" candidate).
        ->and(preg_match_all('#<div\b#', $summary))->toBe(preg_match_all('#</div>#', $summary));

    // A guest who has just paid sees the account offer AND the full block --
    // the owner's page had both.
    expect($page->getContent())->toContain('class="sec co-acct"');
})->with('order shapes');

it('still renders the block on the second copy of the page, after the first consumed the session marker', function () {
    /*
     * The service worker's two requests: the first (navigation preload)
     * consumes `kbb_last_order` in the pixel block; the second is the one the
     * shopper sees. It must be the whole receipt, not the not-found panel and
     * not a reduced one.
     */
    $order = tyOrder([], [['name' => 'NIDA Cream'], ['name' => 'Medicube']]);

    $first = $this->withSession(['kbb_last_order' => $order->order_number])
        ->get('/checkout/success?order='.$order->order_number)->assertOk();
    expect(session('kbb_last_order'))->toBeNull();

    $second = $this->get('/checkout/success?order='.$order->order_number)->assertOk();

    expect(substr_count(tySummary($second->getContent()), '<div class="ci">'))->toBe(2)
        ->and(tySummary($second->getContent()))->toBe(tySummary($first->getContent()));
});

/* ═══════════════ 3. a card payment in flight is never released ══════════════ */

function tyStripe(): object
{
    $s = new class {
        public string $status = 'requires_payment_method';
        public bool $down = false;
        public array $cancelled = [];
        public int $opened = 0;
    };

    Http::fake(function (Illuminate\Http\Client\Request $request) use ($s) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $post = $request->method() === 'POST';

        if ($post && $path === '/v1/payment_intents') {
            $id = 'pi_ty_'.(++$s->opened);

            return Http::response(['id' => $id, 'client_secret' => $id.'_secret', 'status' => 'requires_payment_method'], 200);
        }
        if ($s->down) {
            return Http::response([], 503);
        }
        if (preg_match('#^/v1/payment_intents/(pi_[a-z_0-9]+)/cancel$#', $path, $m)) {
            // Stripe refuses to cancel an intent whose money has moved.
            if (in_array($s->status, ['succeeded', 'processing'], true)) {
                return Http::response(['error' => ['message' => 'cannot cancel']], 400);
            }
            $s->cancelled[] = $m[1];

            return Http::response(['id' => $m[1], 'status' => 'canceled'], 200);
        }
        if (preg_match('#^/v1/payment_intents/(pi_[a-z_0-9]+)$#', $path, $m)) {
            $order = Order::where('transaction_id', $m[1])->first();
            $status = in_array($m[1], $s->cancelled, true) ? 'canceled' : $s->status;

            return Http::response([
                'id' => $m[1], 'status' => $status, 'currency' => 'aed', 'amount' => (int) ($order?->total ?? 0),
                'amount_received' => $status === 'succeeded' ? (int) ($order?->total ?? 0) : 0,
            ], 200);
        }

        return Http::response([], 404);
    });

    return $s;
}

dataset('in flight', [
    'processing (the bank is still settling it)' => ['processing', false],
    'requires_capture (authorised, held for capture)' => ['requires_capture', false],
    'succeeded (paid; /checkout/card/paid not landed yet)' => ['succeeded', false],
    'Stripe could not be asked' => ['requires_action', true],
]);

it('never releases a card order whose payment is in flight, from /cart or /checkout', function (string $status, bool $down) {
    /*
     * The window the brief asks about: place() has run, the shopper's bank has
     * the payment, and before /checkout/card/paid has answered something
     * loads /cart or /checkout (a second tab, the Back button), which runs
     * UnfinishedPayment::recover(). The order must not be failed, its stock
     * must not go back on the shelf, and the basket must stay converted.
     *
     * A succeeded intent is applied (the order becomes paid) rather than
     * merely left alone; the other three are left exactly as they were.
     *
     * MUTATION: drop the `if (! $mayRelease)` early return in
     * UnfinishedPayment::recover() and processing, requires_capture and
     * "Stripe could not be asked" go red (order failed, stock back on the
     * shelf, basket re-opened); succeeded stays green because the read-back
     * marks it paid first.
     */
    PaymentProvider::query()->delete();
    $row = PaymentProvider::create(['id' => 'stripe', 'title' => 'Credit / Debit Card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $row->config = ['publishable_key' => 'pk_'.'test_ty', 'secret_key' => 'sk_'.'test_ty', 'webhook_signing_secret' => 'wh'.'sec_ty', 'webhook_secret' => 'ty-url-secret-0123456789'];
    $row->save();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'free_shipping', 'title' => 'Free delivery', 'cost' => 0, 'enabled' => true, 'position' => 0]);

    $stripe = tyStripe();
    $serum = Product::create(['slug' => 'ty-serum-'.Str::random(4), 'name' => 'Glow Serum', 'status' => 'publish', 'is_visible' => true, 'price' => 10000, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 5]);
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $serum->id, 'quantity' => 1, 'unit_price' => 10000]);

    $as = fn () => $this->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
    $next = function () {
        app()->forgetScopedInstances();
        app()->forgetInstance(CartService::class);
        foreach (app('router')->getRoutes() as $route) {
            $route->flushController();
        }
    };

    $as()->postJson('/checkout/place', [
        'billing_email' => 'owner@example.com', 'billing_phone' => '+971500000000',
        'billing_first_name' => 'Rafi', 'billing_last_name' => 'Ullah',
        'billing_address_1' => 'Villa 12', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai',
        'billing_country' => 'AE', 'payment_method' => 'stripe',
    ])->assertOk();
    $next();
    $order = Order::latest('id')->firstOrFail();
    expect($serum->fresh()->stock)->toBe(4);

    $stripe->status = $status;
    $stripe->down = $down;

    $as()->get('/cart/')->assertOk();
    $next();
    $as()->get('/checkout/');
    $next();

    expect($stripe->cancelled)->toBe([])
        ->and($order->fresh()->status)->not->toBe('failed')
        ->and($cart->fresh()->status)->toBe('converted')
        ->and($serum->fresh()->stock)->toBe(4);
})->with('in flight');
