<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The LINES a rich-text body draws, and putting a translation's blank lines
 * back where its English has them.                       (Lane ARDX, 14 Nov 2026)
 *
 * ── THE DEFECT ─────────────────────────────────────────────────────────────
 *
 * The owner, 14 November 2026, with screenshots of /ar/product/medicube-vanilla-
 * deodrant-and-body-mist-duo/: "in arabic version, the spacing and line breaking
 * spacing has issues ... fix this permanently". The English description carries
 * a one-line blank (`<div><br></div>`, `<p>&nbsp;</p>`, drawn as
 * `<div class="kbb-bl"></div>` by RichText::markBlankLines()) after each list
 * and between its two one-item lists; the Arabic, hand-translated from the
 * 16 October copy before the English was re-spaced, had one two-item list and
 * no blank anywhere, so every heading sat right under the bullets above it.
 * docs/plans/arabic-description-spacing/AUDIT.md has the catalogue-wide count.
 *
 * ── WHAT THIS DOES ─────────────────────────────────────────────────────────
 *
 * lines() reads a body the way the browser lays it out, as a list of lines:
 * P (a line of words or a picture), B (a blank line, in any of the forms this
 * shop's copy stores one), L (a list, with its items), X (a table), R (a rule).
 *
 * align($english, $translation) compares the two line lists with the blank lines
 * taken out. When the words-and-lists sequence is the SAME SHAPE -- same order
 * of P / L / X / R, a list group allowed to be one list in the translation
 * where the English has several with the same number of items in all -- it
 * makes the translation's blank lines match the English gap for gap (puts the
 * missing ones back, takes extra ones out) and splits a merged list where the
 * English has two. Otherwise it changes NOTHING and says why: a body it cannot
 * line up with certainty is left for a person.
 *
 * NO WORD CHANGES, and that is checked, not hoped: every edit is a splice of a
 * constant (`<div class="kbb-bl"></div>`, `<br>`, a list's own close and open
 * tags) or the removal of an element that holds no words, and the result's
 * text with all whitespace removed must equal the input's or the input comes
 * back unchanged. Every other byte of the translation stays where it was.
 *
 * Pure string work (one regex lexer, no DOM): no query, no setting, a few tens
 * of microseconds on a description, so the product page can call it at render
 * time (ProductTabs) and TranslationStore::put() on every write.
 */
final class BlockStructure
{
    /** The one-line blank this shop draws (RichText::markBlankLines()). */
    public const BLANK = '<div class="kbb-bl"></div>';

    private const BLOCKS = [
        'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'section',
        'article', 'aside', 'figure', 'figcaption', 'header', 'footer', 'pre',
        'dl', 'dt', 'dd', 'main', 'nav', 'address', 'center',
    ];

    private const HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    private const BOLD = ['b', 'strong'];

    private const VOID = ['br', 'img', 'hr', 'wbr', 'source', 'input', 'meta', 'link', 'col', 'embed', 'area', 'track'];

