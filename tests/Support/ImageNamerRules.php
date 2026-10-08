<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\ImageSeo\AltText;
use App\Services\ImageSeo\ImageNamer;
use Illuminate\Support\Str;

/**
 * The Image SEO quality rules, as one checker run over the whole training
 * corpus (tests/Fixtures/image-namer-titles.php). (Lane IN)
 *
 * Pure string work, no database, so it is also cheap to run by hand:
 *   php -r 'require "vendor/autoload.php"; print_r(Tests\Support\ImageNamerRules::check(require "tests/Fixtures/image-namer-titles.php"));'
 *
 * It reads the title with its OWN small tokenizer rather than through
 * ImageNamer, so a rule cannot pass merely because the namer agrees with
 * itself.
 */
final class ImageNamerRules
{
    public const RULES = ['slug', 'brand-once', 'type-run', 'set-type', 'dangling-number', 'name-number', 'by-edge', 'identity-first', 'repeat', 'noise',
        'alt-length', 'alt-separators', 'alt-distinct', 'alt-brand', 'alt-set', 'alt-arabic'];

    private const STOP = ['a', 'an', 'the', 'and', 'or', 'with', 'for', 'of', 'in', 'on', 'to', 'by', 'from', 'at', 'x', 'plus', 'amp'];

    /** Title words a filename may leave out without losing what the product is. */
    private const NOISE = ['new', 'version', 'pa', 'fl', 'oz'];

    private const COUNT_WORDS = 'ea|pcs?|pieces?|pairs?|sheets?|patches|pads|items?|caps|capsules|ct|count|tablets|sticks|masks|steps?';

    private const PACK_WORD = '/^\d+(?:ml|g|kg|mg|oz|l|ea|pcs?|ct)$/';

    private const SET_WORDS = ['set', 'kit', 'duo', 'trio', 'bundle', 'pack', 'collection'];

