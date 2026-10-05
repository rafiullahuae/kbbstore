<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\OwnerApp\VapidKeys;
use App\Services\OwnerApp\WebPush;
use App\Services\SettingsService;
use App\Services\SiteApp;
use App\Services\SiteAppPush;
use App\Support\Locale;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Facades\DB;
use Tests\Support\SiteAppPushRoutes;

/*
 * Lane NT, part 2: the installed shop app asks about notifications and stores
 * the subscriptions (App -> Site App -> "Ask shoppers for notifications when
 * the app opens"). Nothing sends yet.
 *
 * The owner, 5 October: "the site app + owner app, apps should ask by default
 * about to allow notifications, after install when the open the app".
 */

function sapNode(): string
{
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        test()->markTestSkipped('node is not installed here');
    }

    return $node;
}

function sapSet(string $key, mixed $value, bool $autoload = true): void
{
    Setting::query()->updateOrCreate(['key' => $key], ['value' => is_array($value) ? json_encode($value) : $value, 'autoload' => $autoload]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

/** A subscription exactly as Chrome's PushSubscription.toJSON() gives one, with a real P-256 point. */
function sapSub(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc:DEF123'): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

    return ['endpoint' => $endpoint, 'keys' => ['p256dh' => WebPush::b64u(WebPush::publicPoint($key)), 'auth' => WebPush::b64u(random_bytes(16))]];
}

function sapCustomer(): Customer
{
    return Customer::create(['name' => 'Push Shopper', 'email' => 'push-'.uniqid().'@example.test', 'password' => 'password123']);
}

function sapOrder(?Customer $customer, array $address): Order
{
    static $seq = 0;
    $seq++;

    return Order::create([
        'order_number' => 'NT'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT), 'customer_id' => $customer?->id,
        'email' => $customer?->email ?? 'guest@example.test', 'status' => 'processing', 'currency' => 'AED',
        'subtotal' => 100, 'discount_total' => 0, 'shipping_total' => 0, 'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 100,
        'shipping_method' => 'Standard delivery', 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
        'shipping_address' => $address + ['first_name' => 'Ada', 'last_name' => 'L', 'line1' => '1 Road', 'phone' => '+971500000000'],
    ]);
}

/** The subscriber token the response set, decrypted the way EncryptCookies reads it back. */
function sapToken($response): string
{
    $raw = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === SiteAppPush::COOKIE)->getValue();

    return \Illuminate\Cookie\CookieValuePrefix::remove(decrypt($raw, false));
}

function sapLogin(Customer $c): array
{
    return ['login_customer_'.sha1(\Illuminate\Auth\SessionGuard::class) => $c->id];
}

beforeEach(function () {
    SiteAppPushRoutes::wire($this->app);
});

/* ------------------------------------------------------------- the endpoint */

it('answers the installed app with whether to ask, the shop\'s one VAPID key and four strings, and nothing else', function () {
    /* DEFECT: /api is public; a config answer that grew a model or a setting
       would leak to anybody. MUTATION: return $this->push->config(...) + ['setting' => ...]
       and the key list is red; mint a second key pair and the key check is red. */
    $r = $this->getJson('/api/site-app/push')->assertOk();
    $j = $r->json();

    expect(array_keys($j))->toBe(['ask', 'key', 't'])
        ->and(array_keys($j['t']))->toBe(['title', 'body', 'allow', 'later'])
        ->and($j['ask'])->toBeTrue()                                   // ON by default: he asked
        ->and($j['key'])->toBe(VapidKeys::publicKey())                // the owner app's pair, not a second one
        ->and($j['t']['allow'])->toBe('Allow notifications')
        ->and($r->headers->get('Cache-Control'))->toContain('no-store');
});

