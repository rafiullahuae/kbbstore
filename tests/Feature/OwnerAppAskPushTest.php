<?php

declare(strict_types=1);

use App\Services\OwnerApp\OwnerAppSettings;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnerAppRoutes as OA;

/*
 * Lane NT, part 1: the installed owner app offers "Allow notifications" after
 * the PIN (Platform → Users & Roles → Owner app → Settings → "Ask for
 * notifications when the app opens", on by default).
 *
 * The owner, 5 October: "the site app + owner app, apps should ask by default
 * about to allow notifications, after install when the open the app".
 */

beforeEach(function () {
    OA::wire($this->app);
    OwnerAppSettings::forget();
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
});

/* ------------------------------------------------------------ the setting */

it('is on by default, reaches the app in the unlock answer, and a Full Admin can switch it off', function () {
    /* DEFECT: the setting saved but never reaching the phone, or reaching it
       through a second request. MUTATION: remove 'ask_push' from
       AppController::me() -> the enrol answer has no key; change DEFAULTS
       [ASK_PUSH] to 0 -> the first expectation is red. */
    $owner = OA::admin();
    OA::member($owner, '482615');

    $r = $this->withHeaders(['X-OA' => '1'])->postJson(OA::base().'/api/enrol', ['email' => 'owner@example.com', 'pin' => '482615'])->assertOk();
    expect($r->json('ask_push'))->toBeTrue();

    $this->actingAs($owner, 'admin')->getJson('/admin-api/owner-app')->assertOk()->assertJsonPath('settings.ask_push', true);
    $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/settings', ['ask_push' => 'maybe'])->assertStatus(422);
    $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/settings', ['ask_push' => false])->assertOk()
        ->assertJsonPath('settings.ask_push', false)
        ->assertJsonPath('settings.idle_hours', 12);                     // the others untouched
    expect(DB::table('settings')->where('key', OwnerAppSettings::ASK_PUSH)->value('value'))->toBe('0');

    $state = OA::get($this, 'state', OA::cookies($r))->assertOk();
    expect($state->json('ask_push'))->toBeFalse();

    // Saving another knob leaves it off.
    $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/settings', ['idle_hours' => 6])->assertOk()->assertJsonPath('settings.ask_push', false);

    // Fails closed for a role without ownerapp.manage.
    $support = OA::admin('support', 'sara@example.com', 'Sara Support');
    $this->actingAs($support, 'admin')->putJson('/admin-api/owner-app/settings', ['ask_push' => true])->assertForbidden();
    expect(OwnerAppSettings::askPush())->toBeFalse();
});

it('puts the switch in the Settings card and sends it as a boolean with Save', function () {
    /* DEFECT: a control the owner cannot find, or a checkbox sent as
       parseInt('on') = NaN. MUTATION: drop the checkbox branch in the save
       handler -> the second expectation is red. */
    $src = (string) file_get_contents(resource_path('views/admin/partials/owner-app-access.blade.php'));
    expect(substr_count($src, 'data-set="ask_push"'))->toBe(1)
        ->and($src)->toContain("i.type === 'checkbox' ? i.checked : parseInt(i.value, 10)")
        ->and($src)->toContain('Ask for notifications when the app opens');
});

/* -------------------------------------------------------------- the sheet */

/** ask.js (and core.js under it) in node with a browser stubbed around them. */
function ntOwnerAsk(array $env): mixed
{
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        test()->markTestSkipped('node is not installed here');
    }
    $core = 'file://'.resource_path('js/owner-app/core.js');
    $ask = 'file://'.resource_path('js/owner-app/ask.js');
    $E = json_encode($env + ['standalone' => true, 'perm' => 'default', 'askPush' => true, 'npDaysAgo' => null, 'tap' => true]);
    $harness = <<<JS
const E = {$E};
const mem = () => { const m = {}; return { getItem: (k) => (k in m ? m[k] : null), setItem: (k, v) => { m[k] = String(v); }, removeItem: (k) => { delete m[k]; } }; };
const out = { asked: 0, subs: 0 };
const Notification = { permission: E.perm, requestPermission: () => { out.asked++; Notification.permission = 'granted'; return Promise.resolve('granted'); } };
globalThis.window = { sessionStorage: mem(), localStorage: mem(), Notification, PushManager: function () {},
  matchMedia: (q) => ({ matches: E.standalone && q.indexOf('standalone') !== -1, addEventListener() {} }) };
globalThis.requestAnimationFrame = (f) => f();
Object.defineProperty(globalThis, 'navigator', { value: { userAgent: 'node', platform: 'node', maxTouchPoints: 0, serviceWorker: {} }, configurable: true });
const btns = {};
const el = () => ({ classList: { add() {}, remove() {} }, style: {}, setAttribute() {}, addEventListener(t, f) { (this.on = this.on || {})[t] = f; }, remove() {},
  set innerHTML(v) { this.html = v; out.html = out.html || v; }, get innerHTML() { return this.html; }, querySelectorAll: () => [],
  querySelector(sel) { return btns[sel] || (btns[sel] = { on: {}, addEventListener(t, f) { this.on[t] = f; } }); } });