    /** Elements a `<div>` must not be dropped into; a `<br>` is the blank there. */
    private const INLINE_CONTEXT = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'b', 'strong', 'span', 'a', 'em', 'i', 'u', 'font', 'small', 'label', 'li', 'dt', 'dd'];

    /**
     * @return list<array{kind: string, start: int, end: int, ctx?: bool, rm?: array{0: int, 1: int}|null, items?: list<array{0: int, 1: int}>, tag?: string, open?: string}>
     */
    public static function lines(string $html): array
    {
        preg_match_all('/<!--.*?-->|<\/?[a-zA-Z][^>]*>|[^<]+|</s', $html, $m, PREG_OFFSET_CAPTURE);

        $lines = [];
        $stack = [];          // [name, offset, lineCountAtOpen, kbbBl]
        $bold = 0;
        $list = null;         // the top-level list being read
        $table = null;        // [depth, start]
        $run = self::emptyRun();

        $context = static function () use (&$stack): bool {
            foreach ($stack as $s) {
                if (in_array($s[0], self::INLINE_CONTEXT, true)) {
                    return true;
                }
            }

            return false;
        };

        $flush = static function (?array $blank = null) use (&$run, &$lines, $context): void {
            if ($run['vis']) {
                $lines[] = ['kind' => 'P', 'start' => $run['start'], 'end' => $run['end'], 'bold' => $run['bold']];
            } elseif ($blank !== null) {
                $lines[] = ['kind' => 'B', 'start' => $blank[0], 'end' => $blank[1], 'rm' => $blank[2] ?? null, 'ctx' => $context()];
            }
            $run = self::emptyRun();
        };

        foreach ($m[0] as [$raw, $at]) {
            $at = (int) $at;
            $len = strlen($raw);

            if (str_starts_with($raw, '<!--')) {
                continue;
            }

            if ($raw[0] !== '<' || $raw === '<' || ! preg_match('/^<(\/?)([a-zA-Z][a-zA-Z0-9]*)/', $raw, $tm)) {
                // Words.
                if ($list !== null || $table !== null) {
                    continue;
                }
                $text = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (preg_match('/[^\s\x{00A0}\x{200B}-\x{200F}\x{2066}-\x{2069}\x{FEFF}]/u', $text) === 1) {
                    if (! $run['vis']) {
                        $run['start'] = $at;
                        $run['bold'] = true;
                    }
                    $run['vis'] = true;
                    $run['end'] = $at + $len;
                    if ($bold === 0) {
                        $run['bold'] = false;
                    }
                } elseif (preg_match('/\x{00A0}/u', $text) === 1) {
                    $run['nbsp'] = true;
                }
                continue;
            }

            $closing = $tm[1] === '/';
            $name = strtolower($tm[2]);

            // Inside a table: only its own nesting matters.
            if ($table !== null) {
                if ($name === 'table') {
                    $table[0] += $closing ? -1 : 1;
                    if ($table[0] === 0) {
                        $lines[] = ['kind' => 'X', 'start' => $table[1], 'end' => $at + $len];
                        $table = null;
                    }
                }
                continue;
            }

            // Inside a list: count its own items, nothing else draws a line.
            if ($list !== null) {
                if ($name === 'ul' || $name === 'ol') {
                    $list['depth'] += $closing ? -1 : 1;
                    if ($list['depth'] === 0) {
                        self::closeItem($list, $at);
                        $lines[] = ['kind' => 'L', 'start' => $list['start'], 'end' => $at + $len, 'items' => $list['items'], 'tag' => $list['tag'], 'open' => $list['open']];
                        $list = null;
                    }
                } elseif ($name === 'li' && $list['depth'] === 1) {
                    if ($closing) {
                        self::closeItem($list, $at + $len);
                    } else {
                        self::closeItem($list, $at);
                        $list['items'][] = [$at, -1];
                    }
                }
                continue;
            }

            if ($name === 'ul' || $name === 'ol') {
                if (! $closing) {
                    $flush();
                    $list = ['start' => $at, 'depth' => 1, 'items' => [], 'tag' => $name, 'open' => $raw];
                }
                continue;
            }

            if ($name === 'table') {
                if (! $closing) {
                    $flush();
                    $table = [1, $at];
                }
                continue;
            }

            if ($name === 'br') {
                if ($run['vis']) {
                    $flush();
                } else {
                    // A line break on an empty line IS a blank line.
                    $flush([$at, $at + $len, [$at, $at + $len]]);
                }
                continue;
            }

            if ($name === 'hr') {
                $flush();
                $lines[] = ['kind' => 'R', 'start' => $at, 'end' => $at + $len];
                continue;
            }

            if ($name === 'img') {
                if (! $run['vis']) {
                    $run['start'] = $at;
                }
                $run['vis'] = true;
                $run['bold'] = false;
                $run['end'] = $at + $len;
                continue;
            }

            if (in_array($name, self::VOID, true)) {
                continue;
            }

            $isBlock = in_array($name, self::BLOCKS, true);
            $boldish = in_array($name, self::BOLD, true) || in_array($name, self::HEADINGS, true);

            if (! $closing) {
                if ($isBlock) {
                    $flush();
                }
                $stack[] = [$name, $at, count($lines), (bool) preg_match('/\bclass\s*=\s*["\'][^"\']*\bkbb-bl\b/i', $raw)];
                if ($boldish) {
                    $bold++;
                }
                continue;
            }

            // A closing tag: find what it closes (operator HTML is often unbalanced).
            $found = null;
            for ($i = count($stack) - 1; $i >= 0; $i--) {
                if ($stack[$i][0] === $name) {
                    $found = $i;
                    break;
                }
            }
            if ($found === null) {
                continue;
            }

            $open = $stack[$found];

            if ($isBlock) {
                $emptyInside = count($lines) === $open[2] && ! $run['vis'];
                $range = $emptyInside ? [$open[1], $at + $len] : null;

                if ($open[3] && $emptyInside) {
                    $flush([$open[1], $at + $len, $range]);
                } elseif ($run['nbsp'] && ! $run['vis']) {
                    $flush([$open[1], $at + $len, $range]);
                } elseif ($name === 'p' && $emptyInside) {
                    // An empty paragraph is drawn as a blank line (markBlankLines()).
                    $flush([$open[1], $at + $len, $range]);
                } else {
                    $flush();
                }
            }

            for ($i = count($stack) - 1; $i >= $found; $i--) {
                if (in_array($stack[$i][0], self::BOLD, true) || in_array($stack[$i][0], self::HEADINGS, true)) {
                    $bold = max(0, $bold - 1);
                }
                array_pop($stack);
            }
        }

        if ($list !== null) {
            self::closeItem($list, strlen($html));
            $lines[] = ['kind' => 'L', 'start' => $list['start'], 'end' => strlen($html), 'items' => $list['items'], 'tag' => $list['tag'], 'open' => $list['open']];
        }
        if ($table !== null) {
            $lines[] = ['kind' => 'X', 'start' => $table[1], 'end' => strlen($html)];
        }
        $flush();

        return $lines;
    }

    /**
     * A short readable shape, e.g. "P B L3 B P L2". Blank edges are dropped (the
     * product page trims them, RichText::trimBlankEdges()).
     */
    public static function shape(string $html): string
    {
        $out = [];
        foreach (self::trimEdges(self::lines($html)) as $line) {
            $out[] = $line['kind'] === 'L' ? 'L'.count($line['items']) : $line['kind'];
        }

        return implode(' ', $out);
    }

    /**
     * Make $translation's blank lines and list boundaries match $english's.
     *
     * @return array{html: string, changed: bool, ok: bool, changes: list<string>, problem: ?string}
     *   ok      the two bodies line up (whether or not anything had to change)
     *   problem why they could not be lined up; html is then $translation, untouched
     */
    public static function align(string $english, string $translation): array
    {
        $same = ['html' => $translation, 'changed' => false, 'ok' => true, 'changes' => [], 'problem' => null];

        if ($english === $translation || trim($english) === '' || trim($translation) === '') {
            return $same;
        }

        $en = self::trimEdges(self::lines($english));
        $ar = self::trimEdges(self::lines($translation));

        $enGroups = self::groups($en);
        $arGroups = self::groups($ar);

        $fail = static fn (string $why): array => ['html' => $translation, 'changed' => false, 'ok' => false, 'changes' => [], 'problem' => $why];

        if (count($enGroups) !== count($arGroups)) {
            return $fail(self::countProblem($enGroups, $arGroups));
        }

        $edits = [];
        $changes = [];
        // Flattened, in order: for each English unit, where its Arabic twin starts
        // and ends, and the Arabic blank lines in the gap after it.
        $pairs = [];

        foreach ($enGroups as $g => $eg) {
            $ag = $arGroups[$g];
            if ($eg['kind'] !== $ag['kind']) {
                return $fail('line '.($g + 1).' is '.self::kindName($eg['kind']).' in English but '.self::kindName($ag['kind']).' in the translation');
            }

            if ($eg['kind'] !== 'L' || count($eg['units']) === count($ag['units'])) {
                foreach ($eg['units'] as $u => $unit) {
                    $pairs[] = ['en' => $unit, 'ar' => $ag['units'][$u], 'split' => null];
                }
                continue;
            }

            // A list group: the English has several lists where the translation
            // has one with the same number of items in all -> split it there.
            if (count($ag['units']) !== 1) {
                return $fail('the lists at line '.($g + 1).' are grouped differently ('.count($eg['units']).' in English, '.count($ag['units']).' in the translation)');
            }

            $want = array_map(static fn (array $l): int => count($l['items']), array_map(static fn (array $u): array => $u['line'], $eg['units']));
            $one = $ag['units'][0]['line'];

            if (array_sum($want) !== count($one['items'])) {
                return $fail('the English has '.count($want).' lists ('.implode(' + ', $want).' items) where the translation has one list of '.count($one['items']));
            }

            $close = '</'.$one['tag'].'>';
            $taken = 0;
            foreach ($eg['units'] as $u => $unit) {
                $taken += $want[$u];
                $last = $u === count($eg['units']) - 1;
                $pairs[] = [
                    'en' => $unit,
                    'ar' => $ag['units'][0],
                    // Where this piece of the Arabic list ends: the end of its last item.
                    'split' => $last ? null : $one['items'][$taken - 1][1],
                    'close' => $close,
                    'open' => $one['open'],
                ];
            }
            $changes[] = 'split a list of '.count($one['items']).' into '.implode(' + ', $want);
        }

        $lastGap = count($pairs) - 1;
        for ($i = 0; $i < $lastGap; $i++) {
            $want = $pairs[$i]['en']['blanksAfter'];

            if ($pairs[$i]['split'] !== null) {
                $at = $pairs[$i]['split'];
                $edits[] = [$at, 0, $pairs[$i]['close'].str_repeat(self::BLANK, count($want)).$pairs[$i]['open']];
                if (count($want) > 0) {
                    $changes[] = 'put back '.count($want).' blank line(s) between the split lists';
                }
                continue;
            }

            $have = $pairs[$i]['ar']['blanksAfter'];
            $next = $pairs[$i + 1]['ar'];

            if (count($want) > count($have)) {
                $missing = count($want) - count($have);
                $at = self::outerStart($translation, $next['line']['start']);
                $markup = self::inlineAt($translation, $at) ? '<br>' : self::BLANK;
                $edits[] = [$at, 0, str_repeat($markup, $missing)];
                $changes[] = 'put back '.$missing.' blank line(s) before line '.($i + 2);
            } elseif (count($want) < count($have)) {
                $extra = array_slice($have, count($want));
                foreach ($extra as $blank) {
                    if (($blank['rm'] ?? null) === null) {
                        return $fail('an extra blank line before line '.($i + 2).' is part of other markup and cannot be taken out safely');
                    }
                    $edits[] = [$blank['rm'][0], $blank['rm'][1] - $blank['rm'][0], ''];
                }
                $changes[] = 'took out '.count($extra).' extra blank line(s) before line '.($i + 2);
            }
        }

        if ($edits === []) {
            return $same;
        }

        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $out = $translation;
        $last = PHP_INT_MAX;
        foreach ($edits as [$at, $del, $ins]) {
            if ($at + $del > $last) {
                return $fail('two repairs overlap');
            }
            $out = substr($out, 0, $at).$ins.substr($out, $at + $del);
            $last = $at;
        }

        // THE GUARANTEE: not one word moved, and the shape now matches.
        if (self::words($out) !== self::words($translation)) {
            return $fail('a repair would have changed the words');
        }
        if (self::blankShape($out) !== self::blankShape($english)) {
            return $fail('the repaired copy still does not line up');
        }

        return ['html' => $out, 'changed' => true, 'ok' => true, 'changes' => $changes, 'problem' => null];
    }

    /**
     * For the page: the translation lined up with its English when that can be
     * done with certainty, else as it is.
     */
    public static function forDisplay(string $english, string $translation): string
    {
        if ($english === $translation || stripos($translation, '<') === false) {
            return $translation;
        }

        $aligned = self::align($english, $translation);

        return $aligned['ok'] ? $aligned['html'] : self::afterLists($english, $translation);
    }

    /**
     * The fallback for a translation that cannot be lined up (its English has a
     * different number of lines -- e.g. it was edited after the Arabic was
     * written): the 2.60.665 render-time fix, RichText::spaceAfterLists(), made
     * aware of every stored blank form and of the English's own style. When the
     * English puts a blank line after a list anywhere, a list in the translation
     * that is followed by more copy with no blank line gets one. An English body
     * that never does that is left alone, so its translation is too.
     */
    public static function afterLists(string $english, string $translation): string
    {
        $en = self::trimEdges(self::lines($english));
        $styled = false;
        foreach ($en as $i => $line) {
            if ($line['kind'] === 'L' && ($en[$i + 1]['kind'] ?? null) === 'B') {
                $styled = true;
                break;
            }
        }
        if (! $styled) {
            return $translation;
        }

        $ar = self::trimEdges(self::lines($translation));
        $out = $translation;
        // From the end, so earlier offsets stay true.
        for ($i = count($ar) - 2; $i >= 0; $i--) {
            if ($ar[$i]['kind'] === 'L' && $ar[$i + 1]['kind'] !== 'B') {
                $at = self::outerStart($translation, $ar[$i + 1]['start']);
                $out = substr($out, 0, $at).(self::inlineAt($translation, $at) ? '<br>' : self::BLANK).substr($out, $at);
            }
        }

        return self::words($out) === self::words($translation) ? $out : $translation;
    }

    /** The visible words, all whitespace removed: what "no word changed" compares. */
    public static function words(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/[\s\x{00A0}]+/u', '', $text);
    }

    /** Lines with list sizes ignored: what a successful repair must match. */
    private static function blankShape(string $html): string
    {
        $out = [];
        foreach (self::trimEdges(self::lines($html)) as $line) {
            $out[] = $line['kind'];
        }

        return implode(' ', $out);
    }

    /**
     * Units (non-blank lines) with the blank lines after each, then consecutive
     * lists folded into one group.
     *
     * @return list<array{kind: string, units: list<array{line: array, blanksAfter: list<array>}>}>
     */
    private static function groups(array $lines): array
    {
        $units = [];
        foreach ($lines as $line) {
            if ($line['kind'] === 'B') {
                if ($units !== []) {
                    $units[count($units) - 1]['blanksAfter'][] = $line;
                }
                continue;
            }
            $units[] = ['line' => $line, 'blanksAfter' => []];
        }

        $groups = [];
        foreach ($units as $unit) {
            $kind = $unit['line']['kind'];
            $n = count($groups);
            if ($kind === 'L' && $n > 0 && $groups[$n - 1]['kind'] === 'L') {
                $groups[$n - 1]['units'][] = $unit;
                continue;
            }
            $groups[] = ['kind' => $kind, 'units' => [$unit]];
        }

        return $groups;
    }

    private static function trimEdges(array $lines): array
    {
        while ($lines !== [] && $lines[0]['kind'] === 'B') {
            array_shift($lines);
        }
        while ($lines !== [] && $lines[count($lines) - 1]['kind'] === 'B') {
            array_pop($lines);
        }

        return $lines;
    }

    /** Back over the opening tags right before a line, so a blank goes outside them. */
    private static function outerStart(string $html, int $at): int
    {
        while (preg_match('/<([a-zA-Z][a-zA-Z0-9]*)\b[^<>]*>\s*$/', substr($html, max(0, $at - 400), $at - max(0, $at - 400)), $m) === 1) {
            if (in_array(strtolower($m[1]), self::VOID, true) || in_array(strtolower($m[1]), ['ul', 'ol', 'li', 'table'], true)) {
                break;
            }
            $at -= strlen($m[0]);
        }

        return $at;
    }

    /** Is $at inside a paragraph or an inline element? Then a `<br>` is the blank there, not a `<div>`. */
    private static function inlineAt(string $html, int $at): bool
    {
        preg_match_all('/<(\/?)([a-zA-Z][a-zA-Z0-9]*)[^>]*>/', substr($html, 0, $at), $m, PREG_SET_ORDER);
        $stack = [];
        foreach ($m as $t) {
            $name = strtolower($t[2]);
            if (in_array($name, self::VOID, true)) {
                continue;
            }
            if ($t[1] === '') {
                $stack[] = $name;
                continue;
            }
            $i = array_search($name, array_reverse($stack, true), true);
            if ($i !== false) {
                array_splice($stack, (int) $i);
            }
        }

        return array_intersect($stack, self::INLINE_CONTEXT) !== [];
    }

    private static function closeItem(array &$list, int $at): void
    {
        $n = count($list['items']);
        if ($n > 0 && $list['items'][$n - 1][1] === -1) {
            $list['items'][$n - 1][1] = $at;
        }
    }

    private static function emptyRun(): array
    {
        return ['vis' => false, 'start' => 0, 'end' => 0, 'bold' => true, 'nbsp' => false];
    }

    private static function countProblem(array $en, array $ar): string
    {
        $count = static fn (array $groups, string $kind): int => count(array_filter($groups, static fn (array $g): bool => $g['kind'] === $kind));

        return 'the English has '.count($en).' blocks ('.$count($en, 'P').' lines of text, '.$count($en, 'L').' list groups) and the translation '
            .count($ar).' ('.$count($ar, 'P').' lines of text, '.$count($ar, 'L').' list groups)';
    }

    private static function kindName(string $kind): string
    {
        return ['P' => 'a line of text', 'L' => 'a list', 'X' => 'a table', 'R' => 'a rule'][$kind] ?? $kind;
    }
}
