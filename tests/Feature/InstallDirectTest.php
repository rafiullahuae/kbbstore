<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\SiteApp;
use App\Services\SiteFooter;
use Tests\Support\OwnerAppRoutes as OA;
use Tests\Support\SiteAppRoutes;

/*
 * INSTALL, DIRECTLY (Lane IN, 6 October).
 *
 * The owner: "the install app button is not giving auto install, i don't want
 * popup, i want if user click from android or iphone, it should give install
 * itself by receiving permission from the device itself. no any additional
 * steps i need. and for owner app, when login first time, and if app is not
 * installed, it should give immidiately install option to install the app".
 *
 * What a page can do: on Chrome / Edge for Android, call prompt() on the
 * browser's held install offer from a tap — the browser's own install box,
 * nothing of ours. What it cannot: make Chrome hand over that offer before its
 * engagement rule is met, or install anything at all on an iPhone (no API;
 * only Safari's Share -> Add to Home Screen). Where it cannot, the shop row now
 * says the one step left INSIDE the row, with an arrow at the browser's
 * button, instead of the pop-up sheet he refused; and the owner app shows an
 * install card at the top of My store after sign-in.
 *
 * Every test names the defect it would catch and the mutation that turns it red.
 */

function inSet(string $key, mixed $value): void
{
    Setting::query()->updateOrCreate(['key' => $key], ['value' => is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? '1' : '0') : $value), 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function inFooter(array $values): void
{
    app(SiteFooter::class)->save($values);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function inNode(string $js, string $ext): array|string
{
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        test()->markTestSkipped('node is not installed here');
    }
    $file = storage_path('framework/testing/in-'.getmypid().'-'.bin2hex(random_bytes(3)).'.'.$ext);
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, $js);
    $raw = (string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
    @unlink($file);

    return json_decode(trim((string) strrchr("\n".trim($raw), "\n")), true) ?? $raw;
}

const IN_UA = [
    'android' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36',
    'samsung' => 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/27.0 Chrome/125.0 Mobile Safari/537.36',
    'iphone18' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1',
    'iphone26' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1',
    'ipad' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15',
    'instagram' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 350.0',
];

/**
 * The footer row's script (resources/site-app/site-app.js) in node, with just
 * enough of a browser around it: the row, its button, its template carrying
 * the hints, a fake clock and the window's event listeners.
 */
function inShop(array $env): array|string
{
    $E = json_encode($env + ['ua' => IN_UA['android'], 'early' => false, 'late' => false, 'help' => null, 'installed' => null, 'lang' => 'en-US', 'touch' => 0]);
    $src = json_encode((string) file_get_contents(resource_path('site-app/site-app.js')));
    $hints = json_encode(json_encode(['and' => 'H-and', 'sam' => 'H-sam', 'ios' => 'H-ios', 'ios26' => 'H-ios26', 'top' => 'H-top', 'inapp' => 'H-inapp']));
    $seq = json_encode(json_encode([[0, 'Hello'], [1, 'مرحبا']], JSON_UNESCAPED_UNICODE));

    return inNode(<<<JS
const vm = require('vm');
const E = {$E};
const out = { prompted: 0, sheets: 0, styles: [] };
function cl() { const s = new Set(); return { s, toggle(c, on) { if (on === undefined) on = !s.has(c); on ? s.add(c) : s.delete(c); return on; }, contains: (c) => s.has(c), remove: (c) => s.delete(c), add: (c) => s.add(c) }; }
function el(tag) { return { tag, className: '', attrs: {}, children: [], parentNode: null, style: {}, classList: cl(), on: {},
  setAttribute(k, v) { this.attrs[k] = String(v); }, getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; },
  appendChild(c) { c.parentNode = this; this.children.push(c); return c; }, insertBefore(c) { return this.appendChild(c); },
  removeChild(c) { this.children = this.children.filter((x) => x !== c); c.parentNode = null; },
  addEventListener(t, f) { this.on[t] = f; }, querySelector: () => null, focus() {} }; }
const body = el('body'), head = el('head');
const ty = el('span'); ty.textContent = 'Hello';
const vh = el('span'); vh.textContent = 'Hello';
const btn = el('button');
const part = el('div');
const tpl = el('template'); tpl.attrs['data-hint'] = {$hints}; tpl.attrs['data-close'] = 'Close';
tpl.content = { querySelector: (q) => (q.indexOf('data-s=') !== -1 && q.indexOf('qr') === -1 ? part : null) };
const row = el('section'); row.hidden = false; row.attrs['data-kfa'] = {$seq};
if (E.help) row.attrs['data-kfa-help'] = E.help;
row.hasAttribute = (k) => k in row.attrs;
row.querySelector = (q) => ({ '.kfa-bt': btn, 'template.kfa-tpl': tpl, '.kfa-ty': ty, '.kfa-vh': vh })[q] || null;
const html = el('html'); html.attrs = { lang: 'en', dir: 'ltr' };
const docOn = {};
const document = { currentScript: null, hidden: false, documentElement: html, body, head,
  querySelector: (q) => (q === '.kfa[data-kfa]' ? row : null),
  getElementById: (id) => head.children.find((c) => c.id === id) || null,
  createElement: el, importNode: (n) => el('div'),
  addEventListener(t, f) { docOn[t] = f; }, removeEventListener(t, f) { if (docOn[t] === f) delete docOn[t]; } };
const timers = new Map(); let tid = 0;
const setTimeout = (f, ms) => { const id = ++tid; timers.set(id, { f, ms }); return id; };
const clearTimeout = (id) => { timers.delete(id); };
const winOn = {};
function offer() { return { prompt() { out.prompted++; }, userChoice: Promise.resolve({ outcome: 'dismissed' }), preventDefault() { out.prevented = true; } }; }
class IntersectionObserver { constructor(cb) { out.io = cb; } observe() {} }
const window = { matchMedia: () => ({ matches: false }), addEventListener(t, f) { winOn[t] = f; }, IntersectionObserver,
  __kbbBip: E.early ? offer() : undefined, localStorage: { getItem: () => null, setItem() {} } };
const navigator = { userAgent: E.ua, language: E.lang, maxTouchPoints: E.touch };
if (E.installed !== null) navigator.getInstalledRelatedApps = () => Promise.resolve(E.installed ? [{ platform: 'webapp' }] : []);
vm.runInContext({$src}, vm.createContext({ document, window, navigator, setTimeout, clearTimeout, JSON, Array, String, Promise, IntersectionObserver }));
(async () => {
  await Promise.resolve(); await Promise.resolve();
  out.hiddenAtStart = row.hidden;
  out.io([{ isIntersecting: true }]);
  out.typingBefore = timers.size;
  if (E.late === 'before') winOn.beforeinstallprompt(offer());
  btn.on.click();
  out.sheets = body.children.filter((c) => c.className === 'kfa-bk').length;
  out.hintText = ty.textContent;
  out.hintClass = row.classList.contains('kfa-hint');
  const ar = body.children.find((c) => /kfa-ar/.test(c.className));
  out.arrow = ar ? ar.className : null;
  out.styles = head.children.map((c) => c.id);
  out.typingDuring = row.classList.contains('kfa-run');
  out.live = vh.attrs['aria-live'] || null;
  out.sixSeconds = [...timers.values()].some((t) => t.ms === 6000);
  if (E.late === 'after') {
    winOn.beforeinstallprompt(offer());
    out.hintAfterOffer = row.classList.contains('kfa-hint');
    btn.on.click();
  } else if (E.late === 'tap') {
    docOn.pointerdown && docOn.pointerdown();
    out.hintAfterTap = row.classList.contains('kfa-hint');
    out.arrowAfterTap = body.children.some((c) => /kfa-ar/.test(c.className));
  } else if (E.late === 'wait') {
    const t = [...timers.values()].find((x) => x.ms === 6000); t.f();
    out.hintAfterWait = row.classList.contains('kfa-hint');
    out.textAfterWait = ty.textContent;
    out.typingAfterWait = row.classList.contains('kfa-run');
  }
  out.hidden = row.hidden;
  console.log(JSON.stringify(out));
})();
JS, 'cjs');
}

