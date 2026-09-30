<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A CSS PARSER GOOD ENOUGH TO COMPARE TWO STYLESHEETS WITH. (Lane PLC)
 *
 * The product card is written twice — resources/css/kbb/kbb-grid-skins.css and
 * a second copy inside resources/css/kbb/kbb.css — and the shop loads both
 * sheets on three pages and only kbb.css on the rest. So a rule that is in one
 * copy and not the other is a rule whose effect DEPENDS ON WHICH PAGE IT IS,
 * and keeping the two honest needs something that can say which rules those
 * are.
 *
 * ── WHY IT PARSES RATHER THAN GREPS ─────────────────────────────────────────
 *
 * A line count says the files differ by 3,449 lines, which is true and useless:
 * kbb.css is the whole shop. A regex over whole files cannot tell
 * `.kbb-card{color:red}` inside a @media block from the same selector outside
 * one, and those are different rules with different effects. So this walks the
 * CSS with a brace-depth tokenizer, tracks the at-rule stack (@media/@supports),
 * and reports per (context, selector, declaration) rather than per line.
 *
 * Comments are stripped before parsing — kbb.css's comments quote CSS verbatim,
 * including selectors and whole declaration blocks, and a parser that read them
 * would invent rules that are not in the sheet. Quotes are tracked throughout,
 * because this repository ships 8 KB `url("data:image/svg+xml,...")` values
 * carrying slashes, asterisks, semicolons and braces of their own.
 *
 * Used by tests/Feature/GridSkinCopiesTest.php and by tools/plc-css-*.php,
 * which is the point: the guard and the survey read the sheets the same way.
 */
