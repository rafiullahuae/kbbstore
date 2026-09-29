<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\HasTranslations;
use App\Support\ProductTabs;
use Illuminate\Database\Eloquent\Model;

/**
 * One authored tab: global, one product's own, or one product's override.
 * (Lane PT)
 *
 * The three shapes are decided by two nullable columns and are documented on
 * 2027_04_25_000000_create_product_tabs_table. This class adds three things to
 * that row and nothing else:
 *
 *   1. TRANSLATION. `title` and `body` go through App\Support\HasTranslations
 *      exactly as a product's name and description do, so the Arabic half of a
 *      tab is stored in the same table, read by the same map and falls back to
 *      the English the same way. `body` is in TranslationStore::LONG_FIELDS by
 *      virtue of its NAME -- see the migration's header -- so a tab's Arabic
 *      prose is fetched by the page that prints it rather than carried in the
 *      map on every Arabic request.
 *
 *   2. EVICTION. App\Support\ProductTabs caches the global tabs and the set of
 *      product ids that have any row at all, because the product page reads
 *      both on every request and a shop with no tabs must not pay a query for
 *      the feature. Both are flushed from the model's own saved/deleted hooks
 *      rather than from the controller, so a writer that has never heard of
 *      ProductTabs still evicts -- the same argument TranslationStore makes for
 *      putting its flush() on a model hook.
 *
 *   3. NOTHING ELSE. No accessor that renders, no scope that publishes. The
 *      decisions about which tabs a product shows, in what order, and what a
 *      per-product row does to a global one all live in ONE place, and it is
 *      App\Support\ProductTabs. A model that also knew would be a second place
 *      for the answer to drift.
 */
class ProductTab extends Model
{
    use HasTranslations;

    /**
     * Both of the fields a shopper reads.
     *
     * `body` is rich text printed with {!! !!} by
     * partials/product-tabs.blade.php, in BOTH languages, so the Arabic half is
     * sanitised on the way in by ProductTabsApiController through
     * TranslationInput::clean($bag, ProductTabsApiController::RICH_FIELDS) --
     * the same path PageEditorApiController and PostEditorApiController use for
     * `content` and `body`. `title` is NOT rich: it is printed escaped, into a
     * <button> and an accordion heading, and it must stay that way.
     *
     * @var list<string>
     */
    protected array $translatable = ['title', 'body'];

    protected $fillable = [
        'product_id', 'source_key', 'title', 'body', 'position', 'is_enabled',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'position' => 'integer',
        'is_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(static fn () => ProductTabs::flush());
        static::deleted(static fn () => ProductTabs::flush());
    }

    /** A tab that appears on every product. */
    public function scopeGlobal($query)
    {
        return $query->whereNull('product_id');
    }
}
