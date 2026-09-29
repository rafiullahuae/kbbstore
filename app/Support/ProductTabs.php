<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use App\Models\ProductTab;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Facades\Cache;

/**
 * Which tabs a product page shows, in what order. (Lane PT)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THE TABS WERE BEFORE THIS FILE, EXACTLY
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Store\ProductController::tabs() built a list of four kinds of entry and
 * partials/product-tabs.blade.php drew it twice -- a tab strip with a clamped
 * panel above 720px, an accordion below it:
 *
 *   1. Description   __('store.product.tab_description')
 *                    $product->t('description') ?: $product->t('short_description')
 *   2. Ingredients   __('store.product.tab_ingredients')  $product->t('ingredients')
 *   3. How to use    __('store.product.tab_how_to_use')   $product->t('how_to_use')
 *   4. then every entry of the `product_tabs` SETTING, appended in the order
 *      that array happened to hold, as ['title' => ..., 'body' => ...].
 *
 * Then, in this order:
 *
 *   - ANY ENTRY WITH AN EMPTY TITLE OR AN EMPTY BODY WAS DROPPED, body measured
 *     as `trim(strip_tags($body)) !== ''`. That is what hides Ingredients on a
 *     product nobody has written an INCI list for: the tab is not rendered
 *     empty, it is not rendered at all, and the strip closes up.
 *   - WITH DEMO CONTENT ON and fewer than two tabs surviving, DemoContent::
 *     tabs() topped the list up, skipping any whose title was already present.
 *   - IF THE LIST WAS STILL EMPTY, one hard-coded English tab was substituted:
 *     'Description' / '<p>No description available.</p>'.
 *
 * The first tab was always the open one: `0 === $i` decides `.on` and `.open`
 * in the partial, and nothing else ever did.
 *
 * ALL FIVE OF THOSE BEHAVIOURS ARE PRESERVED HERE, byte for byte, and
 * StorefrontEnglishUnchangedTest is the instrument. The `product_tabs` setting
 * is still read and still lands in the same place. It has no writer anywhere in
 * this application -- nothing in the admin has ever set it -- but a live shop is
 * not a clean checkout and a setting with no writer is not a setting with no
 * value.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE FIVE DECISIONS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── 1. ONE ORDERING SCALE, NOT "GLOBALS THEN LOCALS" ───────────────────────
 *
 * Every tab carries a `position` on ONE scale, whether it is built in, global
 * or this product's own. So the answer to "where does a per-product tab sit
 * relative to the global ones" is: WHEREVER THE OWNER PUT IT.
 *
 * The alternative -- globals first, then per-product, or the reverse -- reads
 * simpler and is wrong the first time it is used. A per-product "Battery and
 * charging" tab belongs next to How to use; a global "Shipping & returns"
 * belongs last on every product in the shop. A fixed rule makes one of those
 * two impossible, and which one it makes impossible depends on a coin flip
 * taken by whoever wrote it.
 *
 * The defaults are chosen so that the simple case needs no thought: built-ins
 * at 10/20/30, the legacy setting's tabs at 40, a new global tab after those,
 * a new per-product tab after the globals. Someone who never opens the ordering
 * control gets exactly the arrangement they would have guessed.
 *
 * TIES ARE STABLE, NOT ARBITRARY. usort has been a stable sort since PHP 8.0,
 * and the list is assembled in a documented order before it is sorted, so two
 * tabs sharing a position keep the order this file built them in: built-ins,
 * legacy setting, globals, this product's own. Nothing in the output depends on
 * the order rows came back from the database.
 *
 * ── 2. A PRODUCT CAN HIDE A GLOBAL TAB. YES ────────────────────────────────
 *
 * The owner's own examples are the argument: a gift set has no INCI list, and a
 * facial device has no patch-test advice. Without a per-product hide the only
 * way to keep "Ingredients policy" off the one product it is wrong for is to
 * delete it from all seven hundred -- so the feature would be used once, found
 * to be wrong somewhere, and switched off everywhere.
 *
 * It costs nothing structurally, because the mechanism is the same row that
 * does the override: a per-product row with `source_key` naming the tab and
 * `is_enabled = 0`.
 *
 * A HIDE ALSO WORKS ON THE THREE BUILT-INS, for the same reason. A set whose
 * `ingredients` column was filled in by the WooCommerce import with the INCI of
 * one of its members can now be told not to show it, which was previously only
 * possible by emptying the column.
 *
 * ── 3. A PRODUCT CAN OVERRIDE A GLOBAL TAB'S TITLE OR BODY. YES ────────────
 *
 * Same row, with the title or body filled in. An empty box on the override row
 * means INHERIT -- not "blank" -- which is the only reading that lets the owner
 * override a body while keeping the global title, and it is the same convention
 * the Arabic boxes already use throughout this admin ("blank means not
 * translated yet").
 *
 * HOW THE OWNER TELLS THE TWO APART ON THE SCREEN. Catalog -> Product tabs ->
 * This product's tabs lists every global tab as a row of its own with one of
 * three states, named in words rather than implied by shading:
 *
 *     Inherited      the global's own title and body, shown greyed and
 *                    read-only, with an "Override here" button.
 *     Overridden     an amber "Overridden" pill, the product's own text in an
 *                    editable box, and a "Use the global one" button that
 *                    deletes the override row.
 *     Hidden here    a grey "Hidden here" pill and a "Show it again" button.
 *
 * ── 4. THE THREE BUILT-IN TABS STAY BUILT IN ───────────────────────────────
 *
 * They are NOT converted into rows in this table, and this is the decision that
 * was closest.
 *
 * FOR CONVERTING: one mental model, and the ordering control would then own
 * every tab uniformly.
 *
 * AGAINST, and it wins: the content of those three tabs is three COLUMNS on
 * `products`, written by the product editor, by the WooCommerce importer, by
 * the bulk-edit screen and by the machine-translation runner. Converting the
 * TABS means either (a) copying 700 products' description, ingredients and
 * how_to_use into a second table, which duplicates the source of truth and
 * leaves the product editor writing to a column nothing reads any more, or (b)
 * leaving the columns as the source and making the rows pure pointers -- which
 * is exactly what a built-in tab already is, with a table around it.
 *
 * So they stay columns, and this file gives them what the conversion was
 * actually for: A STABLE KEY (`builtin:description` and friends), A POSITION on
 * the same scale as everything else, and the same per-product hide and override
 * as any global tab. The owner gets one mental model -- every tab is a row with
 * an order and a switch -- without the catalogue being copied into a second
 * place.
 *
 * ── 5. THE COST, AND WHY A SHOP WITH NO TABS PAYS NOTHING ──────────────────
 *
 * Two things are cached, and both are configuration-sized rather than data-
 * sized -- the same argument SettingsService::snapshot() makes for reading the
 * whole settings table in one go:
 *
 *   GLOBALS  every global tab, in full. A handful of rows, read on every
 *            product page.
 *   SCOPED   the DISTINCT set of product ids that have any row at all. Integers
 *            only, one per product the owner has customised.
 *
 * A product whose id is not in SCOPED runs NO QUERY, because there is provably
 * nothing to fetch. A product in it runs exactly ONE, whether it has one
 * per-product tab or twenty. So the product page's query budget does not move
 * for a shop that has not used this feature, which is what
 * StorefrontQueryBudgetTest measures, and moves by one -- flat -- for a product
 * that has.
 *
 * The Arabic side adds AT MOST ONE MORE, and only when there is an authored tab
 * to translate: `title` is short and lives in the map that __() has already
 * loaded, so it costs nothing, and every `body` on the page is primed through
 * TranslationStore::longFor() in one batched call before any of them is read.
 * Reading them one at a time would have been a query per tab -- longFor()
 * memoises per row, so the priming call is what turns N into 1.
 *
 * BOTH CACHES EVICT FROM ProductTab's OWN MODEL HOOKS, so a writer that has
 * never heard of this class still evicts. The process-level memo beside the
 * cache is the Setting::map() trap, and the way out of it is the one
 * TranslationStore took: exactly one flush(), it clears both layers, and it is
 * registered in Tests\Support\StaticMemos so the suite clears it between tests.
 */
