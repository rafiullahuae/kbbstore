<?php

declare(strict_types=1);

use App\Services\ImportConsole\ImportChain;
use App\Services\ImportConsole\ImportDriver;
use App\Services\ImportConsole\ImportWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * The entity's own name, which is what `import_checkpoints.entity` holds and
 * what ImportWorkspace::meta() is keyed by: `menu-items` with a HYPHEN
 * (MenuItemImporter::name()), while the file it reads is menu_items.csv with an
 * underscore. The two spellings are a day lost if they are guessed.
 */
const MENU_ITEMS = 'menu-items';

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  THE RECONCILIATION SENTENCE DESCRIBES THIS EXPORT, NOT EVERY EXPORT EVER
 *  (Lane FIN2)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Store → Store Import / Export → Import progress ends with one sentence:
 *
 *   "WooCommerce said 671 rows across 18 files, 671 arrived, 0 refused, and 22
 *    fields skipped (…). Every row is accounted for."
 *
 * It is, in ImportChain's own words, "the cheapest possible proof of the whole
 * migration". Every number in it is computed over the files that are IN THE
 * WORKSPACE — `$present`, which is `ImportWorkspace::has()`, a file-existence
 * check. Rows, files, arrived, refused: all four.
 *
 * The field list was not. `ImportChain::progress()` appended every entity's
 * stored `dropped_fields` to `$droppedAll` inside a loop over EVERY entity
 * name, present or not, and `reconciliation()` then printed that list beside
 * counts that had been filtered.
 *
 * ── WHY THAT IS NOT THEORETICAL, ON THIS SHOP, ON CUTOVER NIGHT ────────────
 *
 * `ImportDriver::RUN_KEY` is a constant — one run key for the life of the
 * installation — so `import_checkpoints` is not per-import, it is the shop's
 * permanent record, and `Checkpoint::mergeDroppedFields()` UNIONS into it and
 * never subtracts. The runbook's real sequence is a full import, then a delta,
 * then a cutover delta, each from a NEW export, and a delta export is a SMALLER
 * set of files.
 *
 * So: full import of all twenty files, `menu_items` declares `classes` (it has
 * no column for a CSS class — see MenuItemImporter). Cutover night, the owner
 * uploads a products-only delta. The sentence read:
 *
 *   "WooCommerce said N rows across 1 file, … and 22 fields skipped (…,
 *    classes, …)"
 *
 * — naming a field of a file that is not in this export, from an import that
 * finished weeks ago, in the one sentence he is being asked to trust.
 *
 * It never under-reports, so nothing was ever lost because of it. It
 * over-reports, in a sentence whose entire job is to be exact, and it puts
 * `across 1 file` and a twenty-two-name list in the same breath, which reads as
 * though one file shed twenty-two fields.
 *
 * ── HOW THIS IS BUILT, AND WHY DELETING A FILE IS THE HONEST WAY ───────────
 *
 * A full export is imported to completion — really imported, by the driver, so
 * the checkpoint rows are the ones the shop writes rather than rows this test
 * invented. Then one CSV is removed from the workspace, which is precisely what
 * a delta export IS to this screen: `has()` is a file check and nothing else.
 * The checkpoint row survives, because that is the whole point of it.
 *
 * MUTATION: put the `$droppedAll` append back outside the `if ($present)` in
 * ImportChain::progress() and the first case is red, naming `classes`.
 *
 * ── WHAT THIS DOES NOT FIX, AND WHO OWNS IT ───────────────────────────────
 *
 * The other half of the same staleness is in `Checkpoint::open()`, which zeroes
 * `processed` and the four counters when an entity is restarted or re-run after
 * finishing, and does NOT clear `dropped_fields` with them. So a field that the
 * PREVIOUS export of a file carried is still named after a new export of that
 * same file stops carrying it. That is `app/Services/Import/**`, which is Lane
 * A's, and it is reported rather than touched — see this lane's report.
 */

