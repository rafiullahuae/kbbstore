<?php

declare(strict_types=1);

/**
 * Two cancellations of one order at the same instant. The units go back once.
 *
 * THE FAILURE THIS GUARDS. An operator presses Cancel on the order screen while
 * a gateway webhook is marking the same order failed, or two operators cancel
 * the same order from two browsers, or a bulk action overlaps a single-order
 * one. Both arms read the claim ledger, both see one unreleased row for one
 * unit, both decide there is something to give back, and the shop credits itself
 * with two units it does not have. Inventory invented out of a double click is
 * exactly the failure the return was built to avoid causing.
 *
 * WHY THIS CANNOT BE WRITTEN IN ONE PROCESS, and this was measured rather than
 * assumed. StockClaim::release() carries two guards: the SELECT filters
 * `released_at IS NULL`, and the UPDATE that claims each row repeats the
 * condition and checks how many rows it changed. Each was deleted in turn and
 * OrderStockReturnTest — fifteen cases including two explicit double-return
 * ones — stayed GREEN both times, because in a single process either guard
 * alone covers for the other: with no filter on the SELECT the UPDATE refuses
 * the second pass, and with no condition on the UPDATE the SELECT finds nothing
 * to pass it. Only with two transactions in flight together can both arms have
 * selected the row before either has written it, which is the only state in
 * which the UPDATE's own condition is the thing that decides.
 *
 * So the single-process suite is blind to the guard that actually matters here,
 * and this file is not redundancy. It is the test.
 *
 * WHAT IT TAKES TO MAKE THIS FAIL, recorded because the first two attempts did
 * not. Deleting the UPDATE's `whereNull` alone leaves this GREEN, and for a
 * reason that has nothing to do with correctness: the row's new values are
 * timestamps at second precision, so the losing arm writes bytes identical to
 * the winner's and MySQL reports 0 changed rows anyway. The right answer,
 * arrived at by accident, and it would stop being the answer the day a column
 * on this row changed. What this test measures is therefore the guard as a
 * whole — the filter and the affected-row check together — and it fails loudly
 * when they are gone, with `stock=2` against a single claimed unit.
 *
 * IT IS MYSQL-ONLY, and skips otherwise. SQLite serialises writers with one
 * database-wide write lock, so the two arms never overlap and the test would
 * pass with the guard deleted — a green light that means nothing. Production
 * runs MySQL; this is proven there.
 *
 * It uses its own database, created and dropped here, for the reason
 * CouponRaceTest gives: the workers COMMIT, and the suite's own database is
 * inside RefreshDatabase's per-test transaction.
 */

use Illuminate\Support\Facades\DB;

/** @return array{0: resource, 1: array<int, resource>} */
function stockReturnRaceStart(array $arguments): array
{
    $command = array_merge([PHP_BINARY, base_path('tests/Support/stock-return-race-worker.php')], $arguments);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $process = proc_open($command, $descriptors, $pipes, base_path());

    if (! is_resource($process)) {
        throw new RuntimeException('could not start the stock return race worker');
    }

    return [$process, $pipes];
}

function stockReturnRaceFinish(array $handle): array
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

    throw new RuntimeException("stock return race worker returned no JSON.\nstdout: {$stdout}\nstderr: {$stderr}");
}

function stockReturnRaceWorker(array $arguments): array
{
    return stockReturnRaceFinish(stockReturnRaceStart($arguments));
}

it('credits the shelf once when two cancellations release the same order together', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped(
            'The stock return race is only observable on MySQL. SQLite takes one '
            . 'database-wide write lock, so the two arms never overlap and the result would '
            . 'not depend on the conditional UPDATE being there. Run this with phpunit-mysql.xml.'
        );
    }

    $rounds = 10;
    $raceDatabase = DB::connection()->getDatabaseName() . '_return_race';

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
        $migrated = stockReturnRaceWorker(array_merge(['--mode=migrate'], $connection));
        expect($migrated['ok'] ?? false)->toBeTrue('the race database could not be migrated: ' . json_encode($migrated));

        $plan = stockReturnRaceWorker(array_merge(['--mode=seed', "--rounds={$rounds}"], $connection));
        expect($plan['ok'] ?? false)->toBeTrue('seeding failed: ' . json_encode($plan));

        $planFile = tempnam(sys_get_temp_dir(), 'stock-return-race-');
        file_put_contents($planFile, json_encode($plan));

        // Far enough ahead that both workers have booted Laravel and are
        // spinning on the barrier before the first round opens.
        $start = microtime(true) + 6.0;

        $a = stockReturnRaceStart(array_merge(['--mode=race', "--plan={$planFile}", '--arm=a', "--start={$start}"], $connection));
        $b = stockReturnRaceStart(array_merge(['--mode=race', "--plan={$planFile}", '--arm=b', "--start={$start}"], $connection));

        $resultA = stockReturnRaceFinish($a);
        $resultB = stockReturnRaceFinish($b);

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
            $orderId = $plan['orders'][$round];

            $row = $race->query(
                "SELECT stock, stock_status FROM products WHERE id = {$productId}"
            )->fetch(PDO::FETCH_ASSOC);

            $released = (int) $race->query(
                "SELECT COUNT(*) FROM order_stock_claims WHERE order_id = {$orderId} AND released_at IS NOT NULL"
            )->fetchColumn();

            $returned = [
                $resultA['results'][$round]['returned'] ?? null,
                $resultB['results'][$round]['returned'] ?? null,
            ];

            $context = "round {$round}: stock={$row['stock']} status={$row['stock_status']} "
                . "released_rows={$released} returned=" . json_encode($returned) . ' '
                . 'errors=' . json_encode([
                    $resultA['results'][$round]['error'] ?? null,
                    $resultB['results'][$round]['error'] ?? null,
                ]);

            /*
             * Four ways of saying the same thing: exactly one arm was told it
             * returned a unit, the shelf holds the one unit that actually came
             * back rather than two, the claim row is released once, and the
             * product the claim delisted is back in the shop.
             */
            expect(array_filter($returned))->toHaveCount(1, $context);
            expect((int) $row['stock'])->toBe(1, $context);
            expect($released)->toBe(1, $context);
            expect((string) $row['stock_status'])->toBe('instock', $context);
        }
    } finally {
        $server->exec("DROP DATABASE IF EXISTS `{$raceDatabase}`");
    }
})->skipOnWindows();
