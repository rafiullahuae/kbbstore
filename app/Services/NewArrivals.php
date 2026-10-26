<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Support\ScopeOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * NEW ARRIVALS THAT LOOK AFTER THEMSELVES. (Lane NA)
 *
 * The owner: "whatever products we add, that should auto go into New arrivals
 * and display the most recent ones by default ... maximum 300 products ... give
 * to change the limit ... give ability to exclude any products", then "only keep
 * the last three months products ... we will add later in case we needed to add
 * something from the old dates", then "if new product added, it will come auto
 * on the top, unless we sort it again".
 *
 * ── THE DESIGN: REAL MEMBERSHIP ROWS, KEPT TRUE BY ONE SERVICE ─────────────
 *
 * The category stays an ordinary category. Its `category_product` rows are
 * written by sync() and nothing else while the rule is on, so every feature
 * that already reads categories -- filters, facets, counts, pagination, sort,
 * sold-out handling, sitemaps, feeds, the Arabic pages, the Reorder screen --
 * works on it unchanged. A virtual category would have to be taught to every
 * one of those readers; this teaches none of them.
 *
 * WHO IS IN: products that are published and visible now (Product::visible(),
 * the same scope the shop lists with), ADDED within the window (default the
 * last 3 months, or no age limit) OR on the owner's include list, MINUS the
 * exclude list -- exclusion wins when a product is on both -- newest first by
 * `products.created_at`, capped at the limit (default 300). Included products
 * count toward the limit and sort by their own date like everything else.
 *
 * "DATE ADDED" IS `products.created_at`. The WooCommerce importer writes it from
 * the Woo `date_created` / `post_date` (ProductImporter), so imported products
 * carry their original publish date, and the admin's own create stamps it
 * now(). It is also the column the shop's "Newest" sort reads, so this list and
 * that sort can never disagree about what "new" means.
 *
 * WHERE EACH ONE SITS: `category_product.category_position`, the column every
 * category's own order already lives in (App\Support\ScopeOrder), so the shop
 * reads the order off the rows it joins anyway -- no query per product. A sync
 * keeps the owner's arrangement: members stay in their relative order, a
 * product that enters goes in front of the first member OLDER than it (so a
 * brand-new product lands at the very top, and an old product the owner
 * includes lands among its contemporaries), and one that leaves is simply
 * removed. reset() throws the arrangement away and lays the list out newest
 * first.
 *
 * ── WHEN IT RUNS, AND WHAT IT COSTS ────────────────────────────────────────
 *
 * A product write only raises a flag (defer()): no query, so an import of a
 * thousand rows does not re-sync a thousand times. The flag is spent once, when
 * the process finishes (app()->terminating -- after the response has gone to
 * the browser under PHP-FPM), or at once if a New Arrivals page is drawn in the
 * same process. Settings changes sync at once. And `kbb:new-arrivals-sync`
 * runs every minute from routes/console.php: that is what ages a product out
 * when it turns older than the window, with nobody touching anything.
 *
 * One sync is two reads (who should be in; who is in) and, only when something
 * changed, one DELETE, one INSERT and one UPDATE per 500 rows. A second sync
 * with nothing to do writes nothing.
 */
final class NewArrivals
{
    public const TABLE = 'category_auto_picks';

    public const COLUMN = 'auto_rule';

    /** The category this screen looks after when none carries a rule yet. */
    public const SLUG = 'new-arrivals';

    public const DEFAULT_LIMIT = 300;

    public const LIMIT_MIN = 1;

    public const LIMIT_MAX = 1000;

    /** unit => [label, longest window allowed]. */
    public const UNITS = [
        'day' => ['days', 1095],
        'week' => ['weeks', 156],
        'month' => ['months', 36],
    ];

    public const DEFAULT_RULE = [
        'on' => true,
        'limit' => self::DEFAULT_LIMIT,
        'window_on' => true,
        'window_n' => 3,
        'window_unit' => 'month',
    ];

    private const CACHE_KEY = 'kbb.new_arrivals.rules';

    private const CHUNK = 500;

    private static bool $pending = false;

    private static ?object $hooked = null;

    /** How many syncs this process has run: the bulk-import test reads it. */
    public static int $runs = 0;

    // ── The rule ─────────────────────────────────────────────────────────────

