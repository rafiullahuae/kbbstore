<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\Gateways\TamaraGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store → Ecommerce → Payments → Tamara: the two things that cannot be typed in.
 *
 * ── WHAT THIS IS FOR ────────────────────────────────────────────────────────
 *
 * Every other Tamara setting is a box on the payments screen. Two are not, and
 * both were missing entirely:
 *
 *   THE WEBHOOK REGISTRATION. Tamara delivers `order_expired` and
 *   `order_declined` only to an endpoint registered through its own
 *   `POST /webhooks`. The notification URL handed over at session creation
 *   carries the approval and nothing else. So until this endpoint existed,
 *   TamaraGateway::handleWebhook()'s decline branch was unreachable code:
 *   a refused shopper left an order `pending` for ever, holding its stock
 *   claim and its coupon use. There is no way to do this by hand — Tamara's
 *   merchant portal has no webhook screen — so without a button it cannot be
 *   done at all.
 *
 *   THE BASKET LIMITS. Tamara agrees a minimum and maximum basket value per
 *   merchant per market and refuses a session outside them. The numbers are in
 *   the portal and can be typed in, but they change, and `GET
 *   /checkout/payment-types` is the authority. One button beats asking the owner
 *   to remember.
 *
 * ── SECRETS ─────────────────────────────────────────────────────────────────
 *
 * NOTHING THIS CONTROLLER RETURNS CONTAINS A CREDENTIAL. Not the API token, not
 * the notification token, not the webhook secret, and not the webhook URL —
 * which embeds the webhook secret and is therefore treated as one here. The
 * payments screen already shows that URL with a copy button under
 * `payments.manage`; repeating it in a second response is a second place it can
 * be cached, proxied or shoulder-read for no gain.
 *
 * What does come back: whether a webhook is registered, its id (useless without
 * a key, and the thing the owner needs in order to know the answer), the two
 * limits, and short machine codes from PaymentGateway's own error mapping.
 * `RemoteGateway::errorCode()` has already stripped those to identifier
 * characters and capped them at 64, so a Tamara error message containing a
 * customer's name cannot arrive here.
 *
 * ── GUARD ───────────────────────────────────────────────────────────────────
 *
 * `payments.manage`, mapped in App\Support\AdminCapabilities — the same
 * capability as the screen that edits the keys by hand, and for the reason
 * written out there for the Stripe connect routes: these do the same thing with
 * fewer keystrokes. One of them changes what Tamara sends this shop and another
 * changes which baskets are offered BNPL at all. Owner-only, as `payments.manage`
 * already is.
 */
class TamaraAdminController extends Controller
{
    public function __construct(
        private GatewayRegistry $registry,
        private GatewayCredentials $credentials,
    ) {}

    /**
     * The state of the two provider-side settings.
     *
     * Reads only, and never calls Tamara. This is rendered with the payments
     * screen and a BNPL API having a slow morning must not be the reason the
     * owner cannot look at his own settings — the same rule
     * PaymentCapturer::status() follows.
     */
    public function show(): JsonResponse
    {
        $gateway = $this->gateway();

        if ($gateway === null) {
            return response()->json(['error' => 'unsupported'], 404);
        }

        // forget() before reading, for the reason PaymentsApiController::show()
        // sets out at length: GatewayCredentials memoises per instance, and a
        // controller instance outlives one request wherever the process does.
        // Without this, a register that just wrote `webhook_id` reads back as
        // "not registered".
        $this->credentials->forget($gateway->id());

        return response()->json($this->state($gateway));
    }

