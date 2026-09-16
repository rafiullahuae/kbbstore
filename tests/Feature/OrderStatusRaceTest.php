<?php

declare(strict_types=1);

/**
 * Two ways to close one order, at the same instant, in two real processes.
 *
 * THE FAILURE THIS IS ABOUT. Releasing a coupon use is a read and then a write:
 * find the redemption rows this order holds, and give a use back for each. Two
 * callers doing that together both read the same unreleased row, both decide it
 * is theirs to release, and both decrement `coupons.usage_count` — so a
 * one-per-customer code is handed back twice and a shopper gets two goes at a
 * code the owner capped at one. It is exactly the shape of failure that
 * CouponService::recordRedemption() takes a row lock to prevent on the way in,
 * and it is not prevented by a transaction: both transactions read a row that
 * says NULL and both write.
 *
 * WHAT RUNS. Two OS processes, synchronised on a wall clock, doing two
 * different things to the same order per round:
 *
 *   - both call CouponService::releaseRedemptions() on one order. That is the
 *     claim on its own, with no order lock in the picture at all.
 *   - one cancels a second order while the other refunds it. Two different
 *     transitions, so neither is refused as a repeat, and both would release.
 *
 * A single PHP process cannot express either: it can only be inside one
 * transaction at a time, so a "concurrency" test written in one process is
 * really a test that the code calls a conditional UPDATE — which would pass
 * with the condition on the wrong column, or applied after the decision.
 *
 * THE COUNTER IS SEEDED ABOVE THE ROW COUNT, at 5 for one redemption row, which
 * is what a WooCommerce-imported coupon genuinely looks like. It is also what
 * makes a double release visible: the decrement is floored at zero — deliberately,
 * because an unsigned column would reject a negative and a signed one would make
 * the limit read backwards — so at a counter of 1 the second release leaves no
 * trace and at 5 it leaves 3.
 *
 * IT IS MYSQL-ONLY, and skips loudly otherwise. SQLite serialises writers with
 * one database-wide write lock, so the two arms never overlap and the test
 * would pass with the claim deleted. Production runs MySQL; this is proven
 * there.
 *
 * It uses a database of its own, created and dropped here, because the workers
 * COMMIT: rows committed by another process are invisible inside
 * RefreshDatabase's transaction and would outlive the test for every test after
 * it. The name ends _ordrace rather than _race so that running this beside
 * CouponRaceTest does not have the two of them dropping each other's scratch
 * database mid-run.
 */

use Illuminate\Support\Facades\DB;

/*
 * Named apart from OrderNumberRaceTest's helpers on purpose: Pest loads every
 * test file into one process, so two files declaring orderRaceStart() is a
 * fatal redeclare that takes the whole suite with it rather than one test.
 */
function statusRaceWorker(array $arguments): array
{
    return statusRaceFinish(statusRaceStart($arguments));
}

/** @return array{0: resource, 1: array<int, resource>} */
function statusRaceStart(array $arguments): array
{
    $command = array_merge([PHP_BINARY, base_path('tests/Support/order-status-race-worker.php')], $arguments);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $process = proc_open($command, $descriptors, $pipes, base_path());

    if (! is_resource($process)) {
        throw new RuntimeException('could not start the order race worker');
    }

    return [$process, $pipes];
}

