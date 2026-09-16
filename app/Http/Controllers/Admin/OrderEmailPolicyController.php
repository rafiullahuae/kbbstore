<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Mail\OrderStatusMailPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store → Mail → "Which status changes email the customer".
 *
 * The read half feeds two screens from one answer: the settings list, and the
 * "Email the customer about this change" tick box beside the status dropdown on
 * an order — which has to know what to be pre-ticked to, and has to be able to
 * say why it is greyed out for a status nobody wrote a message for. A second
 * endpoint answering the second question separately is a second answer waiting
 * to disagree with the first.
 *
 * ADMIN-GUARDED, like everything else under /admin-api. Not under /api: CLAUDE.md
 * records that `/api/*` is unauthenticated, and this both reads and writes store
 * configuration.
 *
 * THE WRITE GOES THROUGH OrderStatusMailPolicy, WHICH GOES THROUGH
 * SettingsService. Never a direct row write: the toggle map is held in a
 * forever-cache that setModule() clears and a raw insert would not, which leaves
 * every reader in the application — the storefront included — on the value
 * before the change until something unrelated happens to clear it.
 */
class OrderEmailPolicyController extends Controller
{
    public function __construct(private OrderStatusMailPolicy $policy) {}

    /**
     * Every status, whether it can email, whether it does, and why not.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'statuses' => $this->policy->all(),
        ]);
    }

    /**
     * Turn one status on or off.
     *
     * One status per call rather than a whole map, because that is how the
     * screen is used — a tick is a tick — and because a partial save of a map
     * has to invent an answer for the rows it was not sent.
     *
     * A status with no customer message is REFUSED with its reason rather than
     * accepted and quietly ignored. Storing a toggle that could never take
     * effect is the fault CLAUDE.md records this project shipping three times,
     * and it is worse here than elsewhere: the operator would believe they had
     * turned an email on, and the customer would never receive it.
     */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', OrderStatusMailPolicy::STATUSES)],
            'enabled' => ['required', 'boolean'],
        ]);

        $status = (string) $data['status'];

        if (! $this->policy->setEnabled($status, (bool) $data['enabled'])) {
            return response()->json([
                'ok' => false,
                'message' => 'There is no customer email for "' . $status . '". '
                    . (OrderStatusMailPolicy::SILENT_REASONS[$status] ?? ''),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'statuses' => $this->policy->all(),
        ]);
    }
}
