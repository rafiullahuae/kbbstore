<?php

declare(strict_types=1);

/*
 * The owner app's live half (Lane MAC): the events the shop records, the
 * "changes since" cursor, Web Push (encryption, VAPID, delivery) and the
 * service worker that must never cache customer data.
 */

use App\Models\Order;
use App\Models\Product;
use App\Services\OwnerApp\OwnerAppEvents;
use App\Services\OwnerApp\VapidKeys;
use App\Services\OwnerApp\WebPush;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    OA::wire($this->app);
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
    Http::preventStrayRequests();
});

function oaOrder(array $over = []): Order
{
    return Order::query()->create($over + ['order_number' => (string) random_int(10000, 99999), 'email' => 'buyer@example.com',
        'status' => 'pending', 'total' => 16100, 'payment_method' => 'tabby', 'payment_method_title' => 'Tabby',
        'billing_address' => ['first_name' => 'Sabina', 'last_name' => 'Dev']]);
}

function oaTypes(): array
{
    return DB::table('owner_app_events')->orderBy('id')->pluck('type')->all();
}

it('records a new order when it is placed, not while it waits for payment', function () {
    // DEFECT: "New order" for a card checkout nobody paid, or none at all for
    // a COD order created already placed. MUTATION: drop the PLACED check in
    // OwnerAppEvents::orderCreated().
    $card = oaOrder();
    expect(oaTypes())->toBe([]);

    app(\App\Services\Orders\OrderStatus::class)->moveTo($card, 'processing', by: 'test');
    expect(oaTypes())->toBe(['order.new']);

    oaOrder(['status' => 'processing', 'payment_method' => 'cod']);
    expect(oaTypes())->toBe(['order.new', 'order.new'])
        ->and(DB::table('owner_app_events')->value('title'))->toStartWith('New order #');
});

it('records status changes, failed payments and refunds, and nothing for imported or sample orders', function () {
    $o = oaOrder(['status' => 'processing']);
    DB::table('owner_app_events')->delete();
    $moves = app(\App\Services\Orders\OrderStatus::class);

    $moves->moveTo($o, 'shipped', by: 't');
    $moves->moveTo($o, 'refunded', by: 't');
    $f = oaOrder();
    $moves->moveTo($f, 'failed', by: 't');

    expect(oaTypes())->toBe(['order.status', 'order.refunded', 'order.failed']);

    DB::table('owner_app_events')->delete();
    oaOrder(['status' => 'processing', 'wc_order_id' => 123456]);
    oaOrder(['status' => 'processing', 'origin' => 'sample']);
    expect(oaTypes())->toBe([]);
});

it('records low stock once when a product crosses the line, and out of stock at zero', function () {
    // MUTATION: change `$wasAbove` to `true` and a product sitting at 3 is
    // reported again on every save.
    $p = Product::query()->create(['slug' => 'oa-live', 'name' => 'Snail Mucin', 'price' => 5000, 'manage_stock' => true, 'stock' => 20]);

    $p->update(['stock' => 4]);
    $p->update(['stock' => 3]);
    $p->update(['stock' => 0]);
    $p->update(['stock' => 12]);

    expect(oaTypes())->toBe(['stock.low', 'stock.out']);
});

it('reports the shelves an order emptied, once a day per product', function () {
    $p = Product::query()->create(['slug' => 'oa-live2', 'name' => 'Cica Cream', 'price' => 5000, 'manage_stock' => true, 'stock' => 2]);
    foreach ([1, 2] as $i) {
        $o = oaOrder(['status' => 'pending']);
        DB::table('order_items')->insert(['order_id' => $o->id, 'product_id' => $p->id, 'name' => 'Cica Cream', 'quantity' => 1,
            'unit_price' => 5000, 'subtotal' => 5000, 'total' => 5000, 'created_at' => now(), 'updated_at' => now()]);
        app(\App\Services\Orders\OrderStatus::class)->moveTo($o, 'processing', by: 't');
    }

    expect(oaTypes())->toBe(['order.new', 'stock.low', 'order.new']);
});

