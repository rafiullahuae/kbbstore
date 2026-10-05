<?php

declare(strict_types=1);

/*
 * The owner app's refresh and loading behaviour (Lane OA2), and the client
 * half of the CSRF protocol the security review set.
 *
 * The owner: "the auto refresh function should work silently without any
 * loading preview. i would prefer to grey bars type. but not on every open
 * page ... if user open after 40 minutes or 1 hour, it should give loading
 * bars and instantly sync all. also keep the refresh icon on the top header,
 * and always available to fetch / sync".
 *
 * Each test names the defect it would catch and the change that turns it red.
 */

use App\Services\OwnerApp\OwnerAppSettings;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    OA::wire($this->app);
    OwnerAppSettings::forget();
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
});

/** Code only: the comments explain the rules in words the pins would match. */
function oa2Code(string $file): string
{
    return (string) preg_replace(['#/\*.*?\*/#s', '#(^|\s)//[^\n]*#'], ['', '$1'], (string) file_get_contents(resource_path('js/owner-app/'.$file)));
}

/** The body of one top-level JS function, by name. */
function oa2Fn(string $file, string $name): string
{
    $code = oa2Code($file);
    preg_match('/(?:export\s+)?(?:async\s+)?function\s+'.preg_quote($name, '/').'\s*\([^)]*\)\s*\{(.*?)\n\}/s', $code, $m);

    return $m[1] ?? '';
}

/**
 * Runs core.js in node with a browser stubbed around it, then `$body` (an
 * async function body that may use `core`, `seen` and `store`); returns what
 * the body returns, as JSON.
 */
function oa2Node(string $body): mixed
{
    $core = 'file://'.resource_path('js/owner-app/core.js');
    $harness = <<<JS
const seen = [];
const mem = (name) => { const m = {}; return { name, m, getItem: (k) => (k in m ? m[k] : null), setItem: (k, v) => { m[k] = String(v); }, removeItem: (k) => { delete m[k]; } }; };
const session = mem('session'), local = mem('local');
session.setItem('oa.k', 'from-tab');
globalThis.window = { sessionStorage: session, localStorage: local, matchMedia: () => ({ matches: false, addEventListener() {} }) };
Object.defineProperty(globalThis, 'navigator', { value: { userAgent: 'node', platform: 'node', maxTouchPoints: 0 }, configurable: true });
globalThis.document = { body: { getAttribute: () => '/oa' }, addEventListener() {}, querySelectorAll: () => [], querySelector: () => null };
let reply = { status: 200, json: { ok: true } };
globalThis.fetch = async (url, init) => { seen.push({ url, method: init.method, headers: init.headers }); const r = reply; return { ok: r.status < 400, status: r.status, json: async () => r.json }; };
const setReply = (r) => { reply = r; };
const store = { session: session.m, local: local.m };
const core = await import('{$core}');
const out = await (async () => { {$body} })();
console.log(JSON.stringify(out));
JS;
    $file = storage_path('framework/testing/oa2-core-'.getmypid().'-'.bin2hex(random_bytes(3)).'.mjs');
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, $harness);
    $raw = (string) shell_exec('node '.escapeshellarg($file).' 2>&1');
    @unlink($file);

    return json_decode(trim((string) strrchr("\n".trim($raw), "\n")), true) ?? $raw;
}

/* ------------------------------------------------------------ the setting */