beforeEach(function () {
    SiteAppRoutes::wire($this->app);
});

/* ───────────────────────────────────────────── manifests ── */

it('names each app in its own manifest under related_applications, so Chrome can tell a browser tab it is installed', function () {
    /* DEFECT: getInstalledRelatedApps() answers [] on a phone that has the app,
       so the shop row and the owner card keep offering an install that is
       already done. Chrome needs platform "webapp" and the manifest's FULL
       address. MUTATION: drop 'related_applications' from SiteApp::manifest()
       or from AppController::manifest() -> red. */
    $m = $this->get('/manifest.webmanifest')->assertOk()->json();
    expect($m['related_applications'] ?? null)->toBeArray()
        ->and($m['related_applications'][0]['platform'])->toBe('webapp')
        ->and($m['related_applications'][0]['url'])->toMatch('#^https?://[^/]+/manifest\.webmanifest$#')
        ->and($m)->not->toHaveKey('prefer_related_applications');   // never suppresses the install offer

    OA::wire($this->app);
    $base = OA::base();
    $o = $this->get($base.'/manifest.webmanifest')->assertOk()->json();
    expect($o['related_applications'] ?? null)->toBe([['platform' => 'webapp', 'url' => 'http://localhost'.$base.'/manifest.webmanifest']]);
});

