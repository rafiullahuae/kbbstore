<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\SiteApp;
use App\Services\SiteAppUpdate;
use App\Support\Locale;
use App\Services\Translation\TranslationStore;
use Tests\Support\SiteAppRoutes;

/*
 * App → Site App → App update (Lane UA).
 *
 * The owner, 6 October: "also if any user already installed, then the row will
 * not show, but if we published any updates in the site app, then we will have
 * option to enable to display again on the existing users device too with
 * Update App button."
 *
 * Every test names the defect it would catch and the mutation that turns it red.
 */

function uaAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'UA '.$role, 'email' => 'ua-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

function uaFlush(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function uaSet(string $key, mixed $value): void
{
    Setting::query()->updateOrCreate(['key' => $key], ['value' => is_array($value) ? json_encode($value) : $value, 'autoload' => true]);
    uaFlush();
}

/** The row's opening tag out of a rendered page. */
function uaRowTag(string $html): string
{
    return preg_match('#<section class="kfa[^>]*>#u', $html, $m) ? $m[0] : '';
}

beforeEach(function () {
    SiteAppRoutes::wire($this->app);
});

it('changes nothing on the page or in the worker while nothing was ever published', function () {
    /* DEFECT: every page and every installed worker moved by a package that
       only added a card (CLAUDE.md rule 1). MUTATION: print data-kfa-up
       unconditionally, or add 'u0' to SiteApp::version() -> red. */
    $html = (string) $this->get('/')->assertOk()->getContent();
    $tag = uaRowTag($html);

    expect($tag)->toStartWith('<section class="kfa" aria-labelledby="kfa-h" data-kfa="')
        ->and($tag)->not->toContain('data-kfa-up')
        ->and(Setting::query()->where('key', SiteAppUpdate::SETTING)->exists())->toBeFalse()
        ->and(app(SiteAppUpdate::class)->page())->toBe('');

    // The worker's version is exactly the files' hash, as before this lane.
    $parts = [SiteApp::fileHash(resource_path('site-app/sw.js')), SiteApp::fileHash(SiteApp::scriptPath()), SiteApp::fileHash(resource_path('views/site-app/offline.blade.php'))];
    foreach (array_keys(SiteApp::ICONS) as $k) {
        $parts[] = SiteApp::fileHash(SiteApp::iconPath($k));
    }
    expect(SiteApp::version())->toBe(substr(hash('sha256', implode('|', $parts)), 0, 12));
});

it('lets only siteapp.manage publish or switch the row, and refuses a guest', function () {
    /* Fail closed. MUTATION: drop the ['*', 'admin-api/site-app/**', ...] rule
       from AdminCapabilities::RULES -> the manager posts are red; mount the
       routes outside the auth:admin group -> the guest lines are red. */
    foreach (['editor', 'support'] as $role) {
        $this->actingAs(uaAdmin($role), 'admin')->postJson('/admin-api/site-app/update', [])->assertForbidden();
        $this->actingAs(uaAdmin($role), 'admin')->postJson('/admin-api/site-app/update/show', ['show' => false])->assertForbidden();
    }
    expect(app(SiteAppUpdate::class)->number())->toBe(0);

    $this->app['auth']->forgetGuards();
    expect($this->postJson('/admin-api/site-app/update', [])->status())->toBeIn([401, 302, 403, 419]);
    expect(app(SiteAppUpdate::class)->number())->toBe(0);

    $this->actingAs(uaAdmin('manager'), 'admin')->postJson('/admin-api/site-app/update', [])->assertOk()
        ->assertJsonPath('update.v', 1)->assertJsonPath('update.by', 'UA manager')->assertJsonPath('update.show', true);
});

it('publishes the next number with both messages, who and when, and moves the worker version', function () {
    /* DEFECT: a "published" update with no new worker behind it, so Update App
       reloads onto the very same version. MUTATION: drop the 'u'.$update part
       from SiteApp::version() -> the versions are equal. */
    $owner = uaAdmin();
    $before = SiteApp::version();
    $worker = app(SiteApp::class)->worker();

    $this->actingAs($owner, 'admin')->postJson('/admin-api/site-app/update', ['en' => '  New   look  ', 'ar' => ''])->assertOk()
        ->assertJsonPath('update.v', 1)->assertJsonPath('update.en', 'New look')
        ->assertJsonPath('update.ar', SiteAppUpdate::DEFAULT_AR)->assertJsonPath('update.by', 'UA owner');
    uaFlush();
    $one = SiteApp::version();

    $this->actingAs($owner, 'admin')->postJson('/admin-api/site-app/update', [])->assertOk()->assertJsonPath('update.v', 2)
        ->assertJsonPath('update.en', SiteAppUpdate::DEFAULT_EN);
    uaFlush();

    expect($one)->not->toBe($before)
        ->and(SiteApp::version())->not->toBe($one)
        ->and(app(SiteApp::class)->worker())->not->toBe($worker)
        ->and(app(SiteApp::class)->worker())->toContain('const VERSION = "'.SiteApp::version().'";')
        ->and(app(SiteAppUpdate::class)->state()['at'])->toBeString();
});

it('refuses a bad message or field without changing a thing', function () {
    /* MUTATION: drop the strip_tags check in cleanMessage() -> the tag line
       publishes. */
    $owner = uaAdmin();
    foreach ([['en' => '<b>x</b>'], ['en' => str_repeat('a', 81)], ['ar' => ['x']], ['icon' => 'yes'], ['v' => 9], ['en' => "a\x07b"]] as $body) {
        $this->actingAs($owner, 'admin')->postJson('/admin-api/site-app/update', $body)->assertStatus(422);
    }
    $this->actingAs($owner, 'admin')->postJson('/admin-api/site-app/update/show', ['show' => true])->assertStatus(422);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/site-app/update/show', ['show' => 'no'])->assertStatus(422);
    expect(Setting::query()->where('key', SiteAppUpdate::SETTING)->exists())->toBeFalse();
});

it('puts the update on the row in the page language, and only the number once the switch is off', function () {
    /* DEFECT: the switch that does not switch, or English words on the Arabic
       app. MUTATION: ignore `show` in page() -> the off page still carries the
       words; always use 'en' -> the Arabic line is red. */
    $owner = uaAdmin();
    $this->actingAs($owner, 'admin')->postJson('/admin-api/site-app/update', ['en' => 'Fresh & "new"', 'ar' => 'جديد'])->assertOk();
    uaFlush();

    $tag = uaRowTag((string) $this->get('/')->getContent());
    preg_match('#data-kfa-up="([^"]*)"#', $tag, $m);
    $up = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);
    expect($up)->toMatchArray(['v' => 1, 't' => 'Fresh & "new"', 'b' => 'Update App', 'x' => 'Close'])
        ->and($up)->toHaveKey('l')
        ->and($tag)->not->toContain('"new"' /* escaped by Blade */);

    uaSet(Locale::SETTING_ENABLED, '1');
    uaSet(Locale::SETTING_RTL, '1');
    TranslationStore::flush();
    $ar = uaRowTag((string) $this->get('/ar/')->getContent());
    expect(html_entity_decode($ar, ENT_QUOTES))->toContain('"t":"جديد"');

    $this->actingAs($owner, 'admin')->postJson('/admin-api/site-app/update/show', ['show' => false])->assertOk()->assertJsonPath('update.show', false);
    uaFlush();
    $off = uaRowTag((string) $this->get('/')->getContent());
    expect($off)->toContain('data-kfa-up="{&quot;v&quot;:1}"');
});

