<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Is product P inside set S?                                    (Lane FBT3)
 *
 * The owner, 14 November 2026, on the live page of "Dr.Althea 345 Relief
 * Cream": "Frequently bought together" drew the cream itself, then "Dr. Althea
 * 345 Relief Cream + Mist Spray Set", then "Dr.Althea 345 Relief Cream Mist
 * Spray", then a serum -- the cream and the mist spray both sitting beside the
 * set that holds them: "i need a strong solution to identify the product in
 * the set and exclude it."
 *
 * 2.60.667 only knew a set's contents when they were RECORDED (product_set_
 * items). On the live shop not one of 72 set-like products has its contents
 * recorded (no set-contents panel on any of their pages, sampled 14 November):
 * they are ordinary products named "... Set" / "Duo" / "Trio" / "Kit" whose
 * description lists what is inside ("What's Included in the Set: Dr Althea 345
 * Relief Cream: ..."). So membership is read from three signals, and ANY one
 * is enough:
 *
 *   recorded     ProductSetItem rows (the caller passes them in);
 *   name         S is set-like and one PART of its name ("A + B Set", "A & B
 *                Duo") is P's core name -- or, for the elided later part
 *                ("+ Mist Spray"), P's core is the first part's words followed
 *                by that part;
 *   description  one LINE of S's description is exactly P's core name (a
 *                heading or list item: "Dr Althea 345 Relief Cream:",
 *                "SKIN 1004 – Madagascar Centella Toning Toner - 210ml").
 *
 * PRECISION BEFORE RECALL. A false link HIDES a real companion, so the name
 * and description signals both demand the SAME BRAND and an EXACT core (whole
 * part, whole line), never "the words appear somewhere": "345 Relief Cream" is
 * not inside "345 Relief Cream Mask Set" (a set of cream masks), nor inside a
 * line reading "345 Relief Cream Mist Spray". A missed link only leaves the
 * row as it was before this class.
 *
 * Pure: no query, no cache, no settings. BuyTogether hands it the rows it
 * already has.
 *
 * Core name: lower-case letters and digits (letter/digit runs split, as
 * ProductRange does), the brand's own leading words dropped ("Dr.Althea",
 * "Dr. Althea" and "Dr Althea" all read "dr althea"; "SKIN1004" and "SKIN 1004"
 * both), and sizes dropped ("50ml", "5 pcs", "x 2", "1.35 fl oz").
 */
final class SetMembership
{
    /** A word in a name that makes it a set. */
    public const SET_WORDS = ['set', 'sets', 'duo', 'trio', 'kit', 'kits', 'bundle', 'routine'];

    /** Units a number is dropped with. */
    private const UNITS = [
        'ml', 'l', 'g', 'gr', 'kg', 'mg', 'oz', 'fl', 'pcs', 'pc', 'ea', 'count', 'ct', 'sheet', 'sheets',
        'pads', 'patches', 'capsules', 'pieces', 'piece', 'x',
    ];

    /** The shortest core that may be matched: two words, eight letters. */
    private const MIN_WORDS = 2;

    private const MIN_CHARS = 8;

    public static function isSetLike(?string $type, ?string $name): bool
    {
        if ($type === 'set') {
            return true;
        }

        return array_intersect(self::tokens((string) $name), self::SET_WORDS) !== [];
    }

    /**
     * P's core name, or null when it is too short to match safely.
     *
     * @return list<string>|null
     */
    public static function core(?string $brand, ?string $name): ?array
    {
        $words = self::strip(self::tokens((string) $name), $brand);

        return self::usable($words) ? $words : null;
    }

    /**
     * The name signal: is P (brand $brand, name $name) a part of the set's name?
     */
    public static function byName(?string $brand, string $setName, string $name): bool
    {
        $core = self::core($brand, $name);

        if ($core === null) {
            return false;
        }

        $parts = [];

        foreach (preg_split('/\s*[+\/]\s*|\s+(?:&|and|with)\s+/iu', $setName) ?: [] as $part) {
            $words = array_values(array_diff(self::strip(self::tokens($part), $brand), self::SET_WORDS));

            if ($words !== []) {
                $parts[] = $words;
            }
        }

        if ($parts === []) {
            return false;
        }

        foreach ($parts as $i => $words) {
            if ($words === $core) {
                return true;
            }

            // "345 Relief Cream + Mist Spray Set": the later part elides the
            // first part's words. P is those words followed by this part.
            if ($i > 0 && count($core) > count($words) && array_slice($core, -count($words)) === $words) {
                $head = array_slice($core, 0, count($core) - count($words));

                if (array_slice($parts[0], 0, count($head)) === $head) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The description signal: is one line of the set's description P's core?
     * $lines is linesOf() of the description (computed once per set).
     *
     * @param  list<list<string>>  $lines
     */
    public static function byDescription(?string $brand, array $lines, string $name): bool
    {
        $core = self::core($brand, $name);

        if ($core === null) {
            return false;
        }

        foreach ($lines as $line) {
            if (self::strip($line, $brand) === $core) {
                return true;
            }
        }

        return false;
    }

    /**
     * A description cut into its lines and headings, each as words. Block tags
     * and line breaks end a line, and so do ":", "|", "•", ";", brackets, "+"
     * and a spaced dash -- the ways a list item separates a product's name
     * from its size or its blurb. Long lines (prose) are dropped: no product
     * name is twenty words.
     *
     * @return list<list<string>>
     */
    public static function linesOf(?string $html): array
    {
        $html = (string) $html;

        if ($html === '') {
            return [];
        }

        $text = (string) preg_replace('~<\s*(?:br|/?p|/?li|/?ul|/?ol|/?div|/?h[1-6]|/?tr|/?td|/?strong|/?b)\b[^>]*>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out = [];

        foreach (preg_split('/\n|[:|•·;()\[\]+]|\s[-–—]\s|^[-–—]\s|\s[-–—]$/u', $text) ?: [] as $seg) {
            $words = self::tokens($seg);
            // "1." / "2)" list numbering and "1 x" / "2x" counts in front of a name.
            if (preg_match('/^\s*\d+\s*(?:[.)]|x\b)/iu', $seg) === 1) {
                $words = array_slice($words, ($words[1] ?? '') === 'x' ? 2 : 1);
            }

            if ($words !== [] && count($words) <= 20) {
                $out[] = $words;
            }
        }

        return $out;
    }

    /**
     * Every link among these products.
     *
     * $sets: set-like rows [id => ['brand_id' => ?int, 'brand' => ?string,
     *   'name' => string, 'description' => ?string, 'recorded' => list<int>]].
     * $products: candidates [id => ['brand_id' => ?int, 'brand' => ?string,
     *   'name' => string]] -- the page product and the cards.
     *
     * @return array<int, array<int, string>>  set id => [member id => signal]
     */
    public static function links(array $sets, array $products): array
    {
        $out = [];

        foreach ($sets as $sid => $s) {
            $sid = (int) $sid;
            $lines = null;

            foreach ($products as $pid => $p) {
                $pid = (int) $pid;

                if ($pid === $sid) {
                    continue;
                }

                $signal = null;

                if (in_array($pid, array_map('intval', $s['recorded'] ?? []), true)) {
                    $signal = 'recorded';
                } elseif (($s['brand_id'] ?? null) !== null && (int) $s['brand_id'] === (int) ($p['brand_id'] ?? 0)
                    && ! self::isSetLike(null, (string) $p['name'])) {
                    if (self::byName($p['brand'] ?? null, (string) $s['name'], (string) $p['name'])) {
                        $signal = 'name';
                    } else {
                        $lines ??= self::linesOf($s['description'] ?? null);

                        if (self::byDescription($p['brand'] ?? null, $lines, (string) $p['name'])) {
                            $signal = 'description';
                        }
                    }
                }

                if ($signal !== null) {
                    $out[$sid][$pid] = $signal;
                }
            }
        }

        return $out;
    }

    /** @param list<string> $words */
    private static function usable(array $words): bool
    {
        return count($words) >= self::MIN_WORDS && mb_strlen(implode('', $words)) >= self::MIN_CHARS;
    }

    /**
     * Drop the brand's leading words and every size.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    private static function strip(array $words, ?string $brand): array
    {
        $squash = implode('', self::tokens((string) $brand));

        if ($squash !== '') {
            $acc = '';

            foreach ($words as $i => $w) {
                $acc .= $w;

                if ($acc === $squash) {
                    $words = array_slice($words, $i + 1);
                    break;
                }

                if (! str_starts_with($squash, $acc)) {
                    break;
                }
            }
        }

        $out = [];
        $n = count($words);

        for ($i = 0; $i < $n; $i++) {
            $w = $words[$i];

            if (ctype_digit($w)) {
                // "50 ml", "1 35 fl oz" (1.35 fl oz), "x 2", "5 pcs"
                $j = $i + 1;

                while ($j < $n && ctype_digit($words[$j]) && $j === $i + 1) {
                    $j++;
                }

                if ($j < $n && in_array($words[$j], self::UNITS, true)) {
                    while ($j < $n && in_array($words[$j], self::UNITS, true)) {
                        $j++;
                    }
                    $i = $j - 1;

                    continue;
                }

                if (($out !== [] && end($out) === 'x')) {
                    array_pop($out);

                    continue;
                }
            }

            $out[] = $w;
        }

        return $out;
    }

    /** @return list<string> */
    private static function tokens(string $value): array
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = (string) preg_replace('/(\p{L})(\p{N})/u', '$1 $2', $value);
        $value = (string) preg_replace('/(\p{N})(\p{L})/u', '$1 $2', $value);

        preg_match_all('/[\p{L}\p{N}]+/u', $value, $matches);

        return $matches[0];
    }
}
