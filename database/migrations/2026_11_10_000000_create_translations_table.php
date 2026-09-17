<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every translated string in the shop, in one table.
 *
 * ── WHY A TABLE AND NOT lang/ar.json ────────────────────────────────────────
 *
 * This is not a preference. It is forced by the host, twice over.
 *
 * The server has no shell. A file under lang/ can only change by building a
 * signed zip, uploading it and applying it through Store → Core Updates. The
 * owner's requirement was "if we found anything incorrect, we can correct it
 * manually" — a correction that costs a release is a correction that does not
 * happen.
 *
 * And a package could not carry the file anyway: UpdateGuard::ALLOWED_PREFIXES
 * is app/, config/, database/migrations/, database/seeders/, resources/,
 * routes/ and public/build/. `lang/` is on none of them, so an update package
 * containing lang/ar.json is REJECTED by the updater. Laravel's conventional
 * home for translations is, on this host, a directory that cannot be shipped
 * to.
 *
 * So: English defaults live in code, under app/, where a package can reach them
 * (App\Services\Translation\InterfaceStrings). Everything the owner types lives
 * here, where he can reach it. The code default is the fallback and the row is
 * the override — which also means he can correct an English wording without a
 * release, not only an Arabic one.
 *
 * ── THE SHAPE, AND WHY ONE TABLE COVERS BOTH KINDS OF STRING ────────────────
 *
 * Interface strings are keyed and finite ("Add to bag"). Content is per-row and
 * unbounded (671 product names). They are stored the same way because the
 * difference is entirely in the key:
 *
 *   group     'ui' for interface strings; otherwise the model's TABLE name —
 *             'products', 'categories', 'brands', 'pages', 'posts',
 *             'menu_items'. A table name rather than a class name so a class
 *             can be renamed or moved without orphaning its translations.
 *   item_id   the row's primary key; 0 for interface strings.
 *   field     the column for content ('name', 'short_description'), or the
 *             dotted key for interface strings ('cart.empty_title').
 *
 * THE ALTERNATIVE WAS name_ar, short_description_ar, description_ar … on every
 * table. Rejected for three concrete reasons, not taste:
 *
 *   1. Every new translatable field is a migration, on a host where a migration
 *      is a release. This shop adds product fields regularly.
 *   2. "What is still untranslated?" becomes a query with one COALESCE per
 *      column per table, rewritten every time a column is added. Here it is one
 *      GROUP BY over one table, which is what makes the owner's progress
 *      screen possible at all.
 *   3. A third language doubles the column count on six tables. Here it is
 *      rows.
 *
 * What it costs is a join, and an N+1 if nobody thinks about it. That is paid
 * for by App\Support\HasTranslations::scopeWithTranslations(), which loads a
 * whole page of products' translations in ONE query, and pinned by a query
 * budget test over a 24-product grid.
 *
 * ── COLLATION, AND THE TRAP THIS SHOP IS ONE ALTER AWAY FROM ────────────────
 *
 * config/database.php sets utf8mb4 / utf8mb4_unicode_ci, so that is what these
 * columns get. utf8mb4_unicode_ci is case-insensitive AND accent-insensitive,
 * which is right for searching product names and WRONG for anything used as an
 * identity. Two consequences, both designed around rather than hoped about:
 *
 *   - The unique key is (locale, group, item_id, field). Those are ASCII
 *     identifiers, and under a _ci collation 'Name' and 'name' are the SAME
 *     KEY. That is a feature here, not a bug — it makes a typo'd key collide
 *     loudly on insert instead of silently creating a second row that nothing
 *     reads — but it is only safe because App\Services\Translation\
 *     TranslationStore::normaliseKey() forces every key to lowercase before it
 *     is written. A test pins that.
 *
 *   - `value` holds Arabic and is NEVER indexed, never unique and never
 *     compared with =. Under utf8mb4_unicode_ci two visibly different Arabic
 *     strings — the same letters with and without tashkeel, or with a tatweel
 *     between them — compare EQUAL. A unique index on value, or a
 *     where('value', $x) de-duplication, would silently merge or reject
 *     legitimate distinct translations. There is no such index and no such
 *     comparison, and that is deliberate.
 *
 * Index width: 5 + 32 + 8 + 64 characters = 20 + 128 + 8 + 256 = 412 bytes
 * under utf8mb4, comfortably inside InnoDB's 3072-byte limit. The 767-byte
 * ceiling that bit add_capture_and_refund_tracking is for the old
 * COMPACT/REDUNDANT row formats; 412 is under that too, so this is safe either
 * way rather than safe only on a modern row format.
 *
 * ── item_id IS 0, NOT NULL, FOR INTERFACE STRINGS ───────────────────────────
 *
 * In SQL, NULL is not equal to NULL, so a unique index containing a nullable
 * column does not constrain rows where it is null — on MySQL and on SQLite
 * alike. A nullable item_id would mean the UI strings, the half of this table
 * with the strongest reason to be unique, were the half with no uniqueness at
 * all: every save of "Add to bag" would append a row and the reader would pick
 * one arbitrarily.
 *
 * No ->after() anywhere: this creates tables. See MigrationConventionTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('translations')) {
            return;
        }

        Schema::create('translations', function (Blueprint $t) {
            $t->id();

            // BCP-47 short codes: 'en', 'ar'. 5 leaves room for 'pt-BR'.
            $t->string('locale', 5);

            // 'ui', or the translated model's table name.
            $t->string('group', 32);

            // The row's id; 0 for interface strings. See the header.
            $t->unsignedBigInteger('item_id')->default(0);

            // A column name, or a dotted interface key.
            $t->string('field', 64);

            /*
             * mediumtext, not text. A product description on this shop runs to
             * a few kilobytes of HTML and Arabic costs two bytes per letter in
             * utf8mb4 where English costs one, so the same copy is materially
             * longer here. TEXT's 65,535 BYTES is about 32,000 Arabic letters —
             * survivable for most rows and a silent truncation for the worst,
             * which is the kind of failure this shop has already paid for.
             */
            $t->mediumText('value')->nullable();

            /*
             * 'draft' or 'published'. ONLY published rows are ever served.
             *
             * This is the whole of the owner's "if we found anything incorrect,
             * we can correct it manually" requirement, expressed as a column:
             * machine output arrives as a draft and reaches a shopper only
             * after he has looked at it. A machine translation of a skincare
             * ingredient list published straight to the storefront is a product
             * safety claim nobody read.
             */
            $t->string('status', 10)->default('draft');

            // 'manual', 'machine', 'import' — so the owner can find and re-read
            // everything a machine wrote, and only that.
            $t->string('source', 10)->default('manual');

            /*
             * sha1 of the ENGLISH text this translation was made from.
             *
             * Without it, editing an English product description leaves a
             * confident, published, now-WRONG Arabic translation in place with
             * nothing anywhere saying so. With it, "the English changed since
             * this was translated" is a string comparison, and the progress
             * screen can show stale rows beside untranslated ones. 40 chars,
             * hex, so it is ASCII whatever the column collation says.
             */
            $t->string('source_hash', 40)->nullable();

            // When a human last approved it. Null means nobody has.
            $t->timestamp('reviewed_at')->nullable();

            $t->timestamps();

            // Identity. See the header for why item_id defaults to 0 and why a
            // case-insensitive collation is safe here but not on `value`.
            $t->unique(['locale', 'group', 'item_id', 'field'], 'translations_identity_unique');

            // "Everything for this page of products", the read path that must
            // not become an N+1.
            $t->index(['group', 'item_id']);

            // "What is left to do", the progress screen's only query.
            $t->index(['locale', 'group', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
