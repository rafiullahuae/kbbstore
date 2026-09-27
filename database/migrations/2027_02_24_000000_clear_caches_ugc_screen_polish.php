<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Drop the compiled admin screens after the video-screen repairs.
 *
 * Required, for the usual reason: `storage/framework/views` caches a compiled
 * view by the PATH of its source and decides staleness by comparing file times,
 * and an unzip's timestamps are not reliably newer than what is already there.
 * A shop holding the old compiled copy would go on flashing on every tab
 * switch, from a package that reported success.
 *
 * No route cache clear -- this change adds no route. It writes no setting row
 * and changes nothing the shop renders: every file it touches is admin-only.
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

        echo "Cleared {$cleared} compiled views. The clip editor no longer flashes when you change tab.\n";
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