it('flags the iPhone icon tip only for an update that changed the icon or name', function () {
    /* DEFECT: "remove the app and add it again" told to every iPhone on every
       update, for nothing. MUTATION: set look_v on every publish -> the second
       publish's k is 2. */
    $owner = uaAdmin();
    $this->actingAs($owner, 'admin')->postJson('/admin-api/site-app/update', ['icon' => false])->assertOk();
    uaFlush();
    expect(json_decode(app(SiteAppUpdate::class)->page(), true))->not->toHaveKey('h')->and(app(SiteAppUpdate::class)->lookChanged())->toBeFalse();

    app(SiteApp::class)->save(['name' => 'KB Glow']);
    uaFlush();
    $this->actingAs($owner, 'admin')->getJson('/admin-api/site-app')->assertJsonPath('update.look_changed', true);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/site-app/update', [])->assertOk()->assertJsonPath('update.look_v', 2);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/site-app/update', [])->assertOk()->assertJsonPath('update.look_v', 2);
    uaFlush();
    $up = json_decode(app(SiteAppUpdate::class)->page(), true);
    expect($up['v'])->toBe(3)->and($up['k'])->toBe(2)->and($up['h'])->toBe('To see the new icon: remove the app and add it again.');
});

/**
 * The footer row's script in node, around a stubbed browser: standalone or a
 * tab, iPhone or not, what this phone stored, whether a worker is in charge,
 * and what registration.update() finds.
 *
 * @param  array<string,mixed>  $o
 * @return array<string,mixed>
 */
