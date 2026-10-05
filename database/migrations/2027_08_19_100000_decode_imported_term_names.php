<?php

declare(strict_types=1);

use App\Support\TermName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * "Hydration &amp; Glow" in the owner's Filters drawer. (Lane FP)
 *
 * WordPress stores a term name HTML-encoded and the importer copied it as it
 * came, so `categories.name` held "Hydration &amp; Glow" and the shop, which
 * escapes what it prints, drew the entity. The importer now stores the plain
 * text (App\Support\TermName); this brings the rows already imported into line,
 * categories and brands both -- the brand list in the same drawer has the same
 * shape ("Rom&amp;nd").
 *
 * Only a row whose name actually decodes to something different is written.
 * A name typed in the admin ("Bath & Body") has no entity in it and is left
 * byte for byte. Slugs are not touched, so no URL moves.
 *
 * Every cache that holds a category or brand name is forgotten, or the drawer
 * would keep the old text for up to fifteen minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fixed = 0;

        foreach (['categories', 'brands'] as $table) {
            DB::table($table)->select('id', 'name')->where('name', 'like', '%&%')->orderBy('id')
                ->each(function (object $row) use ($table, &$fixed): void {
                    $plain = TermName::plain((string) $row->name);

                    if ($plain !== $row->name) {
                        DB::table($table)->where('id', $row->id)->update(['name' => $plain]);
                        $fixed++;
                    }
                });
        }

        foreach ([
            'kbb.shop.cats', 'kbb.shop.brands', 'kbb.home.cats', 'kbb.home.brands', 'kbb.admin.cats',
            'kbb.admin.brands', 'kbb.search.brandnames', 'kbb.search.starter.brands',
            'kbb.nav.primary', 'kbb.nav.mobile', 'kbb.nav.footer',
        ] as $key) {
            Cache::forget($key);
        }

        foreach ([
            [\App\Http\Controllers\Store\HomeController::class, 'flushCache'],
            [\App\Support\ProductTabs::class, 'flush'],
            [\App\Services\BuyTogetherPairs::class, 'forget'],
        ] as [$class, $method]) {
            if (method_exists($class, $method)) {
                $class::$method();
            }
        }

        if (app()->runningInConsole()) {
            echo "Category and brand names stored as plain text: {$fixed} fixed.\n";
        }
    }

    public function down(): void {}
};
