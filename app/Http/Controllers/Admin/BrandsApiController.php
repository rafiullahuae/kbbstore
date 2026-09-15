<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
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
            ->select('brands.id', 'brands.slug', 'brands.name', 'brands.logo', 'brands.description', 'brands.position')
            ->selectRaw('COUNT(products.id) as products_count')
            ->leftJoin('products', function ($join) {
                $join->on('products.brand_id', '=', 'brands.id')
                    ->whereNull('products.deleted_at');
            })
            ->groupBy('brands.id', 'brands.slug', 'brands.name', 'brands.logo', 'brands.description', 'brands.position')
            ->orderBy('brands.position')
            ->orderBy('brands.name')
            ->get();

        return response()->json(['ok' => true, 'brands' => $brands]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);

        return response()->json(['ok' => true, 'brand' => Brand::query()->create($data)], 201);
    }

    public function update(Request $request, Brand $brand): JsonResponse
    {
        $brand->update($this->validated($request, $brand));

        return response()->json(['ok' => true, 'brand' => $brand->fresh()]);
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

        $data = $request->validate([
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
        ], [
            'slug.regex' => 'The slug may contain only lower-case letters, numbers and single hyphens.',
            'slug.unique' => 'Another brand already uses that slug.',
            'slug.required' => 'A brand needs a name it can make a slug from.',
        ]);

        $data['logo'] = $this->safeLogoUrl($data['logo'] ?? null);
        $data['position'] = (int) ($data['position'] ?? $brand?->position ?? 0);

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
