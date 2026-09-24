<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\Translation;
use App\Support\Locale;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the translations table, once per request, without becoming the
 * Setting::map() trap.
 *
 * ── THE TRAP THIS IS WRITTEN AGAINST ────────────────────────────────────────
 *
 * CLAUDE.md records it: Setting::map() memoises in a process-level static as
 * well as in the cache, so within one long-lived process it never sees a write
 * made after the first call. Fine under PHP-FPM, where a request is a process.
 * A trap in tests and in queue workers — and a queue worker is exactly where
 * the order emails get rendered, which is exactly where this data is read.
 *
 * Both layers are kept, because both earn their place:
 *
 *   - the CACHE, because on shared hosting the driver is a file and a storefront
 *     page asks for dozens of strings; and
 *   - the per-process MEMO, because even one cache read per request is one file
 *     read, and the header alone asks for a dozen keys.
 *
 * What is different is that there is exactly ONE way to clear them, flush(),
 * it clears BOTH, and it is called from a model hook rather than from the
 * callers — so every writer evicts, including writers that do not know this
 * class exists. Registered in Tests\Support\StaticMemos so the suite clears it
 * between tests, which is what stops the answer depending on test order.
 *
 * ── THE FALLBACK CHAIN, STATED PLAINLY ──────────────────────────────────────
 *
 * For a UI key in Arabic:
 *
 *   1. a PUBLISHED Arabic row in this table            → use it
 *   2. a PUBLISHED English row in this table           → use it (the owner
 *                                                        corrected the English
 *                                                        without a release)
 *   3. the English default in InterfaceStrings         → use it
 *   4. nothing                                         → the key itself
 *
 * A DRAFT is never step 1. That is the point of the column.
 *
 * ── AND WHAT AN UNTRANSLATED STRING LOOKS LIKE ──────────────────────────────
 *
 * By default: English. A half-Arabic page is ugly, but a page with ⟪brackets⟫
 * around a third of its words is broken, and the shop is live while the owner
 * is translating. English is the honest degradation — it is what he would see
 * today anyway.
 *
 * The obvious-placeholder behaviour exists as well, because "which strings have
 * I missed" is a real question and reading a page is how a shopkeeper answers
 * it. It is a setting, `translation_highlight_missing`, off by default, and
 * when it is on an untranslated string renders as ⟪English⟫. It is a review
 * tool, not a production mode, and the admin screen says so beside the switch.
 */
final class TranslationStore
{
    /**
     * locale => [ "group.item_id.field" => value ], published rows only.
     *
     * One entry per locale rather than one per key: the whole table is a few
     * thousand small rows, the shop reads dozens per page, and a file cache
     * turns per-key entries into per-key file reads. Same reasoning as
     * SettingsService::snapshot(), and the same measurement behind it.
     *
     * @var array<string, array<string, string>>|null
     */
    private static ?array $memo = null;

    private const CACHE_PREFIX = 'kbb.translations.';

    /**
     * The fields that are NOT in the map, and the whole reason there is a
     * split.
     *
     * ── WHY THESE FIVE AND NOT A LENGTH CHECK ───────────────────────────────
     *
     * A length check would put a row in or out of the map depending on what the
     * owner happened to type, so the same page would cost different amounts on
     * different days and no test could pin either number. This is a named list
     * of COLUMNS, decided by what the column is for: five columns that hold an
     * article — a product's description, its INCI list, its how-to-use, a
     * policy page's body, a journal post's body.
     *
     * ── WHAT IT IS WORTH, MEASURED ──────────────────────────────────────────
     *
     * MySQL 8.0.46 at 696 products with the whole catalogue translated, one
     * process, warm file cache — docs/fp-storefront-reads-translations.md, and
     * Lane FN's docs/fn-translation-at-scale.md before it:
     *
     *     map('ar') with them:     4,512 entries, 3.20 MB of strings, +4.72 MB, 7.1-7.8 ms
     *     map('ar') without them:  2,491 entries, 0.18 MB of strings, +0.48 MB, 0.9-1.0 ms
     *
     * A tenth of the resident memory, and the map is loaded on EVERY Arabic request —
     * not because a product card wants a name but because __() asks the loader
     * for an interface string, on /ar/cart, which prints no prose at all.
     *
     * The thing that makes those 3.1 MB unnecessary is not that they are large.
     * It is WHEN they are read: a description is read on one product page at a
     * time. So they are read that way instead — longFor() below, one query,
     * measured at 73 rows / 3.0–3.4 ms / 0.11 MB for a page of 25 products.
     * At the page that is /ar/ costing 34.0 MB where it cost 42.5, which is
     * what an English / costs, with the query count unmoved.
     *
     * NOT `short_description` and NOT `excerpt`, deliberately, though both are
     * prose. Both are printed on GRIDS — a blurb under a card, a teaser under a
     * journal tile — so taking them out of the map would trade 0.02 MB for a
     * query per card, which is the N+1 the map exists to prevent.
     */
    public const LONG_FIELDS = ['description', 'ingredients', 'how_to_use', 'content', 'body'];

