<?php

declare(strict_types=1);

/**
 * THE SELECTOR-LEVEL HALF OF THE DRIFT. (Lane PLC)
 *
 *     php tools/plc-css-sides.php
 *
 * tools/plc-css-diff.php compares the sheets where they declare the SAME
 * selector, which is the half that can disagree on a value. This is the other
 * half, and the one kbb.css's own "~180 rules" comment is about: a selector
 * that one copy writes and the other does not.
 *
 * ── THE SURFACE IS NAMED BY SELECTOR, NOT BY A LINE RANGE ──────────────────
 *
 * The first version of this cut kbb.css to the region between its
 * "A SECOND, COMPLETE COPY" banner and the next banner comment, and reported
 * 95 selectors present only there. It was wrong by 40-odd: that window also
 * contains the page background (`html::before`, the three gradients, the 300s
 * keyframes), the homepage section card and the product-page price — Lane BG's
 * and Lane PDP2's work, which merely LANDED between two banners. A line window
 * measures where a rule sits in a file, and where a rule sits in a file is not
 * what makes it part of the card.
 *
 * So the surface is the CARD's own selector vocabulary, and a rule is in it if
 * any of its comma parts names one of those classes. That is stable under any
 * edit above or below it, and it is the same shape of mistake CLAUDE.md names
 * for a fixed-width substr() window — a boundary chosen by position rather than
 * by content is a boundary that silently moves.
 */
require __DIR__.'/plc-css-diff-lib.php';

/**
 * The product grid's own vocabulary.
 *
 * `.cb`, `.cn` and `.cp` are card internals and far too generic to list on
 * their own; they only ever appear here under a `.kbb-card`/`.kbb-pgrid`
 * ancestor, so the ancestor is what is matched and they come along with it.
 */
const KBB_CARD_SURFACE = [
    'kbb-pgrid',
    'kbb-card',
    'kbb-badge',
    'kbb-crate',
    'kbb-cstar',
    'kbb-tile',
    'kbb-gridhead',
    'pc-no',
    'data-skin',
    'qv-btn',
];

function kbbIsCardRule(string $selector): bool
{
    foreach (KBB_CARD_SURFACE as $token) {
        if (str_contains($selector, $token)) {
            return true;
        }
    }

    return false;
}

$root = dirname(__DIR__);
$a = kbbParseCss((string) file_get_contents($root.'/resources/css/kbb/kbb-grid-skins.css'));
$b = kbbParseCss((string) file_get_contents($root.'/resources/css/kbb/kbb.css'));

$keyed = static function (array $rules, bool $cardOnly): array {
    $out = [];

    foreach ($rules as $r) {
        if ($cardOnly && ! kbbIsCardRule($r['selector'])) {
            continue;
        }

        $out[$r['context'].'|'.$r['selector']][] = $r;
    }

    return $out;
};

// kbb-grid-skins.css is the card sheet: every rule in it is in the surface by
// construction, and the `*{box-sizing}` reset is the one that is not.
$ka = $keyed($a, false);
$kb = $keyed($b, true);

$onlySkins = array_diff_key($ka, $kb);
$onlyKbb = array_diff_key($kb, $ka);
$both = array_intersect_key($ka, $kb);

/*
 * The human report is buffered so that --json can discard it. Printing both and
 * letting the caller `tail` past the prose is how a JSON reader comes to parse a
 * report banner as data.
 */
ob_start();

printf("kbb-grid-skins.css               : %d rules, %d distinct selectors\n", count($a), count($ka));
printf("kbb.css, card surface only       : %d distinct selectors\n", count($kb));
printf("\nselectors in BOTH copies             : %d\n", count($both));
printf("selectors ONLY in kbb-grid-skins.css : %d\n", count($onlySkins));
printf("selectors ONLY in kbb.css            : %d\n", count($onlyKbb));
printf("total divergent selectors            : %d\n", count($onlySkins) + count($onlyKbb));

$ia = kbbDeclarationIndex($a);
$ib = kbbDeclarationIndex(array_merge(...array_values($kb) ?: [[]]));

