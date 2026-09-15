<?php

/**
 * Password reset for storefront customers — Lane L.
 *
 * These are real HTTP tests, not router-dispatch tests. routes/auth-customer.php
 * ships unmounted (CLAUDE.md forbids this lane from editing routes/web.php), so
 * each test registers it into the `web` group first — which is exactly the line
 * the integrator will add, so what is exercised here is what will run.
 *
 * That is possible where MailRoutesTest's approach was not because none of these
 * paths is a single root segment: kbb-brands-blog.php's trailing `/{slug}/`
 * catch-all cannot swallow `/my-account/reset/1/abc`, and Route::fallback is
 * sorted last by the RouteCollection regardless of registration order.
 */

use App\Http\Controllers\Store\PasswordResetController;
use App\Models\Customer;
use App\Support\WordPressHasher;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/** Mount the lane's route file the way the integrator will. */
function resetRoutes(): void
{
    // Builds the kernel, which is what registers the 'throttle' and 'auth'
    // middleware aliases on the router. Without it the pipeline resolves
    // 'throttle' to nothing useful and a guard test passes for the wrong reason.
    app(Illuminate\Contracts\Http\Kernel::class);

    if (Route::has('customer.password.email')) {
        return;
    }

    Route::middleware('web')->group(base_path('routes/auth-customer.php'));

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
}

/** Every message the array transport has collected this test. */
function resetMessages(): array
{
    $transport = Mail::mailer('array')->getSymfonyTransport();

    return $transport instanceof ArrayTransport ? $transport->messages()->all() : [];
}

/** The reset link out of the one message that was sent. */
function resetLinkSent(): ?string
{
    foreach (resetMessages() as $sent) {
        $body = (string) $sent->getOriginalMessage()->getHtmlBody();

        if (preg_match('#https?://[^\s"<]*/my-account/reset/\d+/[A-Za-z0-9]+/#', $body, $m) === 1) {
            return $m[0];
        }
    }

    return null;
}

/** The path a browser would request, from an absolute link. */
function resetPath(string $url): string
{
    return (string) parse_url($url, PHP_URL_PATH);
}

beforeEach(function () {
    resetRoutes();

    config(['mail.default' => 'array']);
    Mail::purge('array');

    // The limiters are keyed on the test client's IP, which is the same in
    // every test in the process; without this, test six starts throttled.
    app('cache')->flush();
    RateLimiter::clear('kbb-forgot-ip:' . sha1('127.0.0.1'));
});

/* ------------------------------------------------------------------ the flow */

it('sends a link that sets a new password end to end', function () {
    $customer = Customer::create([
        'name' => 'Aisha', 'email' => 'aisha@example.com', 'password' => 'old-password-1',
    ]);

    $this->post('/my-account/forgot', ['email' => 'aisha@example.com'])
        ->assertRedirect()
        ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);

    $link = resetLinkSent();
    expect($link)->not->toBeNull();

    // The token is stored HASHED, never in the clear.
    $row = DB::table('customer_password_reset_tokens')->where('email', 'aisha@example.com')->first();
    expect($row)->not->toBeNull();
    preg_match('#/reset/(\d+)/([A-Za-z0-9]+)/#', $link, $m);
    [$id, $token] = [$m[1], $m[2]];
    expect($row->token)->not->toBe($token)
        ->and(Hash::check($token, $row->token))->toBeTrue();

    $this->get(resetPath($link))->assertOk()->assertSee('Set a new password');

    $this->post('/my-account/reset', [
        'id' => $id,
        'token' => $token,
        'password' => 'a-brand-new-one',
        'password_confirmation' => 'a-brand-new-one',
    ])->assertRedirect();

    $customer->refresh();
    expect(Hash::check('a-brand-new-one', $customer->password))->toBeTrue()
        ->and(Hash::check('old-password-1', $customer->password))->toBeFalse();

    // And the new password actually signs in, through the real login endpoint.
    $this->post('/my-account/login', [
        'email' => 'aisha@example.com', 'password' => 'a-brand-new-one',
    ])->assertRedirect();

    expect(auth()->guard('customer')->check())->toBeTrue();
});