    /**
     * Every violation, as "rule · brand · title · detail".
     *
     * @param  list<array{0: ?string, 1: string, 2: string, 3: bool}>  $corpus
     * @return list<string>
     */
    public static function check(array $corpus): array
    {
        $out = [];

        foreach ($corpus as [$brand, $title, $category, $isSet]) {
            foreach (self::violations($brand, $title, $category, $isSet) as $v) {
                $out[] = $v[0].' · '.($brand ?? '∅').' · '.$title.' · '.$v[1];
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, int> violations per rule */
    public static function tally(array $violations): array
    {
        $t = array_fill_keys(self::RULES, 0);

        foreach ($violations as $v) {
            $t[explode(' · ', $v)[0]]++;
        }

        return array_filter($t);
    }

    /** @return list<array{0: string, 1: string}> */
    public static function violations(?string $brand, string $title, string $category, bool $isSet): array
    {
        $v = [];
        $raw = self::titleTokens($title);
        $bare = array_values(array_filter($raw, static fn ($w) => ! in_array($w, self::STOP, true)));
        $marked = array_values(array_filter(self::titleTokens($title, true), static fn ($w) => ! in_array($w, self::STOP, true)));
        $brandSlug = ImageNamer::slug((string) $brand);
        $brandWords = $brandSlug === '' ? [] : explode('-', $brandSlug);
        $g = ImageNamer::groups($brand, $title);
        $type = implode('-', $g['type']);

        // The type phrase of a set: contents first, then the set word.
        if ($isSet) {
            $last = null;

            foreach ($g['type'] as $i => $w) {
                if (in_array($w, self::SET_WORDS, true)) {
                    $last = $i;
                }
            }

            if ($last === null || $last < 1) {
                $v[] = ['set-type', 'type "'.$type.'" does not carry its contents and the set word'];
            }
        }

        foreach (ImageNamer::STRATEGIES as $strategy) {
            for ($n = 1; $n <= 8; $n++) {
                $names = ImageNamer::names($brand, $title, $n, $strategy);

                if (count(array_unique(array_map('strval', $names))) !== $n) {
                    $v[] = ['slug', $strategy.' '.$n.': names repeat'];
                }

                foreach ($names as $i => $name) {
                    if ($name === null) {
                        $v[] = ['slug', 'no name'];

                        continue;
                    }

                    foreach (self::nameViolations($name, $i, $names[0], $brandSlug, $brandWords, $type, $raw, $bare, $marked, $g['type']) as [$rule, $detail]) {
                        $v[] = [$rule, $name.' — '.$detail];
                    }
                }
            }

            $template = $strategy === ImageNamer::STRATEGY_NUMBERED ? 'numbered' : 'variations';

            for ($n = 1; $n <= 8; $n++) {
                $alts = AltText::propose($brand, $title, $category, $n, $template);

                if (count(array_unique(array_map('mb_strtolower', $alts))) !== $n) {
                    $v[] = ['alt-distinct', $template.' '.$n.': '.implode(' | ', $alts)];
                }

                foreach ($alts as $alt) {
                    foreach (self::altViolations($alt, (string) $brand, $title, $isSet) as $rule) {
                        $v[] = [$rule, '"'.$alt.'"'];
                    }
                }
            }
        }

        return $v;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function nameViolations(string $name, int $i, ?string $first, string $brandSlug, array $brandWords, string $type, array $raw, array $bare, array $marked, array $typeWords): array
    {
        $v = [];
        $words = explode('-', $name);

        // ── slug, length, word count (the joining "by" and a position suffix are not words of the name)
        $content = $words;
        $suffixed = $first !== null && $i > 0 && str_starts_with($name, $first.'-') && ctype_digit(substr($name, strlen($first) + 1));

        if ($suffixed) {
            array_pop($content);
        }

        $byAt = array_search('by', $content, true);

        if ($byAt !== false && ! in_array('by', $brandWords, true)) {
            unset($content[$byAt]);
        }

        if (! ImageNamer::valid($name) || strlen($name) > ImageNamer::MAX_LENGTH || count($content) > ImageNamer::MAX_WORDS) {
            $v[] = ['slug', strlen($name).' chars, '.count($content).' words'];
        }

        // ── the brand, exactly once
        if ($brandSlug !== '') {
            $hits = preg_match_all('/(?<=^|-)'.preg_quote($brandSlug, '/').'(?=-|$)/', $name);

            if ($hits !== 1) {
                $v[] = ['brand-once', 'brand "'.$brandSlug.'" '.$hits.' times'];
            }
        }

        // ── the type phrase, contiguous
        if ($type !== '' && preg_match('/(?<=^|-)'.preg_quote($type, '/').'(?=-|$)/', $name) !== 1) {
            $v[] = ['type-run', 'type "'.$type.'" not contiguous'];
        }

        // ── no dangling number
        foreach ($words as $p => $w) {
            if (! ctype_digit($w) || ($suffixed && $p === count($words) - 1)) {
                continue;
            }

            $mine = array_values(array_filter([$words[$p - 1] ?? null, $words[$p + 1] ?? null], 'is_string'));
            $theirs = array_merge(self::neighbours($raw, $w), self::neighbours($bare, $w));

            if (($words[$p + 1] ?? null) === 'pack' || array_intersect($mine, $theirs) !== []) {
                continue;
            }

            $v[] = ['dangling-number', '"'.$w.'" lost the word it belongs to'];
        }

        // ── a number that IS the name is never dropped while a later word of the name is kept
        foreach ($marked as $at => $d) {
            if (! ctype_digit($d) || in_array($d, $words, true)) {
                continue;
            }

            $later = array_intersect(
                array_diff(array_slice($marked, $at + 1), $typeWords, $brandWords, self::NOISE, ['packsize']),
                $content,
            );
            $later = array_filter($later, static fn ($w) => ! ctype_digit($w));

            if ($later !== []) {
                $v[] = ['name-number', '"'.$d.'" dropped but "'.implode(' ', $later).'" after it kept'];
            }
        }

        // ── nothing a filename says nothing with: PA ratings, "new", fl oz conversions
        if (array_intersect($words, ['pa', 'new', 'version', 'fl', 'tm']) !== []) {
            $v[] = ['noise', implode(' ', array_intersect($words, ['pa', 'new', 'version', 'fl', 'tm']))];
        }

        // ── "by" never at an edge (unless it is the brand's own first word)
        $brandLeads = $brandSlug !== '' && str_starts_with($name, $brandSlug);

        if (($words[0] === 'by' && ! ($brandLeads && $brandWords[0] === 'by')) || end($words) === 'by') {
            $v[] = ['by-edge', '"by" at an edge'];
        }

        // ── identifying words before the pack size
        $hasPack = array_intersect($words, ['fl', 'oz']) !== [];

        foreach ($words as $p => $w) {
            if (preg_match(self::PACK_WORD, $w) === 1
                || (ctype_digit($w) && preg_match('/^(?:'.self::COUNT_WORDS.')$/', $words[$p + 1] ?? '') === 1)) {
                $hasPack = true;
            }
        }

        if ($hasPack) {
            $lost = array_diff(self::identity($raw, $brandWords), $words);

            if ($lost !== []) {
                $v[] = ['identity-first', 'kept its pack size but lost "'.implode(' ', $lost).'"'];
            }
        }

        // ── no word twice (a number only as often as the title has it)
        $titleCounts = array_count_values($raw);

        foreach (array_count_values($content) as $w => $c) {
            $allowed = ctype_digit((string) $w) ? max(1, $titleCounts[(string) $w] ?? 0) : 1;

            if ($c > $allowed) {
                $v[] = ['repeat', '"'.$w.'" ×'.$c];
            }
        }

        return $v;
    }

    /** @return list<string> */
    private static function altViolations(string $alt, string $brand, string $title, bool $isSet): array
    {
        $v = [];
        $length = mb_strlen($alt);

        if ($length < AltText::MIN || $length > AltText::MAX) {
            $v[] = 'alt-length';
        }

        if (preg_match('/\s{2,}|[-–—|:,\/]\s*[-–—|:,\/]|^[\s\-–—|:,\/+&]|[\s\-–—|:,\/]$|\(\s*\)|\[\s*\]/u', $alt) === 1) {
            $v[] = 'alt-separators';
        }

        if (trim($brand) !== '') {
            $compact = static fn (string $s): string => (string) preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii($s, 'en')));

            if (! str_contains($alt, trim($brand)) || substr_count($compact($alt), $compact($brand)) !== 1) {
                $v[] = 'alt-brand';
            }
        }

        if ($isSet && preg_match('/\b(set|kit|duo|trio|bundle|pack|collection)\b|1\+1|x\s?\d\b/i', $alt) !== 1) {
            $v[] = 'alt-set';
        }

        if (preg_match('/\p{Latin}/u', $title) === 1 && preg_match('/\p{Arabic}/u', $alt) === 1) {
            $v[] = 'alt-arabic';
        }

        return $v;
    }

    /**
     * The title's words, read independently of ImageNamer.
     *
     * @return list<string>
     */
    private static function titleTokens(string $title, bool $markPacks = false): array
    {
        $t = (string) preg_replace('/\p{Arabic}+/u', ' ', $title);
        $t = (string) preg_replace("/['’‘`´]/u", '', $t);
        $t = strtolower(Str::ascii($t, 'en'));
        $t = (string) preg_replace_callback('/\b(?:[a-z]\.){2,}[a-z]?\b/', static fn ($m) => str_replace('.', '', $m[0]), $t);
        $t = (string) preg_replace('/\bspf\s+(\d)/', 'spf$1', $t);

        if ($markPacks) {
            // Every pack size, multi-pack and conversion becomes one placeholder,
            // so the digits left over are the numbers that ARE the name.
            foreach ([
                '/\d+(?:[.,]\d+)?\s*fl\.?\s*oz\.?/',
                '/(?<![\d+])\d+\s*\+\s*\d+(?![\d+])/',
                '/\b\d+\s*[x×]\s*(?=\d)/',
                '/[x×]\s*\d+\b/',
                '/\b\d+(?:[.,]\d+)?\s*(?:ml|g|kg|mg|oz|l|'.self::COUNT_WORDS.')\b/',
                '/\b\d+\s*-?\s*packs?\b|\b(?:pack|set)\s+of\s+\d+/',
            ] as $pattern) {
                $t = (string) preg_replace($pattern, ' packsize ', $t);
            }
        }

        return preg_split('/[^a-z0-9]+/', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return list<string> */
    private static function neighbours(array $tokens, string $word): array
    {
        $out = [];

        foreach ($tokens as $i => $t) {
            if ($t === $word) {
                array_push($out, $tokens[$i - 1] ?? '', $tokens[$i + 1] ?? '');
            }
        }

        return array_values(array_filter($out, static fn (string $t): bool => $t !== ''));
    }

    /**
     * The words that say what the product is: everything but joining words,
     * noise, the brand, bare numbers and the pack size.
     *
     * @return list<string>
     */
    private static function identity(array $raw, array $brandWords): array
    {
        $out = [];

        foreach ($raw as $i => $w) {
            $afterNumber = $i > 0 && ctype_digit($raw[$i - 1]);

            if (in_array($w, self::STOP, true) || in_array($w, self::NOISE, true) || in_array($w, $brandWords, true) || $w === implode('', $brandWords)
                || ctype_digit($w) || preg_match(self::PACK_WORD, $w) === 1 || preg_match('/^x\d+$/', $w) === 1
                || ($afterNumber && preg_match('/^(?:ml|g|kg|mg|oz|l|'.self::COUNT_WORDS.')$/', $w) === 1)) {
                continue;
            }

            $out[] = $w;
        }

        return array_values(array_unique($out));
    }
}
