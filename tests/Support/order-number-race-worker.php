<?php

declare(strict_types=1);

/**
 * One arm of the order-number race, run as its own OS process.
 *
 * OrderNumberRaceTest starts two of these at once and has them place real
 * orders at the same instant. Two processes is the point, for the same reason
 * tests/Support/coupon-race-worker.php gives: the thing under test is what
 * MySQL does when two transactions allocate an order number together, and a
 * single PHP process can only ever be inside one transaction at a time.
 *
 * WHAT IS BEING RACED. `orders.order_number` is NOT NULL UNIQUE. Both
 * placement paths used to mint it by reading MAX() *inside* the placing
 * transaction and walking forward to the first free number. Under MySQL's
 * default REPEATABLE READ every read in a transaction is served from that
 * transaction's own snapshot, so the retry loops were re-reading a frozen
 * maximum: every attempt computed the identical candidate, and the loser of
 * the race failed the whole placement with SQLSTATE 23000 at the last step of
 * a customer's checkout.
 *
 * It cannot prove anything on SQLite, which takes one database-wide write lock
 * and therefore never lets the two arms overlap.
 *
 * TWO PATHS, because the defect was in both and a fix has to cover both:
 *
 *   --path=manual    ManualOrderBuilder::create(), the back-office placement,
 *                    driven end to end (draft cart, pricing, insert).
 *   --path=checkout  Store\CheckoutController's own allocation, called through
 *                    reflection inside a real transaction and followed by the
 *                    real Order::create() — i.e. the allocate-and-insert pair
 *                    that raises the duplicate key on the storefront.
 *
 * Usage:
 *   php order-number-race-worker.php --mode=migrate --db=NAME
 *   php order-number-race-worker.php --mode=seed    --db=NAME --rounds=N
 *   php order-number-race-worker.php --mode=race    --db=NAME --plan=FILE \
 *       --arm=a|b --start=UNIXFLOAT --path=manual|checkout
 *
 * Each mode prints one line of JSON on stdout and nothing else.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Models\Customer;
use App\Models\Order;
use App\Services\ManualOrderBuilder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\ManualOrders;

$options = getopt('', [
    'mode:', 'db:', 'host::', 'port::', 'user::', 'pass::',
    'rounds::', 'plan::', 'arm::', 'start::', 'path::', 'count::',
]);

$mode = (string) ($options['mode'] ?? '');
$database = (string) ($options['db'] ?? '');

if ($mode === '' || $database === '') {
    fwrite(STDERR, "mode and db are required\n");
    exit(2);
}

/* ------------------------------------------------------------------- boot */

/*
 * Forced through the ENVIRONMENT, not config(). Several migrations in this set
 * call Artisan::call('config:cache'), each building a fresh Application that
 * re-reads .env and re-points Eloquent's resolver at itself; any config()
 * override made earlier is discarded with the old container, and a migrate run
 * that started on MySQL silently continues on the suite's SQLite file. The
 * long version of this landmine is in tests/Pest.php and in the coupon
 * worker's header. Environment variables survive it because Dotenv will not
 * overwrite a variable that is already set.
 */
$environment = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => (string) ($options['host'] ?? '127.0.0.1'),
    'DB_PORT' => (string) ($options['port'] ?? '3306'),
    'DB_DATABASE' => $database,
    'DB_USERNAME' => (string) ($options['user'] ?? 'root'),
    'DB_PASSWORD' => (string) ($options['pass'] ?? ''),
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
];

foreach ($environment as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'mysql',
    'database.connections.mysql.database' => $database,
]);
DB::purge('mysql');

/* ------------------------------------------------------------------ modes */

try {
    echo json_encode(match ($mode) {
        'migrate' => runMigrate($app),
        'seed' => runSeed((int) ($options['rounds'] ?? 1)),
        'race' => runRace(
            (string) ($options['plan'] ?? ''),
            (string) ($options['arm'] ?? 'a'),
            (float) ($options['start'] ?? 0),
            (string) ($options['path'] ?? 'manual'),
        ),
        'allocate' => runAllocate(
            (int) ($options['count'] ?? 100),
            (float) ($options['start'] ?? 0),
        ),
        default => throw new RuntimeException("unknown mode {$mode}"),
    }), "\n";
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'fatal' => $e->getMessage(),
        'where' => $e->getFile() . ':' . $e->getLine(),
    ]), "\n";
    exit(1);
}

/** Build the schema in the race database. */
function runMigrate($app): array
{
    $app->make(Kernel::class)->call('migrate', ['--force' => true]);

    return ['ok' => true];
}

/**
 * The shop, one product per arm per round, and two customers.
 *
 * A PRE-EXISTING ORDER CARRYING AN IMPORTED-LOOKING NUMBER is seeded on
 * purpose, and a SOFT-DELETED one beside it. Between them they pin the two
 * rules the allocator must not break: an imported WooCommerce order keeps its
 * number, and a trashed order still holds its number in the unique index.
 * An allocator that reads `Order::max(...)` through the default scope cannot
 * see the second one and will hand its number out again.
 */
