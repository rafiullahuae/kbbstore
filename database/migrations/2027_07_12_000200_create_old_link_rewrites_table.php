<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of every old-site link this shop re-pointed, so it can be put
 * back. (Lane PT)
 *
 * The owner: "Internal links in the end of anua foam and other cleansers -- on
 * all articles and products/sets descriptions, internal links are going to
 * still old site." App\Services\Import\OldSiteLinks rewrites each
 * `<a href="https://kbeautybliss.com/...">` inside a description, an article,
 * a content block or a category/brand description to this shop's address for
 * the same thing -- at the end of every import, and from Store -> Store
 * Import / Export -> Addresses & pictures -> Links to the old site.
 *
 * UNLIKE MediaRewrite, THIS NEEDS A LEDGER. A picture re-point drops a scheme
 * and a host and keeps the path, so it is exactly invertible from the row
 * alone. A link re-point is not: `https://kbeautybliss.com/face-cleansers/`
 * becomes `/collections/skincare/face-cleansers/`, and nothing in the new value
 * says what the old one was. So each changed document records its changes
 * here, in document order, and Undo walks them back -- only where the link
 * still says exactly what this wrote, so a link edited by hand since is kept.
 *
 *   batch        one id per run, so the screen can say what the last run did
 *   table/row_id/field   the document
 *   changes      json list of {from, to}, in the order they occur
 *   restored_at  set when Undo has put this document's links back
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('old_link_rewrites')) {
            return;
        }

        Schema::create('old_link_rewrites', function (Blueprint $t) {
            $t->id();
            $t->string('batch', 40)->index();
            $t->string('table', 40);
            $t->unsignedBigInteger('row_id');
            $t->string('field', 40);
            $t->json('changes');
            $t->timestamp('restored_at')->nullable();
            $t->timestamps();

            $t->index(['table', 'row_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('old_link_rewrites');
    }
};
