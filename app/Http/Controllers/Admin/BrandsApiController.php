<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
use App\Support\PageBanner;
use App\Support\TranslationInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Brand CRUD for the admin — Catalog → Brands.
 *
 * There was no brand create/edit screen at all: the Brands tab in the admin
 * rendered a hard-coded preview array, and the only brand-aware admin
 * controller was CatalogReorderApiController, which reorders a brand's
 * products but cannot create the brand. Every brand row on the live site got
 * there through the WooCommerce import, which meant a new brand could not be
 * added without a database client.
 *
 * `logo` is a URL string, not an upload. Images go through
 * Admin\MediaUploadController (/admin-api/media/upload) exactly as the SEO
 * share image and organisation logo do — one upload path, one set of type and
 * size rules, one place where the SVG screening lives. This controller only
 * stores the URL that comes back, and checks it is one that is safe to put in
 * an `src`.
 */
class BrandsApiController extends Controller
{
    /**
     * GET /admin-api/brands — every brand, with how many products point at it.
     *
     * One grouped query rather than a count per brand: the live catalogue has
     * ninety-three brands and this screen would otherwise be ninety-four
     * queries.
     */
    public function index(): JsonResponse
    {
        $brands = Brand::query()
            ->select('brands.id', 'brands.slug', 'brands.name', 'brands.logo', 'brands.description',
                'brands.position', 'brands.seo', 'brands.banner')
            ->selectRaw('COUNT(products.id) as products_count')
            ->leftJoin('products', function ($join) {
                $join->on('products.brand_id', '=', 'brands.id')
                    ->whereNull('products.deleted_at');
            })
            ->groupBy('brands.id', 'brands.slug', 'brands.name', 'brands.logo', 'brands.description',
                'brands.position', 'brands.seo', 'brands.banner')
            ->orderBy('brands.position')
            ->orderBy('brands.name')
            ->get();

        /*
         * THE ARABIC BOXES' PREFILL. (Lane EX, T4b)
         *
         * ONE query for all ninety-three brands. translationsForEditor() is per
         * model and would have turned the screen this method was deliberately
         * written as one grouped query into ninety-four of them again.
         *
         * Drafts included — see App\Support\TranslationInput::editorMapFor.
         */
        $translations = TranslationInput::editorMapFor($brands);

        // Set as an attribute so it rides along in the JSON, and NOT saved back:
        // `translations` is also the name of the trait's relation method and
        // there is no such column, so these instances are read-only from here.
        foreach ($brands as $brand) {
            $brand->setAttribute('translations', $translations[(int) $brand->id] ?? null);
        }

        return response()->json([
            'ok' => true,
            'brands' => $brands,
            /*
             * The EMPTY shape of the Arabic boxes, for the "add" form — a row
             * being created has no translations but still has to draw a box for
             * every translatable field. Handed down from the server so the
             * screen never holds a second copy of Brand::$translatable.
             */
            'translatable' => (new Brand)->translationsForEditor(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);
        $translations = $this->translationsFrom($data);

        $brand = Brand::query()->create($data);

        // After create(), because the row has no id before it. One call, the
        // same request, both paths. See App\Support\TranslationInput.
        $brand->saveTranslations($translations);

        return response()->json(['ok' => true, 'brand' => $brand], 201);
    }

    public function update(Request $request, Brand $brand): JsonResponse
    {
        $data = $this->validated($request, $brand);
        $translations = $this->translationsFrom($data);

        $brand->update($data);
        $brand->saveTranslations($translations);

        return response()->json(['ok' => true, 'brand' => $brand->fresh()]);
    }

    /**
     * Lift the `translations` bag out of the validated data.
     *
     * It has to come OUT before the array reaches create() or update(): Brand
     * is `$guarded = []`, so a stray `translations` key would be mass assigned
     * as though it were a column.
     *
     * Nothing is named rich: the brand dialog's description is a plain
     * textarea on both sides, so there is no {!! !!} asymmetry to close here.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, string|null>>
     */
    private function translationsFrom(array &$data): array
    {
        $bag = $data['translations'] ?? [];
        unset($data['translations']);

        return TranslationInput::clean(is_array($bag) ? $bag : []);
    }

    /**
     * DELETE /admin-api/brands/{brand}
     *
     * Products carry `brand_id` as a real foreign key, declared
     * `nullable()->constrained()->nullOnDelete()`, so the database would
     * happily accept this and quietly blank the brand on every product that
     * referenced it. That is not a decision to make by accident: on the live
     * catalogue one delete could unbrand fifty products and nothing in the
     * admin would say so.
     *
     * So a brand with products attached is refused, with the count, and the
     * operator has to pass `force=1` to mean it. The forced path nulls the
     * column explicitly inside a transaction rather than leaning on the FK
     * action — the same delete then behaves identically on MySQL, on SQLite,
     * and on any connection where the constraint was never created.
     */
    public function destroy(Request $request, Brand $brand): JsonResponse
    {
        $attached = Product::query()->where('brand_id', $brand->id)->count();
        $force = $request->boolean('force');

        if ($attached > 0 && ! $force) {
            return response()->json([
                'ok' => false,
                'error' => 'brand_in_use',
                'products_count' => $attached,
                'message' => $attached . ' ' . Str::plural('product', $attached) . ' still ' .
                    ($attached === 1 ? 'belongs' : 'belong') . ' to this brand. Deleting it leaves ' .
                    ($attached === 1 ? 'that product' : 'those products') . ' with no brand.',
            ], 422);
        }

        DB::transaction(function () use ($brand) {
            Product::query()->where('brand_id', $brand->id)->update(['brand_id' => null]);
            $brand->delete();
        });

        return response()->json(['ok' => true, 'unbranded' => $attached]);
    }

    /**
     * Shared rules for create and edit.
     *
     * The slug is derived from the name when the operator leaves it blank,
     * and the derived value is validated too — otherwise a second "Beauty of
     * Joseon" would skip the uniqueness rule entirely and surface as a
     * QueryException, i.e. a 500 on a duplicate name. It is put back on the
     * request before validation so the failure is reported against `slug`,
     * which is where the form already shows errors.
     */
    private function validated(Request $request, ?Brand $brand): array
    {
        $slug = Str::slug((string) $request->input('slug') ?: (string) $request->input('name'));
        $request->merge(['slug' => $slug]);

        $english = [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255',
                // Lower-case words joined by single hyphens. The slug is a URL
                // segment (/korean-skincare-brands/{slug}/) and a shop filter
                // value, so anything else either does not round-trip or has to
                // be encoded at every use site.
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('brands', 'slug')->ignore($brand?->id),
            ],
            'logo' => ['nullable', 'string', 'max:2048'],
            'description' => ['nullable', 'string', 'max:5000'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            // Bounded deliberately, and to the same lengths the category
            // screen uses. These land in a <title> and a
            // <meta name="description">, where anything past roughly 60 and
            // 160 characters is truncated by the search engine anyway.
            'seo' => ['nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:255'],
            'seo.description' => ['nullable', 'string', 'max:500'],
            // The banner bag is validated as a shape only. Every field inside
            // it is clamped by App\Support\PageBanner::sanitize(), which is
            // also what the storefront reads it back through, so there is one
            // definition of what a banner is rather than a validation rule
            // here and a renderer somewhere else that disagree.
            'banner' => ['nullable', 'array'],
        ];

        /*
         * The Arabic boxes, shape-validated off the English rules above rather
         * than restated. Required-ness does not carry: blank Arabic means "not
         * translated yet" and deletes the row — which is exactly how a brand
         * name like Anua, deliberately identical in both languages, stays
         * distinguishable from one nobody has reached yet. Typing it in is what
         * says "translated".
         */
        $data = $request->validate($english + TranslationInput::rules(new Brand, $english), [
            'slug.regex' => 'The slug may contain only lower-case letters, numbers and single hyphens.',
            'slug.unique' => 'Another brand already uses that slug.',
            'slug.required' => 'A brand needs a name it can make a slug from.',
        ]);

        $data['logo'] = $this->safeLogoUrl($data['logo'] ?? null);
        $data['position'] = (int) ($data['position'] ?? $brand?->position ?? 0);

        // Only the two keys the screen edits are kept, and empties are dropped
        // rather than stored as "". A stored empty title is not the same as no
        // title: the page would render an empty <title> instead of falling
        // back to the brand name.
        $seo = array_filter([
            'title' => trim((string) ($data['seo']['title'] ?? '')),
            'description' => trim((string) ($data['seo']['description'] ?? '')),
        ], fn ($v) => $v !== '');

        $data['seo'] = $seo === [] ? null : $seo;
        $data['banner'] = PageBanner::sanitize($data['banner'] ?? null);

        return $data;
    }

    /**
     * Keep the logo to something that is safe as an <img src>.
     *
     * The value normally arrives straight from MediaUploadController, but the
     * field is a plain string and the screen also lets an operator paste a URL
     * by hand. `javascript:` in an `src` is inert, but `data:` is not — a
     * data: URL of type image/svg+xml renders as a document and can carry
     * script, which is the same stored-XSS shape MediaUploadController already
     * refuses for uploaded SVG. Only http(s) and site-relative paths are kept.
     */
    private function safeLogoUrl(?string $logo): ?string
    {
        $logo = trim((string) $logo);

        if ($logo === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $logo) === 1) {
            return $logo;
        }

        // A site-relative path: one leading slash, and no "..", so a stored
        // value cannot be walked outside the web root by whatever consumes it.
        if (str_starts_with($logo, '/') && ! str_contains($logo, '..')) {
            return $logo;
        }

        throw ValidationException::withMessages([
            'logo' => 'The logo must be an uploaded image or an http(s) URL.',
        ]);
    }
}
