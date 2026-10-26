<?php
/*
 * Lane SSB: server ms, queries, settings-map reads and HTML bytes for
 * /super-sale/ (English and Arabic) with the live banner OFF (the page as it
 * was) and ON, and the product, category and brand pages beside them, measured
 * in-process against the preview database by tools/phb-measure.php:
 *
 *     SSB_ON=0 sh tools/ssb-tinker.sh "require 'tools/ssb-measure.php';"
 *     SSB_ON=1 sh tools/ssb-tinker.sh "require 'tools/ssb-measure.php';"
 *
 * Leaves the banner as the last run set it.
 */
use App\Services\SettingsService;
use App\Support\SuperSaleBanner;

$product = '/product/'.\App\Models\Product::query()->where('status', 'publish')->where('is_visible', true)->orderBy('id')->value('slug').'/';
$category = '/collections/'.\App\Models\Category::query()->where('slug', '!=', 'super-sale')->whereHas('products')->orderBy('id')->value('slug').'/';
$brand = '/brands/'.\App\Models\Brand::query()->whereHas('products', fn ($q) => $q->where('is_visible', true))->orderBy('id')->value('slug').'/';

// One pass per process (phb-measure.php registers its settings-read listener
// each time it is required, so a second pass in one process counts twice).
foreach ([getenv('SSB_ON') !== '0'] as $on) {
    app(SettingsService::class)->set(SuperSaleBanner::KEY, ['on' => $on] + SuperSaleBanner::defaults());
    \Illuminate\Support\Facades\Cache::flush();
    echo "\n== live banner ".($on ? 'ON' : 'OFF (the page as it was)')." ==\n";
    putenv('CB_PATHS=/super-sale/,/ar/super-sale/,'.$product.','.$category.','.$brand);
    putenv('CB_RUNS='.(getenv('SSB_RUNS') ?: 15));
    require __DIR__.'/phb-measure.php';
}