it('answers the live poll with only what changed since the cursor', function () {
    // DEFECT: a poll that re-sends everything, or skips events. MUTATION:
    // change `>` to `>=` in LiveController::changes() and the second poll
    // returns the event it already gave.
    $owner = OA::admin();
    OA::member($owner);
    [$c] = OA::enrol($this);
    oaOrder(['status' => 'processing']);

    $first = OA::get($this, 'changes?after=0', $c)->assertOk();
    expect($first->json('events'))->toBe([]);
    $cursor = $first->json('cursor');
    expect($cursor)->toBeGreaterThan(0);

    oaOrder(['status' => 'processing', 'order_number' => '77777']);
    $next = OA::get($this, 'changes?after='.$cursor, $c)->assertOk();
    expect($next->json('events'))->toHaveCount(1)
        ->and($next->json('events.0.title'))->toBe('New order #77777')
        ->and($next->json('cursor'))->toBeGreaterThan($cursor);

    expect(OA::get($this, 'changes?after='.$next->json('cursor'), $c)->json('events'))->toBe([]);
});

/* ------------------------------------------------------------------ push */

/** The receiving side of RFC 8291, written from the RFC, to check encrypt() against. */
function oaDecrypt(string $body, \OpenSSLAsymmetricKey $uaPrivate, string $uaPublic, string $auth): string
{
    $salt = substr($body, 0, 16);
    $rs = unpack('N', substr($body, 16, 4))[1];
    $idlen = ord($body[20]);
    $asPublic = substr($body, 21, $idlen);
    $cipher = substr($body, 21 + $idlen);

    expect($rs)->toBe(4096)->and($idlen)->toBe(65);

    $shared = openssl_pkey_derive(WebPush::keyFromPoint($asPublic), $uaPrivate, 32);
    $prk = hash_hmac('sha256', $shared, $auth, true);
    $ikm = substr(hash_hmac('sha256', "WebPush: info\0".$uaPublic.$asPublic."\x01", $prk, true), 0, 32);
    $prk2 = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk2, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk2, true), 0, 12);

    $tag = substr($cipher, -16);
    $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    expect($plain)->not->toBeFalse();

    $plain = rtrim((string) $plain, "\0");
    expect(substr($plain, -1))->toBe("\x02");     // the last-record delimiter

    return substr($plain, 0, -1);
}

it('encrypts a push payload that the browser side of RFC 8291 decrypts back', function () {
    // DEFECT: a payload no browser can open — every notification silently
    // dropped by the push service. MUTATION: swap the two hash_hkdf info
    // strings in WebPush::encrypt() and the decrypt fails.
    $ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $uaPublic = WebPush::publicPoint($ua);
    $auth = random_bytes(16);
    $payload = json_encode(['t' => 'New order #33447', 'b' => 'AED 161 · Tabby', 'u' => '#/orders/12']);

    $body = WebPush::encrypt($payload, WebPush::b64u($uaPublic), WebPush::b64u($auth));

    expect($body)->not->toBeNull()
        ->and(oaDecrypt($body, $ua, $uaPublic, $auth))->toBe($payload)
        ->and(str_contains($body, 'New order'))->toBeFalse();
});

it('signs a VAPID JWT that verifies against the public key it advertises', function () {
    $pair = VapidKeys::pair();
    $header = WebPush::vapidHeader('https://fcm.googleapis.com/fcm/send/abc', $pair, 1_800_000_000);

    preg_match('/^vapid t=([^,]+), k=(.+)$/', (string) $header, $m);
    [$h, $c, $s] = explode('.', $m[1]);
    expect($m[2])->toBe($pair['public'])
        ->and(json_decode(WebPush::b64uDecode($c), true))->toMatchArray(['aud' => 'https://fcm.googleapis.com', 'exp' => 1_800_000_000 + 43200])
        ->and(json_decode(WebPush::b64uDecode($h), true))->toBe(['typ' => 'JWT', 'alg' => 'ES256']);

    $raw = WebPush::b64uDecode($s);
    $int = static function (string $x): string {
        $x = ltrim($x, "\0");
        if ($x === '' || ord($x[0]) > 0x7f) {
            $x = "\0".$x;
        }

        return "\x02".chr(strlen($x)).$x;
    };
    $seq = $int(substr($raw, 0, 32)).$int(substr($raw, 32));
    $der = "\x30".chr(strlen($seq)).$seq;
    $pub = WebPush::keyFromPoint(WebPush::b64uDecode($pair['public']));

    expect(strlen($raw))->toBe(64)
        ->and(openssl_verify($h.'.'.$c, $der, $pub, OPENSSL_ALGO_SHA256))->toBe(1);

    expect(DB::table('settings')->where('key', VapidKeys::PRIVATE)->value('value'))->not->toContain('PRIVATE KEY');
});

