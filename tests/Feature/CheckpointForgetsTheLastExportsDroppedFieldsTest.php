<?php

declare(strict_types=1);

use App\Services\Import\Checkpoint;
use App\Services\ImportConsole\ImportChain;
use App\Services\ImportConsole\ImportDriver;
use App\Services\ImportConsole\ImportWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A NEW EXPORT'S DROPPED-FIELD LIST IS THIS EXPORT'S. (Lane CX, task 4 — found
 * by Lane FIN2, which fixed the other half of it one level up and handed this
 * one over by name.)
 *
 * ── WHAT THE DEFECT LOOKED LIKE, ON THE SCREEN THE OWNER WATCHES ────────────
 *
 * Store → Store Import / Export → Import progress ends with one sentence, which
 * ImportChain calls "the cheapest possible proof of the whole migration":
 *
 *   "WooCommerce said 671 rows across 18 files, 671 arrived, 0 refused, and 22
 *    fields skipped (…). Every row is accounted for."
 *
 * `Checkpoint::open()` zeroes `processed` and the four counters in the two
 * places that mean "a new pass over a new file starts at row one" — a
 * `--restart`, and re-running an entity that has FINISHED, which is what the
 * runbook's full → delta → cutover sequence does every single time. It did not
 * clear `dropped_fields` with them, and `mergeDroppedFields()` unions and never
 * subtracts.
 *
 * So a field the PREVIOUS export of a file carried went on being named after a
 * new export of that same file stopped carrying it. The owner's whole workflow
 * is to read that list, go and fix the export in WooCommerce, export again and
 * re-import — and the list would still name the field he had just fixed, for
 * the life of the installation, because `ImportDriver::RUN_KEY` is a constant.
 * The count printed beside the names is derived from the list, so the count was
 * wrong too.
 *
 * It over-reports rather than under-reports — the same shape and the same
 * danger as the sentence FIN2 scoped in `ImportChain::progress()`, in the one
 * sentence whose whole job is to be exact.
 *
 * ── THE HALF THAT IS EASY TO BREAK WHILE FIXING IT ──────────────────────────
 *
 * Clearing the column is one line. Clearing it in a place that is NOT a new
 * pass — an ordinary resume, which is every step of a background import — would
 * throw the list away twenty times over a large file and leave the owner with
 * whatever the last batch happened to see. And clearing it on a new pass must
 * not lose a field that is still genuinely missing: the list is re-reported by
 * `advance()` on the new pass's first committed batch. Both are asserted below,
 * and the end-to-end case moves ONE field between exports so that "cleared" and
 * "emptied" cannot be confused.
 *
 * ── MUTATION NOTE (run, not asserted) ───────────────────────────────────────
 *
 * Take `self::clearedDroppedFields() +` off the finished-entity branch of
 * `Checkpoint::open()` and cases 1 and 5 are red on `weight` / `tag_term_ids`.
 * Take it off the restart branch and case 2 is red. Move the call up into the
 * ordinary resume path and case 3 is red, which is the expensive way to be
 * wrong. Make it clear the column unconditionally rather than through
 * `columnExists()` and case 4 is red on a shop whose migration has not landed.
 */
const CX_RUN = 'cx-dropped';

function cxStored(string $entity = 'products', string $run = CX_RUN): ?string
{
    $value = DB::table(Checkpoint::TABLE)
        ->where('run_key', $run)
        ->where('entity', $entity)
        ->value('dropped_fields');

    return $value === null ? null : (string) $value;
}

it('forgets the last export\'s dropped fields when a finished entity is run again', function () {
    Checkpoint::forgetColumnMemo();

    // Pass one: an export whose products carry a weight this shop cannot store,
    // and a brand column it has nowhere to put.
    $first = Checkpoint::open(CX_RUN, 'products', 'sha-export-1', 'products.csv', false);
    $first->advance(10, 10, 0, 0, 0, ['brand', 'weight']);
    $first->finish();

    expect(cxStored())->toBe('brand,weight');

    /*
     * Pass two: a NEW export of the same file, after the owner has gone and
     * added a weight column in WooCommerce. `finished_at` is set, so open()
     * starts this entity from row one — and the list has to start from nothing
     * with it, or it will keep naming `weight` on every import this shop ever
     * runs.
     */
    $second = Checkpoint::open(CX_RUN, 'products', 'sha-export-2', 'products.csv', false);

    expect(cxStored())->toBeNull(
        'the previous export\'s dropped-field names survived a new pass, so the progress page names a field the owner has already fixed'
    );

    // AND THE NAMES THAT ARE STILL TRUE COME STRAIGHT BACK. The list is this
    // pass's account of this file, not a history: advance() re-reports whatever
    // the new export is still missing on its first committed batch.
    $second->advance(10, 0, 10, 0, 0, ['brand']);

    expect(cxStored())->toBe('brand');
});