it('answers in Arabic once the Arabic is approved, and in English until then', function () {
    /* DEFECT: the strings hard-coded in English in the script. MUTATION: drop
       the $locale argument from __() in SiteAppPush::config() -> red. */
    sapSet(Locale::SETTING_ENABLED, '1');
    sapSet(Locale::SETTING_RTL, '1');
    TranslationStore::flush();
    $this->artisan('migrate', ['--path' => 'database/migrations/2027_08_28_130100_seed_site_app_push_arabic_drafts.php', '--force' => true]);

    expect($this->getJson('/api/site-app/push?lang=ar')->json('t.allow'))->toBe('Allow notifications');   // drafts are not served

    DB::table('translations')->where('locale', 'ar')->where('field', 'like', 'store.site_app.push_%')->update(['status' => 'published']);
    TranslationStore::flush();
    app('translator')->setLoaded([]);

    expect($this->getJson('/api/site-app/push?lang=ar')->json('t'))->toBe([
        'title' => 'تفعيل الإشعارات؟',
        'body' => 'اسمح بالإشعارات لتصلك أخبار K-Beauty Bliss على هذا الهاتف. يمكنك إيقافها في أي وقت من إعدادات هاتفك.',
        'allow' => 'السماح بالإشعارات',
        'later' => 'ليس الآن',
    ])->and($this->getJson('/api/site-app/push?lang=xx')->json('t.later'))->toBe('Not now');   // unknown code: the default
})->skip(fn () => ! class_exists(TranslationStore::class));

it('stores a real subscription once, upserting by endpoint, with the session\'s shopper and never the body\'s', function () {
    /* DEFECT: a second row each time the app syncs (fan-out sends the same
       phone N notifications), or a customer id taken from the request, which
       would let anybody attach their phone to somebody else's account.
       MUTATION: use insert() in SiteAppPush::store() -> count 2; read
       $request->input('customer_id') -> the id check is red. */
    $sub = sapSub();
    $mine = sapCustomer();
    $other = sapCustomer();

    $this->postJson('/api/site-app/push', $sub + ['lang' => 'en', 'customer_id' => $other->id])->assertOk()->assertExactJson(['ok' => true]);
    expect(DB::table('site_app_push_subscriptions')->value('customer_id'))->toBeNull();

    $this->withSession(sapLogin($mine))
        ->postJson('/api/site-app/push', $sub + ['lang' => 'en', 'customer_id' => $other->id])->assertOk();
    $this->flushSession();
    $this->postJson('/api/site-app/push', $sub + ['lang' => 'en'])->assertOk();          // a later guest sync keeps the link

    $rows = DB::table('site_app_push_subscriptions')->get();
    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->customer_id)->toBe($mine->id)
        ->and($rows[0]->endpoint_hash)->toBe(hash('sha256', $sub['endpoint']))
        ->and($rows[0]->locale)->toBe('en')
        ->and($rows[0]->status)->toBe('active')
        ->and($rows[0]->last_seen_at)->not->toBeNull();

    // Off forgets it, and answers the same whether it existed or not.
    $this->postJson('/api/site-app/push/off', ['endpoint' => $sub['endpoint']])->assertExactJson(['ok' => true]);
    $this->postJson('/api/site-app/push/off', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/never'])->assertExactJson(['ok' => true]);
    expect(DB::table('site_app_push_subscriptions')->count())->toBe(0);
});

