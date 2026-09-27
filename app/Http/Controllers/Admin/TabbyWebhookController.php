<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\Gateways\TabbyGateway;
use Illuminate\Http\JsonResponse;

/**
 * Store → Payments → Tabby · Webhook registration.
 *
 * WHY THIS SCREEN EXISTS AT ALL, which is the whole point of the lane.
 *
 * Stripe and Tamara both take a webhook URL from a field in their dashboard, and
 * the payments screen tells the owner to paste one there. Tabby does not have
 * that field. Its callback address is REGISTERED THROUGH THE API — one
 * registration per merchant country, `POST /api/v1/webhooks` with `{url,
 * is_test}` — and until that call is made Tabby never contacts this shop about
 * anything. A Tabby gateway with perfect keys, a perfect webhook handler and no
 * registration authorises money and then goes silent: the order stays `pending`,
 * `paid_at` stays null, and the authorisation lapses about a month later with
 * the goods shipped and nobody paid.
 *
 * So there are two endpoints and no more:
 *
 *   GET  …/payments/tabby/webhooks   what Tabby has been told, per country,
 *                                    changing nothing. It is a READ, but it is
 *                                    five outbound calls to Tabby (one per
 *                                    merchant country) — so it is a button the
 *                                    owner presses, not something to poll.
 *   POST …/payments/tabby/webhooks   make Tabby's registration agree with this
 *                                    shop: register what is missing, correct a
 *                                    sandbox/live mismatch, and delete a hook
 *                                    pointing at an old secret of ours.
 *
 * SECURITY
 *
 *   - `payments.manage`, which is owner-only, and the same capability as the
 *     screen that stores the keys. This endpoint reads the credentials, and the
 *     POST registers a public address inside the merchant's Tabby account. It is
 *     not a looser act than editing the keys and it does not get a looser
 *     capability.
 *   - THE WEBHOOK URL IS A SECRET AND IS NOT RETURNED. Its random tail is what
 *     makes the endpoint unguessable, so a response here reports whether a hook
 *     points at this shop and never the address it points at. The payments
 *     screen already has the URL from /admin-api/payments and is the one place
 *     that shows it; echoing it a second time from a second endpoint would be a
 *     second thing to get wrong. What comes back instead is a per-country state
 *     and a sentence.
 *   - Nothing from Tabby's response reaches the browser except a state word this
 *     file chose and an error CODE that RemoteGateway::errorCode() has already
 *     reduced to identifier characters. A provider is free to put a merchant's
 *     name in an error message; none of that is rendered.
 *   - Fails closed on a gateway this build does not ship, on a gateway that is
 *     not a TabbyGateway, and on missing credentials — each with its own answer
 *     rather than one generic 422, because the fix for each is different.
 */
class TabbyWebhookController extends Controller
{
    public function __construct(
        private GatewayRegistry $registry,
        private GatewayCredentials $credentials,
    ) {}

    public function show(): JsonResponse
    {
        $gateway = $this->tabby();

        if ($gateway === null) {
            return $this->notTabby();
        }

        return response()->json($this->safe($gateway->webhookStatus()));
    }

    public function sync(): JsonResponse
    {
        $gateway = $this->tabby();

        if ($gateway === null) {
            return $this->notTabby();
        }

        $result = $this->safe($gateway->syncWebhooks());

        // 200 even when Tabby refused. The call ran, the report is the answer,
        // and a 502 here would make the console show a transport failure over a
        // response that says exactly which country failed and why.
        return response()->json($result);
    }

    private function tabby(): ?TabbyGateway
    {
        /*
         * forget() first, for the reason PaymentsApiController documents at
         * length: this controller instance and the gateway's own
         * GatewayCredentials both outlive one request wherever the process does,
         * so a sync run straight after a save would otherwise read the keys as
         * they were before it.
         */
        $this->credentials->forget();

        $gateway = app(GatewayRegistry::class)->find('tabby');

        return $gateway instanceof TabbyGateway ? $gateway : null;
    }

    private function notTabby(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => 'unsupported',
            'message' => 'This build does not ship the Tabby gateway.',
        ], 404);
    }

    /**
     * Strip anything that is not this file's own vocabulary.
     *
     * An allowlist and not a blocklist, per CLAUDE.md rule 5: the gateway is
     * trusted code but its report is built partly from a third party's response,
     * and the way a URL or a key ends up on a screen is that somebody adds a
     * field to a payload and nothing between it and the browser is looking.
     * Every key below is named here; `url` deliberately is not.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function safe(array $report): array
    {
        $out = [
            'ok' => (bool) ($report['ok'] ?? false),
            // Whether an address exists, never what it is.
            'webhook_url_ready' => isset($report['url']) && is_string($report['url']) && $report['url'] !== '',
            'is_test' => (bool) ($report['is_test'] ?? false),
            'keys_disagree' => (bool) ($report['keys_disagree'] ?? false),
        ];

        foreach (['error', 'message'] as $key) {
            if (isset($report[$key]) && is_string($report[$key])) {
                $out[$key] = $report[$key];
            }
        }

        if (array_key_exists('registered_anywhere', $report)) {
            $out['registered_anywhere'] = (bool) $report['registered_anywhere'];
        }

        $out['countries'] = [];

        foreach ((array) ($report['countries'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $out['countries'][] = [
                'country' => substr(preg_replace('/[^A-Z]/', '', strtoupper((string) ($row['country'] ?? ''))) ?: '', 0, 2),
                'state' => substr(preg_replace('/[^a-z_]/', '', (string) ($row['state'] ?? '')) ?: 'unknown', 0, 32),
                'stale' => max(0, (int) ($row['stale'] ?? 0)),
                'pruned' => max(0, (int) ($row['pruned'] ?? 0)),
                // Written by this application, in every branch of
                // TabbyGateway::syncCountry(), which is why it is safe to print.
                'message' => is_string($row['message'] ?? null) ? $row['message'] : '',
                // Tabby's `errorType`, already reduced to identifier characters
                // and capped by RemoteGateway::errorCode().
                'error' => is_string($row['error'] ?? null) ? $row['error'] : null,
            ];
        }

        return $out;
    }
}
