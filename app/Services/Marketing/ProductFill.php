<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\Product;
use App\Services\Mail\Kit\MailKit;
use App\Support\EffectivePrice;
use App\Support\Url;

/**
 * Product blocks that fill themselves from the catalogue (Lane MK).
 *
 * A product block stores HOW to choose (fill, order, count, a brand, a
 * category, hand-picked ids) and never a name, a price or a picture. ids()
 * answers which products that means today; cards() reads them — name, brand,
 * price, the sale price struck through, the picture, the link — at the moment
 * an email is rendered, in ONE query for every product block in the email.
 *
 * SOLD OUT OR UNPUBLISHED IS DROPPED, not advertised. Both halves check
 * Product::visible() (published, visible, its publish date arrived) and
 * inStock(), so a product that sold out between the campaign starting and
 * the 3,000th message going out simply leaves the grid of the later ones.
 */
final class ProductFill
{
    /** How many candidates a "biggest saving" order compares in PHP. */
    public const POOL = 48;

    /**
     * The product ids a block means right now, in order.
     *
     * @param  array<string, mixed>  $props  a cleaned product_row / product_grid
     * @return list<int>
     */
    public static function ids(array $props, ?int $topBrandId = null): array
    {
        $count = max(1, min(8, (int) ($props['count'] ?? 4)));
        $fill = (string) ($props['fill'] ?? 'newest');
        $order = (string) ($props['order'] ?? 'auto');

        $q = Product::query()->visible()->inStock()->select('products.*');

        switch ($fill) {
            case 'hand_picked':
                $wanted = array_values(array_map('intval', (array) ($props['product_ids'] ?? [])));

                if ($wanted === []) {
                    return [];
                }

                $live = $q->whereIn('products.id', $wanted)->pluck('products.id')->map(fn ($v) => (int) $v)->all();

                return array_slice(array_values(array_filter($wanted, fn ($id) => in_array($id, $live, true))), 0, $count);

            case 'on_sale':
                EffectivePrice::whereOnSale($q);
                break;

            case 'under_price':
                EffectivePrice::whereRange($q, null, max(1, (int) ($props['max_price'] ?? 54)) * 100);
                break;

            case 'brand':
                $q->where('products.brand_id', (int) ($props['brand_id'] ?? 0));
                break;

            case 'group_top_brand':
                if ($topBrandId !== null) {
                    $q->where('products.brand_id', $topBrandId);
                }
                // With no group chosen yet there is no top brand: the block
                // shows best sellers, and the panel says so.
                break;

            case 'category':
                $cat = (int) ($props['category_id'] ?? 0);
                $q->where(fn ($w) => $w->where('products.category_id', $cat)
                    ->orWhereIn('products.id', fn ($s) => $s->select('product_id')->from('category_product')->where('category_id', $cat)));
                break;

            case 'sets':
                $q->where('products.type', 'set');
                break;
        }

        if ($order === 'auto') {
            $order = match ($fill) {
                'newest' => 'newest',
                'on_sale' => 'biggest_saving',
                'under_price' => 'best_sellers',
                default => 'best_sellers',
            };
        }

        if ($order === 'biggest_saving') {
            $pool = $q->orderByDesc('products.total_sales')->orderByDesc('products.id')->limit(self::POOL)->get();
            $scored = [];

            foreach ($pool as $p) {
                $compare = $p->compareAtPrice();
                $price = $p->effectivePrice();
                $scored[] = [(int) $p->id, $compare !== null && $compare > 0 && $price < $compare ? ($compare - $price) / $compare : 0.0];
            }

            usort($scored, fn ($a, $b) => $b[1] <=> $a[1] ?: $b[0] <=> $a[0]);

            return array_slice(array_map(fn ($r) => $r[0], $scored), 0, $count);
        }

        match ($order) {
            'newest' => $q->orderByDesc('products.created_at')->orderByDesc('products.id'),
            'price_low' => EffectivePrice::orderBy($q, 'asc')->orderBy('products.id'),
            default => $q->orderByDesc('products.total_sales')->orderByDesc('products.id'),
        };

        return $q->limit($count)->pluck('products.id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * The cards for these ids — ONE query whatever the number of blocks —
     * keyed by id, leaving out anything no longer live.
     *
     * @param  list<int>  $ids
     * @return array<int, array{id:int, img:?string, h:int, brand:string, name:string, price:string, was:?string, href:string}>
     */
    public static function cards(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));

        if ($ids === []) {
            return [];
        }

        $rows = Product::query()->visible()->inStock()->with('brand')->whereIn('products.id', $ids)->get();
        $out = [];

        foreach ($rows as $p) {
            $price = $p->effectivePrice();
            $compare = $p->compareAtPrice();

            if ($price <= 0) {
                // A product with no price is not one to advertise.
                continue;
            }

            $out[(int) $p->id] = [
                'id' => (int) $p->id,
                'img' => MailKit::image($p->image, 400),
                'h' => MailKit::heightFor(is_string($p->image) ? $p->image : null, 200),
                'brand' => trim((string) ($p->brand?->name ?? '')),
                'name' => (string) $p->name,
                'price' => MailKit::money($price),
                'was' => $compare !== null && $compare > $price ? MailKit::money($compare) : null,
                'href' => Url::external('/product/' . $p->slug . '/'),
            ];
        }

        return $out;
    }
}
