<?php

declare(strict_types=1);

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Support\AdminCapabilities;
use App\Support\AdminRoles;
use App\Support\EditPresence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\AdminRolesRoutes;

/**
 * Edit presence + Take over (Lane RL).
 *
 * The owner: "if any user is editing something ... it should notify to another
 * user if he wants to edit the same. and have function to take control over
 * it ... real time, and super light".
 *
 * The defect each case catches is what happened before this lane: two people
 * opened the same product, both pressed Save, and the second silently erased
 * the first. Every case says what it would look like on the shop and how to
 * make it go red.
 */
beforeEach(function () {
    AdminRolesRoutes::wire(app());
});

function epUser(string $name, string $role = 'owner', ?string $preset = null): AdminUser
{
    $u = AdminUser::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
    if ($preset !== null) {
        $u->forceFill(['role_id' => AdminRole::query()->where('slug', $preset)->value('id')])->save();
    }

    return $u->fresh();
}

function epBeat(AdminUser $as, ?string $token = null, string $type = 'product', string $id = '42'): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($as, 'admin')->postJson('/admin-api/presence/beat', ['type' => $type, 'id' => $id] + ($token ? ['token' => $token] : []));
}

/** A guarded save: PUT /admin-api/products/{id}. The guard answers before the controller. */
function epSave(AdminUser $as, ?string $token = null, string $id = '42'): \Illuminate\Testing\TestResponse
{
    // Headers per request, not withHeaders(): that one sticks to every later request in the test.
    return test()->actingAs($as, 'admin')
        ->putJson('/admin-api/products/'.$id, ['name' => 'x'], $token ? [EditPresence::HEADER => $token] : []);
}

it('shows B, at once, that A is editing — the name and how long, nothing else', function () {
    /*
     * Defect: B opened a product A had open and saw nothing. MUTATION: return
     * the answer without the holder in EditPresence::beat()'s last line and
     * B's banner has nobody to name.
     */
    $a = epUser('Sara Ahmed');
    $b = epUser('Omar Haddad', 'manager', 'sub-admin');

    $first = epBeat($a)->assertOk()->json();
    expect($first['you_hold'])->toBeTrue()->and($first['token'])->toMatch('/^[a-f0-9]{32}$/');

    test()->travel(120)->seconds();
    epBeat($a, $first['token'])->assertOk()->assertJsonPath('you_hold', true);
    $seen = epBeat($b)->assertOk()->json();

    expect($seen['you_hold'])->toBeFalse()
        ->and($seen['holder'])->toBe('Sara Ahmed')
        ->and($seen['since'])->toBeGreaterThanOrEqual(120)
        ->and($seen['can_take'])->toBeTrue()
        ->and($seen)->not->toHaveKey('token');
});

it('never answers with an email, an id or anybody else\'s token', function () {
    /*
     * The heartbeat is reachable by every role. Defect it guards: returning the
     * lock row (or the holder model) hands a Customer Support account every
     * admin's email and A's lock token. MUTATION: `return (array) $row;` in
     * beat() and the key allowlist goes red.
     */
    $a = epUser('Sara Ahmed');
    $b = epUser('Lina Park', 'support');
    $tokenA = epBeat($a)->json('token');

    $res = epBeat($b)->assertOk();
    expect(array_keys($res->json()))->toEqualCanonicalizing(['holder', 'since', 'you_hold', 'taken_over_by', 'can_take'])
        ->and($res->getContent())->not->toContain('@')
        ->and($res->getContent())->not->toContain($tokenA)
        ->and($res->getContent())->not->toContain('"'.$a->id.'"');
    expect($res->headers->get('Cache-Control'))->toContain('no-store');
});

it('lets a holder of presence.takeover take over, and refuses everybody else', function () {
    /*
     * Defect it guards: Take over by anybody would let a Content Editor kick
     * the owner off a product. MUTATION: map admin-api/presence/take to
     * presence.view in AdminCapabilities::RULES and the manager's 403 is a 200.
     */
    $a = epUser('Sara Ahmed', 'editor');
    $manager = epUser('Store Manager Person', 'manager');
    $sub = epUser('Omar Haddad', 'manager', 'sub-admin');
    epBeat($a)->assertOk();

    expect(epBeat($manager)->json('can_take'))->toBeFalse();
    test()->actingAs($manager, 'admin')->postJson('/admin-api/presence/take', ['type' => 'product', 'id' => '42'])->assertForbidden();

    $took = test()->actingAs($sub, 'admin')->postJson('/admin-api/presence/take', ['type' => 'product', 'id' => '42'])->assertOk()->json();
    expect($took['you_hold'])->toBeTrue()->and($took['token'])->toMatch('/^[a-f0-9]{32}$/');

    // The owner always can, whatever the presets say.
    $owner = epUser('Rafi Owner');
    test()->actingAs($owner, 'admin')->postJson('/admin-api/presence/take', ['type' => 'product', 'id' => '42'])->assertOk()->assertJsonPath('you_hold', true);
});

