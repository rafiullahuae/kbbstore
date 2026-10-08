<?php

declare(strict_types=1);

namespace App\Services\ImageSeo;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Catalog → Image SEO: the search, the stored scores, the cache clear. (Lane IR)
 */
final class ImageSeo
{
    public const PER_PAGE = 20;

    public const FILTERS = ['', 'not_renamed', 'low', 'missing_alt'];

    public const SORTS = ['name', 'attention', 'newest'];

    /**
     * The Find tab: one page of products, each with every picture planned.
     *
     * @param  array<string, mixed>  $q
     * @return array{items: list<array>, total: int, page: int, pages: int}
     */
    public static function find(array $q, string $strategy = ImageNamer::STRATEGY_VARIATIONS, bool $includeShared = false): array
    {
        $query = self::query($q);
        $total = (clone $query)->toBase()->getCountForPagination();
        $page = max(1, (int) ($q['page'] ?? 1));
        $products = self::sorted($query, (string) ($q['sort'] ?? 'name'))
            ->with(['brand:id,name', 'category:id,name'])
            ->forPage($page, self::PER_PAGE)
            ->get(['products.id', 'products.name', 'products.slug', 'products.sku', 'products.status', 'products.brand_id', 'products.category_id', 'products.image', 'products.images', 'products.image_alts']);

        return [
            'items' => (new ImageSeoPlanner($strategy, $includeShared))->plan($products),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
        ];
    }

