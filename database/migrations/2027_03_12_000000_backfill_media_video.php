<?php

declare(strict_types=1);

use App\Support\MediaBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue the shoppable-video files already on disk.
 *
 * ── WHAT THE STATE WAS, AND HOW MUCH OF IT WAS MISSING ──────────────────────
 *
 * Two places in the tree have ever written a `media` row: the image upload
 * endpoint and MediaBackfill. Services\UgcMedia — the only writer of files under
 * public/uploads/ugc/ — wrote none, so every clip, teaser and poster the
 * shoppable-video module has ever taken was invisible to the Media Library.
 *
 * NOT ALL OF IT, and the difference is worth stating because it is why this
 * migration adds fewer rows than it sounds like it should. MediaBackfill's walk
 * of public/uploads is RECURSIVE and uploads/ugc/ is under it, so the POSTERS
 * (.jpg/.png/.webp) have been catalogued by every Rescan since that class was
 * written. What was missing is the clips and the teasers, because the extension
 * table it filtered on was images-only. That table now lives in
 * App\Support\MediaRegistrar and carries mp4 and webm, so the same walk finds
 * them and this migration is simply that walk, run once.
 *
 * NO SCHEMA CHANGE. Nothing is created, altered or positioned with an AFTER
 * clause. It is data only and additive only: MediaBackfill never deletes a row,
 * so a file that has not finished copying onto the server cannot cost anybody a
 * record.
 *
 * SAFE TO RUN TWICE, which matters on a host where updates are zips applied by
 * hand and the same package does sometimes get applied twice. MediaBackfill
 * matches on `path` and inserts only what is missing, and it re-reads the rows it
 * inserted to record their usage — `Media::query()->insert()` is a query-builder
 * bulk insert and fires no `created` event, so the Media hook in
 * MediaUsageWriter would otherwise never see them and every catalogued file would
 * read as "unused". That is the dangerous direction and it is closed there.
 *
 * IT ALSO PICKS UP EVERY IMAGE THE LIBRARY WAS STILL MISSING, because it is the
 * same one walk. That is a side effect of running it, not a second behaviour:
 * an operator pressing Rescan on the Media Library has always done exactly this.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Defensive rather than decorative: the table is created by
        // 0001_01_01_000000_create_kbb_schema, but a partial or repaired
        // database is a real state on this host, and a backfill is not worth
        // failing a deploy over.
        if (! Schema::hasTable('media')) {
            return;
        }

        $added = MediaBackfill::run();

        if (app()->runningInConsole()) {
            echo "Catalogued {$added} file(s) into the media library, including every shoppable-video\n"
                ."clip and teaser, which nothing had ever recorded. From this version each new upload\n"
                ."records itself, and Content → Media Library shows videos as well as pictures.\n";
        }
    }

    /**
     * Deliberately empty.
     *
     * Rolling back must not delete media rows. By the time anybody rolls back,
     * the library may hold alt text and rows created by later uploads, and
     * there is no way to tell those from the ones this added — `created_at` is
     * the file's discovery time, not a marker. Leaving accurate rows in place
     * costs nothing; removing them loses work, and the files themselves are
     * still on disk and still being served either way.
     */
    public function down(): void {}
};