it('refuses an endpoint off the push-service allowlist, plain http, bad keys and an oversized body', function () {
    /* DEFECT: an unauthenticated endpoint that stores any URL is a server-side
       request forgery the day a sender walks the table, and junk keys make
       every send fail. MUTATION: drop WebPush::allowedEndpoint() from
       SiteAppPush::clean() -> the evil hosts are stored; drop the MAX_BODY
       check -> the 413 line is red. */
    $good = sapSub();
    $bad = [
        'other host' => ['endpoint' => 'https://evil.example/fcm.googleapis.com'] + $good,
        'lookalike' => ['endpoint' => 'https://fcm.googleapis.com.evil.example/x'] + $good,
        'http' => ['endpoint' => 'http://fcm.googleapis.com/fcm/send/x'] + $good,
        'userinfo' => ['endpoint' => 'https://a@fcm.googleapis.com/x'] + $good,
        'port' => ['endpoint' => 'https://fcm.googleapis.com:8443/x'] + $good,
        'short p256dh' => ['keys' => ['p256dh' => WebPush::b64u(random_bytes(33)), 'auth' => $good['keys']['auth']]] + $good,
        'not on the curve' => ['keys' => ['p256dh' => WebPush::b64u("\x04".str_repeat("\x01", 64)), 'auth' => $good['keys']['auth']]] + $good,
        'auth 15 bytes' => ['keys' => ['p256dh' => $good['keys']['p256dh'], 'auth' => WebPush::b64u(random_bytes(15))]] + $good,
        'array endpoint' => ['endpoint' => ['https://fcm.googleapis.com/x']] + $good,
        'no keys' => ['endpoint' => $good['endpoint']],
    ];
    foreach ($bad as $why => $body) {
        $this->postJson('/api/site-app/push', $body)->assertStatus(422)->assertExactJson(['ok' => false]);
    }

    $this->postJson('/api/site-app/push', $good + ['pad' => str_repeat('x', SiteAppPush::MAX_BODY)])->assertStatus(413);
    expect(DB::table('site_app_push_subscriptions')->count())->toBe(0);

    // Each real push service is accepted.
    foreach (['https://fcm.googleapis.com/fcm/send/a', 'https://web.push.apple.com/QJ', 'https://updates.push.services.mozilla.com/wpush/v2/a', 'https://wns2-par02p.notify.windows.com/w/?token=a'] as $ep) {
        $this->postJson('/api/site-app/push', sapSub($ep))->assertOk();
    }
    expect(DB::table('site_app_push_subscriptions')->count())->toBe(4);
});

it('is rate limited per address', function () {
    /* MUTATION: drop the throttle middleware in routes/site-app-push.php -> no 429. */
    $codes = [];
    for ($i = 0; $i < 22; $i++) {
        $codes[] = $this->postJson('/api/site-app/push', ['endpoint' => 'x'])->status();
    }
    expect($codes)->toContain(429);
});

it('answers 404 to all three while the Site App is off', function () {
    sapSet(SiteApp::SETTING, ['on' => false, 'name' => 'K-Beauty Bliss']);
    $this->getJson('/api/site-app/push')->assertNotFound()->assertExactJson(['ok' => false]);
    $this->postJson('/api/site-app/push', sapSub())->assertNotFound();
    $this->postJson('/api/site-app/push/off', ['endpoint' => 'x'])->assertNotFound();
});

it('drops the shop app\'s subscriptions when the VAPID pair is replaced, as it does the owner app\'s', function () {
    /* DEFECT: rows bound to a key that no longer exists, every send refused.
       MUTATION: take site_app_push_subscriptions out of VapidKeys::generate(). */
    $this->postJson('/api/site-app/push', sapSub())->assertOk();
    VapidKeys::forget();
    VapidKeys::generate();
    expect(DB::table('site_app_push_subscriptions')->count())->toBe(0);
});

/* ------------------------------------------------------------ the control */

it('switches the question on and off from App -> Site App, and off means the app is told not to ask', function () {
    /* DEFECT: the switch saved nowhere, or saved into `site_app` where
       SiteApp::save() would drop it on the next rename. MUTATION: ignore
       'ask_push' in SiteAppApiController::save() -> the second GET still says true. */
    $admin = AdminUser::create(['name' => 'SA owner', 'email' => 'sa-np-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']);

    expect($this->actingAs($admin, 'admin')->getJson('/admin-api/site-app')->json('ask_push'))->toBeTrue();

    $this->actingAs($admin, 'admin')->postJson('/admin-api/site-app', ['ask_push' => false])->assertOk()->assertJsonPath('ask_push', false);
    expect($this->getJson('/api/site-app/push')->json('ask'))->toBeFalse();

    // A rename afterwards keeps it off; a non-boolean is refused, never coerced.
    $this->actingAs($admin, 'admin')->postJson('/admin-api/site-app', ['name' => 'KB Bliss'])->assertOk()->assertJsonPath('ask_push', false);
    $this->actingAs($admin, 'admin')->postJson('/admin-api/site-app', ['ask_push' => 'yes'])->assertStatus(422);
    $this->actingAs($admin, 'admin')->postJson('/admin-api/site-app', ['ask_push' => true])->assertOk()->assertJsonPath('ask_push', true);
    expect(app(SiteApp::class)->all())->toBe(['on' => true, 'name' => 'KB Bliss']);
});