    /** Product ids for "select every match", capped so one request stays bounded. */
    public static function ids(array $q, int $cap = 5000): array
    {
        return self::sorted(self::query($q), (string) ($q['sort'] ?? 'name'))
            ->limit($cap)->pluck('products.id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param array<string, mixed> $q */
    public static function query(array $q): Builder
    {
        $query = Product::query();
        $term = trim(mb_substr((string) ($q['q'] ?? ''), 0, 120));

        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $query->where(function (Builder $w) use ($like, $term): void {
                $w->where('products.name', 'like', $like)
                    ->orWhere('products.sku', 'like', $like)
                    ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', $like));

                if (ctype_digit($term)) {
                    $w->orWhere('products.id', (int) $term);
                }
            });
        }

        if (($brand = (int) ($q['brand'] ?? 0)) > 0) {
            $query->where('products.brand_id', $brand);
        }

        if (($category = (int) ($q['category'] ?? 0)) > 0) {
            $query->where(function (Builder $w) use ($category): void {
                $w->where('products.category_id', $category)
                    ->orWhereExists(fn ($s) => $s->from('category_product')->whereColumn('category_product.product_id', 'products.id')->where('category_product.category_id', $category));
            });
        }

        $filter = in_array($q['filter'] ?? '', self::FILTERS, true) ? (string) ($q['filter'] ?? '') : '';

        if ($filter === 'not_renamed' && ImageFiles::hasTable('image_renames')) {
            $query->whereNotExists(fn ($s) => $s->from('image_renames')->whereColumn('image_renames.product_id', 'products.id')->where('image_renames.status', 'done'));
        } elseif ($filter === 'missing_alt') {
            $query->where(fn (Builder $w) => $w->whereNull('products.image_alts')->orWhereIn('products.image_alts', ['', '[]', '{}', 'null']));
        } elseif ($filter === 'low' && ImageSeoPlanner::hasScore() && ImageFiles::hasTable('media_usages')) {
            $query->whereExists(fn ($s) => $s->from('media_usages')->join('media', 'media.id', '=', 'media_usages.media_id')
                ->whereColumn('media_usages.owner_id', 'products.id')->where('media_usages.owner_type', 'product')
                ->where(fn ($w) => $w->whereNull('media.seo_score')->orWhere('media.seo_score', '<', ImageScore::TICK)));
        }

        return $query;
    }

    private static function sorted(Builder $query, string $sort): Builder
    {
        if ($sort === 'attention' && ImageSeoPlanner::hasScore() && ImageFiles::hasTable('media_usages')) {
            // Lowest stored picture score first; never scored counts as lowest.
            $sub = DB::table('media_usages')->join('media', 'media.id', '=', 'media_usages.media_id')
                ->whereColumn('media_usages.owner_id', 'products.id')->where('media_usages.owner_type', 'product')
                ->selectRaw('MIN(COALESCE(media.seo_score, -1))');

            return $query->orderByRaw('COALESCE(('.$sub->toSql().'), 101) asc', $sub->getBindings())->orderBy('products.id');
        }

        if ($sort === 'newest') {
            return $query->orderByDesc('products.id');
        }

        return $query->orderBy('products.name')->orderBy('products.id');
    }

    /**
     * Store each picture's score on its Media Library row, for these products.
     * Only rows whose number moved are written.
     *
     * @param  list<int>  $ids
     */
    public static function rescore(array $ids): int
    {
        if ($ids === [] || ! ImageSeoPlanner::hasScore()) {
            return 0;
        }

        $products = Product::query()->whereIn('id', $ids)->with(['brand:id,name', 'category:id,name'])
            ->get(['id', 'name', 'slug', 'sku', 'status', 'brand_id', 'category_id', 'image', 'images', 'image_alts']);

        $want = [];

        foreach ((new ImageSeoPlanner())->plan($products) as $plan) {
            foreach ($plan['images'] as $image) {
                if ($image['rel'] !== null && ! isset($want[$image['rel']])) {
                    $want[$image['rel']] = (int) $image['score'];
                }
            }
        }

        if ($want === []) {
            return 0;
        }

        $written = 0;

        foreach (DB::table('media')->whereIn('path', array_keys($want))->get(['id', 'path', 'seo_score']) as $row) {
            $score = $want[$row->path];

            if ($row->seo_score === null || (int) $row->seo_score !== $score) {
                DB::table('media')->where('id', $row->id)->update(['seo_score' => $score]);
                $written++;
            }
        }

        return $written;
    }

    /**
     * The one-time backfill, in bounded batches: products after $after whose
     * pictures have a library row with no score yet.
     *
     * @return array{scored: int, next: int, done: bool, left: int}
     */
    public static function backfill(int $after = 0, int $batch = 100): array
    {
        if (! ImageSeoPlanner::hasScore() || ! ImageFiles::hasTable('media_usages')) {
            return ['scored' => 0, 'next' => 0, 'done' => true, 'left' => 0];
        }

        $ids = DB::table('products')->where('products.id', '>', $after)
            ->whereExists(fn ($q) => $q->from('media_usages')->join('media', 'media.id', '=', 'media_usages.media_id')
                ->whereColumn('media_usages.owner_id', 'products.id')->where('media_usages.owner_type', 'product')->whereNull('media.seo_score'))
            ->orderBy('products.id')->limit($batch)
            ->pluck('products.id')->map(fn ($id) => (int) $id)->all();

        $scored = self::rescore($ids);
        $next = $ids === [] ? 0 : max($ids);

        return ['scored' => $scored, 'next' => $next, 'done' => count($ids) < $batch, 'left' => self::unscored()];
    }

    public static function unscored(): int
    {
        if (! ImageSeoPlanner::hasScore() || ! ImageFiles::hasTable('media_usages')) {
            return 0;
        }

        return (int) DB::table('media')->whereNull('seo_score')
            ->whereExists(fn ($s) => $s->from('media_usages')->whereColumn('media_usages.media_id', 'media.id')->where('owner_type', 'product'))
            ->count();
    }

    /**
     * Keep the Media Library's score true when a product changes elsewhere
     * (the product editor, the importer). Registered once from
     * AppServiceProvider::boot(); runs only when a picture, an alt or the
     * name actually moved, and never on the shop.
     */
    public static function listen(): void
    {
        Product::updated(static function (Product $product): void {
            if (! $product->wasChanged(['image', 'images', 'image_alts', 'name', 'brand_id'])) {
                return;
            }

            try {
                self::rescore([(int) $product->id]);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * The storefront caches rendered fragments that carry image URLs: the same
     * clear WebpBulk does after re-pointing, and Platform → Cache's button.
     */
    public static function flushCaches(): void
    {
        try {
            Cache::flush();
        } catch (\Throwable $e) {
            report($e);
        }

        \App\Models\Setting::flushMap();
        \App\Services\SettingsService::forgetMemo();

        // The Google Shopping feed keys its cache on a stamp the rename's
        // query-builder writes cannot move (Lane SEO). WebpReferences already
        // bumps it on every write; said again here so an alt-only run and a
        // future writer cannot miss it. The image sitemap and the JSON-LD are
        // built from live rows on each request and hold no cache to clear.
        if (class_exists(\App\Services\Seo\MerchantFeed::class)) {
            \App\Services\Seo\MerchantFeed::forget();
        }
    }
}