it('forgets them on a --restart too, which is the other place processed goes back to zero', function () {
    Checkpoint::forgetColumnMemo();

    $first = Checkpoint::open(CX_RUN, 'orders', 'sha-a', 'orders.csv', false);
    $first->advance(5, 5, 0, 0, 0, ['shipping_lines']);

    expect(cxStored('orders'))->toBe('shipping_lines');

    Checkpoint::open(CX_RUN, 'orders', 'sha-b', 'orders.csv', true);

    expect(cxStored('orders'))->toBeNull();
});

it('keeps them across an ordinary resume, which is every step of a background import', function () {
    /*
     * THE HALF THAT MUST NOT MOVE. A background import is many HTTP requests
     * over one file; each one calls open() and each one is the SAME pass. The
     * list is unioned across those batches on purpose — clearing it here would
     * leave the owner with whatever the final slice happened to see, which for a
     * field missing from the first thousand rows and present in the last is
     * nothing at all.
     */
    Checkpoint::forgetColumnMemo();

    $first = Checkpoint::open(CX_RUN, 'reviews', 'sha-same', 'reviews.csv', false);
    $first->advance(400, 400, 0, 0, 0, ['reviewer_avatar']);

    expect(cxStored('reviews'))->toBe('reviewer_avatar');

    // Same file, same fingerprint, not finished: a resume, not a new pass.
    $resumed = Checkpoint::open(CX_RUN, 'reviews', 'sha-same', 'reviews.csv', false);

    expect($resumed->processed)->toBe(400);
    expect(cxStored('reviews'))->toBe('reviewer_avatar');

    // And the second slice's own names are unioned in rather than replacing.
    $resumed->advance(400, 400, 0, 0, 0, ['reviewer_ip']);

    expect(cxStored('reviews'))->toBe('reviewer_avatar,reviewer_ip');
});

it('still opens on a shop whose migration has not been applied', function () {
    /*
     * These packages are applied by hand and an import that dies because a
     * progress-page nicety has no column is a far worse failure than a progress
     * page that cannot show one number — mergeDroppedFields() says so at length,
     * and the reset has to keep the same promise. An UPDATE naming a column that
     * is not there throws, and open() runs before a single row is imported.
     */
    Schema::table(Checkpoint::TABLE, function ($table): void {
        $table->dropColumn('dropped_fields');
    });

    Checkpoint::forgetColumnMemo();

    expect(Schema::hasColumn(Checkpoint::TABLE, 'dropped_fields'))->toBeFalse();

    $first = Checkpoint::open(CX_RUN, 'coupons', 'sha-x', 'coupons.csv', false);
    $first->advance(3, 3, 0, 0, 0, ['usage_limit_per_user']);
    $first->finish();

    // Both branches, on a table with no column: a restart and a finished re-run.
    Checkpoint::open(CX_RUN, 'coupons', 'sha-y', 'coupons.csv', false);
    Checkpoint::open(CX_RUN, 'coupons', 'sha-z', 'coupons.csv', true);

    expect(DB::table(Checkpoint::TABLE)->where('entity', 'coupons')->count())->toBe(1);

    /*
     * PUT IT BACK, and this is not tidiness. RefreshDatabase wraps each case in
     * a transaction, and SQLite commits DDL implicitly — so the drop above is
     * NOT rolled back and the column would be missing for every case after this
     * one in the same process. Measured: the end-to-end case below then ran in
     * 0.26s against a table with no column and passed while proving nothing,
     * which is exactly the vacuous green this repo has been bitten by four
     * times. The memo goes with it, for the reason forgetColumnMemo() gives.
     */
    Schema::table(Checkpoint::TABLE, function ($table): void {
        $table->text('dropped_fields')->nullable();
    });

    Checkpoint::forgetColumnMemo();

    expect(Schema::hasColumn(Checkpoint::TABLE, 'dropped_fields'))->toBeTrue();
});

/* =====================================================================
 | End to end, on the screen the owner actually reads
 ===================================================================== */