it('sends only to real push services', function (string $url, bool $ok) {
    // DEFECT: a member registering http://169.254.169.254/ as their "push
    // endpoint" and the server POSTing into its own network.
    expect(WebPush::allowedEndpoint($url))->toBe($ok);
})->with([
    ['https://fcm.googleapis.com/fcm/send/abc', true],
    ['https://updates.push.services.mozilla.com/wpush/v2/x', true],
    ['https://web.push.apple.com/QH-abc', true],
    ['https://wns2-par02p.notify.windows.com/w/?token=x', true],
    ['http://fcm.googleapis.com/fcm/send/abc', false],
    ['https://169.254.169.254/latest/meta-data', false],
    ['https://localhost/x', false],
    ['https://fcm.googleapis.com.evil.example/x', false],
    ['https://evilfcm.googleapis.com.example/x', false],
    ['https://fcm.googleapis.com:8443/x', false],
    ['https://user:pw@fcm.googleapis.com/x', false],
]);

it('stores a subscription per device, refuses a fake one, and delivers an encrypted push to it', function () {
    $owner = OA::admin();
    OA::member($owner);
    [$c, $csrf] = OA::enrol($this);

    $ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $sub = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/device-1', 'keys' => ['p256dh' => WebPush::b64u(WebPush::publicPoint($ua)), 'auth' => WebPush::b64u(random_bytes(16))]];

    OA::post($this, 'push', ['endpoint' => 'https://10.0.0.5/push'] + $sub, $c, $csrf)->assertStatus(422);
    OA::post($this, 'push', ['keys' => ['p256dh' => 'short', 'auth' => 'x']] + $sub, $c, $csrf)->assertStatus(422);
    OA::post($this, 'push', $sub, $c, $csrf)->assertOk();
    expect(DB::table('owner_app_push_subscriptions')->count())->toBe(1);

    Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
    OwnerAppEvents::forget();
    oaOrder(['status' => 'processing', 'order_number' => '55555']);
    expect(OwnerAppEvents::flush())->toBe(1);

    Http::assertSent(function (HttpRequest $r) {
        return $r->url() === 'https://fcm.googleapis.com/fcm/send/device-1'
            && $r->header('Content-Encoding')[0] === 'aes128gcm'
            && str_starts_with($r->header('Authorization')[0], 'vapid t=')
            && ! str_contains($r->body(), '55555');
    });
});

