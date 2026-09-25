<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shoppable UGC video — the storage side. Phase 20, docs/UGC-VIDEO-PLAN.md §4.
 *
 * ── WHAT THIS DOES TO THE SHOP ──────────────────────────────────────────────
 *
 * NOTHING. Two empty tables and no reader anywhere on the storefront. There is
 * no route, no section, no setting and no query on any page that exists today;
 * StorefrontEnglishUnchangedTest cannot move because nothing it renders reads
 * a row from here. The owner picks a rail and a player first (§8 questions 1
 * and 2 are still open), and the round that draws one is the round that adds a
 * page. This is the concrete under it.
 *
 * ── WHY THREE FILE PATHS AND NOT ONE ────────────────────────────────────────
 *
 * Measured in round three (§0b.1), over real HTTP, on a rail of eight tiles:
 *
 *     one full clip, seeking back to 0 every 2.5s   12,786,600 B
 *     the same clip with a #t=0,2.5 media fragment  12,786,600 B
 *     a separate 2.5s teaser file, looped            1,055,160 B
 *
 * A media fragment tells the player where to start and the network nothing at
 * all, so the two cheap-looking answers cost the same as the expensive one:
 * the browser fetched every byte of every file, eight times over. 12.1x, and
 * it is not only shorter — a rail tile is 158 CSS px, so the teaser wants
 * 360x640 at ~400 kbps while the full clip must stay 720x1280 for a
 * full-screen player. One file can never carry both renditions.
 *
 * So `file_path`, `teaser_path` and `poster_path`, with the byte count beside
 * each: the storefront budget in §2 is a byte budget and a column it can read
 * is cheaper than a filesize() per tile.
 *
 * ── THE TEASER IS NULLABLE AND THAT IS A FEATURE ────────────────────────────
 *
 * §8 question 4 — does ffmpeg exist on that Cloudways box — is still
 * unanswered, and nobody here can answer it. So the schema is designed for
 * both worlds: with a transcoder the teaser is cut from the file that was
 * already uploaded, and without one the column stays null and the tile shows
 * its poster at ~22 KB, which is exactly what the previews already draw under
 * Save-Data. Publication therefore requires a poster and NEVER requires a
 * teaser — see App\Models\UgcVideo::publishBlockers(), which is where that
 * rule is enforced and pinned.
 *
 * `poster_path`, by contrast, IS required to publish: a tile with no poster is
 * a hole in the page at first paint, and the box is reserved from
 * `width`/`height` rather than measured, because no JavaScript in this project
 * measures layout.
 *
 * ── RIGHTS FAIL CLOSED ──────────────────────────────────────────────────────
 *
 * §3.3: re-hosting a creator's Instagram video is a reproduction and the
 * creator owns the copyright in it. Being tagged in a clip grants nothing. So
 * permission is a column and not a note in a spreadsheet, `rights_status`
 * defaults to 'pending', and a video cannot be published until it says
 * 'granted'.
 *
 * ── NO ->after() ANYWHERE ───────────────────────────────────────────────────
 *
 * For the reason 2026_09_15_020000_repair_order_tables already paid for on
 * this project: SQLite does not implement it and the whole migration set has
 * to run on the test database.
 *
 * Guarded with hasTable() so a re-run over a live table is a no-op rather than
 * a failed package, the way 2026_12_11_000000_create_audit_events is.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ugc_videos')) {
            Schema::create('ugc_videos', function (Blueprint $t) {
                $t->id();

                /*
                 * An address of its own later, with no migration then. Unique,
                 * because it will be a URL segment the day §1's style 3 lands
                 * at /videos/{slug}.
                 */
                $t->string('slug', 191)->unique();

                /*
                 * The two translatable fields (§5). Declared on
                 * UgcVideo::$translatable, which is the same allowlist Product
                 * uses, so the Arabic storefront reads them through
                 * TranslationStore's one cached map and a rail costs no extra
                 * query at all.
                 *
                 * A caption is a creator's own voice, so the machine path is
                 * deliberately NOT used on it — see §5. Typed, or left English
                 * with `locale` keeping the clip off the Arabic shop.
                 */
                $t->string('title', 191)->default('');
                $t->text('caption')->nullable();

                // 'draft' | 'publish'. A string and not an enum: this project
                // has had to widen one before, and MySQL's ALTER on an enum is
                // a table copy.
                $t->string('status', 16)->default('draft');

                /*
                 * Paths under /uploads/ugc/, root-relative and never absolute
                 * URLs, so this shop can move host without a rewrite — the
                 * rule Media::urlFor() already follows for every other upload
                 * in the tree.
                 */
                $t->string('file_path', 255)->nullable();
                $t->unsignedBigInteger('bytes')->nullable();

                $t->string('teaser_path', 255)->nullable();
                $t->unsignedBigInteger('teaser_bytes')->nullable();

                $t->string('poster_path', 255)->nullable();
                $t->unsignedBigInteger('poster_bytes')->nullable();

                /*
                 * The box, reserved at first paint from these two rather than
                 * measured. Layout shift is budgeted at 0 in §2 and the two
                 * tests that forbid getBoundingClientRect by name are why it
                 * cannot be measured instead.
                 *
                 * Read from ffprobe when there is one, and from the POSTER's
                 * own header via getimagesize() when there is not — which is
                 * the fallback that makes a shop with no transcoder still
                 * shift-free.
                 */
                $t->unsignedSmallInteger('width')->nullable();
                $t->unsignedSmallInteger('height')->nullable();
                $t->unsignedInteger('duration_ms')->nullable();

                /*
                 * Attribution and a link back, NEVER an embed target (§3.1 and
                 * §3.2). `source_platform` is a select and a select stores one
                 * of its own options or the default — UgcVideo::PLATFORMS.
                 */
                $t->string('source_platform', 24)->default('upload');
                $t->string('source_url', 512)->nullable();
                $t->string('creator_handle', 120)->nullable();
                $t->string('creator_url', 512)->nullable();

                // §3.3. Publication fails closed on the first of these.
                $t->string('rights_status', 16)->default('pending');
                $t->timestamp('rights_granted_at')->nullable();
                $t->text('rights_evidence')->nullable();

                /*
                 * null = both storefronts; 'en' or 'ar' restricts it. A clip
                 * spoken in English is not automatically right for the Arabic
                 * shop, and a caption nobody has translated is not either.
                 */
                $t->string('locale', 5)->nullable();

                /*
                 * Manual order, set by dragging — NOT created_at. The owner
                 * will want the good one first, not the new one.
                 */
                $t->integer('position')->default(0);

                /*
                 * Compared against now() on read, the way ProductVisibility
                 * already does it, BECAUSE THERE IS NO SCHEDULER: this host
                 * has no cron and no queue worker (docs/LC-SECURITY-MODULE.md
                 * says so for retention, and it is true here), so a job that
                 * flipped a status at a time would be a job that never ran.
                 */
                $t->timestamp('published_at')->nullable();

                $t->timestamps();

                /*
                 * The rail's own read: published rows, in the owner's order.
                 * One index over the three columns that decide it, so the
                 * round that draws a rail inherits a query plan rather than
                 * having to add one under a live shop.
                 */
                $t->index(['status', 'published_at', 'position'], 'ugc_videos_live_idx');
                $t->index('rights_status');
            });
        }

        if (! Schema::hasTable('ugc_video_product')) {
            /*
             * THE PIVOT — requirement one, in the owner's own words: several
             * products tagged on one video. The benchmark app cannot do it,
             * and the reason it cannot is that somebody else owns its player
             * (§3.2); self-hosting is what makes this table possible at all.
             */
            Schema::create('ugc_video_product', function (Blueprint $t) {
                $t->id();

                $t->foreignId('ugc_video_id')->constrained('ugc_videos')->cascadeOnDelete();
                $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();

                // The order they appear in the player. The first is the one a
                // rail tile's card shows.
                $t->integer('position')->default(0);

                /*
                 * The moment in the clip this product appears — player D's
                 * timeline markers and nothing else. NULLABLE and null
                 * everywhere by default, so choosing any other player never
                 * asks anybody to type a timestamp.
                 */
                $t->unsignedInteger('at_ms')->nullable();

                /*
                 * One row per product per video. Without this a double-click
                 * on Add tags the same product twice and the player draws two
                 * identical cards — a defect that looks like a rendering bug
                 * and is a data one.
                 */
                $t->unique(['ugc_video_id', 'product_id'], 'ugc_video_product_unique');
                $t->index(['ugc_video_id', 'position'], 'ugc_video_product_order_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ugc_video_product');
        Schema::dropIfExists('ugc_videos');
    }
};