final class ProductTabs
{
    /**
     * The three tabs that are columns on `products` rather than rows here, and
     * the position each ships at.
     *
     * The KEYS are stored values -- they end up in `product_tabs.source_key` on
     * every override and hide the owner writes -- so they are renamed only on
     * purpose. The ORDER is the order the product page has always drawn them
     * in, and the numbers are spaced so a tab can be moved between two of them
     * without renumbering anything.
     */
    public const BUILTINS = [
        'description' => ['field' => 'description', 'string' => 'store.product.tab_description', 'position' => 10],
        'ingredients' => ['field' => 'ingredients', 'string' => 'store.product.tab_ingredients', 'position' => 20],
        'how_to_use' => ['field' => 'how_to_use', 'string' => 'store.product.tab_how_to_use', 'position' => 30],
    ];

    /**
     * Where the `product_tabs` SETTING's entries land.
     *
     * After the three built-ins and before anything authored here, which is
     * precisely where they were appended before this file existed. They all
     * share one position and the sort is stable, so their relative order is the
     * order the setting holds -- unchanged.
     */
    public const LEGACY_SETTING_POSITION = 40;

    /** Where a NEW global tab lands, before the owner moves it. */
    public const DEFAULT_GLOBAL_POSITION = 100;

    /** Where a NEW per-product tab lands: after the globals. */
    public const DEFAULT_PRODUCT_POSITION = 500;

