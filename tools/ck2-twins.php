<?php

declare(strict_types=1);

/*
 * Lane CK2 (23 October 2026): the text-size override table, generated ONCE at
 * build time from the stylesheets, never per request.
 *
 * Lane CK put a "twin" rule after every sized rule of the checkout, the cart
 * page and the cart panel (calc(Npx*var(--ckt,1)) and friends) and shipped all
 * of them in kbb.css / kbb-cart.css / kbb-checkout.css: +4.9 KB gzip on the
 * pages that never use them. Lane CK2 takes the twins OUT of the stylesheets and
 * keeps them here, as a PHP constant the server prints from -- only the twins a
 * moved setting needs, only on the pages that setting belongs to.
 *
 *   php tools/ck2-twins.php json <lane-ck-tree>                 -> the twins, as JSON (for the role scan)
 *   php tools/ck2-twins.php php  <lane-ck-tree> <roles.json>    -> app/Support/TextSizeTwins.php
 *
 * <lane-ck-tree> is a checkout of commit 0e27fb0f (the stylesheets with the
 * twins in place). A twin is a rule that follows a rule with the SAME selector
 * in the SAME at-rule context and carries var(--X,1) for one of the six factor
 * variables; only its factor-carrying declarations are kept.
 *
 * <roles.json> is tools/ck2-roles.cjs's output: which text role's container
 * each twin's elements sit in, measured in Chromium on the real pages. A twin
 * whose selector names a role container is in that role as well.
 */

const VARS = [
    'ckt' => ['checkout', 't'], 'cks' => ['checkout', 's'],
    'cgt' => ['cartpage', 't'], 'cgs' => ['cartpage', 's'],
    'kpt' => ['cartpanel', 't'], 'kps' => ['cartpanel', 's'],
];

const FILES = [
    'resources/css/kbb/kbb.css',
    'resources/css/kbb/kbb-cart.css',
    'resources/css/kbb/kbb-checkout.css',
    'resources/views/store/cart-squeeze.blade.php',
    'resources/views/store/cart-inner.blade.php',
];

/** Role => the selector text that names its container (for the text half of membership). */
const ROLE_TOKENS = [
    'checkout' => [
        'rows' => ['.sumrow', '.srow'], 'total' => ['.tot', '.cosr-tot'],
        'coupon' => ['.coupon', '.co-sumcoupon', '.co-oscoupon', '.co-cline', '.co-cmsg'],
        'help' => ['.co-ehint', '.co-dnote', '.co-psec', '.co-si-stack', '.co-note', '.kbb-placing-note', '.kbb-card-save', '.kbb-delivery-line', '.note', '.vat', '.backlink', '.conti', '.paylogos', '.optional', '.woocommerce-error', '.woocommerce-info', '.woocommerce-message'],
        'dlv' => ['.woocommerce-shipping-methods'], 'pay' => ['.wc_payment_methods', '.payment_box', '#payment'],
        'tick' => ['.kbb-acct-opt', '.kbb-gift-opt', 'label.checkbox'],
    ],
    'cartpage' => [
        'sumh' => ['aside.sum > h2', '.sum > h2', '.sum h2'], 'rows' => ['.srow'], 'total' => ['.tot', '.cpg-totband'],
        'coupon' => ['.coupon', '.cohint', '.co-tk'], 'help' => ['.note', '.ship', '.cpg-ship', '.paylogos', '.conti', '.free', '.backlink'],
    ],
    'cartpanel' => [
        'tabs' => ['.kc-tab'], 'sum' => ['.sumrow'], 'help' => ['.kc-ship', '.kc-note', '.kc-fdl', '.empty-d'], 'coupon' => ['.kc-coupon', '.kc-cch'],
    ],
];

