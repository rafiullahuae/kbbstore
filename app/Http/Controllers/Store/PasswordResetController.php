<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Rules\StorefrontEmail;
use App\Support\Url;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

/**
 * "I forgot my password" for storefront customers.
 *
 * Nothing served these addresses before: resources/views/store/account/forgot.blade.php
 * has posted to /my-account/forgot since the account area was built, and the
 * POST route did not exist, so the form 404'd. This is the other end of it.
 *
 * ---------------------------------------------------------------------------
 * NO ENUMERATION ORACLE
 * ---------------------------------------------------------------------------
 * The endpoint answers the same way whether the address has an account, has no
 * account, or has an account that already asked for a link a moment ago. That
 * last case is the one that is usually missed: Laravel's broker distinguishes
 * RESET_THROTTLED from INVALID_USER, and surfacing that difference is a perfect
 * oracle, because only a real account can ever be throttled. All three
 * outcomes are folded into one message here.
 *
 * Timing is handled in two places. Laravel's PasswordBroker already wraps the
 * lookup and the token write in a Timebox (200ms floor), so the cheap "no such
 * customer" path cannot return measurably sooner than the expensive one. What
 * the Timebox does NOT cover is the send: an SMTP handshake to a shared host
 * takes far longer than 200ms and varies wildly, so leaving it inside the
 * request would put the oracle straight back. The send is therefore deferred to
 * `app()->terminating()` — it happens after the response has been handed to the
 * client, so the response time is identical in every case by construction.
 *
 * This matches what checkout already does: CheckoutController stays silent when
 * it declines to set an initial password, for the same reason.
 *
 * ---------------------------------------------------------------------------
 * THE ADVISORY
 * ---------------------------------------------------------------------------
 * The address is validated by App\Rules\StorefrontEmail, not by `email`. See
 * that class: the CRLF-injection advisory CLAUDE.md records is against the
 * default rule, and this is the one public form on the site that takes an
 * address from a stranger and causes a message to be sent.
 *
 * Second layer: the value that reaches the mailer is `$customer->email`, read
 * back out of the database. The submitted string is used to look a row up and
 * is then discarded. A header cannot be injected with a string that never
 * reaches a header.
 */
class PasswordResetController extends Controller
{
    /**
     * The one answer the request endpoint ever gives.
     *
     * A constant, not a literal at three call sites, so the three cannot drift
     * into three subtly different sentences — which is all an oracle needs.
     */
    public const SENT_MESSAGE = 'If that address has an account, we have sent it a link to set a new password. Please check your inbox, and your spam folder.';

    /** The one answer every failed reset gives. */
    public const INVALID_MESSAGE = 'That link is no longer valid. Password reset links can only be used once, and expire after an hour. Please request a new one.';

    /** Per address+IP: five requests, then a fifteen-minute wait. */
    private const ADDRESS_ATTEMPTS = 5;

    private const ADDRESS_DECAY = 900;

    /** Per IP regardless of address, so one client cannot walk a list. */
    private const IP_ATTEMPTS = 15;

    private const IP_DECAY = 900;

    /** Per link, on the form that actually changes the password. */
    private const RESET_ATTEMPTS = 10;

    private const RESET_DECAY = 900;

    /** The form. Also reachable at /my-account/forgot (GET) via web.php. */
    public function request(): View
    {
        return view('store.account.forgot');
    }

