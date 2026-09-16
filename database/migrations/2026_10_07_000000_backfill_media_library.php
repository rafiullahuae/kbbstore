<?php

declare(strict_types=1);

use App\Support\MediaBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue the images already on disk, so the Media Library is not empty on a
 * store that has been uploading for months.
 *
 * WHAT THE STATE WAS. `media` has existed since the original schema — it was
 * built for the WooCommerce import, which is why it carries
 * `source_attachment_id` — and NOTHING in this application has ever written a
 * row to it. `Media::` had zero call sites in the tree. Meanwhile
 * /admin-api/media/upload, the one upload endpoint, has been writing files into
 * public/uploads/ for the product gallery, brand logos, category images and the
 * SEO share image the whole time, recording none of them.
 *
 * So the file system is the only record of what this store has uploaded. From
 * this version the endpoint records each new upload itself; this migration is
 * the one-time catch-up for everything before it. Without it the owner opens a
 * brand-new Media Library and sees nothing, on a store full of images — which
 * would look exactly like the screen being broken.
 *
 * NO SCHEMA CHANGE. Nothing is created, altered or positioned with an AFTER
 * clause — the thing that made nine earlier migrations in this repo silent
 * no-ops on MySQL. It is data only, and it is additive only: MediaBackfill
 * never deletes a row, so a file that has not finished copying onto the server
 * cannot cost anybody a record.
 *
 * SAFE TO RUN TWICE. It matches on `path` and inserts only what is missing,
 * which matters on a host where updates are zips applied by hand and the same
 * package does sometimes get applied twice.
 *
 * The Rescan button on the Media Library runs the same code, so an operator who
 * adds files by FTP after this has run does not need another migration.
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
            echo "Catalogued {$added} existing uploads into the media library.\n";
        }
    }

    /**
     * Deliberately empty.
     *
     * Rolling back must not delete media rows. By the time anybody rolls back,
     * the library may hold alt text and rows created by later uploads, and
     * there is no way to tell those from the ones this added — `created_at` is
     * the file's discovery time, not a marker. Leaving accurate rows in place
     * costs nothing; removing them loses work.
     */
    public function down(): void {}
};
