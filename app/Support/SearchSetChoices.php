<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * Store -> Site Search -> Sets in search -> "Set shown first, by brand".
 *
 * THE OWNER'S ASK (1 October 2026): "if multiple sets in anua, then it should
 * display random on every search or i should also control to choose which set
 * need to display against every brand in search box." The random/best rule
 * already existed; this is the second half -- one chosen set per brand, which
 * wins over the rule whenever a search names that brand.
 *
 * ── WHERE IT IS STORED, AND WHY THERE ─────────────────────────────────────
 *
 * One `settings` row, `search_sets_by_brand`, JSON `{"<brand id>": <set id>}`,
 * autoloaded. Not a table, for three reasons:
 *
 *   1. COST. The storefront reads it on every search keystroke, OUTSIDE the
 *      response cache (that is what makes the random rotation real). An
 *      autoloaded setting is already in SettingsService::all(), the payload
 *      the header has loaded anyway, so reading it is free. A table would be
 *      one more SELECT per keystroke, or a second cache to keep coherent.
 *   2. IT SURVIVES AN IMPORT RE-RUN. The importer never writes `settings`, and
 *      it matches brands on `source_term_id` and products on `wc_id`, updating
 *      rows IN PLACE -- so the ids this map holds are the ids after a re-run.
 *   3. A STALE ENTRY IS HARMLESS BY CONSTRUCTION. Nothing reads the map as a
 *      list of products. The storefront only honours an entry when that set is
 *      still among the visible sets the search found for that brand, so a set
 *      deleted, unpublished, hidden or emptied of the brand's products later
 *      falls back to the random/best rule silently -- no foreign key needed,
 *      and no way for the map to put a product on the page the shop would not
 *      show anyway.
 *
 * ── WHAT A SAVE ACCEPTS (CLAUDE.md rule 5) ────────────────────────────────
 *
 * A select stores one of its own options or the default. Each row's options
 * are the sets that fit that brand RIGHT NOW, so clean() re-asks the same
 * question at save time, in one query, and refuses any pair it does not
 * answer with: an unknown brand, a set id that does not exist, is not a set,
 * is not visible, or belongs to neither the brand nor holds its products.
 * Blank / 0 / null is "Follow the rule above" and removes the entry.
 */
final class SearchSetChoices
{
    public const KEY = 'search_sets_by_brand';

