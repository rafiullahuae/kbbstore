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

    /** A gift with this many units or fewer reads "Low" (Overview, Ranges, Gift products). Stated on screen. */
    public const LOW_STOCK = 20;

    /** Overview's numbers look back this many days. */
    public const STATS_DAYS = 30;

    public function __construct(private FreeGifts $gifts) {}

    /** GET /admin-api/free-gifts -- everything the five tabs draw. */
    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /**
     * POST /admin-api/free-gifts/switch {mode: off|testing|live} -- the Mode.
     *
     * NEVER refused for the checklist (Lane FG3, the owner 22 Oct: "allow me
     * to enable the gifts and test, it should not be restricted by
     * non-available products"). A missing, sold-out or unpublished gift is
     * skipped by the shop and the rest of its range is still given; a range
     * with nothing it can give gives nothing. The Overview highlights them.
     *
     * The one case left is Testing with nobody on the list, which would show
     * nobody. Rather than refuse, the address the request comes from -- the
     * owner at this screen -- is put on the list and the answer says so
     * (`added_ip`), so the screen can toast it. Only when that address is
     * not a usable IP is it refused. The old {on} is still read: true is live.
     */
    public function switch(Request $request): JsonResponse
    {
        $request->validate(['mode' => ['nullable', Rule::in(FreeGifts::MODES)]]);
        $mode = $request->filled('mode') ? (string) $request->input('mode') : ($request->boolean('on') ? 'live' : 'off');

        $config = $this->gifts->freshConfig();
        $added = null;

        if ($mode === 'testing' && $config['testers'] === []) {
            $ip = (string) ($request->ip() ?? '');

            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return response()->json(['error' => 'Add at least one IP to "IPs that can see it in Testing" first.'], 422);
            }

            $added = \App\Support\IpRange::normalise($ip) ?? $ip;
            $config['testers'] = [['ip' => $added, 'label' => 'My device (added when Testing was switched on)']];
        }

        $config['mode'] = $mode;
        $config['on'] = $mode !== 'off';
        $this->gifts->saveConfig($config);

        if ($mode !== 'off') {
            $this->gifts->compile();
        }

        return response()->json($this->payload() + ($added !== null ? ['added_ip' => $added] : []));
    }

    /**
     * POST /admin-api/free-gifts/testers {testers: [{ip, label}]} -- the
     * Testing list, replaced whole. Every address passes filter_var
     * FILTER_VALIDATE_IP (IPv4 and IPv6), is stored in its canonical form,
     * de-duplicated, at most FreeGifts::MAX_TESTERS. Emptying the list while
     * in Testing is refused rather than silently hiding the feature.
     */
    public function testers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'testers' => ['present', 'array', 'max:' . FreeGifts::MAX_TESTERS],
            'testers.*.ip' => ['required', 'string', 'max:45'],
            'testers.*.label' => ['nullable', 'string', 'max:60'],
        ]);

        $list = [];
        foreach (array_values($data['testers']) as $i => $t) {
            $ip = trim((string) $t['ip']);

            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw ValidationException::withMessages(['testers.' . $i . '.ip' => '"' . mb_substr($ip, 0, 45) . '" is not an IP address (IPv4 like 203.0.113.7, or IPv6).']);
            }

            $ip = \App\Support\IpRange::normalise($ip) ?? $ip;
            $list[$ip] ??= ['ip' => $ip, 'label' => trim((string) ($t['label'] ?? ''))];
        }

        $config = $this->gifts->freshConfig();

        if ($list === [] && $config['mode'] === 'testing') {
            throw ValidationException::withMessages(['testers' => 'Testing needs at least one IP. Switch the mode to Off first to empty the list.']);
        }

        $config['testers'] = array_values($list);
        $this->gifts->saveConfig($config);

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

        $config = $this->gifts->freshConfig();
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
            // Lane FG4, internal only: the gift's box, and the range's criteria.
            'gifts.*.kind' => ['nullable', Rule::in(FreeGifts::KINDS)],
            'needs' => ['sometimes', 'nullable', 'array'],
            'needs.mask' => ['required_with:needs', 'integer', 'min:0', 'max:' . FreeGifts::MAX_NEED],
            'needs.mini' => ['required_with:needs', 'integer', 'min:0', 'max:' . FreeGifts::MAX_NEED],
            'needs.full' => ['required_with:needs', 'integer', 'min:0', 'max:' . FreeGifts::MAX_NEED],
        ]);

        $min = (int) $data['min'] * 100;
        $max = $data['max'] === null ? null : (int) $data['max'] * 100;

        /*
         * NO RANGE RESTRICTIONS -- the owner, 22 October: "the server should not
         * restrict the ranges. it's up to us how we set and what gap we set.
         * server should also calculate the range." Ranges saved one at a time
         * overlap for a moment while the owner moves a boundary (100-300 saved
         * before 300-499 becomes 301-500), and refusing that made the screen
         * unusable. Overlaps and gaps are allowed; FreeGifts::rangeFor() gives a
         * basket that two ranges cover the HIGHER range. A top amount typed
         * below the start is read the other way round rather than refused.
         */
        if ($max !== null && $max < $min) {
            [$min, $max] = [$max, $min];
        }

        $ids = array_map(static fn ($g) => (int) $g['product_id'], $data['gifts']);
        $found = $ids === [] ? collect() : Product::query()->whereIn('id', $ids)->get(['id', 'type', 'gift_kind'])->keyBy('id');

        foreach ($data['gifts'] as $i => $g) {
            $p = $found[(int) $g['product_id']] ?? null;

            if ($p === null) {
                throw ValidationException::withMessages(['gifts.' . $i . '.product_id' => 'That product does not exist (or was deleted).']);
            }

            if ($p->type === 'variable') {
                throw ValidationException::withMessages(['gifts.' . $i . '.product_id' => 'A product with shades or sizes cannot be a gift; pick a simple product.']);
            }
        }

        /*
         * Each gift's type (Lane FG4). A gift sent WITHOUT a `kind` key (an
         * older screen) takes its product's remembered type; one sent with
         * kind null is the owner's "untagged" and stays so. A gift sent with a
         * type teaches its product that type for the next time it is added.
         */
        $kinds = [];
        $remember = [];
        foreach (array_values($data['gifts']) as $i => $g) {
            $raw = $request->input('gifts.' . $i);
            $kind = is_array($raw) && array_key_exists('kind', $raw)
                ? FreeGifts::kind($g['kind'] ?? null)
                : FreeGifts::kind($found[(int) $g['product_id']]->gift_kind ?? null);
            $kinds[$i] = $kind;

            if ($kind !== null && ($found[(int) $g['product_id']]->gift_kind ?? null) !== $kind) {
                $remember[$kind][] = (int) $g['product_id'];
            }
        }

        // Criteria: only when sent (an older screen leaves them as they are).
        $needs = null;
        if ($request->has('needs') && is_array($data['needs'] ?? null)) {
            foreach (FreeGifts::KINDS as $kind) {
                $needs['need_' . $kind] = (int) $data['needs'][$kind];
            }
        } elseif ($request->has('needs')) {
            $needs = ['need_mask' => null, 'need_mini' => null, 'need_full' => null];
        }

        DB::transaction(function () use ($id, $data, $min, $max, $kinds, $remember, $needs) {
            $row = [
                'min_fils' => $min,
                'max_fils' => $max,
                'label_en' => trim($data['label_en']),
                'label_ar' => trim($data['label_ar']),
                'enabled' => (bool) $data['enabled'],
                'starts_at' => $data['starts_on'] ?? null,
                'ends_at' => $data['ends_on'] ?? null,
                'updated_at' => now(),
            ] + ($needs ?? []);

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
                    'kind' => $kinds[$sort] ?? null,
                    'sort' => $sort,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Remembered per product: one statement per type, never per gift.
            // DB::table, so Product::saving() and updated_at are not touched.
            foreach ($remember as $kind => $productIds) {
                DB::table('products')->whereIn('id', $productIds)->update(['gift_kind' => $kind]);
            }
        });

        $this->gifts->compile();

        return response()->json($this->payload());
    }

    /** DELETE /admin-api/free-gifts/ranges/{id} */
    public function deleteRange(int $id): JsonResponse
    {
        // Deleting the last range no longer switches the mode off (Lane FG3):
        // the mode is the owner's, and an empty set of ranges gives nothing.
        DB::table('free_gift_ranges')->where('id', $id)->delete();
        $this->gifts->compile();

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
        // A select stores one of its own options: anything else is "all".
        $filter = in_array($request->query('filter'), ['gift', 'instock'], true) ? $request->query('filter') : 'all';
        // Lane FG4: by remembered type; `none` is untagged, anything else all.
        $kind = in_array($request->query('kind'), [...FreeGifts::KINDS, 'none'], true) ? $request->query('kind') : 'all';

        $rows = Product::query()
            ->with('brand:id,name')
            ->when($filter === 'gift', fn ($query) => $query->where('gift_only', true))
            ->when($filter === 'instock', fn ($query) => $query->where('stock_status', 'instock')
                ->where(fn ($w) => $w->where('manage_stock', false)->orWhere('stock', '>', 0)))
            ->when($kind === 'none', fn ($query) => $query->whereNull('gift_kind'))
            ->when(in_array($kind, FreeGifts::KINDS, true), fn ($query) => $query->where('gift_kind', $kind))
            ->when($q !== '', function ($query) use ($q) {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('slug', 'like', $like));
            })
            ->orderByDesc('gift_only')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'name', 'slug', 'sku', 'brand_id', 'image', 'type', 'status', 'stock_status', 'manage_stock', 'stock', 'gift_only', 'gift_kind', 'price', 'sale_price', 'sale_starts_at', 'sale_ends_at']);

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
            // The gift's own price, in dirhams: what "Show gift prices" strikes
            // through. Optional; a gift-only product is never sold at it.
            'price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            // A picture from the Media Library upload (/admin-api/media/upload).
            // Scheme-checked: it becomes an <img src>, never a javascript: or data: URL.
            'image' => ['nullable', 'string', 'max:500', 'regex:#^(https?://|/(?!/))[^\s"\'<>]+$#i'],
        ], [
            'image.regex' => 'The photo must be an uploaded image (an http(s) address).',
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
            'price' => isset($data['price']) ? (int) round((float) $data['price'] * 100) : 0,
            'image' => ($data['image'] ?? '') !== '' ? $data['image'] : null,
            'manage_stock' => ($data['stock'] ?? null) !== null,
            'stock' => $data['stock'] ?? null,
            'stock_status' => (($data['stock'] ?? null) === null || (int) $data['stock'] > 0) ? 'instock' : 'outofstock',
            'gift_only' => true,
            'is_visible' => false,
        ])->save();

        return response()->json($this->payload() + ['created' => $this->productRow($product->load('brand:id,name'))]);
    }

    /**
     * GET /admin-api/free-gifts/stats -- Overview's numbers, from data the shop
     * already keeps: the order's own gift lines (order_items.is_gift) and the
     * gift products' stock. FOUR queries whatever the number of orders, gifts
     * or ranges (FreeGiftsAdminTest pins 3 orders against 40). Real orders
     * only (Order::REAL_STATUSES): a failed or pending payment gave nothing.
     */
    public function stats(): JsonResponse
    {
        $since = now()->subDays(self::STATS_DAYS);
        $real = \App\Models\Order::REAL_STATUSES;

        $orders = (int) DB::table('orders')
            ->where('free_gift_count', '>', 0)
            ->whereIn('status', $real)
            ->where('created_at', '>=', $since)
            ->count();

        $lines = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.is_gift', true)
            ->whereIn('orders.status', $real)
            ->where('orders.created_at', '>=', $since)
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id as product_id, MAX(order_items.name) as name, SUM(order_items.quantity) as units')
            ->orderByDesc('units')
            ->orderBy('order_items.product_id')
            ->get();

        $top = $lines->first();

        $usedIds = DB::table('free_gift_range_items')->distinct()->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        $stock = $usedIds === [] ? collect() : Product::query()
            ->whereIn('id', $usedIds)
            ->where(fn ($q) => $q->where('stock_status', '<>', 'instock')
                ->orWhere(fn ($w) => $w->where('manage_stock', true)->where('stock', '<=', self::LOW_STOCK)))
            ->orderBy('stock')
            ->orderBy('name')
            ->get(['id', 'name', 'stock_status', 'manage_stock', 'stock']);

        return response()->json([
            'days' => self::STATS_DAYS,
            'low_stock' => self::LOW_STOCK,
            'orders' => $orders,
            'units' => (int) $lines->sum('units'),
            'top' => $top === null ? null : ['product_id' => (int) $top->product_id, 'name' => (string) $top->name, 'units' => (int) $top->units],
            'stock_alerts' => $stock->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'stock' => $p->manage_stock ? (int) $p->stock : null,
                'level' => ($p->stock_status !== 'instock' || ($p->manage_stock && (int) $p->stock < 1)) ? 'out' : 'low',
            ])->values(),
        ]);
    }

    /** GET /admin-api/free-gifts/brands -- the create form's brand list, asked for only when that form opens. */
    public function brands(): JsonResponse
    {
        return response()->json([
            'brands' => DB::table('brands')->orderBy('name')->orderBy('id')->limit(1000)->get(['id', 'name'])
                ->map(fn ($b) => ['id' => (int) $b->id, 'name' => (string) $b->name])->values(),
        ]);
    }

    /**
     * Amounts no enabled range covers, between two that do: "AED 300 - 300
     * gives nothing" when one band ends at 299 and the next starts at 301.
     * Below the first band is the shop's choice (no gift under AED 100) and
     * is not a gap. Whole dirhams, as the screen shows them. The screen runs
     * the same walk as you type; this is the server's copy of the answer.
     *
     * @return list<array{from:int,to:int}>
     */
    public static function gaps(iterable $ranges): array
    {
        $bands = [];
        foreach ($ranges as $r) {
            if ((bool) $r->enabled) {
                $bands[] = [intdiv((int) $r->min_fils, 100), $r->max_fils === null ? null : intdiv((int) $r->max_fils, 100)];
            }
        }
        usort($bands, static fn ($a, $b) => $a[0] <=> $b[0]);

        $gaps = [];
        $reach = null; // the last dirham covered so far; null before the first band
        foreach ($bands as [$min, $max]) {
            if ($reach !== null && $min > $reach + 1) {
                $gaps[] = ['from' => $reach + 1, 'to' => $min - 1];
            }
            if ($max === null) {
                break;
            }
            $reach = $reach === null ? $max : max($reach, $max);
        }

        return $gaps;
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
            ->get(['id', 'name', 'slug', 'sku', 'brand_id', 'image', 'type', 'status', 'stock_status', 'manage_stock', 'stock', 'gift_only', 'gift_kind', 'price', 'sale_price', 'sale_starts_at', 'sale_ends_at']);
        $byId = $giftProducts->keyBy('id');

        $config = $this->gifts->freshConfig();

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
                // Lane FG4: the criteria (null = none set) and each gift's box.
                'needs' => FreeGifts::needsOf($r),
                'gifts' => ($items[$r->id] ?? collect())->map(fn ($i) => [
                    'product_id' => (int) $i->product_id,
                    'quantity' => (int) $i->quantity,
                    'kind' => FreeGifts::kind($i->kind ?? null),
                    'product' => isset($byId[$i->product_id]) ? $this->productRow($byId[$i->product_id]) : null,
                ])->values(),
            ])->values(),
            'gift_products' => $giftProducts->map(fn (Product $p) => $this->productRow($p) + [
                'used_in' => $items->filter(fn ($rows) => $rows->contains('product_id', $p->id))->keys()->map(fn ($k) => (int) $k)->values(),
            ])->values(),
            'gaps' => self::gaps($ranges),
            // The address the server sees for the person at this screen --
            // $request->ip(), the firewall's answer -- for "Add my current IP".
            'my_ip' => (string) (request()->ip() ?? ''),
            'low_stock' => self::LOW_STOCK,
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
            'gift_kind' => FreeGifts::kind($p->gift_kind ?? null),
            'price' => Money::plain(max(0, (int) $p->effectivePrice())),
        ];
    }
}
