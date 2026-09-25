<?php

declare(strict_types=1);

/*
 * =============================================================================
 * THE WIDTH A COLUMN ACTUALLY HAS
 * =============================================================================
 *
 * THE DEFECT, and it is the reason this file exists: `carts.token` is declared
 * uuid(), which is char(36) on the server. Five CartFooterTest cases wrote
 * Str::random(40) into it. The SQLite suite was green on all five, because
 * SQLite does not enforce VARCHAR or CHAR length at all -- Illuminate's
 * SQLiteGrammar compiles string(), char() AND uuid() to the bare word
 * `varchar`, so the width never reaches the table. The MySQL config answered
 *
 *     SQLSTATE[22001]: String data, right truncated: 1406
 *     Data too long for column 'token' at row 1
 *
 * on every one of them. Nine cases were red on MySQL and green here; five were
 * this.
 *
 * Those five were fixtures, so no shopper lost a basket -- every production
 * write of that column (CartService::create(), ManualOrderBuilder::
 * buildDraftCart(), PageCostDataset) is `(string) Str::uuid()`, exactly 36
 * characters, and a cookie token is only ever read back as a WHERE value. But
 * the suite could not tell that apart from an over-wide write by the shop
 * itself, which is a 500 at checkout on a host nobody can reach.
 *
 * So Tests\Support\ColumnWidths carries the widths SQLite was never told, and
 * tests/Pest.php runs it over every statement every Feature test issues. This
 * file is the two halves that keep it honest:
 *
 *   1. the fingerprint matches a real information_schema, checked on MySQL, in
 *      both directions, so a migration cannot move a width without failing the
 *      MySQL job docs/MYSQL-PARITY.md calls required;
 *   2. the rule fires -- on a statement written by hand, and on a real write to
 *      a real table.
 *
 * ── WHAT IT COSTS, MEASURED ─────────────────────────────────────────────────
 *
 * The full default suite, same machine, back to back:
 *
 *   with the guard      338.80s   5851 passed, 26 skipped, 0 failed
 *   without it          342.64s   (ColumnWidths::watch() commented out; the
 *                                 one failure is case 4 below, which needs it)
 *
 * So the listener is inside the run-to-run noise — the guarded run was the
 * faster of the two. That is what one str_starts_with() per statement buys,
 * and it is why this is installed for the whole suite rather than for a list
 * of endpoints.
 *
 * ── MUTATIONS, EACH ONE APPLIED AND RUN ──────────────────────────────────────
 *
 *   - Put `Str::random(40)` back into cartFooterCart(). Five CartFooterTest
 *     cases go red ON SQLITE, each naming
 *     "carts.token is char(36) and was written 40 characters". RUN: red, five
 *     cases, on the default config. That is the whole claim of this lane.
 *   - Change ColumnWidths::WIDTHS['carts']['token'] from 36 to 40. Case 1 below
 *     goes red on the MySQL config -- "the map says 40, information_schema says
 *     36" -- and the CartFooterTest mutation above stops being caught. RUN: red.
 *   - Delete the `ColumnWidths::watch()` line from tests/Pest.php. Case 4 below
 *     goes red on SQLite: the write is accepted and nothing saw it. RUN: red.
 *   - Delete the `if ($length <= $width) continue;` early return in inspect().
 *     Every case in the suite that writes any string goes red. RUN: red, and
 *     not kept.
 */

use App\Models\Cart;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ColumnWidths;

/* ═══════════ 1. the fingerprint, against the engine that knows ═══════════ */

it('carries the widths a real information_schema reports, column for column', function () {
    /*
     * ENGINE-SPECIFIC ON PURPOSE, and the only case in this file that is.
     *
     * The widths cannot be read back on SQLite -- they were discarded by the
     * grammar, so there is nothing to compare against and no weaker version of
     * this assertion worth making. This is the one place the map can be
     * checked, which is why it is checked HERE and not left to a reviewer:
     * phpunit-mysql.xml is a required CI job, so a migration that moves a
     * width fails the build rather than quietly rotting the guard.
     */
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped('column widths exist only on a server that keeps them; run -c phpunit-mysql.xml');
    }

    $live = [];

    foreach (DB::select(
        'select table_name as t, column_name as c, character_maximum_length as n '
        . 'from information_schema.columns '
        . 'where table_schema = database() and data_type in (?, ?)',
        ['char', 'varchar']
    ) as $row) {
        $live[(string) $row->t][(string) $row->c] = (int) $row->n;
    }

    ksort($live);

    foreach ($live as &$columns) {
        ksort($columns);
    }
    unset($columns);

    $pinned = ColumnWidths::WIDTHS;
    ksort($pinned);

    foreach ($pinned as &$columns) {
        ksort($columns);
    }
    unset($columns);

    /*
     * BOTH DIRECTIONS IN ONE COMPARE. A column added by a migration and left
     * out of the map is an unguarded column; a column in the map that the
     * schema no longer has is a guard aimed at nothing. toBe() on the whole
     * structure catches either, and names the table and column that moved.
     */
    expect($pinned)->toBe($live);
});

