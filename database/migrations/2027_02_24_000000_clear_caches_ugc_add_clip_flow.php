<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Drop the compiled admin screens after the add-a-clip rebuild.
 *
 * NOT OPTIONAL. `storage/framework/views` caches a compiled view by the PATH of
 * its source and decides staleness by comparing file times — and an unzip's
 * timestamps are not reliably newer than what is already on disk. A shop left
 * holding the compiled copy of the old screen would go on drawing the one long
 * raw form, from a package that reported success, and the owner would apply the
 * fix and see no change at all.
 *
 * What this package changes is Content -> Shoppable video -> All clips:
 *
 *   * adding a clip is five guided steps instead of one column of everything,
 *     and the steps are the publish gate rather than a decoration;
 *   * the video, the cover and the teaser have real drag-and-drop zones, with a
 *     progress bar on the upload;
 *   * the clip, the cover and the 2-3 second loop are PREVIEWED after they are
 *     added, the loop at the tile's real 158px width, with the shop's own
 *     mechanism, and for the length the owner's own `teaser_ms` setting says
 *     rather than a 2500 typed into the screen;
 *   * the screen no longer claims a clip without a separate teaser file shows a
 *     still. It does not: it loops the first seconds of the full clip, and the
 *     badge that used to read "Poster only" now reads "Loops from full video",
 *     which is the wording the Sections tab uses for the same state;
 *   * a clip whose video uploaded but whose cover is missing no longer says
 *     "No video yet" -- it says "No cover yet", because mediaState() answers
 *     MEDIA_NONE for both and only one of them was being reported.
 *
 * No route cache clear: this change adds no route. It writes no setting row and
 * changes nothing the shop renders — every file it touches is admin-only, and
 * no default moved.
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

        echo "Cleared {$cleared} compiled views. Adding a clip is five guided steps now, with "
            ."drag-and-drop, a preview of the video and of the 2-3 second loop, and no more "
            ."\"Poster only\" on clips that loop perfectly well.\n";
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
