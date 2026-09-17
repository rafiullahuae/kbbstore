<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CartRecovery;
use App\Services\OutboundBacklog;
use App\Services\OutboundTick;
use App\Services\StockAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the owner sees about the two shopper-triggered emails (Lane EN).
 *
 * ── ADMIN ONLY, AND NOT NEGOTIABLE ─────────────────────────────────────────
 *
 * CLAUDE.md records that `/api/*` is unauthenticated by design and has leaked
 * three times. Everything here is mounted in the admin-api group behind
 * `auth:admin`. The demand list is commercial information — what this shop
 * cannot keep in stock — and `sweep` CAUSES EMAILS TO BE SENT, which
 * unauthenticated is a button a stranger can press to spend this shop's sending
 * reputation. Same reasoning routes/mail-admin.php gives for the test-send.
 *
 * ── NO ADDRESSES ANYWHERE ──────────────────────────────────────────────────
 *
 * The demand list COUNTS the people waiting for each product; it does not list
 * them. The owner's question is "what should I reorder" and a number answers
 * it. A screen that printed the addresses would be an export of people who
 * asked this shop for one specific thing, built for a question that did not
 * need it — and CLAUDE.md's rule about checking what columns a model carries
 * before returning it is the standing version of that argument.
 *
 * The backlog is counts and timestamps only, for the same reason.
 */
class OutboundApiController extends Controller
{
    public function __construct(
        private StockAlerts $alerts,
        private CartRecovery $recovery,
        private OutboundBacklog $backlog,
        private OutboundTick $tick,
    ) {}

    /**
     * Store → Mail → Sent mail, the panel above the delivery log: what is owed
     * and why it has not gone.
     */
    public function backlog(): JsonResponse
    {
        return response()->json($this->backlog->summary());
    }

    /**
     * The demand list: what shoppers are waiting for, most-wanted first.
     *
     * A genuine reorder signal and cheap — one grouped query over an indexed
     * column — which is why it is built rather than deferred. The owner
     * question in the hand-back is whether he wants it as a REPORT with its own
     * screen; this endpoint is the data either way, and it also fills the
     * Back-in-stock settings screen's own panel.
     */
    public function demand(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 200));

        return response()->json([
            'enabled' => $this->alerts->enabled(),
            // So the screen can say "switched on but no wording, so nothing
            // will be sent" rather than showing a healthy-looking empty list.
            'message_written' => $this->alerts->messageWording() !== null,
            'form_written' => $this->alerts->formLabel() !== null,
            'products' => $this->alerts->demand($limit),
        ]);
    }

    /**
     * Send what is owed, now.
     *
     * WHY THIS EXISTS despite the tick. The tick runs on the tail of ordinary
     * page views, so on a quiet shop the owner has no way to make anything
     * happen and no way to tell a misconfiguration from an absence of visitors.
     * This is the button that answers "is it actually working" — he presses it,
     * and the Sent mail screen either fills up or shows him the transport's
     * refusal in the shop's own words.
     *
     * It is NOT a way to send twice. It calls the same run() the tick calls,
     * and every send inside it goes through the same claim; a message that has
     * already gone cannot be claimed again however many times this is pressed.
     * Pressing it while a tick is mid-sweep is two processes racing for the same
     * rows, which is precisely the case the compare-and-swap is written for.
     *
     * Throttled at the route, because it is a button that sends email.
     */
    public function sweep(): JsonResponse
    {
        $result = $this->tick->run();

        return response()->json([
            'sent' => $result,
            'backlog' => $this->backlog->summary(),
        ]);
    }

    /**
     * What the cart-recovery settings screen needs to draw itself honestly.
     *
     * `schedule` comes back PARSED rather than as the owner typed it, so the
     * screen shows what will actually happen. An owner who typed "4, 24, banana"
     * should see two stages and not three, and should see it before a shopper
     * does.
     */
    public function recovery(): JsonResponse
    {
        return response()->json([
            'enabled' => $this->recovery->enabled(),
            'message_written' => $this->recovery->messageWording() !== null,
            'optin_written' => $this->recovery->optInLabel() !== null,
            'schedule_hours' => $this->recovery->schedule(),
            'max_stages' => CartRecovery::MAX_STAGES,
        ]);
    }
}
