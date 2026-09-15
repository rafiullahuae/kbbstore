<?php

/**
 * Email verification for storefront customers — Lane L.
 *
 * The interesting half of this file is the base-path pair at the bottom.
 * KBB_BASE_PATH prefixes every route on the server and nothing in CI, and a
 * verification link is minted on one request and clicked on another, possibly
 * days later and certainly from a different client. If the signature depends on
 * the prefix, the link is valid when it is sent and rejected when it is opened
 * — for every customer at once, with nothing in the logs to explain it. That is
 * the failure these two tests exist to make impossible.
 */

use App\Http\Controllers\Store\EmailVerificationController;
use App\Models\Customer;
use App\Notifications\CustomerEmailVerification;
use App\Support\CustomerLinkSigner;
use App\Support\Url;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

function verifyRoutes(): void
{
    app(Illuminate\Contracts\Http\Kernel::class);

    if (Route::has('customer.verify')) {
        return;
    }

    Route::middleware('web')->group(base_path('routes/auth-customer.php'));

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
}

/**
 * Forget Support\Url's memoised base path.
 *
 * Url::base() caches into a private static on first call and never looks again
 * — deliberately, it is called once per link and there are a hundred on a page.
 * That makes it the same kind of trap CLAUDE.md records against Setting::map():
 * fine under PHP-FPM, wrong in a process that handles more than one
 * configuration. A test that changes the base path has to clear it.
 */
function forgetUrlBase(): void
{
    $property = new ReflectionProperty(Url::class, 'base');
    $property->setAccessible(true);
    $property->setValue(null, null);
}