    /**
     * The group, and the fields in it, that a storefront template prints RAW.
     *
     * ── WHY A SANITISER LIVES IN A CACHE READER ────────────────────────────
     *
     * App\Support\RichText's header states the rule this enforces:
     * partials/product-tabs.blade.php prints a product description with
     * `{!! !!}` — twice, desktop panel and mobile accordion — so "the allowlist
     * runs on the way IN to the database, on the server, every time. Nothing is
     * trusted for having come from the editor's own toolbar."
     *
     * The English side obeys that in three places (ProductEditorApiController,
     * CatalogProductsApiController, ProductImporter). The Arabic side had it in
     * ONE: ProductEditorApiController hands TranslationInput::clean() its
     * RICH_FIELDS, which is the T4b fix — the master plan records that the
     * Arabic halves of two `{!! !!}` tabs going in unsanitised WAS the
     * stored-XSS hole, not a two-line oversight beside it.
     *
     * TranslationsApiController::store() — Content -> Translations, the
     * standalone screen, published immediately with no draft step — did not. It
     * passed the owner's typing to put() verbatim. That bypass was INERT for as
     * long as nothing on the storefront read a content translation, which is
     * precisely what the render in this same cycle changed: the value now
     * reaches `{!! $tab['body'] !!}`.
     *
     * So the rule is enforced HERE, at the one function all three writers go
     * through (HasTranslations::writeTranslation, MachineTranslationRunner and
     * TranslationsApiController), rather than added to the one caller that
     * happened to be missing it. A sanitiser that each writer has to remember
     * is a sanitiser one writer will not have; this is the choke point, and a
     * fourth writer added later inherits the rule instead of re-opening the
     * hole.
     *
     * ── WHY IT IS FOUR FIELDS IN ONE GROUP AND NOT EVERY VALUE ─────────────
     *
     * clean() parses its input as HTML. Over a NAME that is wrong in both
     * directions: a product genuinely called "Serum <3" or a category described
     * with a bare ampersand would come back re-encoded, and the shop would
     * change under the owner for a value he typed correctly. The list is
     * therefore exactly the set whose ENGLISH is cleaned by the product editor,
     * on the group those columns belong to — parity per field, nothing wider.
     *
     * Deliberately NOT `pages.content` or `posts.body`, though both are printed
     * raw as well. Their English is not sanitised either — those editors store
     * operator HTML as trusted by a decision older than this lane — so cleaning
     * only the Arabic would render one document differently in its two
     * languages. That asymmetry is real and is reported rather than decided
     * here.
     *
     * Held identical to ProductEditorApiController::RICH_FIELDS by a test
     * rather than an import, because that file belongs to another lane.
     */
    public const RICH_FIELDS = ['short_description', 'description', 'ingredients', 'how_to_use'];

    /** The group whose RICH_FIELDS are printed raw. */
    public const RICH_GROUP = 'products';

    /**
     * locale => group => item id => [ field => value ], published long prose.
     *
     * Filled by longFor() and cleared by flush(), exactly like $memo. There is
     * no CACHE entry behind this one: a second file-cache key holding the 3.2 MB
     * this split just removed would be read back in whole by the first page that
     * wanted one paragraph of it, which is the cost being removed. A targeted
     * query is a few milliseconds and a tenth of a megabyte, and is the second
     * half of what the measurement recommends.
     *
     * @var array<string, array<string, array<int, array<string, string>>>>
     */
    private static array $longMemo = [];