/* ───────────────────────────────────────────── the shop's page ── */

it('prints the early offer catcher only while the app row is on, and the hints and the switch on the row', function () {
    /* DEFECT: a catcher on a shop whose row is off (it would take Chrome's
       offer and show nothing); a hint the script cannot find. MUTATION: drop
       appRowOn() from partials/site-app-head -> the "row off" case is red. */
    $catcher = "<script>addEventListener('beforeinstallprompt'";
    $html = (string) $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, $catcher))->toBe(1)
        ->and(strpos($html, $catcher))->toBeLessThan(strpos($html, '</head>'))
        ->and($html)->not->toContain('data-kfa-help')
        ->and($html)->toMatch('#<template class="kfa-tpl" data-close="Close" data-hint="\{&quot;and&quot;:&quot;Tap ⋮ in your browser, then “Install app”.&quot;,&quot;sam&quot;:.*?&quot;inapp&quot;:&quot;Open this page in Safari or Chrome to install.&quot;\}">#');

    inFooter(['site_app_help' => 'sheet']);
    expect((string) $this->get('/')->getContent())->toContain(' data-kfa-help=sheet>');

    inFooter(['site_app_help' => '<script>']);   // a select keeps one of its own options
    expect(app(SiteFooter::class)->all()['site_app_help'])->toBeIn(['inline', 'sheet']);

    inFooter(['site_app_on' => false]);
    expect((string) $this->get('/')->getContent())->not->toContain($catcher);

    inFooter(['site_app_on' => true, 'site_design' => 'classic']);
    expect((string) $this->get('/')->getContent())->not->toContain($catcher);

    inFooter(['site_design' => 'bliss']);
    app(SiteApp::class)->save(['on' => false]);
    inSet('noop', '1');
    expect((string) $this->get('/')->getContent())->not->toContain('beforeinstallprompt');
});

it('Android with an offer: the tap opens the browser’s own install box and nothing of ours', function () {
    /* DEFECT: the sheet, or our hint, when the browser could install in one
       tap. MUTATION: in the click handler, call sheet() before the offer
       branch -> sheets is 1; drop `window.__kbbBip ||` -> `early` prompts 0. */
    foreach (['early' => ['early' => true], 'late' => ['late' => 'before']] as $why => $env) {
        $r = inShop($env);
        expect($r, is_string($r) ? $r : $why)->toBeArray()
            ->and($r['prompted'])->toBe(1, $why)
            ->and($r['sheets'])->toBe(0, $why)
            ->and($r['hintClass'])->toBeFalse($why)
            ->and($r['arrow'])->toBeNull($why)
            ->and($r['hintText'])->toBe('Hello', $why);
    }
});

