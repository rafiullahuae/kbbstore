<?php

declare(strict_types=1);

/**
 * The phone menu's account box, small.                         (2.60.504)
 *
 * The owner: "option E is good, but i don't want to display such big like other
 * menu items, it should be small, no rull rows" and then "Slim strip for
 * non-login users and Two small buttons, then links for logged in users"
 * (docs/mobile-menu-account-options/minimal.html, E5 and E6).
 *
 * THE DEFECT IT ALSO FIXES. The rows asked plain @auth, which is the `web`
 * (back-office) guard; shoppers sign in on `customer`, so on the shop a
 * signed-in shopper was shown "Sign in" and "Create an account". Measured on
 * the preview shop: signed in, the menu still drew the visitor strip until the
 * guard was named. And "Create an account" linked ?action=register, which the
 * account page ignores -- it opened Sign in; the page reads ?tab=register.
 *
 * Signed in the way a browser is (the session key the guard reads), NOT with
 * actingAs(), which makes `customer` the default guard and would hide the
 * defect (see AccountIconSignedInTest).
 *
 * MUTATIONS, RUN: `@auth('customer')` back to `@auth` -- the signed-in case red;
 * `?tab=register` back to `?action=register` -- the visitor case red.
 */

use App\Models\Customer;
use App\Services\SettingsService;
use Illuminate\Auth\SessionGuard;

it('draws the slim strip for a visitor, with a register link that opens the register tab', function () {
    $html = (string) $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, '<div class="mm-acs">'))->toBe(1)
        ->and($html)->toContain('href="/my-account/?tab=register" aria-label="Create an account"')
        ->and($html)->not->toContain('mm-acx')
        ->and($html)->not->toContain('<div class="mm-grp">Account</div>');
});

it('draws the small links for a signed-in shopper, greeted by first name, and never offers Sign in', function () {
    $customer = Customer::create(['name' => 'Sara Ahmed', 'email' => 'mmab@example.com', 'password' => 'password123']);

    $html = (string) $this->withSession(['login_customer_'.sha1(SessionGuard::class) => $customer->id])
        ->get('/')->assertOk()->getContent();

    expect(substr_count($html, '<fieldset class="mm-acx">'))->toBe(1)
        ->and($html)->toContain('<legend>Welcome, Sara</legend>')
        ->and($html)->toContain('<form method="post" action="/my-account/logout"><input type="hidden" name="_token"')
        ->and($html)->not->toContain('mm-acs-who')
        ->and($html)->not->toContain('mmab@example.com');
});

it('puts the old rows back with Account box set to List, register tab fixed there too', function () {
    $s = app(SettingsService::class);
    $s->set('mobile_menu', array_merge((array) $s->get('mobile_menu'), ['account_style' => 'list']));

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('<div class="mm-grp">Account</div>')
        ->and($html)->toContain('<a class="mm-it" href="/my-account/?tab=register">Create an account</a>')
        ->and($html)->not->toContain('mm-acs');
});
