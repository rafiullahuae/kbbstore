<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\FreeGifts;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Marketing -> Free gifts (Lane FG). Every route is in routes/free-gifts-admin.php,
 * inside the guarded admin-api group; AdminCapabilities maps the GETs to
 * freegifts.view and everything else to freegifts.manage, failing closed.
 *
 * Every write is validated here and nowhere else: amounts are whole dirhams,
 * min below max, bands never overlap, a quantity is 1-50, a product exists, is
 * not deleted and has no options, and a select stores one of its own options.
 * Every band or gift change recompiles the storefront's copy
 * (FreeGifts::compile()), so the shop never reads the two tables.
 */
class FreeGiftsController extends Controller
{
    private const MAX_RANGES = 20;

    private const MAX_GIFTS = 30;

    public function __construct(private FreeGifts $gifts) {}

    /** GET /admin-api/free-gifts -- everything the five tabs draw. */
    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /** POST /admin-api/free-gifts/switch {on} -- refused while a blocking item is open. */
    public function switch(Request $request): JsonResponse
    {
        $on = $request->boolean('on');

        if ($on && $this->gifts->blocked()) {
            return response()->json([
                'error' => 'Not yet: finish the items marked "Needed" on the Overview first.',
                'checklist' => $this->gifts->checklist(),
            ], 422);
        }

        $config = $this->gifts->config();
        $config['on'] = $on;
        $this->gifts->saveConfig($config);

        if ($on) {
            $this->gifts->compile();
        }

        return response()->json($this->payload());
    }

    /** POST /admin-api/free-gifts/settings -- Display and Homepage section. */
    public function settings(Request $request): JsonResponse
    {
        $textRules = [];
        foreach (array_keys(FreeGifts::TEXTS) as $key) {
            $textRules['texts.' . $key . '_en'] = ['required', 'string', 'max:160'];
            $textRules['texts.' . $key . '_ar'] = ['required', 'string', 'max:160'];
        }

        $data = $request->validate([
            'show_value' => ['required', 'boolean'],
            'countries' => ['required', Rule::in(FreeGifts::COUNTRIES)],
            'basis' => ['required', Rule::in(FreeGifts::BASES)],
            'texts' => ['required', 'array'],
            'home' => ['required', 'array'],
            'home.on' => ['required', 'boolean'],
            'home.title_en' => ['required', 'string', 'max:160'], 'home.title_ar' => ['required', 'string', 'max:160'],
            'home.sub_en' => ['nullable', 'string', 'max:160'], 'home.sub_ar' => ['nullable', 'string', 'max:160'],
            'home.btn_en' => ['required', 'string', 'max:60'], 'home.btn_ar' => ['required', 'string', 'max:60'],
        ] + $textRules);

        foreach (['line'] as $key) {
            foreach (['_en', '_ar'] as $lang) {
                if (! str_contains($data['texts'][$key . $lang], ':count')) {
                    throw ValidationException::withMessages(['texts.' . $key . $lang => 'Keep ":count" in this text: it is where the number goes.']);
                }
            }
        }

        $config = $this->gifts->config();
        $config['show_value'] = (bool) $data['show_value'];
        $config['countries'] = $data['countries'];
        $config['basis'] = $data['basis'];

        foreach (array_keys(FreeGifts::TEXTS) as $key) {
            $config['texts'][$key . '_en'] = trim($data['texts'][$key . '_en']);
            $config['texts'][$key . '_ar'] = trim($data['texts'][$key . '_ar']);
        }

        foreach (array_keys(FreeGifts::HOME) as $key) {
            $config['home'][$key] = $key === 'on' ? (bool) $data['home']['on'] : trim((string) ($data['home'][$key] ?? ''));
        }

        $this->gifts->saveConfig($config);

        return response()->json($this->payload());
    }

