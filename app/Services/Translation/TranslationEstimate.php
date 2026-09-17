<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\Translation;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What machine-translating this shop would cost, before anything is spent.
 *
 * ── WHY THIS EXISTS AT ALL ──────────────────────────────────────────────────
 *
 * "Translate everything" is a button whose price is invisible. Google bills per
 * character of source text — spaces and markup included — and a catalogue of
 * 671 products does not feel like six hundred thousand characters until the
 * invoice arrives. Nothing in this shop calls a paid API until this class has
 * shown the owner a count and a figure, and he has pressed a second button.
 *
 * ── HOW THE COUNT IS TAKEN ──────────────────────────────────────────────────
 *
 * mb_strlen, UTF-8, on the SOURCE text. Not strlen: an English source is
 * one byte per character, so the two agree today and would silently disagree
 * the moment anything is re-translated out of Arabic. Not a word count: nobody
 * bills by words.
 *
 * ALREADY-TRANSLATED ROWS ARE EXCLUDED. Re-sending text that already has a
 * translation is money spent to overwrite work, and on a shop being translated
 * in monthly batches — which is the plan, to stay inside the free allowance —
 * it would be most of the bill.
 *
 * ── THE PRICE, AND ITS HONESTY ──────────────────────────────────────────────
 *
 * USD 20 per million characters, first 500,000 per calendar month free. Those
 * are Google's published Basic (v2) numbers and they are CONSTANTS HERE, not
 * live prices — nothing in this application can see the owner's billing
 * account, the tier he is on, or whether he has already used the month's free
 * allowance on something else. The figure is an estimate and the screen says
 * the word "estimate"; the authority is his Google Cloud console.
 */
final class TranslationEstimate
{
    /** USD per million characters of source text. */
    public const USD_PER_MILLION = 20.0;

    /** Characters per calendar month at no charge, on Google's free tier. */
    public const FREE_TIER_CHARACTERS = 500_000;

    /** What the interface-strings group is called on a screen. One spelling. */
    public const UI_LABEL = 'Interface text';

    /**
     * Every model whose content is translatable, and the columns that are.
     *
     * Declared here as well as on the models because the estimate has to be
     * able to count a table WITHOUT loading 671 Eloquent objects into memory on
     * a shared host. Kept honest by a test that compares this list against each
     * model's own $translatable.
     *
     * @var array<class-string, list<string>>
     */
    public const CONTENT = [
        // ingredients and how_to_use are prose and are each their own tab on
        // the product page. See Product::$translatable.
        \App\Models\Product::class => ['name', 'short_description', 'description', 'ingredients', 'how_to_use'],
        \App\Models\Category::class => ['name', 'description'],
        \App\Models\Brand::class => ['name', 'description'],
        \App\Models\Page::class => ['title', 'content'],
        // `body`, not `content`: that is what the posts table calls it, and a
        // column name that does not exist would silently count as zero work.
        \App\Models\Post::class => ['title', 'excerpt', 'body'],
        \App\Models\MenuItem::class => ['label'],
    ];

    /**
     * The whole job, broken down.
     *
     * @return array{
     *     locale: string,
     *     groups: array<string, array{label: string, characters: int, fields: int, translated: int}>,
     *     characters: int,
     *     fields: int,
     *     free_tier: int,
     *     billable_characters: int,
     *     usd: float,
     *     months_at_free_tier: int
     * }
     */
    public static function forLocale(string $locale): array
    {
        $done = self::translatedSlots($locale);

        $groups = [];
        $total = 0;
        $fields = 0;

        // Interface strings.
        [$uiChars, $uiFields, $uiDone] = self::countInterface($done);
        // The label travels with the figure. A screen that mapped 'menu_items'
        // to "Menu labels" itself would be a second copy of progress()'s
        // vocabulary, drifting the first time a table is renamed.
        $groups[Translation::GROUP_UI] = [
            'label' => self::UI_LABEL, 'characters' => $uiChars, 'fields' => $uiFields, 'translated' => $uiDone,
        ];
        $total += $uiChars;
        $fields += $uiFields;

        // Catalogue content, one aggregate query per table.
        foreach (self::CONTENT as $class => $columns) {
            /** @var \Illuminate\Database\Eloquent\Model $model */
            $model = new $class;
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                continue;
            }

            [$chars, $count, $already] = self::countTable($table, $columns, $done);

            $groups[$table] = [
                'label' => self::label($table), 'characters' => $chars, 'fields' => $count, 'translated' => $already,
            ];
            $total += $chars;
            $fields += $count;
        }