    /**
     * A category's stored rule, cleaned; null when it has none. Read off the
     * row the caller already holds: no query.
     *
     * @return array{on:bool,limit:int,window_on:bool,window_n:int,window_unit:string}|null
     */
    public static function rule(?object $category): ?array
    {
        $raw = $category?->{self::COLUMN} ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? self::clean($decoded) : null;
    }

    /** Whether this category's list is kept automatically right now. */
    public static function isAuto(?object $category): bool
    {
        return (self::rule($category)['on'] ?? false) === true;
    }

    /**
     * Any input to a valid rule. Out-of-range numbers are clamped, an unknown
     * unit is the default one: a stored rule can never be something the sync
     * cannot run. (The admin endpoint refuses bad input first; this is the
     * belt to that brace.)
     */
    public static function clean(array $in): array
    {
        $unit = is_string($in['window_unit'] ?? null) && isset(self::UNITS[$in['window_unit']])
            ? $in['window_unit'] : self::DEFAULT_RULE['window_unit'];

        return [
            'on' => (bool) ($in['on'] ?? false),
            'limit' => max(self::LIMIT_MIN, min(self::LIMIT_MAX, (int) ($in['limit'] ?? self::DEFAULT_LIMIT))),
            'window_on' => (bool) ($in['window_on'] ?? true),
            'window_n' => max(1, min(self::UNITS[$unit][1], (int) ($in['window_n'] ?? self::DEFAULT_RULE['window_n']))),
            'window_unit' => $unit,
        ];
    }

    /** The oldest "date added" still inside the window; null for no age limit. */
    public static function cutoff(array $rule): ?Carbon
    {
        if (! $rule['window_on']) {
            return null;
        }

        $n = (int) $rule['window_n'];

        return match ($rule['window_unit']) {
            'day' => now()->subDays($n),
            'week' => now()->subWeeks($n),
            default => now()->subMonthsNoOverflow($n),
        };
    }

    /** Whether the schema this needs is there (a package applied before its migration). */
    public static function ready(): bool
    {
        try {
            return Schema::hasColumn('categories', self::COLUMN) && Schema::hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * id => rule for every category whose rule is ON, from a cache that every
     * category write and every rule change forgets. Usually one entry.
     *
     * @return array<int, array>
     */
    public static function activeRules(): array
    {
        try {
            $map = Cache::rememberForever(self::CACHE_KEY, static function (): array {
                if (! self::ready()) {
                    return [];
                }

                $out = [];

                foreach (DB::table('categories')->whereNotNull(self::COLUMN)->get(['id', self::COLUMN]) as $row) {
                    $rule = self::rule($row);

                    if ($rule !== null && $rule['on']) {
                        $out[(int) $row->id] = $rule;
                    }
                }

                return $out;
            });
        } catch (\Throwable) {
            return [];
        }

        return is_array($map) ? $map : [];
    }

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
        }
    }

    /**
     * The category Catalog -> New Arrivals looks after: the one carrying a rule,
     * else the one whose slug is new-arrivals. Null when the shop has neither.
     */
    public static function managedCategory(): ?Category
    {
        if (! self::ready()) {
            return Category::query()->where('slug', self::SLUG)->first();
        }

        return Category::query()->whereNotNull(self::COLUMN)->orderBy('id')->first()
            ?? Category::query()->where('slug', self::SLUG)->first();
    }

    // ── Deferring and coalescing ─────────────────────────────────────────────

    /**
     * A product was created, published, hidden, deleted... Raise the flag and
     * nothing else. No category is automatic: not even that.
     */
    public static function defer(): void
    {
        $app = app();

        if ((self::$pending && self::$hooked === $app) || self::activeRules() === []) {
            return;
        }

        self::$pending = true;

        // Once per application instance (one per request under PHP-FPM).
        if (self::$hooked !== $app) {
            self::$hooked = $app;
            $app->terminating(static function (): void {
                self::flushPending();
            });
        }
    }

    public static function pending(): bool
    {
        return self::$pending;
    }

    /** Spend the flag: one sync of every automatic category, however many writes raised it. */
    public static function flushPending(): void
    {
        if (! self::$pending) {
            return;
        }

        self::$pending = false;

        try {
            self::syncAll();
        } catch (\Throwable $e) {
            // The minute sync puts it right; a product save must never fail for this.
            Log::error('New Arrivals sync failed: '.$e->getMessage());
        }
    }