it('Android without an offer: the row’s own line says ⋮ then Install app, an arrow points at the menu, no sheet — and an offer that arrives later installs on the next tap', function () {
    /* DEFECT: the pop-up sheet the owner refused ("i don't want popup").
       MUTATION: make the click handler always call sheet(kind()) -> sheets 1
       and hintClass false; drop the beforeinstallprompt listener -> prompted 0. */
    $r = inShop(['late' => 'after']);

    expect($r, is_string($r) ? $r : '')->toBeArray()
        ->and($r['sheets'])->toBe(0)
        ->and($r['hintText'])->toBe('H-and')
        ->and($r['hintClass'])->toBeTrue()
        ->and($r['arrow'])->toBe('kfa-ar kfa-ar-tr')
        ->and($r['styles'])->toBe(['kfa-hcss'])             // the hint's CSS, not the sheet's
        ->and($r['typingDuring'])->toBeFalse()             // the typing stops under the hint
        ->and($r['live'])->toBe('polite')
        ->and($r['sixSeconds'])->toBeTrue()
        ->and($r['hintAfterOffer'])->toBeFalse()
        ->and($r['prompted'])->toBe(1);
});

it('the hint ends after six seconds or at any tap, and the typing comes back', function () {
    /* DEFECT: an arrow that never leaves, or a dead typing line. MUTATION: drop
       the pointerdown listener -> arrowAfterTap is true; drop sync() from
       unhint() -> typingAfterWait is false. */
    $tap = inShop(['late' => 'tap']);
    $wait = inShop(['late' => 'wait']);

    expect($tap['hintAfterTap'])->toBeFalse()
        ->and($tap['arrowAfterTap'])->toBeFalse()
        ->and($wait['hintAfterWait'])->toBeFalse()
        ->and($wait['textAfterWait'])->toBe('Hello')
        ->and($wait['typingAfterWait'])->toBeTrue();
});

it('points at the right browser button on each phone, and mirrors for a phone set to Arabic', function () {
    /* DEFECT: "tap ⋮" on an iPhone, or an arrow at the wrong corner. MUTATION:
       return ['ios', 'bc'] for every iPhone in where() -> the iOS 26 line is
       red; drop the SamsungBrowser test -> the Samsung line is red. */
    $cases = [
        'samsung' => [['ua' => IN_UA['samsung']], 'H-sam', 'kfa-ar kfa-ar-br'],
        'iphone18' => [['ua' => IN_UA['iphone18']], 'H-ios', 'kfa-ar kfa-ar-bc'],
        'iphone26' => [['ua' => IN_UA['iphone26']], 'H-ios26', 'kfa-ar kfa-ar-br'],
        'ipad' => [['ua' => IN_UA['ipad'], 'touch' => 5], 'H-top', 'kfa-ar kfa-ar-tr'],
        'instagram' => [['ua' => IN_UA['instagram']], 'H-inapp', 'kfa-ar kfa-ar-tr'],
        'android, Arabic phone' => [['lang' => 'ar-AE'], 'H-and', 'kfa-ar kfa-ar-tl'],
    ];
    foreach ($cases as $why => [$env, $text, $arrow]) {
        $r = inShop($env);
        expect($r, is_string($r) ? $r : $why)->toBeArray()
            ->and($r['hintText'])->toBe($text, $why)
            ->and($r['arrow'])->toBe($arrow, $why)
            ->and($r['sheets'])->toBe(0, $why)
            ->and($r['prompted'])->toBe(0, $why);
    }
});

it('opens the old sheet instead when Install help is set to the pop-up', function () {
    /* MUTATION: ignore data-kfa-help in the click handler -> sheets is 0. */
    $r = inShop(['help' => 'sheet', 'ua' => IN_UA['iphone18']]);

    expect($r['sheets'])->toBe(1)
        ->and($r['hintClass'])->toBeFalse()
        ->and($r['arrow'])->toBeNull()
        ->and($r['styles'])->toBe(['kfa-css']);
});

it('hides the row in the browser on a phone that already has the app installed', function () {
    /* DEFECT: "Install App" on a phone that has it. MUTATION: drop the
       getInstalledRelatedApps() call -> hidden is false. */
    expect(inShop(['installed' => true])['hidden'])->toBeTrue()
        ->and(inShop(['installed' => false])['hidden'])->toBeFalse()
        ->and(inShop([])['hiddenAtStart'])->toBeFalse();   // starts drawn: nothing reserved, nothing flashes in
});

