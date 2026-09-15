<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Rules\StorefrontEmail;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CartService;
use App\Support\WordPressHasher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Customer accounts — ledger item F-10. The previous build had none.
 *
 * The interesting part is login: an imported customer's WordPress password still
 * works. On the first successful sign-in the legacy hash is verified, a bcrypt
 * hash is written, and the legacy one is cleared. Accounts upgrade themselves as
 * people return, and nobody is forced through a password reset at launch.
 */
class CustomerAuthController extends Controller
{
    public function __construct(
        private CartService $carts,
        private WordPressHasher $wordpress,
    ) {}

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'login:' . mb_strtolower($data['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in ' . RateLimiter::availableIn($key) . ' seconds.',
            ]);
        }

        $customer = Customer::where('email', $data['email'])->first();

        if (! $customer || ! $this->verify($customer, $data['password'])) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['email' => 'Those details did not match our records.']);
        }

        RateLimiter::clear($key);

        // Carry the guest basket into the account instead of discarding it.
        $guestCart = $this->carts->current($request, create: false);

        Auth::guard('customer')->login($customer, $request->boolean('remember'));
        $request->session()->regenerate();

        if ($guestCart && $guestCart->customer_id === null && $guestCart->items()->exists()) {
            $this->carts->mergeGuestCart($guestCart, $customer->id);
        }

        // Shown once inside the account panel, then gone.
        session()->flash('kbb.greet', ['kind' => 'back', 'name' => $this->firstName($customer)]);

        return redirect()->intended('/my-account/');
    }

    public function register(Request $request): RedirectResponse
    {
        // A small sum, checked before anything is created. Sign-ups are the one
        // public write on the site, so they are the one worth guarding.
        $header = app(\App\Services\HeaderSettings::class);

        if ($header->get('account_check')
            && ! app(\App\Services\HumanCheck::class)->passes(
                $request->input('hc_token'), $request->input('hc_answer'))) {
            return back()
                ->withInput($request->except(['password', 'hc_answer']))
                ->withErrors(['hc_answer' => 'That sum was not right. Please try the new one.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'max:160', new StorefrontEmail, 'unique:customers,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $customer = Customer::create([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
        ]);

        $guestCart = $this->carts->current($request, create: false);

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        if ($guestCart && $guestCart->customer_id === null && $guestCart->items()->exists()) {
            $this->carts->mergeGuestCart($guestCart, $customer->id);
        }

        // Shown once inside the account panel, then gone.
        session()->flash('kbb.greet', ['kind' => 'new', 'name' => $this->firstName($customer)]);

        return redirect('/my-account/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * The name to greet this shopper by, off the Customer we just signed in.
     *
     * NOT `auth()->guard()->user()`, which is what both call sites used to say.
     * That is the DEFAULT guard — `web`, the admin-side `users` table — and
     * SessionGuard::login() does not make `customer` the default, so the name
     * came from whoever held the `web` session in that browser. Two outcomes,
     * both wrong: for an ordinary shopper the web guard has nobody, so `?? ''`
     * fired and every "Welcome back" rendered with a blank name; and on a
     * browser where an admin was also signed in — the owner checking their own
     * storefront, or any shared back-office machine — the shopper was greeted
     * with the ADMIN USER'S NAME, putting a back-office identity on a
     * storefront page. Same default-guard trap the AccountController class
     * docblock was written about.
     *
     * The Customer is already in hand at both call sites, so no guard needs
     * consulting at all.
     */
    private function firstName(Customer $customer): string
    {
        return (string) strtok(trim((string) $customer->name), ' ');
    }

    /**
     * Check the password, transparently upgrading a WordPress hash to bcrypt on
     * the first successful sign-in.
     */
    private function verify(Customer $customer, string $password): bool
    {
        if ($customer->password && Hash::check($password, $customer->password)) {
            return true;
        }

        if ($customer->legacy_password && $this->wordpress->check($password, $customer->legacy_password)) {
            $customer->forceFill([
                'password' => Hash::make($password),
                'legacy_password' => null,
            ])->save();

            return true;
        }

        return false;
    }
}