    /**
     * locale => the UI slice of the map, built once.
     *
     * uiMap() walks the whole map to answer, and the loader asks it once per
     * translation GROUP — five or so times a request. Before the split that was
     * five walks of 4,512 entries; it is now five walks of 2,491, and with this
     * memo it is one.
     *
     * @var array<string, array<string, string>>
     */
    private static array $uiMemo = [];

    /**
     * Clears ALL THREE layers. Called from Translation's saved/deleted hooks.
     *
     * The third is the one that was missed first time and is worth naming.
     * Illuminate\Translation\Translator keeps its OWN per-instance cache of
     * every group it has loaded (`$loaded[namespace][group][locale]`), and it
     * never consults the loader again for a group it has already seen. So
     * evicting the cache and the memo was not enough: within one process, a
     * page rendered before a translation was approved kept rendering the old
     * words afterwards. That is the Setting::map() trap in a layer the
     * framework owns, and it is exactly what the draft/approve cycle would
     * have fallen over: press Approve, reload, see the draft still hidden.
     *
     * Harmless under PHP-FPM, where a request is a process — and a real defect
     * in the test suite and in a queue worker, which are the two places this
     * shop has been bitten by a memo before.
     */
    public static function flush(): void
    {
        self::forgetMemo();

        foreach (Locale::codes() as $code) {
            Cache::forget(self::CACHE_PREFIX . $code);
        }

        try {
            $translator = app('translator');

            if ($translator instanceof \Illuminate\Translation\Translator) {
                $translator->setLoaded([]);
            }
        } catch (\Throwable) {
            // Called from a model hook, which can fire during a migration or a
            // console command where the translator is not resolvable. A cache
            // that could not be evicted must never take down the write.
        }
    }

    /**
     * The PROCESS-LEVEL layers only, leaving the cache where it is.
     *
     * This is what the end of a request does under PHP-FPM: the process goes
     * and takes every static with it, while the file cache — which is shared
     * and is the whole reason the memo is worth having — survives. flush() is
     * the other thing entirely: a WRITE happened and the cached answer is
     * wrong, so both layers have to go.
     *
     * It exists because a test process is not a request. A measurement that
     * warms these statics and then measures again is measuring a state no
     * visitor is ever in, and it hides exactly the defect the split introduces
     * the possibility of: a template reading a long field per row runs a query
     * per row on every real request and none at all on a second render inside
     * one process. Proven while writing the guard for it — an N+1 deliberately
     * added to the product card left the flatness test green until this was
     * called between passes. See StorefrontQueryBudgetTest's header, which
     * makes the same distinction about SettingsService and the cache store.
     */
    public static function forgetMemo(): void
    {
        self::$memo = null;
        self::$longMemo = [];
        self::$uiMemo = [];
    }

    /**
     * Keys are identifiers, so they are normalised before they are stored or
     * looked up.
     *
     * Under utf8mb4_unicode_ci — what config/database.php configures — 'Name'
     * and 'name' are the same value, so the unique index would reject the
     * second as a duplicate while an un-normalised reader looked for a key that
     * was never written. Forcing the case here means the index's opinion and
     * the reader's opinion are the same opinion, on MySQL and on SQLite alike.
     */
    public static function normaliseKey(string $key): string
    {
        return strtolower(trim($key));
    }

    /** The composite key one row is stored under, inside a locale's map. */
    public static function slot(string $group, int $itemId, string $field): string
    {
        return self::normaliseKey($group) . '.' . $itemId . '.' . self::normaliseKey($field);
    }