it('validates "Show loading bars after (minutes)" on the server and clamps whatever reaches the table', function () {
    // DEFECT: a threshold of 0 (bars on every open — the thing he asked to
    // stop) or 100000 (bars never), or a string, saved from the admin screen.
    // MUTATION: drop 'min:5' from OwnerAppAdminController::settings() and the
    // 4-minute PUT answers 200; change BOUNDS[STALE] to [0, 240] and the
    // direct put of -3 stores 0.
    $owner = OA::admin();

    $this->actingAs($owner, 'admin')->getJson('/admin-api/owner-app')->assertOk()
        ->assertJsonPath('settings.stale_minutes', 30);

    foreach ([4, 241, 'soon', 12.5] as $bad) {
        $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/settings', ['stale_minutes' => $bad])->assertStatus(422);
    }
    expect(DB::table('settings')->where('key', OwnerAppSettings::STALE)->exists())->toBeFalse();

    $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/settings', ['stale_minutes' => 45])->assertOk()
        ->assertJsonPath('settings.stale_minutes', 45)
        ->assertJsonPath('settings.idle_hours', 12);
    expect(DB::table('settings')->where('key', OwnerAppSettings::STALE)->value('value'))->toBe('45');

    // Saving the other two leaves it where it was.
    $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/settings', ['idle_hours' => 6])->assertOk()
        ->assertJsonPath('settings.stale_minutes', 45);

    OwnerAppSettings::put([OwnerAppSettings::STALE => -3]);
    expect(OwnerAppSettings::staleMinutes())->toBe(5);
    OwnerAppSettings::put([OwnerAppSettings::STALE => 99999]);
    expect(OwnerAppSettings::staleMinutes())->toBe(240);

    // Fails closed for a role without ownerapp.manage.
    $support = OA::admin('support', 'sara@example.com', 'Sara Support');
    $this->actingAs($support, 'admin')->putJson('/admin-api/owner-app/settings', ['stale_minutes' => 60])->assertForbidden();
    expect(OwnerAppSettings::staleMinutes())->toBe(240);
});

it('delivers the threshold to the app with its state, and no longer computes the overlay counts', function () {
    // DEFECT: the setting saved but never reaching the phone (the app keeps
    // its built-in 30), or the three COUNT queries the old "Refreshing the
    // app" card needed still running on every open. MUTATION: remove
    // 'stale_minutes' from AppController::me() and the first two
    // expectations are red; restore `+ ['pulse' => ...]` and the last is.
    OwnerAppSettings::put([OwnerAppSettings::STALE => 50]);
    OA::member(OA::admin(), '482615');                              // six digits: the PIN minimum

    $r = $this->withHeaders(['X-OA' => '1'])->postJson(OA::base().'/api/enrol', ['email' => 'owner@example.com', 'pin' => '482615'])->assertOk();
    expect($r->json('stale_minutes'))->toBe(50);

    $state = OA::get($this, 'state', OA::cookies($r))->assertOk();
    expect($state->json('stage'))->toBe('app')
        ->and($state->json('stale_minutes'))->toBe(50)
        ->and($state->json())->not->toHaveKey('pulse');
});

it('puts the setting beside "Lock after (hours)" on Platform → Users & Roles → Owner app', function () {
    // DEFECT: a setting the owner cannot find. MUTATION: drop the label.
    $src = (string) file_get_contents(resource_path('views/admin/partials/owner-app-access.blade.php'));
    $lock = strpos($src, 'Lock after (hours unused)');
    $bars = strpos($src, 'Show loading bars after (minutes)');

    expect($lock)->toBeInt()->and($bars)->toBeInt()->and($bars)->toBeGreaterThan($lock)
        ->and($src)->toContain('min="5" max="240" data-set="stale_minutes"')
        ->and($src)->toContain('New PIN (6–8 digits)');
});

/* -------------------------------------------------- no overlay, grey bars */

it('has no "Refreshing the app" overlay left anywhere', function () {
    // DEFECT: the blurred card he asked to be rid of, on open, resume or unlock.
    // MUTATION: put back overlay() in owner-app.js or the .rf rules.
    $js = collect(glob(resource_path('js/owner-app/*.js')))->map(fn ($f) => oa2Code(basename($f)))->implode("\n");
    $css = (string) file_get_contents(resource_path('css/owner-app/owner-app.css'));

    expect($js)->not->toContain('Refreshing the app')->not->toContain('overlay(')->not->toContain("'rf'")->not->toContain('rf-card')
        ->and($css)->not->toContain('.rf {')->not->toContain('.rf-card')->not->toContain('backdrop-filter: blur(')
        ->and($css)->toContain('prefers-reduced-motion: reduce');
});

