<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cards themselves — Phase 22, Lane BN.
 *
 * ── WHY A TABLE AND NOT A JSON COLUMN ON `banner_sets` ──────────────────────
 *
 * A card carries an IMAGE, and an image on this shop is a row in the Media
 * Library: `App\Support\MediaRegistrar` is the one door into it and
 * `media_usages` is the index that answers "what is using this file". A picture
 * buried in a JSON blob is a file the library cannot see, which is the exact
 * complaint the owner made about the video clips before Lane MB.
 *
 * It also carries ordering and its own publish state, both of which a JSON
 * column makes the application's problem rather than the database's.
 *
 * ── WIDTH AND HEIGHT ARE STORED, AND THAT IS AN LCP DECISION ────────────────
 *
 * The first card's image is the largest thing on the homepage above the fold
 * once this section is on, so it ships with explicit `width`/`height`,
 * `fetchpriority="high"` and no `loading="lazy"`. The attributes have to come
 * from somewhere, and reading the file header at RENDER time would put a
 * `getimagesize()` — a disk read — inside the homepage. So they are read ONCE,
 * on save, out of the Media row the registrar has already made, and stored
 * here. A card whose dimensions are unknown stores NULL and the template omits
 * both attributes rather than printing a guess: a wrong width is worse than no
 * width, because the browser reserves the wrong box and the page shifts anyway.
 *
 * ── EVERY TEXT COLUMN IS OPTIONAL ───────────────────────────────────────────
 *
 * The owner writes one or two lines under a picture and sometimes neither. A
 * card with no body must not leave a gap and a card with no button must not
 * leave a hole, which is a layout problem the partial solves inside a
 * fixed-height band — but it starts here, with columns that are allowed to be
 * empty rather than a schema that pretends they are always filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('banner_cards')) {
            return;
        }

        Schema::create('banner_cards', function (Blueprint $t) {
            $t->id();

            /*
             * CASCADE. A card belongs to exactly one set and means nothing
             * without it; the alternative is an orphan row the admin cannot
             * reach and the homepage can never draw.
             */
            $t->foreignId('banner_set_id')->constrained('banner_sets')->cascadeOnDelete();

            /*
             * A root-relative stored path — `uploads/banners/x.webp` — exactly
             * as MediaUploadController writes and Media::urlFor() reads. Never
             * a URL: a URL in this column is a picture the Media Library cannot
             * account for and a scheme this shop has not checked.
             */
            $t->string('image', 400)->default('');

            $t->string('alt', 255)->default('');
            $t->string('heading', 190)->default('');
            $t->string('body', 255)->default('');
            $t->string('button_label', 80)->default('');
            $t->string('button_url', 400)->default('');

            /* NULL is "we do not know", never 0. See the header. */
            $t->unsignedInteger('image_w')->nullable();
            $t->unsignedInteger('image_h')->nullable();

            $t->unsignedInteger('position')->default(0);
            $t->string('status', 16)->default('publish');

            $t->timestamps();

            /*
             * The one index the storefront reads through: the homepage asks for
             * one set's published cards in position order and nothing else.
             */
            $t->index(['banner_set_id', 'status', 'position'], 'banner_cards_set_status_pos');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_cards');
    }
};