    /**
     * The ordering scale's ceiling, and the column's.
     *
     * `position` is an unsignedSmallInteger, so the database refuses anything
     * past 65,535 anyway; this is the bound the controller validates against,
     * and it is well inside that on purpose. An ordering value is bounded
     * (CLAUDE.md rule 5) and a four-digit one is already more room than a
     * hundred tabs need.
     */
    public const MAX_POSITION = 9999;

    /**
     * The closed vocabulary of `source_key`, anchored.
     *
     * `global:` takes digits only, so the key can never carry a quote, an angle
     * bracket or a path separator whatever reaches the endpoint. The three
     * built-in names are spelled out rather than matched with `\w+`, because a
     * key outside this list names a tab that does not exist and would be a row
     * that silently does nothing forever.
     */
    public const SOURCE_KEY_PATTERN = '/^(builtin:(description|ingredients|how_to_use)|global:[1-9][0-9]{0,18})$/';

    /**
     * WHERE A GLOBAL TAB SHOWS. The owner's five options, and the only five
     * this application will store. (Lane PT, round 2)
     *
     *   global      every product. THE DEFAULT, and what every row written
     *               before this feature existed already holds.
     *   products    the product ids in `audience_ids`.
     *   categories  those categories AND EVERYTHING UNDER THEM. See
     *               categoryFamily() for the argument.
     *   brands      the brand ids in `audience_ids`.
     *   sets        every product whose `type` is 'set'. A TYPE MATCH and not a
     *               picker, so a set created next week is covered without
     *               anybody going back to tick it.
     *
     * The order is the order the select offers them in, and `global` is first
     * because it is the default and the common case.
     */
    public const AUDIENCES = ['global', 'products', 'categories', 'brands', 'sets'];

    /** What an unrecognised or absent audience reads as. */
    public const AUDIENCE_DEFAULT = 'global';

    /** The two audiences that take no target list at all. */
    public const AUDIENCES_WITHOUT_TARGETS = ['global', 'sets'];

    /**
     * How many ids one tab may target.
     *
     * A bound rather than a limit anybody will reach: CLAUDE.md rule 5 asks for
     * an ordering value to be bounded and the same argument applies to a list
     * that arrives in a request body. 200 is comfortably more products than a
     * shop would tick by hand -- past that the honest answer is a category or a
     * brand, which is what those two options are for.
     */
    public const MAX_AUDIENCE_IDS = 200;


    private const CACHE_GLOBALS = 'kbb.product_tabs.globals';

    private const CACHE_SCOPED = 'kbb.product_tabs.scoped';

    /**
     * The category tree, for `categories` targeting.
     *
     * Its own entry rather than a third field on the globals entry, because the
     * two evict for different reasons: a tab is written from the Product tabs
     * screen, and a category is re-parented from the Categories screen by
     * somebody who has never heard of this file. App\Models\Category evicts
     * this one from its own model hooks for exactly that reason -- the argument
     * TranslationStore makes about putting flush() on a hook rather than in the
     * callers.
     */
    private const CACHE_CATEGORY_TREE = 'kbb.product_tabs.category_tree';

    /** @var list<array<string, mixed>>|null */
    private static ?array $globalsMemo = null;

    /** @var list<int>|null */
    private static ?array $scopedMemo = null;

    /** @var array<int, int|null>|null category id => parent id */
    private static ?array $treeMemo = null;

