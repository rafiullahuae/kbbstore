<?php

declare(strict_types=1);

/**
 * Two shoppers, one remaining use, at the same instant.
 *
 * This is the failure a transaction alone does not prevent, and the reason
 * CouponService::recordRedemption() re-reads the coupon under
 * SELECT ... FOR UPDATE. Both transactions read usage_count = 0 against a
 * usage_limit of 1, both decide there is room, and both write: the code is
 * redeemed twice and the shop gives away a discount it had capped.
 *
 * WHAT THIS TEST DOES, and why it is shaped so awkwardly.
 *
 * It runs two real OS processes, each opening a real transaction that writes a
 * real order and then redeems the coupon — the same two writes, in the same
 * order, inside one transaction, as both production placement paths — against
 * the same coupons, synchronised on a wall clock. A single PHP process cannot
 * express this: it can only be inside one transaction at a time, so any
 * "concurrency" test written in one process is really a test that the code
 * calls lockForUpdate(), which is the structural assertion this is supposed to
 * replace. Asserting the lock exists would pass even if the lock were taken on
 * the wrong row, after the check, or in a transaction that had already
 * committed.
 *
 * WHAT IT DELIBERATELY DOES NOT DO is drive ManualOrderBuilder::create(), which
 * would have been the fuller test. Concurrent order placement is broken for a
 * reason that has nothing to do with coupons: order_number is allocated by
 * reading MAX(order_number) inside the placing transaction, and under MySQL's
 * REPEATABLE READ that read is served from the transaction's own snapshot. The
 * loser of the allocation race therefore retries eight times, is handed the
 * same stale maximum every time, and fails the placement outright with a
 * duplicate-key error on orders.order_number. The first version of this test
 * used the builder and hit exactly that. Both placement paths have the flaw —
 * Store\CheckoutController::nextOrderNumber() does not retry at all — and
 * neither is this lane's to repair, so the arms here are given order numbers
 * nothing else can pick. The coupon row is then the only contended resource,
 * which is what makes a failure here unambiguous.
 *
 * IT IS MYSQL-ONLY, and skips otherwise. SQLite serialises writers with one
 * database-wide write lock, so the two arms cannot overlap and the test would
 * pass with the FOR UPDATE clause deleted — a green light that means nothing.
 * Production runs MySQL; this is proven there.
 *
 * It uses its own database, created and dropped here. The workers COMMIT, and
 * the suite's own database is inside RefreshDatabase's per-test transaction —
 * committed rows from another process would be invisible to the assertions and
 * would outlive the test for every test after it.
 */

use Illuminate\Support\Facades\DB;

/** Run one worker to completion and return its decoded JSON line. */
function raceWorker(array $arguments): array
{
    $process = raceWorkerStart($arguments);

    return raceWorkerFinish($process);
}

/** @return array{0: resource, 1: array<int, resource>} */
function raceWorkerStart(array $arguments): array
{
    $command = array_merge([PHP_BINARY, base_path('tests/Support/coupon-race-worker.php')], $arguments);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $process = proc_open($command, $descriptors, $pipes, base_path());

    if (! is_resource($process)) {
        throw new RuntimeException('could not start the race worker');
    }

    return [$process, $pipes];
}

