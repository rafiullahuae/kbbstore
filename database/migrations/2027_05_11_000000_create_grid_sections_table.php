<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One reusable homepage product-grid section, as many times as the owner likes.
 *
 * Phase 23, Lane GS. The owner, in full:
 *
 *   "Under the above sections, i need another section as same as attached, the
 *    product grid 4 by default on desktop and on mobile careousel, and give
 *    controls to chooose the brand, category, manual products selection etc and
 *    other controls. on desktop also give control to make it carousel. with
 *    bottom view all button (manual link) and THEN i need anothe product grid
 *    section called BEST SELLERS. 5 columns on desktop and in mobile 6
 *    products, and all controls i need for it. DO ONE thing. prepare a proper
 *    grid section with all controls and it can be use anywhere, and can be edit
 *    that specific grid section. so this case we can re-use this grid section
 *    anywhere multiple times with different products etc selection."
 *
 * ── WHY A TABLE AND NOT A SETTINGS BLOB ─────────────────────────────────────
 *
 * The last three sentences are the brief: ONE section type, created as many
 * times as wanted, each instance edited on its own. A `settings` row holds ONE
 * value per key for the whole shop — which is exactly the reason
 * `Banners::SCHEMA` carries two keys and `banner_sets` carries eighteen
 * columns, and the same reason applies here one step further along: there is no
 * fixed number of instances at all. A row per instance is what makes "add
 * another one" a write rather than a code change.
 *
 * It is also what lets an instance BE a homepage section. `HomepageSections`
 * keys everything off `REGISTRY`, which is a const; `HomepageSections::
 * registry()` now returns that const plus one row per instance in this table,
 * so every instance inherits the ordering, the Desktop/Mobile switches and the
 * dividers the seventeen shipped sections already have, with no second
 * mechanism beside it. `GridSections::registryRows()` is the one reader.
 *
 * ── WHY THE MANUAL PICK IS A JSON COLUMN AND NOT A PIVOT TABLE ──────────────
 *
 * Lane BN put its cards in a table and argued it: they carry images, their own
 * publish state, and the Media Library has to see them. None of that is true of
 * a manual product pick. It is an ORDERED LIST OF IDS and nothing else — no
 * attributes of its own, no lifecycle of its own, nothing that reads it but
 * this section. A pivot table would be a second write path, a second delete
 * path and a JOIN on the homepage's critical path to store what `[41, 12, 8]`
 * already says. The ids are validated against rows that exist on the way IN
 * (GridSectionApiController::cleanManualIds) and re-checked against
 * `Product::visible()` on the way out, so a product deleted after the pick was
 * saved drops out of the row rather than 500ing it.
 *
 * ── NOTHING HERE MOVES THE SHOP ─────────────────────────────────────────────
 *
 * The table is created EMPTY. With no rows, `registryRows()` returns `[]`,
 * `HomepageSections::registry()` is `REGISTRY` byte for byte, the homepage's
 * new block renders nothing at all and emits no style element — so a shop that
 * applies this package and opens nothing renders the identical document.
 * `GridSectionShipsOffTest` asserts that with rows in the table and the loop
 * live; `StorefrontEnglishUnchangedTest` asserts it against the tree this
 * branched from.
 *
 * The two sections the owner named are NOT seeded here. They are content he
 * creates, and the screen offers each as a one-click preset instead — see
 * `GridSections::PRESETS`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grid_sections')) {
            return;
        }

        Schema::create('grid_sections', function (Blueprint $t) {
            $t->id();

            /*
             * The name on the ADMIN's list, which is not the heading the shop
             * prints. Two fields because they answer different questions: the
             * owner needs to tell two instances apart on the Homepage ordering
             * screen even when both have their heading switched off, and a
             * section whose heading is off would otherwise be an unnamed row.
             */
            $t->string('name', 190);

            /*
             * UNIQUE, and the section key is derived from the id rather than
             * from this — `grid_{id}` — so renaming an instance cannot orphan
             * its saved order, its Desktop/Mobile switches or its divider.
             * The slug is for the anchor the "View all" button can point at and
             * for a human-readable handle in a later shortcode.
             */
            $t->string('slug', 190)->unique();

            /*
             * `draft` is an instance the owner is still building. It is listed
             * on the Homepage ordering screen (so he can see why it is not
             * showing) and draws NOTHING on the shop — GridSections::forHome()
             * filters on it. That is separate from the Desktop/Mobile switches,
             * which live in `homepage_sections` with every other section's.
             */
            $t->string('status', 16)->default('draft');

            $t->unsignedInteger('position')->default(0);

            /* ── the heading ───────────────────────────────────────────────── */

            $t->boolean('show_heading')->default(true);
            $t->string('heading', 190)->default('');
            $t->string('subheading', 255)->default('');

            /* ── where the products come from ─────────────────────────────── */

            /*
             * One of GridSection::SOURCES. A select stores one of its own
             * options or the default (CLAUDE.md rule 5), which is
             * ModuleSchema::cast()'s job on the way in and this column's
             * default on the way out.
             */
            $t->string('source', 24)->default('bestsellers');

            /* Set only when `source` is brand / category. Nullable rather than
             * 0, because 0 is an id nobody issued and null is the absence the
             * query actually tests for. */
            $t->unsignedBigInteger('source_brand_id')->nullable();
            $t->unsignedBigInteger('source_category_id')->nullable();

            /* Category only: also take the products of its descendants. */
            $t->boolean('include_children')->default(false);

            /* The manual pick, in the owner's own order. See the header. */
            $t->json('manual_ids')->nullable();

            /*
             * How many the DESKTOP shows. `mobile_count` is separate because
             * the owner asked for exactly that on the second of his two
             * instances — "5 columns on desktop and in mobile 6 products" —
             * and a single number cannot say it.
             *
             * The row is fetched ONCE at max(count, mobile_count) and the
             * surplus is hidden by a class at the other breakpoint, so the two
             * numbers cost one query and not two. resources/views/partials/
             * home/grid-section.blade.php carries that argument.
             */
            $t->unsignedSmallInteger('count')->default(8);
            $t->unsignedSmallInteger('mobile_count')->default(8);

            /* ── layout, desktop and mobile separately ────────────────────── */

            /* 'grid' or 'carousel', one of GridSection::LAYOUTS. */
            $t->string('desktop_layout', 16)->default('grid');
            $t->unsignedTinyInteger('desktop_cols')->default(4);

            $t->string('mobile_layout', 16)->default('carousel');
            $t->unsignedTinyInteger('mobile_cols')->default(2);

            /*
             * '' means "whatever the shop's own grid skin is", which is what
             * GridSkins::resolve(null) already answers. A named skin is one of
             * GridSkins::ALL and nothing else.
             *
             * ▲ THIS PICKS AN EXISTING SKIN. It never defines one. The card is
             * components/product-card.blade.php and its 28 templates are
             * resources/css/kbb/kbb-grid-skins.css; this lane adds no card CSS
             * and edits neither.
             */
            $t->string('skin', 32)->default('');

            /*
             * The eyebrow line on every tile of THIS grid — "SKINCARE SETS" on
             * the owner's reference shot. It is the SECTION's label and not the
             * product's own category, which is why the card takes it as a
             * caller's string (`components/product-card.blade.php`, `catLabel`)
             * and why it belongs on this row rather than on the product.
             *
             * '' draws no eyebrow at all, which is what the card already does
             * for a null — so this ships at the value the page renders.
             */
            $t->string('card_label', 120)->default('');

            /*
             * The #1, #2… badge the shipped best-sellers rail carries. The
             * owner's second instance IS a best-sellers row, so the control has
             * to exist for him to reproduce what the shop already draws; it
             * ships OFF, because a grid of newest arrivals numbered #1 to #10
             * is a ranking of nothing.
             */
            $t->boolean('show_rank')->default(false);

            /* ── the "View all" button at the foot ────────────────────────── */

            $t->boolean('show_view_all')->default(false);
            $t->string('view_all_label', 120)->default('');

            /*
             * A URL FROM A SETTING, so it is scheme-checked with
             * App\Support\SafeUrl before it becomes an href — CLAUDE.md rule 5,
             * and the reason that helper exists. Stored raw and checked at
             * render, because a value that was safe when it was stored is not
             * the question the browser asks.
             */
            $t->string('view_all_url', 500)->default('');

            $t->timestamps();

            /*
             * The one index this table needs. Every read is "every instance, in
             * position order" — registryRows() for the console and for
             * HomepageSections, forHome() for the shop — and there will be tens
             * of rows here, not thousands.
             */
            $t->index(['position', 'id'], 'grid_sections_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grid_sections');
    }
};