final class CssRules
{
    /**
     * Strip CSS comments without eating a `/*` inside a string.
     *
     * url(data:image/svg+xml,...) in this repo carries slashes and asterisks, so
     * the walk has to know when it is inside a quote.
     */
    public static function stripComments(string $css): string
    {
        $out = '';
        $n = strlen($css);
        $quote = '';

        for ($i = 0; $i < $n; $i++) {
            $c = $css[$i];

            if ($quote !== '') {
                $out .= $c;

                if ($c === '\\' && $i + 1 < $n) {
                    $out .= $css[++$i];

                    continue;
                }

                if ($c === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($c === '"' || $c === "'") {
                $quote = $c;
                $out .= $c;

                continue;
            }

            if ($c === '/' && $i + 1 < $n && $css[$i + 1] === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $n : $end + 1;
                // A comment is whitespace to CSS, and joining the two sides without
                // one would weld `a` to `b` in `a/* x */b`.
                $out .= ' ';

                continue;
            }

            $out .= $c;
        }

        return $out;
    }

    /**
     * Split a declaration block on top-level semicolons.
     *
     * Not explode(';'), because `background:url(data:...;base64,...)` and
     * `grid-template-areas:"a b"` both carry semicolons that are not separators.
     */
    public static function splitDeclarations(string $body): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;
        $quote = '';
        $n = strlen($body);

        for ($i = 0; $i < $n; $i++) {
            $c = $body[$i];

            if ($quote !== '') {
                $buf .= $c;

                if ($c === '\\' && $i + 1 < $n) {
                    $buf .= $body[++$i];

                    continue;
                }

                if ($c === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($c === '"' || $c === "'") {
                $quote = $c;
                $buf .= $c;

                continue;
            }

            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            }

            if ($c === ';' && $depth === 0) {
                $parts[] = $buf;
                $buf = '';

                continue;
            }

            $buf .= $c;
        }

        $parts[] = $buf;

        $out = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $colon = strpos($part, ':');

            if ($colon === false) {
                // A declaration with no colon is not one. Keep it verbatim so the
                // report can show it rather than dropping it silently.
                $out[] = ['?', $part, $part];

                continue;
            }

            $prop = strtolower(trim(substr($part, 0, $colon)));
            $value = trim(substr($part, $colon + 1));
            // One space between tokens, so a reflow of the source is not drift.
            $value = (string) preg_replace('/\s+/', ' ', $value);

            $out[] = [$prop, $value, $prop.':'.$value];
        }

        return $out;
    }

    /** Whitespace-normalise a selector, and sort its comma-separated parts. */
    public static function normalizeSelector(string $sel): string
    {
        $sel = trim((string) preg_replace('/\s+/', ' ', $sel));
        // `a , b` and `b,a` select the same elements; sorting makes them one key.
        $parts = array_map('trim', explode(',', $sel));
        $parts = array_filter($parts, static fn (string $p): bool => $p !== '');
        // Space around combinators is optional in CSS: `a > b` and `a>b` are one.
        $parts = array_map(static fn (string $p): string => (string) preg_replace('/\s*([>+~])\s*/', '$1', $p), $parts);
        sort($parts);

        return implode(',', $parts);
    }

    /**
     * Every rule in a sheet, as context => selector => list of declarations.
     *
     * @return list<array{context: string, selector: string, decls: list<array{0:string,1:string,2:string}>, order: int}>
     */
    public static function parse(string $css): array
    {
        $css = self::stripComments($css);
        $rules = [];
        $stack = [];
        $buf = '';
        $depth = 0;
        $quote = '';
        $n = strlen($css);
        $order = 0;

        for ($i = 0; $i < $n; $i++) {
            $c = $css[$i];

            if ($quote !== '') {
                $buf .= $c;

                if ($c === '\\' && $i + 1 < $n) {
                    $buf .= $css[++$i];

                    continue;
                }

                if ($c === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($c === '"' || $c === "'") {
                $quote = $c;
                $buf .= $c;

                continue;
            }

            if ($c === '{') {
                $prelude = trim((string) preg_replace('/\s+/', ' ', $buf));
                $buf = '';

                if (str_starts_with($prelude, '@')) {
                    // A nesting at-rule: @media, @supports, @layer. Its children
                    // are rules in their own right and inherit this context.
                    $stack[] = $prelude;
                    $depth++;

                    continue;
                }

                // An ordinary rule. Read its block to the matching close brace.
                $body = '';
                $inner = 1;
                $q2 = '';

                for ($j = $i + 1; $j < $n; $j++) {
                    $d = $css[$j];

                    if ($q2 !== '') {
                        $body .= $d;

                        if ($d === '\\' && $j + 1 < $n) {
                            $body .= $css[++$j];

                            continue;
                        }

                        if ($d === $q2) {
                            $q2 = '';
                        }

                        continue;
                    }

                    if ($d === '"' || $d === "'") {
                        $q2 = $d;
                        $body .= $d;

                        continue;
                    }

                    if ($d === '{') {
                        $inner++;
                    } elseif ($d === '}') {
                        $inner--;

                        if ($inner === 0) {
                            $i = $j;

                            break;
                        }
                    }

                    $body .= $d;
                }

                if ($prelude !== '') {
                    $rules[] = [
                        'context' => implode(' >> ', $stack),
                        'selector' => self::normalizeSelector($prelude),
                        'raw_selector' => $prelude,
                        'decls' => self::splitDeclarations($body),
                        'order' => $order++,
                    ];
                }

                continue;
            }

            if ($c === '}') {
                if ($stack !== []) {
                    array_pop($stack);
                    $depth--;
                }

                $buf = '';

                continue;
            }

            if ($c === ';' && $stack === [] && trim($buf) !== '' && str_starts_with(trim($buf), '@')) {
                // @import / @charset: no block.
                $buf = '';

                continue;
            }

            $buf .= $c;
        }

        return $rules;
    }

    /** context|selector|property => list of values declared for it. */
    public static function declarationIndex(array $rules): array
    {
        $index = [];

        foreach ($rules as $rule) {
            foreach ($rule['decls'] as [$prop, $value, $whole]) {
                $key = $rule['context'].'|'.$rule['selector'].'|'.$prop;
                $index[$key][] = [
                    'value' => $value,
                    'context' => $rule['context'],
                    'selector' => $rule['selector'],
                    'prop' => $prop,
                    'order' => $rule['order'],
                ];
            }
        }

        return $index;
    }
}