it('keeps the row script light: no layout read, no request, no endless timer', function () {
    /* MUTATION: measure the button for the arrow (getBoundingClientRect) -> red. */
    $src = (string) file_get_contents(resource_path('site-app/site-app.js'));
    $row = substr($src, (int) strpos($src, "THE FOOTER'S APP ROW"), (int) strpos($src, 'var s = document.currentScript;') - (int) strpos($src, "THE FOOTER'S APP ROW"));
    foreach (['getBoundingClientRect', 'offsetTop', 'offsetLeft', 'getComputedStyle', 'setInterval', 'requestAnimationFrame', 'fetch('] as $banned) {
        expect(str_contains($row, $banned))->toBeFalse("the row script uses {$banned}");
    }
    expect($row)->toContain('hintT = setTimeout(unhint, 6000);')
        ->and($row)->toContain("document.removeEventListener('pointerdown', unhint, true);");
});

/* ───────────────────────────────────────────── the owner app ── */

/** install.js and ask.js (with core.js under them) in node, a browser stubbed around them. */
function inOwner(array $env): array|string
{
    $E = json_encode($env + ['ua' => IN_UA['android'], 'standalone' => false, 'offer' => false, 'icDaysAgo' => null, 'installed' => null, 'act' => null, 'forceIc' => false]);
    $core = 'file://'.resource_path('js/owner-app/core.js');
    $inst = 'file://'.resource_path('js/owner-app/install.js');
    $ask = 'file://'.resource_path('js/owner-app/ask.js');

    return inNode(<<<JS
const E = {$E};
const out = { prompted: 0 };
const mem = () => { const m = {}; return { getItem: (k) => (k in m ? m[k] : null), setItem: (k, v) => { m[k] = String(v); }, removeItem: (k) => { delete m[k]; } }; };
const winOn = {};
globalThis.window = { sessionStorage: mem(), localStorage: mem(), PushManager: function () {},
  Notification: { permission: 'default', requestPermission: () => Promise.resolve('default') },
  addEventListener(t, f) { winOn[t] = f; },
  matchMedia: (q) => ({ matches: E.standalone && q.indexOf('standalone') !== -1, addEventListener() {} }) };
globalThis.requestAnimationFrame = (f) => f();
const nav = { userAgent: E.ua, platform: /Mac/.test(E.ua) ? 'MacIntel' : 'Linux', maxTouchPoints: 0, serviceWorker: {} };
if (E.installed !== null) nav.getInstalledRelatedApps = () => Promise.resolve(E.installed ? [{}] : []);
Object.defineProperty(globalThis, 'navigator', { value: nav, configurable: true });
const html = { classList: { add() {}, remove() {}, contains: () => false }, style: { setProperty() {}, removeProperty() {}, getPropertyValue: () => '' } };
const sheets = [];
const docOn = {};
let cards = [];
globalThis.document = { documentElement: html, body: { getAttribute: () => '/oa', appendChild(n) { if (n.className === 'sheet a2' || /sheet/.test(n.className || '')) sheets.push(n); } },
  createElement: () => ({ classList: { add() {}, remove() {} }, setAttribute() {}, addEventListener() {}, remove() {}, style: {}, querySelectorAll: () => [], querySelector: () => ({ addEventListener() {} }) }),
  addEventListener(t, f) { docOn[t] = f; }, removeEventListener() {}, querySelectorAll: (q) => (q === '[data-ic]' ? cards : []), querySelector: () => null };
const core = await import('{$core}');
if (E.icDaysAgo !== null) core.store.set('oa.ic', Date.now() - E.icDaysAgo * 864e5);
const inst = await import('{$inst}');
const ask = await import('{$ask}');
Object.assign(core.S, { askPush: true, vapid: 'BKey', me: { notify: ['orders'] }, groups: { orders: 'New orders' } });
await new Promise((r) => setTimeout(r, 1));
if (E.offer) winOn.beforeinstallprompt({ preventDefault() {}, prompt() { out.prompted++; }, userChoice: Promise.resolve({ outcome: 'accepted' }) });
out.card = inst.card();
out.icOn = core.S.icOn;
if (E.forceIc) core.S.icOn = true;
out.pushSheet = ask.offerPush(async () => true);
const btn = (attr) => ({ target: { closest: (q) => (q.indexOf(attr) !== -1 ? { hasAttribute: (a) => a === attr, closest: () => ({ remove() { out.removed = true; } }) } : null) } });
if (E.act === 'later') { out.took = inst.cardClick(btn('data-ic-later')); out.stored = core.store.get('oa.ic') !== null; out.cardAfter = inst.card(); }
if (E.act === 'go') { out.took = inst.cardClick(btn('data-ic-go')); }
out.days = inst.NOT_NOW_DAYS;
console.log(JSON.stringify(out));
process.exit(0);
JS, 'mjs');
}

