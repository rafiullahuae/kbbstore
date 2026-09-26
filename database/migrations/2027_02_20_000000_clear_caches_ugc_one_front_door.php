<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Drop the compiled views after the shoppable-video screens became one row.
 *
 * WHY THIS IS OWED. Three Blade partials changed — the sections, library and
 * appearance screens — and `storage/framework/views` caches a compiled view by
 * the PATH of its source, so a stale copy survives an update that replaced the
 * file. What the owner would see without this is a console that still draws
 * three sidebar rows for one feature, two of which open screens whose tab strip
 * is missing, from a package that reported success.
 *
 * NO ROUTE CACHE CLEAR IS NEEDED and that is deliberate rather than an
 * oversight: this change adds no route. `routes/ugc-admin.php` and
 * `routes/ugc.php` were both wired in earlier packages.
 *
 * It writes no setting row. Nothing about this migration changes what the shop
 * renders — the whole change is which sidebar row opens which admin screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
        ] as $glob) {
            foreach (glob($glob) ?: [] as $file) {
                if (@unlink($file)) {
                    $cleared++;
                }
            }
        }

        echo "Cleared {$cleared} compiled views. Shoppable video is one sidebar row with three tabs now.\n";
    }

    /**
     * Nothing to undo. Clearing a cache is not a state change, and rebuilding
     * the compiled views is what the next request does anyway.
     */
    public function down(): void {}
};
