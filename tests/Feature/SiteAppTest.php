<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\SiteApp;
use App\Support\Locale;
use App\Services\Translation\TranslationStore;
use Tests\Support\SiteAppRoutes;

/*
 * The shop as a Home Screen app (Lane PW, App -> Site App).
 *
 * The owner, 5 October: "just build the app for the site ... give me just one
 * icon to test it that add to my homescreen." So: a manifest, a service
 * worker, an offline page, the iOS head tags, one icon at every size, and an
 * admin switch. How the shop OFFERS the install is decided later, so nothing
 * here may draw a button, a bar or a sheet.
 *
 * routes/site-app.php and routes/site-app-admin.php are required from
 * routes/web.php by the INTEGRATOR (docs/pwa-wiring.json). SiteAppRoutes
 * mounts the real files the way that wiring does; SiteAppWiringTest carries
 * the `=== 1` pins. Nothing here asserts that anything is NOT wired.
 */

function siteAppAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'SA '.$role,
        'email' => 'sa-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

function siteAppSet(string $key, mixed $value): void
{
    Setting::query()->updateOrCreate(['key' => $key], ['value' => is_array($value) ? json_encode($value) : $value, 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function siteAppArabic(): void
{
    siteAppSet(Locale::SETTING_ENABLED, '1');
    siteAppSet(Locale::SETTING_RTL, '1');
    TranslationStore::flush();
}

/** The seven-tag block out of a rendered page. */
function siteAppHead(string $html): string
{
    preg_match('#<link rel="manifest"[^\n]*\n(?:[^\n]*\n){5}<script src="[^"]*site-app\.js[^\n]*\n#', $html, $m);

    return $m[0] ?? '';
}

beforeEach(function () {
    SiteAppRoutes::wire($this->app);
});

it('serves a manifest Chrome and Safari can install from: id, scope, standalone, the name and three icons', function () {
    /* MUTATION: drop the maskable entry from SiteApp::ICONS -> the purposes line is red.
       MUTATION: set 'display' => 'browser' -> red (Chrome will not offer to install). */
    $r = $this->get('/manifest.webmanifest');
    $r->assertOk();
    expect($r->headers->get('Content-Type'))->toBe('application/manifest+json')
        ->and($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($r->headers->getCookies())->toBe([]);

    $m = json_decode((string) $r->getContent(), true);
    expect($m['id'])->toBe('/')
        ->and($m['scope'])->toBe('/')
        ->and($m['start_url'])->toBe('/?utm_source=homescreen&utm_medium=app')
        ->and($m['display'])->toBe('standalone')
        ->and($m['name'])->toBe('K-Beauty Bliss')
        ->and($m['short_name'])->toBe('K-Beauty Bliss')
        ->and($m['lang'])->toBe('en')
        ->and($m['dir'])->toBe('ltr')
        ->and($m['theme_color'])->toBe('#FFFFFF')
        ->and($m['background_color'])->toBe('#FFF8F5')
        ->and(array_column($m['icons'], 'purpose'))->toBe(['any', 'any', 'maskable'])
        ->and(array_column($m['icons'], 'sizes'))->toBe(['192x192', '512x512', '512x512']);

    // Every icon the manifest names is served, is a PNG, and is the size it claims.
    foreach ($m['icons'] as $icon) {
        $res = $this->get($icon['src']);
        $res->assertOk();
        $path = SiteApp::iconPath(basename(parse_url($icon['src'], PHP_URL_PATH), '.png'));
        [$w, $h, $type] = getimagesize($path);
        expect([$w, $h, $type])->toBe([(int) $icon['sizes'], (int) $icon['sizes'], IMAGETYPE_PNG]);
    }
});

it('draws the four icons the plan needs, opaque where iOS and Android need it', function () {
    /* The apple-touch-icon and the maskable icon are full-bleed: iOS paints a
       transparent pixel black and Android crops to its own shape, so a clear
       corner on either is a black or empty corner on the phone. The "any"
       icons keep clear rounded corners. MUTATION: run tools/pwa-icons.cjs with
       omitBackground true for apple-180 -> the opaque check is red. */
    foreach (['apple-180' => 180, 'maskable-512' => 512] as $key => $px) {
        $im = imagecreatefrompng(SiteApp::iconPath($key));
        expect(imagesx($im))->toBe($px);
        foreach ([[0, 0], [$px - 1, 0], [0, $px - 1], [$px - 1, $px - 1]] as [$x, $y]) {
            expect(imagecolorsforindex($im, imagecolorat($im, $x, $y))['alpha'])->toBe(0, "$key corner $x,$y is not opaque");
        }
    }
    foreach (['icon-192' => 192, 'icon-512' => 512] as $key => $px) {
        $im = imagecreatefrompng(SiteApp::iconPath($key));
        expect(imagecolorsforindex($im, imagecolorat($im, 0, 0))['alpha'])->toBe(127, "$key should have clear rounded corners");
        // The centre column, a third of the way down, is the white of the K.
        $mid = imagecolorsforindex($im, imagecolorat($im, (int) ($px * 0.36), (int) ($px * 0.5)));
        expect($mid['red'] + $mid['green'] + $mid['blue'])->toBeGreaterThan(700, "$key has no white KB where the K should be");
    }
});

it('serves the Arabic manifest with lang, direction and an Arabic start page, as the SAME app', function () {
    /* A file name takes no /ar/ segment (Locale::localisable), so the Arabic
       page asks for ?lang=ar. MUTATION: hard-code 'dir' => 'ltr' in
       SiteApp::manifest() -> red. */
    siteAppArabic();
    $m = json_decode((string) $this->get('/manifest.webmanifest?lang=ar')->assertOk()->getContent(), true);
    expect($m['lang'])->toBe('ar')
        ->and($m['dir'])->toBe('rtl')
        ->and($m['start_url'])->toBe('/ar/?utm_source=homescreen&utm_medium=app')
        ->and($m['id'])->toBe('/')
        ->and($m['scope'])->toBe('/');

    // The language comes from the allowlist, never from the request.
    foreach (['fr', '<x>', 'ar/../en', ''] as $junk) {
        $j = json_decode((string) $this->get('/manifest.webmanifest?lang='.urlencode($junk))->assertOk()->getContent(), true);
        expect($j['lang'])->toBe('en', $junk)->and($j['start_url'])->toBe('/?utm_source=homescreen&utm_medium=app');
    }
});

it('puts the seven head tags on a storefront page once, escaped, with the script deferred', function () {
    /* MUTATION: drop `defer` from partials/site-app-head -> red (a blocking script in the head).
       MUTATION: print the name with {!! !!} -> the escaping line is red. */
    siteAppSet(SiteApp::SETTING, ['on' => true, 'name' => 'K&B Bliss']);
    $html = (string) $this->get('/')->assertOk()->getContent();
    $block = siteAppHead($html);

    expect($block)->not->toBe('')
        ->and(substr_count($html, '<link rel="manifest"'))->toBe(1)
        ->and(substr_count($html, 'rel="apple-touch-icon"'))->toBe(1)
        ->and(substr_count($html, 'site-app.js'))->toBe(1)
        ->and($block)->toContain('<meta name="apple-mobile-web-app-title" content="K&amp;B Bliss">')
        ->and($block)->toContain('<meta name="apple-mobile-web-app-status-bar-style" content="default">')
        ->and($block)->toMatch('#<script src="/site-app\.js\?v=[0-9a-f]{10}" data-sw="/sw\.js" data-scope="/" defer></script>#')
        // No theme-color on the page: it would tint the browser bar for every
        // ordinary visitor. The colour lives in the manifest only.
        ->and($html)->not->toContain('name="theme-color"')
        // And no install UI of any kind: that is decided later.
        ->and($html)->not->toContain('beforeinstallprompt');

    // The block sits in <head>, before </head>.
    expect(strpos($html, '<link rel="manifest"'))->toBeLessThan(strpos($html, '</head>'));
});

it('links the Arabic manifest from an Arabic page', function () {
    siteAppArabic();
    $html = (string) $this->get('/ar/')->assertOk()->getContent();
    expect($html)->toContain('<link rel="manifest" href="/manifest.webmanifest?lang=ar">');
});

it('turns everything off cleanly: no tags, no manifest, and a worker that removes itself', function () {
    /* MUTATION: make SiteApp::worker() ignore on() -> the unregister line is red,
       and a phone that installed the app would keep its worker forever. */
    siteAppSet(SiteApp::SETTING, ['on' => false, 'name' => 'K-Beauty Bliss']);

    $html = (string) $this->get('/')->assertOk()->getContent();
    expect($html)->not->toContain('rel="manifest"')
        ->and($html)->not->toContain('site-app.js')
        ->and($html)->not->toContain('apple-mobile-web-app');

    $this->get('/manifest.webmanifest')->assertNotFound();
    $this->get('/offline')->assertNotFound();
    $this->get('/site-app.js')->assertNotFound();

    $sw = $this->get('/sw.js')->assertOk();
    $body = (string) $sw->getContent();
    expect($sw->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($body)->toContain('self.registration.unregister()')
        ->and($body)->toContain("k.startsWith('kbb-')")
        ->and($body)->not->toContain("addEventListener('fetch'");
});

it('serves the worker as JavaScript, never cached by the browser, with no cookie and every placeholder filled', function () {
    $r = $this->get('/sw.js')->assertOk();
    $body = (string) $r->getContent();
    expect($r->headers->get('Content-Type'))->toBe('application/javascript; charset=utf-8')
        ->and($r->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($r->headers->getCookies())->toBe([])
        ->and($body)->not->toContain('__SA_')
        ->and($body)->toMatch('/const VERSION = "[0-9a-f]{12}";/')
        ->and($body)->toContain('const BASE = "";');
});

it('never writes the secret admin or owner-app address into the public worker', function () {
    /* /sw.js is public. The admin path and the owner app's path are secrets
       (CLAUDE.md; ExportProbe; OwnerAppPath). MUTATION: add the admin path to
       the worker's bypass list -> red. */
    siteAppSet('admin_path', 'door-7f3k9q2');
    siteAppSet(\App\Services\OwnerApp\OwnerAppPath::SETTING, 'oa-x9k2m4p7q1zz');
    \App\Services\OwnerApp\OwnerAppPath::forgetMemo();

    $body = (string) $this->get('/sw.js')->assertOk()->getContent();
    expect($body)->not->toContain('door-7f3k9q2')
        ->and($body)->not->toContain('oa-x9k2m4p7q1zz')
        ->and($body)->not->toContain((string) config('kbb.admin_path', 'admin').'/');
});

it('serves the registration script and icons immutable only under their current hash, and nothing else', function () {
    $js = SiteApp::fileHash(SiteApp::scriptPath());
    expect($this->get('/site-app.js?v='.$js)->assertOk()->headers->get('Cache-Control'))->toContain('immutable');
    expect($this->get('/site-app.js?v=0000000000')->assertOk()->headers->get('Cache-Control'))->not->toContain('immutable');

    $icon = SiteApp::fileHash(SiteApp::iconPath('icon-192'));
    $res = $this->get('/site-app/icons/icon-192.png?v='.$icon)->assertOk();
    expect($res->headers->get('Content-Type'))->toBe('image/png')
        ->and($res->headers->get('Cache-Control'))->toContain('immutable')
        ->and($res->headers->getCookies())->toBe([]);

    // An allowlist, not a file read: anything not one of the four is a 404.
    foreach (['/site-app/icons/badge-96.png', '/site-app/icons/sw.png', '/site-app/icons/..%2F..%2F.env.png'] as $u) {
        expect($this->get($u)->status())->toBe(404, $u);
    }
});

it('serves an offline page with no session, no token, no form and nothing personal', function () {
    /* The worker precaches this for every shopper; MUTATION: render it through
       layouts.store -> the csrf and cookie lines are red. */
    $r = $this->get('/offline')->assertOk();
    $html = (string) $r->getContent();
    expect($r->headers->getCookies())->toBe([])
        ->and($r->headers->get('X-Robots-Tag'))->toBe('noindex')
        ->and($html)->toContain('You are offline')
        ->and($html)->toContain('<a href="/">Try again</a>')
        ->and($html)->not->toContain('csrf')
        ->and($html)->not->toContain('<form')
        ->and($html)->not->toContain('_token');

    siteAppArabic();
    $ar = (string) $this->get('/ar/offline')->assertOk()->getContent();
    expect($ar)->toContain('<html lang="ar" dir="rtl">')->and($ar)->toContain('href="/ar/"');
});

it('adds no query to a storefront page, at three products or forty', function () {
    /* The head block reads one autoloaded setting. MUTATION: make SiteApp::all()
       query Setting directly -> the counts differ. */
    $count = function (bool $on): int {
        siteAppSet(SiteApp::SETTING, ['on' => $on, 'name' => 'K-Beauty Bliss']);
        $this->get('/'); // warm
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->get('/')->assertOk();
        $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        return $n;
    };

    expect($count(true))->toBe($count(false));
});

it('lets an owner or manager read and save it, and refuses everyone else', function () {
    /* Fail closed: a role the capability does not name gets 403.
       MUTATION: delete the ['*', 'admin-api/site-app', ...] line from
       AdminCapabilities::RULES -> the manager line is red (owner-only). */
    foreach (['owner', 'manager'] as $role) {
        $this->actingAs(siteAppAdmin($role), 'admin')->getJson('/admin-api/site-app')->assertOk()
            ->assertJsonPath('values.on', true)->assertJsonPath('values.name', 'K-Beauty Bliss')
            ->assertJsonCount(4, 'icons');
    }
    foreach (['editor', 'support'] as $role) {
        $this->actingAs(siteAppAdmin($role), 'admin')->getJson('/admin-api/site-app')->assertForbidden();
        $this->actingAs(siteAppAdmin($role), 'admin')->postJson('/admin-api/site-app', ['on' => false])->assertForbidden();
    }
    expect(app(SiteApp::class)->on())->toBeTrue();
});

it('refuses a guest outright', function () {
    $r = $this->getJson('/admin-api/site-app');
    expect($r->status())->toBeIn([401, 302, 403]);
    $p = $this->postJson('/admin-api/site-app', ['on' => false]);
    expect($p->status())->toBeIn([401, 302, 403, 419]);
    expect(app(SiteApp::class)->on())->toBeTrue();
});

it('saves on/off and the name, and refuses anything else without changing a thing', function () {
    /* MUTATION: drop the strip_tags comparison in SiteApp::cleanName() -> the <b> case is red. */
    $owner = siteAppAdmin();
    $this->actingAs($owner, 'admin')->postJson('/admin-api/site-app', ['on' => false, 'name' => '  KB   Bliss '])
        ->assertOk()->assertJsonPath('values.on', false)->assertJsonPath('values.name', 'KB Bliss');
    expect(app(SiteApp::class)->all())->toBe(['on' => false, 'name' => 'KB Bliss']);

    $bad = [
        ['name' => ''], ['name' => str_repeat('x', 31)], ['name' => '<b>KB</b>'], ['name' => 'K"B'],
        ['name' => ['x']], ['on' => 'yes'], ['on' => 1], ['colour' => '#fff'], [],
    ];
    foreach ($bad as $body) {
        $this->actingAs($owner, 'admin')->postJson('/admin-api/site-app', $body)->assertStatus(422);
    }
    expect(app(SiteApp::class)->all())->toBe(['on' => false, 'name' => 'KB Bliss']);
});

it('ships on, as the owner asked, with no setting row at all', function () {
    expect(Setting::query()->where('key', SiteApp::SETTING)->exists())->toBeFalse()
        ->and(app(SiteApp::class)->all())->toBe(['on' => true, 'name' => 'K-Beauty Bliss']);
});
