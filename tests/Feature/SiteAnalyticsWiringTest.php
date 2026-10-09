<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/*
 * Analytics reaches the router and the console only through the integrator's
 * files (routes/web.php, resources/views/admin/app.blade.php), which Lane AN
 * may not edit. docs/an-wiring.json carries the edits; tools/an-wire.php
 * applies them. These tests apply the same record in memory, so they pin the
 * FINISHED state -- green before the integrator wires it and after -- and
 * never "not wired yet" (CLAUDE.md). (Lane AN)
 */

function anWired(): array
{
    $edits = json_decode((string) file_get_contents(base_path('docs/an-wiring.json')), true, 512, JSON_THROW_ON_ERROR);
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
    /* MUTATION: change any anchor in docs/an-wiring.json by one character -> red, naming the block. */
    $w = anWired();

    expect($w['problems'])->toBe([], implode("\n", $w['problems']));
});

it('mounts the admin routes exactly once, inside the guarded admin-api group', function () {
    $web = anWired()['files']['routes/web.php'];

    expect(substr_count($web, "require __DIR__.'/site-analytics-admin.php';"))->toBe(1)
        ->and($web)->toContain("require __DIR__.'/not-found-page-admin.php';  // Safety → 404 page (Lane NF)\n        require __DIR__.'/site-analytics-admin.php';");
});

it('puts the screen in the console exactly once: include, title, late replay and sidebar row', function () {
    /* MUTATION: duplicate block 4 -> the include count is 2 and the screen wraps window.go around itself. */
    $app = anWired()['files']['resources/views/admin/app.blade.php'];

    expect(substr_count($app, "@include('admin.partials.site-analytics-screen')"))->toBe(1)
        ->and(substr_count($app, "'site-analytics':['Overview','Analytics']"))->toBe(1)
        ->and(substr_count((string) file_get_contents(app_path('Support/AdminNav.php')), "['id' => 'site-analytics', 'label' => 'Analytics', 'read' => 'admin-api/site-analytics', 'late' => true"))->toBe(1);

    expect(preg_match('/const LATE_RENDERED=new Set\(\[(.*?)\]\);/s', $app, $m))->toBe(1)
        ->and(substr_count($m[1], "'site-analytics'"))->toBe(1);
});

it('gives the Orders list a Source column and filter, and the order screen a Source panel, once each', function () {
    $app = anWired()['files']['resources/views/admin/app.blade.php'];

    expect(substr_count($app, "['payment', 'Payment'], ['source', 'Source'],"))->toBe(1)
        ->and(substr_count($app, "if(OL.source) p.set('source', OL.source);"))->toBe(1)
        ->and(substr_count($app, '<select id="olSource">'))->toBe(1)
        ->and(substr_count($app, "      case 'source':"))->toBe(1)
        ->and(substr_count($app, "odCardHead('Source')"))->toBe(1);
});

it('mounts the owner app\'s two reads inside its session group, the live one passive', function () {
    $oa = (string) file_get_contents(base_path('routes/owner-app.php'));

    expect(substr_count($oa, "Route::get('/analytics', [\\App\\Http\\Controllers\\OwnerApp\\AnalyticsController::class, 'summary'])"))->toBe(1)
        ->and(substr_count($oa, "Route::get('/analytics/live', [\\App\\Http\\Controllers\\OwnerApp\\AnalyticsController::class, 'live'])->defaults('oa_passive', true)"))->toBe(1);
});

it('schedules the minute rollup once', function () {
    $c = (string) file_get_contents(base_path('routes/console.php'));

    expect(substr_count($c, "Schedule::command('kbb:analytics-rollup')"))->toBe(1);
});

/* ── the Orders list ─────────────────────────────────────────────────── */

function anOwner(): AdminUser
{
    return AdminUser::create(['name' => 'AN owner', 'email' => 'an-ol-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']);
}