    /**
     * Mint a token and post a link.
     *
     * Returns the same redirect, with the same flash message, in every branch.
     */
    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // `max` before the rule so an absurd payload is rejected without
            // running a regex over it.
            'email' => ['required', 'string', 'max:254', new StorefrontEmail],
        ]);

        $email = mb_strtolower(trim($data['email']));

        // Rate limiting is deliberately BEFORE the lookup. Both keys are built
        // from values the caller supplied, so a throttle message reveals only
        // what the caller already knew about their own behaviour — never
        // whether the address exists.
        $this->throttle($request, $email);

        $status = Password::broker('customers')->sendResetLink(
            ['email' => $email],
            function (Customer $customer, string $token): string {
                /*
                 * Deferred, not sent. See the class comment: an SMTP handshake
                 * inside the request is the timing oracle the Timebox cannot
                 * close. The token lives in this closure and in the (hashed)
                 * database row, and in nothing else.
                 *
                 * defer(), not app()->terminating(). Both run after the response,
                 * but terminating callbacks are never cleared from the
                 * Application — harmless under PHP-FPM, one process one request,
                 * and a duplicate send the moment anything long-lived (a queue
                 * worker, Octane, the test suite) handles two requests in one
                 * process. Deferred callbacks are collected per request and
                 * unset as they are invoked. The name makes it idempotent
                 * per customer as well.
                 */
                defer(function () use ($customer, $token): void {
                    try {
                        $customer->sendPasswordResetNotification($token);
                    } catch (\Throwable $e) {
                        /*
                         * The customer id and the exception CLASS. Not the
                         * message (a mail transport happily puts the recipient
                         * and sometimes the body in it), not the address, and
                         * above all not the token or the link.
                         *
                         * Caught here rather than left to defer()'s own
                         * rescue(): that reports the throwable, and a reported
                         * transport exception carries the recipient and
                         * sometimes the message into the log.
                         */
                        Log::warning('Customer password reset link could not be sent.', [
                            'customer_id' => $customer->getKey(),
                            'exception' => $e::class,
                        ]);
                    }
                }, 'kbb-password-reset-' . $customer->getKey());

                return Password::RESET_LINK_SENT;
            }
        );

        /*
         * Every status collapses to one message. RESET_LINK_SENT, INVALID_USER
         * and RESET_THROTTLED are all "we have sent it a link if there is an
         * account". $status is read only to record that something went wrong at
         * a level worth an operator's attention, with no address attached.
         */
        if (! in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            Log::warning('Customer password reset returned an unexpected broker status.', ['status' => $status]);
        }

        return redirect(Url::redirect('/my-account/forgot'))->with('status', self::SENT_MESSAGE);
    }

    /**
     * The "choose a new password" form.
     *
     * The link carries the customer id and the token. It does NOT carry the
     * address — see CustomerPasswordReset::url() for why an integer is
     * preferred to `?email=`.
     *
     * A bad id and a bad token produce the identical page. Since the id is a
     * small integer anyone can guess, a form that rendered for real ids and
     * errored for absent ones would be a customer-count oracle.
     */
    public function edit(Request $request, string $id, string $token): View
    {
        $customer = $this->customerFor($id);

        $valid = $customer !== null
            && Password::broker('customers')->tokenExists($customer, $token);

        return view('store.account.reset', [
            'valid' => $valid,
            'id' => $id,
            'token' => $token,
            // Shown only on a valid form, and only so the outcome is not a
            // surprise: the WordPress password stops working at this point.
            'retiresLegacy' => $valid && $customer?->legacy_password !== null,
            'message' => $valid ? null : self::INVALID_MESSAGE,
        ]);
    }

    /**
     * Apply the new password.
     *
     * Single use is the broker's: PasswordBroker::reset() deletes the row after
     * the callback runs, so the second POST with the same link finds no token.
     * Expiry is the broker's too (`auth.passwords.customers.expire`), and the
     * comparison is DatabaseTokenRepository's `Hash::check()` — bcrypt, constant
     * time. None of that is reimplemented here; reimplementing it is how it
     * ends up subtly wrong.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:20'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $key = 'kbb-reset:' . $data['id'] . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, self::RESET_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'password' => 'Too many attempts. Try again in ' . RateLimiter::availableIn($key) . ' seconds.',
            ]);
        }

        RateLimiter::hit($key, self::RESET_DECAY);

        $customer = $this->customerFor($data['id']);

        if ($customer === null) {
            // Same sentence a wrong or spent token gets.
            return back()->withErrors(['token' => self::INVALID_MESSAGE]);
        }

        $status = Password::broker('customers')->reset(
            [
                // From the database, never from the request. The link does not
                // carry an address and this endpoint does not accept one, so
                // there is no submitted string to smuggle into a header.
                'email' => $customer->email,
                'password' => $data['password'],
                'token' => $data['token'],
            ],
            function (Customer $customer, string $password) use ($request): void {
                /*
                 * Three writes, and all three are required.
                 *
                 * 1. The new password, WITH the WordPress hash cleared in the
                 *    same save. applyNewPassword() explains why: leaving
                 *    legacy_password in place means the old password still
                 *    signs in, and a reset that does not retire the previous
                 *    credential has not reset anything.
                 *
                 * 2. The address is marked verified. Clicking a link delivered
                 *    to it is proof of control of the mailbox — the same proof
                 *    the verification mail asks for — so making the customer
                 *    prove it twice would be theatre.
                 *
                 * 3. Every other session for this customer is ended, and the
                 *    remember-me token is rotated. A reset that leaves the
                 *    thief signed in has not recovered the account.
                 */
                $customer->applyNewPassword($password);
                $customer->markEmailAsVerified();
                $customer->invalidateSessions($request->session()->getId());
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['token' => self::INVALID_MESSAGE]);
        }

        RateLimiter::clear($key);

        /*
         * The browser that did the reset is logged out too, and its session is
         * thrown away rather than reused. invalidateSessions() skipped this one
         * so that the flash message below survives to be displayed; the
         * credential that could have been riding on it is gone either way.
         */
        auth()->guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect(Url::redirect('/my-account/'))
            ->with('status', 'Your password has been changed. Please sign in with it.');
    }

    /* ---------------------------------------------------------------- helpers */

    /**
     * Two limiters, both keyed on caller-supplied values.
     *
     * The per-address one stops someone hammering one victim's inbox into
     * treating this shop as a spammer; the per-IP one stops the same client
     * walking a list of addresses. Neither is allowed to be keyed on anything
     * that depends on the account existing.
     */
    private function throttle(Request $request, string $email): void
    {
        $ip = (string) $request->ip();

        $keys = [
            'kbb-forgot-addr:' . sha1($email . '|' . $ip) => [self::ADDRESS_ATTEMPTS, self::ADDRESS_DECAY],
            'kbb-forgot-ip:' . sha1($ip) => [self::IP_ATTEMPTS, self::IP_DECAY],
        ];

        foreach ($keys as $key => [$max, $decay]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'email' => 'Too many requests. Try again in ' . RateLimiter::availableIn($key) . ' seconds.',
                ]);
            }
        }

        // Hit only after both checks pass, so a blocked caller does not extend
        // their own block by continuing to knock.
        foreach ($keys as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    /**
     * The customer a link points at, or null.
     *
     * Not route-model binding: a missing row must produce the ordinary
     * "link is no longer valid" page, not a 404 that says whether the id is
     * real. Trashed customers are excluded — SoftDeletes' default scope does
     * that — because a deleted account is not an account.
     */
    private function customerFor(string $id): ?Customer
    {
        if (! ctype_digit($id)) {
            // Keep the work roughly constant even for obvious rubbish.
            Hash::make(Str::random(16));

            return null;
        }

        return Customer::query()->whereKey((int) $id)->first();
    }
}