it('tells A within one beat that B took over, and refuses A\'s stale save with a 409 naming B', function () {
    /*
     * THE DEFECT THE OWNER IS PAYING FOR: A's tab still has the old text, A
     * presses Save, and B's work is gone. Refused server-side — with A's token
     * (taken_over), and without any token at all (edit_locked), which is the
     * save from a tab whose script never noticed.
     * MUTATION: delete the EditPresence::refuseSave() call from
     * EnforceAdminCapability and both saves go through.
     */
    $a = epUser('Sara Ahmed');   // the owner: the lock binds the owner as well
    $b = epUser('Omar Haddad', 'manager', 'sub-admin');
    $tokenA = epBeat($a)->json('token');
    test()->actingAs($b, 'admin')->postJson('/admin-api/presence/take', ['type' => 'product', 'id' => '42'])->assertOk();

    epBeat($a, $tokenA)->assertOk()
        ->assertJsonPath('you_hold', false)
        ->assertJsonPath('taken_over_by', 'Omar Haddad');

    epSave($a, $tokenA)->assertStatus(409)
        ->assertJsonPath('error', 'taken_over')
        ->assertJsonPath('holder', 'Omar Haddad');
    epSave($a)->assertStatus(409)->assertJsonPath('error', 'edit_locked');

    // And B, who holds it, is not refused by the lock.
    expect(epSave($b)->status())->not->toBe(409);
});

it('keeps a taken-over tab refused after the taker has left, until it reloads', function () {
    /*
     * Defect: B takes over, saves, closes the tab; A's stale tab saves next and
     * erases B's save, because nobody "holds" the record any more. MUTATION:
     * make release() delete the row and A's save is a 200 that overwrites B.
     */
    $a = epUser('Sara Ahmed');
    $b = epUser('Omar Haddad');
    $tokenA = epBeat($a)->json('token');
    $tokenB = test()->actingAs($b, 'admin')->postJson('/admin-api/presence/take', ['type' => 'product', 'id' => '42'])->json('token');
    test()->actingAs($b, 'admin')->postJson('/admin-api/presence/release', ['type' => 'product', 'id' => '42', 'token' => $tokenB])->assertOk();

    epSave($a, $tokenA)->assertStatus(409)->assertJsonPath('error', 'taken_over');
    epBeat($a, $tokenA)->assertJsonPath('you_hold', false)->assertJsonPath('taken_over_by', 'Omar Haddad');

    // A reloaded: a fresh open, no token, and the record is A's again.
    $fresh = epBeat($a)->json();
    expect($fresh['you_hold'])->toBeTrue();
    expect(epSave($a, $fresh['token'])->status())->not->toBe(409);
});

it('frees a lock nobody has beaten for 45 seconds', function () {
    /*
     * Defect: a closed laptop locks a product for ever. MUTATION: set TTL to
     * 3600 and B is still blocked after 46 seconds.
     */
    $a = epUser('Sara Ahmed');
    $b = epUser('Omar Haddad', 'manager');
    epBeat($a);

    test()->travel(44)->seconds();
    expect(epBeat($b)->json('you_hold'))->toBeFalse();
    expect(epSave($b)->status())->toBe(409);

    test()->travel(2)->seconds();
    expect(epBeat($b)->json('you_hold'))->toBeTrue();
});

it('frees the lock the moment the tab leaves', function () {
    /*
     * pagehide sends /presence/release. MUTATION: make release() a no-op and B
     * waits out the TTL instead of getting the record now.
     */
    $a = epUser('Sara Ahmed');
    $b = epUser('Omar Haddad', 'manager');
    $tokenA = epBeat($a)->json('token');

    test()->actingAs($a, 'admin')->postJson('/admin-api/presence/release', ['type' => 'product', 'id' => '42', 'token' => $tokenA])->assertOk();

    expect(epBeat($b)->json('you_hold'))->toBeTrue();
});