    /**
     * Register this shop's webhook endpoint with Tamara.
     *
     * Idempotent: an already-registered webhook is reported as registered rather
     * than replaced, because two registrations mean every expiry delivered twice
     * and Tamara offers no "replace" call.
     */
    public function registerWebhook(): JsonResponse
    {
        $gateway = $this->gateway();

        if ($gateway === null) {
            return response()->json(['error' => 'unsupported'], 404);
        }

        $result = $gateway->registerWebhook();

        $this->credentials->forget($gateway->id());

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'error' => $result['error'],
                'message' => $this->explain((string) $result['error']),
                'tamara' => $this->state($gateway),
            ], $this->statusFor((string) $result['error']));
        }

        return response()->json([
            'ok' => true,
            'created' => $result['created'],
            'message' => $result['created']
                ? 'Tamara will now send expiry and decline notices to this shop.'
                : 'A webhook was already registered; nothing was changed.',
            'tamara' => $this->state($gateway),
        ]);
    }

    /** Remove the registration again. */
    public function unregisterWebhook(): JsonResponse
    {
        $gateway = $this->gateway();

        if ($gateway === null) {
            return response()->json(['error' => 'unsupported'], 404);
        }

        $result = $gateway->unregisterWebhook();

        $this->credentials->forget($gateway->id());

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'error' => $result['error'],
                'message' => $this->explain((string) $result['error']),
                'tamara' => $this->state($gateway),
            ], $this->statusFor((string) $result['error']));
        }

        return response()->json([
            'ok' => true,
            'message' => 'The webhook registration was removed. Tamara will no longer send expiry '
                . 'and decline notices, so declined orders will stay pending until somebody looks.',
            'tamara' => $this->state($gateway),
        ]);
    }

    /**
     * Pull the basket limits from Tamara and store them.
     *
     * The country and currency may be named, for a shop that sells into more
     * than one of Tamara's markets and wants to check another one's limits
     * before switching. Both are validated against the gateway's own
     * allowlists inside refreshLimits(), which returns null rather than
     * querying a market Tamara does not serve — so a country posted from a
     * browser cannot become part of a URL this shop calls.
     */
    public function refreshLimits(Request $request): JsonResponse
    {
        $gateway = $this->gateway();

        if ($gateway === null) {
            return response()->json(['error' => 'unsupported'], 404);
        }

        $data = $request->validate([
            // Two letters and three letters. Bounded here as well as
            // allowlisted in the gateway, so a 4 KB "country" never reaches the
            // query builder at all.
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
        ]);

        $limits = $gateway->refreshLimits($data['country'] ?? null, $data['currency'] ?? null);

        $this->credentials->forget($gateway->id());

        if ($limits === null) {
            return response()->json([
                'ok' => false,
                'error' => 'limits_unavailable',
                'message' => 'Tamara did not return limits for that market and payment type. '
                    . 'Nothing was changed — the limits already stored are untouched.',
                'tamara' => $this->state($gateway),
            ], 502);
        }

        return response()->json([
            'ok' => true,
            'message' => sprintf(
                'Tamara reports %s to %s for %s in %s.',
                $limits['min'],
                $limits['max'],
                $limits['payment_type'],
                $limits['country'],
            ),
            'limits' => $limits,
            'tamara' => $this->state($gateway),
        ]);
    }

    /**
     * Everything the screen shows, and nothing that is a credential.
     *
     * @return array<string, mixed>
     */
    private function state(TamaraGateway $gateway): array
    {
        $id = $gateway->id();

        return [
            'configured' => $gateway->configured(),
            'webhook_registered' => $this->credentials->get($id, 'webhook_id') !== '',
            // Not a secret: an opaque id that is unusable without the API token,
            // and the only way for the owner to tell one registration from
            // another if he ever has to ask Tamara's support about it.
            'webhook_id' => $this->credentials->get($id, 'webhook_id') ?: null,
            'webhook_events' => TamaraGateway::WEBHOOK_EVENTS,
            'min_limit' => $this->credentials->get($id, 'min_limit') ?: null,
            'max_limit' => $this->credentials->get($id, 'max_limit') ?: null,
        ];
    }

    /**
     * The gateway, or null when this build does not ship it.
     *
     * `instanceof`, not a cast. GatewayRegistry::supports() already degrades to
     * "that method is not offered" when a package ships without a gateway file —
     * three files went missing from a package on this project once — and a
     * controller that assumed the class was there would fatal instead of 404ing.
     */
    private function gateway(): ?TamaraGateway
    {
        $gateway = $this->registry->find('tamara');

        return $gateway instanceof TamaraGateway ? $gateway : null;
    }

    /** A sentence for the operator, per machine code. Never the provider's own text. */
    private function explain(string $code): string
    {
        return match ($code) {
            'not_configured' => 'Tamara has no API token stored yet. Paste the keys above and save first.',
            'no_webhook_secret' => 'This gateway has no webhook secret yet. Save the Tamara settings once — '
                . 'that generates it — and then register the webhook.',
            default => 'Tamara refused the request. Nothing was changed.',
        };
    }

    /** 422 for something the owner has to fix here; 502 for Tamara's end. */
    private function statusFor(string $code): int
    {
        return in_array($code, ['not_configured', 'no_webhook_secret'], true) ? 422 : 502;
    }
}