    /**
     * Every global tab, enabled or not, in position order.
     *
     * Enabled or not because the admin screen wants both and the read path
     * filters -- one cache entry answering two questions rather than two
     * entries that can disagree about how many tabs exist.
     *
     * @return list<array<string, mixed>>
     */
    public static function globals(): array
    {
        if (self::$globalsMemo !== null) {
            return self::$globalsMemo;
        }

        try {
            $rows = Cache::rememberForever(self::CACHE_GLOBALS, static function (): array {
                return ProductTab::query()
                    ->whereNull('product_id')
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get(['id', 'title', 'body', 'position', 'is_enabled', 'audience', 'audience_ids'])
                    ->map(static fn (ProductTab $t): array => [
                        'id' => (int) $t->id,
                        'title' => (string) $t->title,
                        'body' => (string) ($t->body ?? ''),
                        'position' => (int) $t->position,
                        'is_enabled' => (bool) $t->is_enabled,
                        /*
                         * NORMALISED HERE, ONCE, AND NOT AT MATCH TIME. The
                         * column is text and a hand-edited row -- or a row
                         * written before these two columns existed -- can hold
                         * anything at all, so the cached shape is always a
                         * known audience and a list of positive ints. The
                         * matcher then has no parsing to do and no branch for
                         * bad data, which is what keeps it cheap enough to run
                         * for every tab on every product page.
                         */
                        'audience' => self::audienceOf($t->audience ?? null),
                        'audience_ids' => self::idsOf($t->audience_ids ?? null),
                    ])
                    ->all();
            });
        } catch (\Throwable) {
            /*
             * The same argument TranslationStore::map() makes: this is reached
             * from a storefront view, and it is reachable while the migration
             * that creates the table is still running on the live host -- the
             * package's files land before its migrations do. A product page
             * with no authored tabs is survivable; a 500 on every product page
             * for the length of an update is not.
             */
            $rows = [];
        }

        return self::$globalsMemo = is_array($rows) ? $rows : [];
    }