it('draws the switch on the Site App screen and sends it with Save', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/site-app-screen.blade.php'));
    expect(substr_count($src, 'Ask shoppers for notifications when the app opens'))->toBe(1)
        ->and($src)->toContain('ask_push: draft.ask');
});

/* --------------------------------------------------------------- the pages */

it('adds nothing to a page: the question lives in site-app.js and its strings come from the API', function () {
    /* DEFECT: the sheet's markup or strings printed into every page. The head
       block stays its seven tags; the only change a page sees is the script's
       ?v= hash. MUTATION: print the strings into partials/site-app-head -> red. */
    $html = (string) $this->get('/')->getContent();
    expect($html)->not->toContain('kbb-np')->not->toContain('site-app/push')->not->toContain('Allow notifications');
});

/* -------------------------------------------------------- the script, run */

/**
 * site-app.js in node, with a browser stubbed around it. $setup runs before
 * the script; $after runs once its promises settle. Returns $after's value.
 */
function sapRun(array $env, string $after = 'return state;'): mixed
{
    $node = sapNode();
    $src = json_encode((string) file_get_contents(resource_path('site-app/site-app.js')));
    $cfg = json_encode($env + ['standalone' => true, 'perm' => 'default', 'np' => 0, 'ask' => true, 'now' => 1_800_000_000_000]);
    $key = json_encode(VapidKeys::publicKey());
    $js = <<<JS
const vm = require('vm');
const E = {$cfg};
const state = { fetches: [], asked: 0, subscribed: 0, posted: null, ls: {}, ss: {}, viewed: [] };
if (E.np) state.ls['kbb.sa.np'] = String(E.np);
if (E.synced) state.ls['kbb.sa.ps'] = String(E.now - 3600e3);
if (E.seen) state.ss['kbb.sa.oos'] = E.seen;
function mk(tag) {
  return { tag, children: [], attrs: {}, on: {}, className: '', textContent: '', id: '', parentNode: null, style: {},
    setAttribute(k, v) { this.attrs[k] = String(v); }, getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; },
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    removeChild(c) { this.children = this.children.filter((x) => x !== c); c.parentNode = null; },
    addEventListener(t, f) { (this.on[t] = this.on[t] || []).push(f); }, focus() {} };
}
const find = (n, cls) => { if (n.className === cls) return n; for (const c of n.children || []) { const f = find(c, cls); if (f) return f; } return null; };
const script = mk('script'); script.attrs = { 'data-sw': '/sw.js', 'data-scope': '/' };
const html = mk('html'); html.attrs = { lang: E.lang || 'en', dir: E.dir || 'ltr' };
const link = mk('link'); link.attrs = { href: '/site-app/icons/apple-180.png' };
const form = mk('form'); form.attrs = { 'data-product_id': '42' };
const Q = { 'link[rel="apple-touch-icon"]': link, 'form.kbb-cart-form[data-product_id]': form, '.stockline.out': E.oos ? mk('div') : null };
const document = { currentScript: script, readyState: 'complete', documentElement: html, head: mk('head'), body: mk('body'),
  createElement: mk, getElementById: () => null, querySelector: (q) => (q in Q ? Q[q] : null), addEventListener() {}, removeEventListener() {} };
