<?php

declare(strict_types=1);

/**
 * Two shoppers pressing Place order at the same instant, and both getting one.
 *
 * `orders.order_number` is NOT NULL UNIQUE. Both placement paths used to mint
 * it by reading MAX() *inside* the placing transaction and retrying against
 * the unique index when the insert was refused — a thousand times on the
 * storefront, eight times in the back office.
 *
 * The retry was the trap, and it is the reason a transaction alone does not
 * prevent this. Under MySQL's default REPEATABLE READ every consistent read in
 * a transaction is served from the snapshot taken at its first read, so each
 * retry re-read the SAME frozen maximum, computed the SAME candidate, and was
 * refused again. The loser did not get a different number; it ran out of
 * attempts and failed the placement outright, with SQLSTATE 23000, at the last
 * step of a customer's checkout.
 *
 * MEASURED BEFORE THE FIX, with this file's own workers: eight rounds, eight
 * duplicate-key failures, eight lost orders — one of the two placements died
 * in every single round.
 *
 * WHAT THIS TEST DOES, and why it is shaped like CouponRaceTest. It runs two
 * real OS processes, each placing a real order against the same database,
 * synchronised on a wall clock. A single PHP process cannot express this: it
 * can only be inside one transaction at a time, so any "concurrency" test
 * written in one process is really a test that the code calls some particular
 * method — the structural assertion this is meant to replace. Asserting that
 * an allocator exists, or that it is called outside a transaction, would pass
 * just as happily if it allocated the same number twice.
 *
 * BOTH PATHS ARE RACED, because the defect was in both:
 *
 *   manual    ManualOrderBuilder::create(), end to end — draft cart, pricing,
 *             coupons, insert. The back office.
 *   checkout  Store\CheckoutController's own allocation, reached by reflection
 *             so this races the shop's code rather than a copy of it, arranged
 *             in the order place() arranges it: allocate, THEN open the
 *             transaction. That order is the fix, so the test is worthless if
 *             it does not reproduce it.
 *
 * THE FIXTURE PINS THE TWO RULES THE ALLOCATOR MUST NOT BREAK. An imported
 * WooCommerce order sits at 50001 and a SOFT-DELETED order at 50002, and the
 * sequence is seeded after both exist, exactly as the live migration is. A
 * trashed order still holds its number in the unique index: the checkout's old
 * allocator read through the default scope, could not see it, and handed 50002
 * out for ever — every checkout in the shop failing on the same number with no
 * concurrency involved at all. Both arms numbering from 50003 is that fix.
 *
 * IT IS MYSQL-ONLY, and skips otherwise. SQLite serialises writers with one
 * database-wide write lock, so the two arms cannot overlap and the test would
 * pass with the fix reverted — a green light that means nothing. Production
 * runs MySQL; this is proven there.
 *
 * It uses its own database, created and dropped here, for the reason
 * CouponRaceTest gives: the workers COMMIT, and the suite's own database is
 * inside RefreshDatabase's per-test transaction.
 */

use Illuminate\Support\Facades\DB;

/** @return array{0: resource, 1: array<int, resource>} */
function orderRaceStart(array $arguments): array
{
    $command = array_merge([PHP_BINARY, base_path('tests/Support/order-number-race-worker.php')], $arguments);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $process = proc_open($command, $descriptors, $pipes, base_path());

    if (! is_resource($process)) {
        throw new RuntimeException('could not start the order-number race worker');
    }

    return [$process, $pipes];
}

