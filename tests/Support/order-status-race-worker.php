<?php

declare(strict_types=1);

/**
 * One arm of the double-release race, run as its own OS process.
 *
 * OrderStatusRaceTest starts two of these at once and has them close the same
 * order at the same instant. Two processes is the point: what is under test is
 * what the database does when two transactions reach the same redemption row
 * together, and that cannot be observed from inside one PHP process, which can
 * only ever be in one transaction at a time. A single-process test could assert
 * that the code calls a conditional UPDATE, which is the structural claim this
 * is meant to replace — it would pass with the condition on the wrong column,
 * or checked after the fact.
 *
 * TWO RACES PER ROUND, because there are two things to be sure of.
 *
 *   release — both arms call CouponService::releaseRedemptions() on the same
 *             order directly. This is the claim on its own, with no order lock
 *             anywhere near it: exactly one arm may hand the use back.
 *
 *   transition — arm A cancels the order, arm B refunds it. Two DIFFERENT
 *             transitions, so neither is refused as a no-op, and both would
 *             release. This is the whole funnel under contention, which is the
 *             shape the real failure has: an operator cancelling an order while
 *             a refund settles.
 *
 * Both coupons are seeded with a usage_count HIGHER than the number of
 * redemption rows this application holds for them, which is what a coupon
 * imported from WooCommerce genuinely looks like. It also makes a double
 * release visible: the decrement is floored at zero, so a counter of 1 hides
 * the second one and a counter of 5 does not.
 *
 * Every mode works on a database named on the command line, never the suite's
 * own: these writes COMMIT, and committed rows would otherwise outlive the test
 * and be visible to every test after it.
 *
 * Usage:
 *   php order-status-race-worker.php --mode=migrate --db=NAME
 *   php order-status-race-worker.php --mode=seed    --db=NAME --rounds=N
 *   php order-status-race-worker.php --mode=race    --db=NAME --plan=FILE --arm=a|b --start=UNIXFLOAT
 *
 * Each mode prints one line of JSON on stdout and nothing else.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Services\CouponService;
use App\Services\Orders\OrderStatus;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$options = getopt('', [
    'mode:', 'db:', 'host::', 'port::', 'user::', 'pass::',
    'rounds::', 'plan::', 'arm::', 'start::',
]);

$mode = (string) ($options['mode'] ?? '');
$database = (string) ($options['db'] ?? '');

if ($mode === '' || $database === '') {
    fwrite(STDERR, "mode and db are required\n");
    exit(2);
}

/* ------------------------------------------------------------------- boot */

/*
 * The database is forced through the ENVIRONMENT, not through config(), for the
 * reason tests/Support/coupon-race-worker.php sets out at length: several
 * migrations in this set call Artisan::call('config:cache'), each of which
 * builds a brand-new Application that re-reads .env and re-points the facade
 * root and Eloquent's connection resolver at itself. A config() override made
 * before that point is discarded with the old container, and .env here says
 * sqlite, so a run that started against MySQL silently continues against the
 * suite's own file partway through. Environment variables survive it, because
 * Dotenv will not overwrite a variable that is already set.
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

// Belt as well as braces: if a connection was opened during bootstrap, drop it
// so the next query builds a fresh PDO from the config above.
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
 * Two orders per round, each carrying one real redemption of its own coupon.
 *
 * The redemption is recorded through CouponService::recordRedemption(), inside
 * the transaction that writes the order, exactly as both placement paths do it.
 * Writing the row by hand would prove nothing about the counter it has to move.
 */
