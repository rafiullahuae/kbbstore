<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a run that is continuing WITHOUT A BROWSER needs to remember — Lane GO.
 *
 * `import_runs` already answers "what was the person doing". These columns
 * answer the new question: "is anything still doing it, and what is allowed to
 * carry on doing it". Nothing here is ever consulted to decide which ROW to
 * import next — `import_checkpoints` remains the only record of that, for the
 * reason the create migration gives, and a background run resumes from exactly
 * the same offsets a browser-driven one does.
 *
 * ADDITIVE AND COLUMN-BY-COLUMN. A package can land on a server whose
 * `import_runs` was created by any earlier version of this screen, and one
 * `hasColumn` per column means a half-applied schema adds only what is missing
 * rather than failing the whole migration. ImportChain reads every one of them
 * through ImportDriver's `runsCarry()` convention, so the background feature
 * simply reports itself unavailable on a database that has not had this
 * applied, and the browser-driven run keeps working.
 *
 * -----------------------------------------------------------------------------
 * `chain_token` IS THE ONE COLUMN WITH A SECURITY ARGUMENT
 * -----------------------------------------------------------------------------
 * It holds the SHA-256 OF the continuation secret, never the secret. The secret
 * exists in exactly two places and neither is durable: the memory of the
 * process that just issued it, and one request header on the loopback call that
 * process makes. It is never written to a log, never put in a URL and never
 * sent to a browser.
 *
 * It is also SINGLE USE. ImportChain::claim() matches this column and replaces
 * it in ONE conditional UPDATE, so presenting a secret both proves the caller
 * holds it and consumes it. That one statement is the token check, the replay
 * guard and the "only one chain" guard at the same time, which is why it is one
 * statement and not three — see ImportChain's header.
 *
 * 64 characters because that is a hex SHA-256 exactly. A shorter column would
 * silently truncate on MySQL in a non-strict mode and every comparison would
 * then match a prefix.
 *
 * -----------------------------------------------------------------------------
 * WHY `paused` IS NOT `status = 'paused'`
 * -----------------------------------------------------------------------------
 * `status` is what the existing screen, the existing driver and the existing
 * tests all branch on, and `step()` refuses anything that is not 'running'. A
 * fourth status value would mean a paused run could not be stepped from the
 * console either — and Pause is meant to stop the CHAIN, not to take the run
 * away from the person sitting in front of it. So pause is a flag beside the
 * status, the chain honours it, and the browser-driven Continue button behaves
 * exactly as it did before this lane existed.
 *
 * NO down() THAT DROPS THEM. Dropping `chain_token` mid-run would leave a
 * background import with a baton in flight that nothing could then refuse —
 * the one state this design must never reach. Same reasoning as
 * import_checkpoints and import_runs, only sharper.
 */
return new class extends Migration
{
    /** @return array<string, callable(Blueprint): void> */
    private function columns(): array
    {
        return [
            // Is this run meant to keep going with no browser attached?
            'background' => fn (Blueprint $t) => $t->boolean('background')->default(false),

            // Stop the chain without ending the run. See the header.
            'paused' => fn (Blueprint $t) => $t->boolean('paused')->default(false),

            // SHA-256 of the one secret that may continue this run, or NULL for
            // "nothing may". NULL is the resting state and the halt state both.
            'chain_token' => fn (Blueprint $t) => $t->string('chain_token', 64)->nullable(),

            // After this, the baton is dead even if it was never presented. A
            // chain whose process was killed leaves a token nobody holds; this
            // is what stops it being a valid credential for the rest of time.
            'chain_expires_at' => fn (Blueprint $t) => $t->timestamp('chain_expires_at')->nullable(),

            // When the owner last asked for the background run. The wall-clock
            // half of the runaway bound.
            'chain_started_at' => fn (Blueprint $t) => $t->timestamp('chain_started_at')->nullable(),

            // The heartbeat. Written when a slice is claimed AND when it ends,
            // so a slow slice is not mistaken for a dead one.
            'chain_beat_at' => fn (Blueprint $t) => $t->timestamp('chain_beat_at')->nullable(),

            // The counted half of the runaway bound.
            'chain_slices' => fn (Blueprint $t) => $t->unsignedInteger('chain_slices')->default(0),

            // Consecutive slices that achieved nothing. A chain that cannot
            // make progress must stop, not spin.
            'chain_fails' => fn (Blueprint $t) => $t->unsignedInteger('chain_fails')->default(0),

            // Rows for the next slice. The browser adapts this from what it
            // measures; a chain with no browser has to adapt it for itself, so
            // the number lives here rather than in a tab that is closed.
            'chain_rows' => fn (Blueprint $t) => $t->unsignedInteger('chain_rows')->default(0),

            // Why the chain is not running, in the owner's words. The whole
            // point of the STALLED/HALTED distinction is that this is readable
            // on a screen by someone with no shell and no log.
            'chain_note' => fn (Blueprint $t) => $t->text('chain_note')->nullable(),
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('import_runs')) {
            return;
        }

        foreach ($this->columns() as $name => $define) {
            if (Schema::hasColumn('import_runs', $name)) {
                continue;
            }

            Schema::table('import_runs', function (Blueprint $t) use ($define) {
                $define($t);
            });
        }
    }

    public function down(): void {}
};
