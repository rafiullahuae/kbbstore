<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the importer records how far it got.
 *
 * WHY A TABLE AND NOT A FILE. The host is shared hosting with no shell access,
 * so the import runs from a PHP process the owner cannot watch and cannot
 * signal. It will be killed: by max_execution_time, by the process manager, by
 * the browser tab that started it being closed. The question is not whether the
 * run is interrupted but what is true afterwards.
 *
 * The answer has to be a single fact, written by the same engine and in the
 * same transaction as the rows it describes. A progress file on disk cannot be:
 * it is written after the commit, so a crash in the gap between the two leaves
 * "500 rows done" recorded against 1,000 rows actually written, or the reverse,
 * and nobody can tell which. Here, each batch's writes and the advance of
 * `processed` commit together or not at all, so the checkpoint is never a
 * guess. That is the entire design, and everything else in this table is
 * bookkeeping.
 *
 * THE FINGERPRINT IS THE OTHER HALF. `processed = 18000` only means anything
 * relative to a specific file: rows are resumed by position, so a re-export
 * with six new orders at the top makes every offset point at the wrong row and
 * the importer would skip 18,000 rows that are no longer the 18,000 it already
 * did. `source_fingerprint` is a sha256 of the input; a resume against a
 * different file is refused and the owner is told to pass --restart, which
 * starts the entity again from row one. Starting over is cheap — every write is
 * an updateOrCreate on an external id, so a redone row is an unchanged row —
 * and being wrong about it is not.
 *
 * NOT unsignedBigInteger for the counts: `integer` is 2.1 billion rows, which
 * is several orders of magnitude past the largest WooCommerce store that has
 * ever existed, and matching the width the rest of this schema uses matters
 * more than the headroom.
 *
 * NO down() that drops the table with data in it, for the same reason the
 * external-id migration has none: this table is the only record of which half
 * of a part-finished import actually happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('import_checkpoints')) {
            return;
        }

        Schema::create('import_checkpoints', function (Blueprint $t) {
            $t->id();

            // Lets a trial import of one entity live alongside the real run
            // without either one resuming into the other's progress.
            $t->string('run_key', 64)->default('default');
            $t->string('entity', 64);

            // sha256 of the source, hex, 64 characters.
            $t->string('source_fingerprint', 64)->nullable();
            $t->string('source_label', 255)->nullable();

            // Source rows consumed and COMMITTED. The resume offset.
            $t->unsignedInteger('processed')->default(0);

            $t->unsignedInteger('created_rows')->default(0);
            $t->unsignedInteger('updated_rows')->default(0);
            $t->unsignedInteger('unchanged_rows')->default(0);
            $t->unsignedInteger('rejected_rows')->default(0);

            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();

            // One checkpoint per entity per run, and the database enforces it:
            // two rows here would mean two different answers to "how far did we
            // get", which is the one question this table exists to answer.
            // Named explicitly and kept short — MySQL caps an identifier at 64
            // characters and Laravel's generated names concatenate the table
            // and every column.
            $t->unique(['run_key', 'entity'], 'import_checkpoints_run_entity_unique');
        });
    }

    public function down(): void {}
};