/** Every rule of a stylesheet: [context, selector, body], in source order. */
function rules(string $css): array
{
    $out = [];
    $stack = [];
    $prelude = '';
    $n = strlen($css);
    $i = 0;

    while ($i < $n) {
        if ($css[$i] === '/' && $i + 1 < $n && $css[$i + 1] === '*') {
            $end = strpos($css, '*/', $i + 2);
            $i = $end === false ? $n : $end + 2;
            continue;
        }

        $c = $css[$i];

        if ($c === '{') {
            $sel = trim((string) preg_replace('/\s+/', ' ', $prelude));
            $prelude = '';

            if (str_starts_with($sel, '@')) {
                $stack[] = $sel;
                $i++;
                continue;
            }

            $close = $i + 1;
            $q = null;
            while ($close < $n) {
                $ch = $css[$close];
                if ($q !== null) { if ($ch === '\\') { $close += 2; continue; } if ($ch === $q) { $q = null; } $close++; continue; }
                if ($ch === '/' && $close + 1 < $n && $css[$close + 1] === '*') { $e = strpos($css, '*/', $close + 2); $close = $e === false ? $n : $e + 2; continue; }
                if ($ch === '"' || $ch === "'") { $q = $ch; $close++; continue; }
                if ($ch === '}') { break; }
                $close++;
            }

            $body = (string) preg_replace('#/\*.*?\*/#s', '', substr($css, $i + 1, $close - $i - 1));
            $out[] = [implode('', $stack), $sel, $body];
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
        $i++;
    }

    return $out;
}

/** Split on ; at paren depth 0, outside strings. */
function decls(string $b): array
{
    $parts = [];
    $depth = 0;
    $q = null;
    $cur = '';
    $len = strlen($b);
    for ($k = 0; $k < $len; $k++) {
        $c = $b[$k];
        if ($q !== null) { $cur .= $c; if ($c === '\\' && $k + 1 < $len) { $cur .= $b[++$k]; continue; } if ($c === $q) { $q = null; } continue; }
        if ($c === '"' || $c === "'") { $q = $c; $cur .= $c; continue; }
        if ($c === '(') { $depth++; }
        if ($c === ')') { $depth--; }
        if ($depth === 0 && $c === ';') { $parts[] = trim($cur); $cur = ''; continue; }
        $cur .= $c;
    }
    $parts[] = trim($cur);

    return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
}

/** Split a value on spaces at paren depth 0. */
function tokens(string $v): array
{
    $out = [];
    $depth = 0;
    $cur = '';
    $len = strlen($v);
    for ($k = 0; $k < $len; $k++) {
        $c = $v[$k];
        if ($c === '(') { $depth++; }
        if ($c === ')') { $depth--; }
        if ($depth === 0 && ctype_space($c)) { if ($cur !== '') { $out[] = $cur; } $cur = ''; continue; }
        $cur .= $c;
    }
    if ($cur !== '') { $out[] = $cur; }

    return $out;
}

/**
 * A converted declaration as the LONGHANDS it scales, and only those: a
 * padding/margin shorthand becomes its -top / -bottom, a gap its row-gap.
 * Printed late in the page, a shorthand would also re-set the sides it never
 * scaled and beat a later rule that sets them; a longhand cannot.
 *
 * @return list<array{0: string, 1: string}>
 */
function longhands(string $d): array
{
    if (! preg_match('/^([a-zA-Z-]+)\s*:\s*(.*)$/s', $d, $m)) {
        throw new RuntimeException("not a declaration: {$d}");
    }
    [, $prop, $value] = $m;
    $prop = strtolower($prop);
    $imp = '';
    if (preg_match('/^(.*?)\s*(!important)$/s', $value, $im)) {
        [$value, $imp] = [$im[1], '!important'];
    }
    $value = trim($value);
    $scaled = static fn (string $x): bool => (bool) preg_match('/var\(--(ckt|cks|cgt|cgs|kpt|kps),1\)/', $x);

    if ($prop === 'padding' || $prop === 'margin') {
        $tk = tokens($value);
        $top = $tk[0];
        $bottom = $tk[2] ?? $tk[0];
        $out = [];
        if ($scaled($top)) { $out[] = [$prop.'-top', $top.$imp]; }
        if ($scaled($bottom)) { $out[] = [$prop.'-bottom', $bottom.$imp]; }

        return $out;
    }
    if ($prop === 'gap') {
        $tk = tokens($value);

        return $scaled($tk[0]) ? [['row-gap', $tk[0].$imp]] : [];
    }

    return [[$prop, $value.$imp]];
}

/** Does any of these later declarations set $prop (itself or through a shorthand)? */
function overridden(string $prop, array $later): bool
{
    $covers = [
        'padding-top' => ['padding', 'padding-top', 'padding-block', 'padding-block-start'],
        'padding-bottom' => ['padding', 'padding-bottom', 'padding-block', 'padding-block-end'],
        'margin-top' => ['margin', 'margin-top', 'margin-block', 'margin-block-start'],
        'margin-bottom' => ['margin', 'margin-bottom', 'margin-block', 'margin-block-end'],
        'row-gap' => ['gap', 'row-gap'],
        'font-size' => ['font', 'font-size'],
    ][$prop] ?? [$prop];

    foreach ($later as $d) {
        if (preg_match('/^([a-zA-Z-]+)\s*:/', $d, $m) && in_array(strtolower($m[1]), $covers, true)) {
            return true;
        }
    }

    return false;
}

function sourceCss(string $tree, string $file): string
{
    $raw = (string) file_get_contents($tree.'/'.$file);

    if (! str_ends_with($file, '.blade.php')) {
        return $raw;
    }

    preg_match_all('#<style[^>]*>(.*?)</style>#s', $raw, $m);

    // A Blade echo inside a style block is masked, so it is never read as CSS.
    // An integer setting in a media query (the squeezed cart's desktop width)
    // becomes kbbint(key), filled from the saved value when the block is built
    // (TextSizes::wrap()); any other echo is masked.
    $css = (string) preg_replace('/\{\{\s*\(int\)\s*\$kbbCartPage->get\(\'(\w+)\'\)\s*\}\}/', 'kbbint($1)', implode("\n", $m[1]));

    return (string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', 'KBBMASK', $css);
}

/** @return list<array{surface: string, file: string, ctx: string, sel: string, t: string, s: string}> */
function twins(string $tree): array
{
    $out = [];

    foreach (FILES as $file) {
        $prev = null;

        foreach (rules(sourceCss($tree, $file)) as $rule) {
            [$ctx, $sel, $body] = $rule;
            $twinIndex = null;

            // Lane CK's one factor written into an existing rule rather than a
            // twin: kbb.css's touch-device 16px floor on the checkout, times
            // --ckt. It is a rule of its own, so it travels whole.
            if (str_contains($body, 'var(--ckt, 1)')) {
                $twinIndex = count($out);
                $out[] = ['surface' => 'checkout', 'file' => $file, 'ctx' => preg_replace('/\s+/', ' ', $ctx), 'sel' => $sel, 't' => trim((string) preg_replace('/\s+/', ' ', $body)), 's' => ''];
            } elseif ($prev !== null && $prev[0] === $ctx && $prev[1] === $sel && preg_match('/var\(--(ckt|cks|cgt|cgs|kpt|kps),1\)/', $body)) {
                $t = [];
                $s = [];
                $surface = null;
                $list = decls($body);

                foreach ($list as $idx => $d) {
                    if (! preg_match_all('/var\(--(ckt|cks|cgt|cgs|kpt|kps),1\)/', $d, $vm)) {
                        continue;
                    }
                    if (str_contains($d, 'KBBMASK')) {
                        throw new RuntimeException("a Blade echo inside a twin: {$sel}");
                    }
                    $v = $vm[1][0];
                    $surface ??= VARS[$v][0];
                    if (VARS[$v][0] !== $surface) {
                        throw new RuntimeException("two surfaces in one twin: {$sel}");
                    }
                    // The lifted label's own factor has no definition in the
                    // stylesheet any more: it is printed only when moved, so it
                    // needs its own fallback of 1 here.
                    $d = str_replace('var(--cop-tflift)', 'var(--cop-tflift,1)', (string) preg_replace('/\s+/', ' ', $d));

                    foreach (longhands($d) as [$prop, $value]) {
                        // A later declaration of the same rule that covers this
                        // property wins in the stylesheet, so it would win over
                        // the factor too: leave it out.
                        if (overridden($prop, array_slice($list, $idx + 1))) {
                            continue;
                        }
                        if (preg_match('/var\(--(ckt|cgt|kpt),1\)/', $value)) {
                            $t[] = $prop.':'.$value;
                        } elseif (preg_match('/var\(--(cks|cgs|kps),1\)/', $value)) {
                            $s[] = $prop.':'.$value;
                        }
                    }
                }

                if ($surface !== null && ($t !== [] || $s !== [])) {
                    $twinIndex = count($out);
                    $out[] = ['surface' => $surface, 'file' => $file, 'ctx' => preg_replace('/\s+/', ' ', $ctx), 'sel' => $sel, 't' => implode(';', $t), 's' => implode(';', $s)];
                }
            }

            // Every rule, in order, for the re-assertion pass (php mode).
            $isTwin = $prev !== null && $prev[0] === $ctx && $prev[1] === $sel && preg_match('/var\(--(ckt|cks|cgt|cgs|kpt|kps), ?1\)/', $body);
            $GLOBALS['ck2rules'][] = ['file' => $file, 'ctx' => (string) preg_replace('/\s+/', ' ', $ctx), 'sel' => $sel, 'body' => $body,
                'twin' => $twinIndex, 'isTwin' => (bool) $isTwin || $twinIndex !== null];

            $prev = $rule;
        }
    }

    return $out;
}

/** The roles a twin's selector names by text. */
function textRoles(string $surface, string $sel): array
{
    $roles = [];
    foreach (ROLE_TOKENS[$surface] as $role => $tokens) {
        foreach ($tokens as $tok) {
            if (preg_match('/'.preg_quote($tok, '/').'(?![\w-])/', $sel)) {
                $roles[] = $role;
                break;
            }
        }
    }

    return $roles;
}

[$mode, $tree] = [$argv[1] ?? '', $argv[2] ?? ''];

if ($tree === '' || ! is_dir($tree)) {
    fwrite(STDERR, "usage: php tools/ck2-twins.php json|php <lane-ck-tree> [roles.json]\n");
    exit(2);
}

$all = twins($tree);

if ($mode === 'json') {
    echo json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$measured = json_decode((string) file_get_contents($argv[3] ?? ''), true);
if (! is_array($measured) || count($measured) !== count($all)) {
    fwrite(STDERR, "roles.json missing or not from this twin list\n");
    exit(3);
}

$roleNames = ['checkout' => array_merge(array_keys(ROLE_TOKENS['checkout']), ['flift']), 'cartpage' => array_keys(ROLE_TOKENS['cartpage']), 'cartpanel' => array_keys(ROLE_TOKENS['cartpanel'])];

foreach ($all as $i => $tw) {
    $roles = ($tw['all'] ?? false) ? $roleNames[$tw['surface']] : array_values(array_unique(array_merge($measured[$i] ?? [], textRoles($tw['surface'], $tw['sel']))));
    if (str_contains($tw['t'], 'var(--cop-tflift')) {
        $roles[] = 'flift';
    }
    $roles = array_values(array_unique($roles));
    sort($roles);
    $all[$i]['roles'] = $roles;
}

/*
 * RE-ASSERTION. In the stylesheet a twin sat straight after its rule, so any
 * LATER rule that set the same property on the same element (the phone's
 * padding:0 on .kc-tab, a label.checkbox margin:0) still beat it. Printed at
 * the end of the page, the twin would beat that later rule. So every later
 * rule that sets a property an earlier twin scales, on an element it may share
 * (the same class, id or tag in the last compound), is printed again at its own
 * place in the order, unchanged. Printed again it wins exactly where it won in
 * the stylesheet, by order or by weight, and loses where it lost -- and only
 * while the twin it answers is printed (same text/spacing half, same roles).
 * Only the stylesheets loaded with each surface are read.
 */
const SURFACE_FILES = [
    'checkout' => ['resources/css/kbb/kbb.css', 'resources/css/kbb/kbb-checkout.css'],
    'cartpage' => ['resources/css/kbb/kbb.css', 'resources/css/kbb/kbb-cart.css', 'resources/views/store/cart-squeeze.blade.php', 'resources/views/store/cart-inner.blade.php'],
    'cartpanel' => ['resources/css/kbb/kbb.css'],
];

/** prop => value, for the properties a twin can scale, as the rule sets them (last one wins). */
function tracked(string $body): array
{
    $out = [];
    foreach (decls($body) as $d) {
        if (! preg_match('/^([a-zA-Z-]+)\s*:\s*(.*)$/s', $d, $m)) {
            continue;
        }
        $prop = strtolower($m[1]);
        $value = trim((string) preg_replace('/\s+/', ' ', $m[2]));
        $imp = '';
        if (preg_match('/^(.*?)\s*(!important)$/s', $value, $im)) {
            [$value, $imp] = [$im[1], '!important'];
        }
        if (str_contains($value, 'KBBMASK')) {
            continue;
        }
        $tk = tokens($value);
        $set = match ($prop) {
            'padding', 'margin' => [$prop.'-top' => $tk[0] ?? null, $prop.'-bottom' => $tk[2] ?? ($tk[0] ?? null)],
            'padding-block', 'margin-block' => [str_replace('-block', '', $prop).'-top' => $tk[0] ?? null, str_replace('-block', '', $prop).'-bottom' => $tk[1] ?? ($tk[0] ?? null)],
            'padding-block-start', 'margin-block-start' => [str_replace('-block-start', '', $prop).'-top' => $value],
            'padding-block-end', 'margin-block-end' => [str_replace('-block-end', '', $prop).'-bottom' => $value],
            'padding-top', 'padding-bottom', 'margin-top', 'margin-bottom', 'row-gap', 'font-size' => [$prop => $value],
            'gap' => ['row-gap' => $tk[0] ?? null],
            'font' => in_array($value, ['inherit', 'initial', 'unset'], true) ? ['font-size' => $value] : [],
            default => [],
        };
        foreach ($set as $p => $v) {
            if ($v !== null && $v !== '') {
                $out[$p] = $v.$imp;
            }
        }
    }

    return $out;
}

/** The last compound of each selector in a list: [pseudo-element, tokens]. */
function lastCompounds(string $sel): array
{
    $out = [];
    foreach (decls(str_replace(',', ';', $sel)) as $one) {
        $parts = preg_split('/\s*[>+~]\s*|\s+(?![^(]*\))/', trim($one));
        $last = (string) end($parts);
        preg_match('/::?(before|after|placeholder|marker|first-line|first-letter)\b/', $last, $pe);
        $bare = (string) preg_replace('/:[\w-]+(\((?:[^()]|\([^()]*\))*\))?/', '', $last);
        preg_match_all('/[.#][\w-]+|^[a-zA-Z][\w-]*/', $bare, $tk);
        $tokens = $tk[0];
        // The alternatives of a compound that is only :is()/:where(): its arguments' heads.
        if ($tokens === [] && preg_match('/^:(?:is|where)\((.*)\)/', $last, $alt)) {
            preg_match_all('/(?:^|;|,)\s*([a-zA-Z][\w-]*|[.#][\w-]+)/', $alt[1], $heads);
            $tokens = $heads[1];
        }
        $out[] = [$pe[1] ?? '', $tokens];
    }

    return $out;
}

function mayShare(string $a, string $b): bool
{
    foreach (lastCompounds($a) as [$pa, $ta]) {
        foreach (lastCompounds($b) as [$pb, $tb]) {
            if ($pa === $pb && ($ta === [] || $tb === [] || array_intersect($ta, $tb) !== [])) {
                return true;
            }
        }
    }

    return false;
}

/** The longhands a twin's declarations set: prop => 't' | 's'. */
function twinProps(array $tw): array
{
    $out = [];
    foreach (['t', 's'] as $kind) {
        foreach (tracked($tw[$kind]) as $p => $v) {
            $out[$p] = $kind;
        }
    }

    return $out;
}

$table = ['checkout' => [], 'cartpage' => [], 'cartpanel' => []];
$reasserted = ['checkout' => 0, 'cartpage' => 0, 'cartpanel' => 0];
$rules = $GLOBALS['ck2rules'];

foreach (SURFACE_FILES as $surface => $files) {
    $active = [];
    foreach ($rules as $n => $r) {
        if (! in_array($r['file'], $files, true)) {
            continue;
        }
        if ($r['twin'] !== null) {
            $tw = $all[$r['twin']];
            if ($tw['surface'] === $surface) {
                $table[$surface][] = [$tw['ctx'], $tw['sel'], $tw['t'], $tw['s'], $tw['roles']];
                $active[] = ['sel' => $tw['sel'], 'props' => twinProps($tw), 'roles' => $tw['roles']];
            }
            continue;
        }
        // Never a header or logo rule (HeaderLayoutLockTest's own pattern, plus
        // the checkout's secure bar): no twin scales those, and the header is
        // frozen.
        if ($r['isTwin'] || $active === [] || preg_match('/(^|[\s,>+~(])(header\b|\.sbox\b|\.hin\b|\.hact\b|\.logo\b|\.lgx|\.kbbmi\b|\.ib\b|\.search-in\b|\.mnav-h\b|\.co-head\b|\.secure\b|\.co-cart\b)/', $r['sel'])) {
            continue;
        }
        $props = tracked($r['body']);
        // What this rule's own twin (the next rule) scales is printed by that twin.
        $next = $rules[$n + 1] ?? null;
        if ($next !== null && $next['twin'] !== null && $next['sel'] === $r['sel'] && $next['ctx'] === $r['ctx']) {
            $props = array_diff_key($props, twinProps($all[$next['twin']]));
        }
        if ($props === []) {
            continue;
        }
        $t = [];
        $s = [];
        $roles = [];
        foreach ($active as $a) {
            $hit = array_intersect_key($props, $a['props']);
            if ($hit === [] || ! mayShare($r['sel'], $a['sel'])) {
                continue;
            }
            foreach ($hit as $p => $v) {
                if ($a['props'][$p] === 't') {
                    $t[$p] = $p.':'.$v;
                    $roles = array_merge($roles, $a['roles']);
                } else {
                    $s[$p] = $p.':'.$v;
                }
            }
        }
        if ($t !== [] || $s !== []) {
            $roles = array_values(array_unique($roles));
            sort($roles);
            $table[$surface][] = [$r['ctx'], $r['sel'], implode(';', $t), implode(';', $s), $roles];
            $reasserted[$surface]++;
        }
    }
}
fwrite(STDERR, 'reasserted: '.json_encode($reasserted)."\n");

$body = var_export($table, true);
$body = (string) preg_replace(["/array \(\n/", "/\n\s*\),/", "/\n\s*\)/", '/\d+ => /'], ["[\n", "\n],", "\n]", ''], $body);
$version = substr(hash('sha256', json_encode($table)), 0, 12);

$php = <<<PHP
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * GENERATED by tools/ck2-twins.php -- do not edit by hand. Lane CK2.
 *
 * The text-size override rules of the checkout, the cart page and the cart
 * panel, per surface, in stylesheet order:
 *   [at-rule context, selector, text declarations, spacing declarations, roles]
 * Every value is written times var(--ckt|--cgt|--kpt,1) (text) or
 * var(--cks|--cgs|--kps,1) (vertical spacing), so it computes to the
 * stylesheet's own value until App\Support\TextSizes prints a factor. None of
 * this is in a stylesheet: it is printed, inline, only on a page whose owner
 * has moved a size, and only the rules that size needs.
 */
final class TextSizeTwins
{
    /** Changes whenever the table does; a cached block built from another table is rebuilt. */
    public const VERSION = '{$version}';

    public const TABLE = {$body};
}

PHP;

file_put_contents(__DIR__.'/../app/Support/TextSizeTwins.php', $php);
echo 'twins: ', implode(', ', array_map(static fn (string $k): string => $k.' '.count($table[$k]), array_keys($table))), ' -> app/Support/TextSizeTwins.php v', $version, "\n";
