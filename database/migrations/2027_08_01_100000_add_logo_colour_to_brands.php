<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Support\BrandLogo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE RING ROUND A BRAND'S LOGO, IN THE BRAND'S OWN COLOUR.          (Lane BH)
 *
 * The owner, 4 October: "provide facility to upload the brand logo, and it
 * will be auto circled with outer brand color border. the brand color the
 * system can fetch from the logo image itself."
 *
 *   logo_color   #rrggbb taken from the logo file by App\Support\BrandLogo,
 *                or NULL (no logo, a logo on another host -- never fetched --
 *                or a logo with no clear colour). Recomputed whenever a logo
 *                is saved.
 *   ring_color   #rrggbb the owner chose in Catalog → Brands → Edit → Ring
 *                colour, or NULL for "from the logo".
 *
 * Both nullable, VARCHAR(7), on MySQL and SQLite alike, each added only when
 * it is missing so a second run is a no-op.
 *
 * ── THE BACKFILL CANNOT FAIL THIS MIGRATION ────────────────────────────────
 *
 * Every brand that already has a logo gets its colour now, so applying the
 * package rings the whole shop rather than only the brands somebody edits
 * afterwards. One brand at a time, each inside its own try/catch, through
 * BrandLogo::refresh(), which itself never throws: a logo whose file was
 * deleted, a corrupt upload, a logo on the old WooCommerce domain -- each is
 * a NULL colour (the pink ring), never a failed update. The whole loop is
 * guarded again, because an update package that dies here leaves the shop
 * half-migrated, and a ring is not worth that.
 *
 * ── WHY NOTHING ELSE TOUCHES THESE COLUMNS UNTIL THEY EXIST ────────────────
 *
 * Writers ask BrandLogo::columnsReady() first; the storefront only reads the
 * attribute, which is null on a row loaded before this ran. No model event
 * writes them, so the older migrations a fresh install runs before this one
 * -- some of which create brands -- never mention a column that is not there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brands')) {
            return;
        }

        Schema::table('brands', function (Blueprint $t) {
            if (! Schema::hasColumn('brands', 'logo_color')) {
                $t->string('logo_color', 7)->nullable();
            }

            if (! Schema::hasColumn('brands', 'ring_color')) {
                $t->string('ring_color', 7)->nullable();
            }
        });

        BrandLogo::forgetColumns();

        $coloured = 0;
        $looked = 0;

        try {
            foreach (Brand::query()->select(['id', 'logo'])->whereNotNull('logo')->where('logo', '<>', '')->orderBy('id')->get() as $brand) {
                $looked++;

                try {
                    if (BrandLogo::refresh($brand) !== null) {
                        $coloured++;
                    }
                } catch (\Throwable) {
                    // One brand's logo is never a reason to stop the others.
                }
            }
        } catch (\Throwable) {
            // Nor is the loop itself a reason to fail the update.
        }

        if (app()->runningInConsole()) {
            echo "Brand logos now sit in a circle with a ring in the brand's own colour.\n"
                ."Coloured {$coloured} of {$looked} brand logos from the logo itself; the rest ring in the shop pink.\n"
                ."Change one in Catalog -> Brands -> Edit -> Ring colour.\n";
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('brands')) {
            return;
        }

        foreach (['ring_color', 'logo_color'] as $column) {
            if (Schema::hasColumn('brands', $column)) {
                Schema::table('brands', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }

        BrandLogo::forgetColumns();
    }
};
