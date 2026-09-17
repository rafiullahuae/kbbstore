<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Translation;
use App\Services\Translation\TranslationStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Translated CONTENT — a product's name, a category's description.
 *
 * Interface strings are keyed and finite and go through __(). This is the other
 * half: per-row, unbounded, and 671 products long.
 *
 * ── THE READ, AND THE N+1 THAT ISN'T ────────────────────────────────────────
 *
 * A translations table is the textbook way to turn a 24-product grid into 25
 * queries. It does not happen here, and not because of an eager-load anybody
 * has to remember: TranslationStore holds one locale's whole published set in
 * ONE cached map, so a row's translation is an array lookup and a page of
 * products costs NO QUERIES AT ALL beyond the page of products itself.
 *
 * That is affordable because of what the SHORT half of this table is. The shop
 * has 671 products and about eight translatable fields between the catalogue
 * models; the names, labels and blurbs, fully translated into one language, are
 * a few thousand short rows — configuration-sized, not data-sized, and the same
 * argument SettingsService makes for reading the whole settings table in one go.
 * It is measured rather than assumed: BilingualFoundationTest drives a
 * 24-product grid and ContentTranslationAtScaleTest drives 400, and both assert
 * the query count does not move.
 *
 * ── AND THE HALF THAT IS NOT IN THE MAP ─────────────────────────────────────
 *
 * This header used to name "a third and fourth language" as the thing that
 * would end the one-map design. THAT WAS WRONG, and it was wrong in a way that
 * would have kept the real problem hidden: each locale has its own cache entry
 * and a request loads only the locale it is being served in, so a fourth
 * language costs a fourth cache key and NOTHING per request.
 *
 * The trigger was always field LENGTH, and it had been crossed long before
 * anybody looked. Measured at 696 products with the catalogue fully translated,
 * one process, warm file cache — docs/fp-storefront-reads-translations.md, and
 * docs/fn-translation-at-scale.md before it:
 *
 *     before   4,512 entries, 3.20 MB of strings, +4.72 MB resident, 7.1-7.8 ms
 *     after    2,491 entries, 0.18 MB of strings, +0.48 MB resident, 0.9-1.0 ms
 *
 * 97% of those bytes were product long prose — read on at most one page at a
 * time, and paid for on /ar/cart, which prints none of it.
 *
 * So the map is split rather than replaced. TranslationStore::LONG_FIELDS —
 * description, ingredients, how_to_use, content, body — are not in it. They are
 * read by the page that prints them instead, through TranslationStore::longFor():
 * one query, 73 rows and 0.11 MB for a page of 25 products. At the page that is
 * /ar/ at 34.0 MB where it was 42.5, which is what an English / costs.
 * scopeWithTranslations() below is the other way to the same place and is what
 * the admin editor uses.
 *
 * ── WHAT IS NEVER TRANSLATED ────────────────────────────────────────────────
 *
 * translatable() is an allowlist per model, and the things missing from it are
 * missing on purpose:
 *
 *   SKU, coupon code, order number, currency code — identifiers. A translated
 *   SKU is a SKU nobody can search the supplier's catalogue for, and a
 *   translated coupon code is a coupon that does not apply.
 *
 *   SLUG. This is the one worth arguing. Translating slugs gives Arabic
 *   shoppers a readable URL, and costs: a second slug column on six tables, a
 *   second uniqueness space, a second set of rows in the redirects table, a
 *   doubled sitemap, and — the real cost — a product whose address changes when
 *   somebody edits its Arabic name. ONE slug per row, with the language carried
 *   by the /ar prefix, means /ar/product/anua-heartleaf-toner/ and
 *   /product/anua-heartleaf-toner/ are the same product at two addresses that
 *   differ by four characters, which is exactly what hreflang is for. Arabic
 *   URLs are percent-encoded into unreadability when they are copied anyway.
 *
 *   PRICE. Not because it cannot be, but because it must not be reversed: AED
 *   199 stays AED 199 on an RTL page.
 */
trait HasTranslations
{
    /**
     * Which columns of this model may be translated.
     *
     * Declared per model as a `protected array $translatable` property. A
     * model that declares none is simply never translated, which is the right
     * default for the thirty-odd models in this app that hold no prose.
     *
     * @return list<string>
     */
    public function translatable(): array
    {
        /** @var list<string> */
        return property_exists($this, 'translatable') ? $this->translatable : [];
    }