    /** Forget the flag without syncing: tests, and a fresh process state. */
    public static function resetState(): void
    {
        self::$pending = false;
        self::$hooked = null;
        self::$runs = 0;
    }

    /**
     * Model hooks. A product counts when it is created, or when anything that
     * decides whether it is listed (or how new it is) changes.
     */
    public static function listen(): void
    {
        $columns = ['status', 'is_visible', 'published_at', 'deleted_at', 'created_at'];

        Product::saved(static function (Product $product) use ($columns): void {
            if ($product->wasRecentlyCreated || $product->wasChanged($columns)) {
                self::defer();
            }
        });
        Product::deleted(static fn () => self::defer());
        Category::saved(static fn () => self::forget());
        Category::deleted(static fn () => self::forget());
    }

    // ── The sync ─────────────────────────────────────────────────────────────

    /** @return array<int, array{added:int,removed:int,moved:int}> */
    public static function syncAll(): array
    {
        $out = [];

        foreach (self::activeRules() as $id => $rule) {
            $out[$id] = self::sync($id, $rule);
        }

        return $out;
    }

    /**
     * Make one category's rows what its rule says. Idempotent.
     *
     * @param  bool  $reset  lay the list out newest first, dropping the owner's order
     * @param  list<int>|null  $preferred  an explicit order (the admin grid's Save)
     * @return array{added:int,removed:int,moved:int}
     */
    public static function sync(int $categoryId, ?array $rule = null, bool $reset = false, ?array $preferred = null): array
    {
        self::$runs++;
        $rule ??= self::activeRules()[$categoryId] ?? null;

        if ($rule === null || ! $rule['on']) {
            return ['added' => 0, 'removed' => 0, 'moved' => 0];
        }

        $target = self::targetRows($categoryId, $rule);           // newest first
        $current = DB::table('category_product')->where('category_id', $categoryId)
            ->pluck(ScopeOrder::CATEGORY_COLUMN, 'product_id')->all();

        $order = self::arrange($target, $current, $reset, $preferred);

        return self::write($categoryId, $order, $current);
    }

    /**
     * Who should be in, newest first: [[id, created timestamp|null], ...].
     *
     * @return list<array{0:int,1:?int}>
     */
    public static function targetRows(int $categoryId, array $rule, bool $withExcluded = false): array
    {
        $cutoff = self::cutoff($rule);
        $query = Product::query()->visible()->select(['products.id', 'products.created_at']);

        if (! $withExcluded) {
            $query->whereNotIn('products.id', self::pickQuery($categoryId, 'exclude'));
        }

        if ($cutoff !== null) {
            $query->where(static fn ($q) => $q->where('products.created_at', '>=', $cutoff)
                ->orWhereIn('products.id', self::pickQuery($categoryId, 'include')));
        }

        return $query->orderByDesc('products.created_at')->orderByDesc('products.id')
            ->limit((int) $rule['limit'])->toBase()->get()
            ->map(static fn ($r) => [(int) $r->id, $r->created_at === null ? null : strtotime((string) $r->created_at)])
            ->all();
    }

    private static function pickQuery(int $categoryId, string $kind): \Closure
    {
        return static fn ($q) => $q->select('product_id')->from(self::TABLE)
            ->where('category_id', $categoryId)->where('kind', $kind);
    }