const sub = { options: { applicationServerKey: null }, toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/fcm/send/x', keys: { p256dh: 'p', auth: 'a' } }), unsubscribe: async () => true };
const reg = { pushManager: { getSubscription: async () => null, subscribe: async (o) => { state.subscribed++; state.key = o.applicationServerKey.length; return sub; } } };
const Notification = { permission: E.perm, requestPermission() { state.asked++; Notification.permission = 'granted'; return Promise.resolve('granted'); } };
const window = { matchMedia: (q) => ({ matches: E.standalone && q.indexOf('standalone') !== -1 }), Notification, PushManager: function () {},
  localStorage: { getItem: (k) => (k in state.ls ? state.ls[k] : null), setItem: (k, v) => { state.ls[k] = v; } },
  sessionStorage: { getItem: (k) => (k in state.ss ? state.ss[k] : null), setItem: (k, v) => { state.ss[k] = v; } },
  atob: (s) => Buffer.from(s, 'base64').toString('binary'), KBB: { csrf: 'tok' }, addEventListener() {} };
const navigator = { serviceWorker: { register: async () => reg, ready: Promise.resolve(reg) } };
const fetch = async (url, o) => {
  state.fetches.push((o && o.method) || 'GET');
  if (o && o.method === 'POST' && url.endsWith('/viewed')) { state.viewed.push(JSON.parse(o.body).product_id); return { ok: true, json: async () => ({ ok: true }) }; }
  if (o && o.method === 'POST') { state.posted = { url, body: JSON.parse(o.body), csrf: o.headers['X-CSRF-TOKEN'] }; return { ok: true, json: async () => ({ ok: true }) }; }
  return { ok: true, json: async () => ({ ask: E.ask, key: {$key}, t: { title: 'T', body: 'B', allow: 'Allow notifications', later: 'Not now' } }) };
};
const D = function () {}; D.now = () => E.now;
vm.runInContext({$src}, vm.createContext({ document, window, navigator, fetch, Promise, Uint8Array, String, JSON, encodeURIComponent, Date: D }));
const tick = () => new Promise((r) => setTimeout(r, 5));
(async () => {
  await tick(); await tick();
  state.sheet = !!find(document.body, 'kbb-np');
  state.dir = state.sheet ? find(document.body, 'kbb-np').attrs.dir : null;
  state.askedBeforeTap = state.asked;
  const allow = find(document.body, 'kbb-np-allow');
  const out = await (async () => { {$after} })();
  console.log(JSON.stringify(out));
})();
JS;
    $file = storage_path('framework/testing/nt-sa-'.getmypid().'-'.bin2hex(random_bytes(3)).'.cjs');
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, $js);
    $raw = (string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
    @unlink($file);

    return json_decode(trim((string) strrchr("\n".trim($raw), "\n")), true) ?? $raw;
}

it('shows the sheet only in the installed app with the permission undecided, and asks the browser only from the Allow tap', function () {
    /* DEFECT: requestPermission() on load, which iOS refuses outright and
       Chrome turns into a blocked quiet prompt; or the sheet in a browser tab.
       MUTATION: call requestPermission() in notify() -> askedBeforeTap is 1;
       drop standalone() from plan() -> the tab case shows a sheet. */
    $s = sapRun([], "allow.on.click[0](); await tick(); await tick(); return state;");
    expect($s['sheet'])->toBeTrue()
        ->and($s['askedBeforeTap'])->toBe(0)
        ->and($s['asked'])->toBe(1)
        ->and($s['subscribed'])->toBe(1)
        ->and($s['key'])->toBe(65)                                              // the uncompressed P-256 public key
        ->and($s['posted']['url'])->toBe('/api/site-app/push')
        ->and($s['posted']['csrf'])->toBe('tok')
        ->and(array_keys($s['posted']['body']))->toBe(['endpoint', 'keys', 'lang'])
        ->and($s['ls'])->toHaveKey('kbb.sa.np');

    $tab = sapRun(['standalone' => false]);
    expect($tab['sheet'])->toBeFalse()->and($tab['fetches'])->toBe([]);         // a browser tab: not even a request
});