/* ═══════════════ 2. the rule, on a statement written by hand ═════════════ */

it('names the column, the width and the length it was given', function () {
    $problems = ColumnWidths::inspect(
        'insert into `carts` (`token`, `currency`) values (?, ?)',
        [str_repeat('a', 40), 'AED']
    );

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('carts.token')
        ->and($problems[0])->toContain('char(36)')
        ->and($problems[0])->toContain('40 characters');

    // The value that fits is not reported, whatever else the statement says.
    expect(ColumnWidths::inspect(
        'insert into `carts` (`token`, `currency`) values (?, ?)',
        [(string) Str::uuid(), 'AED']
    ))->toBe([]);
});

it('follows the bindings across a multi-row insert and an update', function () {
    /*
     * A batch insert repeats its column list once per row, so binding 3 is
     * `token` again and not `currency`. Getting that wrong would report the
     * wrong column -- or worse, the right length against the wrong width.
     */
    $rows = ColumnWidths::inspect(
        'insert into `carts` (`token`, `currency`) values (?, ?), (?, ?)',
        [(string) Str::uuid(), 'AED', str_repeat('b', 40), 'AED']
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toContain('carts.token');

    // And on an UPDATE the SET values come first, the WHERE bindings after.
    $update = ColumnWidths::inspect(
        'update `carts` set `token` = ?, `status` = ? where `id` = ?',
        [str_repeat('c', 40), 'active', 17]
    );

    expect($update)->toHaveCount(1)
        ->and($update[0])->toContain('carts.token');
});

it('says nothing at all when the mapping is a guess', function () {
    /*
     * BAILING IS THE DESIGN. A wrong binding-to-column mapping fails somebody
     * else's test for a reason that is not true, which is worse than a missed
     * check -- see the head of Tests\Support\ColumnWidths. An upsert, an
     * expression in the SET segment, and a table the map has never heard of
     * all produce nothing rather than a guess.
     */
    expect(ColumnWidths::inspect(
        'insert into `carts` (`token`) values (?) on duplicate key update `token` = ?',
        [str_repeat('d', 40), str_repeat('d', 40)]
    ))->toBe([]);

    expect(ColumnWidths::inspect(
        'update `carts` set `token` = concat(`token`, ?) where `id` = ?',
        [str_repeat('e', 40), 1]
    ))->toBe([]);

    expect(ColumnWidths::inspect(
        'insert into `not_a_table_here` (`token`) values (?)',
        [str_repeat('f', 40)]
    ))->toBe([]);

    // A select is not a write, however long the value it compares against.
    expect(ColumnWidths::inspect(
        'select * from `carts` where `token` = ?',
        [str_repeat('g', 400)]
    ))->toBe([]);
});

/* ════════════ 3. and on a real write, through the real listener ══════════ */

it('catches an over-wide write on whichever engine is running', function () {
    /*
     * THE END-TO-END CLAIM, asserted the same way on both engines because the
     * two halves are the same promise: an over-wide write does not get through.
     *
     * On MySQL the server refuses it and the guard never sees the statement --
     * Connection::run() logs AFTER the callback returns, so a statement that
     * throws is never logged. On SQLite the write succeeds silently and the
     * guard is the only thing that notices. Both are a pass; a value stored
     * whole with nobody saying so is the failure.
     */
    $token = str_repeat('h', 40);

    try {
        DB::table('carts')->insert([
            'token' => $token,
            'currency' => 'AED',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::connection()->getDriverName())->not->toBe('mysql');

        expect(ColumnWidths::violations())->toHaveCount(1)
            ->and(ColumnWidths::violations()[0])->toContain('carts.token is char(36) and was written 40 characters');
    } catch (QueryException $e) {
        expect(DB::connection()->getDriverName())->toBe('mysql')
            ->and($e->getMessage())->toContain('Data too long');
    } finally {
        // This case wrote an over-wide value ON PURPOSE. Without this the
        // afterEach in tests/Pest.php would fail it for succeeding.
        ColumnWidths::reset();
    }
});

it('lets the shop\'s own cart token through, which is what 36 is for', function () {
    /*
     * The other side of the same coin, and the reason the fix to
     * CartFooterTest was a UUID rather than a wider column: 36 is not an
     * accident, it is exactly a UUID, and every production writer of this
     * column writes one.
     */
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED']);

    expect(mb_strlen((string) $cart->token))->toBe(36)
        ->and(ColumnWidths::violations())->toBe([])
        ->and(ColumnWidths::WIDTHS['carts']['token'])->toBe(36);
});