function statusRaceFinish(array $handle): array
{
    [$process, $pipes] = $handle;

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    /*
     * The result is the LAST JSON line, not the whole of stdout: every
     * clear_caches_* migration in this set prints as it goes, so a migrate run
     * hands back several screens of prose with the answer at the end of it.
     */
    foreach (array_reverse(preg_split('/\R/', trim($stdout)) ?: []) as $line) {
        $decoded = json_decode(trim($line), true);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    throw new RuntimeException("order race worker returned no JSON.\nstdout: {$stdout}\nstderr: {$stderr}");
}

it('hands one coupon use back when two processes close the same order together', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped(
            'The double-release race is only observable on MySQL. SQLite takes one '
            . 'database-wide write lock, so the two arms never overlap and the result would '
            . 'not depend on the claim being there. Run this with phpunit-mysql.xml.'
        );
    }

    $rounds = 8;
    $raceDatabase = DB::connection()->getDatabaseName() . '_ordrace';

    $config = config('database.connections.mysql');

    $server = new PDO(
        "mysql:host={$config['host']};port={$config['port']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    /*
     * Creating the scratch database needs a privilege the test user does not
     * automatically have. Skipped rather than failed, and skipped LOUDLY with
     * the exact grant — a concurrency guard that quietly stops running is worse
     * than one that was never written.
     */
    try {
        $server->exec("DROP DATABASE IF EXISTS `{$raceDatabase}`");
        $server->exec("CREATE DATABASE `{$raceDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        if (! str_contains($e->getMessage(), '1044') && ! str_contains($e->getMessage(), '1045')) {
            throw $e;
        }

        test()->markTestSkipped(
            'THIS CONCURRENCY GUARD DID NOT RUN. The test user cannot create the scratch '
            . "database `{$raceDatabase}` the two competing processes need. Grant it with: "
            . "CREATE DATABASE `{$raceDatabase}`; "
            . "GRANT ALL PRIVILEGES ON `{$raceDatabase}`.* TO '" . $config['username'] . "'@'%'; "
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
        $migrated = statusRaceWorker(array_merge(['--mode=migrate'], $connection));
        expect($migrated['ok'] ?? false)->toBeTrue('the race database could not be migrated: ' . json_encode($migrated));

        $plan = statusRaceWorker(array_merge(['--mode=seed', "--rounds={$rounds}"], $connection));
        expect($plan['ok'] ?? false)->toBeTrue('seeding failed: ' . json_encode($plan));

        $planFile = tempnam(sys_get_temp_dir(), 'order-status-race-');
        file_put_contents($planFile, json_encode($plan));

        // Far enough ahead that both workers have booted Laravel and are
        // spinning on the barrier before the first round opens.
        $start = microtime(true) + 6.0;

        $a = statusRaceStart(array_merge(['--mode=race', "--plan={$planFile}", '--arm=a', "--start={$start}"], $connection));
        $b = statusRaceStart(array_merge(['--mode=race', "--plan={$planFile}", '--arm=b', "--start={$start}"], $connection));

        $resultA = statusRaceFinish($a);
        $resultB = statusRaceFinish($b);

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

        foreach ($plan['rounds'] as $index => $round) {
            foreach (['release', 'transition'] as $kind) {
                $couponId = (int) $round[$kind]['coupon'];
                $orderId = (int) $round[$kind]['order'];

                $usage = (int) $race->query("SELECT usage_count FROM coupons WHERE id = {$couponId}")->fetchColumn();

                $rows = (int) $race->query(
                    "SELECT COUNT(*) FROM coupon_redemptions WHERE order_id = {$orderId}"
                )->fetchColumn();

                $released = (int) $race->query(
                    "SELECT COUNT(*) FROM coupon_redemptions WHERE order_id = {$orderId} AND released_at IS NOT NULL"
                )->fetchColumn();

                $status = (string) $race->query("SELECT status FROM orders WHERE id = {$orderId}")->fetchColumn();

                $context = "round {$index} ({$kind}): usage_count={$usage} rows={$rows} released={$released} "
                    . "status={$status} arms=" . json_encode([
                        $resultA['results'][$index] ?? null,
                        $resultB['results'][$index] ?? null,
                    ]);

                // Seeded at 5, one of which this order holds. One release, and
                // only one, whichever arm won: 4, never 3.
                expect($usage)->toBe(4, $context);

                // The row is still there — it is the record that the code was
                // accepted on this order — and it is stamped exactly once.
                expect($rows)->toBe(1, $context);
                expect($released)->toBe(1, $context);
            }

            // Both arms moved the second order, so it ended up at whichever of
            // the two transitions committed last. Either is a correct outcome;
            // what would not be is the use coming back twice, asserted above.
            $status = (string) $race->query(
                'SELECT status FROM orders WHERE id = ' . (int) $round['transition']['order']
            )->fetchColumn();

            expect(in_array($status, ['cancelled', 'refunded'], true))
                ->toBeTrue("round {$index}: the contended order ended at {$status}");
        }
    } finally {
        $server->exec("DROP DATABASE IF EXISTS `{$raceDatabase}`");
    }
})->skipOnWindows();