    /**
     * The ids of products that have at least one row of their own.
     *
     * This is the whole of why a shop that has not used this feature pays
     * nothing: a product whose id is absent has provably no per-product row, so
     * forProduct() does not ask.
     *
     * @return list<int>
     */
    public static function scopedProductIds(): array
    {
        if (self::$scopedMemo !== null) {
            return self::$scopedMemo;
        }

        try {
            $ids = Cache::rememberForever(self::CACHE_SCOPED, static function (): array {
                return ProductTab::query()
                    ->whereNotNull('product_id')
                    ->distinct()
                    ->orderBy('product_id')
                    ->pluck('product_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
            });
        } catch (\Throwable) {
            $ids = [];
        }

        return self::$scopedMemo = is_array($ids) ? $ids : [];
    }

    /**
     * The whole category tree: id => parent id. (Lane PT, round 2)
     *
     * ONE cached read of two integer columns. The shop nests product_cat four
     * levels deep and has a few dozen categories, which is configuration-sized
     * -- the same argument SettingsService makes for reading its whole table in
     * one go, and the reason a `categories`-targeted tab costs the product page
     * NO query at all once the entry is warm.
     *
     * Evicted by App\Models\Category's own model hooks, so re-parenting a
     * category from the Categories screen corrects every tab that targets it
     * without that screen knowing this file exists.
     *
     * @return array<int, int|null>
     */
    public static function categoryTree(): array
    {
        if (self::$treeMemo !== null) {
            return self::$treeMemo;
        }

        try {
            $rows = Cache::rememberForever(self::CACHE_CATEGORY_TREE, static function (): array {
                $out = [];

                foreach (Category::query()->get(['id', 'parent_id']) as $row) {
                    $out[(int) $row->id] = $row->parent_id === null ? null : (int) $row->parent_id;
                }

                return $out;
            });
        } catch (\Throwable) {
            // Reached from a storefront view, and reachable while the migration
            // that adds these columns is still running. A tab that does not
            // appear for one request is survivable; a 500 on every product page
            // for the length of an update is not.
            $rows = [];
        }

        return self::$treeMemo = is_array($rows) ? $rows : [];
    }

    /**
     * The categories a `categories` rule actually covers: the ones named, plus
     * EVERY CATEGORY UNDER THEM.
     *
     * ── A CHILD INHERITS ITS PARENT'S TAB. YES, AND THIS IS THE ARGUMENT ────
     *
     * It is the first question the owner will ask, so it is decided here rather
     * than fallen into.
     *
     * When he ticks "Skincare" he means skincare -- the whole of it. The
     * shop nests four deep (Skincare -> Face cleansers -> Makeup removers) and
     * a product lives at the BOTTOM of that chain, so a rule that matched only
     * the exact category ticked would put an ingredients policy on nothing at
     * all on the day it was written, and the owner would conclude the feature
     * does not work.
     *
     * The alternative -- tick every leaf -- fails in the direction that cannot
     * be seen: he ticks the eleven categories that exist today, adds a twelfth
     * next month, and the policy tab is silently missing from everything in it.
     * Nothing says so. A missing legal tab is a worse outcome than a tab on one
     * product too many, and the too-many case is visible on the page and fixed
     * with one per-product "Hide on this product" that already exists.
     *
     * Exact-only is still reachable when he wants it: tick the leaf category
     * rather than its parent. Descendants-only-if-you-ask is not reachable the
     * other way round without a second control, and a second control on this
     * row would be a switch the owner has to understand before he can write a
     * shipping paragraph.
     *
     * CYCLES CANNOT HANG THIS. `categories.parent_id` is a self-referencing
     * foreign key and nothing in the application refuses a loop, so the walk
     * carries a visited set. A cycle is then simply a family that stops growing
     * rather than a product page that never answers.
     *
     * @param  list<int>  $ids
     * @return array<int, true> a SET, so the match below is a hash lookup
     */
    public static function categoryFamily(array $ids): array
    {
        $tree = self::categoryTree();

        // parent => children, built once from the id => parent map.
        $children = [];

        foreach ($tree as $id => $parent) {
            if ($parent !== null) {
                $children[$parent][] = $id;
            }
        }

        $family = [];
        $queue = array_values(array_unique(array_map('intval', $ids)));

        while ($queue !== []) {
            $id = (int) array_pop($queue);

            if ($id <= 0 || isset($family[$id])) {
                // The visited check and the cycle guard are the same line.
                continue;
            }

            $family[$id] = true;

            foreach ($children[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $family;
    }

    /**
     * Does this global tab show on this product?
     *
     * ── NO QUERY, PER TAB OR PER RULE TYPE ─────────────────────────────────
     *
     * Everything this reads is already in hand: the tab's own row came out of
     * one cached entry, `type` and `brand_id` are columns on the product the
     * page has already loaded, the category tree is a second cached entry, and
     * the product's own category ids are resolved ONCE per page by the closure
     * the caller passes in -- and only when some enabled tab actually targets a
     * category. That is what keeps the product page's budget where it is with
     * thirty targeted tabs on the shop.
     *
     * ── AN AUDIENCE THIS BUILD DOES NOT KNOW SHOWS NOWHERE ────────────────
     *
     * Fails CLOSED, and audienceOf() has already turned such a value into
     * 'global'... for every path but this one, which is why the final `return
     * false` is reachable only if AUDIENCES grows and this match does not. A
     * future audience is by definition NARROWER than global -- nobody adds an
     * option meaning "everything", that is what global is -- so showing nowhere
     * is closer to what its author asked for than printing a paragraph on the
     * whole catalogue.
     *
     * @param  array<string, mixed>  $global   one row out of globals()
     * @param  callable(): list<int>  $categoryIds  this product's categories,
     *                                              resolved at most once
     */
    public static function showsOn(array $global, object $product, callable $categoryIds): bool
    {
        $audience = (string) ($global['audience'] ?? self::AUDIENCE_DEFAULT);

        if ($audience === 'global') {
            return true;
        }

        if ($audience === 'sets') {
            return (string) ($product->type ?? '') === 'set';
        }

        /** @var list<int> $ids */
        $ids = $global['audience_ids'] ?? [];

        if ($ids === []) {
            // A rule that names nothing matches nothing. NOT everything: an
            // empty picker is an unfinished tab, and the failure the owner can
            // see (it is not on any product) is the one he can fix.
            return false;
        }

        if ($audience === 'products') {
            return in_array((int) ($product->id ?? 0), $ids, true);
        }

        if ($audience === 'brands') {
            $brand = $product->brand_id ?? null;

            return $brand !== null && in_array((int) $brand, $ids, true);
        }

        if ($audience === 'categories') {
            $family = self::categoryFamily($ids);

            foreach ($categoryIds() as $id) {
                if (isset($family[$id])) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * Every category this product is in, as ids.
     *
     * BOTH HALVES, and that is not belt and braces. ProductEditorApiController
     * names it as the landmine in that file: `products.category_id` is the
     * primary category used for breadcrumbs and the product's own page, while
     * the archive filters through the `categories` many-to-many, and "the two
     * must move together". A rule that read only one of them would put a tab on
     * a product the owner can see in that category, or leave it off one he can,
     * depending on which of the two a past importer wrote.
     *
     * The relation is EAGER-LOADED by Store\ProductController::show()
     * (`categories:id,name,slug,path`), so on the page this is actually about
     * it costs nothing. Somewhere else it is one query, once, for the whole
     * page -- never one per tab.
     *
     * @return list<int>
     */
    public static function productCategoryIds(object $product): array
    {
        $ids = [];

        $primary = $product->category_id ?? null;

        if ($primary !== null && (int) $primary > 0) {
            $ids[] = (int) $primary;
        }

        try {
            foreach ($product->categories ?? [] as $category) {
                $id = (int) ($category->id ?? 0);

                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        } catch (\Throwable) {
            // A fixture or a projection that has no categories relation at all.
            // A product with no categories simply matches no category rule.
        }

        return array_values(array_unique($ids));
    }

    /** One of AUDIENCES, or the default. Never the string that arrived. */
    public static function audienceOf(mixed $value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, self::AUDIENCES, true) ? $value : self::AUDIENCE_DEFAULT;
    }

    /**
     * A stored or posted target list, as positive ints and nothing else.
     *
     * Accepts the JSON string the column holds and the array a request sends,
     * because both reach this and a second normaliser is a second thing to get
     * wrong. Anything that is not a positive integer is DROPPED rather than
     * coerced: `"7abc"` is not product 7, and (int) would say it was.
     *
     * @return list<int>
     */
    public static function idsOf(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $id) {
            /*
             * POSITIVE INTEGERS ONLY, and `is_int($id)` alone is not that --
             * `-3` is an int and would have gone in. A row id is never zero or
             * negative, and a negative one reaching the matcher would be an id
             * that can match nothing while looking like a real target on the
             * screen. A string is admitted only if it is entirely digits with
             * no leading zero, so "7abc" is not product 7 -- which is what
             * (int) would have said it was.
             */
            if (is_int($id) && $id > 0) {
                $out[] = $id;

                continue;
            }

            if (is_string($id) && preg_match('/^[1-9][0-9]{0,18}$/', $id) === 1) {
                $out[] = (int) $id;
            }
        }

        return array_values(array_slice(array_unique($out), 0, self::MAX_AUDIENCE_IDS));
    }

    /** Both layers, one call, called from ProductTab's own model hooks. */
    public static function flush(): void
    {
        self::$globalsMemo = null;
        self::$scopedMemo = null;
        self::$treeMemo = null;

        try {
            Cache::forget(self::CACHE_GLOBALS);
            Cache::forget(self::CACHE_SCOPED);
            Cache::forget(self::CACHE_CATEGORY_TREE);
        } catch (\Throwable) {
            // A model hook can fire inside a migration or a console command
            // where the cache store is not resolvable. An eviction that could
            // not happen must never take down the write.
        }
    }

    /** The process memo alone. Registered in Tests\Support\StaticMemos. */
    public static function forgetMemo(): void
    {
        self::$globalsMemo = null;
        self::$scopedMemo = null;
        self::$treeMemo = null;
    }

    /**
     * The tab list for one product page.
     *
     * Returns exactly the shape partials/product-tabs.blade.php has always been
     * handed: a list of ['title' => string, 'body' => string], first one open.
     * The demo top-up and the never-empty fallback stay in the controller,
     * where they were, because both are about the SHOP being empty rather than
     * about what a tab is.
     *
     * @param  list<array<string, mixed>>  $legacySettingTabs  the `product_tabs` setting
     * @return list<array{title: string, body: string}>
     */
    public static function forProduct(object $product, array $legacySettingTabs = []): array
    {
        $entries = self::builtins($product);

        foreach ($legacySettingTabs as $custom) {
            if (! is_array($custom)) {
                continue;
            }

            $entries[] = [
                'key' => null,
                'title' => (string) ($custom['title'] ?? ''),
                'body' => (string) ($custom['body'] ?? ''),
                'position' => self::LEGACY_SETTING_POSITION,
            ];
        }

        $productId = (int) ($product->id ?? 0);
        $rows = self::rowsFor($productId);

        // The overrides, keyed by what they cover, so applying one is a lookup
        // rather than a scan. A second row covering the same key cannot happen
        // -- the controller refuses it -- and if one ever did, the last wins,
        // which is at least deterministic.
        $overrides = [];

        foreach ($rows as $row) {
            if ($row['source_key'] !== null) {
                $overrides[$row['source_key']] = $row;
            }
        }

        /*
         * THIS PRODUCT'S CATEGORY IDS, RESOLVED AT MOST ONCE AND ONLY IF ASKED.
         * (Lane PT, round 2)
         *
         * A closure rather than a value, because the common shop has no
         * `categories`-targeted tab at all and must not pay for the feature:
         * showsOn() calls it only on the `categories` branch, and the memo
         * means ten targeted tabs resolve the list once between them. On the
         * product page it is free either way -- ProductController eager-loads
         * the relation -- but "free because somebody else loaded it" is not a
         * thing to rely on from a support class.
         */
        $memoisedCategoryIds = null;

        $categoryIds = static function () use ($product, &$memoisedCategoryIds): array {
            return $memoisedCategoryIds ??= self::productCategoryIds($product);
        };

        // The globals, each already knowing its own key so an override finds it.
        foreach (self::globals() as $global) {
            if (! $global['is_enabled']) {
                continue;
            }

            /*
             * WHERE IT SHOWS. A tab whose rule does not match this product is
             * not added at all -- not added and hidden, which would leave the
             * strip with a gap and the accordion with a separator over nothing.
             * The list this builds is the list the template draws, so a tab
             * that matches nothing is simply absent everywhere.
             */
            if (! self::showsOn($global, $product, $categoryIds)) {
                continue;
            }

            $entries[] = [
                'key' => 'global:' . $global['id'],
                'title' => $global['title'],
                'body' => $global['body'],
                'position' => $global['position'],
                'model' => $global['id'],
            ];
        }

        // This product's own tabs, which cover nothing and are simply added.
        foreach ($rows as $row) {
            if ($row['source_key'] !== null || ! $row['is_enabled']) {
                continue;
            }

            $entries[] = [
                'key' => null,
                'title' => $row['title'],
                'body' => $row['body'],
                'position' => $row['position'],
                'model' => $row['id'],
            ];
        }

        $entries = self::applyOverrides($entries, $overrides);

        /*
         * PRIMED IN ONE CALL, BEFORE ANY BODY IS READ. longFor() memoises per
         * row and records the ids that have no translation, so asking for all
         * of them here turns what would be one query per authored tab into one
         * query for the page -- and into NO query at all in English, or when
         * the page carries no authored tab.
         */
        self::primeTranslations($entries);

        $out = [];

        foreach ($entries as $entry) {
            $title = self::translated($entry, 'title');
            $body = self::translated($entry, 'body');

            /*
             * THE DROP RULE, UNCHANGED. `trim(strip_tags($body)) !== ''` and
             * not RichText::isBlank(): isBlank() decodes entities and
             * normalises a non-breaking space, so it would drop a body of
             * `<p>&nbsp;</p>` that this shop renders today. Tighter is still a
             * change, and rule 1 says nothing that already works may change.
             */
            if ($title === '' || trim(strip_tags($body)) === '') {
                continue;
            }

            $out[] = ['title' => $title, 'body' => $body];
        }

        return $out;
    }

    /**
     * The three built-in entries, exactly as ProductController built them.
     *
     * @return list<array<string, mixed>>
     */
    private static function builtins(object $product): array
    {
        $out = [];

        foreach (self::BUILTINS as $key => $spec) {
            /*
             * Description falls back to the short description, and that is not
             * a tidy-up to fold into the loop: it is the behaviour the shop has
             * today, and a product with no long description but a short one
             * shows the short one under a heading that says "Description".
             */
            $body = $key === 'description'
                ? (string) ($product->t('description') ?: $product->t('short_description'))
                : (string) ($product->t($spec['field']) ?? '');

            $out[] = [
                'key' => 'builtin:' . $key,
                'title' => (string) __($spec['string']),
                'body' => $body,
                'position' => $spec['position'],
            ];
        }

        return $out;
    }

    /**
     * One product's rows, or none at all if it provably has none.
     *
     * @return list<array<string, mixed>>
     */
    private static function rowsFor(int $productId): array
    {
        if ($productId <= 0 || ! in_array($productId, self::scopedProductIds(), true)) {
            return [];
        }

        try {
            return ProductTab::query()
                ->where('product_id', $productId)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['id', 'source_key', 'title', 'body', 'position', 'is_enabled'])
                ->map(static fn (ProductTab $t): array => [
                    'id' => (int) $t->id,
                    'source_key' => $t->source_key === null ? null : (string) $t->source_key,
                    'title' => (string) $t->title,
                    'body' => (string) ($t->body ?? ''),
                    'position' => (int) $t->position,
                    'is_enabled' => (bool) $t->is_enabled,
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Hide, re-word and re-position, in that order.
     *
     * AN EMPTY BOX ON AN OVERRIDE ROW MEANS INHERIT, never "blank". That is the
     * only reading under which the owner can override a body and keep the
     * global's title, and it is the convention the Arabic boxes on this admin
     * already use. It is also why a hide does not need a body: the row exists
     * to carry `is_enabled = 0` and inherits everything else.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, array<string, mixed>>  $overrides
     * @return list<array<string, mixed>>
     */
    private static function applyOverrides(array $entries, array $overrides): array
    {
        if ($overrides === []) {
            return self::sorted($entries);
        }

        $out = [];

        foreach ($entries as $entry) {
            $key = $entry['key'] ?? null;
            $override = ($key !== null && isset($overrides[$key])) ? $overrides[$key] : null;

            if ($override === null) {
                $out[] = $entry;

                continue;
            }

            if (! $override['is_enabled']) {
                // Hidden on this product. Dropped, not rendered empty -- the
                // same disposal an empty built-in tab has always had.
                continue;
            }

            if ($override['title'] !== '') {
                $entry['title'] = $override['title'];
                $entry['title_model'] = $override['id'];
            }

            if (trim(strip_tags($override['body'])) !== '') {
                $entry['body'] = $override['body'];
                $entry['body_model'] = $override['id'];
            }

            $entry['position'] = $override['position'];
            $out[] = $entry;
        }

        return self::sorted($out);
    }

    /**
     * By position, and stable within it.
     *
     * usort is stable from PHP 8.0, and the list was assembled in a documented
     * order -- built-ins, the legacy setting, globals, this product's own -- so
     * a tie resolves to that order rather than to whatever the database
     * returned. Nothing in the rendered page depends on row order.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $entries): array
    {
        usort($entries, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return $entries;
    }

    /**
     * Every authored row on this page, translated in one query rather than N.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private static function primeTranslations(array $entries): void
    {
        if (Locale::isDefault()) {
            return;
        }

        $ids = [];

        foreach ($entries as $entry) {
            foreach (['model', 'title_model', 'body_model'] as $slot) {
                if (isset($entry[$slot])) {
                    $ids[] = (int) $entry[$slot];
                }
            }
        }

        if ($ids === []) {
            return;
        }

        TranslationStore::longFor(Locale::current(), 'product_tabs', array_values(array_unique($ids)));
    }

    /**
     * One field of one entry, in the language being served.
     *
     * A built-in's text is already translated -- it arrived through __() or
     * $product->t() -- so only an AUTHORED value has a row of its own to look
     * up, and only that value is handed to a ProductTab instance. `title_model`
     * and `body_model` exist because an override may replace one of the two and
     * inherit the other, and each half then has to be translated against the
     * row that actually holds it.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function translated(array $entry, string $field): string
    {
        $value = (string) ($entry[$field] ?? '');
        $id = $entry[$field . '_model'] ?? $entry['model'] ?? null;

        if ($id === null || Locale::isDefault()) {
            return $value;
        }

        /*
         * newFromBuilder rather than a fetched model: the row is already in
         * hand, and t() needs nothing from the database but the key and the
         * column it falls back to. The translation itself comes from the map
         * (title) or from the batch primeTranslations() has already run (body).
         */
        $model = (new ProductTab)->newFromBuilder([
            'id' => (int) $id,
            'title' => $entry['title'] ?? '',
            'body' => $entry['body'] ?? '',
        ]);

        $translated = $model->t($field);

        return is_string($translated) && $translated !== '' ? $translated : $value;
    }
}
