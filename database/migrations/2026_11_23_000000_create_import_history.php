<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was imported, from which export, and when — Lane GF.
 *
 * =============================================================================
 * WHY A THIRD TABLE, WHEN THERE ARE ALREADY TWO
 * =============================================================================
 *
 * `import_checkpoints` answers "where is this entity up to", and it is allowed
 * to be forgotten: the Import screen's own Reset button deletes every row of it
 * so the next run starts at row one. `import_runs` answers "what is the person
 * doing right now", holds exactly ONE row per run key, and is overwritten in
 * place by the next run — `ImportDriver::start()` updates that row rather than
 * inserting a second one.
 *
 * So between the two of them, the moment a second import starts, there is
 * nothing left anywhere that can answer the question the owner actually asks:
 *
 *     "Which export is this shop's data from, when did it come in, and what
 *      did it do?"
 *
 * He has no shell and no log access. If that answer is not in a table it does
 * not exist. `ImportReport` knows all of it and lives for the length of one
 * HTTP request.
 *
 * ONE ROW PER (RUN, ENTITY). Not one per run — "customers finished and orders
 * refused 14 rows" is the granularity the owner reads — and not one per batch,
 * which would be tens of thousands of rows for one import and would make the
 * history page the slowest screen on the shop. The row is REWRITTEN on every
 * step of that entity, so a killed step loses nothing but the few seconds since
 * the last one.
 *
 * =============================================================================
 * THE DUPLICATE GUARD READS THIS TABLE, AND ONLY THE COLUMNS IT SAYS
 * =============================================================================
 *
 * `file_sha256` is the sha256 of the file as it was on disk when it was
 * imported — ImportWorkspace's own fingerprint, the same digest
 * `import_checkpoints.source_fingerprint` carries. That is the match key for
 * "have I imported this file before", and it is matched on BYTES rather than on
 * the export id, because bytes are what gets imported.
 *
 * `export_id` is the manifest's, and it is what the SENTENCE is built from —
 * "this is the export of kbeautybliss.com taken on 18 September, which you
 * imported on the 18th" — and what tells a corrected re-export apart from a
 * duplicate: the same export id and entity carrying a DIFFERENT sha256 is the
 * owner fixing something and re-exporting it, which must never be refused.
 *
 * NULLABLE, all of it, because an export with no manifest must still import and
 * must still be recorded. A history row with no export id says less and is not
 * nothing.
 *
 * =============================================================================
 * JSON IN A TEXT COLUMN
 * =============================================================================
 * The same decision, and the same reason, as `create_import_runs`: this repo
 * has already been bitten by a native JSON column holding a value MySQL then
 * refused. `notes` and `verification` are never filtered or indexed on.
 *
 * NO DESTRUCTIVE down(). Dropping the record of what was imported, during an
 * update, on a host with no shell, would take the only answer to "what is
 * already in" with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_history')) {
            Schema::create('import_history', function (Blueprint $t) {
                $t->id();

                /*
                 * One import, from the moment Start was pressed. Random rather
                 * than the import_runs row id, because that row is reused: its
                 * id is the same for every run this shop ever does, so keying
                 * history on it would collapse every run into one.
                 */
                $t->string('run_uid', 32);

                $t->string('run_key', 64)->default('admin');

                // 'live' or 'preview'. A preview is recorded and is labelled as
                // one: "you looked at this and wrote nothing" is a true and
                // useful line of history, and leaving it out would make the
                // page look like nothing happened that afternoon.
                $t->string('mode', 16)->default('live');

                $t->string('entity', 32);

                $t->string('export_id', 64)->nullable();
                $t->string('export_generated_at', 40)->nullable();
                $t->string('source_site', 255)->nullable();
                $t->text('source')->nullable();

                // The digest of the file that was actually read.
                $t->string('file_sha256', 64)->nullable();
                $t->string('file_name', 64)->nullable();

                // What the manifest said about that file, kept beside what was
                // found, so a disagreement stays readable after the fact.
                $t->unsignedInteger('manifest_rows')->nullable();
                $t->string('manifest_sha256', 64)->nullable();
                $t->unsignedInteger('rows_counted')->nullable();

                $t->unsignedInteger('processed')->default(0);
                $t->unsignedInteger('created_rows')->default(0);
                $t->unsignedInteger('updated_rows')->default(0);
                $t->unsignedInteger('unchanged_rows')->default(0);
                $t->unsignedInteger('rejected_rows')->default(0);
                $t->unsignedInteger('adjusted')->default(0);
                $t->unsignedInteger('discarded')->default(0);

                /*
                 * The adjustments and discards BY KIND, which is the shape the
                 * volume rehearsal's §10 table has and the only shape a total
                 * can be correctly derived from here.
                 *
                 * A bare total cannot be: `reportIgnoredColumns` emits ONE
                 * entry per entity per run() call, and the screen calls run()
                 * once per browser step — so summing the step totals counts
                 * that entry once per step and an import in slices of two
                 * reports more discards than the identical import in one go.
                 * Merged by kind, the restated entry replaces itself and the
                 * per-row kinds add up, which is what ImportDriver::mergeNotes
                 * already does for the verification note for the same reason.
                 */
                $t->text('adjusted_kinds')->nullable();
                $t->text('discarded_kinds')->nullable();

                $t->boolean('finished')->default(false);

                $t->text('notes')->nullable();
                $t->text('verification')->nullable();

                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();

                // The duplicate guard's only query shape.
                $t->index(['export_id', 'entity'], 'import_history_export_entity_idx');
                $t->index(['file_sha256'], 'import_history_sha_idx');
                // One row per entity per run; the step rewrites it in place.
                $t->unique(['run_uid', 'entity'], 'import_history_run_entity_uniq');
            });
        }

        /*
         * TWO COLUMNS ON A TABLE THIS LANE DID NOT CREATE, additively and
         * guarded, because both are facts about the run and belong on the run:
         *
         *   run_uid  — the identity history rows are keyed on. Without it a
         *              second run silently overwrites the first one's rows.
         *   manifest — the manifest as it was when Start was pressed. Read
         *              again on every step instead, a manifest.json replaced
         *              mid-import would rewrite the export id attached to rows
         *              that were imported under the old one.
         */
        if (Schema::hasTable('import_runs') && ! Schema::hasColumn('import_runs', 'run_uid')) {
            Schema::table('import_runs', function (Blueprint $t) {
                $t->string('run_uid', 32)->nullable();
            });
        }

        if (Schema::hasTable('import_runs') && ! Schema::hasColumn('import_runs', 'manifest')) {
            Schema::table('import_runs', function (Blueprint $t) {
                $t->text('manifest')->nullable();
            });
        }
    }

    public function down(): void {}
};
