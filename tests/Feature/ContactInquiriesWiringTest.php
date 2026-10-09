<?php

declare(strict_types=1);

/*
 * Store → Inquiries and the contact form reach the router and the console only
 * through the integrator's files (routes/web.php, resources/views/admin/
 * app.blade.php), which Lane CT may not edit. docs/ct-wiring.json carries the
 * edits; tools/ct-wire.php applies them. These tests apply the same record in
 * memory, so they pin the FINISHED state — green before the integrator wires
 * it and after — and never "not wired yet" (CLAUDE.md). (Lane CT)
 */

function ctWired(): array
{
    $edits = json_decode((string) file_get_contents(base_path('docs/ct-wiring.json')), true, 512, JSON_THROW_ON_ERROR);
    $files = [];
    $problems = [];

    foreach ($edits as $e) {
        $files[$e['file']] ??= (string) file_get_contents(base_path($e['file']));
        $src = $files[$e['file']];

        if (str_contains($src, $e['replacement'])
            && (str_contains($e['replacement'], $e['anchor']) || substr_count($src, $e['anchor']) === 0)) {
            continue;
        }

        $n = substr_count($src, $e['anchor']);
        if ($n !== $e['count']) {
            $problems[] = "block {$e['n']} ({$e['file']}): anchor found {$n} times, expected {$e['count']}";
            continue;
        }

        $files[$e['file']] = str_replace($e['anchor'], $e['replacement'], $src);
    }

    return ['files' => $files, 'problems' => $problems];
}

it('can apply the handover, every anchor exactly as often as it says', function () {
    /* MUTATION: change any anchor in docs/ct-wiring.json by one character -> red, naming the block. */
    $w = ctWired();

    expect($w['problems'])->toBe([], implode("\n", $w['problems']));
});

it('mounts each route file exactly once, the admin one inside the guarded group', function () {
    $web = ctWired()['files']['routes/web.php'];

    expect(substr_count($web, "require __DIR__.'/contact-inquiries-admin.php';"))->toBe(1)
        ->and(substr_count($web, "require __DIR__.'/contact-form.php';"))->toBe(1)
        ->and($web)->toContain("require __DIR__.'/not-found-page-admin.php';  // Safety → 404 page (Lane NF)\n        require __DIR__.'/contact-inquiries-admin.php';")
        ->and($web)->toContain("require __DIR__.'/newsletter-public.php';\nrequire __DIR__.'/contact-form.php';");
});

it('puts the screen in the console exactly once: include, title, late replay and sidebar row', function () {
    /* MUTATION: duplicate block 5 -> the include count is 2 and the screen wraps window.go around itself. */
    $app = ctWired()['files']['resources/views/admin/app.blade.php'];

    expect(substr_count($app, "@include('admin.partials.contact-inquiries-screen')"))->toBe(1)
        ->and(substr_count($app, "'inquiries':['Store','Inquiries']"))->toBe(1)
        ->and(substr_count((string) file_get_contents(app_path('Support/AdminNav.php')), "['id' => 'inquiries', 'label' => 'Inquiries', 'read' => 'admin-api/inquiries', 'late' => true"))->toBe(1);

    expect(preg_match('/const LATE_RENDERED=new Set\(\[(.*?)\]\);/s', $app, $m))->toBe(1)
        ->and(substr_count($m[1], "'inquiries'"))->toBe(1);
});

it('maps every Inquiries endpoint to its own capability', function () {
    $map = static fn (string $m, string $p) => \App\Support\AdminCapabilities::forPath($m, $p);

    expect($map('GET', 'admin-api/inquiries'))->toBe('inquiries.view')
        ->and($map('POST', 'admin-api/inquiries/12/read'))->toBe('inquiries.view')
        ->and($map('DELETE', 'admin-api/inquiries/12'))->toBe('inquiries.manage')
        ->and($map('GET', 'admin-api/inquiries/settings'))->toBe('inquiries.manage')
        ->and($map('POST', 'admin-api/inquiries/settings'))->toBe('inquiries.manage')
        ->and(\App\Support\AdminCapabilities::CAPABILITIES['inquiries.view'])->toBe(['owner', 'manager', 'support'])
        ->and(\App\Support\AdminCapabilities::CAPABILITIES['inquiries.manage'])->toBe(['owner', 'manager']);
});
