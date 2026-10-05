<?php

declare(strict_types=1);

/*
 * Lane WP: the one line routes/web.php needs for Content -> Media Library ->
 * WebP images.
 *
 *     php tools/wp-wire.php            apply (checks the anchor count first;
 *                                      writes nothing if it is off)
 *     php tools/wp-wire.php --check    report only
 *
 * The screen needs no wiring in app.blade.php: admin/partials/webp-screen is
 * included from admin/partials/media-library-screen, which this lane owns the
 * change to. Running this twice is harmless — an applied edit is skipped.
 * tests/Feature/WebpImagesTest.php pins the FINISHED state: the require
 * appears exactly once.
 */

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);

$edits = [[
    'file' => 'routes/web.php',
    'what' => 'mount routes/webp-admin.php inside the admin-api group, after image-sizes',
    'anchor' => "        require __DIR__.'/image-sizes-admin.php';\n",
    'replacement' => "        require __DIR__.'/image-sizes-admin.php';\n\n"
        ."        // Content → Media Library → WebP images (Lane WP): settings and the\n"
        ."        // bulk converter. Same group as the media library; the capability map\n"
        ."        // gives admin-api/media/webp/** to media.optimize, above media/**.\n"
        ."        require __DIR__.'/webp-admin.php';\n",
    'done' => "require __DIR__.'/webp-admin.php';",
]];

$files = [];
$problems = [];

foreach ($edits as $i => $e) {
    $files[$e['file']] ??= (string) file_get_contents($root.'/'.$e['file']);
    $src = $files[$e['file']];

    if (substr_count($src, $e['done']) === 1) {
        echo 'edit '.($i + 1).": already applied\n";
        continue;
    }

    if (substr_count($src, $e['done']) > 1) {
        $problems[] = 'edit '.($i + 1).": {$e['done']} appears more than once in {$e['file']} — fix by hand";
        continue;
    }

    $n = substr_count($src, $e['anchor']);

    if ($n !== 1) {
        $problems[] = 'edit '.($i + 1)." ({$e['file']}): anchor found {$n} times, expected 1";
        continue;
    }

    $files[$e['file']] = str_replace($e['anchor'], $e['replacement'], $src);
    echo 'edit '.($i + 1).': '.($check ? 'would apply' : 'applied')." — {$e['what']}\n";
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