it('lets a beat refresh only its own lock, and never with a forged or old token', function () {
    /*
     * Defect: a heartbeat that refreshes "the lock on product 42" whoever sends
     * it keeps a lock alive for a tab that left long ago, and a guessed token
     * would let anybody hold or release somebody else's record.
     * MUTATION: drop ->where('admin_id', $me->id) from the refresh UPDATE and
     * B's beat with A's token reports you_hold = true.
     */
    $a = epUser('Sara Ahmed');
    $b = epUser('Omar Haddad', 'manager');
    $tokenA = epBeat($a, null, 'product', '42')->json('token');
    epBeat($a, null, 'product', '7');

    // B with A's own token, and B with a forged one: neither holds.
    epBeat($b, $tokenA)->assertJsonPath('you_hold', false)->assertJsonPath('holder', 'Sara Ahmed');
    epBeat($b, str_repeat('ab', 16))->assertJsonPath('you_hold', false);
    expect(epSave($b, str_repeat('ab', 16))->status())->toBe(409);

    // B's release with a token that is not B's frees nothing.
    test()->actingAs($b, 'admin')->postJson('/admin-api/presence/release', ['type' => 'product', 'id' => '42', 'token' => $tokenA])->assertOk();
    expect(epBeat($b)->json('you_hold'))->toBeFalse();

    // A's beat on 42 leaves 7 alone: 7 still ages out on its own clock.
    test()->travel(30)->seconds();
    epBeat($a, $tokenA)->assertJsonPath('you_hold', true);
    $beats = DB::table(EditPresence::TABLE)->where('resource_type', 'product')->pluck('heartbeat_at', 'resource_id');
    expect((string) $beats['42'])->not->toBe((string) $beats['7']);

    // Malformed tokens never reach the database.
    test()->actingAs($b, 'admin')->postJson('/admin-api/presence/beat', ['type' => 'product', 'id' => '42', 'token' => "x' OR 1=1"])->assertStatus(422);
    test()->actingAs($b, 'admin')->postJson('/admin-api/presence/beat', ['type' => 'users', 'id' => '1'])->assertStatus(422);
});

it('costs one query for the holder, two for anybody else, and one on any other screen', function () {
    /*
     * "super light ... blazing speed". MUTATION: read the row before the
     * refresh UPDATE in beat() and the holder's count is 2.
     */
    $a = epUser('Sara Ahmed');
    $b = epUser('Omar Haddad', 'manager');
    $token = EditPresence::beat($a, 'product', '42', null)['token'];

    $count = function (callable $fn) {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $fn();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    // A beat is 15 seconds after the last one. MySQL counts an UPDATE that
    // writes the value already there as 0 rows changed, so a second beat in
    // the SAME second takes the slower (still correct) path -- travel a
    // second, as the real interval always does.
    test()->travel(1)->seconds();
    expect($count(fn () => EditPresence::beat($a, 'product', '42', $token)))->toBe(1)
        ->and($count(fn () => EditPresence::beat($b, 'product', '42', null)))->toBe(2)
        ->and($count(fn () => EditPresence::beat($b, null, null, null)))->toBe(1);

    // A request that is not a guarded save pays nothing for the guard.
    DB::enableQueryLog();
    DB::flushQueryLog();
    test()->actingAs($a, 'admin')->getJson('/admin-api/roles');
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, EditPresence::TABLE))->all())->toBe([]);
    DB::disableQueryLog();
});

it('shows who is online now on the Members tab, from the same beats, in one query', function () {
    /*
     * MUTATION: return [] from EditPresence::onlineMap() and Sara's dot is gone.
     */
    $owner = epUser('Rafi Owner');
    $sara = epUser('Sara Ahmed', 'manager');
    $lina = epUser('Lina Park', 'support');
    epBeat($sara);
    test()->actingAs($lina, 'admin')->postJson('/admin-api/presence/beat', [])->assertOk();

    $members = collect(test()->actingAs($owner, 'admin')->getJson('/admin-api/roles/members')->assertOk()->json('members'))->keyBy('id');

    expect($members[$sara->id]['online'])->toBeTrue()
        ->and($members[$sara->id]['editing'])->toBe('product')
        ->and($members[$lina->id]['online'])->toBeTrue()
        ->and($members[$lina->id]['editing'])->toBeNull();

    test()->travel(EditPresence::ONLINE + 1)->seconds();
    $later = collect(test()->actingAs($owner, 'admin')->getJson('/admin-api/roles/members')->json('members'))->keyBy('id');
    expect($later[$sara->id]['online'])->toBeFalse();
});

