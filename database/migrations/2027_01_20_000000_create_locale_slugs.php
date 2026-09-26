<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second address per row, per language — and a locale on the redirects table
 * so that moving one language's address is expressible at all.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * NOTHING MOVES WHEN THIS RUNS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Both halves ship INERT. `locale_slugs` is created empty and
 * `App\Support\LocaleSlugs` reads it only while `seo_arabic_slugs` says
 * `translated`; no row seeds that setting, so it reads `shared` — which is the
 * behaviour this shop has today, one slug per row with the language carried by
 * the /ar prefix. `redirects.locale` is nullable and every existing row keeps
 * NULL, which `CheckRedirects` treats as "both languages", which is what every
 * row in that table has always meant.
 *
 * docs/SEO-ARABIC-SLUGS.md is the decision this is built underneath, including
 * the recommendation NOT to switch it on. It is built because the owner asked
 * for the choice to exist before Arabic launches rather than after, and because
 * the retrofit is free to rehearse while no Arabic URL is indexed and expensive
 * to invent once one is.
 *
 * ── WHY A TABLE OF ITS OWN AND NOT `translations` ───────────────────────────
 *
 * `HasTranslations` keeps SLUG off every model's allowlist deliberately, and
 * that stays true: a slug is not prose, it is an ADDRESS, and the two need
 * different guarantees. A translation may be blank, duplicated across rows,
 * drafted and approved. An address may not: it needs a UNIQUENESS SPACE per
 * language, and `translations` has none — its unique key is
 * (locale, group, item_id, field), which would happily let two products share
 * one Arabic address and give the second one a 404 nobody could explain.
 *
 * So: unique (locale, group, slug) as well as (locale, group, item_id). One
 * address per row and one row per address, per language, enforced by the
 * database rather than by the screen that writes it.
 *
 * ── 191, AND WHY IT IS NOT 255 ──────────────────────────────────────────────
 *
 * The utf8mb4 index-length limit under the row formats this shop's MySQL may be
 * using. It is the same figure `ugc_videos.slug` already carries. In Arabic
 * that is 191 BYTES — about 95 letters — which is longer than any address a
 * person would type and far shorter than the escaped form a browser sends
 * (`/ar/product/` plus a 15-letter Arabic slug is already 120 characters on the
 * wire; see the doc).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('locale_slugs')) {
            Schema::create('locale_slugs', function (Blueprint $t) {
                $t->id();

                // 'ar'. 5 leaves room for 'pt-BR', exactly as translations.locale does.
                $t->string('locale', 5);

                // The model's table name — 'products', 'categories'. Same
                // convention as translations.group, so one mental model.
                $t->string('group', 32);

                $t->unsignedBigInteger('item_id');

                $t->string('slug', 191);

                $t->timestamps();

                // One address per row per language …
                $t->unique(['locale', 'group', 'item_id'], 'locale_slugs_row_unique');
                // … and one row per address per language.
                $t->unique(['locale', 'group', 'slug'], 'locale_slugs_address_unique');
            });
        }

        /*
         * ── THE COLUMN THAT MAKES AN ARABIC-ONLY MOVE POSSIBLE ──────────────
         *
         * `SetLocaleFromPath` strips /ar BEFORE `CheckRedirects` sees the path,
         * so `redirects.source` is stored with no locale segment and ONE row
         * serves both languages. That is exactly right for an old WooCommerce
         * address, which moved in both, and it is measured in
         * ArabicSlugPolicyTest — and it means the table could not say "this
         * address moved in Arabic only". Without this column the retrofit has
         * nowhere to write.
         *
         * NULL MEANS BOTH, and that is what every existing row keeps. Not '' and
         * not 'all': the fifteen derived legacy redirects and every row the owner
         * has written apply to both languages, so the absence of an opinion has
         * to be the value they already hold. `CheckRedirects::claimed()` reads
         * the index on `source` alone and the locale is checked on the ROW, so
         * the zero-query storefront path is unchanged.
         */
        if (Schema::hasTable('redirects') && ! Schema::hasColumn('redirects', 'locale')) {
            Schema::table('redirects', function (Blueprint $t) {
                /*
                 * NO ->after(). MigrationConventionTest forbids it in a new
                 * migration and grandfathers the eleven that predate the rule:
                 * SQLite has no column-position syntax, so a migration carrying
                 * one behaves differently on the two engines this project runs
                 * on. Column order is not a property anything here reads.
                 */
                $t->string('locale', 5)->nullable();
            });
        }

        if (app()->runningInConsole()) {
            echo "Added locale_slugs and redirects.locale. Both are EMPTY and INERT: the\n"
                ."Arabic slug policy ships at 'shared', which is the one-slug-per-row\n"
                ."behaviour this shop already has, so no address on the storefront moves.\n";
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('redirects') && Schema::hasColumn('redirects', 'locale')) {
            Schema::table('redirects', function (Blueprint $t) {
                $t->dropColumn('locale');
            });
        }

        Schema::dropIfExists('locale_slugs');
    }
};