function uaRun(array $o): array
{
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        test()->markTestSkipped('node is not installed here');
    }
    $cfg = json_encode($o + ['app' => true, 'ios' => false, 'stored' => null, 'controller' => true, 'up' => null, 'reg' => 'waiting', 'click' => false, 'close' => false], JSON_UNESCAPED_UNICODE);
    $src = json_encode((string) file_get_contents(resource_path('site-app/site-app.js')));
    $js = <<<JS
const vm = require('vm');
const C = {$cfg};
function cl() { const s = new Set(); return { s, toggle(c, on) { if (on === undefined) on = !s.has(c); on ? s.add(c) : s.delete(c); return on; }, contains: (c) => s.has(c), remove: (c) => s.delete(c), add: (c) => s.add(c) }; }
function mk(tag) { const e = { tag, attrs: {}, kids: [], on: {}, classList: cl(), textContent: '', hidden: false, disabled: false,
  setAttribute(k, v) { this.attrs[k] = String(v); }, getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; },
  addEventListener(t, f) { this.on[t] = f; }, appendChild(c) { this.kids.push(c); c.parentNode = this; return c; },
  insertBefore(c, ref) { const i = this.kids.indexOf(ref); this.kids.splice(i < 0 ? this.kids.length : i, 0, c); c.parentNode = this; return c; },
  get firstChild() { return this.kids[0] || null; }, get nextSibling() { const p = this.parentNode; return p ? p.kids[p.kids.indexOf(this) + 1] || null : null; } };
  return e; }
const wrap = mk('div'), g = mk('div'), h = mk('b'), ty = mk('span'), vh = mk('span'), lab = mk('span'), path = mk('path'), btn = mk('button');
wrap.appendChild(g); g.appendChild(btn); h.textContent = 'Get the app'; ty.textContent = 'Hello'; lab.textContent = 'Install App';
btn.querySelector = (q) => (q === 'span' ? lab : q === 'path' ? path : null);
const row = mk('section'); row.attrs['data-kfa'] = JSON.stringify([[0, 'Hello'], [1, 'مرحبا']]);
if (C.up !== null) row.attrs['data-kfa-up'] = JSON.stringify(C.up);
row.querySelector = (q) => ({ '.kfa-bt': btn, '.kfa-ty': ty, '.kfa-g': g, '#kfa-h': h, '.kfa-vh': vh }[q] || null);
const html = mk('html'); html.attrs.lang = 'en'; html.attrs.dir = 'ltr';
const head = mk('head'); const ids = {};
const icon = mk('link'); icon.attrs.href = '/site-app/icons/apple-180.png?v=1';
const document = { currentScript: null, hidden: false, documentElement: html, head,
  querySelector: (q) => (q === '.kfa[data-kfa]' ? row : q === 'link[rel="apple-touch-icon"]' ? icon : null),
  getElementById: (id) => ids[id] || null, createElement: (t) => mk(t), addEventListener() {} };
head.appendChild = function (c) { if (c.id) ids[c.id] = c; this.kids.push(c); return c; };
const store = {}; if (C.stored !== null) store['kbb.sa.up'] = String(C.stored);
const localStorage = { getItem: (k) => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); } };
const timers = []; const setTimeout = (f) => { timers.push(f); return timers.length; }; const clearTimeout = () => {};
const log = []; let reloads = 0; const swOn = {};
const w = { state: C.reg === 'waiting' ? 'installed' : 'installing', on: {}, addEventListener(t, f) { this.on[t] = f; }, postMessage(m) { log.push(m); } };
const reg = { waiting: C.reg === 'waiting' ? w : null, installing: C.reg === 'installing' ? w : null, update() { log.push('update'); return Promise.resolve(); } };
const serviceWorker = { controller: C.controller ? {} : null, addEventListener(t, f) { swOn[t] = f; },
  getRegistration() { log.push('getRegistration'); return Promise.resolve(C.reg === 'noreg' ? undefined : reg); } };
