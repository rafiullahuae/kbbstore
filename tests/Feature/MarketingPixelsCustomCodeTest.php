<?php

declare(strict_types=1);

/*
 * Marketing Pixels → Custom code (Lane MP). The owner: "give facility to use
 * header code, footer or body code … but make sure site speed must not be
 * disturb in any case." Each case says what it would catch (MUTATION).
 */

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\Pixels\CustomCode;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\PixelConnectRoutes;

const CCX_TAG = '<script>window.kbbCcProbe=1</script>';

beforeEach(function () {
    PixelConnectRoutes::wire(app());
    ccxFlush();
});

function ccxFlush(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    Cache::flush();
}

function ccxOwner(): AdminUser
{
    return AdminUser::create(['name' => 'CCX', 'email' => 'ccx-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']);
}

function ccxSave(array $slots, ?AdminUser $as = null): \Illuminate\Testing\TestResponse
{
    $r = test()->actingAs($as ?? ccxOwner(), 'admin')->postJson('/admin-api/marketing-pixels/custom-code', ['slots' => $slots]);
    ccxFlush();

    return $r;
}

function ccxRequest(string $path, string $routeName): Request
{
    $request = Request::create('https://kbeautybliss.test/'.ltrim($path, '/'));
    $route = (new Route('GET', $path, fn () => null))->name($routeName);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

it('prints nothing while a box is off or empty: the page is byte-identical', function () {
    $before = test()->get('/')->assertOk()->getContent();

    // Saved directly, not through the screen: a signed-in admin sees the
    // admin bar, and that is a different page for reasons of its own.
    expect(app(CustomCode::class)->save(['footer' => ['on' => false, 'code' => CCX_TAG], 'head' => ['on' => true, 'code' => '   ']], 'test')['ok'])->toBeTrue();
    ccxFlush();
    $after = test()->get('/')->assertOk()->getContent();

    // MUTATION: drop the `! $s['on']` test in CustomCode::render().
    expect($after)->toBe($before)->and($after)->not->toContain('kbbCcProbe');
});

it('defers by default: the code sits inert in a <template>, with one loader for the page', function () {
    ccxSave([
        'head' => ['on' => true, 'code' => '<script>window.kbbHeadProbe=1</script>'],
        'body' => ['on' => true, 'code' => '<noscript><img src="https://px.example/p.gif"></noscript>'],
        'footer' => ['on' => true, 'code' => CCX_TAG],
    ])->assertOk();

    $html = test()->get('/')->assertOk()->getContent();

    expect($html)->toContain('<template data-kbb-cc="footer">'.CCX_TAG.'</template>')
        ->and($html)->toContain('<template data-kbb-cc="head"><script>window.kbbHeadProbe=1</script></template>')
        // In the head, right where it says, and at body start right after <body ...>.
        ->and(strpos($html, 'data-kbb-cc="head"'))->toBeLessThan(strpos($html, '</head>'))
        ->and((bool) preg_match('/<body[^>]*>\n<template data-kbb-cc="body">/', $html))->toBeTrue()
        ->and(strrpos($html, 'data-kbb-cc="footer"'))->toBeGreaterThan(strrpos($html, '@stack') ?: 0)
        // MUTATION: drop the LOADER_FLAG check and the loader prints three times.
        ->and(substr_count($html, CustomCode::LOADER))->toBe(1)
        // The probe exists only inside its template, never as a live script.
        ->and(substr_count($html, CCX_TAG))->toBe(1);

    // The loader waits for `load`, then idles, and re-creates scripts so they run.
    expect(CustomCode::LOADER)->toContain('addEventListener("load"')->toContain('requestIdleCallback')->toContain('createElement("script")');

    // A document.write in deferred code runs after load, where it calls
    // document.open() and REPLACES the page — an old vendor snippet blanked
    // the whole shop in Chromium on the preview. The loader switches it off
    // while the scripts run and puts it back after.
    // MUTATION: drop `d.write=d.writeln=function(){}` from LOADER.
    expect(CustomCode::LOADER)->toContain('d.write=d.writeln=function(){};try{')->toContain('finally{delete d.write;delete d.writeln}');
});

it('keeps to the page it was told: all, thank-you only, everything but checkout', function () {
    // MUTATION: make allowedHere() return true for 'thankyou' everywhere.
    expect(CustomCode::allowedHere('thankyou', ccxRequest('checkout/success', 'checkout.success')))->toBeTrue()
        ->and(CustomCode::allowedHere('thankyou', ccxRequest('product/x', 'product.show')))->toBeFalse()
        ->and(CustomCode::allowedHere('not_checkout', ccxRequest('checkout', 'checkout')))->toBeFalse()
        ->and(CustomCode::allowedHere('not_checkout', ccxRequest('checkout/success', 'checkout.success')))->toBeTrue()
        ->and(CustomCode::allowedHere('all', ccxRequest('collections/toners', 'category.show')))->toBeTrue();

    ccxSave(['footer' => ['on' => true, 'where' => 'thankyou', 'code' => CCX_TAG]])->assertOk();
    expect(test()->get('/')->getContent())->not->toContain('kbbCcProbe');
});

it('never prints on the admin, /api or a feed, whatever the box says', function () {
    $owner = ccxOwner();
    ccxSave(['footer' => ['on' => true, 'code' => CCX_TAG], 'head' => ['on' => true, 'load' => 'immediate', 'code' => CCX_TAG]], $owner)->assertOk();

    expect(CustomCode::allowedHere('all', ccxRequest('api/settings', 'api.settings')))->toBeFalse()
        ->and(CustomCode::allowedHere('all', ccxRequest('feeds/meta-catalog.xml', 'feeds.meta-catalog')))->toBeFalse()
        ->and(CustomCode::allowedHere('all', ccxRequest('admin-api/x', 'x')))->toBeFalse()
        ->and(CustomCode::allowedHere('all', ccxRequest('kbb-admin', 'admin')))->toBeFalse();

    expect(test()->getJson('/api/settings')->getContent())->not->toContain('kbbCcProbe');
    expect(test()->actingAs($owner, 'admin')->get(route('admin'))->getContent())->not->toContain('kbbCcProbe');
});

it('makes "Immediately" code unable to block rendering', function () {
    $in = '<script src="https://cdn.example/a.js"></script><script defer src="https://cdn.example/b.js"></script>'
        .'<script>inline()</script><link rel="stylesheet" href="https://cdn.example/a.css">'
        .'<link rel="stylesheet" media="screen" href="https://cdn.example/b.css"><link rel="preconnect" href="https://cdn.example">';
    $out = CustomCode::nonBlocking($in);

    // MUTATION: return $code unchanged from nonBlocking() and the first is red.
    expect($out)->toContain('<script async src="https://cdn.example/a.js">')
        ->and($out)->toContain('<script defer src="https://cdn.example/b.js">')
        ->and($out)->toContain('<script>inline()</script>')
        ->and($out)->toContain('<link rel="stylesheet" href="https://cdn.example/a.css" media="print" onload="this.media=\'all\'">')
        ->and($out)->toContain('<link rel="stylesheet" media="screen" href="https://cdn.example/b.css">')
        ->and($out)->toContain('<link rel="preconnect" href="https://cdn.example">');

    ccxSave(['head' => ['on' => true, 'load' => 'immediate', 'code' => '<script src="https://cdn.example/a.js"></script>']])->assertOk();
    $html = test()->get('/')->getContent();
    expect($html)->toContain('<script async src="https://cdn.example/a.js"></script>')
        ->and($html)->not->toContain(CustomCode::LOADER);
});

it('refuses a box over 20 KB and warns about document.write', function () {
    // MUTATION: drop the MAX_BYTES check in validate().
    ccxSave(['footer' => ['on' => true, 'code' => str_repeat('a', CustomCode::MAX_BYTES + 1)]])
        ->assertStatus(422)->assertJsonPath('ok', false);
    ccxSave(['footer' => ['on' => true, 'code' => '<x></template><script>live()</script>']])->assertStatus(422);
    ccxSave(['footer' => ['on' => true, 'where' => 'admin', 'code' => 'x']])->assertStatus(422);

    $warn = ccxSave(['footer' => ['on' => true, 'code' => '<script>document.write("<p>")</script>']])->assertOk()->json('warnings');
    expect($warn['footer'] ?? '')->toContain('document.write');
});

it('keeps every version with who saved it, and restores one in a click', function () {
    $owner = ccxOwner();
    ccxSave(['footer' => ['on' => true, 'code' => '<script>v1()</script>']], $owner)->assertOk();
    ccxSave(['footer' => ['on' => true, 'code' => '<script>v2()</script>']], $owner)->assertOk();

    $history = test()->actingAs($owner, 'admin')->getJson('/admin-api/marketing-pixels/custom-code')->assertOk()->json('history');
    expect($history)->toHaveCount(2)->and($history[0]['saved_by'])->toBe($owner->email);

    // MUTATION: make restore() write the current snapshot instead of the row's.
    test()->actingAs($owner, 'admin')->postJson('/admin-api/marketing-pixels/custom-code/restore/'.$history[1]['id'])
        ->assertOk()->assertJsonPath('slots.footer.code', '<script>v1()</script>');
    ccxFlush();

    expect(app(CustomCode::class)->current()['footer']['code'])->toBe('<script>v1()</script>')
        ->and(DB::table('marketing_custom_code')->count())->toBe(3)
        ->and(DB::table('marketing_custom_code')->orderByDesc('id')->value('note'))->toBe('Restored version #'.$history[1]['id']);
});

it('hands the code to the screen as JSON text, never as markup in the console page', function () {
    $owner = ccxOwner();
    ccxSave(['footer' => ['on' => true, 'code' => CCX_TAG]], $owner)->assertOk();

    $json = test()->actingAs($owner, 'admin')->getJson('/admin-api/marketing-pixels/custom-code')->assertOk();
    expect($json->json('slots.footer.code'))->toBe(CCX_TAG)
        ->and($json->headers->get('Content-Type'))->toContain('application/json');

    // The screen fills a textarea with `t.value = …` — text, never innerHTML.
    $partial = (string) file_get_contents(resource_path('views/admin/partials/marketing-pixels-connect.blade.php'));
    expect($partial)->toContain("t.value = CC.slots[b.getAttribute('data-cc')].code");
});