function runSeed(int $rounds): array
{
    ManualOrders::shop();

    $products = [];

    for ($i = 0; $i < $rounds; $i++) {
        $products[] = [
            ManualOrders::product("Race {$i} A", 8900)->id,
            ManualOrders::product("Race {$i} B", 8900)->id,
        ];
    }

    $customers = [
        Customer::create([
            'name' => 'Racer A', 'first_name' => 'Racer', 'last_name' => 'A',
            'email' => 'racer-a@example.ae', 'phone' => '+971500000001',
        ])->id,
        Customer::create([
            'name' => 'Racer B', 'first_name' => 'Racer', 'last_name' => 'B',
            'email' => 'racer-b@example.ae', 'phone' => '+971500000002',
        ])->id,
    ];

    // An imported order, numbered the way WooCommerce numbered it.
    $imported = Order::create([
        'order_number' => '50001',
        'customer_id' => $customers[0],
        'email' => 'racer-a@example.ae',
        'status' => 'completed',
        'currency' => 'AED',
        'subtotal' => 8900, 'discount_total' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'tax_total' => 0, 'total' => 8900,
        'payment_method' => 'cod',
    ]);

    // A trashed order holding the next number up. Invisible to the default
    // scope, still very much present in the unique index.
    $trashed = Order::create([
        'order_number' => '50002',
        'customer_id' => $customers[1],
        'email' => 'racer-b@example.ae',
        'status' => 'cancelled',
        'currency' => 'AED',
        'subtotal' => 8900, 'discount_total' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'tax_total' => 0, 'total' => 8900,
        'payment_method' => 'cod',
    ]);
    $trashed->delete();

    /*
     * Re-seed the sequence now that those two rows exist.
     *
     * On the live server the migration that creates this row runs against a
     * table that already holds ~2,400 imported orders, so it seeds above them.
     * Here the migration ran first, against an empty table, and the imported
     * rows were inserted afterwards — so without this the arms would be
     * numbering from 10001 and the imported block would never be tested. This
     * puts the fixture in the state the real server is actually in.
     */
    DB::table(App\Services\Orders\OrderNumbers::TABLE)
        ->where('id', App\Services\Orders\OrderNumbers::ROW_ID)
        ->update(['next_number' => App\Services\Orders\OrderNumbers::seedValue()]);

    /*
     * A TRASHED ORDER SITTING ON THE SEQUENCE'S VERY NEXT NUMBER.
     *
     * Without this the soft-delete guard is not tested at all, and a first
     * version of this fixture proved it: deleting withTrashed() from the
     * allocator left the suite green, because the sequence had been seeded
     * above the trashed row and never proposed its number in the first place.
     *
     * A trashed order DOES still hold its number in the unique index, and the
     * case that matters is the one where the sequence points straight at it —
     * an order placed, then cancelled and trashed, while the sequence has not
     * moved past it. The allocator must step over this row. An allocator that
     * reads through the default scope cannot see it, hands the number out, and
     * the insert dies on the index.
     */
    $blockerNumber = (int) DB::table(App\Services\Orders\OrderNumbers::TABLE)
        ->where('id', App\Services\Orders\OrderNumbers::ROW_ID)
        ->value('next_number');

    $blocker = Order::create([
        'order_number' => (string) $blockerNumber,
        'customer_id' => $customers[0],
        'email' => 'racer-a@example.ae',
        'status' => 'cancelled',
        'currency' => 'AED',
        'subtotal' => 8900, 'discount_total' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'tax_total' => 0, 'total' => 8900,
        'payment_method' => 'cod',
    ]);
    $blocker->delete();

    return [
        'ok' => true,
        'blocker' => $blocker->order_number,
        'sequence_seeded_at' => $blockerNumber,
        'products' => $products,
        'customers' => $customers,
        'imported' => $imported->order_number,
        'trashed' => $trashed->order_number,
    ];
}

/**
 * Place one order per round, each at a wall-clock instant both arms agree on.
 *
 * The barrier is a spin on microtime rather than a sleep, for the reason the
 * coupon worker gives: usleep() granularity is the difference between a real
 * race and two placements that merely land in the same second.
 */