    /** The translations table's `group` for this model: its table name. */
    public function translationGroup(): string
    {
        return $this->getTable();
    }

    /**
     * This row's text for one field, in the current language.
     *
     * Falls back to the stored English, which is the column itself. A product
     * with no Arabic name shows its English name — a shopper can still find it,
     * buy it and recognise it on the box, which is the whole argument for
     * English-as-fallback rather than a placeholder.
     *
     * A field not on the allowlist returns the column untouched rather than
     * throwing. This is called from Blade, and a typo in a template must not be
     * a 500 on a product page.
     */
    public function t(string $field, ?string $locale = null): mixed
    {
        $value = $this->getAttribute($field);

        if (! in_array($field, $this->translatable(), true)) {
            return $value;
        }

        $locale = $locale ?? Locale::current();

        if ($locale === Locale::DEFAULT) {
            return $value;
        }

        $translated = $this->translationFor($field, $locale);

        return ($translated === null || $translated === '') ? $value : $translated;
    }

    /** Is this field translated in this language right now? */
    public function hasTranslation(string $field, ?string $locale = null): bool
    {
        $locale = $locale ?? Locale::current();

        if ($locale === Locale::DEFAULT) {
            return true;
        }

        $value = $this->translationFor($field, $locale);

        return $value !== null && $value !== '';
    }

    /**
     * One field's stored translation for this row, or null.
     *
     * THREE SOURCES, AND WHICH ONE IS ASKED IS DECIDED BY THE FIELD.
     *
     *   1. An eager-loaded `translations` relation, if there is one. Then
     *      withTranslations() genuinely avoids both of the others, which is
     *      what the admin editor and a primed page want.
     *   2. For a LONG_FIELDS column: TranslationStore::longFor(), one query per
     *      page and memoised per row. These are deliberately not in the map —
     *      see the header.
     *   3. Otherwise the cached map, which is already in memory because __()
     *      loaded it, and costs no query at all.
     *
     * Asking per FIELD rather than building the whole row's array is the point.
     * The old shape returned every field at once, so the moment the long columns
     * moved out of the map, printing a product's NAME on a grid of cards would
     * have fetched its description too — a query per card, on the exact page the
     * map exists to keep flat.
     */
    private function translationFor(string $field, string $locale): ?string
    {
        $key = TranslationStore::normaliseKey($field);

        if ($this->relationLoaded('translations')) {
            foreach ($this->getRelation('translations') as $row) {
                if ($row->locale === $locale
                    && $row->status === Translation::STATUS_PUBLISHED
                    && TranslationStore::normaliseKey((string) $row->field) === $key) {
                    return (string) $row->value;
                }
            }

            return null;
        }

        $id = (int) $this->getKey();

        if (in_array($key, TranslationStore::LONG_FIELDS, true)) {
            return TranslationStore::longFor($locale, $this->translationGroup(), [$id])[$id][$key] ?? null;
        }

        /*
         * get(), not forGroup(), and it is not a tidy-up.
         *
         * forGroup() walks the WHOLE map to answer, because it is the batch
         * form and does not know which slot it is looking for. Called from here
         * it was doing that once per FIELD per ROW: a grid of 24 cards reading
         * two fields each was 48 full scans of a 2,491-entry array to perform
         * 48 lookups it already had the exact key for. get() is one hash
         * lookup against the same map, which is what the map was built to be —
         * and the saving grows with the catalogue, which is the wrong direction
         * for the thing it was costing.
         */
        return TranslationStore::get($locale, $this->translationGroup(), $id, $key);
    }

    /**
     * The rows in the translations table that belong to this model.
     *
     * Not a real Eloquent relationship declaration, because the join is on
     * (group, item_id) and `group` is a constant per model rather than a
     * column on this table — a morphMany would need a `translatable_type`
     * column holding a class name, which is the thing the migration header
     * explains is worth avoiding.
     */
    public function translations()
    {
        return $this->hasMany(Translation::class, 'item_id', $this->getKeyName())
            ->where('group', $this->translationGroup());
    }

