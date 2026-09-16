<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `media_usages`: which image belongs to which product, brand or category.
 * (Lane BF)
 *
 * WHAT WAS THERE BEFORE. Nothing. The schema records an attachment as a URL
 * STRING on the owning row — products.image, products.images (json),
 * brands.logo, categories.image — and there is no other record of it anywhere.
 * `media.source_attachment_id` is the only id-shaped column on `media` and it
 * points at a WordPress attachment id that means nothing in this database;
 * grep finds no reader for it. App\Support\MediaUsage therefore DERIVES the
 * association on every lookup by walking all three catalogues. This table is
 * the index that walk was always standing in for.
 *
 * IT IS AN INDEX, NOT THE AUTHORITY, and that distinction is the whole design.
 * The delete guard in MediaLibraryApiController still asks MediaUsage::verify()
 * whether a file is in use, because a stale row here would offer to delete an
 * image that is live on a product page — the one error on this screen a
 * shopper sees. The grid's filter and badges read this table, where being a
 * moment behind costs a tile badge and nothing more. MediaUsage's class
 * comment carries the same split from the other side.
 *
 * COLUMNS.
 *
 *  - `media_id` is the row in `media`. Indexed by the unique below, whose
 *    leading column it is.
 *  - `owner_type` is 'product', 'brand' or 'category' — the same three strings
 *    MediaUsage::TYPES offers and the API already returns, not a model class
 *    name. A dump of this table should be readable without a class map.
 *  - `owner_id` is the row in that table.
 *  - `field` is the COLUMN the URL was read from: 'image', 'images' or 'logo'.
 *    Deliberately the column name rather than the label the screen prints
 *    ("Gallery image 3"), which renumbers whenever a gallery is reordered and
 *    would churn every row on a drag.
 *
 * THE UNIQUE IS (media_id, owner_type, owner_id, field), and it is what makes
 * the backfill and the reconcile safely re-runnable — on this host a package
 * gets applied twice by hand often enough that idempotence is a requirement,
 * not a nicety. It also states the grain: this table answers "is this image on
 * that product", a set. It deliberately does NOT record a gallery position, so
 * one media row reachable twice in one gallery through two differently-shaped
 * URLs is one row here while MediaUsage::verify() reports two references. The
 * delete guard reads verify(), so the count the operator is shown before a
 * destructive action is still the derived one.
 *
 * NO FOREIGN KEY, on purpose. `media_id` would cascade nicely, but this repo's
 * house rule is already written down in BrandsApiController::destroy(): the
 * forced path nulls the column explicitly rather than leaning on the FK action
 * "so the same delete then behaves identically on MySQL, on SQLite, and on any
 * connection where the constraint was never created". The Media observer in
 * App\Support\MediaUsageWriter deletes these rows explicitly for the same
 * reason, and an ALTER that can fail against the live host's `media` table is
 * a risk this migration does not need to take.
 *
 * No AFTER clause anywhere — see the standing note in this directory about the
 * nine migrations that were silent no-ops on MySQL because of one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_usages')) {
            return;
        }

        Schema::create('media_usages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('media_id');
            $t->string('owner_type', 16);
            $t->unsignedBigInteger('owner_id');
            $t->string('field', 32);
            $t->timestamps();

            // Named explicitly: the generated name for four columns runs to 54
            // characters, which fits MySQL's 64 today and is one renamed
            // column away from not fitting.
            $t->unique(['media_id', 'owner_type', 'owner_id', 'field'], 'media_usages_unique');

            // "Forget everything this owner used", which is what every save
            // does before recording what it uses now.
            $t->index(['owner_type', 'owner_id'], 'media_usages_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_usages');
    }
};
