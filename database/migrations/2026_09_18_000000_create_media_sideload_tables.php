<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sideloader's ledger — Lane GD.
 *
 * =============================================================================
 * WHY THERE IS A TABLE AT ALL, WHEN THE FILE ON DISK IS THE REAL CHECKPOINT
 * =============================================================================
 *
 * "Has this picture been fetched?" is answered by `is_file(public_path($path))`
 * and by nothing else. That is deliberate and it is the whole resume story:
 * the disk cannot lie, it survives a SIGKILL mid-request, and it is true even
 * when the file arrived by FTP instead of over HTTP. A row that said "fetched"
 * beside an empty web root would be exactly the half-successful fetch
 * `MediaAudit`'s header warned about.
 *
 * So this table records the ONE thing the disk cannot: why a file is NOT there.
 *
 *   `media_sideload_items`  one row per source URL that was attempted, with the
 *                           outcome and, when it failed, the reason in words
 *                           the owner can act on. Without it a URL that 404s on
 *                           the old host is retried on every batch forever and
 *                           "remaining" never reaches zero — the run would look
 *                           stuck while behaving exactly as designed.
 *
 *   `media_sideload_runs`   one row per run, with a heartbeat. This is what
 *                           lets the live page tell "idle, 412 still to fetch"
 *                           from "running, 118 of 530" from "the last batch
 *                           died three minutes ago" — three different states
 *                           that a bare progress number renders identically.
 *
 * Both are derived, disposable state. Dropping either loses no photograph: the
 * work list is re-derived from the catalogue on every request and every file
 * already on disk is skipped. `down()` therefore really does drop them.
 *
 * URL_HASH, NOT A UNIQUE INDEX ON THE URL ITSELF. A WordPress attachment URL
 * with a year folder and a percent-escaped filename runs well past the 191/3072
 * byte key limits MySQL applies under utf8mb4, and an index that is silently
 * prefix-truncated is an index that collapses two different photographs into
 * one row. sha256 of the raw URL is fixed width and collision-free in practice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_sideload_items')) {
            Schema::create('media_sideload_items', function (Blueprint $t): void {
                $t->id();

                // sha256 of the URL exactly as the catalogue spells it.
                $t->char('url_hash', 64)->unique();
                $t->text('url');
                $t->string('host', 253)->nullable();

                // The path under the WEB ROOT this file belongs at, relative to
                // the uploads root, so MediaRewrite re-points rows at it
                // unchanged. Null when the URL could not be turned into a safe
                // path at all, which is itself a refusal.
                $t->string('target_path', 500)->nullable();

                // pending | fetched | failed | refused. See MediaSideloader.
                $t->string('state', 16)->index();

                // Why, in words. Populated for failed and refused; the reason a
                // person with no shell and no log access has to read.
                $t->text('reason')->nullable();

                $t->unsignedBigInteger('bytes')->default(0);
                $t->unsignedInteger('attempts')->default(0);
                $t->unsignedSmallInteger('status_code')->nullable();
                $t->string('content_type', 191)->nullable();

                $t->timestamp('attempted_at')->nullable();
                $t->timestamp('fetched_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('media_sideload_runs')) {
            Schema::create('media_sideload_runs', function (Blueprint $t): void {
                $t->id();

                $t->timestamp('started_at')->nullable();

                /*
                 * Written at the end of every batch. The live page compares it
                 * with now(): a run whose heartbeat is older than
                 * MediaSideloader::STALE_SECONDS is reported as STALLED, not as
                 * running, because a request that was killed by the host's
                 * execution limit leaves no other trace.
                 */
                $t->timestamp('heartbeat_at')->nullable()->index();
                $t->timestamp('finished_at')->nullable();

                $t->unsignedInteger('batches')->default(0);
                $t->unsignedInteger('fetched')->default(0);
                $t->unsignedInteger('failed')->default(0);
                $t->unsignedBigInteger('bytes')->default(0);

                // Set when a run stops for a reason that is not "nothing left":
                // no disk, byte ceiling reached, the operator pressed stop.
                $t->string('stopped_reason', 500)->nullable();

                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_sideload_items');
        Schema::dropIfExists('media_sideload_runs');
    }
};
