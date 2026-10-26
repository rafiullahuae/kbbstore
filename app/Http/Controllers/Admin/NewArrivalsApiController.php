<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Services\NewArrivals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catalog -> New Arrivals (Lane NA). Every route is behind
 * `catalog.new_arrivals` (AdminCapabilities), which fails closed: a role not
 * granted it gets 403 on all six.
 *
 *   GET  /admin-api/new-arrivals          the settings card: rule, lists, count
 *   GET  /admin-api/new-arrivals/grid     Display: the list as the shop opens it
 *   GET  /admin-api/new-arrivals/search   find a product for a list (?q=)
 *   POST /admin-api/new-arrivals/settings switch, limit, window, include, exclude
 *   POST /admin-api/new-arrivals/save     the grid's Save: exclusions + order
 *   POST /admin-api/new-arrivals/reset    "Reset to newest first"
 */
final class NewArrivalsApiController extends Controller
{
    private const MAX_LIST = 1000;

    public function show(): JsonResponse
    {
        $category = $this->category();

        if ($category === null) {
            return response()->json(['ok' => true, 'category' => null, 'ready' => NewArrivals::ready()]);
        }

        return response()->json($this->state($category));
    }

    public function grid(): JsonResponse
    {
        $category = $this->categoryOrFail();

        return response()->json(['ok' => true, 'items' => NewArrivals::grid($category)]);
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['ok' => true, 'items' => []]);
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)).'%';
        $rows = Product::query()
            ->where(static fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(20)
            ->get(['id', 'name', 'status', 'created_at']);

        return response()->json(['ok' => true, 'items' => $rows->map(fn ($p) => $this->row($p))->all()]);
    }

    public function settings(Request $request): JsonResponse
    {
        $category = $this->categoryOrFail();
        $units = array_keys(NewArrivals::UNITS);
        $unit = $request->input('window_unit');
        $maxN = is_string($unit) && isset(NewArrivals::UNITS[$unit]) ? NewArrivals::UNITS[$unit][1] : 1;
        $data = $request->validate([
            'on' => ['required', 'boolean'],
            'limit' => ['required', 'integer', 'min:'.NewArrivals::LIMIT_MIN, 'max:'.NewArrivals::LIMIT_MAX],
            'window_on' => ['required', 'boolean'],
            'window_unit' => ['required', 'string', Rule::in($units)],
            'window_n' => ['required', 'integer', 'min:1', 'max:'.$maxN],
            'include' => ['present', 'array', 'max:'.self::MAX_LIST],
            'include.*' => ['integer', 'min:1'],
            'exclude' => ['present', 'array', 'max:'.self::MAX_LIST],
            'exclude.*' => ['integer', 'min:1'],
        ]);

        $id = (int) $category->id;
        NewArrivals::savePicks($id, 'include', $data['include']);
        NewArrivals::savePicks($id, 'exclude', $data['exclude']);
        NewArrivals::saveRule($category, $data);

        return response()->json($this->state($category->fresh()));
    }

    public function save(Request $request): JsonResponse
    {
        $category = $this->categoryOrFail();
        $data = $request->validate([
            'order' => ['present', 'array', 'max:'.(NewArrivals::LIMIT_MAX * 2)],
            'order.*' => ['integer', 'min:1'],
            'excluded' => ['present', 'array', 'max:'.(NewArrivals::LIMIT_MAX * 2)],
            'excluded.*' => ['integer', 'min:1'],
        ]);

        $result = NewArrivals::saveGrid($category, $data['order'], $data['excluded']);

        return response()->json(['ok' => true, 'result' => $result] + $this->state($category->fresh()));
    }

    public function reset(): JsonResponse
    {
        $category = $this->categoryOrFail();
        $result = NewArrivals::reset($category);

        return response()->json(['ok' => true, 'result' => $result] + $this->state($category));
    }

    private function category(): ?Category
    {
        return NewArrivals::managedCategory();
    }

    private function categoryOrFail(): Category
    {
        abort_unless(NewArrivals::ready(), 409, 'Run the migrations first.');
        $category = $this->category();
        abort_if($category === null, 404, 'There is no New Arrivals category.');

        return $category;
    }

    private function state(Category $category): array
    {
        $id = (int) $category->id;
        $rule = NewArrivals::rule($category) ?? (['on' => false] + NewArrivals::DEFAULT_RULE);
        $lists = [];

        foreach (['include', 'exclude'] as $kind) {
            $ids = NewArrivals::ready() ? NewArrivals::picks($id, $kind) : [];
            $rows = $ids === [] ? collect() : Product::withTrashed()->whereIn('id', $ids)->get(['id', 'name', 'status', 'created_at', 'deleted_at'])->keyBy('id');
            $lists[$kind] = array_values(array_filter(array_map(fn ($pid) => isset($rows[$pid]) ? $this->row($rows[$pid]) : null, $ids)));
        }

        return [
            'ok' => true,
            'ready' => NewArrivals::ready(),
            'category' => ['id' => $id, 'name' => (string) $category->name, 'url' => $category->url()],
            'rule' => $rule,
            'count' => \Illuminate\Support\Facades\DB::table('category_product')->where('category_id', $id)->count(),
            'include' => $lists['include'],
            'exclude' => $lists['exclude'],
            'units' => array_map(static fn ($u) => ['label' => $u[0], 'max' => $u[1]], NewArrivals::UNITS),
            'limits' => ['min' => NewArrivals::LIMIT_MIN, 'max' => NewArrivals::LIMIT_MAX],
        ];
    }

    private function row(object $p): array
    {
        return [
            'id' => (int) $p->id,
            'name' => (string) $p->name,
            'status' => ($p->deleted_at ?? null) !== null ? 'trash' : (string) $p->status,
            'date' => $p->created_at === null ? null : date('j M Y', strtotime((string) $p->created_at)),
        ];
    }
}
