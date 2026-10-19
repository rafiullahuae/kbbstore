<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\VariantOptions;
use Illuminate\Support\Facades\DB;

/**
 * The card's shade dots and size chips for a whole grid, from ONE query.
 * (Lane VPA)
 *
 * The same shape App\Services\VariantPricing has, for the same reason: a grid
 * of 40 tiles must not become 41 queries. The first variable tile drawn in a
 * request reads every variable product's options in one statement — variants,
 * their values and the values' attributes, joined — and every later tile is an
 * array lookup. A page with no variable tile never runs it.
 *
 * FLAT AS THE GRID GROWS: three tiles or forty, one statement
 * (tests/Feature/VariantPickerTest.php renders both and compares the counts).
 * The rows it reads grow with the number of OPTIONS in the catalogue, which is
 * the same bound VariantPricing already accepts.
 *
 * Translations are TranslationStore's cached map (VariantOptions::valueName()),
 * so an Arabic grid costs no query more than an English one.
 */
class VariantSwatches
{
    /** @var array<int, list<array<string, mixed>>>|null product id => rows */
    private ?array $rows = null;

    /** @var array<int, array<string, mixed>|null> */
    private array $built = [];

    public function forget(): void
    {
        $this->rows = null;
        $this->built = [];
    }

    /** Drop the snapshot after a write. Same contract as VariantPricing::invalidate(). */
    public static function invalidate(): void
    {
        $container = app();

        if ($container->resolved(self::class)) {
            $container->make(self::class)->forget();
        }
    }

    /**
     * The picker for this card, or null for a product without one. Only a
     * variable product ever asks the database.
     *
     * @return array<string, mixed>|null
     */
    public function forCard(Product $product): ?array
    {
        if (($product->getAttributes()['type'] ?? null) !== 'variable') {
            return null;
        }

        $id = (int) $product->id;
        $key = $id.'|'.\App\Support\Locale::current();

        if (array_key_exists($key, $this->built)) {
            return $this->built[$key];
        }

        $rows = $this->rows()[$id] ?? [];

        return $this->built[$key] = $rows === [] ? null : VariantOptions::build($product, $rows);
    }

    /** What the card prints from this product's options, for CardFragments' signature. */
    public function signature(Product $product): ?string
    {
        if (($product->getAttributes()['type'] ?? null) !== 'variable') {
            return null;
        }

        $rows = $this->rows()[(int) $product->id] ?? [];

        return $rows === [] ? null : md5(serialize(array_map(
            static fn (array $r): array => [$r['model']->getAttributes(), $r['tag'], $r['values']],
            $rows,
        )));
    }

    /** @return array<int, list<array<string, mixed>>> */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $raw = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('product_variant_attribute_value as pv', 'pv.product_variant_id', '=', 'v.id')
            ->leftJoin('attribute_values as av', 'av.id', '=', 'pv.attribute_value_id')
            ->leftJoin('attributes as a', 'a.id', '=', 'av.attribute_id')
            ->where('p.type', 'variable')
            ->orderBy('v.product_id')->orderBy('v.position')->orderBy('v.id')
            ->orderBy('a.position')->orderBy('av.position')
            ->get([
                'v.id', 'v.product_id', 'v.sku', 'v.price', 'v.sale_price', 'v.stock_status', 'v.image', 'v.tag',
                'av.id as av_id', 'av.name as av_name', 'av.swatch_color as av_color', 'av.swatch_image as av_image',
                'av.position as av_pos', 'av.attribute_id as a_id', 'a.name as a_name', 'a.slug as a_slug',
                'a.position as a_pos',
            ]);

        $out = [];
        $index = [];

        foreach ($raw as $r) {
            $pid = (int) $r->product_id;
            $vid = (int) $r->id;

            if (! isset($index[$vid])) {
                $model = new ProductVariant;
                $model->setRawAttributes([
                    'id' => $vid,
                    'product_id' => $pid,
                    'sku' => $r->sku,
                    'price' => $r->price,
                    'sale_price' => $r->sale_price,
                    'stock_status' => $r->stock_status,
                    'image' => $r->image,
                ], true);
                $model->exists = true;

                $out[$pid][] = ['model' => $model, 'id' => $vid, 'tag' => $r->tag, 'values' => []];
                $index[$vid] = [$pid, count($out[$pid]) - 1];
            }

            if ($r->av_id !== null) {
                [$p, $i] = $index[$vid];
                $out[$p][$i]['values'][] = [
                    'id' => (int) $r->av_id,
                    'name' => (string) $r->av_name,
                    'color' => $r->av_color,
                    'image' => $r->av_image,
                    'pos' => (int) $r->av_pos,
                    'attr_id' => (int) $r->a_id,
                    'attr_name' => (string) ($r->a_name ?? ''),
                    'attr_slug' => (string) ($r->a_slug ?? ''),
                    'attr_pos' => (int) ($r->a_pos ?? 0),
                ];
            }
        }

        return $this->rows = $out;
    }
}
