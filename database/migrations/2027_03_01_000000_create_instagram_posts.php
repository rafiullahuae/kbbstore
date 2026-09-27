<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instagram Profile — the one table, and what every column is for.
 *
 * Phase 21, Lane IG. docs/IG-PROFILE.md is the research; this is the storage it
 * concluded on.
 *
 * ── WHY A TABLE AND NOT A CACHE ENTRY ───────────────────────────────────────
 *
 * The obvious cheap answer is to put the fetched feed in the cache and be done.
 * It is the wrong answer for three reasons, all of them about the shop not going
 * blank:
 *
 *   1. A cache is allowed to disappear. `php artisan cache:clear` on the live box
 *      — which the Cache screen has a button for — would empty the Instagram
 *      section on the homepage until somebody opened the admin and pressed
 *      Refresh. A homepage section that a maintenance action silently empties is
 *      the failure mode this project keeps finding.
 *   2. Every tile needs a LOCAL FILE, because Meta's `media_url` and
 *      `thumbnail_url` are signed CDN addresses that expire (docs/IG-PROFILE.md
 *      §2). Something durable has to remember which file on our disk belongs to
 *      which post, or the download is repeated on every refresh and the orphans
 *      are never cleaned up.
 *   3. The token expires after 60 days and this host has no cron. When it lapses,
 *      the right behaviour is that the section keeps showing what it showed
 *      yesterday — stale, never empty — and that is only possible if the posts
 *      outlive both the token and the cache.
 *
 * The cache still exists, over the top of this table, in App\Services\InstagramFeed.
 * It is a read cache, not the store.
 *
 * ── EVERY COUNT IS NULLABLE WITH NO DEFAULT ─────────────────────────────────
 *
 * docs/UGC-ENGAGEMENT.md paid for this rule and its wording is exact: "NULL is
 * *we do not know* and 0 is *the source said zero*. A default of 0 makes those the
 * same value, and the shop then prints '0 likes' under a reel with fourteen
 * thousand of them the first time a token expires."
 *
 * So `like_count` and `comments_count` are nullable with NO default, they are
 * compared with `=== null` and never with a falsy test, and the template draws no
 * element at all when they are null — the same way the rating bar draws nothing
 * for a product with no reviews. A post genuinely posted an hour ago with zero
 * comments prints "0", because that is a fact.
 *
 * ── NOTHING ON THE STOREFRONT MOVES WHEN THIS RUNS ──────────────────────────
 *
 * An empty table, and the module ships OFF. InstagramShipsOffTest walks the shop
 * with a full table and a published profile and asserts every page is byte-for-byte
 * what it was with an empty one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('instagram_posts')) {
            return;
        }

        Schema::create('instagram_posts', function (Blueprint $t) {
            $t->id();

            /*
             * Instagram's own id for the media. UNIQUE, because the sync is an
             * upsert on it: a refresh must update the post it already has rather
             * than adding a second copy of it, and the like count moving is the
             * whole reason to re-fetch.
             *
             * A string and not an integer. The ids are numeric today and are
             * documented as opaque; storing an opaque identifier in a bigint is
             * the bet that costs a table alter the day it stops being true.
             */
            $t->string('remote_id', 64)->unique();

            /*
             * IMAGE | VIDEO | CAROUSEL_ALBUM, as Meta spells them. Kept as the
             * remote string rather than mapped to our own vocabulary, so that a
             * type we have never seen is stored honestly and the reader decides
             * what to do with it. InstagramPost::TYPES is the allowlist and
             * anything outside it is treated as an image, which is the safe
             * reading: it draws a still and no play button.
             */
            $t->string('media_type', 32)->default('IMAGE');

            /*
             * The post's own URL on instagram.com. This is the ONLY link the
             * storefront ever offers, and it is re-checked through
             * UgcPath::link() on the way out — a column is only as trustworthy
             * as everything that has ever written to it.
             */
            $t->string('permalink', 255)->nullable();

            /*
             * The shortcode out of the permalink — the `DEF456` in
             * instagram.com/p/DEF456/. Stored separately and validated to
             * [A-Za-z0-9_-] so the in-page embed's iframe src can be REBUILT
             * from characters we have checked rather than assembled out of a
             * remote string. Rule 5: anything that becomes an attribute is
             * validated at the boundary, not at the place that prints it.
             */
            $t->string('shortcode', 64)->nullable();

            // The caption, as posted. Remote user input; printed through {{ }}
            // and truncated for display, never used in an attribute.
            $t->text('caption')->nullable();

            /*
             * OUR OWN COPY of the thumbnail, as a path under uploads/instagram/.
             * Null means the download failed or has not happened, and a post with
             * a null path IS NOT RENDERED — the rule UgcRail applies to a clip
             * with no poster, for the same reason: a tile with no poster is a hole
             * in the page at first paint.
             */
            $t->string('local_path', 255)->nullable();

            /*
             * The real pixel size of that local file, from getimagesize() at
             * download time. This is what makes width/height honest on the <img>
             * and layout shift zero without a line of JavaScript measuring
             * anything — rule 4 forbids the element-measuring APIs by name.
             */
            $t->unsignedSmallInteger('width')->nullable();
            $t->unsignedSmallInteger('height')->nullable();

            // See the docblock. Nullable, NO default, compared with === null.
            $t->unsignedInteger('like_count')->nullable();
            $t->unsignedInteger('comments_count')->nullable();

            // When Instagram says it was posted. The sort key, because the
            // owner asked for "recent posts" and our own row order is the order
            // a paginated fetch happened to return them in.
            $t->timestamp('posted_at')->nullable();

            /*
             * When we last saw this post in a fetch. Not decoration: it is how
             * the sync knows which local files belong to posts that have been
             * deleted on Instagram, so they can be pruned instead of accumulating
             * on a shared plan's disk forever.
             */
            $t->timestamp('seen_at')->nullable();

            $t->timestamps();

            // The storefront's only query: ordered, limited. One index, covering
            // exactly that. `posted_at` descending is the order; the id breaks a
            // tie so a LIMIT over two posts in the same second is not the
            // database's choice to make.
            $t->index(['posted_at', 'id'], 'instagram_posts_recent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_posts');
    }
};
