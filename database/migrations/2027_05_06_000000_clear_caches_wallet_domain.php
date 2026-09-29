<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Clear the compiled caches for the wallet round (Lane WAL).
 *
 * The convention this repository ships every route-adding package with, and the
 * reason is in CLAUDE.md: a route added in routes/*.php does not exist until the
 * compiled route cache is thrown away, so a package that adds one and does not
 * clear it lands a 404 on the very address it just built.
 *
 * Three caches and not one:
 *
 *   - route, for /.well-known/apple-developer-merchantid-domain-association and
 *     the express-wallet amount endpoint in routes/wallet-checkout.php;
 *   - view, because the checkout's payment step and three payment-mark rows
 *     changed and a compiled Blade from before the package would go on drawing
 *     the dead Apple Pay buttons this round removed;
 *   - config, which is free here and is the one that bites when a package also
 *     moves a setting.
 *
 * Each call is wrapped on its own. A host with no cached routes answers
 * `route:clear` perfectly happily, but a read-only bootstrap/cache directory
 * throws — and an update that fails on a cache clear would roll back a package
 * whose files are already correct. Nothing here writes to the database, so
 * there is no state to leave dirty; see the swallowed-exception landmine in
 * CLAUDE.md for why that distinction is the one that matters.
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
