<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * "Buy these together": WHICH CATEGORIES GO WITH WHICH.            (Lane RB)
 *
 * The owner, 2 October:
 *
 *   "first product will be the same as on the product page. second, if the
 *    frst product is from sunscreen, then other 3 will be from moisturizer,
 *    toners, cleansing oils, face masks. one from each [...] give option to
 *    choose the criteria at the backend."
 *
 * ── TWO LAYERS: A DEFAULT THAT READS HIS SHELVES, AND HIS OWN OVERRIDE ──────
 *
 *   1. DEFAULTS BY KIND. Every category is given a KIND by matching its name
 *      and slug against KINDS (sunscreen, toner, serum, moisturizer, mask, …),
 *      in KINDS' order — so "Cleansing Oils" is a cleansing oil before the
 *      word "cleansing" can make it a cleanser, and "Sun Cream" is a sunscreen
 *      before "cream" can make it a moisturizer. DEFAULT_PAIRS says which kinds
 *      complement which, and each complementary kind is answered by ONE of his
 *      categories (representative(): the shallowest, then his own order).
 *      Nothing is hard-coded to an id or a slug of this shop's, so the same
 *      table resolves on the live catalogue, on a fresh install and in the
 *      test suite.
 *
 *   2. HIS OVERRIDE, per category, in `settings.bt_pairs`:
 *        { "<category id>": [<category id>, …] }   — that list, in that order
 *        { "<category id>": [] }                     — no pairing: best sellers
 *        (absent)                                    — the default above
 *      Every id is re-checked against the category table on the way IN
 *      (validateOverrides()) and on the way OUT (pairsFor()), so a category
 *      deleted since he saved simply drops out of the list.
 *
 * ── AND IT COSTS NO QUERY ON A WARM PAGE ───────────────────────────────────
 *
 * The category table is read once into `kbb.bt.cats` (id, name, slug,
 * parent, depth, position) and kept ten minutes; the override is a row of
 * `settings`, read through the snapshot the request already holds.
 */
class BuyTogetherPairs
{
    public const SETTING = 'bt_pairs';

    public const CACHE_KEY = 'kbb.bt.cats';

    public const TTL = 600;

    /** The most complementary categories one category can name. */
    public const MAX_PAIRS = 5;

    /**
     * kind => [label, patterns]. ORDER MATTERS: the first kind whose pattern
     * matches a category's "slug name" text is its kind.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    public const KINDS = [
        'cleansing_oil' => ['Cleansing oils & balms', ['/cleansing[ -]?(oil|balm)/', '/\boil[ -]?cleanser/', '/make[ -]?up[ -]?remover/']],
        'sunscreen' => ['Sunscreens', ['/sun[ -]?(screen|cream|care|block|stick|protection)/', '/\bspf\b/']],
        'set' => ['Sets & kits', ['/\b(sets?|kits?|bundles?)\b/']],
        'device' => ['Beauty devices', ['/\bdevices?\b/', '/\btools?\b/', '/massager/']],
        'hair' => ['Hair care', ['/\bhair\b/', '/shampoo/', '/conditioner/', '/\bscalp\b/']],
        'lip' => ['Lip care', ['/\blips?\b/']],
        'eye' => ['Eye care', ['/\beyes?\b/']],
        'mask' => ['Face masks', ['/\bmasks?\b/']],
        'exfoliator' => ['Exfoliators', ['/exfoliat/', '/\bpeel(s|ing)?\b/', '/\bscrubs?\b/']],
        'toner' => ['Toners', ['/\btoners?\b/', '/\bmists?\b/']],
        'serum' => ['Serums', ['/\bserums?\b/', '/\bampoules?\b/', '/\bessences?\b/']],
        'moisturizer' => ['Moisturizers', ['/moisturi[sz]/', '/\bcreams?\b/', '/\blotions?\b/', '/\bemulsions?\b/']],
        'cleanser' => ['Cleansers & face washes', ['/cleanser/', '/\b(face|facial)[ -]?wash/', '/\bfoam/', '/\bcleansing\b/']],
        'skincare' => ['Skincare (general)', ['/\bskin[ -]?care\b/']],
    ];

    /**
     * kind => the kinds that complement it, in the order the section shows them.
     * Sunscreen's row is his sentence word for word.
     *
     * @var array<string, list<string>>
     */
    public const DEFAULT_PAIRS = [
        'sunscreen' => ['moisturizer', 'toner', 'cleansing_oil', 'mask'],
        'cleanser' => ['toner', 'serum', 'moisturizer', 'sunscreen'],
        'cleansing_oil' => ['cleanser', 'toner', 'moisturizer', 'sunscreen'],
        'toner' => ['serum', 'moisturizer', 'sunscreen', 'mask'],
        'serum' => ['moisturizer', 'sunscreen', 'toner', 'cleanser'],
        'moisturizer' => ['serum', 'sunscreen', 'toner', 'cleanser'],
        'mask' => ['toner', 'serum', 'moisturizer', 'sunscreen'],
        'exfoliator' => ['toner', 'serum', 'moisturizer', 'sunscreen'],
        'eye' => ['serum', 'moisturizer', 'sunscreen', 'mask'],
        'lip' => ['moisturizer', 'sunscreen', 'mask', 'eye'],
        // A shampoo goes with a conditioner: one more from its own shelf first.
        'hair' => ['hair', 'mask', 'lip', 'sunscreen'],
        'device' => ['serum', 'mask', 'moisturizer', 'eye'],
        'set' => ['sunscreen', 'mask', 'lip', 'eye'],
        'skincare' => ['toner', 'serum', 'moisturizer', 'sunscreen'],
    ];

