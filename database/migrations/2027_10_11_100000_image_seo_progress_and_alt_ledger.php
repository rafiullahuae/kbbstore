<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Catalog → Image SEO, Lane IS2: the combined "Rename files + ALT text" run,
 * its live progress, and an Undo that cannot lose an alt text.
 *
 * image_seo_jobs.alt_written — ALT texts a run wrote, counted apart from files
 * renamed so the progress line can say both.
 * image_seo_jobs.elapsed_ms  — time actually spent in steps, so "about 2 min
 * left" is measured from work done, not from when the run was created (a run
 * paused overnight is not slow).
 *
 * image_alt_changes — THE ALT LEDGER. One row per product an alt-writing run
 * changed: the alts before and after. Undo restores from here. It used to
 * restore from the run's log, which is capped at 3,000 entries for display;
 * a combined run over the owner's ~2,000 products writes more entries than
 * that, so the earliest products' "before" would have been cut off and their
 * Undo silently skipped.
 *
 * Guarded by hasTable/hasColumn: a package applied twice is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('image_seo_jobs')) {
            Schema::table('image_seo_jobs', function (Blueprint $t) {
                if (! Schema::hasColumn('image_seo_jobs', 'alt_written')) {
                    $t->unsignedInteger('alt_written')->default(0);
                }

                if (! Schema::hasColumn('image_seo_jobs', 'elapsed_ms')) {
                    $t->unsignedBigInteger('elapsed_ms')->default(0);
                }
            });
        }

        if (! Schema::hasTable('image_alt_changes')) {
            Schema::create('image_alt_changes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('job_id')->index();
                $t->unsignedBigInteger('product_id')->index();
                $t->text('before');
                $t->text('after');
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('image_alt_changes');

        if (Schema::hasTable('image_seo_jobs')) {
            foreach (['alt_written', 'elapsed_ms'] as $column) {
                if (Schema::hasColumn('image_seo_jobs', $column)) {
                    Schema::table('image_seo_jobs', fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }
};
