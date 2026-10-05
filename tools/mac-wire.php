<?php

declare(strict_types=1);

/*
 * Lane MAC: wire the owner app into the integrator's files, from
 * docs/mac-wiring.json.
 *
 *     php tools/mac-wire.php            apply (checks EVERY anchor count first;
 *                                       writes nothing at all if one is off)
 *     php tools/mac-wire.php --check    report only
 *
 * Three edits:
 *   1. routes/web.php       require routes/owner-app.php at the top level,
 *                           directly above the admin login route;
 *   2. routes/web.php       require routes/owner-app-admin.php inside the
 *                           admin-api group, directly under admin-roles.php;
 *   (The provider is registered from AppServiceProvider::register(), not
 *   bootstrap/providers.php: UpdateGuard forbids bootstrap/ to a package.)
 *
 * A block whose replacement is already present is skipped, so running it twice
 * is harmless. tests/Feature/OwnerAppWiringTest.php pins the finished state
 * (each line exactly once) and applies this same JSON in memory, so the record
 * and the edits cannot drift.
 */

$root = dirname(__DIR__);
$edits = json_decode((string) file_get_contents($root.'/docs/mac-wiring.json'), true, 512, JSON_THROW_ON_ERROR);
$check = in_array('--check', $argv, true);

$files = [];
$problems = [];

foreach ($edits as $e) {
    $path = $root.'/'.$e['file'];
    $files[$e['file']] ??= (string) file_get_contents($path);
    $src = $files[$e['file']];

    if (str_contains($src, $e['replacement'])) {
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
