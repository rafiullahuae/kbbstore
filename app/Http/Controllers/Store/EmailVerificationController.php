<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Notifications\CustomerEmailVerification;
use App\Rules\StorefrontEmail;
use App\Support\CustomerLinkSigner;
use App\Support\Url;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

/**
 * Email verification for storefront customers.
 *
 * `customers.email_verified_at` has been on the table since the baseline schema
 * and nothing has ever written to it. This writes to it.
 *
 * ---------------------------------------------------------------------------
 * WHY THE LINK IS NOT A LARAVEL SIGNED URL
 * ---------------------------------------------------------------------------
 * Support\CustomerLinkSigner carries the full argument. In one paragraph: the
 * signed-URL path-confusion advisory is one of the three CLAUDE.md records
 * against this Laravel 11 install, a verification link is exactly the artefact
 * it concerns, and separately, `hasValidSignature()` re-derives the signature
 * from the incoming request's URL — which on this install is prefixed by
 * KBB_BASE_PATH. A signature computed over the URL is therefore a signature
 * that depends on the prefix being the same when the link is clicked as when it
 * was minted, and it is not: APP_URL already ends in /kbb-upgrade, the prefix is
 * configurable, and a proxy can rewrite it. What breaks is not a test, it is
 * every verification link in every customer's inbox, silently and all at once.
 *
 * So what is signed contains no URL: purpose, customer id, address digest,
 * expiry. Identical under any base path, by construction, which is what
 * CustomerAuthSignatureTest asserts.
 *
 * ---------------------------------------------------------------------------
 * ENUMERATION
 * ---------------------------------------------------------------------------
 * The public verify endpoint takes a customer id, which is a small integer.
 * Every failure — unknown id, wrong digest, bad MAC, expired link — renders the
 * identical page with the identical message, so walking the ids tells an
 * attacker nothing. The MAC is checked before anything else is believed.
 */
class EmailVerificationController extends Controller
{
    public const FAILED_MESSAGE = 'That confirmation link is not valid. It may have expired, or the address on the account may have changed since it was sent. Sign in and we will send you a new one.';

    /** Resends: three per customer per hour, and per IP. Each one sends mail. */
    private const RESEND_ATTEMPTS = 3;

    private const RESEND_DECAY = 3600;

    private const RESEND_IP_ATTEMPTS = 10;

    /**
     * Confirm an address.
     *
     * Public by necessity: the customer is clicking from their mail client and
     * may well not be signed in, possibly not even on the same device.
     */
    public function verify(Request $request, string $id, string $hash): View
    {
        $expires = (int) $request->query('expires', '0');
        $signature = (string) $request->query('signature', '');

        $customer = ctype_digit($id)
            ? Customer::query()->whereKey((int) $id)->first()
            : null;

        /*
         * The MAC first, before the row is trusted for anything. The claims fed
         * to the verifier are rebuilt from the REQUEST (the id and hash in the
         * path), not from the customer row — verifying a MAC over values you
         * looked up yourself proves nothing about the values that arrived.
         */
        $signed = CustomerLinkSigner::verify(
            CustomerEmailVerification::PURPOSE,
            ['id' => $id, 'hash' => $hash],
            $expires,
            $signature,
        );

        // Only now may the row have a say, and only to confirm that the digest
        // in the link still matches the address on the account. A customer who
        // changed their address after the mail went out invalidates the link.
        $ok = $signed
            && $customer !== null
            && hash_equals($customer->verificationHash(), $hash);

        if (! $ok) {
            return view('store.account.verify-result', [
                'ok' => false,
                'message' => self::FAILED_MESSAGE,
            ]);
        }

        // Idempotent: clicking twice is a normal thing for a person to do, and
        // the second click must not look like a failure.
        $customer->markEmailAsVerified();

        return view('store.account.verify-result', [
            'ok' => true,
            'message' => 'Thank you — your email address is confirmed.',
        ]);
    }

    /** "We sent you a link" — shown to a signed-in customer who has not confirmed. */
    public function notice(Request $request): View
    {
        return view('store.account.verify-notice', [
            'customer' => $request->user('customer'),
        ]);
    }

    /**
     * Send (or re-send) the confirmation link to the signed-in customer.
     *
     * Behind `auth:customer`, so there is no address to accept and no oracle to
     * open: the recipient is the address already on the session's own account.
     * It is still rate limited, because it still sends mail on demand.
     */
    public function resend(Request $request): RedirectResponse
    {
        /** @var Customer|null $customer */
        $customer = $request->user('customer');

        if ($customer === null) {
            return redirect(Url::redirect('/my-account/'));
        }

        $keys = [
            'kbb-verify-send:' . $customer->getKey() => self::RESEND_ATTEMPTS,
            'kbb-verify-ip:' . sha1((string) $request->ip()) => self::RESEND_IP_ATTEMPTS,
        ];

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'email' => 'We have already sent a confirmation link. Try again in '
                        . RateLimiter::availableIn($key) . ' seconds.',
                ]);
            }
        }

        if ($customer->hasVerifiedEmail()) {
            return redirect(Url::redirect('/my-account/'))
                ->with('status', 'Your email address is already confirmed.');
        }

        /*
         * A stored address that would not pass the form's own rule is not sent
         * to. Rows predate this rule — 3,712 of them came out of WordPress —
         * and the CRLF advisory is about what ends up in a header, so the check
         * belongs at the point of sending as well as at the point of entry.
         */
        if (! StorefrontEmail::passes((string) $customer->email)) {
            Log::warning('Refusing to send a verification link to an address that fails validation.', [
                'customer_id' => $customer->getKey(),
            ]);

            return redirect(Url::redirect('/my-account/'))
                ->with('status', 'We could not send to the address on this account. Please update it first.');
        }

        foreach (array_keys($keys) as $key) {
            RateLimiter::hit($key, self::RESEND_DECAY);
        }

        // Deferred for the same reason the reset link is — see
        // PasswordResetController. Here it buys latency rather than secrecy,
        // but the failure handling is the point: a mail server that is refusing
        // connections must not turn the account page into a 500.
        defer(function () use ($customer): void {
            try {
                $customer->sendEmailVerificationNotification();
            } catch (\Throwable $e) {
                Log::warning('Customer verification link could not be sent.', [
                    'customer_id' => $customer->getKey(),
                    'exception' => $e::class,
                ]);
            }
        }, 'kbb-verify-send-' . $customer->getKey());

        return redirect(Url::redirect('/my-account/verify'))
            ->with('status', 'We have sent a confirmation link to your email address.');
    }
}
