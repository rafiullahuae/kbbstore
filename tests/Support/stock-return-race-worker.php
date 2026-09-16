<?php

declare(strict_types=1);

/**
 * One arm of the stock-RETURN race, run as its own OS process.
 *
 * StockReturnRaceTest starts two of these at once and has them release the same
 * order's claimed units at the same instant, through
 * App\Services\StockClaim::release() — the same call the cancellation paths
 * make. Exactly one of them must credit the shelf.
 *
 * WHY THIS NEEDS TWO PROCESSES, stated because this lane proved it the hard
 * way. release() carries two guards against a double return: the SELECT filters
 * `released_at IS NULL`, and the UPDATE that claims each row repeats the
 * condition and checks the affected row count. In ONE process either guard
 * alone is sufficient — deleting the SELECT's filter leaves the UPDATE to
 * refuse the second pass, and deleting the UPDATE's leaves the SELECT to find
 * nothing. Both mutations were run against OrderStockReturnTest and both stayed
 * green. Only with two transactions in flight together does the UPDATE's own
 * condition become the thing that decides, because only then can both arms have
 * selected the row before either has written it.
 *
 * So the single-process suite cannot see the guard that matters here at all,
 * and this file is not belt-and-braces: it is the only thing testing it.
 *
 * IT IS MYSQL-ONLY, and the test skips otherwise. SQLite serialises writers
 * with one database-wide write lock, so the two arms cannot overlap and the
 * result would not depend on the guard being there. Production runs MySQL.
 *
 * Every mode works on a database named on the command line, never the suite's
 * own: these writes COMMIT, and committed rows would otherwise be invisible to
 * the assertions and outlive the test.
 *
 * Usage:
 *   php stock-return-race-worker.php --mode=migrate --db=NAME
 *   php stock-return-race-worker.php --mode=seed    --db=NAME --rounds=N
 *   php stock-return-race-worker.php --mode=race    --db=NAME --plan=FILE --arm=a|b --start=UNIXFLOAT
 *
 * Each mode prints one line of JSON on stdout and nothing else.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Models\Order;
use App\Services\StockClaim;
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
 * The database is forced through the ENVIRONMENT, not through config(), for the
 * reason coupon-race-worker.php sets out at length: several migrations in this
 * set call Artisan::call('config:cache'), each of which builds a brand-new
 * Application that re-reads .env and re-points Eloquent's connection resolver
 * at itself. A config() override made before that point is discarded with the
 * old container, and .env here says sqlite — so a run that started against
 * MySQL silently continues against the suite's own file partway through.
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
        'migrate' => stockReturnRaceMigrate($app),
        'seed' => stockReturnRaceSeed((int) ($options['rounds'] ?? 1)),
        'race' => stockReturnRaceRun(
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

function stockReturnRaceMigrate($app): array
{
    $app->make(Kernel::class)->call('migrate', ['--force' => true]);

    return ['ok' => true];
}

/**
 * One product per round with exactly one unit, and an order that has claimed it.
 *
 * Seeded THROUGH StockClaim::claim() rather than by writing the ledger row by
 * hand, so what the race releases is a real claim made the way a placement makes
 * one — the shelf at 0, the status at outofstock and one unreleased row.
 */
function stockReturnRaceSeed(int $rounds): array
{
    ManualOrders::shop();

    $products = [];
    $orders = [];

    for ($i = 0; $i < $rounds; $i++) {
        $product = ManualOrders::product("Return {$i}", 8900, [
            'manage_stock' => true,
            'stock' => 1,
            'stock_status' => 'instock',
        ]);

        $order = Order::create([
            'order_number' => "RETURN-{$i}",
            'email' => 'returner@example.ae',
            'status' => 'pending',
            'currency' => 'AED',
            'subtotal' => 8900,
            'discount_total' => 0,
            'shipping_total' => 0,
            'fee_total' => 0,
            'tax_total' => 0,
            'total' => 8900,
            'payment_method' => 'cod',
        ]);

        DB::transaction(function () use ($product, $order) {
            app(StockClaim::class)->claim([[
                'product_id' => (int) $product->id,
                'variant_id' => null,
                'quantity' => 1,
                'label' => (string) $product->name,
            ]], (int) $order->id);
        });

        $products[] = $product->id;
        $orders[] = $order->id;
    }

    return ['ok' => true, 'products' => $products, 'orders' => $orders];
}

/**
 * Release the same order from both arms at a wall-clock instant they agree on.
 *
 * The barrier is a spin on microtime rather than a sleep: the two processes need
 * to enter release() as close together as the OS will allow, and usleep()
 * granularity is the difference between a real race and two releases that merely
 * happen in the same second.
 */
function stockReturnRaceRun(string $planFile, string $arm, float $start): array
{
    $plan = json_decode((string) file_get_contents($planFile), true, 512, JSON_THROW_ON_ERROR);

    $stock = app(StockClaim::class);
    $results = [];

    foreach ($plan['orders'] as $round => $orderId) {
        $at = $start + ($round * 0.35);

        while (microtime(true) < $at) {
            // Spin; see above.
        }

        $outcome = ['round' => $round, 'order_id' => $orderId, 'returned' => null, 'error' => null];

        try {
            $outcome['returned'] = $stock->release((int) $orderId, 'order cancelled');
        } catch (Throwable $e) {
            $outcome['error'] = get_class($e) . ': ' . $e->getMessage();
            $outcome['unexpected'] = true;
        }

        $results[] = $outcome;
    }

    return ['ok' => true, 'arm' => $arm, 'results' => $results];
}
