<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\CustomerInvites\CustomerInviter;
use App\Services\Mail\EmailBranding;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The page an account invite links to: "your account is ready — choose a
 * password". (Lane PQ — sent from Store → Customers → Send account invite.)
 *
 * PUBLIC, and so built like PasswordResetController:
 *
 *  - ONE ANSWER FOR EVERY DEAD LINK. Unknown, expired, already used, replaced
 *    by a newer invite, or pointing at a deleted customer: the same page with
 *    the same sentence, INVALID_MESSAGE. A page that said "expired" for real
 *    tokens and "not found" for made-up ones would tell a stranger which links
 *    once existed.
 *  - RATE-LIMITED twice: `throttle` on the route as the outer fence, and a
 *    per-IP limiter here on the form that changes a password.
 *  - The token is 256 bits and only its sha256 is stored, so guessing one is
 *    not a strategy; the limits are about cost, not about that.
 *  - `no-store` on the page, because the token is in its address and no
 *    shared computer's cache may keep it. The Referer is already safe: the
 *    site-wide SecurityHeaders middleware sends
 *    `strict-origin-when-cross-origin`, so anything cross-origin the page
 *    loads is told the origin only, never the path that carries the token.
 *
 * The password rule is the shop's existing one — registration and the reset
 * form both say `min:8` and `confirmed` — so a customer is not told one thing
 * here and another at sign-up.
 *
 * On success the customer is SIGNED IN, as the owner asked: they clicked a link
 * that only arrives in their mailbox, which is the same proof of control the
 * reset link relies on, and making them type the password they just chose is
 * friction with no security in it.
 */
class AccountInviteController extends Controller
{
    public const INVALID_MESSAGE_KEY = 'store.account.welcome_invalid';

    private const ATTEMPTS = 10;

    private const DECAY = 900;

    public function __construct(
        private CustomerInviter $inviter,
        private EmailBranding $branding,
    ) {}

    public function edit(Request $request, string $token): Response
    {
        $customer = $this->inviter->customerForToken($token);

        return $this->page(response()->view('store.account.welcome', [
            'valid' => $customer !== null,
            'token' => $customer !== null ? $token : '',
            'email' => $customer?->email,
            'shop' => $this->branding->storeName(),
            'message' => $customer === null ? __(self::INVALID_MESSAGE_KEY) : null,
        ]));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $key = 'kbb-invite:' . sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            throw ValidationException::withMessages([
                'password' => 'Too many attempts. Try again in ' . RateLimiter::availableIn($key) . ' seconds.',
            ]);
        }

        RateLimiter::hit($key, self::DECAY);

        $customer = $this->inviter->accept($data['token'], $data['password']);

        if ($customer === null) {
            // The token is NOT flashed back: a dead link has nothing to retry.
            return redirect(Url::redirect('/my-account/welcome/' . (CustomerInviter::wellFormed($data['token']) ? $data['token'] : 'invalid') . '/'));
        }

        RateLimiter::clear($key);

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        session()->flash('kbb.greet', ['kind' => 'new', 'name' => (string) strtok(trim((string) $customer->displayName()), ' ')]);

        return redirect(Url::redirect('/my-account/'));
    }

    private function page(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
