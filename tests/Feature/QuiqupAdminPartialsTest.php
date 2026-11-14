<?php

declare(strict_types=1);

/*
 * The two console partials (Store -> Quiqup, and Schedule with Quiqup on the
 * Orders list). Pins what CLAUDE.md asks of every admin screen: a late answer
 * never repaints another screen, no layout-measuring JavaScript, no polling
 * timer, every Quiqup string escaped, and -- once the integrator wires them --
 * each include exactly ONCE (zero is "built, never wired up"; two wraps
 * window.go around its own wrapper). Nothing here asserts they are NOT wired.
 */

function qqPartial(string $name): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/' . $name . '.blade.php'));
}

it('guards every late answer with ours() / sheetOpen() before painting', function () {
    $screen = qqPartial('quiqup-screen');
    $orders = qqPartial('quiqup-orders');

    // Every await of a request in the screen is followed by an ours() check.
    preg_match_all('/await call\([^;]+;\s*\n([^\n]*\n){0,2}/', $screen, $m);
    foreach ($m[0] as $block) {
        expect($block)->toMatch('/ours\(\)|S\.busy = \'\'/');
    }
    expect(substr_count($orders, 'sheetOpen(t)'))->toBeGreaterThanOrEqual(3);
});

it('measures no layout, runs no polling timer and loads no new script', function () {
    foreach (['quiqup-screen', 'quiqup-orders'] as $p) {
        $src = qqPartial($p);
        foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight', 'getComputedStyle', 'setInterval', '<script src'] as $bad) {
            expect(str_contains($src, $bad))->toBeFalse("{$p} uses {$bad}");
        }
    }
    // The batch loop is bounded and driven by answers, not a clock.
    expect(qqPartial('quiqup-orders'))->toContain('round < 40');
});

it('escapes what Quiqup and the order say before printing it', function () {
    $orders = qqPartial('quiqup-orders');
    foreach (['e(res.error', 'e(b.text)', 'e(r.customer', 'e(s.reason)', 'e(s.error)', 'e(res.tracking_url)'] as $needle) {
        expect($orders)->toContain($needle);
    }
});

it('is included at most once each, and in the right order when the integrator wires it', function () {
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $screen = substr_count($app, "@include('admin.partials.quiqup-screen')");
    $orders = substr_count($app, "@include('admin.partials.quiqup-orders')");

    expect($screen)->toBeLessThanOrEqual(1)->and($orders)->toBeLessThanOrEqual(1);
    if ($screen === 1 && $orders === 1) {
        expect(strpos($app, "@include('admin.partials.quiqup-screen')"))->toBeLessThan(strpos($app, "@include('admin.partials.quiqup-orders')"));
    }

    foreach (['web.php' => "require __DIR__.'/quiqup-admin.php';", 'api.php' => "require __DIR__.'/quiqup-webhooks.php';"] as $file => $line) {
        expect(substr_count((string) file_get_contents(base_path('routes/' . $file)), $line))->toBeLessThanOrEqual(1);
    }
});
