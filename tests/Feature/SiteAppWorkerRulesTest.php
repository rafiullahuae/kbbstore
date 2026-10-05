<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;
use Tests\Support\SiteAppRoutes;

/*
 * The shop's service worker, RUN, not read (Lane PW).
 *
 * The bytes /sw.js serves go through tools/pwa-sw-unit.mjs, which loads them
 * into a Node sandbox with a stub browser (caches, fetch, Response) and
 * dispatches real fetch events at the real handler. It proves, rule by rule:
 * POST/PUT/DELETE and every other origin (Tabby, Tamara, Stripe, Google,
 * Meta, WhatsApp) are never touched; the cart, checkout and every payment
 * return, My account, orders, wishlist and the APIs are stepped around, in
 * English and Arabic; every other page comes from the network and is NEVER
 * stored; offline navigation gets the precached offline page in its own
 * language; hashed assets and images are cached with their caps; a 404 is not
 * stored; activate removes only the worker's own older shells.
 *
 * MUTATIONS, each one run:
 *   - delete `if (req.method !== 'GET') return;` in resources/site-app/sw.js
 *       -> "POST /cart/add was handled by the worker" (and three more).
 *   - delete `if (bypassed(p)) return;` under `req.mode === 'navigate'`
 *       -> "navigation to /checkout/success?order=1 was handled" and the rest.
 *   - add `caches.open(SHELL).then((c) => c.put(req, pre.clone()))` to the
 *     navigation branch -> "a page was STORED".
 *   - change `p.startsWith(b + '/')` to `p.startsWith(b)` -> "/cartier-serum
 *     is a product page".
 */
it('obeys every rule it states, when actually run against requests', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        $this->markTestSkipped('node is not installed here; tools/pwa-sw-unit.mjs needs it.');
    }

    SiteAppRoutes::wire($this->app);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();

    $body = (string) $this->get('/sw.js')->assertOk()->getContent();
    expect($body)->toContain('"ar":"/ar/offline"');

    $file = tempnam(sys_get_temp_dir(), 'kbb-sw-').'.js';
    file_put_contents($file, $body);

    try {
        exec(escapeshellarg($node).' '.escapeshellarg(base_path('tools/pwa-sw-unit.mjs')).' '.escapeshellarg($file).' 2>&1', $out, $code);
    } finally {
        @unlink($file);
    }

    expect($code)->toBe(0, implode("\n", $out))
        ->and(implode("\n", $out))->toMatch('/^ok \d{3,}$/m');
});

it('is valid JavaScript as served, on and off', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        $this->markTestSkipped('node is not installed here.');
    }

    SiteAppRoutes::wire($this->app);

    foreach ([true, false] as $on) {
        Setting::query()->updateOrCreate(['key' => 'site_app'], ['value' => json_encode(['on' => $on, 'name' => 'K-Beauty Bliss']), 'autoload' => true]);
        Setting::flushMap();
        SettingsService::forgetMemo();
        app(SettingsService::class)->flush();

        $file = tempnam(sys_get_temp_dir(), 'kbb-sw-').'.js';
        file_put_contents($file, (string) $this->get('/sw.js')->getContent());
        exec(escapeshellarg($node).' --check '.escapeshellarg($file).' 2>&1', $out, $code);
        @unlink($file);
        expect($code)->toBe(0, ($on ? 'on' : 'off').': '.implode("\n", $out));
    }

    exec(escapeshellarg($node).' --check '.escapeshellarg(base_path('resources/site-app/site-app.js')).' 2>&1', $out2, $code2);
    expect($code2)->toBe(0, implode("\n", $out2));
});