        $billable = max(0, $total - self::FREE_TIER_CHARACTERS);

        return [
            'locale' => $locale,
            'groups' => $groups,
            'characters' => $total,
            'fields' => $fields,
            'free_tier' => self::FREE_TIER_CHARACTERS,
            'billable_characters' => $billable,
            'usd' => round($billable / 1_000_000 * self::USD_PER_MILLION, 2),
            // How many calendar months this takes if he never wants to pay a
            // cent. The plan the owner was quoted.
            'months_at_free_tier' => (int) max(1, (int) ceil($total / self::FREE_TIER_CHARACTERS)),
        ];
    }

    /**
     * Slots that already carry a translation in this locale.
     *
     * @return array<string, true>
     */
    private static function translatedSlots(string $locale): array
    {
        if (! Schema::hasTable('translations')) {
            return [];
        }

        $out = [];

        foreach (Translation::query()->where('locale', $locale)
            ->whereNotNull('value')->where('value', '!=', '')
            ->get(['group', 'item_id', 'field']) as $row) {
            $out[TranslationStore::slot((string) $row->group, (int) $row->item_id, (string) $row->field)] = true;
        }

        return $out;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function countInterface(array $done): array
    {
        $chars = 0;
        $fields = 0;
        $already = 0;

        foreach (InterfaceStrings::flat() as $key => $english) {
            $fields++;

            if (isset($done[TranslationStore::slot(Translation::GROUP_UI, 0, $key)])) {
                $already++;

                continue;
            }

            $chars += mb_strlen($english, 'UTF-8');
        }

        return [$chars, $fields, $already];
    }

    /**
     * Characters outstanding in one table.
     *
     * Reads only the id and the translatable columns, in a chunked cursor, so
     * the peak memory is a page of rows rather than the catalogue. A shared
     * host's PHP memory limit is the reason this is not
     * `Product::all()->sum(...)`.
     *
     * @param  list<string>  $columns
     * @return array{0: int, 1: int, 2: int}
     */
    private static function countTable(string $table, array $columns, array $done): array
    {
        $present = array_values(array_filter(
            $columns,
            static fn (string $c): bool => Schema::hasColumn($table, $c)
        ));

        if ($present === []) {
            return [0, 0, 0];
        }

        $chars = 0;
        $fields = 0;
        $already = 0;

        $scan = DB::table($table)->select(array_merge(['id'], $present))->orderBy('id');

        if (Schema::hasColumn($table, 'deleted_at')) {
            $scan->whereNull('deleted_at');
        }

        $scan->chunk(500, function ($rows) use (&$chars, &$fields, &$already, $present, $table, $done): void {
                foreach ($rows as $row) {
                    foreach ($present as $column) {
                        $value = $row->{$column} ?? null;

                        if (! is_string($value) || trim($value) === '') {
                            continue;
                        }

                        $fields++;

                        if (isset($done[TranslationStore::slot($table, (int) $row->id, $column)])) {
                            $already++;

                            continue;
                        }

                        $chars += mb_strlen($value, 'UTF-8');
                    }
                }
            });

        return [$chars, $fields, $already];
    }

    /**
     * How complete is the translation, per area, for the owner's screen.
     *
     * ── WHY THIS IS ANSWERABLE AT ALL ───────────────────────────────────────
     *
     * Because blank means "not translated" rather than "same as English", so a
     * field either has a row or it does not and counting rows is counting work.
     * If an empty box left an empty row behind, every figure on this screen
     * would be a guess.
     *
     * ── AND WHY IT IS CHEAP ─────────────────────────────────────────────────
     *
     * ONE aggregate query per content table for the denominator — how many
     * fields have English text worth translating — plus ONE grouped query over
     * the whole translations table for the numerator. Six tables is seven
     * queries for the entire shop, whatever the catalogue grows to. The
     * name_ar-columns design would need one COALESCE per column per table here,
     * rewritten every time a field was added.
     *
     * COUNT with a CASE, not SUM(CHAR_LENGTH(...)): the length functions are
     * spelled differently on MySQL and SQLite and this screen is polled. The
     * character count — which does need the lengths — is taken in PHP by
     * forLocale() above, which the owner runs deliberately and rarely.
     * SqlDialectGuardTest exists because this shop has shipped SQL that only
     * ran on the engine the suite used.
     *
     * @return array{
     *     locale: string,
     *     areas: array<string, array{label: string, total: int, translated: int, drafts: int, percent: int}>,
     *     total: int, translated: int, drafts: int, percent: int
     * }
     */
    public static function progress(string $locale): array
    {
        $translated = self::translatedCountsByGroup($locale, Translation::STATUS_PUBLISHED);
        $drafts = self::translatedCountsByGroup($locale, Translation::STATUS_DRAFT);

        $areas = [];

        // Interface strings: the denominator is code, so it is free.
        $uiTotal = count(InterfaceStrings::flat());
        $areas[Translation::GROUP_UI] = self::area(
            self::UI_LABEL,
            $uiTotal,
            $translated[Translation::GROUP_UI] ?? 0,
            $drafts[Translation::GROUP_UI] ?? 0,
        );

        foreach (self::CONTENT as $class => $columns) {
            /** @var \Illuminate\Database\Eloquent\Model $model */
            $model = new $class;
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                continue;
            }

            $areas[$table] = self::area(
                self::label($table),
                self::fieldsWithText($table, $columns),
                $translated[$table] ?? 0,
                $drafts[$table] ?? 0,
            );
        }

        $total = array_sum(array_column($areas, 'total'));
        $done = array_sum(array_column($areas, 'translated'));
        $draft = array_sum(array_column($areas, 'drafts'));

        return [
            'locale' => $locale,
            'areas' => $areas,
            'total' => $total,
            'translated' => $done,
            'drafts' => $draft,
            // The same rule as each area's own figure, for the same reason:
            // a whole shop one string short of done must not read 100.
            'percent' => $total > 0 ? self::percent($done, $total) : 0,
        ];
    }

    /** @return array{label: string, total: int, translated: int, drafts: int, percent: int} */
    private static function area(string $label, int $total, int $translated, int $drafts): array
    {
        // A translation can outlive the English row it described — a deleted
        // product leaves its rows behind until they are pruned — so the
        // numerator is capped rather than allowed to read 103%.
        $done = min($translated, $total);

        return [
            'label' => $label,
            'total' => $total,
            'translated' => $done,
            'drafts' => $drafts,
            'percent' => self::percent($done, $total),
        ];
    }

    /**
     * How far along, as a whole number, with 0 and 100 reserved for the ends.
     *
     * ── WHAT WAS WRONG WITH round() ON ITS OWN ───────────────────────────
     *
     * `(int) round(999 / 1000 * 100)` is 100. The interface-string area of this
     * shop is 752 keys; a locale ONE STRING short of complete reported 100%,
     * and the console draws a pill green at exactly 100 — so the screen whose
     * entire job is answering "is the Arabic finished?" answered yes while a
     * shopper was still being shown an English word.
     *
     * The same round() lies at the other end: 1 translated row out of 1,000 is
     * 0.1%, which rounds to 0, and 0 on this screen means "not started". Both
     * ends are milestones, and a milestone reached by rounding has not been
     * reached. So 100 means every field, 0 means none, and everything in
     * between is squeezed into 1..99 — round() is untouched inside that range,
     * so no figure anybody has already read moves.
     *
     * This is the fix the free-delivery bar already carries, one screen over:
     * see the `$toFree === 0 ? 100 : min(99, ...)` in
     * App\Services\CartService::totals(). A bar is a claim about whether
     * something is done, and this is the same claim about the same kind of bar.
     *
     * An empty area is 100 and not 0: there is nothing left to translate in it,
     * which is the honest reading of "nothing to do". A shop with no journal
     * posts must not be told its journal is 0% translated forever.
     */
    private static function percent(int $done, int $total): int
    {
        if ($total <= 0) {
            return 100;
        }

        if ($done >= $total) {
            return 100;
        }

        if ($done <= 0) {
            return 0;
        }

        return max(1, min(99, (int) round($done / $total * 100)));
    }

    /**
     * How many rows carry a translation, per group, in one query.
     *
     * @return array<string, int>
     */
    private static function translatedCountsByGroup(string $locale, string $status): array
    {
        if (! Schema::hasTable('translations')) {
            return [];
        }

        /*
         * select('group') and not selectRaw('"group" ...').
         *
         * `group` is a reserved word, and the two engines quote identifiers
         * differently: MySQL wants backticks and reads "group" as the STRING
         * 'group' unless ANSI_QUOTES is set, while SQLite accepts the double
         * quotes. A raw select would therefore have grouped every row under the
         * literal word "group" on the live server while passing in the SQLite
         * suite — which is the exact failure SqlDialectGuardTest exists for.
         * The query builder's grammar knows how to wrap it for the connection
         * it is on.
         */
        $rows = Translation::query()
            ->where('locale', $locale)
            ->where('status', $status)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->select('group')
            ->selectRaw('COUNT(*) as c')
            ->groupBy('group')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->group] = (int) $row->c;
        }

        return $out;
    }

    /**
     * How many translatable fields on this table actually hold English text.
     *
     * The denominator has to exclude empty columns or the figure is a lie: a
     * shop where two thirds of products have no short description would show as
     * two thirds untranslated forever, and the owner would chase work that does
     * not exist.
     *
     * @param  list<string>  $columns
     */
    private static function fieldsWithText(string $table, array $columns): int
    {
        $present = array_values(array_filter(
            $columns,
            static fn (string $c): bool => Schema::hasColumn($table, $c)
        ));

        if ($present === []) {
            return 0;
        }

        /*
         * One query for the whole table, with a CASE per column.
         *
         * CASE WHEN is ANSI and behaves identically on MySQL and SQLite, which
         * is why this is not SUM(CHAR_LENGTH(...)) — the length functions are
         * spelled differently and this screen is polled.
         *
         * The column names are interpolated, and that is safe HERE and only
         * here: they come from self::CONTENT, a class constant, and are
         * filtered through Schema::hasColumn() above, so nothing reaching this
         * string came from a request. The assertion is re-made rather than
         * assumed — a column name that is not a bare identifier is dropped.
         */
        $parts = [];

        foreach ($present as $column) {
            if (! preg_match('/^[a-z_][a-z0-9_]*$/', $column)) {
                continue;
            }

            $parts[] = "SUM(CASE WHEN {$column} IS NOT NULL AND {$column} <> '' THEN 1 ELSE 0 END)";
        }

        if ($parts === []) {
            return 0;
        }

        $query = DB::table($table)->selectRaw(implode(' + ', $parts).' as filled');

        // DB::table() knows nothing about SoftDeletes, and `products` uses it.
        // A trashed product is not work the owner has left to do.
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return (int) ($query->first()->filled ?? 0);
    }

    private static function label(string $table): string
    {
        return [
            'products' => 'Products',
            'categories' => 'Categories',
            'brands' => 'Brands',
            'pages' => 'Pages',
            'posts' => 'Journal posts',
            'menu_items' => 'Menu labels',
        ][$table] ?? ucfirst(str_replace('_', ' ', $table));
    }

    /** Every locale that is not the default — what there is left to translate into. */
    public static function targets(): array
    {
        return array_values(array_filter(
            Locale::codes(),
            static fn (string $c): bool => $c !== Locale::DEFAULT
        ));
    }
}