it('guards a save route that really exists, for every entry in GUARDED', function () {
    /*
     * The guard keys on the route's own URI. A typo there is a record nobody
     * is protected on, silently. MUTATION: spell one 'admin-api/product/{id}'
     * and this names it.
     */
    $have = [];
    foreach (Route::getRoutes()->getRoutes() as $r) {
        foreach ($r->methods() as $m) {
            $have[$m.' '.$r->uri()] = true;
        }
    }
    foreach (array_keys(EditPresence::GUARDED) as $key) {
        expect(isset($have[$key]))->toBeTrue("no route {$key}");
    }
    foreach (EditPresence::GUARDED as [$type]) {
        expect(EditPresence::TYPES)->toHaveKey($type);
    }
});

it('gives every preset the heartbeat and only Full Admin and Sub Admin the take-over', function () {
    /*
     * MUTATION: drop 'presence.takeover' from AdminRoles::SUB_ADMIN_EXTRA and
     * the Sub Admin line goes red.
     */
    foreach (array_keys(AdminRoles::PRESETS) as $slug) {
        $caps = AdminRoles::presetDefault($slug);
        expect($caps)->toContain('presence.view');
        expect(in_array('presence.takeover', $caps, true))->toBe(in_array($slug, ['full-admin', 'sub-admin'], true), $slug);
    }
    expect(AdminCapabilities::forPath('POST', 'admin-api/presence/take'))->toBe('presence.takeover')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/presence/beat'))->toBe('presence.view')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/presence/release'))->toBe('presence.view');
});

it('refuses the heartbeat to a signed-out browser and to a role without presence.view', function () {
    test()->postJson('/admin-api/presence/beat', ['type' => 'product', 'id' => '42'])->assertStatus(401);

    $u = epUser('No Presence', 'support');
    $u->forceFill(['revokes' => ['presence.view']])->save();
    test()->actingAs($u->fresh(), 'admin')->postJson('/admin-api/presence/beat', [])->assertForbidden();
});

/* ------------------------------------------------------------ the console */

function epPartial(): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/edit-presence.blade.php'));
}

it('is included in the console exactly once, after window.go exists', function () {
    /*
     * Zero is "built, never wired up"; two would wrap fetch twice and send
     * every beat twice. RED IN THE LANE'S OWN WORKTREE BY DESIGN until the
     * integrator adds the include (CLAUDE.md: pin the finished state).
     */
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $inc = "@include('admin.partials.edit-presence')";

    expect(substr_count($app, $inc))->toBe(1)
        ->and(strpos($app, $inc))->toBeGreaterThan(strpos($app, 'window.go=go;'));
});

it('beats only while the tab is visible, stops on hide, and releases on pagehide', function () {
    /*
     * "no timer that never stops" (CLAUDE.md, Super light). MUTATION: replace
     * setTimeout with setInterval, or drop the visibility check, and this fails.
     */
    $js = epPartial();

    expect($js)->not->toContain('setInterval')
        ->and($js)->toContain("document.visibilityState !== 'hidden'")
        ->and($js)->toContain("addEventListener('visibilitychange'")
        ->and($js)->toContain("addEventListener('pagehide'")
        ->and($js)->toContain('keepalive')
        ->and($js)->toContain("'X-XSRF-TOKEN': cookie('XSRF-TOKEN')")
        ->and($js)->toContain('EDIT_MS = 15000');
    foreach (['getBoundingClientRect', 'offsetHeight', 'offsetWidth', 'clientHeight', 'scrollHeight', 'getComputedStyle', 'ResizeObserver'] as $api) {
        expect($js)->not->toContain($api);
    }
});

it('escapes every name the server sends before it reaches the banner', function () {
    // A display name is typed by a person. MUTATION: write `'<b>' + d.holder`.
    $js = epPartial();

    expect(preg_match('/\+\s*d\.(holder|taken_over_by)\b/', $js))->toBe(0)
        ->and(substr_count($js, 'esc(d.holder)'))->toBeGreaterThanOrEqual(2)
        ->and(substr_count($js, 'esc(d.taken_over_by)'))->toBeGreaterThanOrEqual(2);
});