function runRace(string $planFile, string $arm, float $start, string $path): array
{
    $plan = json_decode((string) file_get_contents($planFile), true, 512, JSON_THROW_ON_ERROR);

    $index = $arm === 'a' ? 0 : 1;
    $customerId = $plan['customers'][$index];

    $results = [];

    /*
     * How late this process was to the barrier.
     *
     * Booting Laravel is not instant and the two arms do not boot equally
     * fast. If a worker reaches the first round after its instant has already
     * passed, the spin below returns at once and the arm runs every round
     * back to back — two placements that never overlap, which looks exactly
     * like a passing test. The test reads this and fails the run rather than
     * reporting a green it did not earn.
     */
    $readyAt = microtime(true);

    foreach ($plan['products'] as $round => $pair) {
        $at = $start + ($round * 0.45);

        while (microtime(true) < $at) {
            // Spin; the wait is under half a second and this process has
            // nothing else to do.
        }

        $outcome = [
            'round' => $round,
            'ok' => false,
            'error' => null,
            'order_id' => null,
            'order_number' => null,
            'duplicate' => false,
            // Wall clock either side of the placement, so the test can prove
            // the two arms were actually inside it together.
            'began' => null,
            'ended' => null,
        ];

        $outcome['began'] = microtime(true);

        try {
            $order = $path === 'checkout'
                ? placeViaCheckoutAllocation($customerId, $arm)
                : placeViaManualBuilder($pair[$index], $customerId);

            $outcome['ok'] = true;
            $outcome['order_id'] = $order->id;
            $outcome['order_number'] = (string) $order->order_number;
        } catch (Throwable $e) {
            $message = get_class($e) . ': ' . $e->getMessage();
            $outcome['error'] = $message;

            // The signature of the bug: the unique index refusing a number the
            // allocator had just been told was free.
            $outcome['duplicate'] = str_contains($message, '23000')
                || str_contains($message, 'Duplicate entry')
                || str_contains($message, 'UNIQUE constraint');
        }

        $outcome['ended'] = microtime(true);

        $results[] = $outcome;
    }

    return [
        'ok' => true,
        'arm' => $arm,
        'path' => $path,
        // Negative means this arm was ready before the barrier opened, which
        // is the only case in which the rounds below are a real race.
        'ready_offset' => $readyAt - $start,
        'results' => $results,
    ];
}

/**
 * Nothing but allocation, as fast as this process can ask for it.
 *
 * WHY THIS MODE EXISTS. The compare-and-swap in OrderNumbers::allocate() —
 * `WHERE next_number = <what we read>` — guards the window between reading the
 * sequence and advancing it. In the placement races above that window is a few
 * microseconds inside a round that also builds a cart, prices it and writes an
 * order, so two arms almost never land in it: removing the guard entirely left
 * forty rounds of the checkout race green. A test that cannot see a guard
 * removed is not testing it.
 *
 * Stripping the placement away is what makes it visible. Several processes
 * doing nothing but allocating spend all their time in exactly that window, so
 * two reads landing between each other's write goes from rare to routine, and
 * every number handed out twice shows up as a duplicate in the pooled result.
 *
 * The invariant is the allocator's whole purpose and is checked directly: no
 * number is ever returned twice, to anybody.
 */
function runAllocate(int $count, float $start): array
{
    $allocator = app(App\Services\Orders\OrderNumbers::class);

    $readyAt = microtime(true);

    while (microtime(true) < $start) {
        // Spin to the barrier, so every process is contending from the first
        // allocation rather than ramping up while the others finish.
    }

    $numbers = [];

    for ($i = 0; $i < $count; $i++) {
        $numbers[] = $allocator->allocate();
    }

    return [
        'ok' => true,
        'ready_offset' => $readyAt - $start,
        'numbers' => $numbers,
    ];
}

/** The back-office placement path, end to end. */
function placeViaManualBuilder(int $productId, int $customerId): Order
{
    $result = app(ManualOrderBuilder::class)->create([
        'customer_id' => $customerId,
        'items' => [['product_id' => $productId, 'quantity' => 1]],
        'address' => [
            'line1' => '12 Marina Walk', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000001',
        ],
        'payment_method' => 'cod',
        'channel' => 'phone',
    ], 'racer');

    if (! $result['ok']) {
        throw new RuntimeException('manual placement refused: ' . (string) $result['error']);
    }

    return $result['order'];
}

/**
 * The storefront's own allocate-and-insert, inside one transaction.
 *
 * The allocation is reached by reflection rather than copied, so this races
 * the code the shop actually runs: if CheckoutController stops using
 * nextOrderNumber(), this throws instead of quietly testing a duplicate of it.
 * The HTTP layer, the cart and the pricing are left out because none of them
 * touch the number — the contended resource is the allocation and the insert
 * that follows it, and keeping the transaction to those two makes a failure
 * unambiguous.
 */
function placeViaCheckoutAllocation(int $customerId, string $arm): Order
{
    $controller = app(App\Http\Controllers\Store\CheckoutController::class);

    $method = new ReflectionMethod($controller, 'nextOrderNumber');
    $method->setAccessible(true);

    /*
     * ALLOCATE FIRST, THEN OPEN THE TRANSACTION — the order place() itself
     * uses, and the order that matters. Allocating inside the transaction is
     * the bug: the number came from that transaction's snapshot, so the loser
     * of a race recomputed the same one however many times it retried. This
     * arm is arranged to match the controller so that what it proves is what
     * the shop does.
     */
    $number = $method->invoke($controller);

    return DB::transaction(function () use ($number, $customerId, $arm) {
        return Order::create([
            'order_number' => $number,
            'customer_id' => $customerId,
            'email' => $arm === 'a' ? 'racer-a@example.ae' : 'racer-b@example.ae',
            'status' => 'pending',
            'currency' => 'AED',
            'subtotal' => 8900, 'discount_total' => 0, 'shipping_total' => 0,
            'fee_total' => 0, 'tax_total' => 0, 'total' => 8900,
            'payment_method' => 'cod',
        ]);
    });
}
