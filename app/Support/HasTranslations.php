<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Translation;
use App\Services\Translation\TranslationStore;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

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
 * That is affordable because of what this table actually is. The shop has 671
 * products and about eight translatable fields between the catalogue models;
 * fully translated into one language that is a few thousand short rows —
 * configuration-sized, not data-sized, and the same argument SettingsService
 * makes for reading the whole settings table in one go. It is measured rather
 * than assumed: BilingualFoundationTest drives a 24-product grid and asserts
 * the query count does not move.
 *
 * WHEN THAT STOPS BEING TRUE — a third and fourth language, or descriptions
 * long enough that the map costs real memory — the replacement is
 * scopeWithTranslations() below, which is a real eager load and adds exactly
 * one query per page however many rows are on it. It is written, tested and
 * unused, so the swap is a one-line change rather than a redesign.
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

        $translated = $this->loadedTranslations($locale)[TranslationStore::normaliseKey($field)] ?? null;

        return ($translated === null || $translated === '') ? $value : $translated;
    }

    /** Is this field translated in this language right now? */
    public function hasTranslation(string $field, ?string $locale = null): bool
    {
        $locale = $locale ?? Locale::current();

        if ($locale === Locale::DEFAULT) {
            return true;
        }

        $value = $this->loadedTranslations($locale)[TranslationStore::normaliseKey($field)] ?? null;

        return $value !== null && $value !== '';
    }

    /**
     * field => value for this row in one locale.
     *
     * Prefers a relation that has actually been eager-loaded, so
     * withTranslations() genuinely avoids the map; otherwise the cached map,
     * which costs nothing.
     *
     * @return array<string, string>
     */
    private function loadedTranslations(string $locale): array
    {
        if ($this->relationLoaded('translations')) {
            $out = [];

            foreach ($this->getRelation('translations') as $row) {
                if ($row->locale === $locale && $row->status === Translation::STATUS_PUBLISHED) {
                    $out[TranslationStore::normaliseKey((string) $row->field)] = (string) $row->value;
                }
            }

            return $out;
        }

        return TranslationStore::forGroup($locale, $this->translationGroup(), [(int) $this->getKey()])[(int) $this->getKey()] ?? [];
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
     * Warm the cached map for a page of rows.
     *
     * A no-op in the present design, kept as the single named place a caller
     * says "I am about to read translations for these rows" — so if the read
     * path ever changes, the call sites do not have to.
     *
     * @param  EloquentCollection<int, static>  $models
     */
    public static function primeTranslations(EloquentCollection $models, ?string $locale = null): void
    {
        if ($models->isEmpty()) {
            return;
        }

        TranslationStore::map($locale ?? Locale::current());
    }
}