const html = { classList: { add() {}, remove() {}, contains: () => false }, style: { setProperty() {}, removeProperty() {}, getPropertyValue: () => '' } };
globalThis.document = { documentElement: html, body: { getAttribute: () => '/oa', appendChild() {} }, createElement: el, addEventListener() {}, querySelectorAll: () => [], querySelector: () => null };
const core = await import('{$core}');
const ask = await import('{$ask}');
Object.assign(core.S, { askPush: E.askPush, vapid: 'BKey', me: { notify: ['orders', 'payments', 'stock'] }, groups: { orders: 'New orders', payments: 'Failed and refunded payments', stock: 'Low and out-of-stock products', status: 'Order status changes' } });
if (E.npDaysAgo !== null) core.store.set('oa.np', Date.now() - E.npDaysAgo * 864e5);
out.shown = ask.offerPush(async () => { out.subs++; return true; });
out.before = out.asked;
if (out.shown && E.tap) { btns['[data-allow]'].on.click(); await new Promise((r) => setTimeout(r, 5)); }
out.days = ask.ASK_AGAIN_DAYS;
console.log(JSON.stringify(out));
process.exit(0);
JS;
    $file = storage_path('framework/testing/nt-oa-'.getmypid().'-'.bin2hex(random_bytes(3)).'.mjs');
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, $harness);
    $raw = (string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
    @unlink($file);

    return json_decode(trim((string) strrchr("\n".trim($raw), "\n")), true) ?? $raw;
}

it('offers the sheet only in the installed app with the permission undecided, and asks the browser only from the Allow tap', function () {
    /* DEFECT: requestPermission() on unlock without a tap (iOS refuses it,
       Chrome quietens it), or the sheet in a browser tab. MUTATION: call
       window.Notification.requestPermission() at the top of offerPush() ->
       `before` is 1; drop standalone() from shouldAsk() -> the tab case shows. */
    $o = ntOwnerAsk([]);
    expect($o, is_string($o) ? $o : '')->toBeArray()
        ->and($o['shown'])->toBeTrue()
        ->and($o['before'])->toBe(0)
        ->and($o['asked'])->toBe(1)
        ->and($o['subs'])->toBe(1)                                   // the existing syncPush path
        ->and($o['days'])->toBe(7)
        ->and($o['html'])->toContain('Allow notifications')->toContain('New orders')->not->toContain('Order status changes')
        ->and($o['html'])->not->toContain('style="');

    expect(ntOwnerAsk(['standalone' => false])['shown'])->toBeFalse();
});

it('never asks after denied or granted, holds "Not now" seven days, and stays quiet when the setting is off', function () {
    /* MUTATION: accept 'denied' in shouldAsk() -> the first line is red;
       ASK_AGAIN_DAYS = 0 -> the third. */
    expect(ntOwnerAsk(['perm' => 'denied'])['shown'])->toBeFalse()
        ->and(ntOwnerAsk(['perm' => 'granted'])['shown'])->toBeFalse()
        ->and(ntOwnerAsk(['npDaysAgo' => 6])['shown'])->toBeFalse()
        ->and(ntOwnerAsk(['npDaysAgo' => 8])['shown'])->toBeTrue()
        ->and(ntOwnerAsk(['askPush' => false])['shown'])->toBeFalse();
});

it('calls the browser\'s question from exactly one place in the owner app besides the More switch, inside the Allow handler', function () {
    /* Static pin beside the run above. MUTATION: add a requestPermission(
       call anywhere else in resources/js/owner-app -> the count is red. */
    $all = collect(glob(resource_path('js/owner-app/*.js')))
        ->map(fn ($f) => (string) preg_replace(['#/\*.*?\*/#s', '#(^|\s)//[^\n]*#'], ['', '$1'], (string) file_get_contents($f)))->implode("\n");
    expect(substr_count($all, 'requestPermission('))->toBe(2);

    $ask = (string) file_get_contents(resource_path('js/owner-app/ask.js'));
    preg_match("/\\\$\\('\\[data-allow\\]', panel\\)\\.addEventListener\\('click', \\(\\) => \\{(.*?)\\n    \\}\\);/s", $ask, $m);
    expect($m[1] ?? '')->toContain('window.Notification.requestPermission()');

    $app = (string) file_get_contents(resource_path('js/owner-app/owner-app.js'));
    expect($app)->toContain("offerPush(syncPush);")
        ->and(substr_count($app, 'setTimeout('))->toBe(2);                 // no new timer
});