it('refuses the same link a second time', function () {
    Customer::create(['name' => 'Two', 'email' => 'two@example.com', 'password' => 'first-password']);

    $this->post('/my-account/forgot', ['email' => 'two@example.com']);
    preg_match('#/reset/(\d+)/([A-Za-z0-9]+)/#', (string) resetLinkSent(), $m);

    $payload = [
        'id' => $m[1], 'token' => $m[2],
        'password' => 'second-password', 'password_confirmation' => 'second-password',
    ];

    $this->post('/my-account/reset', $payload)->assertRedirect();

    // The row is gone — PasswordBroker::reset() deletes it after the callback.
    expect(DB::table('customer_password_reset_tokens')->where('email', 'two@example.com')->exists())->toBeFalse();

    $this->from('/my-account/reset/' . $m[1] . '/' . $m[2] . '/')
        ->post('/my-account/reset', [
            'id' => $m[1], 'token' => $m[2],
            'password' => 'third-password', 'password_confirmation' => 'third-password',
        ])
        ->assertSessionHasErrors('token');

    // Still the password the FIRST use set, not the second attempt's.
    $customer = Customer::where('email', 'two@example.com')->first();
    expect(Hash::check('second-password', $customer->password))->toBeTrue()
        ->and(Hash::check('third-password', $customer->password))->toBeFalse();
});

it('refuses a token that has expired', function () {
    $customer = Customer::create([
        'name' => 'Old', 'email' => 'old@example.com', 'password' => 'unchanged-password',
    ]);

    // Written straight into the table so the clock, not a sleep, is what ages
    // it. `expire` is 60 minutes (config/auth.php); this is two hours old.
    $token = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    DB::table('customer_password_reset_tokens')->insert([
        'email' => 'old@example.com',
        'token' => Hash::make($token),
        'created_at' => now()->subHours(2),
    ]);

    // The form itself already says so, without needing the POST.
    $this->get('/my-account/reset/' . $customer->id . '/' . $token . '/')
        ->assertOk()
        ->assertSee(PasswordResetController::INVALID_MESSAGE, false);

    $this->from('/my-account/reset/' . $customer->id . '/' . $token . '/')
        ->post('/my-account/reset', [
            'id' => (string) $customer->id, 'token' => $token,
            'password' => 'attempted-new-one', 'password_confirmation' => 'attempted-new-one',
        ])
        ->assertSessionHasErrors('token');

    $customer->refresh();
    expect(Hash::check('unchanged-password', $customer->password))->toBeTrue();
});

/* --------------------------------------------------------------- enumeration */

it('answers an unknown address exactly as it answers a known one', function () {
    Customer::create(['name' => 'Known', 'email' => 'known@example.com', 'password' => 'a-password-here']);

    $known = $this->post('/my-account/forgot', ['email' => 'known@example.com']);
    $knownStatus = session('status');

    session()->forget('status');
    Mail::purge('array');

    $unknown = $this->post('/my-account/forgot', ['email' => 'nobody@example.com']);
    $unknownStatus = session('status');

    expect($unknown->getStatusCode())->toBe($known->getStatusCode())
        ->and($unknown->headers->get('Location'))->toBe($known->headers->get('Location'))
        ->and($unknownStatus)->toBe($knownStatus)
        ->and($unknownStatus)->toBe(PasswordResetController::SENT_MESSAGE);

    // And nothing was sent, so the difference is invisible from outside only.
    expect(resetMessages())->toBeEmpty();
});

it('answers a throttled address the same way too', function () {
    // The broker's own 60-second throttle fires only for accounts that exist,
    // so surfacing RESET_THROTTLED would be a perfect oracle on its own.
    Customer::create(['name' => 'Fast', 'email' => 'fast@example.com', 'password' => 'a-password-here']);

    $this->post('/my-account/forgot', ['email' => 'fast@example.com'])
        ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);

    session()->forget('status');

    $this->post('/my-account/forgot', ['email' => 'fast@example.com'])
        ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE)
        ->assertSessionHasNoErrors();
});