it('never asks again after denied, holds "Not now" for seven days, and does not ask when the shop says not to', function () {
    /* MUTATION: return 'ask' for 'denied' in plan() -> the first line is red;
       change ASK_AGAIN_DAYS to 0 -> the second is red. */
    $now = 1_800_000_000_000;
    expect(sapRun(['perm' => 'denied'])['fetches'])->toBe([])
        ->and(sapRun(['np' => $now - 6 * 864e5])['fetches'])->toBe([])
        ->and(sapRun(['np' => $now - 8 * 864e5])['sheet'])->toBeTrue()
        ->and(sapRun(['ask' => false])['sheet'])->toBeFalse();

    // Arabic: the sheet takes the page's direction.
    expect(sapRun(['lang' => 'ar', 'dir' => 'rtl'])['dir'])->toBe('rtl');
});

it('keeps the browser\'s question inside the click handler, statically', function () {
    /* The run above proves the order; this pins the one call site. MUTATION:
       add a second requestPermission( anywhere in either app -> red. */
    $shop = (string) file_get_contents(resource_path('site-app/site-app.js'));
    $code = (string) preg_replace(['#/\*.*?\*/#s', '#(^|\s)//[^\n]*#'], ['', '$1'], $shop);
    expect(substr_count($code, 'requestPermission('))->toBe(1);
    preg_match("/allow\\.addEventListener\\('click', function \\(\\) \\{(.*?)\\n    \\}\\);/s", $code, $m);
    expect($m[1] ?? '')->toContain('window.Notification.requestPermission()');
});

/* -------------------------------------------------------------- the worker */

it('turns a push into one notification and opens only an address on this shop', function () {
    /* DEFECT: a payload url sending the shopper to another site (or
       javascript:). MUTATION: return url.href without the origin test in
       shopUrl() -> the evil url survives. */
    $node = sapNode();
    $sw = json_encode((string) $this->get('/sw.js')->assertOk()->getContent());
    $js = <<<JS
const vm = require('vm');
const L = {}, shown = [], opened = [];
const self = { location: new URL('https://shop.test/sw.js'), addEventListener: (t, f) => { L[t] = f; },
  registration: { showNotification: async (t, o) => { shown.push({ t, url: o.data.url, body: o.body }); } },
  clients: { matchAll: async () => [], openWindow: async (u) => { opened.push(u); } }, skipWaiting() {} };
vm.runInContext({$sw}, vm.createContext({ self, URL, caches: {}, fetch() {}, Response: function () {}, console }));
const push = async (d) => { const w = []; L.push({ data: { json: () => d }, waitUntil: (p) => w.push(p) }); await Promise.all(w); };
const click = async (url) => { const w = []; L.notificationclick({ notification: { close() {}, data: { url } }, waitUntil: (p) => w.push(p) }); await Promise.all(w); };
(async () => {
  await push({ t: 'Your order shipped', b: 'On its way', u: '/my-account/orders/' });
  await push({ t: 'x', u: 'https://evil.example/' });
  await push({ t: 'x', u: 'javascript:alert(1)' });
  await click(shown[0].url); await click('https://evil.example/');
  console.log(JSON.stringify({ shown, opened }));
})();
JS;
    $file = storage_path('framework/testing/nt-sw-'.getmypid().'-'.bin2hex(random_bytes(3)).'.cjs');
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, $js);
    $raw = (string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
    @unlink($file);
    $out = json_decode(trim((string) strrchr("\n".trim($raw), "\n")), true);

    expect($out, $raw)->toBeArray()
        ->and($out['shown'][0])->toBe(['t' => 'Your order shipped', 'url' => 'https://shop.test/my-account/orders/', 'body' => 'On its way'])
        ->and($out['shown'][1]['url'])->toBe('https://shop.test/')
        ->and($out['shown'][2]['url'])->toBe('https://shop.test/')
        ->and($out['opened'])->toBe(['https://shop.test/my-account/orders/', 'https://shop.test/']);
});

