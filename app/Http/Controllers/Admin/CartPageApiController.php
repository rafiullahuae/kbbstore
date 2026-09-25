<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\CartPage;
use App\Services\ModuleSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance → Cart page.
 *
 * The field/tab loop is the same fifteen lines every module API controller in
 * this project has (see CartPanelApiController) and is kept in that shape
 * deliberately: the admin screen that draws it is generic, and a controller
 * that answered in a different shape would need a renderer of its own.
 *
 * The third endpoint is not generic. `products` backs the "which products fill
 * the rail" picker, because the owner asked for "full control to choose the
 * products for this grid section with search products functionality on
 * backend". It is a search of its own rather than a reuse of
 * /admin-api/manual-orders/products: that one is mapped to the orders
 * capability, and an editor who can arrange the storefront should not have to
 * hold an orders capability to pick six products for a rail.
 */
class CartPageApiController extends Controller
{
    /** One page of search results. Enough to scroll, small enough to be fast. */
    private const PER_PAGE = 30;

    /** The escape character the LIKE clauses below declare. */
    /*
     * `!`, AND NOT A BACKSLASH, WHICH IS WHY THE SEARCH RETURNED NOTHING ON
     * THE LIVE SHOP WHILE EVERY TEST PASSED.
     *
     * This was '\\' -- one backslash -- so the SQL read `LIKE ? ESCAPE '\'`.
     * MySQL reads the backslash inside that literal as escaping the closing
     * quote, keeps consuming, and throws a syntax error. SQLite does not treat
     * backslash as an escape inside string literals, so the same SQL is a valid
     * one-character string there and works.
     *
     * The suite runs on SQLite. Production runs on MySQL. So the picker
     * answered 500 on the shop and green in CI, and the owner saw only
     * "Nothing matches that."
     *
     * `!` needs no quoting in either dialect, so there is no escaping of the
     * escape character to get wrong a second time. It is escaped in the needle
     * below along with % and _, so a shopper searching for a literal ! still
     * gets what they asked for.
     */
    private const LIKE_ESCAPE = '!';

    public function __construct(private CartPage $page) {}

    public function show(): JsonResponse
    {
        // One schema, drawn by one renderer. This was the same fifteen lines
        // that nine other controllers carried, and the reason the three
        // constants a screen depends on could disagree without anything saying
        // so. `POLICY` travels with the schema because ModuleSchema's cast is
        // strict by default and this screen is not — see CartPage::POLICY.
        $tabs = ModuleSchema::tabs(
            CartPage::SCHEMA,
            CartPage::TABS,
            $this->page->all(),
            CartPage::POLICY,
        );

        return response()->json([
            'tabs' => $tabs,
            // The rail's chosen products, resolved to names. The stored value
            // is a list of ids and an id is not something anybody can check by
            // reading it, so the screen is handed the products themselves.
            'chosen' => $this->page->recommended()->map(fn (Product $p) => $this->card($p))->all(),
            'maxRec' => CartPage::MAX_REC,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(CartPage::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        $this->page->save($data['settings']);

        return response()->json(['ok' => true]);
    }

    /**
     * Search the catalogue for the rail picker.
     *
     * Name, sku, slug and brand — the same four columns
     * CatalogProductsApiController and the manual-order picker search, so the
     * owner gets the same answer to "anua" wherever he types it. That
     * consistency is not cosmetic: the two screens disagreeing about the same
     * catalogue is a bug this project has already shipped once.
     *
     * Only what a shopper can actually be sent to: `status = publish` and
     * `is_visible`. A rail is a row of links on the storefront, and a draft in
     * it is a 404 with a picture on it.
     */
    public function products(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'ids' => ['nullable', 'string', 'max:400'],
        ]);

        $query = Product::query()
            ->where('status', 'publish')
            ->where('is_visible', true);

        // `ids` is how the screen resolves a stored list back to products
        // without a search term — the same endpoint, so there is one place
        // that decides what a pickable product is.
        if (($data['ids'] ?? '') !== '') {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $data['ids']))));

            return response()->json([
                'products' => Product::query()
                    ->whereIn('id', $ids)
                    ->where('status', 'publish')
                    ->where('is_visible', true)
                    ->get()
                    ->map(fn (Product $p) => $this->card($p))
                    ->all(),
            ]);
        }

        $term = trim((string) ($data['q'] ?? ''));

        if ($term !== '') {
            // The escape character first, or escaping % would then be escaped
            // again by the pass that escapes the escape.
            $like = '%' . str_replace(
                [self::LIKE_ESCAPE, '%', '_'],
                [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
                $term
            ) . '%';

            $query->where(function ($q) use ($like) {
                $q->orWhereRaw("name LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'", [$like])
                    ->orWhereRaw("sku LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'", [$like])
                    ->orWhereRaw("slug LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'", [$like])
                    ->orWhereHas('brand', function ($b) use ($like) {
                        $b->whereRaw("name LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'", [$like]);
                    });
            });
        }

        $rows = $query->orderByDesc('id')->limit(self::PER_PAGE)->get();

        return response()->json([
            'products' => $rows->map(fn (Product $p) => $this->card($p))->all(),
        ]);
    }

    /**
     * One product as the picker draws it.
     *
     * AN EXPLICIT ALLOWLIST, and not because this endpoint is public — it is
     * behind auth:admin and a capability. It is a list because
     * `products` carries wc_id, sku and total_sales, and the habit of
     * returning a model wholesale is what put each of those on the open web
     * once already. Four fields is what a picker row draws.
     */
    private function card(Product $product): array
    {
        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'brand' => (string) ($product->brand?->name ?? ''),
            'image' => (string) ($product->image ?? ''),
        ];
    }
}