    /**
     * brand id => chosen set id, as saved, malformed entries dropped.
     *
     * Reads the AUTOLOADED payload only (SettingsService::all()), never get():
     * a key that has never been saved is absent from all(), and get() would
     * then take the whole-table snapshot -- one query on a search request that
     * had none. The key is always written autoloaded, so all() is the whole
     * truth.
     *
     * @return array<int, int>
     */
    public static function map(): array
    {
        $raw = app(SettingsService::class)->all()[self::KEY] ?? null;

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $brand => $set) {
            $brand = filter_var($brand, FILTER_VALIDATE_INT);
            $set = filter_var($set, FILTER_VALIDATE_INT);

            if ($brand !== false && $set !== false && $brand > 0 && $set > 0) {
                $out[$brand] = $set;
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * The one definition of "a set fits this brand" the storefront uses:
     * the set IS the brand's, or holds one of the brand's products in the box.
     * Applied inside SearchController::setCandidates(); the grouped query in
     * fitting() below is the same rule asked for every brand at once.
     */
    public static function whereFits(mixed $w, int $brandId): void
    {
        $w->where('brand_id', $brandId)
            ->orWhereIn('id', DB::table('product_set_items')
                ->join('products as kbb_member', 'kbb_member.id', '=', 'product_set_items.member_product_id')
                ->where('kbb_member.brand_id', $brandId)
                ->select('product_set_items.set_product_id'));
    }

    /**
     * Every (brand, visible set) pair that fits, in ONE statement.
     *
     * A UNION of "sets by their own brand" and "sets by the brands of the
     * products they hold" (UNION, not UNION ALL, so a set that is both counts
     * once), joined to brands for the name and to the set for its name.
     * Visibility is asked in a subquery on `products` alone, so the scope's
     * unqualified columns never meet the joined tables.
     *
     * @param  list<int>|null  $brandIds  limit to these brands (the save check)
     * @return list<array{brand_id:int, brand:string, set_id:int, set:string}>
     */
    public static function fitting(?array $brandIds = null): array
    {
        if ($brandIds === []) {
            return [];
        }

        $own = DB::table('products')
            ->where('type', 'set')
            ->whereNotNull('brand_id')
            ->select('brand_id', 'id as set_id');

        $held = DB::table('product_set_items')
            ->join('products as kbb_member', 'kbb_member.id', '=', 'product_set_items.member_product_id')
            ->whereNotNull('kbb_member.brand_id')
            ->select('kbb_member.brand_id', 'product_set_items.set_product_id as set_id');

        $visibleSets = Product::query()->visible()->where('type', 'set')->select('id');

        $rows = DB::query()
            ->fromSub($own->union($held), 'kbb_fit')
            ->join('brands as kbb_brand', 'kbb_brand.id', '=', 'kbb_fit.brand_id')
            ->join('products as kbb_set', 'kbb_set.id', '=', 'kbb_fit.set_id')
            ->whereIn('kbb_fit.set_id', $visibleSets)
            ->when($brandIds !== null, fn ($q) => $q->whereIn('kbb_fit.brand_id', $brandIds))
            ->orderBy('kbb_brand.name')
            ->orderBy('kbb_brand.id')
            ->orderByDesc('kbb_set.total_sales')
            ->orderByDesc('kbb_set.id')
            ->get([
                'kbb_fit.brand_id', 'kbb_brand.name as brand',
                'kbb_fit.set_id', 'kbb_set.name as set',
            ]);

        return $rows->map(fn ($r) => [
            'brand_id' => (int) $r->brand_id,
            'brand' => (string) $r->brand,
            'set_id' => (int) $r->set_id,
            'set' => (string) $r->set,
        ])->all();
    }

    /**
     * The admin list: one row per brand with at least one fitting set, its
     * sets (best sellers first, the order the "best" rule would use) and the
     * saved choice -- or 0, "Follow the rule above", when the saved choice no
     * longer fits, so the screen never shows a selection it could not save.
     *
     * @return list<array{brand_id:int, brand:string, count:int, sets:list<array{id:int, name:string}>, chosen:int}>
     */
    public static function adminRows(): array
    {
        $map = self::map();
        $out = [];

        foreach (self::fitting() as $r) {
            $b = $r['brand_id'];
            $out[$b] ??= ['brand_id' => $b, 'brand' => $r['brand'], 'count' => 0, 'sets' => [], 'chosen' => 0];
            $out[$b]['sets'][] = ['id' => $r['set_id'], 'name' => $r['set']];
            $out[$b]['count']++;

            if (($map[$b] ?? 0) === $r['set_id']) {
                $out[$b]['chosen'] = $r['set_id'];
            }
        }

        return array_values($out);
    }

    /**
     * Validate a submitted map against the shop as it is now.
     *
     * @return array{0: array<int, int>, 1: list<string>} [clean map, errors]
     */
    public static function clean(mixed $input): array
    {
        if (! is_array($input)) {
            return [[], ['Sets by brand must be a list of brand => set.']];
        }

        $wanted = [];
        $errors = [];

        foreach ($input as $brand => $set) {
            $brandId = filter_var($brand, FILTER_VALIDATE_INT);

            if ($brandId === false || $brandId < 1) {
                $errors[] = 'Unknown brand: '.mb_substr((string) $brand, 0, 40);

                continue;
            }

            // Blank, 0 or null: "Follow the rule above" -- no entry.
            if ($set === null || $set === '' || $set === 0 || $set === '0') {
                continue;
            }

            $setId = is_scalar($set) ? filter_var($set, FILTER_VALIDATE_INT) : false;

            if ($setId === false || $setId < 1) {
                $errors[] = 'Brand '.$brandId.': not a set this shop has.';

                continue;
            }

            $wanted[$brandId] = $setId;
        }

        if ($wanted !== []) {
            $fits = [];

            foreach (self::fitting(array_keys($wanted)) as $r) {
                $fits[$r['brand_id']][$r['set_id']] = $r['brand'];
            }

            foreach ($wanted as $brandId => $setId) {
                if (! isset($fits[$brandId][$setId])) {
                    $errors[] = 'Brand '.$brandId.': set '.$setId.' is not a visible set that fits this brand.';
                    unset($wanted[$brandId]);
                }
            }
        }

        ksort($wanted);

        return [$wanted, $errors];
    }

    /** Store a map clean() has already accepted. Autoloaded: see map(). */
    public static function save(array $clean): void
    {
        app(SettingsService::class)->set(self::KEY, (object) $clean, true);
    }
}
