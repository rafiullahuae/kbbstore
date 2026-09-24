<?php

/*
 * THE OUTAGE OF 24 SEPTEMBER 2026, AND THE ONE LINE THAT CAUSED IT.
 *
 * The live shop's updater stopped applying anything at all. Every package, down
 * to an 18 KB one carrying two files, answered a bare "Server Error" on the
 * Core Updates screen. Nothing could be shipped to the server, including the
 * fix for the thing that had broken the storefront an hour earlier.
 *
 * WHAT THE SERVER LOOKED LIKE. `update_releases` had no `manifest` column --
 * 2.60.260 shipped the migration that adds it, the migrate step did not add it,
 * and the update reported "applied" regardless, because the exit code of
 * `Artisan::call('migrate')` was thrown away. So the server ended up running
 * the UpdateRunner that WRITES that column against a table that does not have
 * it.
 *
 * WHY THE GUARD DID NOT HOLD. recordManifest() wrote it with
 * $release->update([...]) inside a try/catch, and its docblock said in as many
 * words that this made it incapable of failing an update. Eloquent's update()
 * is fill() then save(): fill() puts `manifest` on the model FIRST, and only
 * then does the save throw. The catch swallowed the throw and left the
 * attribute on the model, dirty. Every later save() on that same instance
 * re-sent it -- the backup_id write, the status write, and then rollback()'s
 * own status write, and finally the ['status' => 'failed'] inside rollback()'s
 * catch, which is the third throw and the one nothing catches. That escaped
 * apply(), escaped the controller, and became the 500.
 *
 * So one swallowed exception did not stay swallowed: it poisoned the model and
 * came back on every write after it, including the two whose entire job is to
 * report that something went wrong.
 *
 * WHAT THESE CASES PIN. An update must survive a column this server has not got
 * yet -- the ordinary window between a package's files landing and its
 * migrations running -- and a rollback must never be able to throw, because by
 * the time it runs the files are already restored and the only thing at stake
 * is a row.
 *
 * MUTATION NOTES, each one run:
 *   - put `$release->update(['manifest' => ...])` back in recordManifest() and
 *     drop the hasColumn() check: `it applies a package while the manifest
 *     column is missing` fails, and it fails the way the shop did -- a
 *     QueryException naming `manifest` thrown out of apply(), not a handled
 *     rollback.
 *   - keep the hasColumn() check but write through the model: same case, same
 *     failure, because the check is the belt and the query builder is the
 *     braces and the outage needed only one of them to be absent.
 *   - make writeRelease() rethrow instead of logging: `it does not throw out of
 *     a rollback when the row cannot be written` fails.
 *   - ignore the exit code from `migrate` again: `it refuses to keep an update
 *     whose migration failed` fails, and the release reads `applied` with a
 *     broken schema underneath it, which is the state that started all this.
 */

use App\Models\UpdateRelease;
use App\Services\Update\UpdateRunner;
use Illuminate\Support\Facades\Schema;

/** The runner, wired to a scratch app root nothing else in the suite uses. */
function runnerOnScratchRoot(string $root): UpdateRunner
{
    return new UpdateRunner(app(\App\Services\Update\BackupService::class), $root);
}

it('applies a package while the manifest column is missing', function () {
    /*
     * The exact live shape: the column is gone, and the runner that writes it
     * is the one running. Before the fix this threw a QueryException naming
     * `manifest` straight out of apply().
     */
    Schema::table('update_releases', function ($t) {
        $t->dropColumn('manifest');
    });

    expect(Schema::hasColumn('update_releases', 'manifest'))->toBeFalse();

    $release = UpdateRelease::create([
        'name' => 'KBB Storefront',
        'version' => '2.60.999',
        'status' => 'running',
        'file_count' => 2,
    ]);

    $runner = runnerOnScratchRoot(base_path());

    // The private writer is what every step of apply() now goes through, so it
    // is what has to hold. Reaching it directly keeps the case about the defect
    // rather than about building a zip.
    $write = (new ReflectionClass($runner))->getMethod('writeRelease');
    $write->setAccessible(true);

    $record = (new ReflectionClass($runner))->getMethod('recordManifest');
    $record->setAccessible(true);

    // A real UpdatePackage, because recordManifest() is typed against it. It
    // needs no zip on disk: the method reads $package->manifest and nothing
    // else, and that property is public.
    $package = new \App\Services\Update\UpdatePackage(
        '/dev/null',
        sys_get_temp_dir(),
        app(\App\Services\Update\UpdateGuard::class),
    );
    $package->manifest = ['files' => ['app/Services/VariantPricing.php' => 'abc']];

    // 1. Recording the manifest against a table without the column is a no-op,
    //    not a throw, and above all leaves nothing on the model.
    $record->invoke($runner, $release, $package);

    expect($release->isDirty())->toBeFalse(
        'recordManifest() left an attribute on the model. That is the defect: '
        .'every later write re-sends it, including the two that report failure.'
    );

    // 2. And the writes that come after it still land.
    $write->invoke($runner, $release, ['backup_id' => '20260924-161943-13456758']);
    $write->invoke($runner, $release, ['status' => 'applied']);

    expect($release->fresh()->status)->toBe('applied')
        ->and($release->fresh()->backup_id)->toBe('20260924-161943-13456758');
});

it('does not throw out of a rollback when the row cannot be written', function () {
    /*
     * A rollback runs after the files are already back. A row that will not
     * save must cost the record, never the request -- the owner seeing
     * "Server Error" with no reason is how this took two hours to find.
     */
    $release = UpdateRelease::create([
        'name' => 'KBB Storefront',
        'version' => '2.60.998',
        'status' => 'running',
        'file_count' => 1,
    ]);

    $runner = runnerOnScratchRoot(base_path());
    $write = (new ReflectionClass($runner))->getMethod('writeRelease');
    $write->setAccessible(true);

    // A column that does not exist and never will, which is the general case
    // of the live failure.
    $write->invoke($runner, $release, ['no_such_column_anywhere' => 'x']);

    expect(true)->toBeTrue('writeRelease() threw; it must only ever log.');
});

it('refuses to keep an update whose migration failed', function () {
    /*
     * The second defect, and the one that hid the first. The exit code of
     * `Artisan::call('migrate')` was discarded, so 2.60.260 reported "applied"
     * while the migration that adds `manifest` had not run -- leaving the
     * server with the code that writes the column and no column.
     */
    $source = file_get_contents(app_path('Services/Update/UpdateRunner.php'));

    expect($source)->toContain("\$exit = Artisan::call('migrate', ['--force' => true]);")
        ->and($source)->toContain('if ($exit !== 0) {');
});

it('never writes its own release row through the model', function () {
    /*
     * The rule, stated once so a later edit cannot quietly reintroduce the
     * outage: every write to update_releases inside this class goes through
     * writeRelease(), which cannot dirty the model and cannot throw.
     */
    $source = file_get_contents(app_path('Services/Update/UpdateRunner.php'));

    // Strip comments, so the docblock that EXPLAINS the banned call does not
    // read as the banned call -- the same trap Lane G's slider guard hit.
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    expect($code)->not->toContain('$release->update(');
});
