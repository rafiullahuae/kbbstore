<?php

declare(strict_types=1);

namespace App\Services\RecentLocalise;

/**
 * Is a translated HTML field the SAME DOCUMENT as its English? (Lane LT)
 *
 * Product descriptions here are HTML printed raw on the product page, twice
 * (desktop panel, phone accordion). A machine answer that drops a </li>,
 * renames a class, rewrites an href or adds a <span> breaks that page in
 * Arabic only, where nobody at the shop reads it. So the rule is mechanical:
 * the ordered list of tags -- name, open/close, and every attribute with its
 * value -- must be identical between source and answer. Only the words
 * between the tags may differ. Anything else is rejected with the reason and
 * reported; nothing is stored.
 *
 * Also checked, because the brief is skincare and a safety claim is a number:
 * every number in the English (50, 2.5, 1,000) appears in the Arabic, in
 * Western digits. A field with letters must come back with Arabic letters.
 */
final class HtmlStructure
{
    /**
     * The tag sequence: one string per tag, e.g. "<p class=\"x\">", "</p>",
     * "<!--c-->". Attribute names lower-cased, values entity-decoded and
     * whitespace-collapsed, quotes normalised, order kept.
     *
     * @return list<string>
     */
    public static function signature(string $html): array
    {
        preg_match_all('/<!--.*?-->|<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $html, $m, PREG_SET_ORDER);

        $out = [];

        foreach ($m as $tag) {
            if (str_starts_with($tag[0], '<!--')) {
                $out[] = '<!--'.trim(substr($tag[0], 4, -3)).'-->';
                continue;
            }

            $closing = $tag[1] === '/';
            $name = strtolower($tag[2]);
            $rest = rtrim($tag[3]);
            $selfClose = str_ends_with($rest, '/');
            if ($selfClose) {
                $rest = substr($rest, 0, -1);
            }

            $attrs = [];
            preg_match_all('/([^\s=\/"\']+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?/', $rest, $am, PREG_SET_ORDER);
            foreach ($am as $a) {
                $value = $a[2] ?? null;
                if ($value !== null) {
                    if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                        $value = substr($value, 1, -1);
                    }
                    $value = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    $attrs[] = strtolower($a[1]).'="'.$value.'"';
                } else {
                    $attrs[] = strtolower($a[1]);
                }
            }

            $out[] = '<'.($closing ? '/' : '').$name.($attrs === [] ? '' : ' '.implode(' ', $attrs)).'>';
        }

        return $out;
    }

    /** Numbers as written in the source: 50, 2.5, 1,000, 10%. */
    public static function numbers(string $text): array
    {
        $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/\d+(?:[.,]\d+)*/u', $plain, $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * Null when the answer is acceptable, otherwise the one-line reason.
     */
    public static function problem(string $english, string $arabic): ?string
    {
        if (trim($arabic) === '') {
            return 'the answer was empty';
        }

        $want = self::signature($english);
        $got = self::signature($arabic);

        if ($want !== $got) {
            $at = 0;
            $n = max(count($want), count($got));
            while ($at < $n && ($want[$at] ?? null) === ($got[$at] ?? null)) {
                $at++;
            }

            return 'the HTML changed (tag '.($at + 1).': expected '.self::short($want[$at] ?? 'nothing').', got '.self::short($got[$at] ?? 'nothing').')';
        }

        // A stray "<" that is not a tag means the structure check above could
        // not see something the browser will.
        if (substr_count($english, '<') !== substr_count($arabic, '<')) {
            return 'the answer added or removed a "<"';
        }

        $plainArabic = html_entity_decode(strip_tags($arabic), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach (self::numbers($english) as $number) {
            if (! str_contains($plainArabic, $number)) {
                return 'the number '.$number.' is missing from the Arabic';
            }
        }

        if (preg_match('/\p{L}/u', strip_tags($english)) === 1 && preg_match('/\p{Arabic}/u', $plainArabic) !== 1) {
            return 'the answer has no Arabic in it';
        }

        return null;
    }

    private static function short(string $tag): string
    {
        return mb_strlen($tag) > 60 ? mb_substr($tag, 0, 57).'…' : $tag;
    }
}
