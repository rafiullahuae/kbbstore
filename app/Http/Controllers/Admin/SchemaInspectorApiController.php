<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Support\Money;
use App\Support\Seo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Schema Inspector — lets an admin see the real, actual JSON-LD a page
 * would output, without needing to view-source a live page and hunt
 * through it by hand. Builds the exact same context shape the real
 * controllers do (mirroring ShopController/ProductController's own ctx
 * construction) and calls the same Seo::inspect() every real page uses,
 * so what's shown here can never drift from what actually ships.
 */
class SchemaInspectorApiController extends Controller
{
    public function inspect(Request $request): JsonResponse
    {
        $type = $request->query('type', 'product');
        $slug = trim((string) $request->query('slug', ''));
        $base = rtrim((string) (\App\Models\Setting::map()['site_url'] ?? ''), '/');

        $ctx = match ($type) {
            'product' => $this->productCtx($slug, $base),
            'category' => $this->categoryCtx($slug, $base),
            /*
             * `collection`, not `website`, since the listings started
             * publishing a CollectionPage of their own. This class's own header
             * promises that "what's shown here can never drift from what
             * actually ships", and an inspector reporting `website` for a page
             * that ships `CollectionPage` is that promise broken. See
             * categoryCtx() for the one thing this preview still does not
             * reproduce, and for how it says so.
             */
            'shop' => ['type' => 'collection', 'title' => 'Shop all', 'url' => $base . '/shop/'],
            'home' => ['type' => 'home'],
            default => null,
        };

        if ($ctx === null) {
            return response()->json(['ok' => false, 'message' => 'Unknown type.'], 422);
        }

        if (isset($ctx['error'])) {
            return response()->json(['ok' => false, 'message' => $ctx['error']], 404);
        }

        $nodes = Seo::inspect($ctx);

        return response()->json([
            'ok' => true,
            'nodes' => $nodes,
            'warnings' => $this->warnings($nodes),
        ]);
    }

    private function productCtx(string $slug, string $base): array
    {
        if ($slug === '') {
            return ['error' => 'Enter a product slug.'];
        }

        $product = Product::query()
            // `variants` eager-loaded, so the inspector costs one query
            // for the options rather than one per option.
            ->with(['categories:id,name,slug,path', 'variants' => fn ($q) => $q->orderBy('position')])
            ->where('slug', $slug)
            ->first();

        if ($product === null) {
            return ['error' => "No product with slug \"{$slug}\"."];
        }

        $trail = [
            ['name' => 'Home', 'url' => $base . '/'],
            ['name' => 'Shop', 'url' => $base . '/shop/'],
        ];
        $category = $product->categories->first();
        if ($category) {
            $trail[] = ['name' => $category->name, 'url' => $base . $category->url()];
        }
        $trail[] = ['name' => $product->name, 'url' => $base . $product->url()];

        // Same per-product override the real page reads (ProductController)
        // — an inspector that skipped this would show a different result
        // than what a visitor's browser actually receives for a product
        // with an override set, defeating the entire point of the tool.
        $override = is_array($product->seo) ? $product->seo : [];

        $ctx = [
            'type' => 'product',
            'title' => $override['title'] ?? $product->name,
            'description' => $override['desc'] ?? $product->short_description ?? '',
            'image' => $override['og_image'] ?? $product->image,
            'url' => !empty($override['canonical']) ? $override['canonical'] : ($base . $product->url()),
            'breadcrumb' => $trail,
            'noindex' => !empty($override['noindex']),
            'product' => [
                'name' => $product->name,
                'brand' => $product->brand?->name,
                'sku' => $product->sku,
                /*
                 * gtin and variants, because this screen's whole promise is
                 * that nothing shown here can drift from what ships. Both
                 * reached the real Product node in the same release; leaving
                 * them out of the preview would make the inspector quietly
                 * wrong about the two newest fields on it, which is worse than
                 * having no inspector -- an owner checks this screen instead of
                 * viewing source precisely so they do not have to.
                 *
                 * App\Support\Seo re-validates the check digit, so a bad
                 * stored GTIN shows here as absent exactly as it ships absent.
                 */
                'gtin' => $product->gtin,
                'variants' => $product->variants
                    ->map(fn ($variant) => [
                        'price' => Money::decimalString($variant->effectivePrice()),
                        'price_minor' => $variant->effectivePrice(),
                        'sku' => $variant->sku,
                        'stock_status' => $variant->stock_status,
                    ])
                    ->all(),
                'price_aed' => Money::toAed($product->effectivePrice()),
                'stock' => $product->stock_status === 'instock' ? 1 : 0,
                'rating' => $product->rating ?: null,
                'reviews' => $product->review_count ?: null,
            ],
        ];

        return $ctx;
    }

    private function categoryCtx(string $slug, string $base): array
    {
        if ($slug === '') {
            return ['error' => 'Enter a category slug.'];
        }

        $category = Category::where('slug', $slug)->first();

        if ($category === null) {
            return ['error' => "No category with slug \"{$slug}\"."];
        }

        /*
         * NO ItemList HERE, AND THE WARNING BELOW SAYS SO OUT LOUD.
         *
         * The live archive's list is the page window ShopController produces --
         * its visibility predicate, its facets, its sort, its per-page setting
         * and its clamped page number. Rebuilding any of that here would be a
         * SECOND copy of the query, and a second copy that drifts is worse than
         * no copy: this screen exists to be trusted about what ships.
         *
         * So the preview shows the page-level node, which is what an owner
         * checking their title, description and canonical came for, and
         * warnings() states plainly that the products are not in it.
         */
        return [
            'type' => 'collection',
            'title' => $category->name,
            'url' => $base . $category->url(),
            'breadcrumb' => [
                ['name' => 'Home', 'url' => $base . '/'],
                ['name' => 'Shop', 'url' => $base . '/shop/'],
                ['name' => $category->name, 'url' => $base . $category->url()],
            ],
        ];
    }

    /**
     * Plain, pattern-level checks — not a full schema.org validator, just
     * the handful of things genuinely worth an admin's attention: a
     * Product node with no offer at all, or an offer with no price, since
     * either one means Google has nothing to show as a price in search
     * results for that page.
     */
    private function warnings(array $nodes): array
    {
        $warnings = [];

        foreach ($nodes as $node) {
            if (($node['@type'] ?? '') === 'CollectionPage' && empty($node['mainEntity'])) {
                $warnings[] = 'The live page also publishes an ItemList of the products on it. '
                    . 'This preview does not run the listing query, so only the page-level node is shown here.';
            }

            if (($node['@type'] ?? '') === 'Product') {
                if (empty($node['offers'])) {
                    $warnings[] = 'Product schema has no offer — no price will show in search results.';
                } elseif (($node['offers']['@type'] ?? '') === 'AggregateOffer') {
                    /*
                     * A variable product publishes a RANGE, not a price, and
                     * the old test read `offers.price` unconditionally -- so
                     * the moment variant offers shipped, every variable
                     * product in this screen would have reported "Offer has no
                     * price set" over a node that carries two. A warning that
                     * fires on correct output is how an owner learns to ignore
                     * the warnings.
                     */
                    if (empty($node['offers']['lowPrice']) || empty($node['offers']['highPrice'])) {
                        $warnings[] = 'Offer has no price range set.';
                    }
                } elseif (empty($node['offers']['price'])) {
                    $warnings[] = 'Offer has no price set.';
                }
                if (empty($node['image'])) {
                    $warnings[] = 'Product schema has no image.';
                }
            }
        }

        return $warnings;
    }
}