    /** @var array<int, array{id: int, name: string, slug: string, parent_id: int|null, depth: int, position: int, kind: string|null}>|null */
    private ?array $memo = null;

    public function __construct(private SettingsService $settings) {}

    /** Forget the cached category list — after a category is saved. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Every category, with its kind. One query, cached ten minutes.
     *
     * @return array<int, array{id: int, name: string, slug: string, parent_id: int|null, depth: int, position: int, kind: string|null}>
     */
    public function categories(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $rows = Cache::get(self::CACHE_KEY);

        if (! is_array($rows)) {
            $rows = [];

            foreach (Category::query()->orderBy('id')->get(['id', 'name', 'slug', 'parent_id', 'depth', 'position']) as $c) {
                $rows[(int) $c->id] = [
                    'id' => (int) $c->id,
                    'name' => (string) $c->name,
                    'slug' => (string) $c->slug,
                    'parent_id' => $c->parent_id === null ? null : (int) $c->parent_id,
                    'depth' => (int) $c->depth,
                    'position' => (int) $c->position,
                    'kind' => self::kindOf((string) $c->name, (string) $c->slug),
                ];
            }

            Cache::put(self::CACHE_KEY, $rows, self::TTL);
        }

        return $this->memo = $rows;
    }

    /** The kind a category's name and slug describe, or null. */
    public static function kindOf(string $name, string $slug): ?string
    {
        $text = mb_strtolower(str_replace(['-', '_'], ' ', $slug).' '.$name);

        foreach (self::KINDS as $kind => [, $patterns]) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern.'u', $text) === 1) {
                    return $kind;
                }
            }
        }

        return null;
    }

    /**
     * The one category that answers for a kind: the shallowest, then his own
     * order (`position`), then the oldest. Null when he has no such shelf.
     */
    public function representative(string $kind): ?int
    {
        $best = null;

        foreach ($this->categories() as $c) {
            if ($c['kind'] !== $kind) {
                continue;
            }

            if ($best === null
                || [$c['depth'], $c['position'], $c['id']] < [$best['depth'], $best['position'], $best['id']]) {
                $best = $c;
            }
        }

        return $best === null ? null : $best['id'];
    }

    /**
     * The default complementary categories of one category, resolved against
     * the shop's own shelves. Kinds the shop has no category for are skipped,
     * and a category is never listed twice.
     *
     * @return list<int>
     */
    public function defaultFor(int $categoryId): array
    {
        $kind = $this->categories()[$categoryId]['kind'] ?? null;

        if ($kind === null) {
            return [];
        }

        $out = [];

        foreach (self::DEFAULT_PAIRS[$kind] ?? [] as $want) {
            $id = $this->representative($want);

            if ($id !== null && ! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * His saved overrides, cleaned: known category ids only, each list a
     * de-duplicated list of known ids, at most MAX_PAIRS long.
     *
     * @return array<int, list<int>>
     */
    public function overrides(): array
    {
        $raw = $this->settings->get(self::SETTING, null);
        $raw = is_array($raw) ? $raw : [];
        $cats = $this->categories();
        $out = [];

        foreach ($raw as $key => $list) {
            $id = (int) $key;

            if (! isset($cats[$id]) || ! is_array($list)) {
                continue;
            }

            $out[$id] = $this->cleanList($list, $cats);
        }

        return $out;
    }

    /**
     * What one category pairs with: his override when he made one, else the default.
     *
     * @return list<int>
     */
    public function pairsFor(int $categoryId): array
    {
        $over = $this->overrides();

        return array_key_exists($categoryId, $over) ? $over[$categoryId] : $this->defaultFor($categoryId);
    }

    /**
     * Which of the product's categories decides its pairing.
     *
     * A product sits on several shelves — "Skincare" and "Sunscreens" both — so
     * the most SPECIFIC one that pairs with anything wins: one he set an
     * override on first, then the deepest, then a named kind over the general
     * "Skincare", then the product's own primary category, then the oldest.
     */
    public function anchorFor(Product $product): ?int
    {
        $cats = $this->categories();
        $over = $this->overrides();

        $ids = $product->relationLoaded('categories')
            ? $product->categories->pluck('id')->map(fn ($i) => (int) $i)->all()
            : $product->categories()->pluck('categories.id')->map(fn ($i) => (int) $i)->all();

        $primary = $product->category_id === null ? null : (int) $product->category_id;

        if ($primary !== null && ! in_array($primary, $ids, true)) {
            $ids[] = $primary;
        }

        $best = null;
        $bestRank = null;

        foreach (array_unique($ids) as $id) {
            if (! isset($cats[$id])) {
                continue;
            }

            $overridden = array_key_exists($id, $over);

            if (! $overridden && $cats[$id]['kind'] === null) {
                continue;
            }

            $rank = [
                $overridden ? 0 : 1,
                -$cats[$id]['depth'],
                $cats[$id]['kind'] === 'skincare' ? 1 : 0,
                $id === $primary ? 0 : 1,
                $id,
            ];

            if ($bestRank === null || $rank < $bestRank) {
                $best = $id;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /**
     * Validate a posted override map. Returns the clean map, or an error.
     *
     *   key    a category id that exists
     *   value  null or 'default' (remove the override), or a list of category
     *          ids that exist — at most MAX_PAIRS, duplicates refused
     *
     * The result is MERGED over the saved map: categories not posted keep
     * whatever they had.
     *
     * @return array<int, list<int>>|string
     */
    public function validateOverrides(mixed $posted): array|string
    {
        if (! is_array($posted)) {
            return 'The category pairs must be an object.';
        }

        $cats = $this->categories();
        $map = $this->overrides();

        foreach ($posted as $key => $list) {
            if (! is_numeric($key) || ! isset($cats[(int) $key])) {
                return 'Unknown category: '.(is_scalar($key) ? (string) $key : gettype($key)).'.';
            }

            $id = (int) $key;

            if ($list === null || $list === 'default') {
                unset($map[$id]);

                continue;
            }

            if (! is_array($list) || ! array_is_list($list)) {
                return "The pairs for category {$id} must be a list.";
            }

            if (count($list) > self::MAX_PAIRS) {
                return 'At most '.self::MAX_PAIRS." categories can pair with category {$id}.";
            }

            $clean = [];

            foreach ($list as $other) {
                if (! is_numeric($other) || ! isset($cats[(int) $other])) {
                    return 'Unknown category: '.(is_scalar($other) ? (string) $other : gettype($other)).'.';
                }

                if (in_array((int) $other, $clean, true)) {
                    return "A category is listed twice for category {$id}.";
                }

                $clean[] = (int) $other;
            }

            $map[$id] = $clean;
        }

        ksort($map);

        return $map;
    }

    /** @param array<int, list<int>> $clean */
    public function save(array $clean): void
    {
        $out = [];

        foreach ($clean as $id => $list) {
            $out[(string) $id] = array_values(array_map('intval', $list));
        }

        $this->settings->set(self::SETTING, $out);
    }

    /**
     * The admin's view of it: every category, its kind, its default and his
     * override, in the shop's own tree order.
     *
     * @return list<array<string, mixed>>
     */
    public function payload(): array
    {
        $cats = $this->categories();
        $over = $this->overrides();
        $rows = array_values($cats);

        // Tree order: a parent, then its children, by position then name.
        $children = [];

        foreach ($rows as $c) {
            $children[$c['parent_id'] ?? 0][] = $c;
        }

        foreach ($children as &$list) {
            usort($list, fn ($a, $b) => [$a['position'], mb_strtolower($a['name']), $a['id']] <=> [$b['position'], mb_strtolower($b['name']), $b['id']]);
        }
        unset($list);

        $out = [];
        $walk = function (int $parent, int $level) use (&$walk, &$out, $children, $over): void {
            foreach ($children[$parent] ?? [] as $c) {
                $out[] = [
                    'id' => $c['id'],
                    'name' => $c['name'],
                    'level' => $level,
                    'kind' => $c['kind'],
                    'kind_label' => $c['kind'] === null ? null : self::KINDS[$c['kind']][0],
                    'default' => $this->defaultFor($c['id']),
                    'custom' => array_key_exists($c['id'], $over) ? $over[$c['id']] : null,
                ];

                if ($level < 6) {
                    $walk($c['id'], $level + 1);
                }
            }
        };
        $walk(0, 0);

        // Anything the walk could not reach (a parent that no longer exists).
        $seen = array_flip(array_column($out, 'id'));

        foreach ($rows as $c) {
            if (! isset($seen[$c['id']])) {
                $out[] = [
                    'id' => $c['id'], 'name' => $c['name'], 'level' => 0, 'kind' => $c['kind'],
                    'kind_label' => $c['kind'] === null ? null : self::KINDS[$c['kind']][0],
                    'default' => $this->defaultFor($c['id']),
                    'custom' => array_key_exists($c['id'], $over) ? $over[$c['id']] : null,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $list
     * @param  array<int, array<string, mixed>>  $cats
     * @return list<int>
     */
    private function cleanList(array $list, array $cats): array
    {
        $out = [];

        foreach ($list as $other) {
            $id = is_numeric($other) ? (int) $other : 0;

            if (isset($cats[$id]) && ! in_array($id, $out, true)) {
                $out[] = $id;
            }

            if (count($out) >= self::MAX_PAIRS) {
                break;
            }
        }

        return $out;
    }
}
