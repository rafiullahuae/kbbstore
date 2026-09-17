<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A small CSS declaration reader, used by RtlReadinessTest and by the script
 * that regenerates docs/rtl-audit.md.
 *
 * It exists because the T6 rewrite has to be checkable, and the only honest way
 * to check "no physical direction property came back" is to read the
 * stylesheets the same way a browser does — per declaration, inside a rule,
 * with comments and strings skipped — rather than to grep for a substring.
 * `margin-left` appears inside `margin-left:0` and inside a comment explaining
 * why a rule keeps `margin-left`, and a grep cannot tell those apart.
 *
 * This is deliberately NOT a general CSS parser. It handles exactly what these
 * stylesheets contain: nested at-rules, comments, quoted strings (including the
 * multi-kilobyte `data:image/svg+xml` custom properties in kbb.css) and
 * declarations with or without a trailing semicolon.
 */
final class CssDirection
{
    /**
     * Physical direction properties and the logical property each one becomes.
     *
     * `float: inline-start|inline-end` is deliberately absent: Chrome only
     * shipped it in 118 (October 2023), which is a browser-support floor this
     * store has no reason to take on. The only float in the storefront
     * stylesheets is `float:none`, which carries no direction anyway.
     */
    public const MAP = [
        'margin-left' => 'margin-inline-start',
        'margin-right' => 'margin-inline-end',
        'padding-left' => 'padding-inline-start',
        'padding-right' => 'padding-inline-end',
        'border-left' => 'border-inline-start',
        'border-right' => 'border-inline-end',
        'border-left-color' => 'border-inline-start-color',
        'border-right-color' => 'border-inline-end-color',
        'border-left-width' => 'border-inline-start-width',
        'border-right-width' => 'border-inline-end-width',
        'border-left-style' => 'border-inline-start-style',
        'border-right-style' => 'border-inline-end-style',
        'left' => 'inset-inline-start',
        'right' => 'inset-inline-end',
        'border-top-left-radius' => 'border-start-start-radius',
        'border-top-right-radius' => 'border-start-end-radius',
        'border-bottom-left-radius' => 'border-end-start-radius',
        'border-bottom-right-radius' => 'border-end-end-radius',
    ];

    /** The logical properties the rewrite introduced, counted by the floors check. */
    public const LOGICAL = [
        'margin-inline-start', 'margin-inline-end',
        'padding-inline-start', 'padding-inline-end',
        'border-inline-start', 'border-inline-end',
        'border-inline-start-color', 'border-inline-end-color',
        'border-inline-start-width', 'border-inline-end-width',
        'border-inline-start-style', 'border-inline-end-style',
        'inset-inline-start', 'inset-inline-end',
        'border-start-start-radius', 'border-start-end-radius',
        'border-end-start-radius', 'border-end-end-radius',
    ];

    /**
     * The files T6 converted. Scoped to what ships to a shopper: the storefront
     * stylesheets and the storefront views that carry an inline <style> block.
     *
     * The admin panel, the printable invoice and the email layouts are audited
     * in docs/rtl-audit.md and deliberately left physical — see that document
     * for the reason in each case. Adding one of them here without converting
     * it will fail this guard, which is the intended way to notice.
     */
    public const SCOPE = [
        'resources/css/kbb/kbb.css',
        'resources/css/kbb/kbb-shop.css',
        'resources/css/kbb/kbb-product.css',
        'resources/css/kbb/kbb-cart.css',
        'resources/css/kbb/kbb-checkout.css',
        'resources/css/kbb/kbb-account.css',
        'resources/css/kbb/kbb-banner.css',
        'resources/css/kbb/kbb-grid-skins.css',
        'resources/css/kbb/sorina-reviews.css',
        'resources/views/layouts/store.blade.php',
        'resources/views/store/app.blade.php',
        'resources/views/store/skin-quiz.blade.php',
        'resources/views/store/blog.blade.php',
        'resources/views/store/post.blade.php',
        'resources/views/store/review-wall.blade.php',
        'resources/views/store/checkout.blade.php',
        'resources/views/store/checkout-success.blade.php',
        'resources/views/store/account/order-detail.blade.php',
        'resources/views/store/account/track.blade.php',
    ];

    /** Blank out comment bodies, keeping newlines so offsets and lines survive. */
    private static function maskComments(string $s): string
    {
        $out = $s;
        $i = 0;
        $n = strlen($s);

        while ($i < $n) {
            if ($s[$i] === '/' && $i + 1 < $n && $s[$i + 1] === '*') {
                $end = strpos($s, '*/', $i + 2);
                $end = $end === false ? $n : $end + 2;
                for ($k = $i; $k < $end; $k++) {
                    if ($out[$k] !== "\n") {
                        $out[$k] = ' ';
                    }
                }
                $i = $end;

                continue;
            }
            $i++;
        }

        return $out;
    }