it('gives one message for every kind of bad link', function () {
    // Unknown id and wrong token must not be distinguishable: the id is a small
    // integer, so a page that differed would count the shop's customers.
    $customer = Customer::create(['name' => 'Real', 'email' => 'real@example.com', 'password' => 'a-password-here']);

    $unknownId = $this->get('/my-account/reset/999999/' . str_repeat('b', 32) . '/');
    $wrongToken = $this->get('/my-account/reset/' . $customer->id . '/' . str_repeat('b', 32) . '/');

    $unknownId->assertOk()->assertSee(PasswordResetController::INVALID_MESSAGE, false);
    $wrongToken->assertOk()->assertSee(PasswordResetController::INVALID_MESSAGE, false);

    // Byte-for-byte identical once the requested URL itself is normalised out.
    // The layout echoes the URL into <link rel=canonical> and og:url, which
    // differs because the request differed; nothing the CONTROLLER chose to
    // render may differ, and that is what this compares.
    $normalise = fn (string $html): string => preg_replace('#/my-account/reset/\d+/#', '/my-account/reset/ID/', $html);

    expect($normalise($unknownId->getContent()))->toBe($normalise($wrongToken->getContent()));
});

/* ------------------------------------------------------------ legacy accounts */

it('lets a WordPress-only customer reset and then sign in', function () {
    /*
     * The shape 3,712 imported rows are in: a WordPress hash in
     * `legacy_password` and NOTHING in `password`. The hash is generated rather
     * than pasted so the fixture cannot rot, and it is asserted against
     * WordPressHasher first — if this is not a hash the login path actually
     * accepts, the rest of the test proves nothing about imported customers.
     */
    $legacy = password_hash('legacy-secret', PASSWORD_BCRYPT);
    expect(app(WordPressHasher::class)->check('legacy-secret', $legacy))->toBeTrue();

    $customer = Customer::create([
        'name' => 'Imported', 'email' => 'imported@example.com',
        'password' => null, 'legacy_password' => $legacy,
    ]);

    expect($customer->fresh()->password)->toBeNull();

    // They are not locked out of the reset: no `password` is not "no account".
    $this->post('/my-account/forgot', ['email' => 'imported@example.com'])
        ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);

    $link = resetLinkSent();
    expect($link)->not->toBeNull();

    // The form warns them, before they commit, that the old one stops working.
    $this->get(resetPath($link))->assertOk()->assertSee('original K Beauty Bliss password will stop working');

    preg_match('#/reset/(\d+)/([A-Za-z0-9]+)/#', $link, $m);
    $this->post('/my-account/reset', [
        'id' => $m[1], 'token' => $m[2],
        'password' => 'chosen-after-reset', 'password_confirmation' => 'chosen-after-reset',
    ])->assertRedirect();

    $customer->refresh();

    // The new password works...
    $this->post('/my-account/login', [
        'email' => 'imported@example.com', 'password' => 'chosen-after-reset',
    ])->assertRedirect();
    expect(auth()->guard('customer')->check())->toBeTrue();

    // ...and the WordPress hash is GONE, so the old password no longer does.
    // Leaving it would mean a customer resetting because their old password
    // leaked had changed nothing at all.
    expect($customer->legacy_password)->toBeNull();

    auth()->guard('customer')->logout();

    $this->post('/my-account/login', [
        'email' => 'imported@example.com', 'password' => 'legacy-secret',
    ])->assertSessionHasErrors('email');
});

/* ------------------------------------------------------------------ sessions */

