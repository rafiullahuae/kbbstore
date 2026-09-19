<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the Import / Export screen is in the middle of.
 *
 * NOT A SECOND CHECKPOINT TABLE. `import_checkpoints` remains the only record
 * of how far the import itself got, and it stays that way because its offsets
 * commit inside the same transaction as the rows they describe. Nothing here is
 * ever consulted to decide which row to read next.
 *
 * This table answers a different question — "what was the person doing" — and
 * it exists because the person is using a browser on shared hosting:
 *
 *   - THE OPTIONS. Guest handling, order-number source, adopt-by-slug and the
 *     export's timezone are decisions the owner makes once, on screen, and they
 *     have to still be in force on step 40 after the tab was closed and
 *     reopened. Sending them up with every step instead would mean a reload
 *     silently continuing the run under different rules than it started with.
 *
 *   - WHICH ENTITIES THIS RUN HAS FINISHED. A checkpoint that says `finished_at`
 *     cannot distinguish "this run just completed it" from "a run last Tuesday
 *     completed it", and the difference decides whether the screen moves on or
 *     re-presents the file. Held here, so the driver never has to guess.
 *
 *   - THE COUNTER BASELINE. `created_rows` and friends used NOT to be zeroed
 *     when a finished entity was re-run, so a second pass would show the first
 *     pass's numbers added to its own. The value each counter held when this
 *     run first touched the entity is recorded here and subtracted on the way
 *     out.
 *
 *     THEY ARE ZEROED AT THE SOURCE NOW (App\Services\Import\Checkpoint::open,
 *     Lane GJ), because EntityReport::verification() reads `rejected_rows` to
 *     reach a verdict on a resumed run and a stale refusal in it turns a
 *     shortfall into "verified". So the baseline for a finished entity is zero
 *     and this column is no longer doing that job for it. It is kept, and is
 *     still taken, for a checkpoint written by the code that shipped before
 *     that change -- one of which is in the owner's database right now.
 *
 *   - THE CLAIM. Two tabs stepping at once would both read the same offset, both
 *     import the same rows and both advance the checkpoint — leaving a gap no
 *     count would reveal. `locked_at`/`lock_token` are taken with a conditional
 *     UPDATE so exactly one caller can be working, and the claim ages out on its
 *     own so a request killed mid-step does not wedge the screen.
 *
 * JSON IS STORED IN A TEXT COLUMN, NOT A `json` ONE, deliberately. This repo has
 * already been bitten once by a native JSON column holding a value MySQL then
 * refused (the encrypted gateway credentials, repaired by
 * 2026_09_22_000000_widen_encrypted_config_columns). A text column behaves
 * identically on SQLite and MySQL, needs no dialect-specific query support, and
 * nothing here is ever filtered or indexed by its contents.
 *
 * NO down() THAT DROPS IT. Dropping this mid-import would leave a part-imported
 * database with nothing on screen able to say which half happened — the same
 * reason import_checkpoints has no destructive down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('import_runs')) {
            return;
        }

        Schema::create('import_runs', function (Blueprint $t) {
            $t->id();

            // Matches import_checkpoints.run_key. The screen owns 'admin', so a
            // command-line run against a restored copy cannot resume into it.
            $t->string('run_key', 64)->unique();

            // 'preview' (dry run, rolled back) or 'live'.
            $t->string('mode', 16)->default('preview');

            // running | complete | stopped
            $t->string('status', 16)->default('running');

            $t->text('options')->nullable();
            $t->text('started_entities')->nullable();
            $t->text('done_entities')->nullable();
            $t->text('baselines')->nullable();
            $t->text('notes')->nullable();
            $t->text('report')->nullable();
            $t->text('message')->nullable();

            // How deep the preview has read, in rows per file.
            $t->unsignedInteger('preview_limit')->default(0);

            $t->boolean('restart')->default(false);

            $t->timestamp('locked_at')->nullable();
            $t->string('lock_token', 32)->nullable();

            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void {}
};