let io = null; class IntersectionObserver { constructor(cb) { io = cb; } observe() {} }
const mm = (q) => ({ matches: C.app && q.indexOf('display-mode: standalone') !== -1 });
const window = { matchMedia: mm, addEventListener() {}, IntersectionObserver, localStorage, location: { reload() { reloads++; } } };
const navigator = { userAgent: C.ios ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)' : 'Mozilla/5.0 (Linux; Android 14)', serviceWorker, maxTouchPoints: 5 };
vm.runInContext({$src}, vm.createContext({ document, window, navigator, setTimeout, clearTimeout, JSON, Array, String, IntersectionObserver, Promise }));
(async () => {
  const out = { hidden: row.hidden, up: row.classList.contains('kfa-up'), title: h.textContent, label: lab.textContent, line: ty.textContent,
    hint: wrap.kids.filter((k) => k.className === 'kfa-hn').map((k) => k.textContent)[0] || null, css: !!ids['kfa-up-css'],
    close: g.kids.some((k) => k.className === 'kfa-ux'), img: g.kids.some((k) => k.className === 'kfa-ai'), storedAtLoad: store['kbb.sa.up'] ?? null };
  if (io) { io([{ isIntersecting: true }]); out.waAside = html.classList.contains('kfa-on'); out.timers = timers.length; }
  if (C.click && btn.on.click) {
    btn.on.click();
    for (let i = 0; i < 5; i++) await Promise.resolve();
    out.storedAfterTap = store['kbb.sa.up'] ?? null; out.disabled = btn.disabled;
    if (C.reg === 'installing') { w.state = 'installed'; w.on.statechange(); }
    if (swOn.controllerchange) { swOn.controllerchange(); swOn.controllerchange(); }
    if (w.on.statechange && C.reg !== 'none') { w.state = 'activated'; w.on.statechange(); }
  }
  if (C.close) { const x = g.kids.find((k) => k.className === 'kfa-ux'); x.on.click(); out.hiddenAfterClose = row.hidden; out.storedAfterClose = store['kbb.sa.up']; out.waBack = !html.classList.contains('kfa-on'); }
  out.log = log; out.reloads = reloads;
  console.log(JSON.stringify(out));
})();
JS;
    $file = storage_path('framework/testing/ua-'.getmypid().'-'.bin2hex(random_bytes(3)).'.cjs');
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, $js);
    $raw = (string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
    @unlink($file);

    return json_decode(trim((string) strrchr("\n".trim($raw), "\n")), true) ?? ['raw' => $raw];
}

const UA_UP = ['v' => 2, 'k' => 0, 't' => 'A fresh new look is ready', 'l' => 'The newest version, one tap away.', 'b' => 'Update App', 'x' => 'Close'];

it('keeps the row hidden in the installed app while there is no update this phone has not seen', function () {
    /* DEFECT: an Install (or Update) row inside the installed app. MUTATION:
       drop `if (app && !upMode) { row.hidden = true; return; }` -> hidden is
       false. */
    foreach ([['up' => null, 'stored' => 0], ['up' => UA_UP, 'stored' => 2], ['up' => UA_UP, 'stored' => 5], ['up' => ['v' => 2], 'stored' => 1]] as $o) {
        $r = uaRun($o);
        expect($r)->toHaveKey('hidden')->and($r['hidden'])->toBeTrue()->and($r['up'])->toBeFalse();
    }
});

it('turns the same row into Update App in the installed app when the published update is newer', function () {
    /* The owner's ask. MUTATION: compare `pub >= mine` the wrong way round, or
       skip upRow() -> up is false / the label still reads Install App. */
    $r = uaRun(['up' => UA_UP, 'stored' => 1]);

    expect($r['hidden'])->toBeFalse()->and($r['up'])->toBeTrue()->and($r['css'])->toBeTrue()
        ->and($r['title'])->toBe('A fresh new look is ready')
        ->and($r['label'])->toBe('Update App')
        ->and($r['line'])->toBe('The newest version, one tap away.')
        ->and($r['close'])->toBeTrue()->and($r['img'])->toBeTrue()
        ->and($r['hint'])->toBeNull()
        // WhatsApp still steps aside, and nothing types: no timer at all.
        ->and($r['waAside'])->toBeTrue()->and($r['timers'])->toBe(0);
});

it('changes nothing in a browser tab, published or not', function () {
    /* DEFECT: "Update App" in the shop's browser tab. MUTATION: drop `app &&`
       from upMode -> up is true. */
    $r = uaRun(['app' => false, 'up' => UA_UP, 'stored' => 0]);
    expect($r['hidden'])->toBeFalse()->and($r['up'])->toBeFalse()->and($r['label'])->toBe('Install App')->and($r['title'])->toBe('Get the app');
});