it('draws grey bars in each screen\'s own shape when what it holds is stale, and real data at once when fresh', function () {
    // DEFECT: one generic grey slab (the old .skel) that jumps when the real
    // rows land, a screen that shows bars on every open, or one that never
    // does. MUTATION: replace `isFresh('orders')` with `true` in renderOrders
    // (never bars) or drop the isFresh() branch in renderProduct (bars on
    // every open) and that row is red.
    $screens = [
        ['store.js', 'renderDashboard', "isFresh('dash')", 'dashSkel()'],
        ['store.js', 'renderNotifications', "isFresh('notes')", "'<div class=\"row nt\">' + blk('tone')"],
        ['orders.js', 'renderOrders', "isFresh('orders')", 'listShell(held)'],
        ['orders.js', 'loadDetail', "isFresh('order:' + id)", 'orderSkel()'],
        ['products.js', 'renderProducts', "isFresh('products')", 'times(7, rowSkel)'],
        ['products.js', 'renderProduct', "isFresh('product:' + id)", "'<div class=\"gal\">'"],
        ['customers.js', 'renderCustomers', "isFresh('customers')", "'<div class=\"row\">' + blk('av')"],
        ['customers.js', 'renderCustomer', "isFresh('customer:' + id)", "'<section class=\"card cu-h\">' + blk('av xl')"],
    ];
    foreach ($screens as [$file, $fn, $fresh, $skel]) {
        $body = oa2Fn($file, $fn);
        expect($body)->not->toBe('')->toContain($fresh)->toContain($skel);    // fresh: data at once; else bars in its shape
    }

    // Each skeleton is built from the real row / card classes, so it is the
    // content's height (measured in docs/oa2-shots/numbers.txt).
    expect(oa2Code('orders.js'))->toContain("'<div class=\"row ord\">' + blk('av')")
        ->and(oa2Code('store.js'))->toContain("'<section class=\"card sk-hero\">")->toContain('<div class="sk-chart">')
        ->and(oa2Code('core.js'))->not->toContain('const skel');

    $css = (string) file_get_contents(resource_path('css/owner-app/owner-app.css'));
    expect($css)->toContain('.sk::after')->toContain('@keyframes shim')
        ->and($css)->toMatch('/@media \(prefers-reduced-motion: reduce\) \{\s*\*, \*::before, \*::after \{ animation: none !important;/');
});

it('swaps fresh values into the screen in place, so a silent refresh keeps the scroll position', function () {
    // DEFECT: a silent refresh that rebuilds the scroller and throws him back
    // to the top of a long order. MUTATION: in core.js screen(), always take
    // the `host.innerHTML = head + ...` branch.
    $screen = oa2Fn('core.js', 'screen');
    expect($screen)->toContain("const b = same ? \$(':scope > .body', host) : null;")
        ->toContain('b.innerHTML = body;');

    foreach ([['store.js', 'fetchDashboard'], ['orders.js', 'fetchOrder'], ['products.js', 'fetchProduct'], ['customers.js', 'fetchCustomer'], ['store.js', 'fetchNotifications']] as [$file, $fn]) {
        // Never lands after a lock; paints only where the record is shown.
        expect(oa2Fn($file, $fn))->toContain('if (g !== S.gen) return false;')->toContain('onScreen(');
    }
});

/* ---------------------------------------------- resume, sync now, timers */

it('decides silent-or-bars from the age of the last sync when the page comes back, with no timer of its own', function () {
    // DEFECT: a second interval ticking to age the data (battery, and the
    // owner's "super light"), or a resume that always shows bars. MUTATION:
    // make resume() call show() unconditionally, or add a setTimeout keyed on
    // staleMs, and this is red.
    $app = oa2Code('owner-app.js');
    $resume = oa2Fn('owner-app.js', 'resume');

    expect($resume)->toContain('const stale = !freshHere();')
        ->toContain('if (stale) show().catch(() => {});')
        ->toContain('sync(false, !stale);')
        ->and($app)->toContain("document.addEventListener('visibilitychange', () => {")
        ->toContain("window.addEventListener('pageshow', (e) => { if (e.persisted && S.stage === 'app') resume(); });")
        ->and(substr_count($app, 'setTimeout('))->toBe(2)                // the poll, and the spin's one-turn minimum
        ->and($app)->toContain('}, POLL_MS);')
        ->and(collect(glob(resource_path('js/owner-app/*.js')))->map(fn ($f) => oa2Code(basename($f)))->implode("\n"))->not->toContain('setInterval');

    expect(oa2Code('core.js'))->toContain('export const isFresh = (k) => !!AT[k] && Date.now() - AT[k] < S.staleMs;');
});

