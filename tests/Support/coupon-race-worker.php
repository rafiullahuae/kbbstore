<?php

declare(strict_types=1);

/**
 * One arm of the coupon race, run as its own OS process.
 *
 * CouponRaceTest starts two of these at once and has them place real orders
 * against the same limited coupon at the same instant. Two processes is the
 * point: the thing under test is what MySQL does when two transactions reach
 * SELECT ... FOR UPDATE on one coupon row together, and that cannot be
 * observed from inside a single PHP process, which can only ever be in one
 * transaction at a time.
 *
 * It is also why this cannot prove anything on SQLite. SQLite takes a single
 * database-wide write lock, so the two arms never overlap in the first place
 * and the test would pass whether the FOR UPDATE clause were there or not.
 * The test skips unless the suite is pointed at MySQL, which is what
 * production runs.
 *
 * Every mode works on a database named on the command line, never the suite's
 * own: these writes COMMIT, and committed rows would otherwise outlive the
 * test and be visible to every test after it.
 *
 * Usage:
 *   php coupon-race-worker.php --mode=migrate --db=NAME
 *   php coupon-race-worker.php --mode=seed    --db=NAME --rounds=N
 *   php coupon-race-worker.php --mode=race    --db=NAME --plan=FILE --arm=a|b --start=UNIXFLOAT
 *
 * Each mode prints one line of JSON on stdout and nothing else.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Models\Coupon;
use App\Models\Customer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\ManualOrders;

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
 * The database is forced through the ENVIRONMENT, not through config().
 *
 * config() alone is not enough and the failure is silent. Several migrations in
 * this set call Artisan::call('config:cache'), and each of those builds a
 * brand-new Application which re-reads .env and re-points both the facade root
 * and Eloquent's connection resolver at itself — the landmine written up at
 * length in tests/Pest.php. Any config() override made before that point is
 * discarded along with the old container. .env here says DB_CONNECTION=sqlite,
 * so a migrate run that started against MySQL silently CONTINUES against the
 * suite's own SQLite file partway through: the first attempt at this test
 * migrated 156 tables into MySQL and then failed adding an index that already
 * existed in SQLite, which is what that mixture looks like from the outside.
 *
 * Environment variables survive that, because Laravel loads .env immutably:
 * Dotenv will not overwrite a variable that is already set, so every
 * Application built in this process — including the throwaway ones — resolves
 * to the same race database.
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
 * The shop, and one limited coupon per round.
 *
 * Every coupon carries usage_limit = 1, which is the sharpest form of the
 * race: two orders, one use, and any outcome other than exactly one winner is
 * the bug.
 */
function runSeed(int $rounds): array
{
    ManualOrders::shop();

    $coupons = [];
    $products = [];

    for ($i = 0; $i < $rounds; $i++) {
        $coupons[] = Coupon::create([
            'code' => 'RACE' . $i,
            'type' => 'fixed_cart',
            'amount' => 500,
            'usage_limit' => 1,
        ])->id;

        // One product per arm per round, so the two arms never contend on
        // anything except the coupon itself.
        $products[] = [
            ManualOrders::product("Race {$i} A", 8900)->id,
            ManualOrders::product("Race {$i} B", 8900)->id,
        ];
    }

    return [
        'ok' => true,
        'coupons' => $coupons,
        'products' => $products,
        'customers' => [
            Customer::create([
                'name' => 'Racer A', 'first_name' => 'Racer', 'last_name' => 'A',
                'email' => 'racer-a@example.ae', 'phone' => '+971500000001',
            ])->id,
            Customer::create([
                'name' => 'Racer B', 'first_name' => 'Racer', 'last_name' => 'B',
                'email' => 'racer-b@example.ae', 'phone' => '+971500000002',
            ])->id,
        ],
    ];
}

/**
 * Place one order per round, each at a wall-clock instant both arms agree on.
 *
 * The barrier is a spin on microtime rather than a sleep: the two processes
 * need to enter the transaction as close together as the OS will allow, and
 * usleep() granularity is the difference between a real race and two
 * placements that merely happen in the same second.
 */
function runRace(string $planFile, string $arm, float $start): array
{
    $plan = json_decode((string) file_get_contents($planFile), true, 512, JSON_THROW_ON_ERROR);

    $index = $arm === 'a' ? 0 : 1;
    $customerId = $plan['customers'][$index];
    $email = $arm === 'a' ? 'racer-a@example.ae' : 'racer-b@example.ae';
    $coupons = app(App\Services\CouponService::class);

    $results = [];

    foreach ($plan['coupons'] as $round => $couponId) {
        $at = $start + ($round * 0.35);

        while (microtime(true) < $at) {
            // Spin. The wait is at most 350ms and this process has nothing
            // else to do. usleep() granularity is the difference between a real
            // race and two placements that merely land in the same second.
        }

        $coupon = Coupon::findOrFail($couponId);

        $outcome = ['round' => $round, 'coupon_id' => $couponId, 'ok' => false, 'error' => null, 'order_id' => null];

        try {
            $order = DB::transaction(function () use ($coupon, $couponId, $round, $arm, $customerId, $email, $coupons) {
                /*
                 * A real order, written in the same shape and the same order as
                 * both placement paths: the order row first, the redemption
                 * second, both inside one transaction.
                 *
                 * order_number is assigned per arm rather than allocated,
                 * because allocating it is a SEPARATE race that this test is
                 * not about and cannot survive — see the note in
                 * CouponRaceTest. Giving each arm a number nothing else can
                 * pick leaves the coupon row as the only contended resource,
                 * which is the whole point: if the run ends with usage_count 2
                 * against a limit of 1, the coupon lock is what failed.
                 */
                $order = App\Models\Order::create([
                    'order_number' => "RACE-{$round}-{$arm}",
                    'customer_id' => $customerId,
                    'email' => $email,
                    'status' => 'processing',
                    'currency' => 'AED',
                    'subtotal' => 8900,
                    'discount_total' => 500,
                    'shipping_total' => 0,
                    'fee_total' => 0,
                    'tax_total' => 0,
                    'total' => 8400,
                    'payment_method' => 'cod',
                    'coupon_code' => 'RACE' . $round,
                ]);

                $coupons->recordRedemption($coupon, 500, $order->id, $customerId, $email);

                return $order;
            });

            $outcome['ok'] = true;
            $outcome['order_id'] = $order->id;
        } catch (App\Services\CouponExhausted $e) {
            $outcome['error'] = $e->getMessage();
        } catch (Throwable $e) {
            // Anything else is a real failure and must not be read as the
            // coupon having been refused.
            $outcome['error'] = get_class($e) . ': ' . $e->getMessage();
            $outcome['unexpected'] = true;
        }

        $results[] = $outcome;
    }

    return ['ok' => true, 'arm' => $arm, 'results' => $results];
}
