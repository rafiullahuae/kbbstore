<?php

declare(strict_types=1);

/**
 * One arm of the stock race, run as its own OS process.
 *
 * StockRaceTest starts two of these at once and has them claim the same single
 * remaining unit at the same instant, each inside its own transaction, through
 * CartService::claimStock() — the same call, in the same place, as
 * Store\CheckoutController::place().
 *
 * Two processes is the point, and it is the same point CouponRaceTest's worker
 * makes: what is under test is what MySQL does when two transactions reach
 * SELECT ... FOR UPDATE and a conditional decrement on one products row
 * together, and a single PHP process can only ever be inside one transaction
 * at a time. A "race" written in one process is really a test that the code
 * calls lockForUpdate(), which is the structural assertion this replaces.
 *
 * It cannot prove anything on SQLite, which takes one database-wide write lock,
 * so the two arms never overlap. Production runs MySQL; this is proven there.
 *
 * Every mode works on a database named on the command line, never the suite's
 * own: these writes COMMIT, and committed rows would otherwise be invisible to
 * the assertions and outlive the test.
 *
 * Usage:
 *   php stock-race-worker.php --mode=migrate --db=NAME
 *   php stock-race-worker.php --mode=seed    --db=NAME --rounds=N
 *   php stock-race-worker.php --mode=race    --db=NAME --plan=FILE --arm=a|b --start=UNIXFLOAT
 *
 * Each mode prints one line of JSON on stdout and nothing else.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        'migrate' => stockRaceMigrate($app),
        'seed' => stockRaceSeed((int) ($options['rounds'] ?? 1)),
        'race' => stockRaceRun(
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

function stockRaceMigrate($app): array
{
    $app->make(Kernel::class)->call('migrate', ['--force' => true]);

    return ['ok' => true];
}

/**
 * One product per round with EXACTLY ONE unit on the shelf, and a basket per
 * arm holding one of it.
 *
 * usage of a single unit is the sharpest form of the race: two baskets, one
 * unit, and any outcome other than exactly one winner is the bug. The two carts
 * are separate rows so the arms contend on the products row and nothing else.
 */
function stockRaceSeed(int $rounds): array
{
    ManualOrders::shop();

    $products = [];
    $carts = [];

    for ($i = 0; $i < $rounds; $i++) {
        $product = ManualOrders::product("Race {$i}", 8900, [
            'manage_stock' => true,
            'stock' => 1,
            'stock_status' => 'instock',
        ]);

        $products[] = $product->id;

        $pair = [];

        foreach (['a', 'b'] as $arm) {
            $cart = Cart::create([
                'token' => (string) Str::uuid(),
                'currency' => 'AED',
                'status' => 'active',
                'shipping_country' => 'AE',
                'last_activity_at' => now(),
            ]);

            $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 8900,
            ]);

            $pair[$arm] = $cart->id;
        }

        $carts[] = $pair;
    }

    return ['ok' => true, 'products' => $products, 'carts' => $carts];
}

/**
 * Claim one unit per round, at a wall-clock instant both arms agree on.
 *
 * The barrier is a spin on microtime rather than a sleep: the two processes
 * need to enter the transaction as close together as the OS will allow, and
 * usleep() granularity is the difference between a real race and two claims
 * that merely happen in the same second.
 *
 * An order row is written alongside the claim, in the same transaction and in
 * the same order as place() does it, so the rollback being tested is the real
 * one. order_number is assigned per arm rather than allocated, because
 * allocating it is a SEPARATE race that this test is not about — see the note
 * in CouponRaceTest.
 */
function stockRaceRun(string $planFile, string $arm, float $start): array
{
    $plan = json_decode((string) file_get_contents($planFile), true, 512, JSON_THROW_ON_ERROR);

    $carts = app(CartService::class);
    $results = [];

    foreach ($plan['products'] as $round => $productId) {
        $at = $start + ($round * 0.35);

        while (microtime(true) < $at) {
            // Spin; see above.
        }

        $cart = Cart::with('items')->findOrFail($plan['carts'][$round][$arm]);

        $outcome = ['round' => $round, 'product_id' => $productId, 'ok' => false, 'error' => null];

        try {
            DB::transaction(function () use ($cart, $carts, $round, $arm) {
                App\Models\Order::create([
                    'order_number' => "STOCK-{$round}-{$arm}",
                    'email' => "racer-{$arm}@example.ae",
                    'status' => 'processing',
                    'currency' => 'AED',
                    'subtotal' => 8900,
                    'discount_total' => 0,
                    'shipping_total' => 0,
                    'fee_total' => 0,
                    'tax_total' => 0,
                    'total' => 8900,
                    'payment_method' => 'cod',
                ]);

                $carts->claimStock($cart);
            });

            $outcome['ok'] = true;
        } catch (App\Services\StockUnavailable $e) {
            $outcome['error'] = $e->getMessage();
        } catch (Throwable $e) {
            // Anything else is a real failure and must not be read as the shelf
            // having been empty.
            $outcome['error'] = get_class($e) . ': ' . $e->getMessage();
            $outcome['unexpected'] = true;
        }

        $results[] = $outcome;
    }

    return ['ok' => true, 'arm' => $arm, 'results' => $results];
}
