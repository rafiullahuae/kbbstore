<?php

declare(strict_types=1);

/*
 * Store → Security → Firewall (Lane FW): the console half, wired exactly once.
 *
 * The routes and the screen partial need no integrator edit (security-admin.php
 * requires firewall-admin.php; security-screen includes firewall-screen). What
 * does is the console's own lists: TITLES (so ?go=firewall and #firewall name
 * the screen) and LATE_RENDERED (the deep-link replay). docs/fw-wiring.json is
 * applied IN MEMORY here, so this is the same test before and after the
 * integrator runs tools/fw-wire.php -- it pins the finished state.
 */

function fwWired(string $file): string
{
    $src = (string) file_get_contents(base_path($file));

    foreach (json_decode((string) file_get_contents(base_path('docs/fw-wiring.json')), true, 512, JSON_THROW_ON_ERROR) as $e) {
        if ($e['file'] !== $file || substr_count($src, $e['replacement']) >= $e['count']) {
            continue;
        }

        expect(substr_count($src, $e['anchor']))->toBe($e['count'], "block {$e['n']}: {$e['what']}");
        $src = str_replace($e['anchor'], $e['replacement'], $src);
    }

    return $src;
}

it('names the screen once and arms its deep link once', function () {
    // MUTATION: delete block 2 and LATE_RENDERED holds no 'firewall'; apply it twice and it holds two.
    $app = fwWired('resources/views/admin/app.blade.php');

    expect(substr_count($app, "'firewall':['Store → Security','Firewall']"))->toBe(1)
        ->and(preg_match('/const LATE_RENDERED\s*=\s*new Set\(\[([^\]]*)\]\);/', $app, $m))->toBe(1)
        ->and(substr_count($m[1], "'firewall'"))->toBe(1)
        // The include is NOT in app.blade.php: it rides in the Security partial.
        ->and(substr_count($app, "@include('admin.partials.firewall-screen')"))->toBe(0);

    $nav = (string) file_get_contents(app_path('Support/AdminNav.php'));
    expect(substr_count($nav, "['id' => 'firewall', 'label' => 'Firewall', 'read' => 'admin-api/security/firewall', 'late' => true,"))->toBe(1);
});

it('keeps the handover documents that quote LATE_RENDERED in step with the console', function () {
    $app = fwWired('resources/views/admin/app.blade.php');

    preg_match_all('/const LATE_RENDERED=new Set\(\[[^\]]*\]\);/', fwWired('docs/T1B-ADMIN-APP-BLOCKS.md'), $quoted);
    expect($app)->toContain((string) end($quoted[0]));
    expect(substr_count(fwWired('docs/BG-ADMIN-APP-BLOCKS.md'), "'imageseo','security','firewall','paygw',"))->toBe(2);
});

it('leaves every other lane\'s wiring record whole', function () {
    // MUTATION: anchor block 2 on "'routines','imageseo','security'," and Lane IR's record is reported broken.
    $others = [];

    foreach (glob(base_path('docs/*-wiring.json')) ?: [] as $file) {
        if (basename($file) === 'fw-wiring.json') {
            continue;
        }

        foreach (json_decode((string) file_get_contents($file), true) ?: [] as $e) {
            if (($e['replacement'] ?? '') !== '') {
                $others[] = [basename($file).' block '.($e['n'] ?? '?'), $e['file'] ?? '', $e['replacement']];
            }
        }
    }

    $broken = [];

    foreach (array_unique(array_column(json_decode((string) file_get_contents(base_path('docs/fw-wiring.json')), true), 'file')) as $file) {
        $before = (string) file_get_contents(base_path($file));
        $after = fwWired($file);

        foreach ($others as [$label, $target, $needle]) {
            if ($target === $file && str_contains($before, $needle) && ! str_contains($after, $needle)) {
                $broken[] = $label;
            }
        }
    }

    expect($broken)->toBe([]);
});