it('gives the phone a random HttpOnly subscriber cookie and records a coarse platform, never the user agent', function () {
    /* DEFECT: PII in the cookie, a cookie script can read, or the full user
       agent stored. MUTATION: pass httpOnly false to ->cookie() -> red. */
    $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';
    $r = $this->withHeaders(['User-Agent' => $ua])->postJson('/api/site-app/push', sapSub())->assertOk();
    $c = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === SiteAppPush::COOKIE);

    expect($c)->not->toBeNull()
        ->and($c->isHttpOnly())->toBeTrue()
        ->and($c->getSameSite())->toBe('lax');
    $row = DB::table('site_app_push_subscriptions')->first();
    expect($row->platform)->toBe('ios')
        ->and($row->cookie_hash)->toBe(hash('sha256', sapToken($r)))
        ->and(sapToken($r))->toMatch('/\A[0-9a-f]{32}\z/')
        ->and(json_encode($row))->not->toContain('iPhone OS');
    expect(SiteAppPush::platform('Mozilla/5.0 (Linux; Android 14; Pixel 8)'))->toBe('android')
        ->and(SiteAppPush::platform('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'))->toBe('desktop');
});

it('locates the phone without asking: the shopper\'s last order first, else the proxy\'s headers, else nothing', function () {
    /* DEFECT: a location prompt (the owner: "no disturbance to the customer"),
       or a header guess overwriting a real address. MUTATION: drop the
       `!== 'order'` guard in SiteAppPush::store() -> the last region is Dubai. */
    $none = sapSub('https://fcm.googleapis.com/fcm/send/none');
    $this->postJson('/api/site-app/push', $none)->assertOk();
    expect(DB::table('site_app_push_subscriptions')->where('endpoint_hash', hash('sha256', $none['endpoint']))->value('location_source'))->toBeNull();

    $ip = sapSub('https://fcm.googleapis.com/fcm/send/ip');
    $this->withHeaders(['CF-IPCountry' => 'AE', 'CF-Region' => 'Dubai', 'CF-IPCity' => "Dubai\x01"])->postJson('/api/site-app/push', $ip)->assertOk();
    $row = DB::table('site_app_push_subscriptions')->where('endpoint_hash', hash('sha256', $ip['endpoint']))->first();
    expect([$row->country, $row->region, $row->city, $row->location_source])->toBe(['AE', 'Dubai', 'Dubai', 'ip-header']);

    $c = sapCustomer();
    sapOrder($c, ['city' => 'Sharjah', 'state' => 'Sharjah', 'country' => 'AE']);
    $ord = sapSub('https://fcm.googleapis.com/fcm/send/ord');
    $this->withSession(sapLogin($c))->withHeaders(['CF-IPCountry' => 'AE', 'CF-Region' => 'Dubai'])->postJson('/api/site-app/push', $ord)->assertOk();
    $this->flushSession();
    $this->withHeaders(['CF-IPCountry' => 'AE', 'CF-Region' => 'Dubai'])->postJson('/api/site-app/push', $ord)->assertOk();   // a guess never replaces an order
    $row = DB::table('site_app_push_subscriptions')->where('endpoint_hash', hash('sha256', $ord['endpoint']))->first();
    expect([$row->region, $row->city, $row->location_source, (int) $row->customer_id])->toBe(['Sharjah', 'Sharjah', 'order', $c->id]);

    $src = (string) file_get_contents(resource_path('site-app/site-app.js'));
    expect((string) preg_replace('#/\*.*?\*/#s', '', $src))->not->toContain('geolocation');
});