    private static function squash(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    /**
     * Every declaration in a CSS source, as
     * ['selector' => …, 'property' => …, 'value' => …, 'line' => int].
     *
     * The selector is the whole nesting stack joined by a space, so a rule
     * inside `@media(max-width:900px)` is distinguishable from the same
     * selector outside it.
     *
     * @return list<array{selector: string, property: string, value: string, line: int}>
     */
    public static function declarations(string $css, int $lineOffset = 0): array
    {
        $src = self::maskComments($css);
        $n = strlen($src);
        $stack = [];
        $out = [];
        $start = 0;
        $i = 0;

        $emit = function (int $from, int $to) use (&$out, &$stack, $src, $lineOffset): void {
            if ($stack === []) {
                return;
            }
            $seg = substr($src, $from, $to - $from);
            if (! preg_match('/^(\s*)([-\w]+)\s*:\s*/', $seg, $m)) {
                return;
            }
            $propStart = $from + strlen($m[1]);
            $out[] = [
                'selector' => self::squash(implode(' ', $stack)),
                'property' => strtolower($m[2]),
                'value' => self::squash(substr($seg, strlen($m[0]))),
                'line' => substr_count($src, "\n", 0, $propStart) + 1 + $lineOffset,
            ];
        };

        while ($i < $n) {
            $c = $src[$i];

            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                while ($j < $n) {
                    if ($src[$j] === '\\') {
                        $j += 2;

                        continue;
                    }
                    if ($src[$j] === $c) {
                        break;
                    }
                    $j++;
                }
                $i = $j + 1;

                continue;
            }

            if ($c === '{') {
                $stack[] = self::squash(substr($src, $start, $i - $start));
                $start = $i + 1;
                $i++;

                continue;
            }

            if ($c === '}') {
                $emit($start, $i);
                array_pop($stack);
                $start = $i + 1;
                $i++;

                continue;
            }

            if ($c === ';') {
                $emit($start, $i);
                $start = $i + 1;
                $i++;

                continue;
            }

            $i++;
        }

        return $out;
    }

    /**
     * The same, for a file that may be a Blade view: only the contents of its
     * <style> blocks are read.
     *
     * @return list<array{selector: string, property: string, value: string, line: int}>
     */
    public static function declarationsInFile(string $absolutePath): array
    {
        $text = (string) file_get_contents($absolutePath);

        if (str_ends_with($absolutePath, '.css')) {
            return self::declarations($text);
        }

        $out = [];
        if (preg_match_all('#<style[^>]*>(.*?)</style>#is', $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($m as $block) {
                [$body, $offset] = $block[1];
                $out = array_merge($out, self::declarations($body, substr_count($text, "\n", 0, $offset)));
            }
        }

        return $out;
    }

    /**
     * Is this declaration one that states a physical direction?
     *
     * `text-align:center` and `float:none` are not: they carry no side. A
     * `text-align` of `left` or `right` is, and becomes `start` / `end`.
     */
    public static function isPhysical(string $property, string $value): bool
    {
        if ($property === 'text-align') {
            $first = strtolower(strtok($value, ' ') ?: '');

            return $first === 'left' || $first === 'right';
        }

        return isset(self::MAP[$property]);
    }

    /**
     * Physical direction declarations still present in a file, keyed by
     * "relative path | selector | property | value" so that the key survives
     * an edit elsewhere in the file (a line number would not).
     *
     * @return array<string, array{file: string, selector: string, property: string, value: string}>
     */
    public static function physicalIn(string $basePath, string $relative): array
    {
        $rows = [];

        foreach (self::declarationsInFile($basePath.'/'.$relative) as $d) {
            if (! self::isPhysical($d['property'], $d['value'])) {
                continue;
            }
            $key = $relative.' | '.$d['selector'].' | '.$d['property'].' | '.$d['value'];
            $rows[$key] = [
                'file' => $relative,
                'selector' => $d['selector'],
                'property' => $d['property'],
                'value' => $d['value'],
            ];
        }

        return $rows;
    }

    /** How many logical direction declarations a file now carries. */
    public static function logicalCount(string $basePath, string $relative): int
    {
        $n = 0;

        foreach (self::declarationsInFile($basePath.'/'.$relative) as $d) {
            if (in_array($d['property'], self::LOGICAL, true)) {
                $n++;
            }
            if ($d['property'] === 'text-align') {
                $first = strtolower(strtok($d['value'], ' ') ?: '');
                if ($first === 'start' || $first === 'end') {
                    $n++;
                }
            }
        }

        return $n;
    }

    /** Total declarations a file parses to — used to prove the reader is not silently reading nothing. */
    public static function declarationCount(string $basePath, string $relative): int
    {
        return count(self::declarationsInFile($basePath.'/'.$relative));
    }

    /**
     * Rows of a pipe table in docs/rtl-audit.md, between the given HTML anchor
     * comments. Returns each row as a list of trimmed cells.
     *
     * @return list<list<string>>
     */
    public static function markdownTable(string $markdown, string $anchor): array
    {
        $open = '<!-- '.$anchor.':begin -->';
        $close = '<!-- '.$anchor.':end -->';
        $from = strpos($markdown, $open);
        $to = strpos($markdown, $close);

        if ($from === false || $to === false || $to < $from) {
            return [];
        }

        $slice = substr($markdown, $from + strlen($open), $to - $from - strlen($open));
        $rows = [];

        foreach (preg_split('/\R/', $slice) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '|') {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            // Skip the header row and the |---|---| rule under it.
            if ($cells === [] || strtolower($cells[0]) === 'file' || preg_match('/^:?-{3,}:?$/', $cells[0]) === 1) {
                continue;
            }
            $rows[] = $cells;
        }

        return $rows;
    }
}