    /**
     * Eager-load a page of rows' translations in one query.
     *
     * Unused on the read path today — the cached map is cheaper — and kept
     * tested so it is available the moment the map stops being the right
     * answer. It is also what the admin editing screen uses, where "show me
     * every field of these 50 products, translated and untranslated" wants the
     * rows themselves and not the published-only map.
     */
    public function scopeWithTranslations($query, ?string $locale = null)
    {
        $locale = $locale ?? Locale::current();
        $group = $this->translationGroup();

        return $query->with(['translations' => static function ($q) use ($locale, $group) {
            $q->where('group', $group)->where('locale', $locale);
        }]);
    }

    /**
     * Store this row's translations, from an ordinary editor save.
     *
     * ── THE POINT OF THIS METHOD ────────────────────────────────────────────
     *
     * The owner's requirement is that every admin editor has an Arabic box
     * beside the English one, filled in at the moment a product is CREATED
     * rather than on a separate screen visited afterwards. A shop where the
     * Arabic is somewhere else is a shop that is permanently one product behind.
     *
     * So the write path is one call from whatever controller already saves the
     * English row, in the same request and — because these are ordinary Eloquent
     * writes — the same transaction if the caller has opened one:
     *
     *     $product->fill($data)->save();
     *     $product->saveTranslations($request->input('translations', []));
     *
     * Creation works because this is called AFTER save(), when the row has an
     * id. There is no separate create path to get wrong.
     *
     * ── WHAT THE EDITOR SENDS ───────────────────────────────────────────────
     *
     *     translations[ar][name]              string|null
     *     translations[ar][short_description] string|null
     *
     * Locale outside Locale::LOCALES: ignored. Field outside the model's own
     * $translatable allowlist: ignored. Both silently, and deliberately — this
     * is fed by a form, an unknown key is a stale browser tab or a renamed
     * column, and a 422 in the middle of saving a product the owner has spent
     * ten minutes on is a worse outcome than a dropped field. What is NOT
     * silent is the allowlist itself: a field the shop does not intend to
     * translate cannot be written through here at all, which is what keeps SKU,
     * slug, price and coupon code out.
     *
     * ── BLANK MEANS "NOT TRANSLATED YET". IT NEVER MEANS "SAME AS ENGLISH" ──
     *
     * A blank box DELETES the row. That is the whole reason the progress figure
     * can be trusted: "how much is left" is "how many fields have no row", and
     * that answer is only correct if an empty box leaves no row behind.
     *
     * If the owner genuinely wants the Arabic to read the same as the English —
     * a brand name like "Anua", a size like "50ml" — he types it, and it is
     * stored as an ordinary translation. Typed-identical and never-typed are
     * different states and the shop can tell them apart. An empty string stored
     * as a row would make them the same state and the progress screen a guess.
     *
     * ── MANUAL ENTRY IS PUBLISHED, NOT DRAFTED ──────────────────────────────
     *
     * The draft/approve cycle exists so a MACHINE cannot put words in the
     * shop's mouth. The owner typing into his own product editor has already
     * approved it; making him approve his own typing a second time would be
     * ceremony, and ceremony is what gets skipped.
     *
     * @param  array<string, array<string, string|null>>  $byLocale
     * @return int how many fields were written or cleared
     */
    public function saveTranslations(
        array $byLocale,
        string $status = Translation::STATUS_PUBLISHED,
        string $source = Translation::SOURCE_MANUAL,
    ): int {
        $allowed = $this->translatable();

        if ($allowed === [] || $this->getKey() === null) {
            return 0;
        }

        $touched = 0;

        foreach ($byLocale as $locale => $fields) {
            if (! is_string($locale) || ! Locale::isSupported($locale) || ! is_array($fields)) {
                continue;
            }

            foreach ($fields as $field => $value) {
                if (! is_string($field) || ! in_array($field, $allowed, true)) {
                    continue;
                }

                if ($value !== null && ! is_string($value)) {
                    continue;
                }

                $touched += $this->writeTranslation($locale, $field, $value, $status, $source);
            }
        }

        return $touched;
    }