    /** POST /admin-api/free-gifts/ranges, PUT /admin-api/free-gifts/ranges/{id}. */
    public function saveRange(Request $request, ?int $id = null): JsonResponse
    {
        if ($id !== null && ! DB::table('free_gift_ranges')->where('id', $id)->exists()) {
            return response()->json(['error' => 'not_found'], 404);
        }

        if ($id === null && DB::table('free_gift_ranges')->count() >= self::MAX_RANGES) {
            return response()->json(['error' => 'At most ' . self::MAX_RANGES . ' ranges.'], 422);
        }

        $data = $request->validate([
            'min' => ['required', 'integer', 'min:0', 'max:1000000'],
            'max' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'label_en' => ['required', 'string', 'max:160'],
            'label_ar' => ['required', 'string', 'max:160'],
            'enabled' => ['required', 'boolean'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'gifts' => ['present', 'array', 'max:' . self::MAX_GIFTS],
            'gifts.*.product_id' => ['required', 'integer', 'distinct'],
            'gifts.*.quantity' => ['required', 'integer', 'min:1', 'max:' . FreeGifts::MAX_QTY],
        ]);

        $min = (int) $data['min'] * 100;
        $max = $data['max'] === null ? null : (int) $data['max'] * 100;

        if ($max !== null && $max <= $min) {
            throw ValidationException::withMessages(['max' => 'The maximum must be above the minimum (leave it empty for "and above").']);
        }

        foreach (DB::table('free_gift_ranges')->when($id !== null, fn ($q) => $q->where('id', '<>', $id))->get(['min_fils', 'max_fils']) as $other) {
            if (FreeGifts::overlaps($min, $max, (int) $other->min_fils, $other->max_fils === null ? null : (int) $other->max_fils)) {
                throw ValidationException::withMessages(['min' => 'This range overlaps '
                    . FreeGifts::rangeLabel(['min' => (int) $other->min_fils, 'max' => $other->max_fils === null ? null : (int) $other->max_fils])
                    . '. One basket gets one range, so ranges may not share an amount.']);
            }
        }

        $ids = array_map(static fn ($g) => (int) $g['product_id'], $data['gifts']);
        $found = $ids === [] ? collect() : Product::query()->whereIn('id', $ids)->get(['id', 'type'])->keyBy('id');

        foreach ($data['gifts'] as $i => $g) {
            $p = $found[(int) $g['product_id']] ?? null;

            if ($p === null) {
                throw ValidationException::withMessages(['gifts.' . $i . '.product_id' => 'That product does not exist (or was deleted).']);
            }

            if ($p->type === 'variable') {
                throw ValidationException::withMessages(['gifts.' . $i . '.product_id' => 'A product with shades or sizes cannot be a gift; pick a simple product.']);
            }
        }

        DB::transaction(function () use ($id, $data, $min, $max) {
            $row = [
                'min_fils' => $min,
                'max_fils' => $max,
                'label_en' => trim($data['label_en']),
                'label_ar' => trim($data['label_ar']),
                'enabled' => (bool) $data['enabled'],
                'starts_at' => $data['starts_on'] ?? null,
                'ends_at' => $data['ends_on'] ?? null,
                'updated_at' => now(),
            ];

            if ($id === null) {
                $id = DB::table('free_gift_ranges')->insertGetId($row + [
                    'sort' => (int) DB::table('free_gift_ranges')->max('sort') + 1,
                    'created_at' => now(),
                ]);
            } else {
                DB::table('free_gift_ranges')->where('id', $id)->update($row);
            }

            DB::table('free_gift_range_items')->where('range_id', $id)->delete();

            foreach (array_values($data['gifts']) as $sort => $g) {
                DB::table('free_gift_range_items')->insert([
                    'range_id' => $id,
                    'product_id' => (int) $g['product_id'],
                    'quantity' => (int) $g['quantity'],
                    'sort' => $sort,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->gifts->compile();

        return response()->json($this->payload());
    }

    /** DELETE /admin-api/free-gifts/ranges/{id} */
    public function deleteRange(int $id): JsonResponse
    {
        DB::table('free_gift_ranges')->where('id', $id)->delete();
        $this->gifts->compile();

        if ($this->gifts->on() && $this->gifts->blocked()) {
            $config = $this->gifts->config();
            $config['on'] = false;
            $this->gifts->saveConfig($config);
        }

        return response()->json($this->payload());
    }

    /** POST /admin-api/free-gifts/ranges/order {ids: [...]} -- the order the admin lists them in. */
    public function order(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:' . self::MAX_RANGES], 'ids.*' => ['integer', 'distinct']]);

        foreach (array_values($data['ids']) as $sort => $rangeId) {
            DB::table('free_gift_ranges')->where('id', (int) $rangeId)->update(['sort' => $sort]);
        }

        $this->gifts->compile();

        return response()->json($this->payload());
    }

    /** GET /admin-api/free-gifts/products?q= -- the picker: the whole catalogue, gift-only included. */
    public function products(Request $request): JsonResponse
    {
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 80));

        $rows = Product::query()
            ->with('brand:id,name')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('slug', 'like', $like));
            })
            ->orderByDesc('gift_only')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'name', 'slug', 'sku', 'brand_id', 'image', 'type', 'status', 'stock_status', 'manage_stock', 'stock', 'gift_only', 'price', 'sale_price', 'sale_starts_at', 'sale_ends_at']);

