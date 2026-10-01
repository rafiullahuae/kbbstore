<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an imported Content -> HTML Block needs to be matched to its source.
 * (Lane PJ-B)
 *
 * The old shop ran the Rey theme, and many product descriptions open with
 * `[rey_global_section id="18159"]` -- a pointer at a separate WordPress post
 * built in Elementor. The new product page printed that pointer as literal
 * text at the top of the Description tab. `content_blocks.csv` carries those
 * posts across, and every column below is read by something:
 *
 *  - `wc_id` is the WordPress post id the shortcode names. UNIQUE, so the
 *    importer upserts on it and a re-import can never write a second copy; and
 *    the storefront resolves `[rey_global_section id=N]` by it. Nullable,
 *    because every block the owner writes himself has no WordPress origin.
 *  - `source` is the shortcode that places it on the old site
 *    (`rey_global_section`), so the admin can show the owner the exact text
 *    his descriptions carry, and a later importer for another builder's
 *    sections does not have to guess which rows are whose.
 *  - `source_hash` is a sha256 of the content the LAST IMPORT wrote. When the
 *    stored content no longer matches it, the owner has edited the block in
 *    Content -> HTML Blocks, and a re-import keeps his edit instead of
 *    overwriting it -- the promise SeoImporter makes about a typed title.
 *
 * No AFTER clause, for the standing reason in this directory: nine migrations
 * were silent no-ops on MySQL because of one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blocks')) {
            return;
        }

        if (! Schema::hasColumn('blocks', 'wc_id')) {
            Schema::table('blocks', function (Blueprint $t) {
                $t->unsignedBigInteger('wc_id')->nullable()->unique();
            });
        }

        if (! Schema::hasColumn('blocks', 'source')) {
            Schema::table('blocks', function (Blueprint $t) {
                $t->string('source', 40)->nullable();
            });
        }

        if (! Schema::hasColumn('blocks', 'source_hash')) {
            Schema::table('blocks', function (Blueprint $t) {
                $t->char('source_hash', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('blocks')) {
            return;
        }

        if (Schema::hasColumn('blocks', 'wc_id')) {
            Schema::table('blocks', function (Blueprint $t) {
                $t->dropUnique(['wc_id']);
            });
            Schema::table('blocks', function (Blueprint $t) {
                $t->dropColumn('wc_id');
            });
        }

        foreach (['source', 'source_hash'] as $column) {
            if (Schema::hasColumn('blocks', $column)) {
                Schema::table('blocks', function (Blueprint $t) use ($column) {
                    $t->dropColumn($column);
                });
            }
        }
    }
};
