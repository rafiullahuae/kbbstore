<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Category;
use App\Models\Product;
use App\Services\CustomLabels;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Catalog -> Labels (Lane LBL). Two hooks, both free while no label is on:
 *
 *  - the shop layout's head gets the badge stylesheet (~1.9 KB, inline) only
 *    on a page that drew a card or a product page while a label is on. A
 *    composer runs when the layout renders, which is AFTER the page's content
 *    has been rendered, so it knows. Prints nothing otherwise: the HTML of a
 *    shop with no label is byte-identical (StorefrontEnglishUnchangedTest).
 *  - a product or category save rebuilds the label map once, after the
 *    response (CustomLabels::markDirty()). Stock and sales writes are skipped:
 *    they cannot move a product in or out of a label.
 *
 * Registered in bootstrap/providers.php rather than in AppServiceProvider so
 * this lane leaves that shared file alone.
 */
final class CustomLabelsServiceProvider extends ServiceProvider
{
    /** Columns whose change alone cannot change which labels a product has. */
    private const QUIET = ['stock', 'stock_status', 'total_sales', 'rating', 'review_count', 'updated_at'];

    public function boot(): void
    {
        View::composer('layouts.store', static function ($view): void {
            $css = CustomLabels::headStyle();

            if ($css !== '') {
                $view->getFactory()->startPush('head', $css);
            }
        });

        Product::saved(static function (Product $p): void {
            $changed = array_keys($p->getChanges());

            if ($changed !== [] && array_diff($changed, self::QUIET) === []) {
                return;
            }

            CustomLabels::markDirty();
        });
        Product::deleted(static fn () => CustomLabels::markDirty());
        Category::saved(static fn () => CustomLabels::markDirty());
        Category::deleted(static fn () => CustomLabels::markDirty());
    }
}