/** Path + query of an absolute link, which is all a browser sends. */
function verifyTarget(string $url): string
{
    $parts = parse_url($url);

    return $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

function verifyMessages(): array
{
    $transport = Mail::mailer('array')->getSymfonyTransport();

    return $transport instanceof ArrayTransport ? $transport->messages()->all() : [];
}

beforeEach(function () {
    verifyRoutes();
    config(['mail.default' => 'array', 'kbb.base_path' => '']);
    forgetUrlBase();
    Mail::purge('array');
    app('cache')->flush();
});

afterEach(function () {
    config(['kbb.base_path' => '']);
    forgetUrlBase();
});

/* ------------------------------------------------------------------ the flow */

it('confirms an address from the link in the email', function () {
    $customer = Customer::create(['name' => 'Nadia', 'email' => 'nadia@example.com', 'password' => 'a-password-here']);
    expect($customer->hasVerifiedEmail())->toBeFalse();

    $this->actingAs($customer, 'customer')
        ->post('/my-account/verify/resend')
        ->assertRedirect();

    $body = (string) verifyMessages()[0]->getOriginalMessage()->getHtmlBody();
    expect(preg_match('#https?://[^\s"<]*/my-account/verify/[^\s"<]+#', $body, $m))->toBe(1);

    $this->get(verifyTarget(html_entity_decode($m[0])))
        ->assertOk()
        ->assertSee('your email address is confirmed', false);

    expect($customer->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('is idempotent — a second click is not a failure', function () {
    $customer = Customer::create(['name' => 'Twice', 'email' => 'twice@example.com', 'password' => 'a-password-here']);

    $target = verifyTarget(CustomerEmailVerification::linkFor($customer));

    $this->get($target)->assertOk()->assertSee('confirmed', false);
    $first = $customer->fresh()->email_verified_at;

    $this->get($target)->assertOk()->assertSee('confirmed', false);

    // And the original timestamp is kept — re-clicking must not rewrite when
    // the address was actually proved.
    expect($customer->fresh()->email_verified_at->toString())->toBe($first->toString());
});

/* ------------------------------------------------------------------ tampering */

it('rejects a tampered link', function () {
    $customer = Customer::create(['name' => 'Target', 'email' => 'target@example.com', 'password' => 'a-password-here']);
    $other = Customer::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'a-password-here']);

    $link = CustomerEmailVerification::linkFor($customer);
    $target = verifyTarget($link);

    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    $flipped = $query['signature'];
    $flipped[0] = $flipped[0] === 'a' ? 'b' : 'a';

    $tampered = [
        // A single flipped nibble in the MAC.
        str_replace($query['signature'], $flipped, $target),
        // No signature at all.
        preg_replace('#&signature=.*$#', '', $target),
        // An empty signature.
        preg_replace('#signature=[a-f0-9]+#', 'signature=', $target),
        // Something that is not hex.
        preg_replace('#signature=[a-f0-9]+#', 'signature=' . str_repeat('z', 64), $target),
        // The expiry pushed out, keeping the MAC — the obvious forgery.
        preg_replace('#expires=\d+#', 'expires=' . (time() + 99999999), $target),
        // Someone else's customer id, keeping this MAC.
        str_replace('/verify/' . $customer->id . '/', '/verify/' . $other->id . '/', $target),
        // The address digest swapped for another customer's.
        str_replace($customer->verificationHash(), $other->verificationHash(), $target),
    ];

    foreach ($tampered as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSee(EmailVerificationController::FAILED_MESSAGE, false);
    }

    expect($customer->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($other->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects a link that has expired', function () {
    $customer = Customer::create(['name' => 'Stale', 'email' => 'stale@example.com', 'password' => 'a-password-here']);

    // Properly signed, genuinely past. Not a forgery — the MAC is valid and the
    // link must still be refused, which is the case a signature check alone
    // would wave through.
    $link = CustomerEmailVerification::linkFor($customer, time() - 60);

    $this->get(verifyTarget($link))
        ->assertOk()
        ->assertSee(EmailVerificationController::FAILED_MESSAGE, false);

    expect($customer->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects a link after the address it was minted for changes', function () {
    $customer = Customer::create(['name' => 'Moved', 'email' => 'moved@example.com', 'password' => 'a-password-here']);

    $target = verifyTarget(CustomerEmailVerification::linkFor($customer));

    $customer->forceFill(['email' => 'moved-elsewhere@example.com'])->save();

    $this->get($target)
        ->assertOk()
        ->assertSee(EmailVerificationController::FAILED_MESSAGE, false);

    expect($customer->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('gives one answer for every kind of bad link', function () {
    // The id is a small integer. If "no such customer" read differently from
    // "bad signature", walking the ids would count the shop's customers.
    $customer = Customer::create(['name' => 'Real', 'email' => 'real@example.com', 'password' => 'a-password-here']);

    $hash = $customer->verificationHash();

    $unknown = $this->get('/my-account/verify/999999/' . $hash . '/?expires=' . (time() + 600) . '&signature=' . str_repeat('a', 64));
    $known = $this->get('/my-account/verify/' . $customer->id . '/' . $hash . '/?expires=' . (time() + 600) . '&signature=' . str_repeat('a', 64));

    $normalise = fn (string $html): string => preg_replace('#/my-account/verify/\d+/#', '/my-account/verify/ID/', $html);

    expect($normalise($unknown->getContent()))->toBe($normalise($known->getContent()));
});

/* ---------------------------------------------------------------- base path */

it('signs a link the same way under every base path', function () {
    $customer = Customer::create(['name' => 'Prefixed', 'email' => 'prefixed@example.com', 'password' => 'a-password-here']);

    $expires = time() + 600;

    // At a domain root, as CI serves it.
    $root = CustomerEmailVerification::linkFor($customer, $expires);

    // Under /kbb-upgrade, as the server serves it.
    config(['kbb.base_path' => '/kbb-upgrade']);
    forgetUrlBase();
    $prefixed = CustomerEmailVerification::linkFor($customer, $expires);

    // The PATH differs, because the site is served from somewhere else...
    expect(parse_url($root, PHP_URL_PATH))->toBe('/my-account/verify/' . $customer->id . '/' . $customer->verificationHash() . '/')
        ->and(parse_url($prefixed, PHP_URL_PATH))->toBe('/kbb-upgrade/my-account/verify/' . $customer->id . '/' . $customer->verificationHash() . '/');

    // ...and the SIGNATURE does not, because nothing about the URL is signed.
    // This is the whole point: Laravel's own signed URLs compute the MAC over
    // the rendered URL, so these two would differ and a link minted on the
    // server would be rejected anywhere the prefix was not byte-identical.
    parse_str((string) parse_url($root, PHP_URL_QUERY), $rootQuery);
    parse_str((string) parse_url($prefixed, PHP_URL_QUERY), $prefixedQuery);

    expect($prefixedQuery['signature'])->toBe($rootQuery['signature']);
});

it('accepts a link minted under the base path', function () {
    $customer = Customer::create(['name' => 'Crossing', 'email' => 'crossing@example.com', 'password' => 'a-password-here']);

    // Minted exactly as the production server mints it: prefix configured.
    config(['kbb.base_path' => '/kbb-upgrade']);
    forgetUrlBase();
    $link = CustomerEmailVerification::linkFor($customer);

    expect($link)->toContain('/kbb-upgrade/my-account/verify/');

    // Now served where the test app lives, at the root. Stripping the prefix is
    // what the front controller does on the real host — /kbb-upgrade is the web
    // root there, so the application sees the unprefixed path. If the MAC
    // covered the URL, this request would be the broken link.
    config(['kbb.base_path' => '']);
    forgetUrlBase();

    $target = str_replace('/kbb-upgrade/my-account', '/my-account', verifyTarget($link));

    $this->get($target)->assertOk()->assertSee('your email address is confirmed', false);

    expect($customer->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('signs claims that cannot be shuffled into each other', function () {
    // Length-prefixing in CustomerLinkSigner::canonical(). Without it
    // ['ab','c'] and ['a','bc'] flatten to the same bytes, and a customer id
    // could be traded against an address digest under one MAC.
    $at = time() + 600;

    expect(CustomerLinkSigner::sign('p', ['a' => 'ab', 'b' => 'c'], $at))
        ->not->toBe(CustomerLinkSigner::sign('p', ['a' => 'a', 'b' => 'bc'], $at));

    // And the purpose is in the MAC, so a reset claim cannot be replayed as a
    // verification claim.
    expect(CustomerLinkSigner::sign('verify', ['id' => '1'], $at))
        ->not->toBe(CustomerLinkSigner::sign('reset', ['id' => '1'], $at));
});

/* ------------------------------------------------------------ rate limiting */

it('rate-limits the resend form', function () {
    $customer = Customer::create(['name' => 'Impatient', 'email' => 'impatient@example.com', 'password' => 'a-password-here']);

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($customer, 'customer')
            ->post('/my-account/verify/resend')
            ->assertSessionHasNoErrors();
    }

    $this->actingAs($customer, 'customer')
        ->from('/my-account/verify')
        ->post('/my-account/verify/resend')
        ->assertSessionHasErrors('email');
});

it('will not send for a guest', function () {
    // The send endpoint is the one with an enumeration surface, so it takes no
    // address at all: it is behind auth:customer and mails the session's own
    // account. A guest gets the login redirect, and no message is produced.
    $this->post('/my-account/verify/resend')->assertRedirect();

    expect(verifyMessages())->toBeEmpty();
});

it('says nothing new to a customer who is already verified', function () {
    $customer = Customer::create([
        'name' => 'Done', 'email' => 'done@example.com', 'password' => 'a-password-here',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($customer, 'customer')
        ->post('/my-account/verify/resend')
        ->assertRedirect();

    expect(verifyMessages())->toBeEmpty();
});
