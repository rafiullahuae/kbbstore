<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Support\DeliveryTimeline;
use App\Support\OrderLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * THE DELIVERY TIMELINE'S REFRESH (Quiqup P3, QUIQUP-PLAN §6.4).
 *
 *   GET /track-my-order/live?order=&t=   guest; the SIGNED track token only
 *   GET /my-account/orders/{id}/live     signed-in; the shopper's own orders
 *
 * Called by the few hundred bytes of script the two pages print while a
 * parcel is moving: on return to the tab, at most 6 times. Never number +
 * email: the guest endpoint takes only the HMAC'd token, so it cannot be used
 * to probe order numbers (OrderLinks::resolve() does the same work, and
 * answers the same 404, for a forged, expired or other-order token).
 *
 * THE ANSWER IS AN ALLOWLIST. Exactly {v, stage, label, at_local,
 * window_local, attempts, ended, html}: no shipment id, no Quiqup id, no
 * courier, no reason, no phone, no address, no otp. `html` is the list
 * partial, server-rendered with every word escaped.
 *
 * CHEAP WHEN NOTHING CHANGED. The ETag is read off the order row
 * (ship_state, ship_state_at, language), so an If-None-Match that still
 * matches answers 304 before the shipment is looked at.
 */
final class DeliveryLiveController extends Controller
{
    /** The only keys the refresh ever returns. Pinned by QuiqupTimelineTest. */
    public const KEYS = ['v', 'stage', 'label', 'at_local', 'window_local', 'attempts', 'ended', 'html'];

    public function guest(Request $request): JsonResponse|Response
    {
        $order = OrderLinks::resolve(OrderLinks::TRACK, (string) $request->query('order', ''), (string) $request->query('t', ''));

        return $this->answer($request, $order);
    }

    public function account(Request $request, int $id): JsonResponse|Response
    {
        $customer = auth('customer')->user();
        $order = $customer instanceof Customer ? $customer->orders()->find($id) : null;

        return $this->answer($request, $order);
    }

    private function answer(Request $request, ?Order $order): JsonResponse|Response
    {
        if ($order === null || ! DeliveryTimeline::visible($order)) {
            return self::private(response()->json(['result' => 'not_found'], 404));
        }

        $v = DeliveryTimeline::version($order);
        $sent = trim((string) $request->headers->get('If-None-Match', ''), " \"W/");

        if ($sent !== '' && hash_equals($v, $sent)) {
            return self::private(response('', 304)->header('ETag', '"' . $v . '"'));
        }

        $tl = DeliveryTimeline::for($order);

        if ($tl === null) {
            return self::private(response()->json(['result' => 'not_found'], 404));
        }

        $body = [
            'v' => $v,
            'stage' => $tl['stage'],
            'label' => $tl['label'],
            'at_local' => $tl['at_local'],
            'window_local' => $tl['window_local'],
            'attempts' => $tl['attempts'],
            'ended' => $tl['ended'],
            'html' => view('partials.account.delivery-timeline-list', ['tl' => $tl])->render(),
        ];

        return self::private(response()->json(array_intersect_key($body, array_flip(self::KEYS)))->header('ETag', '"' . $v . '"'));
    }

    /** One shopper's order: never cached by a shared cache, always revalidated. */
    private static function private(JsonResponse|Response $r): JsonResponse|Response
    {
        $r->headers->set('Cache-Control', 'private, no-cache');
        $r->headers->set('X-Robots-Tag', 'noindex');

        return $r;
    }
}