/**
 * The shipped WooCommerce fixture, put into the import workspace exactly as an
 * upload leaves it.
 *
 * Deliberately NOT the helpers in ImportProductParityTest: those are global
 * functions in another file, so a run of THIS file alone would not have them,
 * and copying them here would redeclare them the moment the whole suite loads
 * both. The fixture is copied whole and untouched, so its manifest still
 * describes its own bytes — ImportDriver::denominator() refuses to trust a
 * manifest row count whose sha256 does not match, and answers null rather than
 * guessing, which would leave the reconciliation unready and this file
 * asserting nothing.
 */
function fin2Workspace(): ImportWorkspace
{
    $workspace = new ImportWorkspace;
    $directory = $workspace->directory();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    foreach (glob(base_path('tests/Fixtures/kbb-export').'/*') ?: [] as $file) {
        copy($file, $directory.'/'.basename($file));
    }

    return $workspace;
}

/**
 * Step the driver until the run says it is over.
 *
 * step() has no "done" key — the run's own status is the authority, and a loop
 * written against a key that is not there exits after one step and then asserts
 * against a progress page that has imported one file.
 */
function fin2RunToCompletion(ImportDriver $driver, int $cap = 200): void
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

it('names only the skipped fields of files that are in this export', function () {
    /*
     * `classes` is declared by MenuItemImporter for menu_items.csv row 7503,
     * which carries `menu-sale,menu-highlight`. It is the one field in the
     * fixture that belongs to a file other than products.csv, which is what
     * makes it the right needle: after the delete below it is the only name
     * that can only have come from a checkpoint rather than from the export.
     */
    $driver = new ImportDriver;
    fin2Workspace();

    fin2RunToCompletion($driver);

    $before = (new ImportChain)->progress()['reconciliation'];

    // If this is red the fixture has stopped declaring `classes` and the case
    // below is testing nothing — re-point it rather than deleting it.
    expect($before['fields'])->toContain('classes');

    // The delta: the same shop, a smaller export. Nothing else changes, and in
    // particular the checkpoint row menu_items wrote stays exactly where it is.
    $workspace = new ImportWorkspace;
    $file = $workspace->directory().'/'.ImportWorkspace::meta(MENU_ITEMS)['file'];

    expect(is_file($file))->toBeTrue('the fixture has no menu-items file to remove');

    unlink($file);

    expect($workspace->has(MENU_ITEMS))->toBeFalse();

    // The checkpoint survives the file, which is the whole premise: it is the
    // shop's permanent record and ImportDriver::RUN_KEY never changes.
    $stored = (string) DB::table('import_checkpoints')
        ->where('run_key', ImportDriver::RUN_KEY)
        ->where('entity', MENU_ITEMS)
        ->value('dropped_fields');

    expect($stored)->toContain('classes');

    $after = (new ImportChain)->progress()['reconciliation'];

    expect($after['fields'])->not->toContain('classes');

    // And the sentence the owner reads agrees with the list, rather than the
    // list being cleaned while the count printed beside it is not.
    expect($after['sentence'])->not->toContain('classes');
    expect($after['sentence'])->toContain(count($after['fields']).' fields skipped');
});

it('still names every skipped field of every file that IS in this export', function () {
    /*
     * The other direction, and the one that matters more: a filter written one
     * character wrong empties the list, and an empty list reads as "nothing was
     * lost" — the single most expensive sentence this screen can print.
     *
     * MUTATION: change the guard to `if (! $present)` and this is red on
     * `weight`, which is products.csv's own and is in the export throughout.
     */
    $driver = new ImportDriver;
    fin2Workspace();

    fin2RunToCompletion($driver);

    $reconciliation = (new ImportChain)->progress()['reconciliation'];

    expect($reconciliation['fields'])->toContain('weight')
        ->and($reconciliation['fields'])->toContain('classes')
        ->and($reconciliation['ready'])->toBeTrue();

    // The per-file column on the page is unfiltered by design — it is drawn
    // against the file's own row and says what that file did, whether or not
    // the file is still in the workspace. Only the one SENTENCE is scoped.
    $entities = (new ImportChain)->progress()['entities'];
    $menuItems = collect($entities)->firstWhere('entity', MENU_ITEMS);

    expect($menuItems)->not->toBeNull()
        ->and($menuItems['dropped_fields'])->toContain('classes');
});