$disagree = [];
$declOnlyA = [];
$declOnlyB = [];

foreach (array_keys($ia + $ib) as $key) {
    [$ctx, $sel] = explode('|', $key, 3);

    if (! isset($both[$ctx.'|'.$sel])) {
        continue;
    }

    $va = $ia[$key] ?? null;
    $vb = $ib[$key] ?? null;

    if ($va === null) {
        $declOnlyB[$key] = end($vb);

        continue;
    }

    if ($vb === null) {
        $declOnlyA[$key] = end($va);

        continue;
    }

    if (end($va)['value'] !== end($vb)['value']) {
        $disagree[$key] = ['a' => end($va)['value'], 'b' => end($vb)['value']];
    }
}

printf("\nwithin the selectors both copies declare:\n");
printf("  declarations that DISAGREE on value : %d\n", count($disagree));
printf("  declared in kbb-grid-skins.css only : %d\n", count($declOnlyA));
printf("  declared in kbb.css only            : %d\n", count($declOnlyB));

/*
 * THE MACHINE-READABLE HALF, so the browser probe is generated from this parse
 * rather than typed out by hand. A hand-written probe list can only find what
 * its author already suspected, which is the wrong instrument for a survey.
 */
if (in_array('--json', $argv, true)) {
    ob_end_clean();

    $flat = static function (array $groups, string $side): array {
        $out = [];

        foreach ($groups as $key => $rules) {
            [$ctx, $sel] = explode('|', $key, 2);

            foreach ($rules as $r) {
                foreach ($r['decls'] as [$prop, $value]) {
                    $out[] = ['side' => $side, 'context' => $ctx, 'selector' => $sel, 'prop' => $prop, 'value' => $value];
                }
            }
        }

        return $out;
    };

    file_put_contents('php://stdout', json_encode([
        'only_skins' => count($onlySkins),
        'only_kbb' => count($onlyKbb),
        'both' => count($both),
        'disagree' => count($disagree),
        'decls' => array_merge($flat($onlySkins, 'grid-skins'), $flat($onlyKbb, 'kbb')),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    exit(0);
}

$show = static function (string $title, array $groups): void {
    echo "\n──────── ".$title." (".count($groups).") ────────\n";

    foreach ($groups as $key => $rules) {
        [$ctx, $sel] = explode('|', $key, 2);
        echo '  '.($ctx === '' ? '' : $ctx.' :: ').$sel."\n";

        foreach ($rules as $r) {
            foreach ($r['decls'] as [$p, $v]) {
                // A data: URI is 8 KB of SVG and says nothing here.
                echo '        '.$p.': '.(strlen($v) > 120 ? substr($v, 0, 117).'...' : $v)."\n";
            }
        }
    }
};

$show('SELECTORS ONLY IN kbb-grid-skins.css', $onlySkins);
$show('SELECTORS ONLY IN kbb.css', $onlyKbb);

echo "\n──────── DISAGREE ON VALUE (".count($disagree).") ────────\n";
foreach ($disagree as $key => $row) {
    [$ctx, $sel, $prop] = explode('|', $key, 3);
    printf("  %s%s { %s }\n      grid-skins: %s\n      kbb.css   : %s\n", $ctx === '' ? '' : $ctx.' :: ', $sel, $prop, $row['a'], $row['b']);
}

echo "\n──────── DECLARED IN kbb-grid-skins.css ONLY (".count($declOnlyA).") ────────\n";
foreach ($declOnlyA as $key => $row) {
    [$ctx, $sel, $prop] = explode('|', $key, 3);
    printf("  %s%s { %s: %s }\n", $ctx === '' ? '' : $ctx.' :: ', $sel, $prop, $row['value']);
}

echo "\n──────── DECLARED IN kbb.css ONLY (".count($declOnlyB).") ────────\n";
foreach ($declOnlyB as $key => $row) {
    [$ctx, $sel, $prop] = explode('|', $key, 3);
    printf("  %s%s { %s: %s }\n", $ctx === '' ? '' : $ctx.' :: ', $sel, $prop, $row['value']);
}

ob_end_flush();
