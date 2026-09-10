<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminPathService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AdminPathController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $key = 'admin-path|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->with('kbb_update_errors', ['Too many attempts. Wait a few minutes.']);
        }

        RateLimiter::hit($key, 600);

        $request->validate([
            'admin_path' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string'],
        ]);

        $admin = Auth::guard('admin')->user();

        if (! $admin || ! Hash::check($request->string('password')->toString(), $admin->password)) {
            return back()->with('kbb_update_errors', ['That password was not correct. Nothing changed.']);
        }

        if (AdminPathService::isLockedByEnv()) {
            return back()->with('kbb_update_errors', [
                'KBB_ADMIN_PATH is set in .env, which always wins. Remove that line to manage the path from here.',
            ]);
        }

        $result = AdminPathService::set($request->string('admin_path')->toString());

        if (! $result['ok']) {
            return back()->with('kbb_update_errors', [$result['error']]);
        }

        $new = trim($request->string('admin_path')->toString(), '/');

        // Redirect to the NEW location, because the old one no longer exists.
        return redirect('/' . $new . '/updates')
            ->with('kbb_update_message', "Admin moved to /{$new}. Bookmark it — the old address now returns 404.");
    }
}
