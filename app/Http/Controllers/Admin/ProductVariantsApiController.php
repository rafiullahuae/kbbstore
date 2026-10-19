<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\VariantPricing;
use App\Services\VariantSwatches;
use App\Support\Money;
use App\Support\VariantOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Catalog -> Product editor -> Options: a variable product's shades and sizes.
 * (Lane VPA)
 *
 * Until this lane nothing in the admin could create or change a variant —
 * they arrived from the WooCommerce import and stayed as they came. The owner
 * chose a picker for the shop ("card A", "page A"), and a picker is only as
 * good as the options behind it, so this is where they are made: which axes
 * (Shade, Size), one row per option with its value on each axis, its own SKU,
 * price, sale price, stock and picture, and the swatch colour of each shade.
 *
 *   GET /admin-api/product-variants/{id}   catalog.view
 *   PUT /admin-api/product-variants/{id}   catalog.variants
 *
 * PUT replaces the product's whole option list in one transaction: rows sent
 * with an id are updated (and must belong to this product), rows without one
 * are created, rows not sent are removed. A basket line holding a removed
 * option goes with it (cart_items cascades); an ORDER line keeps its snapshot
 * (order_items nulls the link), so no past order changes what it says.
 *
 * Every value is checked here, never trusted from the screen: whole dirhams;
 * a sale price below the price; a stock status from the shop's own list; a
 * value that belongs to its axis; one value per axis per option and no two
 * options alike; a picture that is an upload or an http(s) address; a swatch
 * colour that is #RRGGBB.
 */
class ProductVariantsApiController extends Controller
{
    private const STATUSES = ['instock', 'outofstock', 'onbackorder'];

    private const MAX_OPTIONS = 120;

    private const MAX_AED = 1000000;

    public function show(int $id): JsonResponse
    {
        $product = Product::query()->find($id, ['id', 'name', 'type']);

        if ($product === null) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json($this->payload($product));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::query()->find($id, ['id', 'name', 'type', 'price', 'sale_price', 'sale_starts_at', 'sale_ends_at']);

        if ($product === null) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        if ($product->type !== 'variable') {
            return response()->json(['message' => 'Set Product type to "Variable product — has options" and save first.'], 422);
        }

        $data = $request->validate([
            'axes' => ['present', 'array', 'max:3'],
            'axes.*' => ['integer', 'distinct'],
            'variants' => ['present', 'array', 'max:'.self::MAX_OPTIONS],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.values' => ['present', 'array'],
            'variants.*.sku' => ['nullable', 'string', 'max:100'],
            // Whole dirhams as typed; an imported price with fils is shown
            // and saved back exactly, never rounded by opening the editor.
            'variants.*.price_aed' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'variants.*.sale_aed' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'variants.*.stock_status' => ['required', 'string'],
            'variants.*.manage_stock' => ['nullable', 'boolean'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'variants.*.image' => ['nullable', 'string', 'max:2048'],
            'swatches' => ['nullable', 'array', 'max:500'],
            'swatches.*' => ['nullable', 'string', 'max:9'],
        ], [
            'variants.*.price_aed.required' => 'Every option needs a price.',
            'variants.*.price_aed.regex' => 'Prices are whole dirhams — no decimals, no symbol, no commas.',
            'variants.*.sale_aed.regex' => 'Sale prices are whole dirhams — no decimals, no symbol, no commas.',
        ]);

        $axisIds = array_map('intval', $data['axes']);
        $axes = Attribute::query()->whereIn('id', $axisIds)->get(['id', 'name', 'slug'])->keyBy('id');

        if ($axes->count() !== count($axisIds)) {
            throw ValidationException::withMessages(['axes' => 'One of the axes no longer exists. Reload the editor.']);
        }

        if ($data['variants'] !== [] && $axisIds === []) {
            throw ValidationException::withMessages(['axes' => 'Choose at least one axis — Shade, Size — for the options.']);
        }

        $existing = ProductVariant::query()->where('product_id', $product->id)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $known = AttributeValue::query()->whereIn('attribute_id', $axisIds)->get(['id', 'attribute_id', 'name', 'slug'])->keyBy('id');

        // ── Check every row before writing any. ─────────────────────────────
        $rows = [];
        $seen = [];

        foreach ($data['variants'] as $n => $row) {
            $label = 'Option '.($n + 1);
            $vid = isset($row['id']) ? (int) $row['id'] : null;

            if ($vid !== null && ! in_array($vid, $existing, true)) {
                throw ValidationException::withMessages(["variants.$n.id" => "$label does not belong to this product. Reload the editor."]);
            }

            if (! in_array($row['stock_status'], self::STATUSES, true)) {
                throw ValidationException::withMessages(["variants.$n.stock_status" => "$label: choose In stock, Out of stock or On backorder."]);
            }

            $price = Money::fromMajor((string) $row['price_aed']);
            $sale = isset($row['sale_aed']) && $row['sale_aed'] !== '' ? Money::fromMajor((string) $row['sale_aed']) : null;

            if ($price > Money::fromMajor(self::MAX_AED) || ($sale !== null && $sale >= $price)) {
                throw ValidationException::withMessages(["variants.$n.sale_aed" => "$label: the sale price must be below the price."]);
            }

            $values = [];

            foreach ($axisIds as $aid) {
                $raw = $row['values'][$aid] ?? $row['values'][(string) $aid] ?? null;

                if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
                    $value = $known[(int) $raw] ?? null;

                    if ($value === null || (int) $value->attribute_id !== $aid) {
                        throw ValidationException::withMessages(["variants.$n.values" => "$label: that {$axes[$aid]->name} is not one of its options."]);
                    }

                    $values[$aid] = (int) $value->id;
                } elseif (is_string($raw) && trim($raw) !== '' && mb_strlen(trim($raw)) <= 80) {
                    $values[$aid] = 'new:'.trim($raw);
                } else {
                    throw ValidationException::withMessages(["variants.$n.values" => "$label: choose its {$axes[$aid]->name}."]);
                }
            }

            $key = implode('|', array_map(static fn ($v) => is_int($v) ? (string) $v : mb_strtolower((string) $v), $values));

            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["variants.$n.values" => "$label is the same as option ".($seen[$key] + 1).'. Each option needs its own shade or size.']);
            }

