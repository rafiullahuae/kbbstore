<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shoppable UGC video — SECTIONS. Phase 20, Lane V3.
 *
 * ── WHAT THIS DOES TO THE SHOP ──────────────────────────────────────────────
 *
 * NOTHING, until somebody turns the module on AND writes a shortcode. The
 * `shoppable_video` module row ships `false`, App\Services\UgcSettings::enabled()
 * is the only reader, and App\Support\Shortcodes::ugc() returns the empty string
 * while it is off. So applying this package leaves every page byte-identical and
 * StorefrontEnglishUnchangedTest cannot move.
 *
 * ── WHY A SECTION TABLE AND NOT A COLUMN ON ugc_videos ──────────────────────
 *
 * The owner, in his own words: "i need a full module, where i can create
 * multiple sections of videos contain, and can insert anywhere in the site,
 * products and pages etc via short code."
 *
 * "Anywhere in the site" is the part that decides the shape. A shortcode is
 * written into a page's body, a post's body or an HTML block, and the SAME clip
 * is very often wanted in two of them — the hero rail on the homepage and the
 * sunscreen rail on a sun-care category page share the sunscreen clip. A
 * `ugc_section_id` column on ugc_videos would make that impossible without
 * uploading the file twice, which costs storage, doubles the transcode and
 * gives the two copies different like counts.
 *
 * So it is a pivot with its own `position`, exactly like `ugc_video_product`
 * one table over: a video belongs to as many sections as the owner likes, and
 * each section orders its own list. The video's own `position` column stays what
 * it always was — the LIBRARY's order — and a section never reads it.
 *
 * ── THE HANDLE IS THE SHORTCODE, SO IT IS UNIQUE AND IT IS NARROW ───────────
 *
 * `[kbb_videos section="glass-skin"]` resolves on `handle`. It is generated from
 * the title the way UgcVideoController::slug() generates a video slug, it is
 * unique, and UgcSection::HANDLE_RE is the shape both the admin and the
 * shortcode check against — because a handle that can carry a quote or a
 * bracket is a handle that can break the shortcode that names it.
 *
 * ── NO ->after() ANYWHERE, AND hasTable() GUARDS ────────────────────────────
 *
 * SQLite does not implement ->after() and the whole migration set runs on the
 * test database (2026_09_15_020000_repair_order_tables paid for that once). The
 * hasTable() guards make a re-run over a live table a no-op rather than a failed
 * package, the way 2026_12_11_000000_create_audit_events is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ugc_sections')) {
            Schema::create('ugc_sections', function (Blueprint $t) {
                $t->id();

                /*
                 * What the shortcode names. Unique, because two sections with
                 * one handle makes `[kbb_videos section="x"]` mean whichever row
                 * the database returned first — an editorial decision handed to
                 * a query plan.
                 */
                $t->string('handle', 96)->unique();

                /*
                 * `title` is the operator's own name for the section in the
                 * admin list. `heading` is what the STOREFRONT prints above the
                 * rail, and it is a separate column rather than the same one
                 * because they are different sentences: "Homepage hero rail" is
                 * a label for him and "#KBeautyBliss spotted" is a heading for a
                 * shopper. An empty heading draws no heading element at all.
                 *
                 * Both are translatable (UgcSection::$translatable) so the
                 * Arabic storefront reads them through TranslationStore's one
                 * cached map, which costs a rail no extra query.
                 */
                $t->string('title', 191)->default('');
                $t->string('heading', 191)->nullable();
                $t->string('subheading', 255)->nullable();

                // 'draft' | 'publish'. A string, not an enum: MySQL's ALTER on
                // an enum is a table copy and this project has widened one before.
                $t->string('status', 16)->default('draft');

                /*
                 * A per-section override of Appearance → Video rail's
                 * mobile column choice, or null to follow the setting. Null is
                 * the default and is what keeps rule 1: a section created today
                 * renders exactly what the settings screen says, and the owner
                 * opts a single rail out deliberately.
                 */
                $t->string('columns', 16)->nullable();

                // null = both storefronts; 'en' or 'ar' restricts it, the same
                // rule ugc_videos.locale already follows for a clip.
                $t->string('locale', 5)->nullable();

                // How many tiles this section renders at most. A rail is a rail
                // at eight and a scroll marathon at eighty.
                $t->unsignedSmallInteger('max_tiles')->default(12);

                $t->integer('position')->default(0);

                $t->timestamps();

                $t->index(['status', 'position'], 'ugc_sections_live_idx');
            });
        }

        if (! Schema::hasTable('ugc_section_video')) {
            Schema::create('ugc_section_video', function (Blueprint $t) {
                $t->id();

                $t->foreignId('ugc_section_id')->constrained('ugc_sections')->cascadeOnDelete();
                $t->foreignId('ugc_video_id')->constrained('ugc_videos')->cascadeOnDelete();

                /*
                 * The section's own order, set by dragging. NOT ugc_videos.position
                 * — that is the library's order, and the owner will want a
                 * different clip first in the homepage rail than in the
                 * sunscreen rail.
                 */
                $t->integer('position')->default(0);

                $t->timestamps();

                // One clip appears once in one section. A duplicate would be
                // two tiles of the same video with the same like count, and the
                // admin cannot act on that.
                $t->unique(['ugc_section_id', 'ugc_video_id'], 'ugc_section_video_unique');

                // The rail's own read: this section's rows, in the owner's order.
                $t->index(['ugc_section_id', 'position'], 'ugc_section_video_order_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ugc_section_video');
        Schema::dropIfExists('ugc_sections');
    }
};
