<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payments\GatewayPreflight;
use Illuminate\Http\JsonResponse;

/**
 * Store → Ecommerce → Payments, the "check my setup" half.
 *
 * Read only. It writes nothing, it takes no input beyond a gateway id that
 * must already be one this build ships, and — because GatewayPreflight fakes
 * the HTTP client for the duration — it sends nothing to any provider. It can
 * be pressed as often as the owner likes, on a live shop, at any hour, without
 * creating a payment, a session, or an order.
 *
 * Behind `auth:admin` like every other payments route, for the same reason:
 * the response describes which credentials are missing and what the webhook
 * URL is, which is a map of how to interfere with this shop's money.
 */
class PaymentPreflightController extends Controller
{
    public function __construct(private GatewayPreflight $preflight) {}

    /** Every gateway at once, for the screen's overview. */
    public function index(): JsonResponse
    {
        return response()->json(['gateways' => $this->preflight->inspectAll()]);
    }

    /** One gateway, for the panel under its own tab. */
    public function show(string $gateway): JsonResponse
    {
        $result = $this->preflight->inspect($gateway);

        // A gateway id this build has no code for is a 404 rather than an
        // empty panel, so a typo in the screen is visible as a typo.
        if (($result['known'] ?? false) !== true) {
            return response()->json(['error' => 'Unknown payment gateway.'], 404);
        }

        return response()->json($result);
    }
}