it('ends every other session for the customer', function () {
    $customer = Customer::create(['name' => 'Multi', 'email' => 'multi@example.com', 'password' => 'a-password-here']);
    $before = $customer->remember_token;

    // A session row as SESSION_DRIVER=database writes one in production. It
    // cannot be found by a WHERE: sessions.user_id is filled from the DEFAULT
    // guard, which here is `web`, so the payload is what identifies it.
    $key = 'login_customer_' . sha1(Illuminate\Auth\SessionGuard::class);
    DB::table('sessions')->insert([
        'id' => 'other-device-session',
        'user_id' => null,
        'ip_address' => '10.0.0.9',
        'user_agent' => 'phone',
        'payload' => base64_encode(serialize([$key => $customer->id])),
        'last_activity' => time(),
    ]);
    DB::table('sessions')->insert([
        'id' => 'someone-elses-session',
        'user_id' => null,
        'ip_address' => '10.0.0.10',
        'user_agent' => 'phone',
        'payload' => base64_encode(serialize([$key => $customer->id + 500])),
        'last_activity' => time(),
    ]);

    $this->post('/my-account/forgot', ['email' => 'multi@example.com']);
    preg_match('#/reset/(\d+)/([A-Za-z0-9]+)/#', (string) resetLinkSent(), $m);

    $this->post('/my-account/reset', [
        'id' => $m[1], 'token' => $m[2],
        'password' => 'recovered-account', 'password_confirmation' => 'recovered-account',
    ])->assertRedirect();

    expect(DB::table('sessions')->where('id', 'other-device-session')->exists())->toBeFalse()
        // Another customer's session is untouched — the scan matches on the id
        // in the payload, not merely on the key being present.
        ->and(DB::table('sessions')->where('id', 'someone-elses-session')->exists())->toBeTrue();

    $customer->refresh();
    expect($customer->remember_token)->not->toBe($before)
        ->and($customer->remember_token)->not->toBeNull();
});

it('marks the address verified, because the link proved the mailbox', function () {
    $customer = Customer::create(['name' => 'Unverified', 'email' => 'unver@example.com', 'password' => 'a-password-here']);
    expect($customer->email_verified_at)->toBeNull();

    $this->post('/my-account/forgot', ['email' => 'unver@example.com']);
    preg_match('#/reset/(\d+)/([A-Za-z0-9]+)/#', (string) resetLinkSent(), $m);

    $this->post('/my-account/reset', [
        'id' => $m[1], 'token' => $m[2],
        'password' => 'now-verified-too', 'password_confirmation' => 'now-verified-too',
    ]);

    expect($customer->fresh()->hasVerifiedEmail())->toBeTrue();
});

/* -------------------------------------------------------------- rate limiting */

it('rate-limits the forgot form', function () {
    Customer::create(['name' => 'Spammed', 'email' => 'spammed@example.com', 'password' => 'a-password-here']);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/my-account/forgot', ['email' => 'spammed@example.com'])
            ->assertSessionHasNoErrors();
    }

    $this->from('/my-account/forgot')
        ->post('/my-account/forgot', ['email' => 'spammed@example.com'])
        ->assertSessionHasErrors('email');
});

it('rate-limits the reset form', function () {
    $customer = Customer::create(['name' => 'Guessed', 'email' => 'guessed@example.com', 'password' => 'a-password-here']);

    $attempt = fn () => $this->from('/my-account/reset/' . $customer->id . '/' . str_repeat('c', 32) . '/')
        ->post('/my-account/reset', [
            'id' => (string) $customer->id, 'token' => str_repeat('c', 32),
            'password' => 'guess-attempt-here', 'password_confirmation' => 'guess-attempt-here',
        ]);

    for ($i = 0; $i < 10; $i++) {
        $attempt()->assertSessionHasErrors('token');
    }

    $attempt()->assertSessionHasErrors('password');   // the throttle message
});

/* ------------------------------------------------------ the CRLF advisory */

