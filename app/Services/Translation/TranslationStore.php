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
        self::$memo = null;

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
     * Every published translation for one locale.
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
        $prefix = Translation::GROUP_UI . '.0.';
        $out = [];

        foreach (self::map($locale) as $slot => $value) {
            if (str_starts_with($slot, $prefix)) {
                $out[substr($slot, strlen($prefix))] = $value;
            }
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
}
