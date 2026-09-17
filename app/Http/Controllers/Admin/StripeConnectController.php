<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\StripeConnect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Store -> Ecommerce -> Payments -> Stripe -> Connect / Disconnect.
 *
 * Everything dangerous lives in App\Services\Payments\StripeConnect; this is
 * the guarded doorway to it. Five endpoints, all inside the admin-api group and
 * all mapped to `payments.manage` in AdminCapabilities:
 *
 *   GET  /admin-api/payments/stripe/connect/status        what is connected now
 *   POST /admin-api/payments/stripe/connect               one-paste connect
 *   POST /admin-api/payments/stripe/connect/application   store the ca_... id
 *   GET  /admin-api/payments/stripe/connect/start         open the OAuth popup
 *   GET  /admin-api/payments/stripe/connect/callback      Stripe comes back here
 *   POST /admin-api/payments/stripe/disconnect            reset
 *
 * ---------------------------------------------------------------------------
 * WHAT NEVER LEAVES THIS CONTROLLER
 *
 * A secret key arrives in the body of the connect POST and is passed straight
 * to the service. It is never echoed back, never put in a validation message,
 * never logged, and never stored anywhere but the encrypted config blob. The
 * responses here follow the same rule the payments screen already follows: a
 * caller learns THAT a credential is stored, never what it is — and not even a
 * masked form of it, because a mask still discloses the length.
 *
 * ---------------------------------------------------------------------------
 * THE OAUTH STATE
 *
 * `start` mints 40 random characters, puts them in the ADMIN SESSION, and sends
 * the owner to Stripe with them. `callback` compares what comes back with
 * hash_equals and FORGETS the stored value before doing anything else, so the
 * value is good for exactly one callback. A callback with a missing, stale or
 * wrong state changes nothing at all: no token exchange, no write, no endpoint
 * created. It is the check that stops somebody else's link, opened in the
 * owner's browser while he is signed in, rewiring this shop's payments to an
 * account he does not own.
 */
class StripeConnectController extends Controller
{
    public function __construct(
        private StripeConnect $connect,
        private GatewayCredentials $credentials,
    ) {}

    /* ------------------------------------------------------------- reading */

    public function status(): JsonResponse
    {
        return response()->json($this->connect->status());
    }