    /** @return int 1 if something changed, 0 if not */
    private function writeTranslation(
        string $locale,
        string $field,
        ?string $value,
        string $status,
        string $source,
    ): int {
        $value = $value === null ? '' : trim($value);

        if ($value === '') {
            // Blank means untranslated. See the header: the row goes.
            $deleted = Translation::query()
                ->where('locale', $locale)
                ->where('group', $this->translationGroup())
                ->where('item_id', (int) $this->getKey())
                ->where('field', \App\Services\Translation\TranslationStore::normaliseKey($field))
                ->get();

            foreach ($deleted as $row) {
                // One at a time so the model's deleted hook — which is what
                // evicts the cache — actually fires. A mass delete() on the
                // query builder does not fire model events, which is how a
                // corrected translation would have kept serving the old one
                // for five minutes.
                $row->delete();
            }

            return $deleted->isEmpty() ? 0 : 1;
        }

        \App\Services\Translation\TranslationStore::put(
            $locale,
            $this->translationGroup(),
            (int) $this->getKey(),
            $field,
            $value,
            $status,
            $source,
            englishSource: is_string($this->getAttribute($field)) ? (string) $this->getAttribute($field) : null,
        );

        return 1;
    }

    /**
     * Everything the admin editor needs to render its Arabic boxes.
     *
     * DRAFTS INCLUDED, and that is the point: a machine translation waiting for
     * approval has to appear in the box the owner is looking at, or "approve"
     * means "approve something you cannot see". `status` travels with each
     * value so the editor can mark a draft — the seam the per-field Translate
     * button needs.
     *
     * @return array<string, array<string, array{value: string, status: string, source: string, stale: bool}>>
     */
    public function translationsForEditor(): array
    {
        $out = [];

        foreach (Locale::codes() as $locale) {
            if ($locale === Locale::DEFAULT) {
                continue;
            }

            $out[$locale] = [];

            foreach ($this->translatable() as $field) {
                $out[$locale][$field] = ['value' => '', 'status' => '', 'source' => '', 'stale' => false];
            }
        }

        if ($this->getKey() === null || $this->translatable() === []) {
            return $out;
        }

        $rows = Translation::query()
            ->where('group', $this->translationGroup())
            ->where('item_id', (int) $this->getKey())
            ->get();

        foreach ($rows as $row) {
            $locale = (string) $row->locale;
            $field = (string) $row->field;

            if (! isset($out[$locale][$field])) {
                continue;
            }

            $english = $this->getAttribute($field);

            $out[$locale][$field] = [
                'value' => (string) $row->value,
                'status' => (string) $row->status,
                'source' => (string) $row->source,
                'stale' => $row->isStaleAgainst(is_string($english) ? $english : null),
            ];
        }

        return $out;
    }

    /**
     * "I am about to read translations for these rows."
     *
     * It was a no-op while everything lived in one map. It is not one any more:
     * the long columns are fetched per page now, so this is the single call that
     * turns a page of rows into ONE query instead of one per row that prints a
     * description. Short fields still come from the map and still cost nothing,
     * so calling this for a grid of cards is free either way.
     *
     * Safe to call on the default locale and safe to call twice — longFor()
     * records every id it was asked about, including the ones with no rows.
     *
     * A PLAIN Collection, not an EloquentCollection, and the difference is a
     * page rather than a nicety. App\Services\DemoContent::fill() concatenates
     * anonymous fixture objects onto a collection of real models, so the thing
     * a controller is holding when it calls this is frequently NOT all models —
     * and a fixture has no key to prime. They are filtered out here rather than
     * guarded against at every call site.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $models
     */
    public static function primeTranslations(Collection $models, ?string $locale = null): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $locale = $locale ?? Locale::current();

        TranslationStore::map($locale);

        if ($locale === Locale::DEFAULT) {
            // English is the column. Nothing to fetch, and fetching it would
            // put a query on every English page, which the budgets forbid.
            return;
        }

        $real = $models->filter(static fn ($m): bool => $m instanceof Model && $m->getKey() !== null);

        if ($real->isEmpty()) {
            return;
        }

        $first = $real->first();

        if (array_intersect($first->translatable(), TranslationStore::LONG_FIELDS) === []) {
            // Nothing this model can hold is outside the map, so the map read
            // above is the whole of the priming.
            return;
        }

        TranslationStore::longFor(
            $locale,
            $first->translationGroup(),
            $real->map(static fn ($m): int => (int) $m->getKey())->values()->all(),
        );
    }
}