    /**
     * Every published SHORT translation for one locale.
     *
     * Names, labels, blurbs, interface strings: the text that is read on every
     * page, is bounded by the number of rows rather than by how much the owner
     * typed, and is worth holding in memory so that a grid of cards costs no
     * queries at all.
     *
     * NOT the five columns in LONG_FIELDS — see that constant for the
     * measurement. A caller that wants one of those asks longFor(), which is a
     * query for the page it is on rather than a megabyte on every page.
     *
     * @return array<string, string>
     */
    public static function map(string $locale): array
    {
        if (self::$memo !== null && array_key_exists($locale, self::$memo)) {
            return self::$memo[$locale];
        }

        $loaded = Cache::remember(self::CACHE_PREFIX . $locale, 300, static function () use ($locale): array {
            $out = [];

            /*
             * TRY THE READ, DO NOT ASK WHETHER THE TABLE EXISTS.
             *
             * The table may genuinely be missing: this class is reachable from
             * a view composer and from the exception handler, and both run
             * during the migration that creates it. A 500 there would take the
             * storefront down mid-update on a host whose only rollback is the
             * updater's own backup. So the miss has to be survivable — an empty
             * map degrades to the English defaults, which is the site as it is
             * today.
             *
             * But Schema::hasTable() is NOT the way to find out. On MySQL it is
             * an information_schema query, which is an order of magnitude
             * slower than the read it guards, and __() is called by Laravel's
             * own validator on more or less every request — so the guard cost
             * more than the thing it protected, every time, on the engine
             * production actually runs. It was measured: with hasTable() here
             * the MySQL suite blew the 110-second per-test limit; without it,
             * it does not.
             *
             * A catch costs nothing when the table is there, which is always,
             * except for the few seconds it is not.
             */
            try {
                $rows = Translation::query()
                    ->published()
                    ->where('locale', $locale)
                    // THE SPLIT. Filtered in SQL rather than in the loop below
                    // so the 3.1 MB never crosses the wire, is never hydrated
                    // into models, and is never serialised into the cache
                    // entry. Filtering after the fetch would have saved the
                    // resident megabytes and none of the milliseconds.
                    ->whereNotIn('field', self::LONG_FIELDS)
                    ->get(['group', 'item_id', 'field', 'value']);
            } catch (\Throwable) {
                return [];
            }

            foreach ($rows as $row) {
                if ($row->value === null || $row->value === '') {
                    // An empty translation is not a translation. Leaving it out
                    // lets the fallback chain run, which is what the owner
                    // means when he clears a box he opened by mistake.
                    continue;
                }

                $out[self::slot((string) $row->group, (int) $row->item_id, (string) $row->field)] = (string) $row->value;
            }

            return $out;
        });

        $map = is_array($loaded) ? $loaded : [];

        self::$memo ??= [];
        self::$memo[$locale] = $map;

        return $map;
    }

    /**
     * One translation, or null.
     *
     * Deliberately returns null rather than a default: the caller knows what
     * its own fallback is, and a default supplied here would hide the
     * difference between "translated to this" and "not translated".
     */
    public static function get(string $locale, string $group, int $itemId, string $field): ?string
    {
        return self::map($locale)[self::slot($group, $itemId, $field)] ?? null;
    }

    /**
     * Every published translation for one group and one set of ids.
     *
     * This is the batch read that keeps a 24-product grid off an N+1: the map
     * is already in memory, so a page of products costs array lookups and no
     * queries at all.
     *
     * @param  list<int>  $itemIds
     * @return array<int, array<string, string>> item id => field => value
     */
    public static function forGroup(string $locale, string $group, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $group = self::normaliseKey($group);
        $wanted = array_flip(array_map('intval', $itemIds));
        $out = [];

        foreach (self::map($locale) as $slot => $value) {
            $parts = explode('.', $slot, 3);

            if (count($parts) !== 3 || $parts[0] !== $group) {
                continue;
            }

            $id = (int) $parts[1];

            if (! isset($wanted[$id])) {
                continue;
            }

            $out[$id][$parts[2]] = $value;
        }

        return $out;
    }

    /**
     * Every published INTERFACE string for one locale, keyed by its whole
     * dotted key.
     *
     * A method of its own rather than forGroup($locale, 'ui', [0]) because the
     * loader asks for this on every page and the general form walks the entire
     * map to answer it. This one stops at the group prefix.
     *
     * @return array<string, string>
     */
    public static function uiMap(string $locale): array
    {
        if (array_key_exists($locale, self::$uiMemo)) {
            return self::$uiMemo[$locale];
        }

        $prefix = Translation::GROUP_UI . '.0.';
        $out = [];

        foreach (self::map($locale) as $slot => $value) {
            if (str_starts_with($slot, $prefix)) {
                $out[substr($slot, strlen($prefix))] = $value;
            }
        }

        return self::$uiMemo[$locale] = $out;
    }

