<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Translation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The Arabic box beside the English one — the server half. (Lane EX, T4b)
 *
 * The owner's words: "for addition of everything, like products, posts etc. we
 * should must have arabic place for every field, so we can enter manually."
 *
 * HasTranslations::saveTranslations() already knows how to store what an editor
 * sends. What it does NOT know is anything about the editor: which shape rules
 * the English box was validated by, which of the fields are rich text and have
 * to be sanitised before a storefront prints them with {!! !!}, or how to
 * prefill fifty rows' boxes without fifty queries. Those three answers were
 * about to be copied into four controllers. They live here instead.
 *
 * ── 1. THE ARABIC BOX MUST NOT ACCEPT WHAT THE ENGLISH BOX REFUSES ─────────
 *
 * rules() DERIVES the Arabic rules from the English ones the controller has
 * already written, rather than restating them. A rule restated is a rule that
 * drifts: raise `name` from 200 to 300 characters on the English side and the
 * Arabic side would silently keep refusing at 200, or — far worse in the other
 * direction — lower the English bound and leave the Arabic box accepting a
 * value the column cannot hold.
 *
 * What carries: the SHAPE rules. `string`, `max:N`.
 * What does not: `required`, `required_if`, `sometimes` — an Arabic field is
 * optional BY DEFINITION, because blank is the honest way to say "not
 * translated yet". Also dropped: closures, Rule objects and `unique`, all of
 * which are about the English row's identity (a slug's uniqueness, an existing
 * brand id) and mean nothing applied to a translation of a name.
 *
 * `min` is dropped too, and that is not an oversight: `nullable` exempts null
 * but not '', and the editor sends '' for an empty box. A min length would
 * turn "I cleared this box" into a 422 on a form the owner has spent ten
 * minutes on — the exact outcome docs/BILINGUAL-PLAN.md rules out.
 *
 * ── 2. RICH TEXT IS SANITISED ON BOTH SIDES ───────────────────────────────
 *
 * ProductEditorApiController runs every description through RichText::clean()
 * because partials/product-tabs prints it with {!! !!}. The Arabic description
 * is printed by the SAME template through the same {!! !!}, so an Arabic box
 * that skipped the sanitiser would be a stored-XSS hole reachable from the
 * product editor — a hole opened by the act of adding the second language.
 * clean() runs the identical RichText::clean over the fields the caller names
 * as rich, so the two boxes on one panel are subject to one rule.
 *
 * ── 3. PREFILL COSTS ONE QUERY, NOT ONE PER ROW ───────────────────────────
 *
 * translationsForEditor() is per model and issues a query each time. Correct
 * for the product editor, which opens one product. Wrong for the categories
 * and brands screens, which list every row on one page — ninety-three brands
 * would be ninety-three queries added to a screen that went out of its way to
 * be one. editorMapFor() is the same answer for a whole collection in a single
 * `whereIn`.
 */
final class TranslationInput
{
    /**
     * The ceiling for a field this helper cannot find an English rule for.
     *
     * docs/BILINGUAL-PLAN.md's figure. `translations.value` is a mediumText, so
     * this is a sanity bound rather than a storage one — it exists so an
     * un-derived field is still bounded instead of unbounded.
     */
    public const CEILING = 65000;

    /**
     * Validation rules for the `translations` bag, mirrored off the English.
     *
     * @param  array<string, mixed>  $englishRules  the controller's own rules
     * @return array<string, list<string>>
     */
    public static function rules(Model $model, array $englishRules = []): array
    {
        $fields = method_exists($model, 'translatable') ? $model->translatable() : [];

        $rules = [
            'translations' => ['sometimes', 'array'],
            'translations.*' => ['array'],
        ];

        $ceiling = 0;

        foreach ($fields as $field) {
            $derived = self::shapeOf($englishRules[$field] ?? null);
            $ceiling = max($ceiling, self::maxOf($derived));

            foreach (Locale::codes() as $locale) {
                if ($locale === Locale::DEFAULT) {
                    continue;
                }

                $rules['translations.'.$locale.'.'.$field] = $derived;
            }
        }

        /*
         * The catch-all, and it is deliberately the LOOSEST of the derived
         * bounds rather than a flat number. It only ever applies to a key with
         * no rule of its own — a field outside the allowlist, or a locale the
         * shop does not run — and saveTranslations() drops both of those
         * without writing anything. Making it stricter than the real fields
         * would mean a 200,000-character Arabic description failing validation
         * against a rule meant for keys that are thrown away.
         */
        $rules['translations.*.*'] = ['nullable', 'string', 'max:'.max($ceiling, self::CEILING)];

        return $rules;
    }