function raceWorkerFinish(array $handle): array
{
    [$process, $pipes] = $handle;

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    /*
     * The worker's result is the LAST JSON line, not the whole of stdout.
     *
     * Migrations in this set print as they go — every clear_caches_* migration
     * echoes "Cleared N compiled files." and the web-root cleanup prints a
     * sentence of its own — so a migrate run hands back several screens of
     * prose with the result at the end of it. Parsing the whole buffer fails on
     * the first of those lines.
     */
    foreach (array_reverse(preg_split('/\R/', trim($stdout)) ?: []) as $line) {
        $decoded = json_decode(trim($line), true);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    throw new RuntimeException("race worker returned no JSON.\nstdout: {$stdout}\nstderr: {$stderr}");
}

it('lets only one of two simultaneous orders take the last use of a coupon', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped(
            'The coupon race is only observable on MySQL. SQLite takes one database-wide '
            . 'write lock, so the two arms never overlap and the result would not depend on '
            . 'the FOR UPDATE clause being there. Run this with phpunit.lane-an-mysql.xml.'
        );
    }

    $rounds = 10;
    $raceDatabase = DB::connection()->getDatabaseName() . '_race';

    // A connection to the server itself, not to a database, so it can create
    // and drop one.
    $config = config('database.connections.mysql');
    $server = new PDO(
        "mysql:host={$config['host']};port={$config['port']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    /*
     * The two arms need a database of their own -- they run as separate OS
     * processes and cannot share the test transaction. Creating one needs a
     * privilege the test user does not automatically have: on a MySQL where
     * it is missing this raised SQLSTATE 1044 and the whole suite went red for
     * a reason that has nothing to do with coupons.
     *
     * Skipped rather than failed, and skipped LOUDLY -- the message names the
     * exact grant, because a concurrency guard that quietly stops running is
     * worse than one that was never written. The suite still proves the lock
     * is present and the limits hold; what it cannot prove without this is
     * that two real processes contend correctly.
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
            . "database `{$raceDatabase}` that the two competing processes need. Grant it with: "
            . "GRANT ALL PRIVILEGES ON `{$raceDatabase}`.* TO '" . $config['username'] . "'@'%'; "
            . "GRANT CREATE ON *.* TO '" . $config['username'] . "'@'%'; "
            . 'Underlying error: ' . $e->getMessage()
        );
    }

    // Every worker invocation needs the same connection details; the worker
    // forces them into its own environment before booting.
    $connection = [
        "--db={$raceDatabase}",
        "--host={$config['host']}",
        "--port={$config['port']}",
        "--user={$config['username']}",
        '--pass=' . (string) $config['password'],
    ];

    try {
        $migrated = raceWorker(array_merge(['--mode=migrate'], $connection));
        expect($migrated['ok'] ?? false)->toBeTrue('the race database could not be migrated: ' . json_encode($migrated));

        $plan = raceWorker(array_merge(['--mode=seed', "--rounds={$rounds}"], $connection));
        expect($plan['ok'] ?? false)->toBeTrue('seeding failed: ' . json_encode($plan));

        $planFile = tempnam(sys_get_temp_dir(), 'coupon-race-');
        file_put_contents($planFile, json_encode($plan));

        // Far enough ahead that both workers have booted Laravel and are
        // spinning on the barrier before the first round opens.
        $start = microtime(true) + 6.0;

        $a = raceWorkerStart(array_merge(['--mode=race', "--plan={$planFile}", '--arm=a', "--start={$start}"], $connection));
        $b = raceWorkerStart(array_merge(['--mode=race', "--plan={$planFile}", '--arm=b', "--start={$start}"], $connection));

        $resultA = raceWorkerFinish($a);
        $resultB = raceWorkerFinish($b);

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

        $winners = 0;

        foreach ($plan['coupons'] as $round => $couponId) {
            $usage = (int) $race->query(
                "SELECT usage_count FROM coupons WHERE id = {$couponId}"
            )->fetchColumn();

            $redemptions = (int) $race->query(
                "SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = {$couponId}"
            )->fetchColumn();

            $outcomes = [
                $resultA['results'][$round]['ok'] ?? null,
                $resultB['results'][$round]['ok'] ?? null,
            ];

            $context = "round {$round}: usage_count={$usage} redemptions={$redemptions} "
                . 'outcomes=' . json_encode($outcomes) . ' '
                . 'errors=' . json_encode([
                    $resultA['results'][$round]['error'] ?? null,
                    $resultB['results'][$round]['error'] ?? null,
                ]);

            // The whole point, three ways: the counter stopped at the limit,
            // exactly one redemption row exists, and exactly one of the two
            // placements was told yes.
            expect($usage)->toBe(1, $context);
            expect($redemptions)->toBe(1, $context);
            expect(array_filter($outcomes))->toHaveCount(1, $context);

            $winners++;
        }

        expect($winners)->toBe($rounds);
    } finally {
        $server->exec("DROP DATABASE IF EXISTS `{$raceDatabase}`");
    }
})->skipOnWindows();
