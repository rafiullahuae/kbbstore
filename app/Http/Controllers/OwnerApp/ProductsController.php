<?php

declare(strict_types=1);

namespace App\Http\Controllers\OwnerApp;

use App\Http\Controllers\Admin\ProductEditorApiController;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\OwnerApp\OwnerAppSettings;
use App\Support\EditPresence;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Products and inventory in the owner app (Lane MAC).
 *
 * The list selects named columns only — never `wc_id` and never
 * `total_sales` — and builds each row field by field. SKU is searched but
 * shown on the detail screen only.
 *
 * A quick edit is the admin product editor's own save: the request is cut
 * down to the seven fields the app edits (price, sale price, stock, stock
 * status, stock tracking, status, catalogue visibility) and handed to
 * ProductEditorApiController::save(), so the same rules refuse the same
 * values — whole dirhams, a published product that is invisible, a set whose
 * price is a rule — and the same side effects follow: caches dropped, the
 * set's price re-anchored, IndexNow told. A product somebody has open in the
 * admin editor is refused, as the admin itself would refuse it.
 */
final class ProductsController extends Controller
{
    use Concerns;

    public const PER_PAGE = 30;

    public const FILTERS = ['all', 'low', 'out', 'instock', 'draft', 'hidden'];

    public const EDITABLE = ['price_aed', 'sale_aed', 'stock', 'stock_status', 'manage_stock', 'status', 'is_visible', 'category_ids'];

    private const LIKE_ESCAPE = '!';

    public function index(Request $request): JsonResponse
    {
        if ($r = $this->refuse($request, 'catalog.view')) {
            return $r;
        }

        $filter = in_array($request->query('filter'), self::FILTERS, true) ? (string) $request->query('filter') : 'all';
        $before = max(0, (int) $request->query('before', 0));
        $at = OwnerAppSettings::lowStock();

        $q = DB::table('products as p')->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')->whereNull('p.deleted_at');

        $term = trim(mb_substr((string) $request->query('q', ''), 0, 80));
        if ($term !== '') {
            $like = '%'.self::escape($term).'%';
            $q->where(function (Builder $w) use ($like) {
                foreach (['p.name', 'p.sku', 'b.name'] as $col) {
                    $w->orWhereRaw($col." like ? escape '".self::LIKE_ESCAPE."'", [$like]);
                }
                $w->orWhereExists(fn (Builder $v) => $v->selectRaw('1')->from('product_variants as v')
                    ->whereColumn('v.product_id', 'p.id')->whereRaw("v.sku like ? escape '".self::LIKE_ESCAPE."'", [$like]));
            });
        }

        match ($filter) {
            'low' => $q->where('p.manage_stock', true)->where('p.stock', '>', 0)->where('p.stock', '<=', $at),
            'out' => $q->where('p.stock_status', 'outofstock'),
            'instock' => $q->where('p.stock_status', 'instock'),
            'draft' => $q->where('p.status', '!=', 'publish'),
            'hidden' => $q->where(fn (Builder $w) => $w->where('p.status', '!=', 'publish')->orWhere('p.is_visible', false)),
            default => null,
        };

        $counts = null;
        if ($before === 0) {
            // The inventory chips, in one pass over the same search.
            $counts = (clone $q)->selectRaw(
                'COUNT(*) as all_n,
                 SUM(CASE WHEN p.manage_stock = 1 AND p.stock > 0 AND p.stock <= ? THEN 1 ELSE 0 END) as low_n,
                 SUM(CASE WHEN p.stock_status = ? THEN 1 ELSE 0 END) as out_n,
                 SUM(CASE WHEN p.status <> ? THEN 1 ELSE 0 END) as draft_n',
                [$at, 'outofstock', 'publish'],
            )->first();
        }

        $rows = $q->when($before > 0, fn (Builder $w) => $w->where('p.id', '<', $before))
            ->orderByDesc('p.id')
            ->limit(self::PER_PAGE + 1)
            ->get([
                'p.id', 'p.name', 'p.image', 'p.type', 'p.status', 'p.is_visible', 'p.price', 'p.sale_price',
                'p.manage_stock', 'p.stock', 'p.stock_status', 'b.name as brand',
            ]);

        $more = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE);

