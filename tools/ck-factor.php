<?php

declare(strict_types=1);

/*
 * Lane CK (22 October 2026): put the Overall · Text % / Spacing % factors into
 * a stylesheet, mechanically, so no declaration is missed and none is touched
 * by hand.
 *
 *   php tools/ck-factor.php <file> <textVar> <spaceVar> <includeRegex> [--twin] [--check]
 *
 *   --twin  leaves every original rule byte for byte as it is and adds, right
 *           after it, a rule with the same selector carrying the converted
 *           declarations (see the note in the body). This is how it was run.
 *
 * For every rule whose selector matches <includeRegex> and does NOT touch the
 * header or the logo (HeaderLayoutLockTest's own pattern, plus the checkout's
 * own secure bar), it rewrites
 *
 *   font-size: 12px                -> calc(12px*var(--T,1))
 *   font-size: calc(X)             -> calc((X)*var(--T,1))
 *   font-size: var(--x,13px)       -> calc(var(--x,13px)*var(--T,1))
 *   font-size: max(16px, calc(X))  -> max(16px, calc((X)*var(--T,1)))   (the iOS floor stays outside)
 *   padding/margin (top and bottom), gap (its row gap): each px / calc() / var() token -> the same times var(--S,1)
 *
 * Every factor has a fallback of 1, so with nothing set the computed value is
 * the value the declaration had: calc(12px*1) is 12px. em, %, vw, clamp(),
 * min(), auto, 0 and every other declaration are left exactly as they are.
 * Custom properties (--x:...) and @keyframes are never touched.
 *
 * Idempotent: a declaration already carrying var(--T or var(--S is skipped.
 */

if ($argc < 5) {
    fwrite(STDERR, "usage: php tools/ck-factor.php <file> <textVar> <spaceVar> <includeRegex> [--check]\n");
    exit(2);
}

[$file, $T, $S, $include] = array_slice($argv, 1, 4);
$check = in_array('--check', $argv, true);
$GLOBALS['ckTwin'] = in_array('--twin', $argv, true);
$css = (string) file_get_contents($file);

// HeaderLayoutLockTest's pattern, and the checkout's own secure bar.
$header = '/(^|[\s,>+~(])(header\b|\.sbox\b|\.hin\b|\.hact\b|\.logo\b|\.lgx|\.kbbmi\b|\.ib\b|\.search-in\b|\.mnav-h\b|\.co-head\b|\.secure\b|\.co-cart\b)/';

$out = '';
$i = 0;
$n = strlen($css);
$stack = [];      // 'at' | 'kf' | 'rule'
$changed = 0;
$prelude = '';    // non-comment text since the last ; { } at block level

/*
 * VERTICAL SPACING ONLY. Horizontal padding is also what keeps a field's text
 * clear of its icon and lined up with its label (inset-inline-start), and those
 * offsets are positions, not spacing: scaling one and not the other walks the
 * text off its label. So Spacing % moves the space ABOVE and BELOW things --
 * padding/margin top and bottom, block longhands, row gaps -- and never a width
 * or a side offset. A shorthand keeps its side values exactly as written.
 */
$spacing = '/^(padding|margin|gap|padding-(top|bottom|block|block-start|block-end)|margin-(top|bottom|block|block-start|block-end)|row-gap)$/';