it('forgets a subscription the push service says is gone, and sends nothing when a member opted out', function () {
    $owner = OA::admin();
    OA::member($owner);
    [$c, $csrf] = OA::enrol($this);
    $ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    OA::post($this, 'push', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/gone', 'keys' => ['p256dh' => WebPush::b64u(WebPush::publicPoint($ua)), 'auth' => WebPush::b64u(random_bytes(16))]], $c, $csrf)->assertOk();

    OA::post($this, 'notify', ['groups' => ['stock']], $c, $csrf)->assertOk()->assertJsonPath('groups', ['stock']);
    Http::fake(['*' => Http::response('', 410)]);
    OwnerAppEvents::forget();
    oaOrder(['status' => 'processing']);
    expect(OwnerAppEvents::flush())->toBe(0);
    Http::assertNothingSent();

    OA::post($this, 'notify', ['groups' => ['orders', 'nonsense']], $c, $csrf)->assertJsonPath('groups', ['orders']);
    OwnerAppEvents::forget();
    oaOrder(['status' => 'processing']);
    OwnerAppEvents::flush();
    expect(DB::table('owner_app_push_subscriptions')->count())->toBe(0);
});

it('serves a service worker that never caches or serves /api from a cache', function () {
    // DEFECT: orders and customers sitting in Cache Storage on a lost phone.
    // MUTATION: delete the `/api/` early return in resources/owner-app/sw.js
    // and the api request below is answered from the cache.
    $sw = $this->get(OA::base().'/sw.js')->assertOk();
    expect($sw->headers->get('Content-Type'))->toContain('javascript')
        ->and($sw->headers->get('Service-Worker-Allowed'))->toBe(OA::base().'/');

    $file = storage_path('framework/testing/oa-sw-'.getmypid().'.js');
    @mkdir(dirname($file), 0775, true);
    file_put_contents($file, $sw->getContent());

    $harness = <<<'JS'
const fs = require('fs');
const src = fs.readFileSync(process.argv[2], 'utf8');
const handlers = {}, log = [];
const cache = { addAll: (l) => { log.push(['addAll', l]); return Promise.resolve(); } };
const self = { location: { origin: 'https://shop.test' }, addEventListener: (t, f) => { handlers[t] = f; },
  skipWaiting: () => {}, clients: { claim: () => {} }, registration: {} };
const caches = { open: () => Promise.resolve(cache), match: (u) => { log.push(['match', String(u)]); return Promise.resolve(undefined); },
  keys: () => Promise.resolve([]), delete: () => {} };
const fetch = (r) => { log.push(['fetch', r.url || r]); return Promise.resolve({}); };
new Function('self', 'caches', 'fetch', src)(self, caches, fetch);
const base = JSON.parse(process.argv[3]);
const ev = (url, mode) => { const e = { request: { method: 'GET', url, mode: mode || 'cors' }, responded: false, respondWith() { this.responded = true; } }; handlers.fetch(e); return e.responded; };
const out = {
  api: ev('https://shop.test' + base + '/api/orders'),
  apiNav: ev('https://shop.test' + base + '/api/orders/1', 'navigate'),
  shell: ev('https://shop.test' + base + '/', 'navigate'),
};
handlers.install({ waitUntil: (p) => p });
setTimeout(() => { out.log = log; console.log(JSON.stringify(out)); }, 20);
JS;
    $js = storage_path('framework/testing/oa-sw-harness-'.getmypid().'.cjs');
    file_put_contents($js, $harness);

    $res = json_decode((string) shell_exec('node '.escapeshellarg($js).' '.escapeshellarg($file).' '.escapeshellarg(json_encode(OA::base()))), true);
    @unlink($file);
    @unlink($js);

    expect($res)->toBeArray()
        ->and($res['api'])->toBeFalse()
        ->and($res['apiNav'])->toBeFalse()
        ->and($res['shell'])->toBeTrue();

    $precached = collect($res['log'])->firstWhere(0, 'addAll')[1];
    $matched = collect($res['log'])->where(0, 'match')->pluck(1)->all();
    expect($precached)->toContain(OA::base().'/')
        ->and(collect($precached)->filter(fn ($u) => str_contains($u, '/api/'))->all())->toBe([])
        ->and(collect($matched)->filter(fn ($u) => str_contains($u, '/api/'))->all())->toBe([]);
});

it('runs one timer, only while the page is visible, and measures no layout', function () {
    // CLAUDE.md rule 4. MUTATION: replace the setTimeout chain with
    // setInterval, or drop the visibilityState check in startPolling().
    // Code only: the comments say "never in localStorage", which is the point.
    // sessionStorage holds the CSRF key alone, in core.js alone
    // (OwnerAppSyncTest pins that).
    $js = collect(glob(resource_path('js/owner-app/*.js')))->map(fn ($f) => file_get_contents($f))->implode("\n");
    $js = (string) preg_replace(['#/\*.*?\*/#s', '#(^|\s)//[^\n]*#'], ['', '$1'], $js);

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight', 'scrollWidth', 'scrollHeight', 'getComputedStyle', 'ResizeObserver', 'setInterval', 'indexedDB'] as $api) {
        expect(str_contains($js, $api))->toBeFalse("owner app JS uses {$api}");
    }

    $app = (string) file_get_contents(resource_path('js/owner-app/owner-app.js'));
    // Lane OA4: Customise app can also switch the live check off (fn('live')).
    expect($app)->toContain("if (document.visibilityState !== 'visible' || S.stage !== 'app' || !fn('live')) return;")
        ->toContain("if (document.visibilityState === 'hidden') { stopPolling(); return; }");

    $css = (string) file_get_contents(resource_path('css/owner-app/owner-app.css'));
    // The blurred "Refreshing the app" card is gone (Lane OA2: grey bars
    // only when stale, silent otherwise — OwnerAppSyncTest).
    expect($app)->not->toContain('Refreshing the app')
        ->and($css)->not->toContain('backdrop-filter: blur(')
        ->and($css)->toContain('prefers-reduced-motion: reduce');
});