it('keeps "Sync now" in every screen header, one sync at a time, failing softly', function () {
    // DEFECT: a second tap starting a second burst of requests, a header with
    // no way to sync, or a failure that blanks the screen. MUTATION: delete
    // `if (syncing) return syncing;` and the guard pin is red; drop the
    // syncBtn() call from top() and the header pin is red.
    $sync = oa2Fn('owner-app.js', 'sync');
    expect($sync)->toContain('if (syncing) return syncing;')
        ->toContain('syncing = null;')
        ->toContain("toast('Could not sync everything. Showing what you had.', true)")
        ->and(oa2Code('owner-app.js'))->toContain("if (e.target.closest('[data-sync]')) { sync(true); return; }");

    $core = oa2Code('core.js');
    expect($core)->toContain("(/\\bnosync\\b/.test(cls || '') ? '' : syncBtn('plain'))")
        ->toContain('aria-label="Sync now"')
        ->and(oa2Code('store.js'))->toContain("'<div class=\"hdr-acts\">' + syncBtn()");

    $css = (string) file_get_contents(resource_path('css/owner-app/owner-app.css'));
    expect($css)->toContain('.ib.sync::before { content: ""; position: absolute; inset: -2px;')   // 40px + 2×2px = 44px to touch
        ->toContain('.ib.sync.on .i { animation: spin .9s linear infinite; }');
});

it('asks for each section once per sync, however many triggers arrive together', function () {
    // DEFECT: a request storm — the screen's own load, the resume sync and a
    // tap of Sync now each fetching the same list. MUTATION: make once()
    // always call fn() and `calls` is 3.
    $out = oa2Node(<<<'JS'
let calls = 0;
const job = () => new Promise((res) => setTimeout(() => { calls++; res(calls); }, 5));
const a = core.once('orders', job), b = core.once('orders', job), c = core.once('orders', job);
await Promise.all([a, b, c]);
const forced = await core.once('orders', job, true);
core.S.staleMs = 60000;
core.landed('dash');
const fresh = core.isFresh('dash'), unknown = core.isFresh('orders');
core.S.staleMs = 0;
const stale = core.isFresh('dash');
core.S.staleMs = 60000;
const gen = core.S.gen;
core.resetData();
return { calls, forced, fresh, unknown, stale, afterReset: core.isFresh('dash'), genMoved: core.S.gen === gen + 1 };
JS);

    expect($out)->toBe(['calls' => 2, 'forced' => 2, 'fresh' => true, 'unknown' => false, 'stale' => false, 'afterReset' => false, 'genMoved' => true]);
});

/* -------------------------------------------- the CSRF protocol, client side */

it('sends the key on every request but state, enrol and unlock, and keeps it in this tab only', function () {
    // (a) and (b) of the security review. DEFECT: a GET without the key (the
    // server now refuses it), the key sent to state/enrol/unlock, or the key
    // in localStorage where it outlives the tab. MUTATION: restore
    // `if (method !== 'GET' && S.csrf)` in api() and the orders GET loses its
    // header; write the key with store.set and `local` is not empty.
    $out = oa2Node(<<<'JS'
const startKey = core.S.csrf;
await core.api('GET', 'orders?status=processing');
await core.api('POST', 'orders/7/status', { status: 'completed' });
await core.api('GET', 'state');
await core.api('POST', 'unlock', { pin: '000000' });
await core.api('POST', 'enrol', {});
core.setKey('fresh-key');
const kept = { session: { ...store.session }, local: { ...store.local } };
core.setKey(null);
const h = (i) => seen[i].headers['X-OA-CSRF'] || null;
return { startKey, orders: h(0), write: h(1), state: h(2), unlock: h(3), enrol: h(4), kept, cleared: store.session['oa.k'] || null, mem: core.S.csrf };
JS);

    expect($out)->toBe([
        'startKey' => 'from-tab',
        'orders' => 'from-tab', 'write' => 'from-tab', 'state' => null, 'unlock' => null, 'enrol' => null,
        'kept' => ['session' => ['oa.k' => 'fresh-key'], 'local' => []],
        'cleared' => null, 'mem' => null,
    ]);

    // The key comes only from enrol / unlock (state no longer carries one),
    // and every way out drops it along with the shop data held in memory.
    $app = oa2Code('owner-app.js');
    expect(oa2Fn('owner-app.js', 'onIn'))->toContain('setKey(data.csrf);')
        ->and(oa2Fn('owner-app.js', 'unlocked'))->not->toContain('csrf')
        ->and(oa2Fn('owner-app.js', 'gate'))->toContain('setKey(null);')->toContain('resetData();')
        ->and(oa2Fn('owner-app.js', 'boot'))->toContain("if (!S.csrf) { gate('pin'");

    // sessionStorage appears in core.js only, under the one key.
    foreach (glob(resource_path('js/owner-app/*.js')) as $f) {
        $code = oa2Code(basename($f));
        if (basename($f) !== 'core.js') {
            expect(str_contains($code, 'sessionStorage'))->toBeFalse(basename($f).' touches sessionStorage');
        }
    }
    preg_match_all("/sessionStorage\.(?:get|set|remove)Item\('([^']+)'/", oa2Code('core.js'), $m);
    expect(array_values(array_unique($m[1])))->toBe(['oa.k']);
});

