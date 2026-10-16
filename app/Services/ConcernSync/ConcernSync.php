<?php

declare(strict_types=1);

namespace App\Services\ConcernSync;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Catalog → Shop by Concern → Sync. (Lane SC)
 *
 * The seven "Shop by Skin Concern" collections are ordinary categories
 * (2.60.506, database/migrations/2027_11_01_100000_shop_by_concern_products.php)
 * and membership is a category_product row. This class finds the products in
 * none of them and adds the links the owner ticks. IT ONLY ADDS: an existing
 * link keeps its category_position, nothing is ever deleted, and a new link
 * carries category_position NULL so a hand-ordered collection keeps its order
 * on top -- exactly what the 2.60.506 migration and the admin's own tick do.
 *
 * Cost is flat in the catalogue: one query for the concerns, one for a page of
 * products (NOT EXISTS against category_product), one each for their
 * categories and brands, one count. ConcernSyncTest renders 3 and 40 products
 * and asserts the same query count.
 */
final class ConcernSync
{
    /** The shop's concern collections by slug, in the order the homepage chips use. */
    public const CONCERNS = [
        'acne-blemish-care' => 'Acne & Blemish Care',
        'pigmentation' => 'Pigmentation',
        'anti-aging' => 'Anti-Aging',
        'hydration-glow' => 'Hydration & Glow',
        'pore-care' => 'Pore Care',
        'oily-skin' => 'Oily Skin',
        'redness-sensitive-skin' => 'Redness & Sensitive Skin',
    ];

    /** Products per Sync: two Claude batches, one screen to read. */
    public const PAGE = 40;

    /** Characters of each text field sent anywhere. */
    public const TEXT_CAP = 1500;

    /**
     * The concern categories this shop has: slug => ['id' => int, 'name' => string].
     *
     * @return array<string, array{id:int, name:string}>
     */
    public static function concerns(): array
    {
        $rows = DB::table('categories')->whereIn('slug', array_keys(self::CONCERNS))->get(['id', 'slug', 'name']);
        $out = [];

        foreach (array_keys(self::CONCERNS) as $slug) {
            foreach ($rows as $row) {
                if ($row->slug === $slug) {
                    $out[$slug] = ['id' => (int) $row->id, 'name' => html_entity_decode((string) $row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
                }
            }
        }

        return $out;
    }

    /** How many published products are in no concern. */
    public static function pendingCount(array $concerns): int
    {
        return self::unassignedQuery($concerns)->count();
    }

    /**
     * One page of products in no concern, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function unassigned(array $concerns, ?int $beforeId = null, int $limit = self::PAGE): array
    {
        $query = self::unassignedQuery($concerns)
            ->select(['id', 'name', 'slug', 'brand_id', 'image', 'short_description', 'description', 'ingredients'])
            ->with(['categories:id,name', 'brand:id,name'])
            ->orderByDesc('id')
            ->limit(max(1, min($limit, self::PAGE)));

        if ($beforeId !== null && $beforeId > 0) {
            $query->where('id', '<', $beforeId);
        }

        $out = [];

        foreach ($query->get() as $p) {
            $image = (string) ($p->image ?? '');
            $safe = preg_match('#^(https?://|/)[^\s"\'<>]*$#i', $image) === 1 && ! str_starts_with($image, '//') ? $image : '';

            $out[] = [
                'id' => (int) $p->id,
                'name' => self::plain((string) $p->name, 300),
                'slug' => (string) $p->slug,
                'brand' => $p->brand ? self::plain((string) $p->brand->name, 120) : '',
                'image' => $safe,
                'categories' => $p->categories->map(fn ($c) => self::plain((string) $c->name, 120))->values()->all(),
                'short' => self::plain((string) $p->short_description),
                'description' => self::plain((string) $p->description),
                'ingredients' => self::plain((string) ($p->ingredients ?? '')),
            ];
        }

        return $out;
    }

    /**
     * Add the ticked links. ONLY ADDS. Unknown concern slugs and product ids
     * are refused by the caller's validation and ignored again here.
     *
     * @param  array<int, list<string>>  $links  product id => concern slugs
     * @return array{added:int, already:int}
     */
    public static function apply(array $concerns, array $links): array
    {
        $productIds = array_values(array_unique(array_map('intval', array_keys($links))));
        $known = $productIds === [] ? [] : array_flip(DB::table('products')->whereIn('id', $productIds)->pluck('id')->map(fn ($v) => (int) $v)->all());
        $catIds = array_column($concerns, 'id');

        $have = [];
        if ($known !== [] && $catIds !== []) {
            foreach (DB::table('category_product')->whereIn('category_id', $catIds)->whereIn('product_id', array_keys($known))->get(['category_id', 'product_id']) as $r) {
                $have[(int) $r->category_id.':'.(int) $r->product_id] = true;
            }
        }

        $rows = [];
        $already = 0;

        foreach ($links as $pid => $slugs) {
            $pid = (int) $pid;
            if (! isset($known[$pid])) {
                continue;
            }

            foreach (array_unique($slugs) as $slug) {
                if (! isset($concerns[$slug])) {
                    continue;
                }

                $key = $concerns[$slug]['id'].':'.$pid;
                if (isset($have[$key])) {
                    $already++;
                    continue;
                }

                $have[$key] = true;
                $rows[] = ['category_id' => $concerns[$slug]['id'], 'product_id' => $pid];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('category_product')->insertOrIgnore($chunk);
        }

        if ($rows !== []) {
            self::flushCaches();
        }

        return ['added' => count($rows), 'already' => $already];
    }

    /** Tags out, entities decoded, whitespace collapsed, capped. */
    public static function plain(string $html, int $cap = self::TEXT_CAP): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
        $text = html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</li>'], [' <br', ' </p>', ' </li>'], $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, $cap);
    }

    private static function unassignedQuery(array $concerns)
    {
        $ids = array_column($concerns, 'id');

        return Product::query()
            ->where('status', 'publish')
            ->when($ids !== [], fn ($q) => $q->whereNotExists(function ($sub) use ($ids) {
                $sub->selectRaw('1')->from('category_product')
                    ->whereColumn('category_product.product_id', 'products.id')
                    ->whereIn('category_product.category_id', $ids);
            }));
    }

    private static function flushCaches(): void
    {
        try {
            \App\Http\Controllers\Store\ShopController::flushSidebarCache();
            \App\Support\CategoryRollup::flush();
            Cache::forget('kbb.home.cats');
            Cache::forget('kbb.home.rails');
        } catch (\Throwable) {
            // A cache that cannot forget expires on its own.
        }
    }
}
