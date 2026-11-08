<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Product;
use App\Services\FreeGifts;
use App\Support\MajorUnits;
use App\Support\MediaRegistrar;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Growth & Marketing -> Free gifts -> Ranges & gifts -> "Gift-only products"
 * (Lane GP, the owner 8 Nov 2026: "a quick products addition, those will not
 * show on the shop pages, just for gifts ... product name, product image, cut
 * price, actual price. and i need the list there also").
 *
 * STORAGE IS A REAL PRODUCT ROW with `gift_only = 1`, the flag Lane FG added.
 * Product::booted() forces `is_visible = 0` on every save of such a row, and
 * every shop reader already asks Product::visible() / ProductVisibility (status
 * AND is_visible AND the schedule), so listings, search, the sitemap, the feed,
 * /api, the product page (404) and add-to-bag refuse it with no reader
 * changed. FreeGifts gives it like any product (publish + in stock), at AED 0.
 *
 * Money: cut price -> `price` (the struck-through "worth"), actual price ->
 * `sale_price` (what FreeGifts::extrasBox() shows as `now`), both in fils
 * through MajorUnits, the editor's own parse. Equal prices store no sale.
 *
 * Remove is a SOFT delete (the model's SoftDeletes), never a hard one: order
 * lines keep their product_id and their own name snapshot, so past orders,
 * invoices and the owner app are untouched. A product still on a range is
 * refused with the range named, so a gift never silently vanishes from one.
 *
 * Routes: routes/free-gifts-quick-admin.php, under /admin-api/free-gifts/**,
 * so AdminCapabilities maps GET to freegifts.view and every write to
 * freegifts.manage, failing closed. Nothing here is on /api/*.
 */
class FreeGiftsQuickController extends Controller
{
    /** The list is the owner's own quick products; a ceiling keeps one answer small. */
    public const LIST_CAP = 300;

    private const COLUMNS = ['id', 'name', 'slug', 'brand_id', 'image', 'type', 'status', 'stock_status', 'manage_stock', 'stock',
        'gift_only', 'gift_kind', 'price', 'sale_price', 'sale_starts_at', 'sale_ends_at'];

    /** GET /admin-api/free-gifts/quick -- every gift-only product, flat queries whatever the count. */
    public function index(): JsonResponse
    {
        return response()->json(['products' => $this->rows()]);
    }

    /** POST /admin-api/free-gifts/quick {name, image, cut, now} */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);

        $base = Str::slug($data['name']) ?: 'gift';
        $slug = 'gift-' . $base;
        for ($n = 2; Product::withTrashed()->where('slug', $slug)->exists(); $n++) {
            $slug = 'gift-' . $base . '-' . $n;
        }

        $product = new Product;
        $product->forceFill([
            'name' => $data['name'],
            'slug' => $slug,
            'type' => 'simple',
            'status' => 'publish',
            'price' => $data['cut'],
            'sale_price' => $data['sale'],
            'image' => $data['image'],
            'manage_stock' => false,
            'stock' => null,
            'stock_status' => 'instock',
            'gift_only' => true,
            'is_visible' => false,
        ])->save();

        return response()->json(['ok' => true, 'id' => (int) $product->id, 'products' => $this->rows()], 201);
    }

    /** POST /admin-api/free-gifts/quick/{id} {name, image, cut, now} -- inline edit. */
    public function update(Request $request, int $id): JsonResponse
    {
        $product = $this->ownedOrFail($id);
        $data = $this->validated($request, $product);

        $product->name = $data['name'];
        $product->image = $data['image'];

        if ($product->type !== 'variable') {
            $product->price = $data['cut'];
            $product->sale_price = $data['sale'];
            // The actual price is in force now: a schedule left over from the
            // product's life on sale would hide it.
            $product->sale_starts_at = null;
            $product->sale_ends_at = null;
        }

        $product->save();

        return response()->json(['ok' => true, 'id' => (int) $product->id, 'products' => $this->rows()]);
    }

    /** DELETE /admin-api/free-gifts/quick/{id} -- soft delete; refused while it is a gift on a range. */
    public function destroy(int $id): JsonResponse
    {
        $product = $this->ownedOrFail($id);

        $on = DB::table('free_gift_range_items')
            ->join('free_gift_ranges', 'free_gift_ranges.id', '=', 'free_gift_range_items.range_id')
            ->where('free_gift_range_items.product_id', $product->id)
            ->orderBy('free_gift_ranges.min_fils')
            ->get(['free_gift_ranges.min_fils', 'free_gift_ranges.max_fils']);

        if ($on->isNotEmpty()) {
            $labels = $on->map(fn ($r) => FreeGifts::rangeLabel(['min' => (int) $r->min_fils, 'max' => $r->max_fils === null ? null : (int) $r->max_fils]))->unique()->implode(', ');

            return response()->json([
                'ok' => false,
                'error' => '"' . $product->name . '" is a gift in ' . $labels . '. Take it off ' . ($on->count() > 1 ? 'those ranges' : 'that range') . ' and save, then remove it.',
            ], 409);
        }

        $product->delete();

        return response()->json(['ok' => true, 'products' => $this->rows()]);
    }

    private function ownedOrFail(int $id): Product
    {
        $product = Product::query()->where('gift_only', true)->find($id, self::COLUMNS);

        if ($product === null) {
            // Only gift-only products are this screen's to change: a shop
            // product is the product editor's, and answers exactly as a missing one.
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['ok' => false, 'error' => 'That gift-only product no longer exists.'], 404));
        }

        return $product;
    }

    /**
     * @return array{name:string,image:?string,cut:?int,sale:?int}
     */
    private function validated(Request $request, ?Product $current): array
    {
        $variable = $current !== null && $current->type === 'variable';
        $money = [MajorUnits::shape()];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'image' => ['nullable', 'string', 'max:500'],
            'cut' => $variable ? ['nullable'] : array_merge(['required'], $money),
            'now' => $variable ? ['nullable'] : array_merge(['nullable'], $money),
        ], [
            'cut.required' => 'Enter the cut price (the struck-through price).',
            'cut.regex' => 'The cut price must be a number like 45 or 45.50.',
            'now.regex' => 'The actual price must be a number like 29 or 29.90.',
        ]);

        $name = trim(preg_replace('/\s+/u', ' ', (string) $data['name']) ?? '');
        if (mb_strlen($name) < 2) {
            throw ValidationException::withMessages(['name' => 'Give it a name (2 letters or more).']);
        }

        $out = ['name' => $name, 'image' => $this->image($data['image'] ?? null, $current), 'cut' => null, 'sale' => null];

        if ($variable) {
            return $out;
        }

        $cut = MajorUnits::fils($data['cut']);
        $now = MajorUnits::fils($data['now'] ?? null) ?? $cut;

        foreach (['cut' => $cut, 'now' => $now] as $key => $fils) {
            if (MajorUnits::exceedsColumn($fils)) {
                throw ValidationException::withMessages([$key => 'That price is too large; the most is ' . MajorUnits::maxMajor() . '.']);
            }
        }

        if ($now > $cut) {
            throw ValidationException::withMessages(['now' => 'The actual price cannot be more than the cut price.']);
        }

        $out['cut'] = $cut;
        $out['sale'] = $now < $cut ? $now : null;

        return $out;
    }

    /**
     * The picture: a Media Library file, or nothing.
     *
     * kbbPickMedia hands over a URL. It is cut to its root-relative path the
     * way BannerApiController::storedPath() does (URL only; a bare path must
     * START at a root), MediaRegistrar::normalise() is the allowlist (uploads/
     * or wp-content/uploads/, no traversal, no scheme), and the file must be
     * in the library's own table. What is stored is the library's own URL for
     * it (Media::urlFor), never the host the request named.
     *
     * The picture a product already has is kept as it is, so editing the name
     * of a gift whose photo came from the old shop never fails on the photo.
     */
    private function image(?string $raw, ?Product $current): ?string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        if ($current !== null && $value === trim((string) $current->image)) {
            return $current->image;
        }

        $path = $value;
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $value) === 1) {
            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
            $urlPath = parse_url($value, PHP_URL_PATH);
            $path = '';

            if (in_array($scheme, ['http', 'https'], true) && is_string($urlPath)) {
                $urlPath = rawurldecode($urlPath);
                foreach (MediaRegistrar::ROOTS as $root) {
                    $at = strpos($urlPath, '/' . $root);
                    if ($at !== false) {
                        $path = substr($urlPath, $at + 1);
                        break;
                    }
                }
            }
        }

        $stored = $path === '' ? null : MediaRegistrar::normalise($path);

        if ($stored === null || ! Media::query()->where('path', $stored)->exists()) {
            throw ValidationException::withMessages(['image' => 'Choose the photo from the Media Library.']);
        }

        return Media::urlFor($stored);
    }

    /**
     * Every gift-only product as the panel and the picker draw it.
     * FOUR queries whatever the number of rows: the products, their brands,
     * their ranges, their order lines (FreeGiftsQuickTest renders 3 and 40 and compares).
     *
     * @return list<array<string,mixed>>
     */
    private function rows(): array
    {
        $products = Product::query()
            ->with('brand:id,name')
            ->where('gift_only', true)
            ->orderByDesc('id')
            ->limit(self::LIST_CAP)
            ->get(self::COLUMNS);

        $ids = $products->pluck('id')->all();

        $ranges = $ids === [] ? collect() : DB::table('free_gift_range_items')
            ->whereIn('product_id', $ids)
            ->select('product_id', 'range_id')->distinct()
            ->get()->groupBy('product_id');

        $orders = $ids === [] ? collect() : DB::table('order_items')
            ->whereIn('product_id', $ids)
            ->groupBy('product_id')
            ->selectRaw('product_id, COUNT(DISTINCT order_id) AS n')
            ->pluck('n', 'product_id');

        return $products->map(function (Product $p) use ($ranges, $orders) {
            $cut = max(0, (int) $p->price);
            $now = max(0, (int) $p->effectivePrice());

            return [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'brand' => (string) ($p->brand?->name ?? ''),
                'image' => (string) ($p->image ?? ''),
                'thumb' => $p->image ? \App\Support\ImageVariants::variantUrl((string) $p->image, 200) : '',
                'type' => (string) $p->type,
                'status' => (string) $p->status,
                'in_stock' => $p->stock_status === 'instock' && (! $p->manage_stock || (int) $p->stock > 0),
                'stock' => $p->manage_stock ? (int) $p->stock : null,
                'gift_only' => true,
                'gift_kind' => FreeGifts::kind($p->gift_kind ?? null),
                // `price` is what the picker prints for any product: the gift's own.
                'price' => Money::plain($now),
                'cut_text' => Money::plain($cut),
                // What the edit fields hold: plain major units, no symbol.
                'cut' => self::major($cut),
                'now' => self::major($now),
                'used_in' => ($ranges[$p->id] ?? collect())->pluck('range_id')->map(fn ($r) => (int) $r)->values()->all(),
                'orders' => (int) ($orders[$p->id] ?? 0),
            ];
        })->values()->all();
    }

    /** Fils -> "45.50": the shape MajorUnits::shape() accepts back. */
    private static function major(int $fils): string
    {
        $exp = Money::minorExponent();

        if ($exp < 1) {
            return (string) $fils;
        }

        $unit = 10 ** $exp;

        return intdiv($fils, $unit) . '.' . str_pad((string) ($fils % $unit), $exp, '0', STR_PAD_LEFT);
    }
}
