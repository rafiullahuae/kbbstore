<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CustomLabels;
use App\Support\ArabicSearch;
use App\Support\ImageVariants;
use App\Support\SearchTerms;
use App\Support\LostPictures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catalog -> Labels (Lane LBL): the owner's own product labels and the rules
 * that put them on products. Every route is `labels.manage` (owner, manager)
 * in AdminCapabilities and fails closed; nothing here is on /api/*.
 *
 * The product picker searches in the BROWSER over one compact index
 * (index()), so typing costs no request at all; Save sends the rules once.
 */
final class CustomLabelsApiController extends Controller
{
    /** Ceiling for any id list one save may carry (a whole catalogue, with room). */
    private const MAX_IDS = 20000;

    public const INDEX_KEY = 'kbb.labels.index';

    // ── the list ─────────────────────────────────────────────────────────

    public function list(): JsonResponse
    {
        return response()->json($this->state());
    }

    private function state(): array
    {
        $r = CustomLabels::resolve(false);
        $shop = DB::table('products')->where('status', 'publish')->where('is_visible', true)->pluck('id')->flip()->all();
        $counts = DB::table('product_label_targets')
            ->select('label_id', 'kind', DB::raw('count(*) as n'))
            ->groupBy('label_id', 'kind')->get();
        $by = [];

        foreach ($counts as $c) {
            $by[(int) $c->label_id][$c->kind] = (int) $c->n;
        }

        $out = [];

        foreach ($r['labels'] as $id => $row) {
            $out[] = $this->row($row) + [
                'count' => count(array_intersect_key($r['sets'][$id] ?? [], $shop)),
                'picked' => $by[$id]['product'] ?? 0,
                'cats' => $by[$id]['category'] ?? 0,
                'excluded' => $by[$id]['exclude'] ?? 0,
            ];
        }

        return ['labels' => $out, 'today' => \App\Support\StoreTime::now()->format('Y-m-d')];
    }