it('links an order placed from a subscribed phone to its row, by the cookie alone, and only then', function () {
    /* DEFECT: order updates that can never reach the phone that ordered, or a
       hook that costs a query on every order. MUTATION: drop the
       SiteAppPush::orderPlaced() call from the Order::created hook -> red. */
    $r = $this->postJson('/api/site-app/push', sapSub())->assertOk();
    $token = sapToken($r);
    $c = sapCustomer();

    $q = 0;
    DB::listen(function () use (&$q) { $q++; });
    sapOrder($c, ['city' => 'Abu Dhabi', 'state' => 'Abu Dhabi', 'country' => 'AE']);   // no cookie on this request
    $without = $q;
    expect(DB::table('site_app_push_subscriptions')->value('customer_id'))->toBeNull();

    $this->app['request']->cookies->set(SiteAppPush::COOKIE, $token);
    $q = 0;
    sapOrder($c, ['city' => 'Al Ain', 'state' => 'Abu Dhabi', 'country' => 'AE']);
    $row = DB::table('site_app_push_subscriptions')->first();
    expect([(int) $row->customer_id, $row->region, $row->city, $row->location_source])->toBe([$c->id, 'Abu Dhabi', 'Al Ain', 'order'])
        ->and($q)->toBeLessThanOrEqual($without + 3);                     // the cookie read and one UPDATE, no more
    $this->app['request']->cookies->remove(SiteAppPush::COOKIE);
});

it('records an out-of-stock product a subscribed phone viewed, once, and nothing for anyone else', function () {
    /* DEFECT: "back in stock" sent to phones that never looked, or a table
       anybody can fill. MUTATION: drop the $out check in SiteAppPush::viewed()
       -> the in-stock product is recorded; use insert() -> two rows. */
    $oos = Product::create(['slug' => 'nt-oos', 'name' => 'Sold Out Toner', 'status' => 'publish', 'is_visible' => true, 'price' => 100, 'stock_status' => 'outofstock']);
    $in = Product::create(['slug' => 'nt-in', 'name' => 'Toner', 'status' => 'publish', 'is_visible' => true, 'price' => 100, 'stock_status' => 'instock']);

    // No cookie: the same answer, nothing stored.
    $this->postJson('/api/site-app/push/viewed', ['product_id' => $oos->id])->assertExactJson(['ok' => true]);

    $r = $this->postJson('/api/site-app/push', sapSub())->assertOk();
    $token = sapToken($r);
    foreach ([$oos->id, $oos->id, $in->id, 999999] as $pid) {
        $this->withCredentials()->withCookie(SiteAppPush::COOKIE, $token)->postJson('/api/site-app/push/viewed', ['product_id' => $pid])->assertExactJson(['ok' => true]);
    }
    foreach (['7', -1, 0, [1]] as $bad) {
        $this->withCredentials()->withCookie(SiteAppPush::COOKIE, $token)->postJson('/api/site-app/push/viewed', ['product_id' => $bad])->assertStatus(422);
    }

    $rows = DB::table('site_app_push_interests')->get();
    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->product_id)->toBe($oos->id)
        ->and($rows[0]->notified_at)->toBeNull();
});

it('sends the out-of-stock beacon only from a synced phone on a sold-out page, once per product per session', function () {
    /* MUTATION: drop the `when(SYNC)` test in interest() -> the unsynced case posts. */
    $s = sapRun(['perm' => 'granted', 'synced' => true, 'oos' => true]);
    expect($s['viewed'])->toBe([42]);
    expect(sapRun(['perm' => 'granted', 'synced' => false, 'oos' => true])['viewed'])->toBe([])
        ->and(sapRun(['perm' => 'granted', 'synced' => true, 'oos' => false])['viewed'])->toBe([])
        ->and(sapRun(['perm' => 'granted', 'synced' => true, 'oos' => true, 'seen' => '42'])['viewed'])->toBe([]);
});

it('keeps the route file mounted at most once', function () {
    /* Zero is "built, never wired" and is what the integrator's one line fixes
       (routes/web.php, the ordinary web group, beside newsletter-public.php);
       two would register every path twice. */
    $web = (string) file_get_contents(base_path('routes/web.php'));
    expect(substr_count($web, "require __DIR__.'/site-app-push.php';"))->toBeLessThanOrEqual(1);
});
