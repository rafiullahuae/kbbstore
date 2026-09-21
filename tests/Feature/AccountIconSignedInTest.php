<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Auth\SessionGuard;

/**
 * =============================================================================
 * THE ACCOUNT ICON HAS TO KNOW A SHOPPER IS SIGNED IN
 * =============================================================================
 *
 * Two indicators of the same fact sit on the same element: `account_dot`, the
 * Header screen's signed-in dot, and `mh-signedin`, the Mobile Header screen's
 * green. They were on DIFFERENT GUARDS, three characters apart on one line.
 *
 * `auth()->guard()` with no argument is the DEFAULT guard, `web` — the staff
 * table. A shopper signs in on `customer` (config/auth.php). So the dot was
 * asking "is a member of staff signed in?" and answering no, forever, for
 * every customer who had ever bought anything. Nothing errored, nothing was
 * logged, no page broke: the icon just always looked signed out, which is
 * indistinguishable from a shop that had turned the dot off.
 *
 * Found while adding the green beside it — the green was written on the right
 * guard, and having the two side by side is what made the old one visible.
 *
 * MUTATION: put `auth()->guard()->check()` back. Red.
 *
 * ── AND IT MUST NOT SIGN IN WITH actingAs(), WHICH IS THE WHOLE TRAP ────────
 *
 * `actingAs($customer, 'customer')` calls `Auth::shouldUse('customer')`, which
 * changes the application's DEFAULT guard for the rest of the request. Under
 * it, `auth()->guard()` IS the customer guard — so the broken code passes and
 * the mutation above comes back GREEN. The test would assert nothing and look
 * like it asserted everything.
 *
 * tests/Feature/AccountAreaTest.php carries the same warning at the top,
 * written after this exact mechanism hid a bug that served the login form to a
 * customer who was already signed in. So this writes the session key the
 * session guard actually reads, which is what a browser does and what leaves
 * the default guard alone. Confirmed by running the mutation both ways.
 */
it('lights the signed-in dot for a shopper, not only for staff', function () {
    Setting::query()->updateOrInsert(['key' => 'hd_account_dot'], ['value' => '1']);

    $customer = Customer::create([
        'name' => 'Ada Shopper',
        'email' => 'icon-test@example.com',
        'password' => 'password123',
    ]);

    $signedOut = $this->get('/');
    $signedOut->assertOk();

    // The session key the session guard reads, exactly as a browser sets it.
    $key = 'login_customer_'.sha1(SessionGuard::class);

    $signedIn = $this->withSession([$key => $customer->id])->get('/');
    $signedIn->assertOk();

    /*
     * Asserted on the CLASS rather than on a colour, because the colour is a
     * setting and could legitimately be changed to anything; the class is the
     * contract between the blade and the stylesheet.
     */
    expect(str_contains($signedOut->getContent(), 'class="ib in'))->toBeFalse(
        'a signed-out visitor is being shown the signed-in dot'
    );

    expect(str_contains($signedIn->getContent(), 'class="ib in'))->toBeTrue(
        'a signed-in SHOPPER is not shown the signed-in dot; the dot is reading the staff guard again, '
        .'which is true for nobody who has ever bought anything'
    );
});

it('puts both signed-in marks on the same guard', function () {
    /*
     * The green and the dot must agree. One on `customer` and one on the
     * default guard is the bug above, and it is invisible in a rendered page
     * unless you happen to be signed in as BOTH a customer and a member of
     * staff at once.
     *
     * Read off the source, because that is where the disagreement lives and a
     * render can only show you one combination at a time.
     */
    $src = (string) file_get_contents(base_path('resources/views/partials/header.blade.php'));

    preg_match('/<a class="ib\{\{(.*?)\}\}(.*?)"/s', $src, $m);

    expect($m)->not->toBe([], 'the account icon markup has changed shape; re-point this test');

    $dot = $m[1];
    $green = $m[2];

    expect(str_contains($dot, "auth('customer')"))->toBeTrue(
        "the signed-in dot is not on the customer guard: {$dot}"
    );

    expect(str_contains($green, "@auth('customer')"))->toBeTrue(
        "the signed-in green is not on the customer guard: {$green}"
    );
});