    private function row(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'name_ar' => (string) ($row->name_ar ?? ''),
            'text' => (string) $row->text,
            'text_ar' => (string) ($row->text_ar ?? ''),
            'bg' => CustomLabels::hex((string) $row->bg, '#C13E63'),
            'fg' => CustomLabels::hex((string) $row->fg, '#FFFFFF'),
            'shape' => in_array($row->shape, CustomLabels::SHAPES, true) ? $row->shape : 'A',
            'placement' => in_array($row->placement, CustomLabels::PLACES, true) ? $row->placement : 'both',
            'mode' => in_array($row->mode, CustomLabels::MODES, true) ? $row->mode : 'both',
            'starts_on' => $row->starts_on ? substr((string) $row->starts_on, 0, 10) : '',
            'ends_on' => $row->ends_on ? substr((string) $row->ends_on, 0, 10) : '',
            'priority' => (int) $row->priority,
            'active' => (bool) $row->active,
        ];
    }

    // ── one label, with its rules ────────────────────────────────────────

    public function show(int $id): JsonResponse
    {
        $row = DB::table('product_labels')->find($id);

        if ($row === null) {
            return response()->json(['message' => 'That label no longer exists.'], 404);
        }

        $picks = [];
        $cats = [];
        $excl = [];
        $deep = true;

        foreach (DB::table('product_label_targets')->where('label_id', $id)->orderBy('id')->get() as $t) {
            if ($t->kind === 'product') {
                $picks[] = (int) $t->target_id;
            } elseif ($t->kind === 'category') {
                $cats[] = (int) $t->target_id;
                $deep = (bool) $t->with_children;
            } elseif ($t->kind === 'exclude') {
                $excl[] = (int) $t->target_id;
            }
        }

        return response()->json(['label' => $this->row($row) + [
            'picks' => $picks, 'categories' => $cats, 'with_children' => $deep, 'excluded' => $excl,
        ]]);
    }

    // ── writes ───────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $id = DB::transaction(function () use ($data) {
            $id = (int) DB::table('product_labels')->insertGetId($this->columns($data) + ['created_at' => now(), 'updated_at' => now()]);
            $this->writeTargets($id, $data);

            return $id;
        });

        CustomLabels::saved();

        return response()->json(['id' => $id] + $this->state(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! DB::table('product_labels')->where('id', $id)->exists()) {
            return response()->json(['message' => 'That label no longer exists.'], 404);
        }

        $data = $this->validated($request);
        DB::transaction(function () use ($id, $data) {
            DB::table('product_labels')->where('id', $id)->update($this->columns($data) + ['updated_at' => now()]);
            DB::table('product_label_targets')->where('label_id', $id)->delete();
            $this->writeTargets($id, $data);
        });

        CustomLabels::saved();

        return response()->json(['id' => $id] + $this->state());
    }

    public function destroy(int $id): JsonResponse
    {
        DB::transaction(function () use ($id) {
            DB::table('product_label_targets')->where('label_id', $id)->delete();
            DB::table('product_labels')->where('id', $id)->delete();
        });

        CustomLabels::saved();

        return response()->json($this->state());
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);

        if (DB::table('product_labels')->where('id', $id)->update(['active' => (bool) $data['active'], 'updated_at' => now()]) === 0) {
            return response()->json(['message' => 'That label no longer exists.'], 404);
        }

        CustomLabels::saved();

        return response()->json($this->state());
    }

    public function priority(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['priority' => ['required', 'integer', 'min:1', 'max:99']]);

        if (DB::table('product_labels')->where('id', $id)->update(['priority' => (int) $data['priority'], 'updated_at' => now()]) === 0) {
            return response()->json(['message' => 'That label no longer exists.'], 404);
        }

        CustomLabels::saved();

        return response()->json($this->state());
    }

    public function duplicate(int $id): JsonResponse
    {
        $row = DB::table('product_labels')->find($id);

        if ($row === null) {
            return response()->json(['message' => 'That label no longer exists.'], 404);
        }

        $new = DB::transaction(function () use ($row, $id) {
            $copy = (array) $row;
            unset($copy['id']);
            $copy['name'] = mb_substr($copy['name'].' (copy)', 0, 80);
            $copy['active'] = false;
            $copy['created_at'] = $copy['updated_at'] = now();
            $new = (int) DB::table('product_labels')->insertGetId($copy);

            foreach (DB::table('product_label_targets')->where('label_id', $id)->get() as $t) {
                DB::table('product_label_targets')->insert(['label_id' => $new, 'kind' => $t->kind, 'target_id' => $t->target_id, 'with_children' => $t->with_children]);
            }

            return $new;
        });

        CustomLabels::saved();

        return response()->json(['id' => $new] + $this->state(), 201);
    }

    /**
     * Everything checked before anything is written: colours are #RRGGBB only,
     * shape/placement/mode one of their own options, texts plain strings
     * (escaped wherever they are printed), dates real and in order, and every
     * product and category id one that exists.
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'name_ar' => ['nullable', 'string', 'max:80'],
            'text' => ['required', 'string', 'max:'.CustomLabels::TEXT_MAX],
            'text_ar' => ['nullable', 'string', 'max:'.CustomLabels::TEXT_MAX],
            'bg' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'fg' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'shape' => ['required', 'string', 'in:'.implode(',', CustomLabels::SHAPES)],
            'placement' => ['required', 'string', 'in:'.implode(',', CustomLabels::PLACES)],
            'mode' => ['required', 'string', 'in:'.implode(',', CustomLabels::MODES)],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => array_merge(['nullable', 'date_format:Y-m-d'], $request->filled('starts_on') ? ['after_or_equal:starts_on'] : []),
            'priority' => ['required', 'integer', 'min:1', 'max:99'],
            'active' => ['required', 'boolean'],
            'picks' => ['present', 'array', 'max:'.self::MAX_IDS],
            'picks.*' => ['integer', 'min:1', 'distinct'],
            'categories' => ['present', 'array', 'max:2000'],
            'categories.*' => ['integer', 'min:1', 'distinct'],
            'with_children' => ['required', 'boolean'],
            'excluded' => ['present', 'array', 'max:'.self::MAX_IDS],
            'excluded.*' => ['integer', 'min:1', 'distinct'],
        ]);

        foreach (['name', 'name_ar', 'text', 'text_ar'] as $k) {
            $data[$k] = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($data[$k] ?? '')) ?? '');
        }

        if ($data['name'] === '' || $data['text'] === '') {
            throw ValidationException::withMessages(['text' => 'A label needs a name and the text on its badge.']);
        }

        $missing = fn (string $table, array $ids): int => count($ids) - array_sum(array_map(
            fn (array $chunk): int => DB::table($table)->whereIn('id', $chunk)->count(),
            array_chunk(array_values(array_unique($ids)), 800),
        ));

        foreach (['picks' => 'products', 'excluded' => 'products', 'categories' => 'categories'] as $k => $table) {
            if ($data[$k] !== [] && $missing($table, $data[$k]) > 0) {
                throw ValidationException::withMessages([$k => 'Some of the chosen '.($table === 'products' ? 'products' : 'categories').' no longer exist. Reload the screen and save again.']);
            }
        }

        return $data;
    }

    private function columns(array $d): array
    {
        return [
            'name' => $d['name'],
            'name_ar' => $d['name_ar'] !== '' ? $d['name_ar'] : null,
            'text' => $d['text'],
            'text_ar' => $d['text_ar'] !== '' ? $d['text_ar'] : null,
            'bg' => strtoupper($d['bg']),
            'fg' => strtoupper($d['fg']),
            'shape' => $d['shape'],
            'placement' => $d['placement'],
            'mode' => $d['mode'],
            'starts_on' => $d['starts_on'] ?? null,
            'ends_on' => $d['ends_on'] ?? null,
            'priority' => (int) $d['priority'],
            'active' => (bool) $d['active'],
        ];
    }

    /** The rules, kept whatever the mode, so switching back loses nothing. */
    private function writeTargets(int $id, array $d): void
    {
        $rows = [];

        foreach (array_unique($d['picks']) as $pid) {
            $rows[] = ['label_id' => $id, 'kind' => 'product', 'target_id' => (int) $pid, 'with_children' => false];
        }

        foreach (array_unique($d['categories']) as $cid) {
            $rows[] = ['label_id' => $id, 'kind' => 'category', 'target_id' => (int) $cid, 'with_children' => (bool) $d['with_children']];
        }

        foreach (array_unique($d['excluded']) as $pid) {
            $rows[] = ['label_id' => $id, 'kind' => 'exclude', 'target_id' => (int) $pid, 'with_children' => false];
        }

        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('product_label_targets')->insert($chunk);
        }
    }

    // ── the picker's search index ────────────────────────────────────────

    /**
     * Every product as one compact row -- [id, name, brand, sku, Arabic name,
     * [category ids], price in AED, 64px thumbnail] -- and the category tree,
     * built once and cached until a product, brand, category, category
     * assignment or Arabic name changes (the fingerprint below, one query),
     * with an ETag so the console downloads it again only when it changed.
     */
    public function index(Request $request): Response
    {
        $fp = $this->fingerprint();
        $etag = '"lbl-'.$fp.'"';

        if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        $hit = null;

        try {
            $hit = Cache::get(self::INDEX_KEY);
        } catch (\Throwable) {
        }

        if (! is_array($hit) || ($hit[0] ?? null) !== $fp || ! is_string($hit[1] ?? null)) {
            $hit = [$fp, json_encode($this->buildIndex(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)];

            try {
                Cache::put(self::INDEX_KEY, $hit, 86400 * 7);
            } catch (\Throwable) {
            }
        }

        return response($hit[1], 200)->header('Content-Type', 'application/json')->header('ETag', $etag);
    }

    /** One query: anything the index shows moved, or not. */
    private function fingerprint(): string
    {
        $row = (array) DB::selectOne(
            'select (select max(updated_at) from products) as p, (select count(*) from products) as pc,'
            .' (select max(updated_at) from brands) as b, (select count(*) from brands) as bc,'
            .' (select max(updated_at) from categories) as c, (select count(*) from categories) as cc,'
            .' (select count(*) from category_product) as cp, (select sum(product_id * 7 + category_id) from category_product) as cps,'
            ." (select max(updated_at) from translations where locale = 'ar' and field = 'name') as t,"
            ." (select count(*) from translations where locale = 'ar' and field = 'name') as tc"
        );

        return substr(hash('xxh128', serialize($row)), 0, 20);
    }

    private function buildIndex(): array
    {
        $brands = DB::table('brands')->pluck('name', 'id')->all();
        $ar = [];

        foreach (DB::table('translations')->where('locale', 'ar')->where('field', 'name')->where('status', 'published')
            ->whereIn('group', ['products', 'categories'])->get(['group', 'item_id', 'value']) as $t) {
            $ar[$t->group][(int) $t->item_id] = (string) $t->value;
        }

        $inCat = [];

        foreach (DB::table('category_product')->orderBy('category_id')->get(['product_id', 'category_id']) as $cp) {
            $inCat[(int) $cp->product_id][] = (int) $cp->category_id;
        }

        $rows = [];

        foreach (DB::table('products')->orderBy('name')->get(['id', 'name', 'sku', 'brand_id', 'price', 'sale_price', 'image', 'status', 'is_visible']) as $p) {
            $id = (int) $p->id;
            $price = $p->sale_price !== null && $p->price !== null && (int) $p->sale_price < (int) $p->price ? (int) $p->sale_price : (int) $p->price;
            $rows[] = [
                $id,
                (string) $p->name,
                (string) ($brands[$p->brand_id] ?? ''),
                (string) ($p->sku ?? ''),
                (string) ($ar['products'][$id] ?? ''),
                $inCat[$id] ?? [],
                round($price / 100, 2),
                $this->thumb($p->image),
                ($p->status === 'publish' && $p->is_visible) ? 1 : 0,
            ];
        }

        $cats = [];

        foreach (DB::table('categories')->orderBy('name')->get(['id', 'name', 'parent_id']) as $c) {
            $cats[] = [(int) $c->id, (string) $c->name, (string) ($ar['categories'][(int) $c->id] ?? ''), $c->parent_id === null ? 0 : (int) $c->parent_id];
        }

        return ['p' => $rows, 'c' => $cats, 'g' => self::synonymGroups(), 't' => ArabicSearch::TERMS, 'b' => ArabicSearch::BRANDS];
    }

    /**
     * The shop search's own synonym groups (SearchTerms::GROUPS), so "spf"
     * finds a sunscreen here exactly as it does in the shop. The constant is
     * private to its class and belongs to another lane, so it is read rather
     * than copied: a group added there reaches the picker with no change here.
     *
     * @return list<list<string>>
     */
    public static function synonymGroups(): array
    {
        try {
            $groups = (new \ReflectionClassConstant(SearchTerms::class, 'GROUPS'))->getValue();

            return is_array($groups) ? array_values($groups) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** The smallest img-cache copy on disk, or '' -- never the full-size original. */
    private function thumb(?string $image): string
    {
        $img = LostPictures::usable($image);

        if ($img === null || $img === '') {
            return '';
        }

        $set = ImageVariants::srcsetFor($img);

        if ($set === '') {
            return '';
        }

        return (string) strtok(trim(explode(',', $set)[0]), ' ');
    }
}
