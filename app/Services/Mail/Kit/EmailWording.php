<?php

declare(strict_types=1);

namespace App\Services\Mail\Kit;

use App\Services\Translation\InterfaceStrings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The template builder's words — subject, preview line, headline, message —
 * stored in `email_templates` (docs/EMAILS-PLAN.md §2.1). Lane EK.
 *
 * A BLANK FIELD IS THE BUILT-IN WORDING, and the built-in wording is the
 * interface string the email has always printed (`email.order_status.
 * shipped_subject` …). So the override sits in FRONT of __(): DatabaseTranslationLoader
 * lays the stored words over the `email` group as it loads (its layer 4), and an email
 * nobody edited asks __() exactly what it asked before.
 *
 * LANGUAGE: Arabic uses the Arabic row, then the English row, then the code
 * (the plan's rule — the owner's English correction is better than a stale
 * draft). English uses the English row, then the code.
 *
 * TAGS: the owner types {number}, {store}, {name} (the approved mock's tags);
 * only the placeholders the built-in English actually has are offered and
 * become :number etc. A {tag} the email does not fill stays as typed.
 *
 * NEVER HTML: every field is printed by the kit through {{ }}. The subject is
 * one line — a CR or LF in a subject is a header injection, and is refused.
 *
 * A KEY SHARED BY SEVERAL EMAILS (the status emails' one preview line,
 * `email.kit.pre_status`) is not overlaid on the group — that would change all
 * seven at once. The view asks line() for its own email instead.
 */
final class EmailWording
{
    public const TABLE = 'email_templates';
    public const FIELDS = ['subject' => 200, 'preheader' => 200, 'heading' => 200, 'body' => 2000];
    public const LABELS = [
        'subject' => 'Subject',
        'preheader' => 'Preview line (shown after the subject in the inbox)',
        'heading' => 'Headline',
        'body' => 'Message',
    ];

    /** @var array<string, array<string, object>>|null key => locale => row */
    private static ?array $rows = null;

    /** @var array<string, int>|null built from the TEMPLATES constant */
    private static ?array $uses = null;

    /** @var array<string, array<string, array<string, string>>> template => locale => field => text, for the preview */
    private static array $drafts = [];

    /** The words a template's field resolves to in $locale, as stored ({tags}), or null for built-in. */
    public static function value(string $template, string $field, string $locale): ?string
    {
        foreach (array_unique([$locale, 'en']) as $l) {
            $draft = self::$drafts[$template][$l][$field] ?? null;

            if ($draft !== null && $draft !== '') {
                return $draft;
            }

            $stored = trim((string) (self::rows()[$template][$l]->{$field} ?? ''));

            if ($stored !== '') {
                return $stored;
            }
        }

        return null;
    }

    /**
     * One email's own line, placeholders filled — for a key several emails
     * share. Null when the owner has not written one.
     */
    public static function line(string $template, string $field, array $replace = []): ?string
    {
        $key = KitSections::TEMPLATES[$template]['words'][$field] ?? null;
        $text = self::value($template, $field, app()->getLocale());

        if ($key === null || $text === null) {
            return null;
        }

        $text = self::fromTags($text, self::tags((string) (InterfaceStrings::english($key) ?? '')));
        uksort($replace, static fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));

        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }

    /**
     * The overlay for one translation group in one locale: [item => line],
     * every UNSHARED key of every email the owner has worded.
     *
     * @return array<string, string>
     */
    public static function overlay(string $locale, string $group): array
    {
        $out = [];
        $uses = self::keyUses();

        foreach (KitSections::TEMPLATES as $template => $def) {
            foreach ($def['words'] as $field => $key) {
                if (($uses[$key] ?? 0) !== 1 || ! str_starts_with($key, $group . '.')) {
                    continue;
                }

                $text = self::value($template, $field, $locale);

                if ($text !== null) {
                    $out[substr($key, strlen($group) + 1)] = self::fromTags($text, self::tags((string) (InterfaceStrings::english($key) ?? '')));
                }
            }
        }

        return $out;
    }

    /** The editor's fields for one email, both languages. */
    public static function fields(string $template): array
    {
        $out = [];

        foreach (KitSections::TEMPLATES[$template]['words'] ?? [] as $field => $key) {
            $english = (string) (InterfaceStrings::english($key) ?? '');
            $tags = self::tags($english);
            $out[] = [
                'field' => $field,
                'label' => self::LABELS[$field],
                'max' => self::FIELDS[$field],
                'multiline' => $field === 'body',
                'tags' => $tags,
                'builtin' => self::toTags($english, $tags),
                'en' => (string) (self::rows()[$template]['en']->{$field} ?? ''),
                'ar' => (string) (self::rows()[$template]['ar']->{$field} ?? ''),
                'shared' => (self::keyUses()[$key] ?? 0) - 1,
            ];
        }

        return $out;
    }

    /**
     * Save words: ['en' => [field => text], 'ar' => …]. Only this email's own
     * fields; plain text within the field's length; no CR/LF in a one-line
     * field. Returns refusals [locale.field => why]; nothing refused is saved.
     *
     * @return array<string, string>
     */
    public static function save(string $template, array $words, ?int $by = null): array
    {
        $allowed = KitSections::TEMPLATES[$template]['words'] ?? [];
        $refused = [];
        $clean = [];

        foreach (['en', 'ar'] as $locale) {
            foreach ((array) ($words[$locale] ?? []) as $field => $value) {
                $why = self::problem((string) $field, $value, $allowed);

                if ($why !== null) {
                    $refused[$locale . '.' . $field] = $why;

                    continue;
                }

                $clean[$locale][$field] = trim((string) $value);
            }
        }

        if ($refused !== []) {
            return $refused;
        }

        foreach ($clean as $locale => $fields) {
            $values = array_map(static fn (string $v) => $v === '' ? null : $v, $fields);
            DB::table(self::TABLE)->updateOrInsert(['key' => $template, 'locale' => $locale], $values + ['updated_by' => $by, 'updated_at' => now(), 'created_at' => now()]);
        }

        self::forget();

        return [];
    }

    /** Why a value may not be stored, or null. */
    public static function problem(string $field, mixed $value, array $allowed): ?string
    {
        if (! isset($allowed[$field])) {
            return 'Not a field of this email.';
        }

        if ($value !== null && ! is_scalar($value)) {
            return 'Text only.';
        }

        $value = (string) $value;

        if (mb_strlen($value) > self::FIELDS[$field]) {
            return 'At most ' . self::FIELDS[$field] . ' characters.';
        }

        if ($field !== 'body' && preg_match('/[\r\n]/', $value) === 1) {
            return 'One line only — a line break here could change the email\'s headers.';
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return 'Text only.';
        }

        return null;
    }

    /**
     * Render with unsaved words — the live preview. The translator forgets the
     * groups it loaded before and after, so the draft is read in and then gone.
     *
     * @template T
     *
     * @param  callable(): T  $render
     * @return T
     */
    public static function withDraft(string $template, array $words, callable $render): mixed
    {
        $allowed = KitSections::TEMPLATES[$template]['words'] ?? [];
        $draft = [];

        foreach (['en', 'ar'] as $locale) {
            foreach ((array) ($words[$locale] ?? []) as $field => $value) {
                if (self::problem((string) $field, $value, $allowed) === null && trim((string) $value) !== '') {
                    $draft[$locale][$field] = trim((string) $value);
                }
            }
        }

        if ($draft === []) {
            return $render();
        }

        self::$drafts[$template] = $draft;
        app('translator')->setLoaded([]);

        try {
            return $render();
        } finally {
            unset(self::$drafts[$template]);
            app('translator')->setLoaded([]);
        }
    }

    /** "Reset to default": the email's rows go, in both languages — order and words. */
    public static function reset(string $template): void
    {
        DB::table(self::TABLE)->where('key', $template)->delete();
        self::forget();
    }

    public static function updatedAt(string $template): ?string
    {
        $at = null;

        foreach (self::rows()[$template] ?? [] as $row) {
            $at = max($at ?? '', (string) $row->updated_at);
        }

        return $at === null || $at === '' ? null : \Illuminate\Support\Carbon::parse($at)->toIso8601String();
    }

    /** @return list<string> */
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

    public static function forget(): void
    {
        self::$rows = null;
        self::$drafts = [];
        self::$uses = null;

        try {
            app('translator')->setLoaded([]);
        } catch (\Throwable) {
            // No translator yet: nothing loaded to forget.
        }
    }

    /** @return array<string, array<string, object>> */
    public static function rows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        self::$rows = [];

        try {
            if (Schema::hasTable(self::TABLE)) {
                foreach (DB::table(self::TABLE)->get() as $row) {
                    self::$rows[(string) $row->key][(string) $row->locale] = $row;
                }
            }
        } catch (\Throwable $e) {
            // An email never fails because its overrides could not be read: it
            // goes out in the built-in wording and order.
            Log::warning('email templates not read', ['exception' => class_basename($e)]);
        }

        return self::$rows;
    }

    /** @return array<string, int> translation key => how many emails use it as a word field */
    private static function keyUses(): array
    {
        if (self::$uses === null) {
            self::$uses = [];

            foreach (KitSections::TEMPLATES as $def) {
                foreach ($def['words'] as $key) {
                    self::$uses[$key] = (self::$uses[$key] ?? 0) + 1;
                }
            }
        }

        return self::$uses;
    }
}
