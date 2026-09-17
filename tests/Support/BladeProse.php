<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Finds English prose that a shopper reads and __() does not.
 *
 * ── WHAT COUNTS AS PROSE, AND WHY THE ORDER OF THE STRIPPING MATTERS ────────
 *
 * A Blade file is not HTML and cannot be read as HTML. `<a href="{{ $p->url() }}"
 * title="{{ $x }}">` contains braces inside a tag and a tag inside an
 * expression, so anything that blanks tags before it blanks expressions
 * mis-parses the file and then reports the wreckage as English. The order below
 * is therefore fixed and is the whole of why this is a class rather than a
 * regex at a call site:
 *
 *   1. Blade comments            {{-- … --}}
 *   2. verbatim, script, style   (their own bodies are handled separately)
 *   3. ECHOES                    {{ … }}, {!! … !!}, {{{ … }}}
 *   4. @php … @endphp and every other directive, with its arguments
 *   5. TAGS                      what is left really is a tag
 *   6. entities and glyphs       &times;, ★, emoji — a picture has no language
 *
 * What survives all six is a run of words between two tags, which is exactly
 * what a shopper reads and exactly what has to go through __().
 *
 * Attributes are checked separately and only the ones a person reads:
 * placeholder, title, alt, aria-label and the like. `class`, `href`, `data-*`
 * and `style` are identifiers and addresses, and translating one breaks the
 * page rather than the sentence — the worked example in
 * resources/views/store/wishlist.blade.php says the same thing about the keys
 * passed to an @include.
 */
final class BladeProse
{
    /** Attributes whose value a person reads. */
    private const HUMAN_ATTRS = [
        'title', 'alt', 'placeholder', 'aria-label', 'aria-placeholder',
        'aria-description', 'aria-valuetext', 'label', 'data-confirm',
    ];

    /**
     * Every run of English prose left in one Blade file.
     *
     * @return list<array{kind: string, line: int, text: string}>
     */
    public static function find(string $path): array
    {
        $source = file_get_contents($path);

        if ($source === false) {
            return [];
        }

        $hits = [];

        foreach (self::attributeHits($source) as $hit) {
            $hits[] = $hit;
        }

        foreach (self::textHits($source) as $hit) {
            $hits[] = $hit;
        }

        return $hits;
    }

    /** Blank a run while keeping every byte offset and every newline. */
    private static function blank(string $match): string
    {
        return preg_replace('/[^\n]/', ' ', $match) ?? '';
    }

    private static function blankAll(string $pattern, string $value): string
    {
        return (string) preg_replace_callback(
            $pattern,
            static fn (array $m): string => self::blank($m[0]),
            $value
        );
    }

    /** Everything a shopper cannot read, blanked out, positions preserved. */
    private static function strip(string $source, bool $keepTags): string
    {
        // 1. Blade comments.
        $value = self::blankAll('/\{\{--.*?--\}\}/s', $source);

        // 2. Script and style bodies. Their strings are the front-end's, and
        //    resources/js/kbb/i18n.js is what answers for those.
        $value = self::blankAll('#<script\b[^>]*>.*?</script>#is', $value);
        $value = self::blankAll('#<style\b[^>]*>.*?</style>#is', $value);

        /*
         * And the sections and stacks that CARRY css or javascript without a
         * tag of their own, because the layout supplies the tag. The printed
         * documents do this -- invoices/shipping-label.blade.php puts 30 lines
         * of CSS in @section('style') -- and a stylesheet read as prose is 26
         * false reports in one file, which is how a guard stops being read.
         */
        $value = self::blankAll(
            "/@(?:section|push|prepend)\s*\(\s*'(?:style|styles|page|script|scripts|head)'\s*\).*?@(?:endsection|endpush|endprepend)/s",
            $value
        );

        // 3. Echoes, BEFORE tags: an echo can contain a '>' and a tag can
        //    contain an echo, and only this order survives both.
        $value = self::blankAll('/\{\{\{.*?\}\}\}/s', $value);
        $value = self::blankAll('/\{!!.*?!!\}/s', $value);
        $value = self::blankAll('/\{\{.*?\}\}/s', $value);

        // 4. Directives, with their arguments (one level of nested brackets).
        $value = self::blankAll('/@php\b.*?@endphp/s', $value);
        $value = self::blankAll('/@[a-zA-Z]+\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/s', $value);
        $value = self::blankAll('/@[a-zA-Z]+/', $value);

        if ($keepTags) {
            return $value;
        }

        /*
         * 5. What is left really is a tag — but a tag's attribute values have
         *    to be matched as units, not skipped over. `<x-product-card
         *    :eager="$loop->first" />` contains a '>' inside an attribute, and a
         *    lazy /<[^>]*>/ stops at it and leaves `first" />` behind, which
         *    then reads as prose.
         */
        return self::blankAll(
            '/<\/?[a-zA-Z!][^>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^>"\']*)*>/s',
            $value
        );
    }

    /** @return list<array{kind: string, line: int, text: string}> */
    private static function attributeHits(string $source): array
    {
        $value = self::strip($source, keepTags: true);
        $hits = [];

        if (! preg_match_all('/<[a-zA-Z][^>]*>/s', $value, $tags, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($tags[0] as [$tag, $offset]) {
            foreach (self::HUMAN_ATTRS as $attr) {
                /*
                 * NOT `:label="…"`. A colon in front of an attribute is Blade's
                 * bind syntax and what follows is PHP, which the echo-stripping
                 * above does not reach because it is not an echo. Every one of
                 * those on this storefront is already a __() call; treating the
                 * expression as prose would report each of them as a miss.
                 */
                $pattern = '/(?<![:\w-])' . preg_quote($attr, '/') . '\s*=\s*"([^"]*)"/i';

                if (! preg_match_all($pattern, $tag, $found)) {
                    continue;
                }

                foreach ($found[1] as $raw) {
                    if (self::isProse($raw)) {
                        $hits[] = [
                            'kind' => $attr,
                            'line' => 1 + substr_count(substr($value, 0, $offset), "\n"),
                            'text' => trim($raw),
                        ];
                    }
                }
            }
        }

        return $hits;
    }

    /** @return list<array{kind: string, line: int, text: string}> */
    private static function textHits(string $source): array
    {
        $value = self::strip($source, keepTags: false);
        $hits = [];

        foreach (explode("\n", $value) as $i => $line) {
            if (self::isProse($line)) {
                $hits[] = ['kind' => 'text', 'line' => $i + 1, 'text' => trim($line)];
            }
        }

        return $hits;
    }

    /**
     * Is this run of characters something a shopper reads as words?
     *
     * Two letters in a row is the bar. One letter is a bullet, an initial or a
     * column of a table; "AED" and "SKU" are already excluded because they only
     * ever appear beside an expression that has been blanked, leaving them on a
     * line of their own — and a currency code is not translated anyway.
     */
    private static function isProse(string $value): bool
    {
        $text = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($text === '') {
            return false;
        }

        // A run of two or more Latin letters. Arabic, digits, punctuation and
        // pictographs are not prose in an English template.
        return (bool) preg_match('/[A-Za-z]{2,}/', $text);
    }
}
