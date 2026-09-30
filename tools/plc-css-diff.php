<?php

declare(strict_types=1);

/**
 * WHICH RULES THE TWO GRID STYLESHEETS DISAGREE ABOUT. (Lane PLC)
 *
 *     php tools/plc-css-diff.php
 *     php tools/plc-css-diff.php --json > out.json
 *
 * kbb.css carries a second copy of kbb-grid-skins.css, announced in its own
 * comment as "~180 rules". That number was an estimate written by eye and this
 * tool exists because an estimate is not a drift report: the shop loads both
 * sheets on three pages and only one of them on the rest, so a rule present in
 * one copy and absent from the other is a rule whose effect DEPENDS ON WHICH
 * PAGE IT IS.
 *
 * This compares the WHOLE of both sheets, over the selectors they share.
 * tools/plc-css-sides.php does the other half — the selectors only one of them
 * writes — with kbb.css cut down to the copy region first.
 */
require __DIR__.'/plc-css-diff-lib.php';

$root = dirname(__DIR__);
$aPath = $root.'/resources/css/kbb/kbb-grid-skins.css';
$bPath = $root.'/resources/css/kbb/kbb.css';

$a = kbbParseCss((string) file_get_contents($aPath));
$b = kbbParseCss((string) file_get_contents($bPath));

$ia = kbbDeclarationIndex($a);
$ib = kbbDeclarationIndex($b);

/*
 * THE COMPARISON IS OVER THE SHARED SELECTORS ONLY.
 *
 * kbb.css is the whole shop — header, footer, checkout — and grid-skins.css is
 * the card. Reporting "kbb.css has 3,000 rules grid-skins.css lacks" would be
 * arithmetic about two different files. The DUPLICATION is the set of
 * (context, selector) pairs that appear in BOTH, and the drift is what those
 * shared pairs disagree about.
 */
$selA = [];
foreach ($a as $r) {
    $selA[$r['context'].'|'.$r['selector']] = true;
}
$selB = [];
foreach ($b as $r) {
    $selB[$r['context'].'|'.$r['selector']] = true;
}

$shared = array_intersect_key($selA, $selB);

$sameValue = [];
$different = [];
$onlyA = [];
$onlyB = [];

$props = [];
foreach ($ia as $key => $rows) {
    $props[$key] = true;
}
foreach ($ib as $key => $rows) {
    $props[$key] = true;
}

foreach (array_keys($props) as $key) {
    [$ctx, $sel, $prop] = explode('|', $key, 3);

    if (! isset($shared[$ctx.'|'.$sel])) {
        continue;
    }

    $va = $ia[$key] ?? null;
    $vb = $ib[$key] ?? null;

    if ($va === null) {
        $onlyB[$key] = end($vb);

        continue;
    }

    if ($vb === null) {
        $onlyA[$key] = end($va);

        continue;
    }

    // The LAST declaration of a property inside one sheet is the one that sheet
    // contributes, which is what the cascade uses at equal specificity.
    $lastA = end($va)['value'];
    $lastB = end($vb)['value'];

    if ($lastA === $lastB) {
        $sameValue[$key] = true;
    } else {
        $different[$key] = ['a' => $lastA, 'b' => $lastB, 'context' => $ctx, 'selector' => $sel, 'prop' => $prop];
    }
}

$json = in_array('--json', $argv, true);

if ($json) {
    echo json_encode([
        'rules_a' => count($a),
        'rules_b' => count($b),
        'selectors_a' => count($selA),
        'selectors_b' => count($selB),
        'shared_selectors' => count($shared),
        'same_value' => count($sameValue),
        'different_value' => count($different),
        'only_in_grid_skins' => count($onlyA),
        'only_in_kbb' => count($onlyB),
        'different' => array_values($different),
        'onlyA' => array_values($onlyA),
        'onlyB' => array_values($onlyB),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

    exit(0);
}

printf("kbb-grid-skins.css : %d rules, %d distinct (context, selector)\n", count($a), count($selA));
printf("kbb.css            : %d rules, %d distinct (context, selector)\n", count($b), count($selB));
printf("\nselectors declared in BOTH sheets (the duplication) : %d\n", count($shared));
printf("  declarations that AGREE                          : %d\n", count($sameValue));
printf("  declarations that DISAGREE on value              : %d\n", count($different));
printf("  declared in kbb-grid-skins.css ONLY              : %d\n", count($onlyA));
printf("  declared in kbb.css ONLY                         : %d\n", count($onlyB));

echo "\n──────── DISAGREE ON VALUE ────────\n";
foreach ($different as $key => $row) {
    printf("  %s%s { %s }\n", $row['context'] === '' ? '' : $row['context'].' :: ', $row['selector'], $row['prop']);
    printf("      grid-skins: %s\n      kbb.css   : %s\n", $row['a'], $row['b']);
}

echo "\n──────── ONLY IN kbb-grid-skins.css ────────\n";
foreach ($onlyA as $key => $row) {
    printf("  %s%s { %s: %s }\n", $row['context'] === '' ? '' : $row['context'].' :: ', $row['selector'], $row['prop'], $row['value']);
}

echo "\n──────── ONLY IN kbb.css ────────\n";
foreach ($onlyB as $key => $row) {
    printf("  %s%s { %s: %s }\n", $row['context'] === '' ? '' : $row['context'].' :: ', $row['selector'], $row['prop'], $row['value']);
}
