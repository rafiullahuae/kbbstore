<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Attribute CRUD for the admin — Catalog → Attributes.
 *
 * The Attributes tab rendered a hard-coded CAT_ATTRS array — "Skin Type",
 * "Concern", "Finish" and their terms, none of which exist in this database —
 * behind buttons that raised a "(preview)" toast. The real table carries
 * pa_brands, pa_color, pa_size and pa_shades from the WooCommerce import, and
 * an owner had no way to add a fifth or to add a term to any of the four. This
 * is the endpoint that tab now talks to, shaped to match
 * Admin\BrandsApiController: same response envelope, same slug derivation,
 * same refuse-then-force delete.
 *
 * HOW ATTRIBUTES ACTUALLY RELATE TO PRODUCTS — worth stating, because the
 * screen only makes sense once it is clear and the preview implied something
 * different:
 *
 *   attributes           one global attribute, e.g. Color (`pa_color`)
 *     └─ attribute_values     its terms, e.g. Pink, Beige — `attribute_id`,
 *                             cascadeOnDelete, unique on (attribute_id, slug)
 *
 * Nothing joins a product to an ATTRIBUTE. Products join to attribute VALUES,
 * through two separate pivots:
 *
 *   product_attribute_value          (product_id, attribute_value_id)
 *       which terms a product offers at all — what the shop filters match on
 *   product_variant_attribute_value  (product_variant_id, attribute_value_id)
 *       which terms define one purchasable variant — the axis combination a
 *       shopper picks in the product page's selectors
 *
 * So a variable product offers, say, four colour values through the first
 * pivot and has four variants each pinned to one of them through the second.
 * `attributes.is_variation_axis` is the flag that says an attribute is used the
 * second way; `is_filterable` says it appears in the storefront filter panel;
 * `query_var` is the URL parameter that filter uses (filter_color), preserved
 * verbatim from WooCommerce because those URLs are live.
 *
 * The practical consequence, and the reason delete is guarded the way it is:
 * deleting an attribute value cascades to BOTH pivots, and a variant that
 * loses the value defining it is not deleted — it is left undefined, which is
 * worse, because it still sells and there is no longer anything saying which
 * colour it is.
 */
