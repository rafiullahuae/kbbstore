<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Catalog → Products → edit: replace a picture and the old one leaves the
 * server. (Lane RPL)
 *
 * picture_trash — one row per product picture that a SAVE of that product
 * replaced or removed and that nothing else on the shop still used. The file
 * itself moved out of the web root into storage/app/picture-trash/<id>/; this
 * row is what brings it back (Undo) and what purges it after 30 days.
 *
 *   old_path / twin_path   web-root-relative, the shape media.path uses. The
 *                          twin is the kept JPEG beside a converted WebP.
 *   old_url / slot / position / alt
 *                          exactly what the product held, so Undo puts the
 *                          picture back where it was, with its description.
 *   replacement_*          the picture that took the slot. Its address is
 *                          what the old one 301s to (an image_renames row,
 *                          ids in ledger_ids), so Google Images moves across.
 *   media_rows             the Media Library rows, stashed, restored on Undo.
 *   status                 trashed | restored | purged
 *   purge_after            created + 30 days. A purged row is KEPT: with no
 *                          replacement its old address answers 410 Gone.
 *
 * Guarded by hasTable, no AFTER clauses: applying the package twice is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('picture_trash')) {
            return;
        }

        Schema::create('picture_trash', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id')->nullable()->index();
            $t->string('slot', 16)->default('gallery');
            $t->unsignedSmallInteger('position')->default(0);
            $t->string('old_path')->index();
            $t->string('twin_path')->nullable()->index();
            $t->string('old_url', 500);
            $t->string('alt', 250)->nullable();
            $t->string('replacement_path')->nullable();
            $t->string('replacement_url', 500)->nullable();
            $t->text('files');
            $t->text('media_rows')->nullable();
            $t->string('ledger_ids', 120)->nullable();
            $t->string('status', 16)->default('trashed')->index();
            $t->timestamp('purge_after')->nullable()->index();
            $t->unsignedBigInteger('admin_id')->nullable();
            $t->string('admin_name', 120)->nullable();
            $t->string('note', 250)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('picture_trash');
    }
};
