<?php

declare(strict_types=1);

/*
 * Lane PW: wire the shop's Home Screen app (App -> Site App) into the integrator's files, from
 * docs/pwa-wiring.json.
 *
 *     php tools/pwa-wire.php            apply (checks EVERY anchor count first;
 *                                       writes nothing at all if one is off)
 *     php tools/pwa-wire.php --check    report only
 *     php tools/pwa-wire.php --skip-docs  code files only (the preview copy)
 *
 * Nine edits (docs/pwa-wiring.json says what each one is):
 *   1-2  routes/web.php: require routes/site-app.php inside the stateless
 *        group at the top (under wallet-domain.php), and
 *        routes/site-app-admin.php inside the admin-api group;
 *   3    app.blade.php NAV: the new "App" group after Platform, holding the
 *        static Site App row (Owner App registers after 'siteapp');
 *   4-6  app.blade.php: TITLES, LATE_RENDERED, the @include;
 *   7-9  the three handover records that quote the LATE_RENDERED line.
 *
 * A block whose replacement is already present is skipped, so running it twice
 * is harmless. tests/Feature/SiteAppWiringTest.php pins the finished state
 * (each line exactly once) and applies this same JSON in memory, so the record
 * and the edits cannot drift.
 */

$root = dirname(__DIR__);
$edits = json_decode((string) file_get_contents($root.'/docs/pwa-wiring.json'), true, 512, JSON_THROW_ON_ERROR);
$check = in_array('--check', $argv, true);
// --skip-docs: code files only. tools/pwa-shop-preview.sh wires a throwaway
// copy of the app that carries no docs/ directory.
if (in_array('--skip-docs', $argv, true)) {
    $edits = array_values(array_filter($edits, fn (array $e): bool => ! str_starts_with($e['file'], 'docs/')));
}

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