    /**
     * The long-prose translations for one group and one page of rows, in ONE
     * query.
     *
     * This is the other half of the split, and the reason the map can be a
     * tenth of the size without anything becoming unreadable. LONG_FIELDS are
     * not in the map, so a product's Arabic description is fetched by the page
     * that prints it — measured at 73 rows, 3.0–3.4 ms and 0.11 MB for a page
     * of 25 products, against the 4.72 MB the map cost on every request
     * including ones with no prose on them at all.
     *
     * MEMOISED PER ROW, not per call, and that is what keeps a grid flat. A
     * template that asks for one row's description and then another's runs one
     * query and then none; a controller that primes the whole page runs one
     * query for all of it. Both end up in the same array. flush() clears it, so
     * a correction saved in this process is visible to the next read — the
     * Setting::map() trap this class's header is written against.
     *
     * NOT CACHED. A second file-cache entry holding the megabytes this split
     * just removed would be read back whole by the first page that wanted one
     * paragraph, which is the cost being removed.
     *
     * @param  list<int>  $itemIds
     * @return array<int, array<string, string>> item id => field => value
     */
    public static function longFor(string $locale, string $group, array $itemIds): array
    {
        $group = self::normaliseKey($group);
        $ids = array_values(array_unique(array_map('intval', $itemIds)));

        if ($ids === []) {
            return [];
        }

        $known = self::$longMemo[$locale][$group] ?? [];
        $missing = array_values(array_filter($ids, static fn (int $id): bool => ! array_key_exists($id, $known)));

        if ($missing !== []) {
            try {
                $rows = Translation::query()
                    ->published()
                    ->where('locale', $locale)
                    ->where('group', $group)
                    ->whereIn('item_id', $missing)
                    ->whereIn('field', self::LONG_FIELDS)
                    ->get(['item_id', 'field', 'value']);
            } catch (\Throwable) {
                // Same argument as map(): this is reachable from a view while
                // the migration that creates the table is running, and an
                // untranslated page is survivable where a 500 is not.
                $rows = collect();
            }

            // Every id asked for is recorded, INCLUDING the ones with no rows.
            // Otherwise a product with no Arabic description would be re-queried
            // every time a template mentioned it, which is the N+1 back again on
            // exactly the rows the owner has not reached yet.
            foreach ($missing as $id) {
                self::$longMemo[$locale][$group][$id] = [];
            }

            foreach ($rows as $row) {
                if ($row->value === null || $row->value === '') {
                    continue;
                }

                self::$longMemo[$locale][$group][(int) $row->item_id][self::normaliseKey((string) $row->field)] = (string) $row->value;
            }
        }

        $out = [];

        foreach ($ids as $id) {
            $out[$id] = self::$longMemo[$locale][$group][$id] ?? [];
        }

        return $out;
    }

    /**
     * Write one translation, creating or updating the row.
     *
     * The English source is hashed so staleness is answerable later; see
     * Translation::isStaleAgainst(). The model's saved hook does the eviction,
     * so nothing here has to remember to.
     */
    public static function put(
        string $locale,
        string $group,
        int $itemId,
        string $field,
        ?string $value,
        string $status = Translation::STATUS_DRAFT,
        string $source = Translation::SOURCE_MANUAL,
        ?string $englishSource = null,
    ): Translation {
        if ($value !== null && self::isRichField($group, $field)) {
            // See RICH_FIELDS. On the way in, on the server, every time.
            $value = \App\Support\RichText::clean($value);
        }

        $attributes = [
            'value' => $value,
            'status' => $status,
            'source' => $source,
        ];

        if ($englishSource !== null) {
            $attributes['source_hash'] = sha1($englishSource);
        }

        if ($status === Translation::STATUS_PUBLISHED) {
            $attributes['reviewed_at'] = now();
        }

        return Translation::query()->updateOrCreate([
            'locale' => $locale,
            'group' => self::normaliseKey($group),
            'item_id' => $itemId,
            'field' => self::normaliseKey($field),
        ], $attributes);
    }

    /**
     * Is this group/field pair one a storefront template prints unescaped?
     *
     * Normalised on both halves, because put() normalises before it writes and
     * a caller may hand either form — a check on the raw string would let
     * `Products` / `Description` past a rule that `products` / `description`
     * is subject to, which is a sanitiser bypass spelled in capital letters.
     */
    public static function isRichField(string $group, string $field): bool
    {
        return self::normaliseKey($group) === self::RICH_GROUP
            && in_array(self::normaliseKey($field), self::RICH_FIELDS, true);
    }
}