function anOrder(?string $ch, ?string $cmp = null): Order
{
    $o = Order::create(['order_number' => 'ANL-'.uniqid(), 'email' => 'x@y.z', 'status' => 'processing', 'currency' => 'AED', 'subtotal' => 1000, 'total' => 1000]);
    DB::table('orders')->where('id', $o->id)->update(['src_channel' => $ch, 'src_campaign' => $cmp]);

    return $o;
}

it('shows each order\'s source on the list, filters by it, and costs the list no extra query', function () {
    // MUTATION: load the source per row (a query in rowToApi) and the 3-vs-12
    // counts differ; drop the select columns and every chip reads Unknown.
    $admin = anOwner();
    $count = function () use ($admin): array {
        $n = 0;
        $on = true;
        // The owner app's push for a NEW order runs as the next request
        // terminates (OwnerAppServiceProvider) -- not the list's cost.
        DB::listen(function ($e) use (&$n, &$on) { if ($on && ! str_contains($e->sql, 'owner_app_')) { $n++; } });
        $j = test()->actingAs($admin, 'admin')->getJson('/admin-api/orders-list?per_page=50')->assertOk()->json();
        $on = false;

        return [$n, $j];
    };

    anOrder('instagram_ads', 'eid_sale');
    anOrder(null);
    anOrder('google');
    $count(); // warm the one-time reads
    [$small, $j] = $count();
    $chips = collect($j['orders'])->pluck('source', 'source_key')->all();
    expect($chips['instagram_ads'])->toBe('Instagram Ads · eid_sale')
        ->and($chips[''] ?? $chips[null] ?? null)->toBe('Unknown')
        ->and($j['sources']['unknown'])->toBe('Unknown')
        ->and($j['sources']['tiktok_ads'])->toBe('TikTok Ads');

    for ($i = 0; $i < 9; $i++) {
        anOrder($i % 2 ? 'tiktok_ads' : null, 'c'.$i);
    }
    [$large] = $count();
    // The number itself, so a lane that adds a query has to say so here.
    expect($large)->toBe($small)->and($small)->toBe(8);

    $only = test()->actingAs($admin, 'admin')->getJson('/admin-api/orders-list?source=instagram_ads')->json('orders');
    expect(collect($only)->pluck('source_key')->unique()->values()->all())->toBe(['instagram_ads']);
    $unknown = test()->actingAs($admin, 'admin')->getJson('/admin-api/orders-list?source=unknown&per_page=50')->json('orders');
    expect(collect($unknown)->pluck('source_key')->unique()->values()->all())->toBe([null]);
    // Anything else is ignored, not matched.
    $junk = test()->actingAs($admin, 'admin')->getJson('/admin-api/orders-list?source=%27%20OR%201%3D1&per_page=50')->json('orders');
    expect(count($junk))->toBe(count(test()->actingAs($admin, 'admin')->getJson('/admin-api/orders-list?per_page=50')->json('orders')));
});

it('shows the Source panel on the order screen, Unknown for an order nothing recorded', function () {
    $admin = anOwner();
    $o = anOrder('tiktok_ads', 'launch');
    DB::table('orders')->where('id', $o->id)->update(['src_attr' => json_encode(['first' => ['ch' => 'google', 's' => '', 'm' => '', 'c' => '', 'k' => '', 'p' => '/', 'd' => 1], 'last' => ['ch' => 'tiktok_ads', 's' => 'tiktok', 'm' => 'cpc', 'c' => 'launch', 'k' => 't', 'p' => '/p/', 'd' => 3], 'days' => 2])]);
    $u = anOrder(null);

    $s = test()->actingAs($admin, 'admin')->getJson('/admin-api/orders/'.$o->id.'/detail')->assertOk()->json('source');
    expect($s['chip'])->toBe('TikTok Ads · launch')->and($s['first']['channel'])->toBe('Google Organic')
        ->and($s['last']['click'])->toBe('ttclid')->and($s['days'])->toBe(2)
        ->and(array_keys($s))->toBe(['known', 'chip', 'channel', 'first', 'last', 'days']);
    expect(test()->actingAs($admin, 'admin')->getJson('/admin-api/orders/'.$u->id.'/detail')->json('source.chip'))->toBe('Unknown');
});
