<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember what the operator called the file.
 *
 * THE DEFECT THIS CLOSES, found by driving the finished screen in a browser
 * rather than by reading it.
 *
 * The owner asked for "proper search functinality via name". The upload
 * endpoint does not keep the name: it writes every file as
 * `Ymd-His-<8 random>.ext`, on purpose — two operators uploading `IMG_0042.jpg`
 * must not overwrite each other, and a name the browser supplied must never
 * decide what lands in the public web root. That is right and is not changing.
 *
 * But it means the library knew the file only as `20260916-063940-gFrWgM9v.png`.
 * An operator who uploaded `cosrx-snail-essence.jpg` and later searched for
 * "cosrx" got NOTHING BACK — a search that answers 200 with an empty grid,
 * against a library that has the file. That is the same shape as the landmine
 * in CLAUDE.md: a filter which matches nothing looks exactly like a filter
 * which works, on a store where the operator cannot be sure what is there.
 *
 * So the stored name stays generated and the ORIGINAL name is recorded beside
 * it: searched, and shown as the tile's title with the stored name underneath.
 *
 * NULLABLE, and null is expected. Every row that predates this — everything the
 * backfill catalogued off disk — has no original name to recover, and the
 * screen falls back to the stored filename for those rather than showing a
 * blank tile.
 *
 * NO `AFTER` CLAUSE. Positioning a column with AFTER is what made nine earlier
 * migrations in this repository silent no-ops on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media') || Schema::hasColumn('media', 'original_name')) {
            return;
        }

        Schema::table('media', function (Blueprint $table) {
            $table->string('original_name')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('media') || ! Schema::hasColumn('media', 'original_name')) {
            return;
        }

        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('original_name');
        });
    }
};