            $seen[$key] = $n;

            $rows[] = [
                'id' => $vid,
                'values' => $values,
                'sku' => isset($row['sku']) && trim((string) $row['sku']) !== '' ? trim((string) $row['sku']) : null,
                'price' => $price,
                'sale_price' => $sale,
                'stock_status' => $row['stock_status'],
                'manage_stock' => (bool) ($row['manage_stock'] ?? false),
                'stock' => isset($row['stock']) ? (int) $row['stock'] : null,
                'image' => $this->safeImage($row['image'] ?? null, $n),
                'position' => $n,
            ];
        }

        $swatches = [];

        foreach (($data['swatches'] ?? []) as $valueId => $hex) {
            $value = $known[(int) $valueId] ?? null;

            if ($value === null) {
                continue;
            }

            $hex = trim((string) $hex);

            if ($hex !== '' && VariantOptions::hex($hex) === null) {
                throw ValidationException::withMessages(['swatches' => "The colour for {$value->name} must be a hex value such as #E0567B."]);
            }

            $swatches[(int) $valueId] = $hex === '' ? null : VariantOptions::hex($hex);
        }

        // ── Write. ──────────────────────────────────────────────────────────
        DB::transaction(function () use ($product, $rows, $axisIds, $axes, $existing, $swatches) {
            $kept = [];
            $used = [];

            foreach ($rows as $row) {
                $valueIds = [];

                foreach ($row['values'] as $aid => $v) {
                    if (is_string($v)) {
                        $name = substr($v, 4);
                        $slug = Str::slug($name) ?: 'option-'.substr(md5($name), 0, 8);
                        $v = (int) AttributeValue::query()->firstOrCreate(
                            ['attribute_id' => $aid, 'slug' => $slug],
                            ['name' => $name, 'position' => (int) AttributeValue::query()->where('attribute_id', $aid)->max('position') + 1],
                        )->id;
                    }

                    $valueIds[] = $v;
                    $used[$v] = true;
                }

                $fields = collect($row)->except(['id', 'values'])->all() + ['product_id' => $product->id];
                $variant = $row['id'] !== null
                    ? tap(ProductVariant::query()->findOrFail($row['id']))->update($fields)
                    : ProductVariant::query()->create($fields);

                $variant->attributeValues()->sync($valueIds);
                $kept[] = (int) $variant->id;
            }

            $gone = array_values(array_diff($existing, $kept));

            if ($gone !== []) {
                ProductVariant::query()->whereIn('id', $gone)->get()->each->delete();
            }

            // The product offers exactly the values its options use, on these
            // axes; its other attributes (filters) are left as they were.
            $axisValueIds = AttributeValue::query()->whereIn('attribute_id', $axisIds)->pluck('id')->all();
            DB::table('product_attribute_value')->where('product_id', $product->id)
                ->whereIn('attribute_value_id', $axisValueIds)->delete();
            DB::table('product_attribute_value')->insertOrIgnore(array_map(
                static fn (int $v) => ['product_id' => $product->id, 'attribute_value_id' => $v],
                array_keys($used),
            ));

            Attribute::query()->whereIn('id', $axisIds)->where('is_variation_axis', false)->update(['is_variation_axis' => true]);

            foreach ($swatches as $valueId => $hex) {
                AttributeValue::query()->whereKey($valueId)->first()?->update(['swatch_color' => $hex]);
            }

            // A product with no option left in stock is sold out on the grid,
            // where the parent row is what the card reads.
            $anyIn = ProductVariant::query()->where('product_id', $product->id)->where('stock_status', 'instock')->exists();
            $product->forceFill(['stock_status' => $anyIn || $rows === [] ? 'instock' : 'outofstock']);

            // A product sold by its options is priced by them: the card's
            // "AED 125 – AED 139" and every basket line read the options, and
            // VariantPricing reads them only while the parent's own price is
            // empty. A price left on the parent from before it had options
            // would quietly stand in for all of them on the grid.
            if ($rows !== []) {
                $product->forceFill(['price' => null, 'sale_price' => null]);
            }

            $product->save();
        });

        // Pivot writes fire no model events: drop both snapshots by hand.
        VariantPricing::invalidate();
        VariantSwatches::invalidate();

        return response()->json(['ok' => true] + $this->payload($product));
    }

    /** @return array<string, mixed> */
    private function payload(Product $product): array
    {
        $variants = ProductVariant::query()->where('product_id', $product->id)->orderBy('position')->orderBy('id')
            ->with('attributeValues:id,attribute_id,name')
            ->get();

        $attributes = Attribute::query()->orderBy('position')->orderBy('name')
            ->with(['values' => fn ($q) => $q->select('id', 'attribute_id', 'name', 'swatch_color', 'position')])
            ->get(['id', 'name', 'slug', 'position']);

        $axes = [];

        foreach ($variants as $v) {
            foreach ($v->attributeValues as $val) {
                $axes[(int) $val->attribute_id] = true;
            }
        }

        return [
            'product' => ['id' => (int) $product->id, 'name' => (string) $product->name, 'type' => (string) $product->type],
            'currency' => Money::currency(),
            'axes' => array_keys($axes),
            'attributes' => $attributes->map(fn (Attribute $a) => [
                'id' => (int) $a->id,
                'name' => (string) $a->name,
                'colour' => preg_match('/\b(colou?rs?|shades?|tones?|tints?|hues?)\b/i', $a->name.' '.$a->slug) === 1
                    || $a->values->contains(fn ($v) => VariantOptions::hex($v->swatch_color) !== null),
                'values' => $a->values->map(fn ($v) => [
                    'id' => (int) $v->id,
                    'name' => (string) $v->name,
                    'swatch_color' => VariantOptions::hex($v->swatch_color),
                ])->values(),
            ])->values(),
            'variants' => $variants->map(fn (ProductVariant $v) => [
                'id' => (int) $v->id,
                'values' => $v->attributeValues->mapWithKeys(fn ($val) => [(int) $val->attribute_id => (int) $val->id]),
                'sku' => (string) ($v->sku ?? ''),
                'price_aed' => $this->major($v->price),
                'sale_aed' => $this->major($v->sale_price),
                'stock_status' => (string) $v->stock_status,
                'manage_stock' => (bool) $v->manage_stock,
                'stock' => $v->stock === null ? null : (int) $v->stock,
                'image' => (string) ($v->image ?? ''),
            ])->values(),
        ];
    }

    /** 13900 -> "139", 9980 -> "99.80", null -> "". */
    private function major(mixed $fils): string
    {
        if ($fils === null) {
            return '';
        }

        $fils = (int) $fils;

        return $fils % 100 === 0 ? (string) intdiv($fils, 100) : Money::decimalString($fils);
    }

    private function safeImage(mixed $image, int $n): ?string
    {
        $image = trim((string) $image);

        if ($image === '') {
            return null;
        }

        if (preg_match('#^https?://[^\s"\'<>]+$#i', $image) === 1) {
            return $image;
        }

        if (str_starts_with($image, '/') && ! str_starts_with($image, '//') && ! str_contains($image, '..') && preg_match('#^[^\s"\'<>]+$#', $image) === 1) {
            return $image;
        }

        throw ValidationException::withMessages(["variants.$n.image" => 'Option '.($n + 1).': the picture must be an uploaded file or an http(s) address.']);
    }
}
