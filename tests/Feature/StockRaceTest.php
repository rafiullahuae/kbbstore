<?php

declare(strict_types=1);

/**
 * Two shoppers, one remaining unit, at the same instant.
 *
 * This is the failure a transaction alone does not prevent, and the reason
 * CartService::claimOne() takes the product row under SELECT ... FOR UPDATE and
 * then repeats the condition in the UPDATE's own WHERE. Without either, both
 * transactions read `stock = 1`, both decide there is room, and both write: the
 * shop sells one unit twice, takes two lots of money, and drives the column to
 * -1 on the way.
 *
 * WHAT THIS TEST DOES, and why it is shaped so awkwardly. It runs two real OS
 * processes, each opening a real transaction that writes a real order and then
 * calls CartService::claimStock() — the same call, in the same place, as
 * Store\CheckoutController::place() — against the same single-unit product,
 * synchronised on a wall clock. A single PHP process cannot express this: it
 * can only be inside one transaction at a time, so any "concurrency" test
 * written in one process is really a test that the code CALLS lockForUpdate(),
 * which is the structural assertion this is supposed to replace. Asserting the
 * lock exists would pass even if it were taken on the wrong row, after the
 * check, or in a transaction that had already committed.
 *
 * IT IS MYSQL-ONLY, and skips otherwise. SQLite serialises writers with one
 * database-wide write lock, so the two arms cannot overlap and the test would
 * pass with both guards deleted — a green light that means nothing. Production
 * runs MySQL; this is proven there.
 *
 * It uses its own database, created and dropped here, for the reason
 * CouponRaceTest gives: the workers COMMIT, and the suite's own database is
 * inside RefreshDatabase's per-test transaction.
 */

use Illuminate\Support\Facades\DB;

/** Run one stock-race worker to completion and return its decoded JSON line. */
function stockRaceWorker(array $arguments): array
{
    return stockRaceFinish(stockRaceStart($arguments));
}

/** @return array{0: resource, 1: array<int, resource>} */
function stockRaceStart(array $arguments): array
{
    $command = array_merge([PHP_BINARY, base_path('tests/Support/stock-race-worker.php')], $arguments);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $process = proc_open($command, $descriptors, $pipes, base_path());

    if (! is_resource($process)) {
        throw new RuntimeException('could not start the stock race worker');
    }

    return [$process, $pipes];
}

function stockRaceFinish(array $handle): array
{
    [$process, $pipes] = $handle;

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    // The result is the LAST JSON line, not the whole of stdout: the migration
    // set prints as it goes, so a migrate run hands back several screens of
    // prose with the result at the end of it.
    foreach (array_reverse(preg_split('/\R/', trim($stdout)) ?: []) as $line) {
        $decoded = json_decode(trim($line), true);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    throw new RuntimeException("stock race worker returned no JSON.\nstdout: {$stdout}\nstderr: {$stderr}");
}

it('lets only one of two simultaneous orders take the last unit in stock', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped(
            'The stock race is only observable on MySQL. SQLite takes one database-wide '
            . 'write lock, so the two arms never overlap and the result would not depend on '
            . 'the FOR UPDATE clause being there. Run this with phpunit-mysql.xml.'
        );
    }

    $rounds = 10;
    $raceDatabase = DB::connection()->getDatabaseName() . '_stock_race';

    $config = config('database.connections.mysql');
    $server = new PDO(
        "mysql:host={$config['host']};port={$config['port']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    try {
        $server->exec("DROP DATABASE IF EXISTS `{$raceDatabase}`");
        $server->exec("CREATE DATABASE `{$raceDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        if (! str_contains($e->getMessage(), '1044') && ! str_contains($e->getMessage(), '1045')) {
            throw $e;
        }

        // Skipped LOUDLY, and naming the exact grant: a concurrency guard that
        // quietly stops running is worse than one that was never written.
        test()->markTestSkipped(
            'THIS CONCURRENCY GUARD DID NOT RUN. The test user cannot create the scratch '
            . "database `{$raceDatabase}` that the two competing processes need. Grant it with: "
            . "GRANT ALL PRIVILEGES ON `{$raceDatabase}`.* TO '" . $config['username'] . "'@'%'; "
            . "GRANT CREATE ON *.* TO '" . $config['username'] . "'@'%'; "
            . 'Underlying error: ' . $e->getMessage()
        );
    }

    $connection = [
        "--db={$raceDatabase}",
        "--host={$config['host']}",
        "--port={$config['port']}",
        "--user={$config['username']}",
        '--pass=' . (string) $config['password'],
    ];

    try {
        $migrated = stockRaceWorker(array_merge(['--mode=migrate'], $connection));
        expect($migrated['ok'] ?? false)->toBeTrue('the race database could not be migrated: ' . json_encode($migrated));

        $plan = stockRaceWorker(array_merge(['--mode=seed', "--rounds={$rounds}"], $connection));
        expect($plan['ok'] ?? false)->toBeTrue('seeding failed: ' . json_encode($plan));

        $planFile = tempnam(sys_get_temp_dir(), 'stock-race-');
        file_put_contents($planFile, json_encode($plan));

        // Far enough ahead that both workers have booted Laravel and are
        // spinning on the barrier before the first round opens.
        $start = microtime(true) + 6.0;

        $a = stockRaceStart(array_merge(['--mode=race', "--plan={$planFile}", '--arm=a', "--start={$start}"], $connection));
        $b = stockRaceStart(array_merge(['--mode=race', "--plan={$planFile}", '--arm=b', "--start={$start}"], $connection));

        $resultA = stockRaceFinish($a);
        $resultB = stockRaceFinish($b);

        @unlink($planFile);

        expect($resultA['ok'] ?? false)->toBeTrue('arm A failed: ' . json_encode($resultA));
        expect($resultB['ok'] ?? false)->toBeTrue('arm B failed: ' . json_encode($resultB));

        // Read the outcome from the database rather than from what the workers
        // believe happened.
        $race = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$raceDatabase}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        foreach ($plan['products'] as $round => $productId) {
            $row = $race->query(
                "SELECT stock, stock_status FROM products WHERE id = {$productId}"
            )->fetch(PDO::FETCH_ASSOC);

            $orders = (int) $race->query(
                "SELECT COUNT(*) FROM orders WHERE order_number IN ('STOCK-{$round}-a', 'STOCK-{$round}-b')"
            )->fetchColumn();

            $outcomes = [
                $resultA['results'][$round]['ok'] ?? null,
                $resultB['results'][$round]['ok'] ?? null,
            ];

            $context = "round {$round}: stock={$row['stock']} status={$row['stock_status']} "
                . "orders={$orders} outcomes=" . json_encode($outcomes) . ' '
                . 'errors=' . json_encode([
                    $resultA['results'][$round]['error'] ?? null,
                    $resultB['results'][$round]['error'] ?? null,
                ]);

            // The whole point, four ways: exactly one of the two placements was
            // told yes, exactly one order survived its transaction, the shelf
            // stopped at zero rather than going negative, and the storefront was
            // told the product is gone.
            expect(array_filter($outcomes))->toHaveCount(1, $context);
            expect($orders)->toBe(1, $context);
            expect((int) $row['stock'])->toBe(0, $context);
            expect((string) $row['stock_status'])->toBe('outofstock', $context);
        }
    } finally {
        $server->exec("DROP DATABASE IF EXISTS `{$raceDatabase}`");
    }
})->skipOnWindows();