it('rejects an address carrying a carriage return', function () {
    // The advisory CLAUDE.md records is against the framework's `email` rule,
    // which accepts forms a CR can hide inside. This form does not use it.
    foreach ([
        "victim@example.com\r\nBcc: attacker@evil.test",
        "victim@example.com\nBcc: attacker@evil.test",
        "victim@example.com%0d%0aBcc:x@evil.test",
        "\"vic\rtim\"@example.com",
        "\"vic\r\ntim\"@example.com",
        'victim @example.com',
        '(comment)victim@example.com',
    ] as $address) {
        // Checked at the rule first so a failure names the address that got
        // through; assertSessionHasErrors' third argument is an error bag, not
        // a message, and cannot carry that.
        expect(App\Rules\StorefrontEmail::passes($address))->toBeFalse(json_encode($address));

        $this->from('/my-account/forgot')
            ->post('/my-account/forgot', ['email' => $address])
            ->assertSessionHasErrors('email');
    }

    expect(resetMessages())->toBeEmpty();
});

it('rejects every control character at the rule itself', function () {
    /*
     * At the rule rather than over HTTP, because the framework's global
     * TrimStrings middleware strips leading and trailing whitespace before a
     * validator ever sees the value — so a trailing "\r\n" arrives already
     * cleaned and an HTTP test of it would assert the middleware. The rule has
     * to hold on its own: it is also called directly, on stored addresses,
     * before a verification mail is sent.
     */
    foreach (["\r", "\n", "\t", "\0", "\x0b", "\x1f", "\x7f", ' '] as $char) {
        expect(App\Rules\StorefrontEmail::passes('victim' . $char . '@example.com'))->toBeFalse()
            ->and(App\Rules\StorefrontEmail::passes('victim@example.com' . $char))->toBeFalse();
    }

    expect(App\Rules\StorefrontEmail::passes('ordinary.customer+tag@example.co.uk'))->toBeTrue();
});

it('never puts a submitted string into a message header', function () {
    // The second layer, and the one that holds even if the first is bypassed:
    // the recipient is read back out of the database, so what reaches the
    // mailer is a stored address and never the posted one. Casing proves it —
    // the row is lowercase, the POST is not.
    Customer::create(['name' => 'Cased', 'email' => 'cased@example.com', 'password' => 'a-password-here']);

    $this->post('/my-account/forgot', ['email' => 'CASED@example.com']);

    $to = resetMessages()[0]->getOriginalMessage()->getTo();
    expect($to)->toHaveCount(1)
        ->and($to[0]->getAddress())->toBe('cased@example.com');
});

/* ------------------------------------------------------------------- logging */

it('writes no token, link or address to the log', function () {
    $path = storage_path('logs/lane-l-reset-test.log');
    @unlink($path);

    config([
        'logging.default' => 'single',
        'logging.channels.single.path' => $path,
        'logging.channels.single.level' => 'debug',
    ]);
    app()->forgetInstance('log');

    $customer = Customer::create(['name' => 'Quiet', 'email' => 'quiet@example.com', 'password' => 'a-password-here']);

    $this->post('/my-account/forgot', ['email' => 'quiet@example.com']);

    $link = (string) resetLinkSent();
    preg_match('#/reset/(\d+)/([A-Za-z0-9]+)/#', $link, $m);
    $token = $m[2];

    $this->get(resetPath($link));
    $this->post('/my-account/reset', [
        'id' => $m[1], 'token' => $token,
        'password' => 'kept-out-of-logs', 'password_confirmation' => 'kept-out-of-logs',
    ]);

    // Also exercise the failure branch, which is the one that is tempted to be
    // helpful: force a send that throws and check what it chose to record.
    app()->forgetInstance('mailer');
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
    Mail::clearResolvedInstances();
    $this->post('/my-account/forgot', ['email' => 'quiet@example.com']);

    $log = is_file($path) ? (string) file_get_contents($path) : '';

    expect($log)->not->toContain($token)
        ->and($log)->not->toContain($link)
        ->and($log)->not->toContain('quiet@example.com')
        ->and($log)->not->toContain('kept-out-of-logs');

    @unlink($path);
});
