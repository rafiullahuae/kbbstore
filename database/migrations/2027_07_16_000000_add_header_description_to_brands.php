<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A brand's own header description. (Lane RA)
 *
 * The owner asked for a pencil on every category AND brand page that changes
 * "background image, title, description". Categories have had
 * `header_description` since Lane PY; brands never did, so the brand header
 * could only ever show the brand's general `description` -- the same text the
 * brand directory and the page's meta read. Writing the pencil's description
 * into that column would have changed both of those as well, which nobody
 * asked for.
 *
 * So brands get the column categories already have, with the same meaning:
 * blank means "use the brand's own description". TitleHeader::forModel()
 * already reads `header_description` off whichever model it is given, so no
 * renderer changes -- a brand with nothing in it renders byte for byte what it
 * rendered before, and StorefrontEnglishUnchangedTest pins that.
 *
 * Nullable, no default, no backfill, no AFTER clause (MigrationConventionTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brands') && ! Schema::hasColumn('brands', 'header_description')) {
            Schema::table('brands', fn (Blueprint $t) => $t->text('header_description')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('brands') && Schema::hasColumn('brands', 'header_description')) {
            Schema::table('brands', fn (Blueprint $t) => $t->dropColumn('header_description'));
        }
    }
};