    /**
     * The order the members should be in.
     *
     * @param  list<array{0:int,1:?int}>  $target  newest first
     * @param  array<int|string, int|null>  $current  product_id => position
     * @param  list<int>|null  $preferred
     * @return list<int>
     */
    public static function arrange(array $target, array $current, bool $reset = false, ?array $preferred = null): array
    {
        $rank = [];
        $date = [];

        foreach ($target as $i => [$id, $ts]) {
            $rank[$id] = $i;
            $date[$id] = $ts;
        }

        if ($reset) {
            return array_keys($rank);
        }

        if ($preferred !== null) {
            // The grid's order, for the ones still in.
            $base = [];

            foreach ($preferred as $id) {
                $id = (int) $id;

                if (isset($rank[$id]) && ! isset($base[$id])) {
                    $base[$id] = true;
                }
            }

            $base = array_keys($base);
        } else {
            // The members still in, in their present order; an unplaced one
            // after the placed ones, newest first among themselves.
            $base = array_values(array_filter(array_map('intval', array_keys($current)), static fn ($id) => isset($rank[$id])));
            usort($base, static function (int $a, int $b) use ($current, $rank): int {
                $pa = $current[$a] ?? null;
                $pb = $current[$b] ?? null;

                if (($pa === null) !== ($pb === null)) {
                    return $pa === null ? 1 : -1;
                }

                return [(int) $pa, $rank[$a]] <=> [(int) $pb, $rank[$b]];
            });
        }

        $inBase = array_flip($base);
        $entrants = [];

        foreach ($rank as $id => $_) {
            if (! isset($inBase[$id])) {
                $entrants[] = $id;   // newest first, because $rank is
            }
        }

        // Each entrant goes in front of the first member older than it.
        $newer = static fn (int $a, int $b): bool => $rank[$a] < $rank[$b];
        $out = [];
        $e = 0;

        foreach ($base as $member) {
            while ($e < count($entrants) && $newer($entrants[$e], $member)) {
                $out[] = $entrants[$e++];
            }

            $out[] = $member;
        }

        while ($e < count($entrants)) {
            $out[] = $entrants[$e++];
        }

        return $out;
    }

    /**
     * Rows to match $order, positions 1..n. Writes only what differs.
     *
     * @param  list<int>  $order
     * @param  array<int|string, int|null>  $current
     * @return array{added:int,removed:int,moved:int}
     */
    private static function write(int $categoryId, array $order, array $current): array
    {
        $want = [];

        foreach ($order as $i => $id) {
            $want[$id] = $i + 1;
        }

        $leave = [];
        $move = [];

        foreach ($current as $id => $pos) {
            $id = (int) $id;

            if (! isset($want[$id])) {
                $leave[] = $id;
            } elseif ($pos === null || (int) $pos !== $want[$id]) {
                $move[$id] = $want[$id];
            }
        }

        $add = [];

        foreach ($want as $id => $pos) {
            if (! array_key_exists($id, $current) && ! array_key_exists((string) $id, $current)) {
                $add[] = ['category_id' => $categoryId, 'product_id' => $id, ScopeOrder::CATEGORY_COLUMN => $pos];
            }
        }

        if ($leave === [] && $add === [] && $move === []) {
            return ['added' => 0, 'removed' => 0, 'moved' => 0];
        }

        DB::transaction(static function () use ($categoryId, $leave, $add, $move): void {
            foreach (array_chunk($leave, self::CHUNK) as $chunk) {
                DB::table('category_product')->where('category_id', $categoryId)->whereIn('product_id', $chunk)->delete();
            }

            foreach (array_chunk($add, self::CHUNK) as $chunk) {
                DB::table('category_product')->insertOrIgnore($chunk);
            }

            // One UPDATE per 500 rows, whatever moved: a CASE on the pivot's own key.
            foreach (array_chunk($move, self::CHUNK, true) as $chunk) {
                $case = '';
                $bindings = [];

                foreach ($chunk as $id => $pos) {
                    $case .= ' WHEN ? THEN ?';
                    $bindings[] = (int) $id;
                    $bindings[] = (int) $pos;
                }

                $bindings[] = $categoryId;
                $ids = array_map('intval', array_keys($chunk));
                $marks = implode(',', array_fill(0, count($ids), '?'));

                DB::update(
                    'UPDATE category_product SET '.ScopeOrder::CATEGORY_COLUMN.' = CASE product_id'.$case
                    .' END WHERE category_id = ? AND product_id IN ('.$marks.')',
                    array_merge($bindings, $ids)
                );
            }
        });

        self::afterWrite();

        return ['added' => count($add), 'removed' => count($leave), 'moved' => count($move)];
    }

    // ── The shop's order ─────────────────────────────────────────────────────

    /**
     * The order an automatic category opens on: the owner's arrangement (which
     * is newest first until he moves something), then newest, then id. Reads
     * ScopeOrder's join, which the category page has already made.
     */
    public static function orderShop(Builder $query): Builder
    {
        ScopeOrder::orderInCategory($query);

        return $query->orderByDesc('products.created_at')->orderByDesc('products.id');
    }

    // ── What Catalog -> New Arrivals writes ─────────────────────────────────