function orderRaceFinish(array $handle): array
{
    [$process, $pipes] = $handle;

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    /*
     * The result is the LAST JSON line, not the whole of stdout: migrations in
     * this set print as they go — every clear_caches_* migration echoes
     * "Cleared N compiled files." and the sequence migration announces the
     * number it seeded — so a migrate run hands back several screens of prose
     * with the result at the end of it.
     */
    foreach (array_reverse(preg_split('/\R/', trim($stdout)) ?: []) as $line) {
        $decoded = json_decode(trim($line), true);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    throw new RuntimeException("order-number race worker returned no JSON.\nstdout: {$stdout}\nstderr: {$stderr}");
}

function orderRaceWorker(array $arguments): array
{
    return orderRaceFinish(orderRaceStart($arguments));
}

/**
 * @param  string  $path  'manual' or 'checkout'
 */
function runOrderNumberRace(string $path, int $rounds): void
{
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped(
            'The order-number race is only observable on MySQL. SQLite takes one '
            . 'database-wide write lock, so the two arms never overlap and the result '
            . 'would not depend on the fix being there. Run this with phpunit-mysql.xml.'
        );
    }

    $raceDatabase = DB::connection()->getDatabaseName() . '_ordrace';

    $config = config('database.connections.mysql');
    $server = new PDO(
        "mysql:host={$config['host']};port={$config['port']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    /*
     * Skipped rather than failed, and skipped LOUDLY, for CouponRaceTest's
     * reason: a concurrency guard that quietly stops running is worse than one
     * that was never written, so the message names the exact grant.
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

    $connection = [
        "--db={$raceDatabase}",
        "--host={$config['host']}",
        "--port={$config['port']}",
        "--user={$config['username']}",
        '--pass=' . (string) $config['password'],
    ];

    try {
        $migrated = orderRaceWorker(array_merge(['--mode=migrate'], $connection));
        expect($migrated['ok'] ?? false)->toBeTrue('the race database could not be migrated: ' . json_encode($migrated));

        $plan = orderRaceWorker(array_merge(['--mode=seed', "--rounds={$rounds}"], $connection));
        expect($plan['ok'] ?? false)->toBeTrue('seeding failed: ' . json_encode($plan));

        $planFile = tempnam(sys_get_temp_dir(), 'order-number-race-');
        file_put_contents($planFile, json_encode($plan));

        // Far enough ahead that both workers have booted Laravel and are
        // spinning on the barrier before the first round opens. Booting is not
        // instant and the two arms do not boot equally fast; an arm that
        // arrives late runs every round back to back and never overlaps the
        // other, which would look exactly like a pass. ready_offset below is
        // asserted so that cannot happen quietly.
        $start = microtime(true) + 12.0;

        $a = orderRaceStart(array_merge(
            ['--mode=race', "--plan={$planFile}", '--arm=a', "--start={$start}", "--path={$path}"],
            $connection
        ));
        $b = orderRaceStart(array_merge(
            ['--mode=race', "--plan={$planFile}", '--arm=b', "--start={$start}", "--path={$path}"],
            $connection
        ));

        $resultA = orderRaceFinish($a);
        $resultB = orderRaceFinish($b);

        @unlink($planFile);

        expect($resultA['ok'] ?? false)->toBeTrue('arm A failed: ' . json_encode($resultA));
        expect($resultB['ok'] ?? false)->toBeTrue('arm B failed: ' . json_encode($resultB));

        // Both arms must have been waiting when the barrier opened. A positive
        // offset means that arm booted late, ran unopposed, and proved nothing.
        expect($resultA['ready_offset'])->toBeLessThan(0.0,
            'arm A reached the barrier after it opened, so the rounds were not a race');
        expect($resultB['ready_offset'])->toBeLessThan(0.0,
            'arm B reached the barrier after it opened, so the rounds were not a race');

        $race = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$raceDatabase}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $overlapped = 0;
        $blocker = (string) $plan['blocker'];

        foreach ($resultA['results'] as $round => $outcomeA) {
            $outcomeB = $resultB['results'][$round];

            $context = "round {$round} ({$path}): "
                . 'A=' . json_encode($outcomeA) . ' B=' . json_encode($outcomeB);

            // The whole point: BOTH placements succeeded. Before the fix one of
            // them died here with a duplicate key, every round.
            expect($outcomeA['ok'])->toBeTrue($context);
            expect($outcomeB['ok'])->toBeTrue($context);
            expect($outcomeA['order_number'])->not->toBe($outcomeB['order_number'], $context);

            // Imported and trashed numbers are respected, never reissued.
            expect((int) $outcomeA['order_number'])->toBeGreaterThan(50002, $context);
            expect((int) $outcomeB['order_number'])->toBeGreaterThan(50002, $context);

            // And neither arm was handed the number the soft-deleted blocker
            // sits on. This is the assertion that fails if the allocator stops
            // looking through the default scope's back.
            expect($outcomeA['order_number'])->not->toBe($blocker, $context);
            expect($outcomeB['order_number'])->not->toBe($blocker, $context);

            $overlap = min($outcomeA['ended'], $outcomeB['ended'])
                - max($outcomeA['began'], $outcomeB['began']);

            if ($overlap > 0) {
                $overlapped++;
            }
        }

        /*
         * The arms have to have been inside the placement together for at
         * least most of the rounds, or this measured nothing. Not every round:
         * one arm finishing a few hundred microseconds before the other enters
         * is normal scheduling noise, and demanding all of them would make the
         * guard flaky in exactly the way that gets a concurrency test deleted.
         */
        expect($overlapped)->toBeGreaterThanOrEqual((int) ceil($rounds * 0.6),
            "the two arms only overlapped in {$overlapped} of {$rounds} rounds, which is not a race");

        // Read the outcome from the database rather than from what the workers
        // believe happened.
        $duplicates = (int) $race->query(
            'SELECT COUNT(*) FROM (SELECT order_number FROM orders '
            . 'GROUP BY order_number HAVING COUNT(*) > 1) x'
        )->fetchColumn();

        expect($duplicates)->toBe(0, 'the same order number was written to two rows');

        /*
         * Two arms, one order each, every round, plus the three fixture rows:
         * the imported order, the trashed one above it, and the trashed
         * blocker sitting on the sequence's first number.
         */
        $orders = (int) $race->query('SELECT COUNT(*) FROM orders')->fetchColumn();

        expect($orders)->toBe($rounds * 2 + 3,
            "expected {$rounds} orders per arm plus the three fixture rows, found {$orders}");
    } finally {
        $server->exec("DROP DATABASE IF EXISTS `{$raceDatabase}`");
    }
}

