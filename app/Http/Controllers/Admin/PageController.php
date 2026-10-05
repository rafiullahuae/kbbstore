<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class PageController extends Controller
{
    /** The back-office SPA (kbb-admin.html adopted verbatim, wired to the admin API). */
    public function app(): Response
    {
        // Never cached. The whole console — sidebar, screens, scripts — is this
        // one document, so a browser holding yesterday's copy shows yesterday's
        // menu after an update has already been applied. That is indistinguishable
        // from the update having failed, and cost a round of chasing when a new
        // screen did not appear.
        //
        // (Lane AP) The large static <script>/<style> blocks inside it are a
        // different matter: they are swapped for cached files from the build,
        // and only where the build holds the exact bytes this render produced.
        // See App\Support\AdminConsoleAssets.
        [$html, $assetsCookie] = \App\Support\AdminConsoleAssets::serve(view('admin.app')->render(), request());
        $response = response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');

        // (Lane AP) "this browser now holds the files": set on the inline load
        // that prefetches them. See AdminConsoleAssets::serve().
        if ($assetsCookie !== null) {
            $response->withCookie($assetsCookie);
        }

        /*
         * The storefront admin hint, refreshed every time the console opens
         * (Lane RA). Sign-in sets it; this is what gives it to a session that
         * was already signed in on the day the package was applied, and what
         * keeps a remembered sign-in's hint alive. See StorefrontAdminHint.
         */
        if (\App\Support\StorefrontAdminHint::wantedBy(\Illuminate\Support\Facades\Auth::guard('admin')->user())) {
            $response->withCookie(\App\Support\StorefrontAdminHint::refresh(request()));
        }

        return $response;
    }
}