class AttributesApiController extends Controller
{
    /**
     * GET /admin-api/attributes — every attribute with its values, and how
     * much of the catalogue is standing on each.
     *
     * Four queries flat regardless of how many attributes or values exist: the
     * attributes with correlated count subqueries, the values, and one grouped
     * usage query per pivot. The preview it replaces did zero, which is how it
     * managed to be wrong.
     */
    public function index(): JsonResponse
    {
        $attributes = Attribute::query()
            ->select('attributes.id', 'attributes.slug', 'attributes.name', 'attributes.query_var',
                'attributes.is_variation_axis', 'attributes.is_filterable', 'attributes.position')
            ->selectSub(
                DB::table('attribute_values')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('attribute_values.attribute_id', 'attributes.id'),
                'values_count'
            )
            ->orderBy('attributes.position')
            ->orderBy('attributes.name')
            ->get();

        $values = AttributeValue::query()
            ->select('id', 'attribute_id', 'slug', 'name', 'swatch_color', 'swatch_image', 'position')
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        $productUse = $this->usageByValue('product_attribute_value', 'product_id');
        $variantUse = $this->usageByValue('product_variant_attribute_value', 'product_variant_id');

        $grouped = [];

        foreach ($values as $value) {
            $value->products_count = $productUse[$value->id] ?? 0;
            $value->variants_count = $variantUse[$value->id] ?? 0;
            $grouped[(int) $value->attribute_id][] = $value;
        }

        foreach ($attributes as $attribute) {
            $own = $grouped[(int) $attribute->id] ?? [];
            $attribute->values = array_values($own);
            $attribute->products_count = array_sum(array_map(fn ($v) => $v->products_count, $own));
            $attribute->variants_count = array_sum(array_map(fn ($v) => $v->variants_count, $own));
        }

        return response()->json(['ok' => true, 'attributes' => $attributes]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedAttribute($request, null);

        return response()->json(['ok' => true, 'attribute' => Attribute::query()->create($data)], 201);
    }

    public function update(Request $request, Attribute $attribute): JsonResponse
    {
        $attribute->update($this->validatedAttribute($request, $attribute));

        return response()->json(['ok' => true, 'attribute' => $attribute->fresh()]);
    }

    /**
     * DELETE /admin-api/attributes/{attribute}
     *
     * `attribute_values.attribute_id` is constrained()->cascadeOnDelete(), and
     * both pivots cascade off the value, so the database would take this
     * without complaint and silently strip every product and every variant of
     * the terms that defined them. Deleting `pa_color` on the live catalogue
     * would leave variable products selling variants nobody — including the
     * warehouse — can tell apart.
     *
     * So an attribute whose values are in use is refused with the counts, and
     * `force=1` is what the operator passes to mean it. The forced path deletes
     * the pivot rows and the values explicitly inside a transaction rather than
     * leaning on the cascade, so the same delete behaves identically on MySQL,
     * on SQLite, and on any connection where the constraints were never
     * created. No product and no variant is ever deleted — only the link is.
     */
    public function destroy(Request $request, Attribute $attribute): JsonResponse
    {
        $valueIds = $this->valueIds($attribute);
        $counts = $this->usageCounts($valueIds);
        $force = $request->boolean('force');

        if (! $force && ($counts['products_count'] > 0 || $counts['variants_count'] > 0)) {
            return response()->json([
                'ok' => false,
                'error' => 'attribute_in_use',
                'values_count' => count($valueIds),
                'products_count' => $counts['products_count'],
                'variants_count' => $counts['variants_count'],
                'message' => $this->inUseMessage($attribute->name, $counts),
            ], 422);
        }

        DB::transaction(function () use ($attribute, $valueIds) {
            $this->detachValues($valueIds);

            AttributeValue::query()->where('attribute_id', $attribute->id)->delete();

            $attribute->delete();
        });

        return response()->json([
            'ok' => true,
            'values_removed' => count($valueIds),
            'detached_products' => $counts['products_count'],
            'detached_variants' => $counts['variants_count'],
        ]);
    }

    /* ----------------------------------------------------------- values -- */

    public function storeValue(Request $request, Attribute $attribute): JsonResponse
    {
        $data = $this->validatedValue($request, $attribute, null);
        $data['attribute_id'] = $attribute->id;

        return response()->json(['ok' => true, 'value' => AttributeValue::query()->create($data)], 201);
    }

    public function updateValue(Request $request, Attribute $attribute, AttributeValue $value): JsonResponse
    {
        $this->assertOwns($attribute, $value);

        $value->update($this->validatedValue($request, $attribute, $value));

        return response()->json(['ok' => true, 'value' => $value->fresh()]);
    }

    /**
     * DELETE /admin-api/attributes/{attribute}/values/{value}
     *
     * Same protection one level down, and for the sharper version of the same
     * reason: a variant is DEFINED by its values, so removing the one value a
     * variant is pinned to leaves a purchasable variant with nothing saying
     * what it is. Refused while anything points at it; `force=1` detaches
     * inside a transaction and deletes the value only.
     */
    public function destroyValue(Request $request, Attribute $attribute, AttributeValue $value): JsonResponse
    {
        $this->assertOwns($attribute, $value);

        $counts = $this->usageCounts([(int) $value->id]);
        $force = $request->boolean('force');

        if (! $force && ($counts['products_count'] > 0 || $counts['variants_count'] > 0)) {
            return response()->json([
                'ok' => false,
                'error' => 'value_in_use',
                'products_count' => $counts['products_count'],
                'variants_count' => $counts['variants_count'],
                'message' => $this->inUseMessage($value->name, $counts),
            ], 422);
        }

        DB::transaction(function () use ($value) {
            $this->detachValues([(int) $value->id]);
            $value->delete();
        });

        return response()->json([
            'ok' => true,
            'detached_products' => $counts['products_count'],
            'detached_variants' => $counts['variants_count'],
        ]);
    }

    /* ---------------------------------------------------------- helpers -- */

    /**
     * A value reached through the wrong attribute is a 404, not a silent edit.
     *
     * Without this, /admin-api/attributes/1/values/99 would happily rename a
     * value belonging to attribute 7 — the same shape of missing ownership
     * check CLAUDE.md already records against QuizController::expertRequest.
     */
    private function assertOwns(Attribute $attribute, AttributeValue $value): void
    {
        if ((int) $value->attribute_id !== (int) $attribute->id) {
            abort(404);
        }
    }

    /** @return array<int,int> value id => distinct rows in that pivot */
    private function usageByValue(string $table, string $column): array
    {
        return DB::table($table)
            ->selectRaw('attribute_value_id, COUNT(DISTINCT ' . $column . ') as total')
            ->groupBy('attribute_value_id')
            ->pluck('total', 'attribute_value_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return list<int> */
    private function valueIds(Attribute $attribute): array
    {
        return array_map('intval', AttributeValue::query()
            ->where('attribute_id', $attribute->id)
            ->pluck('id')
            ->all());
    }

    /**
     * @param  list<int>  $valueIds
     * @return array{products_count:int,variants_count:int}
     */
    private function usageCounts(array $valueIds): array
    {
        if ($valueIds === []) {
            return ['products_count' => 0, 'variants_count' => 0];
        }

        return [
            'products_count' => (int) DB::table('product_attribute_value')
                ->whereIn('attribute_value_id', $valueIds)
                ->distinct()
                ->count('product_id'),
            'variants_count' => (int) DB::table('product_variant_attribute_value')
                ->whereIn('attribute_value_id', $valueIds)
                ->distinct()
                ->count('product_variant_id'),
        ];
    }

    /** @param list<int> $valueIds */
    private function detachValues(array $valueIds): void
    {
        if ($valueIds === []) {
            return;
        }

        DB::table('product_attribute_value')->whereIn('attribute_value_id', $valueIds)->delete();
        DB::table('product_variant_attribute_value')->whereIn('attribute_value_id', $valueIds)->delete();
    }

    /** @param array{products_count:int,variants_count:int} $counts */
    private function inUseMessage(string $name, array $counts): string
    {
        $parts = [];

        if ($counts['products_count'] > 0) {
            $parts[] = $counts['products_count'] . ' ' . Str::plural('product', $counts['products_count']);
        }

        if ($counts['variants_count'] > 0) {
            $parts[] = $counts['variants_count'] . ' ' . Str::plural('variant', $counts['variants_count']);
        }

        return $name . ' is still in use by ' . implode(' and ', $parts) .
            '. Removing it strips those terms from them; any variant it defined stays on sale with nothing left to say what it is. Nothing is deleted except the link.';
    }

    private function validatedAttribute(Request $request, ?Attribute $attribute): array
    {
        $slug = Str::slug((string) $request->input('slug') ?: (string) $request->input('name'));
        $queryVar = trim((string) $request->input('query_var'));
        $request->merge(['slug' => $slug, 'query_var' => $queryVar === '' ? null : $queryVar]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('attributes', 'slug')->ignore($attribute?->id),
            ],
            // The live filter parameter — filter_color, filter_size. Underscores
            // are the WooCommerce form and the URLs are in the wild, so the rule
            // allows them here where the slug rule does not.
            'query_var' => [
                'nullable', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
                Rule::unique('attributes', 'query_var')->ignore($attribute?->id),
            ],
            'is_variation_axis' => ['nullable', 'boolean'],
            'is_filterable' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ], [
            'slug.regex' => 'The slug may contain only lower-case letters, numbers and single hyphens.',
            'slug.unique' => 'Another attribute already uses that slug.',
            'slug.required' => 'An attribute needs a name it can make a slug from.',
            'query_var.regex' => 'The filter parameter may contain only lower-case letters, numbers and single underscores.',
            'query_var.unique' => 'Another attribute already filters on that parameter.',
        ]);

        $data['query_var'] = $data['query_var'] ?? null;
        $data['is_variation_axis'] = (bool) ($data['is_variation_axis'] ?? $attribute?->is_variation_axis ?? false);
        $data['is_filterable'] = (bool) ($data['is_filterable'] ?? $attribute?->is_filterable ?? true);
        $data['position'] = (int) ($data['position'] ?? $attribute?->position ?? 0);

        return $data;
    }

    private function validatedValue(Request $request, Attribute $attribute, ?AttributeValue $value): array
    {
        $slug = Str::slug((string) $request->input('slug') ?: (string) $request->input('name'));
        $request->merge(['slug' => $slug]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                // Unique per attribute, matching the table's own
                // unique(['attribute_id','slug']) — "large" may exist under
                // both Size and Shades, and refusing that would be wrong.
                Rule::unique('attribute_values', 'slug')
                    ->where('attribute_id', $attribute->id)
                    ->ignore($value?->id),
            ],
            'swatch_color' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'swatch_image' => ['nullable', 'string', 'max:2048'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ], [
            'slug.regex' => 'The slug may contain only lower-case letters, numbers and single hyphens.',
            'slug.unique' => 'This attribute already has a term with that slug.',
            'slug.required' => 'A term needs a name it can make a slug from.',
            'swatch_color.regex' => 'The swatch colour must be a hex value such as #E0567B.',
        ]);

        $data['swatch_image'] = $this->safeImageUrl($data['swatch_image'] ?? null);
        $data['position'] = (int) ($data['position'] ?? $value?->position ?? 0);

        return $data;
    }

    /**
     * Keep the swatch image to something that is safe as an <img src>.
     *
     * Same rule the brand logo and the category image get: the value normally
     * arrives from MediaUploadController, but the field is a plain string. A
     * data: URL of type image/svg+xml renders as a document and can carry
     * script, which is the stored-XSS shape MediaUploadController already
     * refuses for uploaded SVG. Only http(s) and site-relative paths are kept.
     */
    private function safeImageUrl(?string $image): ?string
    {
        $image = trim((string) $image);

        if ($image === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $image) === 1) {
            return $image;
        }

        if (str_starts_with($image, '/') && ! str_contains($image, '..')) {
            return $image;
        }

        throw ValidationException::withMessages([
            'swatch_image' => 'The swatch image must be an uploaded file or an http(s) URL.',
        ]);
    }
}