/**
 * The shipped WooCommerce fixture in the import workspace, optionally without
 * one file — which is exactly what a partial export IS to this screen.
 *
 * Its own helpers rather than ReconciliationNamesOnlyThisExportTest's, for the
 * reason that file gives about ImportProductParityTest's: Pest loads every test
 * file before it runs any test, so a shared global would be in scope for the
 * whole suite and missing on a `--filter` run of one file.
 */
function cxWorkspace(array $without = []): ImportWorkspace
{
    $workspace = new ImportWorkspace;
    $directory = $workspace->directory();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    foreach (glob(base_path('tests/Fixtures/kbb-export').'/*') ?: [] as $file) {
        if (in_array(basename($file), $without, true)) {
            continue;
        }

        copy($file, $directory.'/'.basename($file));
    }

    return $workspace;
}

/** One file put back, the way a corrected re-export puts it back. */
function cxRestore(string $name): void
{
    copy(
        base_path('tests/Fixtures/kbb-export').'/'.$name,
        (new ImportWorkspace)->directory().'/'.$name,
    );
}

function cxRunToCompletion(ImportDriver $driver, int $cap = 200): void
{
    $driver->start('live', ['adopt_by_slug' => true, 'force' => true]);

    for ($i = 0; $i < $cap; $i++) {
        $run = $driver->run();

        if ($run === null || $run->status !== 'running') {
            return;
        }

        $driver->step(500);
    }

    throw new RuntimeException('the import did not finish within '.$cap.' steps');
}

/** The products row's dropped list, as the progress page prints it. */
function cxProductsDropped(): array
{
    foreach ((new ImportChain)->progress()['entities'] as $row) {
        if ($row['entity'] === 'products') {
            return $row['dropped_fields'];
        }
    }

    return [];
}

it('stops naming a field the new export no longer drops, and keeps naming the ones it does', function () {
    /*
     * ── THE REAL SEQUENCE, WITH ONE FIELD MOVED BETWEEN THE TWO EXPORTS ─────
     *
     * `tag_term_ids` is dropped by ProductImporter only when the export has no
     * tags.csv: the product-to-tag membership normally arrives from the tag side,
     * so without that file the column reaches no table. That makes it the one
     * field in this fixture that can be moved from "dropped" to "carried" by
     * changing the EXPORT rather than the code — which is precisely the owner's
     * workflow, and precisely what this defect could not see.
     *
     * `weight` is dropped by ProductImporter whatever else is in the export
     * (this shop has nowhere to put it), so it is the control: it must survive
     * the clear, or the "fix" would simply be emptying a column the owner reads.
     */
    $driver = new ImportDriver;
    cxWorkspace(without: ['tags.csv']);

    cxRunToCompletion($driver);

    $first = cxProductsDropped();

    // If either of these is red the fixture has moved on and the case below is
    // testing nothing — re-point it rather than deleting it.
    expect($first)->toContain('tag_term_ids');
    expect($first)->toContain('weight');

    // The corrected export: same shop, same files, plus the tags this run was
    // missing. Nothing about the code changes — only the export.
    cxRestore('tags.csv');

    expect((new ImportWorkspace)->has('tags'))->toBeTrue();

    cxRunToCompletion($driver);

    $stored = cxStored('products', ImportDriver::RUN_KEY);

    /*
     * THE COLUMN ITSELF, not only what the screen makes of it. The screen reads
     * this row, so a stale name here is a stale name in front of the owner, and
     * asserting the column is what makes this case fail for the reason it is
     * about rather than for some later filter's reason.
     *
     * And str_contains()->toBeFalse($message) RATHER THAN not->toContain($needle,
     * $message), which is the form ExpectationsThatCannotFailTest asks for and
     * the reason it exists. `toContain()` is VARIADIC: a second argument is a
     * second NEEDLE, not a failure message, so
     * `not->toContain('tag_term_ids', 'some prose')` asserts only that BOTH are
     * absent — and the prose always is, so it can never fail. This case was
     * written that way first and stayed GREEN under the mutation below until the
     * message came off; that is how the trap gets you.
     */
    expect(str_contains((string) $stored, 'tag_term_ids'))->toBeFalse(
        'import_checkpoints still carries a field name from the export before this one'
    );
    expect($stored)->toContain('weight');

    $second = cxProductsDropped();

    expect(in_array('tag_term_ids', $second, true))->toBeFalse(
        'the progress page still names a field this export carries, from an import the owner has already replaced'
    );

    // The control. Clearing the list must not be the same thing as emptying it.
    expect($second)->toContain('weight');
    expect($second)->not->toBe([]);
});