function runSeed(int $rounds): array
{
    $customer = Customer::create([
        'name' => 'Race Buyer',
        'first_name' => 'Race',
        'last_name' => 'Buyer',
        'email' => 'race-buyer@example.ae',
        'phone' => '+971500000009',
    ]);

    $plan = ['ok' => true, 'rounds' => [], 'customer' => $customer->id];

    for ($i = 0; $i < $rounds; $i++) {
        $round = [];

        foreach (['release', 'transition'] as $kind) {
            $coupon = Coupon::create([
                'code' => strtoupper($kind) . $i,
                'type' => 'fixed_cart',
                'amount' => 5000,
            ]);

            $order = DB::transaction(function () use ($coupon, $customer, $kind, $i) {
                $order = Order::create([
                    'order_number' => 'RACE-' . $kind . '-' . $i,
                    'customer_id' => $customer->id,
                    'email' => $customer->email,
                    'status' => 'processing',
                    'currency' => 'AED',
                    'subtotal' => 20000,
                    'discount_total' => 5000,
                    'total' => 15000,
                    'payment_method' => 'cod',
                    'coupon_code' => $coupon->code,
                    'paid_at' => now(),
                    'captured_at' => now(),
                    'captured_total' => 15000,
                ]);

                app(CouponService::class)->recordRedemption(
                    $coupon,
                    5000,
                    (int) $order->id,
                    (int) $customer->id,
                    (string) $customer->email,
                );

                return $order;
            });

            /*
             * Four uses this application holds no row for, on top of the one it
             * does — which is what the WooCommerce import leaves behind, and
             * what makes a second release visible rather than swallowed by the
             * floor at zero.
             */
            Coupon::whereKey($coupon->id)->update(['usage_count' => 5]);

            $round[$kind] = ['coupon' => (int) $coupon->id, 'order' => (int) $order->id];
        }

        $plan['rounds'][] = $round;
    }

    return $plan;
}

/**
 * Both races, each at a wall-clock instant the two arms agree on.
 *
 * The barrier is a spin on microtime rather than a sleep: the two processes
 * need to enter their transactions as close together as the OS will allow, and
 * usleep() granularity is the difference between a real race and two closures
 * that merely land in the same second.
 */
function runRace(string $planFile, string $arm, float $start): array
{
    $plan = json_decode((string) file_get_contents($planFile), true, 512, JSON_THROW_ON_ERROR);

    $coupons = app(CouponService::class);
    $statuses = app(OrderStatus::class);

    $results = [];

    foreach ($plan['rounds'] as $index => $round) {
        $at = $start + ($index * 0.5);

        spinUntil($at);

        $outcome = ['round' => $index];

        try {
            /*
             * The claim on its own: no order lock, both arms on the same rows.
             *
             * THE TRANSACTION IS OPENED, AND A ROW READ, BEFORE THE BARRIER.
             * That is not stagecraft to make the race easier to win — it is the
             * situation the claim exists for. Under MySQL's REPEATABLE READ a
             * transaction's reads are served from the snapshot taken at its
             * first read, and callers here really are inside long transactions:
             * PaymentRefunder holds one open while it talks to a gateway. So an
             * arm that read "not released yet" a moment ago goes on reading it
             * for as long as its transaction lives, however many other
             * processes have released it since. Without a claim that the
             * database settles, both arms act on that stale answer and the use
             * comes back twice.
             */
            $outcome['released'] = DB::transaction(function () use ($coupons, $round, $at) {
                \App\Models\CouponRedemption::query()
                    ->where('order_id', (int) $round['release']['order'])
                    ->get();

                spinUntil($at + 0.05);

                return $coupons->releaseRedemptions((int) $round['release']['order']);
            });
        } catch (Throwable $e) {
            $outcome['released_error'] = get_class($e) . ': ' . $e->getMessage();
        }

        spinUntil($at + 0.25);

        try {
            // The whole funnel: one arm cancels, the other refunds. Both are
            // real transitions and both would hand the use back.
            $outcome['from'] = $statuses->moveTo(
                (int) $round['transition']['order'],
                $arm === 'a' ? 'cancelled' : 'refunded',
                by: 'race-' . $arm,
            );
        } catch (Throwable $e) {
            $outcome['transition_error'] = get_class($e) . ': ' . $e->getMessage();
        }

        $results[] = $outcome;
    }

    return ['ok' => true, 'arm' => $arm, 'results' => $results];
}

function spinUntil(float $at): void
{
    while (microtime(true) < $at) {
        // Spin. The wait is at most half a second and this process has nothing
        // else to do; usleep() granularity is too coarse to make a real race.
    }
}