/** Split on $sep at paren depth 0, outside strings. */
$split = static function (string $s, string $sep): array {
    $parts = [];
    $depth = 0;
    $q = null;
    $cur = '';
    $len = strlen($s);
    for ($k = 0; $k < $len; $k++) {
        $c = $s[$k];
        if ($q !== null) {
            $cur .= $c;
            if ($c === '\\' && $k + 1 < $len) { $cur .= $s[++$k]; continue; }
            if ($c === $q) { $q = null; }
            continue;
        }
        if ($c === '"' || $c === "'") { $q = $c; $cur .= $c; continue; }
        if ($c === '(') { $depth++; }
        if ($c === ')') { $depth--; }
        if ($depth === 0 && ($sep === ' ' ? ctype_space($c) : $c === $sep)) {
            $parts[] = $cur;
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    $parts[] = $cur;

    return $parts;
};

$times = static fn (string $inner, string $var): string => 'calc('.$inner.'*var(--'.$var.',1))';

$fontValue = static function (string $v) use ($T, $times): ?string {
    if (preg_match('/^\d*\.?\d+px$/', $v)) {
        return $times($v, $T);
    }
    if (preg_match('/^calc\((.*)\)$/s', $v, $m)) {
        return $times('('.$m[1].')', $T);
    }
    if (preg_match('/^var\(--[\w-]+(,\s*\d*\.?\d+px)?\)$/', $v)) {
        return $times($v, $T);
    }
    if (preg_match('/^max\(\s*16px\s*,\s*calc\((.*)\)\s*\)$/s', $v, $m)) {
        return 'max(16px, '.$times('('.$m[1].')', $T).')';
    }

    return null;
};

$spaceToken = static function (string $tok) use ($S, $times): string {
    if (preg_match('/^-?\d*\.?\d+px$/', $tok) && (float) $tok != 0.0) {
        return $times($tok, $S);
    }
    if (preg_match('/^calc\((.*)\)$/s', $tok, $m)) {
        return $times('('.$m[1].')', $S);
    }
    if (preg_match('/^var\(--[\w-]+(,[^()]*)?\)$/', $tok)) {
        return $times($tok, $S);
    }

    return $tok;
};

$body = static function (string $b, bool $icon = false) use ($split, $spacing, $fontValue, $spaceToken, $T, $S, &$changed): array {
    $decls = $split($b, ';');
    foreach ($decls as $idx => $d) {
        if (! preg_match('/^(\s*)([a-zA-Z-]+)(\s*:\s*)(.*?)(\s*)$/s', $d, $m)) {
            continue;
        }
        [, $lead, $prop, $colon, $value, $trail] = $m;
        if (str_starts_with($prop, '--') || str_contains($value, 'var(--'.$T) || str_contains($value, 'var(--'.$S) || str_contains($value, '{{')) {
            continue;
        }
        $imp = '';
        if (preg_match('/^(.*?)(\s*!important)$/s', $value, $im)) {
            [$value, $imp] = [$im[1], $im[2]];
        }
        $new = null;
        if (strtolower($prop) === 'font-size') {
            if ($icon) { continue; } // an icon is sized by its own control, not by text (Lane TS "x" group)
            $new = $fontValue(trim($value));
        } elseif (preg_match($spacing, strtolower($prop))) {
            $toks = $split(trim($value), ' ');
            $toks = array_values(array_filter($toks, static fn ($t) => $t !== ''));
            $lp = strtolower($prop);
            if ($lp === 'padding' || $lp === 'margin') {
                // top right bottom left: only the 1st and 3rd are vertical; a
                // one-value shorthand is split into vertical + horizontal.
                if (count($toks) === 1) {
                    $toks = [$toks[0], $toks[0]];
                }
                $mapped = $toks;
                foreach ($toks as $k => $t) {
                    if ($k === 0 || $k === 2) {
                        $mapped[$k] = $spaceToken($t);
                    }
                }
            } elseif ($lp === 'gap') {
                // row-gap column-gap: only the row gap is vertical.
                if (count($toks) === 1) {
                    $toks = [$toks[0], $toks[0]];
                }
                $mapped = $toks;
                $mapped[0] = $spaceToken($toks[0]);
            } else {
                $mapped = array_map($spaceToken, $toks);
            }
            $new = $mapped === $toks ? null : implode(' ', $mapped);
        }
        if ($new !== null) {
            $decls[$idx] = $lead.$prop.$colon.$new.$imp.$trail;
            $changed++;
            $first ??= $idx;
        }
    }

    if ($GLOBALS['ckTwin']) {
        /*
         * TWIN MODE: the original rule is left byte for byte as it was, and a
         * second rule with the same selector follows it at once, carrying the
         * declarations from the first converted one to the end of the rule
         * (converted ones replaced). Same selector, same at-rule, immediately
         * after: it overrides exactly what the converted original would have,
         * keeps the order of shorthands and longhands inside the rule, and
         * changes nothing relative to any other rule.
         */
        if (! isset($first)) {
            return [$b, null];
        }

        return [$b, trim(implode(';', array_slice($decls, $first)))];
    }

    return [implode(';', $decls), null];
};

$convert = function (string $css) use ($header, $include, $body): string {
$out = '';
$i = 0;
$n = strlen($css);
$stack = [];
$prelude = '';
while ($i < $n) {
    // comments
    if ($css[$i] === '/' && $i + 1 < $n && $css[$i + 1] === '*') {
        $end = strpos($css, '*/', $i + 2);
        $end = $end === false ? $n : $end + 2;
        $out .= substr($css, $i, $end - $i);
        $i = $end;
        continue;
    }
    $c = $css[$i];
    if ($c === '{') {
        $selector = trim(preg_replace('/\s+/', ' ', $prelude));
        $prelude = '';
        if (str_starts_with($selector, '@')) {
            $stack[] = preg_match('/^@(-webkit-)?keyframes/', $selector) ? 'kf' : 'at';
            $out .= $c;
            $i++;
            continue;
        }
        // a rule: its closing brace, skipping strings and comments
        $close = $i + 1;
        $q = null;
        while ($close < $n) {
            $ch = $css[$close];
            if ($q !== null) { if ($ch === $q) { $q = null; } $close++; continue; }
            if ($ch === '/' && $close + 1 < $n && $css[$close + 1] === '*') {
                $e = strpos($css, '*/', $close + 2);
                $close = $e === false ? $n : $e + 2;
                continue;
            }
            if ($ch === '"' || $ch === "'") { $q = $ch; $close++; continue; }
            if ($ch === '}' || $ch === '{') { break; }
            $close++;
        }
        if ($close >= $n || $css[$close] === '{') {
            fwrite(STDERR, "unexpected nested block or end of file after: {$selector}\n");
            exit(3);
        }
        $inner = substr($css, $i + 1, $close - $i - 1);
        $skip = in_array('kf', $stack, true)
            || preg_match($header, $selector)
            || ! preg_match($include, $selector);
        if ($skip) {
            $out .= '{'.$inner.'}';
        } else {
            [$main, $twin] = $body($inner, (bool) preg_match('/\.(cth|co-rm|kc-x|kc-rm|kc-th|pay-logo|kc-wt)\b/', $selector));
            $out .= '{'.$main.'}'.($twin === null ? '' : $selector.'{'.$twin.'}');
        }
        $i = $close + 1;
        continue;
    }
    if ($c === '}') {
        array_pop($stack);
        $prelude = '';
    } elseif ($c === ';') {
        $prelude = '';
    } else {
        $prelude .= $c;
    }
    $out .= $c;
    $i++;
}

return $out;
};

if (str_ends_with($file, '.blade.php')) {
    // A Blade view: only its <style> blocks, with every Blade echo masked so
    // a `{{ }}` inside a media query is not read as a CSS block.
    $out = preg_replace_callback('#(<style[^>]*>)(.*?)(</style>)#s', function (array $m) use ($convert): string {
        $masks = [];
        $css = preg_replace_callback('/\{\{.*?\}\}|\{!!.*?!!\}/s', function (array $e) use (&$masks): string {
            $masks[] = $e[0];

            return 'KBBMASK'.(count($masks) - 1).'Z';
        }, $m[2]);
        $css = $convert($css);
        $css = preg_replace_callback('/KBBMASK(\d+)Z/', fn (array $k) => $masks[(int) $k[1]], $css);

        return $m[1].$css.$m[3];
    }, $css);
} else {
    $out = $convert($css);
}

if ($check) {
    echo $changed." declarations would change\n";
    exit(0);
}

file_put_contents($file, $out);
echo $changed." declarations changed in ".$file."\n";
