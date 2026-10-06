<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\SiteApp;
use App\Services\SiteAppPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The installed shop app's notification question (Lane NT). routes/site-app-push.php.
 *
 * PUBLIC BY NATURE — anybody can call these, signed in or not — so each
 * answer is built from constants and a fixed list of keys, never a model:
 *
 *   GET  /api/site-app/push       {ask, key, t}: whether to ask, the public
 *                                 VAPID key, the sheet's four strings.
 *   POST /api/site-app/push       store a subscription  -> {ok: true}
 *   POST /api/site-app/push/off   forget one by endpoint -> {ok: true}
 *   POST /api/site-app/push/viewed  a subscribed phone saw an out-of-stock
 *                                 product -> {ok: true}, whatever happened
 *
 * In the web group (session + CSRF), not routes/api.php: the shopper signed
 * in on that phone is read from the SESSION, never from the body, and the
 * two POSTs carry the page's CSRF token. Throttled per address. All three
 * answer 404 while App → Site App is off, the same as the app's other files.
 */
final class SiteAppPushController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];

    public function __construct(private SiteApp $app, private SiteAppPush $push) {}

    public function config(Request $request): JsonResponse
    {
        if (! $this->app->on()) {
            return $this->gone();
        }

        return response()->json($this->push->config(SiteAppPush::locale($request->query('lang'))), 200, self::HEADERS, JSON_UNESCAPED_UNICODE);
    }

    public function subscribe(Request $request): JsonResponse
    {
        if (! $this->app->on()) {
            return $this->gone();
        }
        if (strlen((string) $request->getContent()) > SiteAppPush::MAX_BODY) {
            return response()->json(['ok' => false], 413, self::HEADERS);
        }

        $sub = SiteAppPush::clean($request->input('endpoint'), $request->input('keys.p256dh'), $request->input('keys.auth'));
        if ($sub === null) {
            return response()->json(['ok' => false], 422, self::HEADERS);
        }

        $id = auth('customer')->id();
        $id = is_numeric($id) ? (int) $id : null;
        $token = SiteAppPush::token($request->cookie(SiteAppPush::COOKIE)) ?? SiteAppPush::newToken();

        SiteAppPush::store($sub, $token, $id, SiteAppPush::locale($request->input('lang')),
            SiteAppPush::locate($request, $id), SiteAppPush::platform($request->userAgent()));

        // The phone's subscriber handle: random, first-party, HttpOnly, no PII.
        // An order placed from this phone finds its row by it (orderPlaced()).
        return response()->json(['ok' => true], 200, self::HEADERS)->cookie(
            SiteAppPush::COOKIE, $token, SiteAppPush::COOKIE_MINUTES, '/', null, $request->isSecure(), true, false, 'lax',
        );
    }

    /** A subscribed phone viewed an out-of-stock product (site-app.js's beacon). */
    public function viewed(Request $request): JsonResponse
    {
        if (! $this->app->on()) {
            return $this->gone();
        }
        if (strlen((string) $request->getContent()) > 256) {
            return response()->json(['ok' => false], 413, self::HEADERS);
        }
        $pid = $request->input('product_id');
        if (! is_int($pid) || $pid < 1) {
            return response()->json(['ok' => false], 422, self::HEADERS);
        }

        // The same answer whether or not the phone is subscribed or the
        // product is out: nothing here tells a caller anything.
        $token = SiteAppPush::token($request->cookie(SiteAppPush::COOKIE));
        if ($token !== null) {
            SiteAppPush::viewed($token, $pid);
        }

        return response()->json(['ok' => true], 200, self::HEADERS);
    }

    /**
     * A notification was tapped (Lane PN): sw.js posts the click token the
     * payload carried. Counted once per message, and only for a token this
     * shop signed (HMAC); anything else is ignored with the same answer.
     */
    public function click(Request $request): JsonResponse
    {
        if (! $this->app->on()) {
            return $this->gone();
        }
        if (strlen((string) $request->getContent()) > 200) {
            return response()->json(['ok' => false], 413, self::HEADERS);
        }
        $id = \App\Services\Push\PushSender::clickId($request->input('c'));
        if ($id !== null) {
            \App\Services\Push\PushSender::click($id);
        }

        return response()->json(['ok' => true], 200, self::HEADERS);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        if (! $this->app->on()) {
            return $this->gone();
        }
        if (strlen((string) $request->getContent()) > SiteAppPush::MAX_BODY) {
            return response()->json(['ok' => false], 413, self::HEADERS);
        }

        SiteAppPush::forget($request->input('endpoint'));

        return response()->json(['ok' => true], 200, self::HEADERS);
    }

    private function gone(): JsonResponse
    {
        return response()->json(['ok' => false], 404, self::HEADERS);
    }
}