        return response()->json(['products' => $rows->map(fn (Product $p) => $this->productRow($p))->values()]);
    }

    /** POST /admin-api/free-gifts/products/{id}/gift-only {on} -- the "Gift only (not for sale)" flag. */
    public function giftOnly(Request $request, int $id): JsonResponse
    {
        $product = Product::query()->find($id);

        if ($product === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $on = $request->boolean('on');
        $product->gift_only = $on;

        // Product::saving() hides a gift-only product; switching the flag off
        // here is the owner putting it back on sale, so it is shown again.
        if (! $on) {
            $product->is_visible = true;
        }

        $product->save();

        return response()->json($this->payload());
    }

    /** POST /admin-api/free-gifts/products -- a new gift-only product: name, brand, stock. */
    public function createProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $base = \Illuminate\Support\Str::slug($data['name']) ?: 'gift';
        $slug = $base;
        for ($n = 2; Product::withTrashed()->where('slug', $slug)->exists(); $n++) {
            $slug = $base . '-' . $n;
        }

        $product = new Product;
        $product->forceFill([
            'name' => trim($data['name']),
            'slug' => $slug,
            'brand_id' => $data['brand_id'] ?? null,
            'type' => 'simple',
            'status' => 'publish',
            'price' => 0,
            'manage_stock' => $data['stock'] !== null,
            'stock' => $data['stock'],
            'stock_status' => ($data['stock'] === null || (int) $data['stock'] > 0) ? 'instock' : 'outofstock',
            'gift_only' => true,
            'is_visible' => false,
        ])->save();

        return response()->json($this->payload() + ['created' => $this->productRow($product->load('brand:id,name'))]);
    }

    private function payload(): array
    {
        $ranges = DB::table('free_gift_ranges')->orderBy('sort')->orderBy('min_fils')->get();
        $items = DB::table('free_gift_range_items')->orderBy('sort')->orderBy('id')->get()->groupBy('range_id');
        $usedIds = $items->flatten(1)->pluck('product_id')->unique()->values()->all();

        $giftProducts = Product::query()
            ->with('brand:id,name')
            ->where(fn ($q) => $q->where('gift_only', true)->when($usedIds !== [], fn ($w) => $w->orWhereIn('id', $usedIds)))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(200)
            ->get(['id', 'name', 'slug', 'sku', 'brand_id', 'image', 'type', 'status', 'stock_status', 'manage_stock', 'stock', 'gift_only', 'price', 'sale_price', 'sale_starts_at', 'sale_ends_at']);
        $byId = $giftProducts->keyBy('id');

        $config = $this->gifts->config();

        return [
            'config' => $config,
            'checklist' => $this->gifts->checklist(),
            'ranges' => $ranges->map(fn ($r) => [
                'id' => (int) $r->id,
                'min' => intdiv((int) $r->min_fils, 100),
                'max' => $r->max_fils === null ? null : intdiv((int) $r->max_fils, 100),
                'label' => FreeGifts::rangeLabel(['min' => (int) $r->min_fils, 'max' => $r->max_fils === null ? null : (int) $r->max_fils]),
                'label_en' => (string) $r->label_en,
                'label_ar' => (string) $r->label_ar,
                'enabled' => (bool) $r->enabled,
                'starts_on' => $r->starts_at ? substr((string) $r->starts_at, 0, 10) : null,
                'ends_on' => $r->ends_at ? substr((string) $r->ends_at, 0, 10) : null,
                'gifts' => ($items[$r->id] ?? collect())->map(fn ($i) => [
                    'product_id' => (int) $i->product_id,
                    'quantity' => (int) $i->quantity,
                    'product' => isset($byId[$i->product_id]) ? $this->productRow($byId[$i->product_id]) : null,
                ])->values(),
            ])->values(),
            'gift_products' => $giftProducts->map(fn (Product $p) => $this->productRow($p) + [
                'used_in' => $items->filter(fn ($rows) => $rows->contains('product_id', $p->id))->keys()->map(fn ($k) => (int) $k)->values(),
            ])->values(),
            'can_manage' => ($admin = auth('admin')->user()) instanceof \App\Models\AdminUser
                && \App\Support\AdminRoles::can($admin, 'freegifts.manage'),
        ];
    }

    private function productRow(Product $p): array
    {
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
            'gift_only' => (bool) $p->gift_only,
            'price' => Money::plain(max(0, (int) $p->effectivePrice())),
        ];
    }
}