it('shows the install card at the top of My store in a browser tab after sign-in, never in the installed app', function () {
    /* DEFECT: no install offer for the owner after his first sign-in, or the
       card inside the installed app. MUTATION: drop standalone() from
       install.js wanted() -> the standalone case shows a card; prepend nothing
       in renderDashboard -> the store.js check is red. */
    $r = inOwner([]);
    expect($r, is_string($r) ? $r : '')->toBeArray()
        ->and($r['card'])->toContain('data-ic')->toContain('Install KBB Owner')
        ->and($r['card'])->toContain('data-ic-show')->toContain('Tap <b>⋮</b> at the top, then <b>Install app</b>')
        ->and($r['card'])->not->toContain('style="')
        ->and($r['icOn'])->toBeTrue();

    expect(inOwner(['standalone' => true])['card'])->toBe('')
        ->and(inOwner(['installed' => true])['card'])->toBe('')   // installed on this phone, seen from the tab
        ->and(inOwner(['ua' => IN_UA['iphone18']])['card'])->toContain('<b>Share</b> below, then <b>Add to Home Screen</b>')
        ->and(inOwner(['ua' => IN_UA['iphone26']])['card'])->toContain('<b>•••</b> below');

    $store = (string) file_get_contents(resource_path('js/owner-app/store.js'));
    expect(substr_count($store, "installCard() + dashSkel()"))->toBe(1)
        ->and(substr_count($store, "installCard() + body(D.data)"))->toBe(2)
        // The old pop-up that opened by itself is gone.
        ->and($store)->not->toContain('setTimeout(openInstall');
});

it('with Chrome’s offer the card’s button is the browser’s own install box, from the tap', function () {
    /* MUTATION: draw data-ic-show whatever the offer -> the first line is red;
       drop o.prompt() from cardClick -> prompted is 0. */
    $r = inOwner(['offer' => true, 'act' => 'go']);
    expect($r['card'])->toContain('data-ic-go')->toContain('Install app')->not->toContain('ic-hint')
        ->and($r['took'])->toBeTrue()
        ->and($r['prompted'])->toBe(1);
});

it('“Not now” stores oa.ic and keeps the card away for seven days', function () {
    /* MUTATION: NOT_NOW_DAYS = 0 -> the 6-days case shows; skip store.set in
       cardClick -> stored is false. */
    $r = inOwner(['act' => 'later']);
    expect($r['took'])->toBeTrue()->and($r['stored'])->toBeTrue()->and($r['removed'])->toBeTrue()
        ->and($r['cardAfter'])->toBe('')->and($r['days'])->toBe(7)
        ->and(inOwner(['icDaysAgo' => 6])['card'])->toBe('')
        ->and(inOwner(['icDaysAgo' => 8])['card'])->toContain('data-ic');
});

it('never shows the install card and the notifications sheet at once', function () {
    /* DEFECT: two asks stacked on one screen. In a tab the card shows and the
       sheet stays quiet; in the installed app the sheet may ask and there is
       no card. MUTATION: drop `S.icOn ||` from ask.js shouldAsk() -> the last
       line is red. */
    $tab = inOwner([]);
    $app = inOwner(['standalone' => true]);
    expect($tab['icOn'])->toBeTrue()->and($tab['pushSheet'])->toBeFalse()
        ->and($app['icOn'])->toBeFalse()->and($app['pushSheet'])->toBeTrue();

    // A card still up when the sheet would ask: the sheet waits.
    expect(inOwner(['standalone' => true, 'forceIc' => true])['pushSheet'])->toBeFalse();
});
