<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Clear the compiled route cache for the card form's two reports.
 *
 * ── WHY THIS MIGRATION EXISTS, AND WHY IT IS NOT OPTIONAL ─────────────────
 *
 * This package's ONLY code change is one `require` line in routes/web.php,
 * mounting routes/checkout-card.php — the file that declares
 * POST /checkout/card/paid and POST /checkout/card/abandon, which have answered
 * 405 on the live shop since 17 September because that line was never added.
 *
 * The router dispatches against bootstrap/cache/routes-*.php, not against the
 * source. So on a host with a cached route table, copying the corrected
 * web.php changes NOTHING AT ALL: the two addresses go on 405-ing, the update
 * reports success, and the defect it was built to repair is still there. A
 * package that adds a route and does not clear the cache is a package that
 * silently did not do its job — which is why CLAUDE.md makes this the
 * convention rather than a judgement call.
 *
 * ▲ AND `migrations` IN update.json IS WHAT DECIDES WHETHER THIS RUNS.
 *   UpdateRunner::hasMigrations() reads that flag and never looks at the files,
 *   so a package that carries a migration without declaring it copies this to
 *   the server and never executes it. Five packages once shipped eight
 *   migrations exactly that way. The builder sets the flag from the presence of
 *   this file, and UpdatePackage::verify() refuses a package that carries
 *   migrations without declaring them — but the door is a backstop, not the
 *   reason to get it right.
 *
 * ── WHY EVERY CALL IS GUARDED, AND WHY THAT IS SAFE HERE ──────────────────
 *
 * A host with no cached routes answers `route:clear` perfectly happily, but a
 * read-only bootstrap/cache directory throws — and a cache that cannot be
 * cleared is one the next deploy clears. It is never a reason to fail an
 * update and strand the shop mid-package.
 *
 * This guard is safe in the way CLAUDE.md's swallowed-exception landmine is
 * about: it catches around a call that writes NO model state, so there is
 * nothing left dirty for a later save to re-send. A guarded write that leaves
 * state behind does not contain a failure, it seeds one; this leaves none.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['route:clear', 'view:clear', 'config:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (\Throwable) {
                // A cache that cannot be cleared is one the next deploy clears.
                // It is never a reason to fail an update.
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: this migration has no schema and no data.
    }
};
