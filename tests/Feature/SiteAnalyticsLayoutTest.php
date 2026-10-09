<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Services\Analytics\BoardLayout;
use Tests\Support\SiteAnalyticsRoutes;

/*
 * The Analytics board's blocks move (Lane AN2). The owner: "the blocks should
 * be moveable to change the position as per my convenience. should be drag n
 * drop." Order and hidden blocks per admin USER, allowlisted.
 */

function lyAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'LY '.$role, 'email' => 'ly-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

it('keeps only known blocks, each once, and puts back any it does not name', function () {
    // MUTATION: drop the "missing blocks go back in" loop and a block added in
    // a later release never appears for anybody who had saved a layout.
    $r = BoardLayout::sanitize(['funnel', 'live', '<script>', 'live', 42, 'feed'], ['pages', 'nope', 'pages']);

    expect($r['order'])->toHaveCount(count(BoardLayout::BLOCKS))
        ->and(array_values(array_unique($r['order'])))->toBe($r['order'])
        ->and(array_slice($r['order'], 0, 1))->toBe(['funnel'])
        ->and($r['order'])->not->toContain('<script>')
        ->and($r['hidden'])->toBe(['pages'])
        ->and(BoardLayout::sanitize(null, null))->toBe(['order' => BoardLayout::BLOCKS, 'hidden' => [], 'custom' => false]);
});

it('saves each admin\'s own layout, resets it, and lets analytics.view arrange the board', function () {
    // MUTATION: key the row by board only (not admin_user_id) and B reads A's order.
    SiteAnalyticsRoutes::wire($this->app);
    $a = lyAdmin('owner');
    $b = lyAdmin('manager');

    $this->actingAs($a, 'admin')->putJson('/admin-api/site-analytics/layout', ['order' => ['funnel', 'live'], 'hidden' => ['google']])
        ->assertOk()->assertJsonPath('order.0', 'funnel')->assertJsonPath('hidden', ['google']);
    expect($this->actingAs($a, 'admin')->getJson('/admin-api/site-analytics/layout')->json('order.0'))->toBe('funnel')
        ->and($this->actingAs($b, 'admin')->getJson('/admin-api/site-analytics/layout')->json('order.0'))->toBe('live')
        // The summary carries the viewer's layout too, so the board opens arranged.
        ->and($this->actingAs($a, 'admin')->getJson('/admin-api/site-analytics')->json('layout.hidden'))->toBe(['google']);

    $this->actingAs($a, 'admin')->deleteJson('/admin-api/site-analytics/layout')->assertOk()->assertJsonPath('custom', false);
    expect(BoardLayout::get($a->id)['order'])->toBe(BoardLayout::BLOCKS);

    // A role without analytics.view cannot arrange (or read) it.
    $this->actingAs(lyAdmin('support'), 'admin')->putJson('/admin-api/site-analytics/layout', ['order' => []])->assertForbidden();
    $this->actingAs($a, 'admin')->putJson('/admin-api/site-analytics/layout', ['order' => 'nope'])->assertStatus(422);
    expect(\App\Support\AdminCapabilities::forPath('PUT', 'admin-api/site-analytics/layout'))->toBe('analytics.view');
});

it('moves blocks with pointer events and the keyboard, measuring nothing and using no library', function () {
    // MUTATION: switch to HTML5 drag-and-drop (draggable / dragstart) or add a
    // getBoundingClientRect, and this is red.
    $js = (string) file_get_contents(resource_path('views/admin/partials/site-analytics-screen.blade.php'));

    expect($js)->toContain("document.addEventListener('pointerdown'")
        ->and($js)->toContain("document.addEventListener('pointermove'")
        ->and($js)->toContain('document.elementFromPoint(e.clientX, e.clientY)')
        ->and($js)->toContain("e.key !== 'ArrowUp' && e.key !== 'ArrowDown'")
        ->and($js)->toContain('aria-live="polite" data-an-say')
        ->and($js)->toContain("api('PUT', '/layout'")
        ->and($js)->toContain('Reset layout')
        ->and($js)->toContain('Hidden blocks (')
        ->and($js)->toContain("if (drag) { st.deferred = true; return; }");
    foreach (['draggable', 'dragstart', 'Sortable', 'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientHeight', 'scrollHeight', 'getComputedStyle', 'ResizeObserver', 'setInterval('] as $no) {
        expect(str_contains($js, $no))->toBeFalse($no.' is in the board');
    }
    // The owner app follows the same saved order.
    expect((string) file_get_contents(resource_path('js/owner-app/analytics.js')))->toContain('L.order.forEach((id)');
});
