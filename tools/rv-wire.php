<?php

declare(strict_types=1);

/*
 * Lane RV: the two lines routes/web.php needs for the review request email.
 *
 *     php tools/rv-wire.php            apply (checks each anchor first; writes
 *                                      nothing if one is off)
 *     php tools/rv-wire.php --check    report only
 *
 * The admin tab needs no line in app.blade.php: admin/partials/review-request-
 * rules is included from admin/partials/emails-templates-screens. Running this
 * twice is harmless — an applied edit is skipped. ReviewRequestPageTest pins
 * the FINISHED state: each require appears exactly once.
 */

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);

$edits = [
    [
        'file' => 'routes/web.php',
        'what' => 'mount routes/review-request-admin.php inside the admin-api group, after emails-templates-admin',
        'anchor' => "        require __DIR__.'/emails-templates-admin.php';   // Emails → Customer emails + the template editor (Lane EK)\n",
        'replacement' => "        require __DIR__.'/emails-templates-admin.php';   // Emails → Customer emails + the template editor (Lane EK)\n        require __DIR__.'/review-request-admin.php';   // Emails → Customer emails → Review request → Schedule & rules (Lane RV)\n",
        'done' => "require __DIR__.'/review-request-admin.php';",
    ],
    [
        'file' => 'routes/web.php',
        'what' => 'mount routes/review-request-public.php at the top level, after marketing-open-public',
        'anchor' => "require __DIR__.'/marketing-open-public.php';   // the open pixel of marketing emails (Lane ER)\n",
        'replacement' => "require __DIR__.'/marketing-open-public.php';   // the open pixel of marketing emails (Lane ER)\nrequire __DIR__.'/review-request-public.php';   // the review request email's page and its unsubscribe (Lane RV)\n",
        'done' => "require __DIR__.'/review-request-public.php';",
    ],
];

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
        $problems[] = 'edit '.($i + 1).": {$e['done']} appears more than once — fix by hand";
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
