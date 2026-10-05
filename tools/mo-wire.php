<?php

declare(strict_types=1);

/*
 * Lane MO: apply docs/mo-wiring.json to resources/views/admin/app.blade.php —
 * the Mega Menu screen's tree and its drag-and-drop become the column board
 * (resources/views/admin/partials/menu-order.blade.php).
 *
 *     php tools/mo-wire.php            apply (checks every anchor count first;
 *                                      writes nothing if one is off)
 *     php tools/mo-wire.php --check    report only
 *
 * Idempotent: a block whose replacement is already present — or, for the
 * three blocks that only REMOVE the old tree code, whose anchor is already
 * gone — is skipped, so running it twice is harmless.
 * tests/Feature/MenuOrderWiringTest.php applies the same JSON in memory, so
 * the record and the edits cannot drift.
 */

$root = dirname(__DIR__);
$edits = json_decode((string) file_get_contents($root.'/docs/mo-wiring.json'), true, 512, JSON_THROW_ON_ERROR);
$check = in_array('--check', $argv, true);

$files = [];
$problems = [];

foreach ($edits as $e) {
    $path = $root.'/'.$e['file'];
    $files[$e['file']] ??= (string) file_get_contents($path);
    $src = $files[$e['file']];

    $applied = $e['replacement'] === ''
        ? substr_count($src, $e['anchor']) === 0
        : substr_count($src, $e['replacement']) >= $e['count'];
    if ($applied) {
        echo "block {$e['n']}: already applied\n";
        continue;
    }

    $n = substr_count($src, $e['anchor']);
    if ($n !== $e['count']) {
        $problems[] = "block {$e['n']} ({$e['file']}): anchor found {$n} times, expected {$e['count']}";
        continue;
    }

    $files[$e['file']] = str_replace($e['anchor'], $e['replacement'], $src);
    echo "block {$e['n']}: ".($check ? 'would apply' : 'applied')." — {$e['what']}\n";
}

if ($problems !== []) {
    fwrite(STDERR, implode("\n", $problems)."\nNothing written.\n");
    exit(1);
}

if (! $check) {
    foreach ($files as $file => $body) {
        file_put_contents($root.'/'.$file, $body);
    }
}
