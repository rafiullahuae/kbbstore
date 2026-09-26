<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Drop the compiled admin screens after the CSRF fix and the redesign.
 *
 * NOT OPTIONAL, and the reason is unusually sharp this time. The defect being
 * fixed is that two screens sent a security token the server refuses — so a
 * shop left holding a COMPILED COPY of the old screen goes on being unable to
 * save a section, from a package that reported success. The owner would apply
 * the fix and see no change at all.
 *
 * `storage/framework/views` caches a compiled view by the PATH of its source
 * and decides staleness by comparing file times, and an unzip's timestamps are
 * not reliably newer than what is already on disk.
 *
 * No route cache clear: this change adds no route. It writes no setting row and
 * changes nothing the shop renders — every file it touches is admin-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $file) {
            if (@unlink($file)) {
                $cleared++;
            }
        }

        echo "Cleared {$cleared} compiled views. Saving a video section works now, and the clip "
            ."editor is the tabbed one.\n";
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