it('lets two simultaneous back-office placements both succeed', function () {
    runOrderNumberRace('manual', 8);
})->skipOnWindows();

it('lets two simultaneous checkouts both succeed', function () {
    runOrderNumberRace('checkout', 8);
})->skipOnWindows();

/**
 * The compare-and-swap, which the placement races above cannot see.
 *
 * `WHERE next_number = <what we read>` guards the window between reading the
 * sequence and advancing it. In a placement race that window is a couple of
 * statements inside a round that also builds a cart, prices it and writes an
 * order, so two arms almost never land in it together: deleting the guard
 * outright left FORTY rounds of the checkout race green. A guard a test cannot
 * see removed is a guard that test is not checking, so it is checked here
 * instead.
 *
 * Several processes doing nothing but allocating spend all of their time in
 * exactly that window. Measured on this repo with the guard deleted: 96 of 300
 * numbers were handed out twice. With it, 2,400 allocations across eight
 * processes were all distinct.
 *
 * The assertion is the allocator's entire purpose, stated directly: no number
 * is ever given to two callers.
 */
it('never hands the same number to two allocators under contention', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped(
            'Allocator contention is only observable on MySQL; SQLite serialises '
            . 'writers, so the processes cannot overlap.'
        );
    }

    $processes = 4;
    $perProcess = 150;

    $raceDatabase = DB::connection()->getDatabaseName() . '_ordrace';
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

        test()->markTestSkipped(
            'THIS CONCURRENCY GUARD DID NOT RUN. The test user cannot create the scratch '
            . "database `{$raceDatabase}`. Grant it with: GRANT ALL PRIVILEGES ON "
            . "`{$raceDatabase}`.* TO '" . $config['username'] . "'@'%'; "
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
        $migrated = orderRaceWorker(array_merge(['--mode=migrate'], $connection));
        expect($migrated['ok'] ?? false)->toBeTrue('migrate failed: ' . json_encode($migrated));

        $seeded = orderRaceWorker(array_merge(['--mode=seed', '--rounds=1'], $connection));
        expect($seeded['ok'] ?? false)->toBeTrue('seed failed: ' . json_encode($seeded));

        $start = microtime(true) + 12.0;

        $handles = [];

        for ($i = 0; $i < $processes; $i++) {
            $handles[] = orderRaceStart(array_merge(
                ['--mode=allocate', "--count={$perProcess}", "--start={$start}"],
                $connection
            ));
        }

        $allocated = [];

        foreach ($handles as $index => $handle) {
            $result = orderRaceFinish($handle);

            expect($result['ok'] ?? false)->toBeTrue(
                "allocator {$index} failed: " . json_encode($result));

            expect($result['ready_offset'])->toBeLessThan(0.0,
                "allocator {$index} reached the barrier late, so it did not contend");

            $allocated = array_merge($allocated, $result['numbers']);
        }

        $expected = $processes * $perProcess;

        expect($allocated)->toHaveCount($expected);

        $duplicates = array_keys(array_filter(
            array_count_values($allocated),
            fn (int $times): bool => $times > 1
        ));

        expect($duplicates)->toBe([],
            'these numbers were handed to more than one allocator: '
            . implode(', ', array_slice($duplicates, 0, 20)));
    } finally {
        $server->exec("DROP DATABASE IF EXISTS `{$raceDatabase}`");
    }
})->skipOnWindows();
