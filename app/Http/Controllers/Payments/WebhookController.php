<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\HandlesWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The one entry point for provider callbacks.
 *
 * Every gateway's webhook arrives here and is dispatched by id, so the things
 * that must be true of all of them are true in one place rather than three:
 *
 *  - A gateway that does not implement HandlesWebhooks gets a 404, not a
 *    "nothing happened" 200. `cod` has no webhook; an endpoint that quietly
 *    accepted a POST for it would be an endpoint nobody was verifying.
 *  - Verification is the gateway's own first act. This controller does not
 *    parse, read or log the body before calling it — it does not touch the
 *    body at all.
 *  - Nothing about the outcome is disclosed beyond a short status word. An
 *    endpoint that answers "no order for that reference" differently from
 *    "amount mismatch" to an unauthenticated caller is an oracle for probing
 *    order numbers, so those distinctions stay in our logs. A rejected caller
 *    is told only that it was rejected.
 *
 * These routes must be CSRF-exempt and unauthenticated by design: the caller
 * is a server in Dubai or Dublin with no session. The signature IS the
 * authentication, which is why HandlesWebhooks exists to make it impossible to
 * route here without one.
 */
class WebhookController extends Controller
{
    public function __construct(private GatewayRegistry $registry) {}

    public function handle(Request $request, string $gateway): JsonResponse
    {
        $handler = $this->registry->find($gateway);

        if (! $handler instanceof HandlesWebhooks) {
            return response()->json(['error' => 'not found'], 404);
        }

        $outcome = $handler->handleWebhook($request);

        // Logged with no body and no customer fields -- the gateway, what we
        // decided, and the code we answered with.
        Log::info('payments: webhook handled', [
            'gateway' => $gateway,
            'accepted' => $outcome->accepted,
            'status' => $outcome->status,
            'detail' => $outcome->message,
        ]);

        return response()->json(
            ['result' => $outcome->accepted ? 'ok' : 'rejected'],
            $outcome->status,
        );
    }
}
