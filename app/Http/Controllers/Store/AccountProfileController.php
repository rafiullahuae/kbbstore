<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Url;
use App\Support\WordPressHasher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * MY ACCOUNT -> PROFILE & PASSWORD (Lane ACC). The owner, 25 October: "keep
 * easy to access, change / update password option etc in user dashboard."
 * Before this the shop had no way at all for a signed-in customer to change
 * her name, her mobile number or her password; only "Forgot password" from
 * the sign-in form.
 *
 * GET  /my-account/profile            the page
 * POST /my-account/profile            first name, last name, mobile
 * POST /my-account/profile/password   current + new password
 *
 * All three sit behind auth:customer (routes/account-profile.php) and the
 * forms carry the CSRF token. The EMAIL is shown and not editable: it is the
 * sign-in name and where receipts go, and changing it safely needs a
 * confirmation round trip this page does not have; the page says to message
 * the shop instead.
 *
 * The password change asks for the CURRENT password and accepts it the way
 * sign-in does (the Laravel hash, or the WordPress hash an imported customer
 * still carries). It is throttled per customer, so a borrowed signed-in phone
 * cannot be used to guess it. On success: Customer::applyNewPassword() (the
 * same write a reset makes, retiring the WordPress hash and any invite link),
 * every OTHER session of hers ended, this session's id rotated, and she stays
 * signed in here.
 */
class AccountProfileController extends Controller
{
    private const PASSWORD_ATTEMPTS = 5;

    private const PASSWORD_DECAY = 600;

    public function __construct(private WordPressHasher $wordpress) {}

    public function show(): View
    {
        $customer = $this->customer();

        return view('store.account.profile', [
            'customer' => $customer,
            'hasPassword' => (string) $customer->password !== '' || (string) $customer->legacy_password !== '',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $customer = $this->customer();

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'],
            // Digits, spaces, +, -, ( and ): every way a mobile is written. Empty clears it.
            'phone' => ['nullable', 'string', 'max:24', 'regex:/^\+?[0-9\s\-()]{6,22}$/'],
        ], [
            'first_name.required' => __('store.profile.name_required'),
            'phone.regex' => __('store.profile.phone_invalid'),
            'phone.max' => __('store.profile.phone_invalid'),
        ]);

        $first = trim((string) $data['first_name']);
        $last = trim((string) ($data['last_name'] ?? ''));

        $customer->forceFill([
            'first_name' => $first,
            'last_name' => $last !== '' ? $last : null,
            'name' => trim($first . ' ' . $last),
            'phone' => ($phone = trim((string) ($data['phone'] ?? ''))) !== '' ? $phone : null,
        ])->save();

        return redirect(Url::redirect('/my-account/profile/'))->with('kac_saved', 'details');
    }

    public function password(Request $request): RedirectResponse
    {
        $customer = $this->customer();
        $key = 'kbb-profile-pw:' . $customer->id;

        if (RateLimiter::tooManyAttempts($key, self::PASSWORD_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'current_password' => __('store.wording.msg_too_many', ['seconds' => RateLimiter::availableIn($key)]),
            ])->redirectTo(Url::redirect('/my-account/profile/') . '#password');
        }

        $data = validator($request->all(), [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ], [
            'password.min' => __('store.profile.password_short'),
            'password.confirmed' => __('store.profile.password_mismatch'),
        ])->validate();

        if (! $this->matches($customer, (string) $data['current_password'])) {
            RateLimiter::hit($key, self::PASSWORD_DECAY);

            throw ValidationException::withMessages(['current_password' => __('store.profile.password_wrong')])
                ->redirectTo(Url::redirect('/my-account/profile/') . '#password');
        }

        RateLimiter::clear($key);

        $customer->applyNewPassword((string) $data['password']);
        $request->session()->regenerate();
        $customer->invalidateSessions($request->session()->getId());

        return redirect(Url::redirect('/my-account/profile/') . '#password')->with('kac_saved', 'password');
    }

    private function matches(Customer $customer, string $plain): bool
    {
        if ((string) $customer->password !== '' && Hash::check($plain, (string) $customer->password)) {
            return true;
        }

        return (string) $customer->legacy_password !== '' && $this->wordpress->check($plain, (string) $customer->legacy_password);
    }

    private function customer(): Customer
    {
        $user = auth('customer')->user();
        abort_unless($user instanceof Customer, 404);

        return $user;
    }
}