it('marks the poll and the silent refresh passive, and nothing he asked for', function () {
    // (d). DEFECT: the 25 s poll keeping the app unlocked for ever, or "Sync
    // now" / a tab tap not counting as use. MUTATION: drop the passive
    // argument from the poll's catchUp() and `poll` loses the header; make
    // sync() pass `passive` through when manual and the manual case gains it.
    $out = oa2Node(<<<'JS'
await core.api('GET', 'changes?after=4', undefined, true);
await core.api('GET', 'orders');
await core.api('POST', 'lock', {}, true);
setReply({ status: 401, json: { ok: false, code: 'pin' } });
let lost = null, threw = null;
core.onAuthLost((c) => { lost = c; });
try { await core.api('GET', 'dashboard'); } catch (e) { threw = e.code; }
const p = (i) => seen[i].headers['X-OA-Passive'] || null;
return { poll: p(0), nav: p(1), write: p(2), lost, threw };
JS);
    expect($out)->toBe(['poll' => '1', 'nav' => null, 'write' => null, 'lost' => 'pin', 'threw' => 'pin']);

    $app = oa2Code('owner-app.js');
    expect($app)->toContain('try { await catchUp(false, true); } catch (e) { return; }')
        ->and(oa2Fn('owner-app.js', 'sync'))->toContain('quiet = !manual && passive')
        ->and(oa2Fn('owner-app.js', 'opened'))->toContain('sync(false, false);');

    // Navigation draws call their fetch with no passive flag.
    foreach ([['store.js', 'renderDashboard', 'await fetchDashboard();'], ['products.js', 'renderProducts', 'await fetchProducts();'], ['customers.js', 'renderCustomers', 'await fetchCustomers();'], ['orders.js', 'loadDetail', 'await fetchOrder(id);']] as [$file, $fn, $call]) {
        expect(oa2Fn($file, $fn))->toContain($call);
    }
});

it('asks for a six-digit PIN, and shows the server\'s words for a lock only a Full Admin can lift', function () {
    // (e), and SEC's 423 {code:'locked', admin_unlock:true} with no
    // retry_minutes. DEFECT: a pad that counts four digits, or a lock that
    // reads as "Wrong PIN." and invites more tries. MUTATION: put MIN_PIN back
    // to 4; or add 423 to api()'s auth-lost statuses and `lost` is 'locked'.
    $auth = oa2Code('auth.js');
    expect($auth)->toContain('const MIN_PIN = 6;')
        ->toContain('if (sending || pin.length < (auto ? len : MIN_PIN)) return;')
        ->toContain('minlength="6" maxlength="8"')
        ->toContain("msg.textContent = r.data.message || 'Wrong PIN.';");

    $out = oa2Node(<<<'JS'
setReply({ status: 423, json: { ok: false, code: 'locked', admin_unlock: true, message: 'Locked until a Full Admin unlocks it.' } });
let lost = null;
core.onAuthLost((c) => { lost = c; });
const r = await core.api('POST', 'unlock', { pin: '000000' });
return { ok: r.ok, status: r.status, message: r.data.message, lost };
JS);
    expect($out)->toBe(['ok' => false, 'status' => 423, 'message' => 'Locked until a Full Admin unlocks it.', 'lost' => null]);
});
