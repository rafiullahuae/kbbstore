<?php

declare(strict_types=1);

namespace App\Services\Mail\Kit;

use App\Models\Translation;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationStore;
use Illuminate\Translation\Translator;

/**
 * The template editor's "Words" card — Lane EK.
 *
 * An email's subject, preview line, headline and message are interface strings
 * (`email.order_status.shipped_subject` …), and interface strings already have
 * a home: one row per locale in `translations`, which __() reads first and
 * Translation → Strings edits. This class edits THOSE rows — it does not keep
 * a second copy of any sentence. "Anything you leave blank uses the built-in
 * wording" is literally how the table works: a blank deletes the row and the
 * code's English (or the Arabic draft's fallback) is what __() returns.
 *
 * TAGS. The strings carry Laravel placeholders (`:number`, `:store`); the
 * owner sees and types `{number}`, `{store}` (the approved mock's tags). Only
 * the placeholders the built-in English actually has are offered and turned
 * back into `:name` on save — a `{tag}` the email does not fill stays literal
 * text rather than becoming a placeholder nothing replaces.
 *
 * Everything saved here is printed through {{ }} by the kit, so a value is
 * text and only text.
 */
final class KitWords
{
    public const MAX = 1000;

    /** @return list<array{label:string,key:string,tags:list<string>,builtin:string,en:string,ar:string,ar_status:string,shared:int}> */
    public static function fields(string $template): array
    {
        $words = KitSections::TEMPLATES[$template]['words'] ?? [];
        $rows = self::rows(array_values($words));
        $out = [];

        foreach ($words as $label => $key) {
            $english = (string) (InterfaceStrings::english($key) ?? '');
            $tags = self::tags($english);
            $out[] = [
                'label' => $label,
                'key' => $key,
                'tags' => $tags,
                'builtin' => self::toTags($english, $tags),
                'en' => self::toTags((string) ($rows['en'][$key]->value ?? ''), $tags),
                'ar' => self::toTags((string) ($rows['ar'][$key]->value ?? ''), $tags),
                'ar_status' => (string) ($rows['ar'][$key]->status ?? ''),
                'shared' => self::sharedWith($template, $key),
            ];
        }

        return $out;
    }

    /**
     * Save the words of one email. $words = ['en' => [key => text], 'ar' => …];
     * only this email's own keys, only the two locales. Returns the keys that
     * were refused, with why.
     *
     * @return array<string, string>
     */
    public static function save(string $template, array $words): array
    {
        $allowed = array_values(KitSections::TEMPLATES[$template]['words'] ?? []);
        $refused = [];

        foreach (['en', 'ar'] as $locale) {
            foreach ((array) ($words[$locale] ?? []) as $key => $value) {
                if (! in_array($key, $allowed, true)) {
                    $refused[$key] = 'Not a word of this email.';

                    continue;
                }

                $value = is_scalar($value) || $value === null ? trim((string) $value) : null;

                if ($value === null || mb_strlen($value) > self::MAX || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $value) === 1) {
                    $refused[$key] = 'Text only, up to ' . self::MAX . ' characters.';

                    continue;
                }

                $english = (string) (InterfaceStrings::english($key) ?? '');
                $stored = self::fromTags($value, self::tags($english));

                if ($stored === '') {
                    Translation::query()->where('locale', $locale)->where('group', Translation::GROUP_UI)
                        ->where('item_id', 0)->where('field', TranslationStore::normaliseKey($key))->get()
                        ->each(static fn (Translation $row) => $row->delete());

                    continue;
                }

                TranslationStore::put($locale, Translation::GROUP_UI, 0, $key, $stored,
                    Translation::STATUS_PUBLISHED, Translation::SOURCE_MANUAL, $english);
            }
        }

        TranslationStore::flush();

        return $refused;
    }

    /** "Reset to default": the English rows go; Arabic is a translation and stays. */
    public static function resetEnglish(string $template): void
    {
        $keys = array_map([TranslationStore::class, 'normaliseKey'], array_values(KitSections::TEMPLATES[$template]['words'] ?? []));

        Translation::query()->where('locale', 'en')->where('group', Translation::GROUP_UI)
            ->where('item_id', 0)->whereIn('field', $keys === [] ? [''] : $keys)->get()
            ->each(static fn (Translation $row) => $row->delete());

        TranslationStore::flush();
    }

    /**
     * Render with words that are not saved yet — the live preview. A
     * translator of its own for the length of $render, reading the real one's
     * lines with the draft laid over them; the real one is put back after.
     *
     * @template T
     *
     * @param  array{en?:array<string,string>,ar?:array<string,string>}  $words
     * @param  callable(): T  $render
     * @return T
     */
    public static function withDraft(string $template, array $words, callable $render): mixed
    {
        $allowed = array_values(KitSections::TEMPLATES[$template]['words'] ?? []);
        $overlay = [];

        foreach (['en', 'ar'] as $locale) {
            foreach ((array) ($words[$locale] ?? []) as $key => $value) {
                $value = is_scalar($value) ? trim((string) $value) : '';

                if (! in_array($key, $allowed, true) || $value === '' || mb_strlen($value) > self::MAX) {
                    continue;
                }

                $overlay[$locale][$key] = self::fromTags($value, self::tags((string) (InterfaceStrings::english($key) ?? '')));
            }
        }

        if ($overlay === []) {
            return $render();
        }

        $real = app('translator');
        $draft = new Translator(new KitWordsOverlay($real->getLoader(), $overlay), $real->getLocale());
        $draft->setFallback($real->getFallback());
        app()->instance('translator', $draft);

        try {
            return $render();
        } finally {
            app()->instance('translator', $real);
        }
    }

    /** @return list<string> the placeholder names in a string, as written */
    public static function tags(string $english): array
    {
        preg_match_all('/:([a-z_]+)/', $english, $m);

        return array_values(array_unique($m[1]));
    }

    public static function toTags(string $text, array $tags): string
    {
        foreach ($tags as $tag) {
            $text = (string) preg_replace('/:' . preg_quote($tag, '/') . '(?![a-z_])/', '{' . $tag . '}', $text);
        }

        return $text;
    }

    public static function fromTags(string $text, array $tags): string
    {
        foreach ($tags as $tag) {
            $text = str_replace('{' . $tag . '}', ':' . $tag, $text);
        }

        return $text;
    }

    /** @return array<string, array<string, object>> locale => key => row */
    private static function rows(array $keys): array
    {
        $out = ['en' => [], 'ar' => []];

        if ($keys === []) {
            return $out;
        }

        $fields = array_map([TranslationStore::class, 'normaliseKey'], $keys);
        $byField = array_combine($fields, $keys);

        foreach (Translation::query()->whereIn('locale', ['en', 'ar'])->where('group', Translation::GROUP_UI)
            ->where('item_id', 0)->whereIn('field', $fields)->get(['locale', 'field', 'value', 'status']) as $row) {
            $key = $byField[(string) $row->field] ?? null;

            if ($key !== null && ($row->locale === 'ar' || $row->status === Translation::STATUS_PUBLISHED)) {
                $out[(string) $row->locale][$key] = $row;
            }
        }

        return $out;
    }

    private static function sharedWith(string $template, string $key): int
    {
        $n = 0;

        foreach (KitSections::TEMPLATES as $other => $def) {
            if ($other !== $template && in_array($key, $def['words'], true)) {
                $n++;
            }
        }

        return $n;
    }
}
