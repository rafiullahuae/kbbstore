<?php

declare(strict_types=1);

namespace App\Support;

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

    private const CACHE_GLOBALS = 'kbb.product_tabs.globals';

    private const CACHE_SCOPED = 'kbb.product_tabs.scoped';

    /** @var list<array<string, mixed>>|null */
    private static ?array $globalsMemo = null;

    /** @var list<int>|null */
    private static ?array $scopedMemo = null;

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
                    ->get(['id', 'title', 'body', 'position', 'is_enabled'])
                    ->map(static fn (ProductTab $t): array => [
                        'id' => (int) $t->id,
                        'title' => (string) $t->title,
                        'body' => (string) ($t->body ?? ''),
                        'position' => (int) $t->position,
                        'is_enabled' => (bool) $t->is_enabled,
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

    /** Both layers, one call, called from ProductTab's own model hooks. */
    public static function flush(): void
    {
        self::$globalsMemo = null;
        self::$scopedMemo = null;

        try {
            Cache::forget(self::CACHE_GLOBALS);
            Cache::forget(self::CACHE_SCOPED);
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

        // The globals, each already knowing its own key so an override finds it.
        foreach (self::globals() as $global) {
            if (! $global['is_enabled']) {
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