    /* ------------------------------------------------------- path B: paste */

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // No `max` that could truncate, no regex in the validator: a
            // rejection here would render the rule's message, and a message
            // about the value of a secret key is a message that has seen one.
            // The shape check lives in the service and never quotes the input.
            'secret_key' => ['required', 'string'],
            'mode' => ['nullable', 'string', 'in:test,live'],
        ]);

        $result = $this->connect->connectWithKey($data['secret_key'], $data['mode'] ?? null);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Store (or clear) the Connect platform application id.
     *
     * Deliberately NOT part of StripeGateway::configSchema(). Adding a fifth
     * field there would make GatewayPreflight report "Connect application id"
     * among the credentials still missing on every shop that will never
     * register one — turning a correctly configured gateway into one that reads
     * as half-finished.
     *
     * `ca_...` is not a secret. It is published in the authorize URL every time
     * the popup opens, so it is stored beside the credentials for convenience
     * rather than for secrecy, and it is the one value here that IS returned to
     * the screen.
     */
    public function application(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'string', 'max:120'],
        ]);

        $clientId = trim((string) ($data['client_id'] ?? ''));

        if ($clientId !== '' && ! preg_match('/^ca_[A-Za-z0-9]+$/', $clientId)) {
            return response()->json([
                'ok' => false,
                'step' => 'client_id',
                'error' => 'A Stripe Connect application id starts with ca_ and has no spaces. '
                    . 'You will find it at Stripe Dashboard -> Settings -> Connect -> Platform settings.',
            ], 422);
        }

        // null clears it; GatewayCredentials treats '' as "leave alone" only
        // for declared secret keys, and this is not one, so be explicit.
        $this->credentials->save(StripeConnect::GATEWAY, [
            'connect_client_id' => $clientId === '' ? null : $clientId,
        ]);

        $this->credentials->forget(StripeConnect::GATEWAY);

        return response()->json([
            'ok' => true,
            'connect_client_id' => $clientId,
            'oauth_available' => $clientId !== '',
            'redirect_uri' => $this->connect->redirectUri(),
        ]);
    }

    /* -------------------------------------------------------- path A: OAuth */

    /**
     * The popup opens straight onto this URL and this URL redirects to Stripe.
     *
     * A redirect rather than a JSON payload the page then navigates to,
     * because the popup has to be opened by the click itself or the browser
     * blocks it — there is no room for a round trip in between.
     */
    public function start(Request $request): RedirectResponse|Response
    {
        $mode = $request->query('mode');
        $mode = $mode === 'live' ? 'live' : 'test';

        $authorize = $this->connect->authorizeUrl($mode);

        if (! ($authorize['ok'] ?? false)) {
            return $this->closingPage(false, (string) ($authorize['error'] ?? 'Stripe could not be opened.'));
        }

        $request->session()->put(StripeConnect::STATE_SESSION_KEY, $authorize['state']);

        return redirect()->away($authorize['url']);
    }

    /**
     * Stripe sends the owner back here.
     *
     * Order matters: the state is consumed FIRST, before the code is looked at
     * and before anything is written, so a replayed callback cannot get a
     * second exchange out of the same state.
     */
    public function callback(Request $request): Response
    {
        $expected = (string) $request->session()->pull(StripeConnect::STATE_SESSION_KEY, '');
        $given = (string) $request->query('state', '');

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return $this->closingPage(
                false,
                'This Stripe window could not be matched to the request that opened it, so nothing was changed. '
                    . 'Close it and press Connect again.',
            );
        }

        // Stripe reports a refusal in the query string rather than by failing
        // the redirect: the owner pressed Cancel, or the application is not
        // approved for the scope it asked for.
        $error = trim((string) ($request->query('error_description') ?? $request->query('error') ?? ''));

        if ($error !== '') {
            return $this->closingPage(false, $error);
        }

        $code = trim((string) $request->query('code', ''));

        if ($code === '') {
            return $this->closingPage(false, 'Stripe returned no authorisation code, so nothing was changed.');
        }

        $result = $this->connect->exchangeCode($code);

        return $this->closingPage(
            (bool) ($result['ok'] ?? false),
            (string) ($result['error'] ?? ''),
            $result,
        );
    }

    /* -------------------------------------------------------- disconnecting */

    /**
     * Reset.
     *
     * `confirm` is required and must be the literal string "disconnect". The
     * screen's own dialog is the real confirmation; this is the second lock,
     * so that a stray POST — a retried request, a bookmarked devtools replay —
     * cannot take a live shop off Stripe.
     *
     * Always 200 on a well-formed request, including when there was nothing to
     * disconnect. "Already disconnected" is the state the caller asked for, and
     * a 4xx would make the screen show a failure for a button that did its job.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'confirm' => ['required', 'string'],
        ]);

        if ($data['confirm'] !== 'disconnect') {
            return response()->json([
                'ok' => false,
                'step' => 'confirm',
                'error' => 'Disconnecting has to be confirmed.',
            ], 422);
        }

        return response()->json($this->connect->disconnect());
    }

    /* ----------------------------------------------------------------- view */

    /**
     * The page the popup ends on.
     *
     * It reports the outcome to the window that opened it and closes itself. A
     * popup that stayed open showing a bare JSON body would be indistinguishable
     * from a failure, and the owner would be left deciding whether to press
     * Connect again — which, half-connected, is the one thing he must not guess
     * at.
     *
     * The payload handed to the opener carries the account report and the
     * warnings. It carries no credential: `store()` in the service returns none,
     * and this is a page in the browser.
     *
     * @param  array<string, mixed>  $result
     */
    private function closingPage(bool $ok, string $message, array $result = []): Response
    {
        return response()
            ->view('admin.stripe-connected', [
                'ok' => $ok,
                'message' => $message,
                'payload' => [
                    'ok' => $ok,
                    'error' => $message !== '' ? $message : null,
                    'account' => $result['account'] ?? null,
                    'warnings' => $result['warnings'] ?? [],
                    'webhook_action' => $result['webhook_action'] ?? null,
                    'mode' => $result['mode'] ?? null,
                ],
            ])
            // Never cached, never stored. The admin-api group's NoStoreAdminApi
            // covers the JSON endpoints; this one renders HTML and says so for
            // itself rather than relying on that.
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
