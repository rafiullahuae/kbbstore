<?php

declare(strict_types=1);

use App\Services\ExtendedDelivery;
use App\Services\SettingsService;
use App\Support\ShopperCountry;
use Illuminate\Http\Request;

/**
 * The time-zone tier has to work ON THE SERVER, not only in a test harness.
 *
 * `kbb_tz` is written by the browser, so it arrives with no encryption
 * envelope and EncryptCookies drops it. bootstrap/app.php exempts it — and
 * that exemption CANNOT BE SHIPPED. BuildPackage's NEVER_SHIP list carries
 * `bootstrap/` because UpdateGuard forbids it on the server: a bad
 * bootstrap/app.php stops the application booting, which would leave the
 * updater unable to roll itself back. The host has no shell, so "reach the
 * server by hand" is not a step that is going to happen.
 *
 * A fix that only works where the exemption is present is therefore a fix that
 * works everywhere except the one place it was needed — and it would test
 * green the whole time, because the suite boots the real bootstrap/app.php.
 *
 * So the read falls back to $_COOKIE, which no middleware touches, and this
 * file asserts the property the way production experiences it: the exemption
 * absent, the cookie raw.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    unset($_COOKIE['kbb_tz']);
});

afterEach(function () {
    unset($_COOKIE['kbb_tz']);
});

it('reads the browser time zone even where the cookie was never decrypted', function () {
    // Exactly what the server hands PHP: the raw cookie, and a request whose
    // decrypted bag is empty because EncryptCookies threw the value away.
    $_COOKIE['kbb_tz'] = 'Asia/Riyadh';

    $detected = app(ExtendedDelivery::class)->detect(Request::create('/', 'GET'));

    expect($detected)->toBe(
        'SA',
        'the browser time zone never reached the country resolver — on the live host the cookie is never decrypted, and bootstrap/app.php cannot be shipped to fix that'
    );
});

it('carries that answer all the way to the shopper country resolver', function () {
    $_COOKIE['kbb_tz'] = 'Asia/Kuwait';

    $resolved = ShopperCountry::for(Request::create('/', 'GET'));

    expect($resolved->code)->toBe('KW')
        ->and($resolved->source)->toBe(ShopperCountry::HEADER);
});

it('prefers a header, which is a statement by the network rather than by the page', function () {
    $_COOKIE['kbb_tz'] = 'Asia/Riyadh';

    $request = Request::create('/', 'GET');
    $request->headers->set('CF-IPCountry', 'BH');

    expect(app(ExtendedDelivery::class)->detect($request))->toBe('BH');
});

it('answers nothing for a zone it does not know, rather than guessing a country', function () {
    $_COOKIE['kbb_tz'] = 'Antarctica/Troll';

    expect(app(ExtendedDelivery::class)->detect(Request::create('/', 'GET')))->toBeNull();
});

it('is not a way to push arbitrary data through the resolver', function () {
    // A visitor controls this value completely, so the only defence that counts
    // is that nothing but a known zone name can come out of it.
    $_COOKIE['kbb_tz'] = str_repeat('A', 200_000);

    expect(app(ExtendedDelivery::class)->detect(Request::create('/', 'GET')))->toBeNull();

    $_COOKIE['kbb_tz'] = "Asia/Riyadh\x00; admin=1";

    expect(app(ExtendedDelivery::class)->detect(Request::create('/', 'GET')))->toBeNull();
});

it('leaves the answer alone when detection is switched off', function () {
    app(SettingsService::class)->set(ExtendedDelivery::SETTING_DETECT, '0');
    SettingsService::forgetMemo();

    $_COOKIE['kbb_tz'] = 'Asia/Riyadh';

    expect(app(ExtendedDelivery::class)->detect(Request::create('/', 'GET')))->toBeNull();
});

it('keeps bootstrap out of any package, since that is what forces this design', function () {
    $ref = new ReflectionClass(\App\Console\Commands\BuildPackage::class);
    $never = $ref->getConstant('NEVER_SHIP');

    expect(in_array('bootstrap/', $never, true))->toBeTrue(
        'bootstrap/ is shippable again — if UpdateGuard now accepts it, the $_COOKIE fallback is worth revisiting, but do not remove it on the strength of a green suite'
    );
});
