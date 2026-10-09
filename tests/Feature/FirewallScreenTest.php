<?php

declare(strict_types=1);

/**
 * Store → Security → Firewall as tabs (Lane FW2).
 *
 * The owner: "the firewall layout at backend i don't like, don't throw just
 * classic room, i want proper tabs and sections etc." The screen is drawn by
 * script, so the drawing itself is proved in Chromium (tools/fw2-tab-shots.cjs,
 * one picture per tab at 1280 and 390); these cases pin what the server and
 * the source must keep true for that drawing to hold.
 */

use App\Models\AdminUser;
use App\Services\Security\FirewallConfig;

function fw2Partial(): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/firewall-screen.blade.php'));
}

function fw2Owner(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'FW2 '.$role, 'email' => 'fw2-'.$role.'-'.uniqid().'@example.test',
        'password' => 'password-long-enough', 'role' => $role]);
}

it('draws seven tabs in the console\'s own tab strip, each with its own renderer and sections', function () {
    /*
     * DEFECT: one long page again, or a tab in the strip with nothing behind
     * it. MUTATION: drop `countries: countriesTab` from the renderer map and
     * the Countries tab draws the Overview.
     */
    $src = fw2Partial();
    $tabs = ['overview' => 'Overview', 'live' => 'Live activity', 'rules' => 'Rules', 'countries' => 'Countries',
        'bots' => 'Good bots', 'lists' => 'Allow & block lists', 'data' => 'Data'];

    foreach ($tabs as $key => $label) {
        expect($src)->toContain("['{$key}', '{$label}']")
            ->and(substr_count($src, $key.': '.$key.'Tab'))->toBe(1)
            ->and($src)->toContain('function '.$key.'Tab()');
    }

    // The console's components, not a new style: its tab strip, section
    // headings over cards, stat tiles, the mode switch, filter chips, toggles.
    foreach (['class="subtabs"', 'class="subtab', 'class="sec-title"', 'class="card pad"', 'class="kpis"', 'class="seg"',
        'class="chip', 'class="pref"', 'class="tog'] as $part) {
        expect($src)->toContain($part);
    }

    // A Save per section where a section saves: scope, flood, bans, protect,
    // fake, countries, bots.
    foreach (['scope', 'flood', 'bans', 'protect', 'fake', 'countries', 'bots'] as $save) {
        expect($src)->toContain("'".$save."'");
    }

    expect($src)->not->toContain('setInterval')->not->toContain('setTimeout')
        ->not->toContain('getBoundingClientRect')->not->toContain('offsetWidth');
});

it('keeps the tab in the address and opens a deep link at its tab', function () {
    /*
     * DEFECT: a reload or a shared link lands on Overview whatever tab it named.
     * MUTATION: make hashTab() return null and #firewall/countries opens Overview.
     */
    $src = fw2Partial();

    expect($src)->toContain("/^#firewall\\/([a-z]+)$/")
        ->and($src)->toContain("history.replaceState(null, '', location.pathname + location.search + '#firewall/' + tab)")
        ->and($src)->toContain("tab = hashTab() || tab;")
        ->and($src)->toContain("window.addEventListener('hashchange'");

    // The console names the screen, so ?go=firewall and #firewall/<tab> route to it.
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($app, "'firewall':['Store → Security','Firewall']"))->toBe(1);
});

it('loads the Live activity lists only when that tab opens: the screen\'s one GET carries the totals and bans only', function () {
    /*
     * DEFECT: opening the screen runs the 24-hour per-address aggregation on
     * every visit, whichever tab the owner wanted. MUTATION: put 'live' back
     * into FirewallApiController::show() and `live` is present.
     */
    $owner = fw2Owner();
    $r = test()->actingAs($owner, 'admin')->getJson('/admin-api/security/firewall')->assertOk();

    expect($r->json())->not->toHaveKey('live')
        ->and($r->json('summary'))->toHaveKeys(['refused', 'logged', 'bans'])
        ->and($r->json('fields.0.key'))->toBe('mode');

    $live = test()->actingAs($owner, 'admin')->getJson('/admin-api/security/firewall/live')->assertOk();
    expect($live->json('live'))->toHaveKeys(['reasons', 'countries', 'ips', 'nets', 'bans']);

    $src = fw2Partial();
    expect($src)->toContain("if (tab === 'live' && live === null)")
        ->and($src)->toContain("else if (tab === 'lists' && blocks === null)");
});

it('saves one section at a time, through the same endpoint and capability', function () {
    /*
     * DEFECT: Save on "Ban length" also writes the flood limits from stale
     * screen state. The endpoint takes only the keys sent; this pins that a
     * part-save leaves the rest alone. Capabilities are unchanged: a manager
     * is still refused.
     */
    $owner = fw2Owner();
    FirewallConfig::save(['ip_10s' => 90]);

    test()->actingAs($owner, 'admin')->postJson('/admin-api/security/firewall', ['values' => ['ban_minutes' => 30]])->assertOk();
    $s = FirewallConfig::all()['settings'];
    expect($s['ban_minutes'])->toBe(30)->and($s['ip_10s'])->toBe(90)->and($s['mode'])->toBe('monitor');

    app('auth')->forgetGuards();
    $manager = fw2Owner('manager');
    test()->actingAs($manager, 'admin')->getJson('/admin-api/security/firewall')->assertForbidden();
    test()->actingAs($manager, 'admin')->postJson('/admin-api/security/firewall', ['values' => ['ban_minutes' => 5]])->assertForbidden();
});