        $out = [
            'ok' => true,
            'products' => $rows->map(fn ($p) => self::row($p, $at))->values(),
            'next' => $more ? (int) $rows->last()->id : null,
            'low_at' => $at,
        ];
        if ($counts !== null) {
            $out['counts'] = ['all' => (int) $counts->all_n, 'low' => (int) $counts->low_n, 'out' => (int) $counts->out_n, 'draft' => (int) $counts->draft_n];
        }

        return response()->json($out);
    }

    /** @return array<string,mixed> */
    public static function row(object $p, int $at): array
    {
        $stock = $p->stock === null ? null : (int) $p->stock;

        return [
            'id' => (int) $p->id,
            'name' => (string) $p->name,
            'brand' => (string) ($p->brand ?? ''),
            'thumb' => OrdersController::thumb($p->image),
            'type' => (string) $p->type,
            'status' => (string) $p->status,
            'visible' => (bool) $p->is_visible,
            'price_display' => $p->price === null ? null : Money::plain((int) $p->price),
            'sale_display' => $p->sale_price === null ? null : Money::plain((int) $p->sale_price),
            'manage_stock' => (bool) $p->manage_stock,
            'stock' => $stock,
            'stock_status' => (string) $p->stock_status,
            'low' => (bool) $p->manage_stock && $stock !== null && $stock > 0 && $stock <= $at,
        ];
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($r = $this->refuse($request, 'catalog.view')) {
            return $r;
        }

        $p = DB::table('products as p')->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->whereNull('p.deleted_at')->where('p.id', $id)
            ->first([
                'p.id', 'p.name', 'p.slug', 'p.sku', 'p.image', 'p.images', 'p.type', 'p.status', 'p.is_visible',
                'p.price', 'p.sale_price', 'p.sale_starts_at', 'p.sale_ends_at', 'p.manage_stock', 'p.stock',
                'p.stock_status', 'p.short_description', 'p.description', 'b.name as brand', 'p.updated_at',
            ]);

        if ($p === null) {
            return response()->json(['ok' => false, 'code' => 'not_found'], 404);
        }

        $at = OwnerAppSettings::lowStock();
        $variants = DB::table('product_variants')->where('product_id', $id)->orderBy('position')->orderBy('id')->limit(60)
            ->get(['id', 'sku', 'price', 'sale_price', 'manage_stock', 'stock', 'stock_status']);
        $labels = $variants->isEmpty() ? collect() : DB::table('product_variant_attribute_value as pv')
            ->join('attribute_values as av', 'av.id', '=', 'pv.attribute_value_id')
            ->whereIn('pv.product_variant_id', $variants->pluck('id')->all())
            ->orderBy('av.position')
            ->get(['pv.product_variant_id', 'av.name'])
            ->groupBy('product_variant_id');

        $sold = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('i.product_id', $id)->whereIn('o.status', \App\Models\Order::REAL_STATUSES)->whereNull('o.deleted_at')
            ->where('o.created_at', '>=', now()->subDays(30))
            ->selectRaw('COALESCE(SUM(i.quantity), 0) as q, COALESCE(SUM(i.total), 0) as t')->first();
        $sold30 = (int) $sold->q;

        $mine = DB::table('category_product')->where('product_id', $id)->pluck('category_id')->map(fn ($v) => (int) $v)->all();

        $images = array_values(array_filter(array_merge([(string) $p->image], (array) (json_decode((string) $p->images, true) ?: [])), 'strlen'));

        return response()->json([
            'ok' => true,
            'product' => self::row($p, $at) + [
                'sku' => (string) ($p->sku ?? ''),
                'url' => \App\Support\Url::to(\App\Support\UrlScheme::product((string) $p->slug)),
                'images' => array_map(fn ($i) => OrdersController::thumb((string) $i), array_slice(array_unique($images), 0, 8)),
                'price' => self::major($p->price),
                'sale' => self::major($p->sale_price),
                'sale_window' => $p->sale_starts_at || $p->sale_ends_at,
                'short_description' => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $p->short_description)) ?? ''), 0, 400),
                'sold_30d' => $sold30,
                'revenue_30d' => $this->may($request, 'analytics.view') ? Money::plain((int) $sold->t) : null,
                'type_label' => match ((string) $p->type) { 'variable' => 'Variable · options', 'set' => 'Set · bundle', default => 'Simple · physical' },
                'category_ids' => $mine,
                'category_names' => $mine === [] ? [] : DB::table('categories')->whereIn('id', $mine)->orderBy('name')->pluck('name')->all(),
                'description' => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $p->description)) ?? ''), 0, 1200),
                'variants' => $variants->map(fn ($v) => [
                    'id' => (int) $v->id,
                    'label' => ($labels[$v->id] ?? collect())->pluck('name')->implode(' · ') ?: ('#'.$v->id),
                    'sku' => (string) ($v->sku ?? ''),
                    'price_display' => $v->price === null ? null : Money::plain((int) $v->price),
                    'stock' => $v->stock === null ? null : (int) $v->stock,
                    'stock_status' => (string) $v->stock_status,
                ])->values(),
                'can_edit' => $this->may($request, 'catalog.manage'),
                'updated_at' => self::iso($p->updated_at),
            ],
        ]);
    }

    /** The category picker's list, fetched only when the sheet opens. */
    public function categories(Request $request): JsonResponse
    {
        if ($r = $this->refuse($request, 'catalog.view')) {
            return $r;
        }

        return response()->json([
            'ok' => true,
            'categories' => DB::table('categories')->orderBy('depth')->orderBy('position')->orderBy('name')->orderBy('id')->limit(400)
                ->get(['id', 'name'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])->values(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($r = $this->refuse($request, 'catalog.manage')) {
            return $r;
        }

        if (! Product::query()->whereKey($id)->exists()) {
            return response()->json(['ok' => false, 'code' => 'not_found'], 404);
        }

        $admin = $this->admin($request);
        if ($admin !== null && ($who = EditPresence::heldByOther('product', (string) $id, $admin)) !== null) {
            return response()->json(['ok' => false, 'code' => 'edit_locked',
                'message' => "{$who} has this product open in the admin, so it was not saved. Try again when they have finished."], 409);
        }

        $input = [];
        foreach (self::EDITABLE as $k) {
            if ($request->exists($k)) {
                $input[$k] = $request->input($k);
            }
        }
        if ($input === []) {
            return response()->json(['ok' => false, 'message' => 'Nothing to save.'], 422);
        }

        $sub = Request::create($request->getRequestUri(), 'POST', [], [], [], $request->server->all());
        $sub->headers->set('Accept', 'application/json');
        $sub->setJson(new \Symfony\Component\HttpFoundation\InputBag($input));
        $sub->request = new \Symfony\Component\HttpFoundation\InputBag($input);
        $sub->setUserResolver(fn () => $admin);

        try {
            $res = app(ProductEditorApiController::class)->save($sub, $id);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'message' => collect($e->errors())->flatten()->first() ?? 'Check the form.', 'errors' => $e->errors()], 422);
        }

        if ($res->getStatusCode() !== 200) {
            $d = (array) $res->getData(true);

            return response()->json(['ok' => false, 'message' => (string) ($d['message'] ?? 'That was not saved.'), 'errors' => $d['errors'] ?? null], $res->getStatusCode());
        }

        return $this->show($request, $id);
    }

    private static function major(mixed $fils): ?string
    {
        return $fils === null ? null : str_replace(',', '', Money::amount((int) $fils, Money::minorExponent()));
    }

    private static function escape(string $s): string
    {
        return str_replace([self::LIKE_ESCAPE, '%', '_'], [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'], $s);
    }
}
