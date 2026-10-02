<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin');
        }
        return view('admin.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // Brute-force protection: 5 attempts per email+IP, then a 60s cooldown.
        $key = Str::lower($data['email']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            /*
             * The one rate-limit trip nothing else can see (Lane C).
             *
             * This throttle answers with a validation error and a 302, not a
             * 429, and it turns the attempt away BEFORE the guard is asked —
             * so neither the Failed auth event nor the 429 listener in
             * App\Services\SecurityModule ever hears about it. It is also the
             * most interesting limiter in the shop, being the one in front of
             * the password, and until now it left no trace anywhere.
             *
             * An explicit call at the write, which is what Phase 18 asks for in
             * place of a middleware: it records and returns. Nothing about the
             * throttle's behaviour above or below this line changes, the email
             * is recorded and the password never is, and record() swallows its
             * own failures so a shop whose audit table is not migrated yet
             * still gets the same 302 it always did.
             */
            app(\App\Services\SecurityModule::class)->recordSignInBlocked(
                Str::lower($data['email']),
                $seconds
            );

            throw ValidationException::withMessages([
                'email' => "Too many attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        if (Auth::guard('admin')->attempt(
            ['email' => $data['email'], 'password' => $data['password']],
            $request->boolean('remember')
        )) {
            RateLimiter::clear($key);
            $request->session()->regenerate();

            $response = redirect()->intended(route('admin'));

            /*
             * The storefront's admin hint (Lane RA): a non-secret cookie that
             * tells the shop's ordinary JavaScript it is worth asking whether
             * to draw the admin bar and the quick-edit pencil. Only for an
             * account that holds one of those -- see StorefrontAdminHint.
             */
            if (\App\Support\StorefrontAdminHint::wantedBy(Auth::guard('admin')->user())) {
                $response->withCookie(\App\Support\StorefrontAdminHint::make($request->boolean('remember')));
            }

            return $response;
        }

        RateLimiter::hit($key, 60);
        return back()->withErrors(['email' => 'Those credentials do not match our records.'])
                     ->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Expired whoever is signing out: a hint left behind would only make
        // the shop ask a question whose answer is now 401. (Lane RA)
        return redirect()->route('admin.login')
            ->withCookie(\App\Support\StorefrontAdminHint::forget());
    }
}
