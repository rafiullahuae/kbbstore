<?php

declare(strict_types=1);

/*
 * The owner app's Petal front end (Lane MAC): what the browser is given, and
 * the rules it must keep — CSP-clean markup, a self-hosted font, three storage
 * keys and no more, the phone-only full-screen toggle, and throttles that do
 * not starve one another.
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    OA::wire($this->app);
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
});

function oaJs(): string
{
    return collect(glob(resource_path('js/owner-app/*.js')))->map(fn ($f) => file_get_contents($f))->implode("\n");
}

it('ships a shell with no inline script or style, the font preloaded from this origin and nothing from Google', function () {
    // DEFECT: a CSP that has to allow 'unsafe-inline', or a font host that
    // learns every time the owner opens his app. MUTATION: add a Google Fonts
    // <link> to shell.blade.php.
    $r = $this->get(OA::base())->assertOk();
    $html = $r->getContent();

    expect($html)->not->toMatch('/<script(?![^>]*\bsrc=)[^>]*>/')
        ->and($html)->not->toContain(' style=')
        ->and($html)->not->toContain('googleapis')->not->toContain('gstatic')
        ->and($html)->toContain('rel="preload"')->toContain('plus-jakarta-sans')
        ->and($html)->toContain('viewport-fit=cover')
        ->and($r->headers->get('Content-Security-Policy'))->toContain("script-src 'self';")->toContain("frame-ancestors 'none'")->not->toContain('unsafe-inline');

    // The generated markup sets no style attribute either: hues, bars and
    // columns go through the CSSOM (core.js paint()).
    expect(oaJs())->not->toMatch('/style="/');
});

it('keeps three convenience keys in browser storage and nothing else, never the PIN or the CSRF value', function () {
    // MUTATION: store S.csrf with store.set('oa.csrf', …) and the key list
    // check fails; write localStorage directly anywhere but core.js and the
    // second check fails.
    $core = (string) file_get_contents(resource_path('js/owner-app/core.js'));
    preg_match("/const KEYS = \\[([^\\]]*)\\]/", $core, $m);

    expect(trim($m[1] ?? ''))->toBe("'oa.a2', 'oa.fs', 'oa.seen'");

    foreach (glob(resource_path('js/owner-app/*.js')) as $f) {
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#(^|\s)//[^\n]*#'], ['', '$1'], (string) file_get_contents($f));
        if (basename($f) !== 'core.js') {
            expect(str_contains($code, 'localStorage'))->toBeFalse(basename($f).' touches localStorage directly');
        }
        expect($code)->not->toMatch('/store\.set\(\s*[\'"]oa\.(pin|csrf|token)/');
    }
});

it('offers full screen to phones only, with labelled YouTube-style glyphs and the request as the first thing the tap does', function () {
    // DEFECT: a tablet forced full screen, or a request made after an await
    // (which the browser refuses as no longer a user gesture).
    $fs = (string) file_get_contents(resource_path('js/owner-app/fs.js'));

    expect($fs)->toContain("matchMedia('(pointer: coarse) and (max-width: 600px)')")
        ->toContain("aria-label=\"Full screen\"")
        ->toContain("'Exit full screen'")
        ->toContain("{ navigationUI: 'hide' }")
        ->toContain('class="i g-out"')->toContain('class="i g-in"')
        ->toContain('standalone()');

    // Inside the icon branch the request comes before anything else, with no await.
    preg_match('/if \(icon\) \{(.*?)\n    \}/s', $fs, $branch);
    expect($branch[1] ?? '')->toMatch('/^\s*if \(document\.fullscreenElement\) document\.exitFullscreen\(\)/')
        ->and($branch[1] ?? '')->toContain('root.requestFullscreen(opts)')
        ->and($branch[1] ?? '')->not->toContain('await');

    // One class on <html> picks the glyph; no innerHTML on the swap.
    preg_match('/function sync\(\) \{(.*?)\n\}/s', $fs, $sync);
    expect($sync[1] ?? '')->toContain("classList.toggle('is-fs'")->not->toContain('innerHTML');

    $css = (string) file_get_contents(resource_path('css/owner-app/owner-app.css'));
    expect($css)->toContain('.is-fs .fs .g-in { display: block; }')->toContain('.can-fs .fsbar { display: flex; }')->toContain('.can-fs .ib.fs { display: grid; }');

    $m = $this->get(OA::base().'/manifest.webmanifest')->assertOk()->json();
    expect($m['display'])->toBe('standalone')->and($m['display_override'])->toBe(['fullscreen', 'standalone']);
});

it('enters full screen ONLY from its icon, which sits beside Sync now in every header', function () {
    // The owner, 2.60.401: "upon clicking anywhere in the owner app, it keeps
    // enabling auto the full screen mode, which is very annoying ... beside the
    // refresh icons make a icon of enlarge ... click anywhere should not
    // perform any full screen". DEFECT: the first tap anywhere asked for full
    // screen. MUTATION: put back an `armed` request outside the icon branch ->
    // two requestFullscreen calls, red; drop fsIcon() from syncBtn -> red.
    $fs = (string) file_get_contents(resource_path('js/owner-app/fs.js'));
    $core = (string) file_get_contents(resource_path('js/owner-app/core.js'));
    $app = (string) file_get_contents(resource_path('js/owner-app/owner-app.js'));

    expect(substr_count($fs, 'root.requestFullscreen('))->toBe(1)
        ->and($fs)->not->toContain('armed')
        ->and($core)->toMatch("/export const syncBtn = .*\\+ fsIcon\\(\\);/")
        ->and($core)->toContain('class="ib fs" data-fs')
        ->and($app)->not->toContain("'<div class=\"main\">' + fsButton()");
});

it('gives sign-in its own throttle, so ordinary app traffic can never lock a second phone out of enrolling', function () {
    // DEFECT, found by the device run: every `throttle:N,1` in Laravel shares
    // ONE counter per IP unless it is given a prefix, so 20 ordinary API
    // calls made `throttle:20,1` on enrol answer 429 to the owner's tablet.
    // MUTATION: drop ",oa-enrol" from the enrol route.
    $owner = OA::admin();
    OA::member($owner);
    [$c] = OA::enrol($this);
    for ($i = 0; $i < 25; $i++) {
        OA::get($this, 'changes?after=1', $c);
    }

    $this->flushHeaders();
    $this->withHeaders(['X-OA' => '1'])->postJson(OA::base().'/api/enrol', ['email' => 'owner@example.com', 'pin' => '482613'])->assertOk();

    $routes = (string) file_get_contents(base_path('routes/owner-app.php'));
    preg_match_all("/throttle:\\d+,\\d+(,[a-z\\-]+)?/", $routes, $all);
    expect(collect($all[1])->filter(fn ($p) => $p === '')->all())->toBe([]);
});

it('precaches the shell, its font and its icons, and gives the PIN pad the PIN length only', function () {
    $sw = $this->get(OA::base().'/sw.js')->assertOk()->getContent();
    expect($sw)->toContain('plus-jakarta-sans')->not->toContain('/api/"');

    $owner = OA::admin();
    OA::member($owner, '482615');
    [$c] = OA::enrol($this, 'owner@example.com', '482615');
    DB::table('owner_app_devices')->update(['session_hash' => null]);

    $s = $this->withCredentials()->withUnencryptedCookies($c)->withHeaders(['X-OA' => '1'])->getJson(OA::base().'/api/state')->assertOk();
    expect($s->json('stage'))->toBe('pin')->and($s->json('pin_length'))->toBe(6)
        ->and($s->getContent())->not->toContain('482615');
});

it('answers the address from the settings row, not from a cache that outlived it', function () {
    // DEFECT, found by the preview: a cached address with no row behind it
    // made ensure() report an address and write none, so nothing was mounted.
    \Illuminate\Support\Facades\Cache::forever('kbb.owner_app_path', 'stale0cache_address00');
    DB::table('settings')->where('key', \App\Services\OwnerApp\OwnerAppPath::SETTING)->delete();
    \App\Services\OwnerApp\OwnerAppPath::forgetMemo();

    $path = \App\Services\OwnerApp\OwnerAppPath::ensure();

    expect($path)->not->toBe('stale0cache_address00')
        ->and(DB::table('settings')->where('key', \App\Services\OwnerApp\OwnerAppPath::SETTING)->value('value'))->toBe($path);
});

it('titles My store with the store name, K-Beauty Bliss by default, in a compact header', function () {
    // The owner, 2.60.401: "the header bar is too heighted, i need to reduce
    // atleast 50%, make the KBB icon small" and "reduce the store name ... it
    // should fit any store name, keep for now my store name K-Beauty Bliss".
    // Measured at 390: the title block 84px -> 44px. MUTATION: drop 'store'
    // from me() -> the key is missing, red; remove the ellipsis rule -> red.
    $src = (string) file_get_contents(app_path('Http/Controllers/OwnerApp/AppController.php'));
    $css = (string) file_get_contents(resource_path('css/owner-app/owner-app.css'));
    $js = (string) file_get_contents(resource_path('js/owner-app/store.js'));

    expect($src)->toContain("'store' => self::storeName(),")->toContain("'K-Beauty Bliss'")
        ->and($css)->toContain('.lt-dash h2 { margin: 0; font-size: 18px;')->toContain('text-overflow: ellipsis; white-space: nowrap; }')
        ->and($css)->toContain('.lt-dash .logo.sm { width: 32px; height: 32px;')
        ->and($js)->toContain('<div class="lt lt-dash">')->toContain("esc(S.store || 'My store')");
});
