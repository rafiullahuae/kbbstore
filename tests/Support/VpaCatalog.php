<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Real-looking variable products for Lane VPA's tests and pictures.
 *
 *   Missha M Perfect Cover BB Cream   3 shades, each with its own price
 *   TIRTIR Mask Fit Red Cushion       9 shades, four sold out, one on sale
 *   Anua Heartleaf 77% Toner          two sizes in ml (a size axis, pills)
 *
 * Shade colours are the same approximations docs/variant-options used.
 */
final class VpaCatalog
{
    /** @return array{missha: Product, tirtir: Product, anua: Product, shade: Attribute, size: Attribute, category: Category} */
    public static function seed(?string $image = null): array
    {
        $category = Category::firstOrCreate(['slug' => 'vpa-makeup'], ['name' => 'Makeup']);
        $shade = Attribute::firstOrCreate(['slug' => 'shade'], ['name' => 'Shade', 'is_variation_axis' => true, 'position' => 1]);
        $size = Attribute::firstOrCreate(['slug' => 'size'], ['name' => 'Size', 'is_variation_axis' => true, 'position' => 2]);

        $missha = self::product('missha', 'MISSHA - M Perfect Cover BB Cream SPF 42 PA+++(50ml)', 'MISSHA', $category, $image);
        foreach ([['#13 Bright Beige', '#F1D2B6', 13900, true], ['#21 Light Beige', '#E8C3A0', 12500, true], ['#27 Honey Beige', '#D3A47A', 12800, true]] as $i => [$n, $hex, $p, $in]) {
            self::variant($missha, $shade, $n, $hex, $p, null, $in, 'MS-BB-'.($i + 1), $i);
        }

        $tirtir = self::product('tirtir', 'TIRTIR - Mask Fit Red Cushion Foundation (9 Shades)', 'TIRTIR', $category, $image);
        $shades = [
            ['17C Porcelain', '#F3D9C7', false], ['21N Ivory', '#EED0B3', true], ['21W Natural Ivory', '#EDCBA6', false],
            ['23N Sand', '#E2BC98', true], ['24N Latte', '#D9B08A', true], ['24W Soft Beige', '#DDB385', false],
            ['25N Mocha', '#CFA27A', true], ['27N Camel', '#C2925F', false], ['29N Natural Beige', '#B4835A', true],
        ];
        foreach ($shades as $i => [$n, $hex, $in]) {
            self::variant($tirtir, $shade, $n, $hex, 14500, $i === 3 ? 12900 : null, $in, 'TT-MF-'.str_replace(' ', '', $n), $i);
        }

        $anua = self::product('anua', 'Anua - Heartleaf 77% Soothing Toner', 'Anua', $category, $image);
        foreach ([['250 ml', 8900, true], ['500 ml', 14900, true]] as $i => [$n, $p, $in]) {
            self::variant($anua, $size, $n, null, $p, null, $in, 'AN-HL-'.(int) $n, $i);
        }

        return compact('missha', 'tirtir', 'anua', 'shade', 'size', 'category');
    }

    private static function product(string $key, string $name, string $brand, Category $category, ?string $image): Product
    {
        $b = Brand::firstOrCreate(['slug' => 'vpa-'.strtolower($brand)], ['name' => $brand]);
        $p = Product::create([
            'slug' => 'vpa-'.$key, 'name' => $name, 'brand_id' => $b->id, 'type' => 'variable',
            'price' => null, 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
            'image' => $image,
        ]);
        $p->categories()->attach($category->id);

        return $p;
    }

    private static function variant(Product $p, Attribute $axis, string $name, ?string $hex, int $price, ?int $sale, bool $in, string $sku, int $pos): ProductVariant
    {
        $value = AttributeValue::firstOrCreate(
            ['attribute_id' => $axis->id, 'slug' => \Illuminate\Support\Str::slug($name) ?: 'v'.$pos],
            ['name' => $name, 'swatch_color' => $hex, 'position' => $pos],
        );
        $v = ProductVariant::create([
            'product_id' => $p->id, 'sku' => $sku, 'price' => $price, 'sale_price' => $sale,
            'stock_status' => $in ? 'instock' : 'outofstock', 'manage_stock' => true, 'stock' => $in ? 12 : 0,
            'position' => $pos,
        ]);
        $v->attributeValues()->attach($value->id);
        $p->attributeValues()->syncWithoutDetaching([$value->id]);

        return $v;
    }
}