    /** @return list<int> */
    public static function picks(int $categoryId, string $kind): array
    {
        return DB::table(self::TABLE)->where('category_id', $categoryId)->where('kind', $kind)
            ->orderBy('created_at')->orderBy('product_id')->pluck('product_id')->map(static fn ($v) => (int) $v)->all();
    }

    /**
     * Replace one list. Only ids of real products are kept.
     *
     * @param  list<int>  $ids
     */
    public static function savePicks(int $categoryId, string $kind, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0)));
        $real = $ids === [] ? [] : Product::withTrashed()->whereIn('id', $ids)->pluck('id')->map(static fn ($v) => (int) $v)->all();
        $have = self::picks($categoryId, $kind);
        $now = now();

        $drop = array_values(array_diff($have, $real));
        $add = array_values(array_diff($real, $have));

        foreach (array_chunk($drop, self::CHUNK) as $chunk) {
            DB::table(self::TABLE)->where('category_id', $categoryId)->where('kind', $kind)->whereIn('product_id', $chunk)->delete();
        }

        foreach (array_chunk($add, self::CHUNK) as $chunk) {
            DB::table(self::TABLE)->insertOrIgnore(array_map(static fn ($id) => [
                'category_id' => $categoryId, 'product_id' => $id, 'kind' => $kind, 'position' => null, 'created_at' => $now,
            ], $chunk));
        }
    }

    /**
     * Store a rule and make the rows follow it.
     *
     * Switching ON keeps a copy of the category's hand-made rows first (only
     * the first time), so switching OFF can put them back exactly as they were:
     * a change with no way back is worse than no change.
     */
    public static function saveRule(Category $category, array $rule): void
    {
        $rule = self::clean($rule);
        $was = self::isAuto($category);
        $id = (int) $category->id;

        DB::table('categories')->where('id', $id)->update([self::COLUMN => json_encode($rule)]);
        $category->setAttribute(self::COLUMN, json_encode($rule));
        self::forget();

        if ($rule['on'] && ! $was) {
            self::snapshot($id);
            self::sync($id, $rule, true);
        } elseif (! $rule['on'] && $was) {
            self::restore($id);
        } elseif ($rule['on']) {
            self::sync($id, $rule);
        }
    }

    /** Copy the hand-made rows aside, once. */
    public static function snapshot(int $categoryId): void
    {
        if (DB::table(self::TABLE)->where('category_id', $categoryId)->where('kind', 'manual')->exists()) {
            return;
        }

        DB::table(self::TABLE)->insertUsing(
            ['category_id', 'product_id', 'kind', 'position', 'created_at'],
            DB::table('category_product')->where('category_id', $categoryId)
                ->select(['category_id', 'product_id', DB::raw("'manual'"), ScopeOrder::CATEGORY_COLUMN, DB::raw('CURRENT_TIMESTAMP')])
        );
    }

    /** Put the hand-made rows back, and forget the copy. */
    public static function restore(int $categoryId): void
    {
        $saved = DB::table(self::TABLE)->where('category_id', $categoryId)->where('kind', 'manual')->get(['product_id', 'position']);

        if ($saved->isEmpty()) {
            return;   // nothing was ever copied: leave the last automatic list as it is
        }

        DB::transaction(static function () use ($categoryId, $saved): void {
            DB::table('category_product')->where('category_id', $categoryId)->delete();

            foreach ($saved->chunk(self::CHUNK) as $chunk) {
                DB::table('category_product')->insertOrIgnore($chunk->map(static fn ($r) => [
                    'category_id' => $categoryId, 'product_id' => (int) $r->product_id, ScopeOrder::CATEGORY_COLUMN => $r->position,
                ])->values()->all());
            }

            DB::table(self::TABLE)->where('category_id', $categoryId)->where('kind', 'manual')->delete();
        });

        self::afterWrite();
    }

    /**
     * Catalog -> New Arrivals -> Display: the list exactly as the shop opens it,
     * plus the excluded products that would otherwise be in, in their natural
     * place and flagged, so one tap can bring them back.
     *
     * The members come from the SAME pieces the category page is built from
     * (Product::visible(), ScopeOrder::inCategory over CategoryRollup's ids,
     * orderShop(), SoldOut::apply()), so the two cannot disagree.
     *
     * @return list<array{id:int,name:string,image:string,date:?string,excluded:bool,sold_out:bool,url:string}>
     */
    public static function grid(Category $category): array
    {
        $id = (int) $category->id;
        $cols = ['products.id', 'products.slug', 'products.name', 'products.image', 'products.created_at', 'products.stock_status'];

        $query = Product::query()->visible()->select($cols);
        ScopeOrder::inCategory($query, $id, \App\Support\CategoryRollup::idsFor($category));
        self::orderShop($query);
        \App\Support\SoldOut::apply($query, \App\Support\SoldOut::for($category));
        $members = $query->toBase()->get()->all();

        $out = [];
        $seen = [];

        foreach ($members as $p) {
            $seen[(int) $p->id] = true;
            $out[] = [$p, false];
        }

        $rule = self::rule($category);
        $excluded = self::picks($id, 'exclude');

        if ($rule !== null && $excluded !== []) {
            $flip = array_flip($excluded);
            $wanted = array_values(array_filter(
                array_map(static fn ($r) => $r[0], self::targetRows($id, $rule, true)),
                static fn ($pid) => isset($flip[$pid]) && ! isset($seen[$pid])
            ));

            if ($wanted !== []) {
                $rows = Product::query()->select($cols)->whereIn('products.id', $wanted)
                    ->orderByDesc('products.created_at')->orderByDesc('products.id')->toBase()->get()->all();
                $merged = [];
                $e = 0;
                $newer = static fn ($a, $b): bool => [strtotime((string) $a->created_at), (int) $a->id] > [strtotime((string) $b->created_at), (int) $b->id];

                foreach ($out as $item) {
                    while ($e < count($rows) && $newer($rows[$e], $item[0])) {
                        $merged[] = [$rows[$e++], true];
                    }

                    $merged[] = $item;
                }

                while ($e < count($rows)) {
                    $merged[] = [$rows[$e++], true];
                }

                $out = $merged;
            }
        }

        return array_map(static fn (array $item) => self::card($item[0], $item[1]), $out);
    }

    /** One grid card: only what the screen draws. */
    public static function card(object $p, bool $excluded): array
    {
        $image = (string) ($p->image ?? '');
        $thumb = $image;

        if ($image !== '') {
            // The 200px img-cache copy when it is on disk; the original otherwise.
            $set = \App\Support\ImageVariants::srcsetFor($image);

            foreach (explode(',', $set) as $candidate) {
                $parts = preg_split('/\s+/', trim($candidate));

                if (($parts[1] ?? '') === '200w') {
                    $thumb = $parts[0];
                    break;
                }
            }
        }

        return [
            'id' => (int) $p->id,
            'name' => (string) $p->name,
            'image' => $thumb,
            'date' => $p->created_at === null ? null : date('j M Y', strtotime((string) $p->created_at)),
            'excluded' => $excluded,
            'sold_out' => ($p->stock_status ?? 'instock') === 'outofstock',
            'url' => \App\Support\Url::to('/product/'.$p->slug.'/'),
        ];
    }

    /**
     * The grid's Save: its exclusions and its order, in one go. Products the
     * grid shows that are NOT marked come off the exclude list; products it
     * marks go on it. The order is the grid's, for whatever stays in.
     *
     * @param  list<int>  $order  every id on the grid, in the grid's order
     * @param  list<int>  $excluded  the ones marked excluded
     */
    public static function saveGrid(Category $category, array $order, array $excluded): array
    {
        $id = (int) $category->id;
        $order = array_values(array_unique(array_map('intval', $order)));
        $marked = array_values(array_intersect(array_unique(array_map('intval', $excluded)), $order));
        $have = self::picks($id, 'exclude');
        $onGrid = array_flip($order);

        // Exclusions the grid did not show stay as they were.
        $keep = array_values(array_filter($have, static fn ($pid) => ! isset($onGrid[$pid])));
        self::savePicks($id, 'exclude', array_merge($keep, $marked));

        $flip = array_flip($marked);
        $in = array_values(array_filter($order, static fn ($pid) => ! isset($flip[$pid])));

        return self::sync($id, self::rule($category), false, $in);
    }

    /** Throw the owner's arrangement away: newest first. */
    public static function reset(Category $category): array
    {
        return self::sync((int) $category->id, self::rule($category), true);
    }

    private static function afterWrite(): void
    {
        // The sidebar's per-category counts and the rollup ids read these rows.
        try {
            \App\Http\Controllers\Store\ShopController::flushSidebarCache();
        } catch (\Throwable) {
        }
    }
}