it('never shows an old update to a fresh install, and shows it to an app installed before', function () {
    /* DEFECT: a shopper installs today and is told at once to update. MUTATION:
       start every phone with no number at 0 -> the fresh install shows the row. */
    $fresh = uaRun(['up' => UA_UP, 'stored' => null, 'controller' => false]);
    $old = uaRun(['up' => UA_UP, 'stored' => null, 'controller' => true]);
    $tab = uaRun(['app' => false, 'up' => UA_UP, 'stored' => null]);

    expect($fresh['hidden'])->toBeTrue()->and($fresh['storedAtLoad'])->toBe('2')
        ->and($old['up'])->toBeTrue()->and($old['storedAtLoad'])->toBe('0')
        ->and($tab['storedAtLoad'])->toBe('2');
});

it('updates on the tap: asks for the new worker, tells a waiting one to take over, and reloads exactly once', function () {
    /* MUTATION: drop the postMessage -> no SKIP_WAITING in the log; drop the
       `done` guard in reload() -> reloads is 2; drop keep(pub) -> stored stays 1. */
    $r = uaRun(['up' => UA_UP, 'stored' => 1, 'reg' => 'waiting', 'click' => true]);
    expect($r['log'])->toBe(['getRegistration', 'update', ['type' => 'SKIP_WAITING']])
        ->and($r['reloads'])->toBe(1)->and($r['storedAfterTap'])->toBe('2')->and($r['disabled'])->toBeTrue();

    $i = uaRun(['up' => UA_UP, 'stored' => 1, 'reg' => 'installing', 'click' => true]);
    expect($i['log'])->toBe(['getRegistration', 'update', ['type' => 'SKIP_WAITING']])->and($i['reloads'])->toBe(1);

    foreach (['none', 'noreg'] as $reg) {
        $n = uaRun(['up' => UA_UP, 'stored' => 1, 'reg' => $reg, 'click' => true]);
        expect($n['reloads'])->toBe(1)->and($n['storedAfterTap'])->toBe('2');
    }
});

it('closes for good on the x, and says the iPhone tip only on an iPhone that missed an icon change', function () {
    /* MUTATION: drop keep(pub) from the x -> the update comes back next page;
       drop ios() from the hint -> Android shows it too. */
    $x = uaRun(['up' => UA_UP, 'stored' => 1, 'close' => true]);
    expect($x['hiddenAfterClose'])->toBeTrue()->and($x['storedAfterClose'])->toBe('2')->and($x['waBack'])->toBeTrue();

    $tip = UA_UP + ['h' => 'To see the new icon: remove the app and add it again.'];
    $tip['k'] = 2;
    expect(uaRun(['up' => $tip, 'stored' => 1, 'ios' => true])['hint'])->toBe('To see the new icon: remove the app and add it again.')
        ->and(uaRun(['up' => $tip, 'stored' => 1, 'ios' => false])['hint'])->toBeNull();
    $tip['k'] = 1; // the icon changed in an update this phone already took
    expect(uaRun(['up' => $tip, 'stored' => 1, 'ios' => true])['hint'])->toBeNull();
});

it('gives the worker the SKIP_WAITING the button sends, and keeps the update code light', function () {
    /* MUTATION: delete the worker's message listener -> red; add a timer or a
       layout read to the update code -> red. */
    $sw = (string) file_get_contents(resource_path('site-app/sw.js'));
    $js = (string) file_get_contents(resource_path('site-app/site-app.js'));
    $part = substr($js, (int) strpos($js, 'UPDATE APP (Lane UA'), 2000).substr($js, (int) strpos($js, '/* ── the update row (Lane UA)'), 4200);

    expect($sw)->toContain("self.addEventListener('message', (event) => {\n  if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();")
        ->and($js)->toContain("w.postMessage({ type: 'SKIP_WAITING' })")
        ->and($js)->toContain("sw.addEventListener('controllerchange', reload)")
        ->and($js)->toContain('reg.update()');
    foreach (['setTimeout', 'setInterval', 'getBoundingClientRect', 'offsetHeight', 'getComputedStyle', 'fetch(', 'innerHTML', 'requestAnimationFrame'] as $banned) {
        expect(str_contains($part, $banned))->toBeFalse("the update code uses {$banned}");
    }
});