    /**
     * The `translations` bag out of a request, sanitised and ready to store.
     *
     * @param  list<string>  $richFields  fields the storefront prints unescaped
     * @return array<string, array<string, string|null>>
     */
    public static function fromRequest(Request $request, array $richFields = []): array
    {
        $bag = $request->input('translations', []);

        return self::clean(is_array($bag) ? $bag : [], $richFields);
    }

    /**
     * @param  array<mixed>  $byLocale
     * @param  list<string>  $richFields
     * @return array<string, array<string, string|null>>
     */
    public static function clean(array $byLocale, array $richFields = []): array
    {
        $out = [];

        foreach ($byLocale as $locale => $fields) {
            if (! is_string($locale) || ! is_array($fields)) {
                continue;
            }

            $out[$locale] = [];

            foreach ($fields as $field => $value) {
                if (! is_string($field) || ($value !== null && ! is_string($value))) {
                    continue;
                }

                if ($value !== null && in_array($field, $richFields, true)) {
                    $value = RichText::clean($value);

                    // The same test the English side applies: markup with no
                    // words in it — an empty <p>, a stray <br> — is an empty
                    // box, and an empty box means untranslated.
                    $value = RichText::isBlank($value) ? '' : $value;
                }

                $out[$locale][$field] = $value;
            }
        }

        return $out;
    }

    /**
     * Prefill for a whole page of rows, in one query.
     *
     * Shaped exactly like translationsForEditor() per row, drafts included —
     * see that method's note on why a draft has to appear in the box.
     *
     * @param  iterable<Model>  $models
     * @return array<int, array<string, array<string, array{value: string, status: string, source: string, stale: bool}>>>
     */
    public static function editorMapFor(iterable $models): array
    {
        $byId = [];
        $group = null;
        $fields = [];

        foreach ($models as $model) {
            if ($model->getKey() === null) {
                continue;
            }

            $group ??= $model->getTable();
            $fields = method_exists($model, 'translatable') ? $model->translatable() : [];
            $byId[(int) $model->getKey()] = $model;
        }

        $out = [];

        foreach (array_keys($byId) as $id) {
            $out[$id] = self::blankFor($fields);
        }

        if ($group === null || $fields === [] || $byId === []) {
            return $out;
        }

        $rows = Translation::query()
            ->where('group', $group)
            ->whereIn('item_id', array_keys($byId))
            ->get();

        foreach ($rows as $row) {
            $id = (int) $row->item_id;
            $locale = (string) $row->locale;
            $field = (string) $row->field;

            if (! isset($out[$id][$locale][$field])) {
                continue;
            }

            $english = $byId[$id]->getAttribute($field);

            $out[$id][$locale][$field] = [
                'value' => (string) $row->value,
                'status' => (string) $row->status,
                'source' => (string) $row->source,
                'stale' => $row->isStaleAgainst(is_string($english) ? $english : null),
            ];
        }

        return $out;
    }

    /** @param  list<string>  $fields */
    private static function blankFor(array $fields): array
    {
        $blank = [];

        foreach (Locale::codes() as $locale) {
            if ($locale === Locale::DEFAULT) {
                continue;
            }

            $blank[$locale] = [];

            foreach ($fields as $field) {
                $blank[$locale][$field] = ['value' => '', 'status' => '', 'source' => '', 'stale' => false];
            }
        }

        return $blank;
    }

    /**
     * The shape half of one English rule set.
     *
     * @return list<string>
     */
    private static function shapeOf(mixed $englishRule): array
    {
        $tokens = [];

        if (is_string($englishRule)) {
            $englishRule = explode('|', $englishRule);
        }

        if (is_array($englishRule)) {
            foreach ($englishRule as $token) {
                if (! is_string($token)) {
                    // A closure or a Rule object: about the English row's
                    // identity, never about the shape of a translation.
                    continue;
                }

                if (preg_match('/^max:\d+$/', $token) === 1) {
                    $tokens[] = $token;
                }
            }
        }

        return array_merge(['nullable', 'string'], $tokens);
    }

    /** @param  list<string>  $rules */
    private static function maxOf(array $rules): int
    {
        foreach ($rules as $rule) {
            if (preg_match('/^max:(\d+)$/', $rule, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }
}
