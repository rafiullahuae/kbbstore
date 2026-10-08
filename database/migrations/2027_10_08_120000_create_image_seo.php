<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Catalog → Image SEO (Lane IR).
 *
 * image_renames — THE LEDGER. One row per file this module moved: the path it
 * had and the path it has now. Three things read it:
 *   - App\Support\ImageRenameRedirect, on a 404 only, to answer the old URL
 *     (and every old img-cache copy of it) with a 301 to the new one. One
 *     indexed lookup on old_path; a normal page never reaches it.
 *   - Undo, which renames a batch back from its own rows.
 *   - The Find tab's "renamed" state.
 * Chains are collapsed when written (A→B then B→C leaves A→C and B→C), so the
 * redirect is always a single hop. Paths are web-root-relative, the shape
 * media.path already uses ("uploads/products/x.jpg").
 *
 * image_seo_jobs — one row per Start (rename, alt or undo). `token` is unique
 * and comes from the screen, so pressing Start twice returns the same job
 * instead of making a second one; `position` is the cursor the bounded step
 * advances, which is what makes a stopped job resumable and a step that runs
 * twice harmless.
 *
 * media.seo_score / media.seo_renamed_at — the Media Library's score and green
 * tick, stored when this module scores or renames an image so a tile never
 * recomputes anything.
 *
 * NO `AFTER` CLAUSES and every step guarded by hasTable/hasColumn, so a package
 * applied twice, or onto a database a half-run left behind, is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('image_renames')) {
            Schema::create('image_renames', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('job_id')->nullable()->index();
                $t->unsignedBigInteger('product_id')->nullable()->index();
                $t->unsignedBigInteger('media_id')->nullable();
                // main | gallery | variant | sibling | undo | repoint
                $t->string('role', 16)->default('main');
                $t->string('old_path')->index();
                $t->string('new_path')->index();
                // done | undone | superseded
                $t->string('status', 16)->default('done')->index();
                $t->unsignedInteger('refs')->default(0);
                $t->unsignedInteger('files')->default(0);
                $t->unsignedBigInteger('admin_id')->nullable();
                $t->string('admin_name', 120)->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('image_seo_jobs')) {
            Schema::create('image_seo_jobs', function (Blueprint $t) {
                $t->id();
                $t->string('token', 64)->unique();
                // rename | alt | undo
                $t->string('kind', 16);
                // running | stopped | done
                $t->string('status', 16)->default('running')->index();
                $t->unsignedBigInteger('undo_of')->nullable();
                $t->longText('items');
                $t->text('options')->nullable();
                $t->unsignedInteger('position')->default(0);
                $t->unsignedInteger('total')->default(0);
                $t->unsignedInteger('renamed')->default(0);
                $t->unsignedInteger('skipped')->default(0);
                $t->unsignedInteger('failed')->default(0);
                $t->longText('log')->nullable();
                $t->unsignedBigInteger('admin_id')->nullable();
                $t->string('admin_name', 120)->nullable();
                $t->timestamps();
            });
        }

        if (Schema::hasTable('media')) {
            Schema::table('media', function (Blueprint $t) {
                if (! Schema::hasColumn('media', 'seo_score')) {
                    $t->unsignedTinyInteger('seo_score')->nullable();
                }

                if (! Schema::hasColumn('media', 'seo_renamed_at')) {
                    $t->timestamp('seo_renamed_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('image_renames');
        Schema::dropIfExists('image_seo_jobs');

        if (Schema::hasTable('media')) {
            foreach (['seo_score', 'seo_renamed_at'] as $column) {
                if (Schema::hasColumn('media', $column)) {
                    Schema::table('media', fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }
};
