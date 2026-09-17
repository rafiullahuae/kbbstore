<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\StripeConnect;
use App\Support\StripeConnectConsole;
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
            'client_id_test' => ['nullable', 'string', 'max:120'],
            // No `max` and no regex on the two secrets, for the same reason the
            // pasted key has none: a validator that rejects them renders its
            // own message, and a message about the value of a secret key is a
            // message that has seen one. Their shape is checked below, by code
            // that never quotes the input.
            'client_secret' => ['nullable', 'string'],
            'client_secret_test' => ['nullable', 'string'],
            // Sent by the "Clear" control. Without it there is no way to
            // remove a secret through this endpoint at all: an empty string
            // means "leave the stored one alone", which is what lets the screen
            // render secrets as blank boxes.
            'clear_client_secret' => ['nullable', 'boolean'],
            'clear_client_secret_test' => ['nullable', 'boolean'],
        ]);

        $ids = [
            'live' => trim((string) ($data['client_id'] ?? '')),
            'test' => trim((string) ($data['client_id_test'] ?? '')),
        ];

        foreach ($ids as $mode => $clientId) {
            if ($clientId !== '' && ! preg_match('/^ca_[A-Za-z0-9]+$/', $clientId)) {
                return response()->json([
                    'ok' => false,
                    'step' => $mode === 'live' ? 'client_id' : 'client_id_test',
                    'error' => 'A Stripe Connect application id starts with ca_ and has no spaces. '
                        . 'In the Stripe Dashboard it is on the Connect settings page, under the onboarding '
                        . 'options — there is one for test mode and a different one for live.',
                ], 422);
            }
        }

        $secrets = [
            'live' => trim((string) ($data['client_secret'] ?? '')),
            'test' => trim((string) ($data['client_secret_test'] ?? '')),
        ];

        foreach ($secrets as $mode => $secret) {
            if ($secret === '') {
                continue;
            }

            $step = $mode === 'live' ? 'client_secret' : 'client_secret_test';

            if (str_starts_with($secret, 'pk_') || str_starts_with($secret, 'ca_')) {
                return response()->json([
                    'ok' => false,
                    'step' => $step,
                    'error' => 'That is not a secret key. The platform secret key is the one on the same Stripe '
                        . 'page that starts sk_ — the publishable key and the application id will not authenticate '
                        . 'the connection.',
                ], 422);
            }

            if (! preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $secret)) {
                return response()->json([
                    'ok' => false,
                    'step' => $step,
                    'error' => 'That does not look like a Stripe secret key. It should start sk_test_, sk_live_, '
                        . 'rk_test_ or rk_live_ and have no spaces.',
                ], 422);
            }

            /*
             * The mode of the key must match the box it went in, and this is
             * the refusal that matters most on this panel.
             *
             * Stripe will not redeem a code issued by the development client id
             * with a live key. If the live secret were accepted into the test
             * box the button would open the sandbox screen, the owner would
             * grant access, and the exchange would fail afterwards with an
             * error about the code rather than about the key — sending him to
             * look in entirely the wrong place.
             */
            if (str_contains($secret, '_live_') !== ($mode === 'live')) {
                return response()->json([
                    'ok' => false,
                    'step' => $step,
                    'error' => $mode === 'live'
                        ? 'That is a TEST secret key and this is the Live box. Stripe will not complete a live '
                            . 'connection with a test key. Paste the sk_live_ key of the same account, or put this '
                            . 'one in the Test box.'
                        : 'That is a LIVE secret key and this is the Test box. Stripe will not complete a test '
                            . 'connection with a live key. Paste the sk_test_ key of the same account, or put this '
                            . 'one in the Live box.',
                ], 422);
            }
        }

        /*
         * null clears; '' is "leave alone" only for the keys named as secrets
         * in the third argument, so the two ids say null explicitly and the two
         * secrets rely on that argument. Clearing a secret is therefore an
         * explicit act with a flag of its own rather than a side effect of
         * saving the panel with an empty box.
         */
        $values = [
            'connect_client_id' => $ids['live'] === '' ? null : $ids['live'],
            'connect_client_id_test' => $ids['test'] === '' ? null : $ids['test'],
            StripeConnect::PLATFORM_KEYS['live']['secret'] => $secrets['live'],
            StripeConnect::PLATFORM_KEYS['test']['secret'] => $secrets['test'],
        ];

        if ($request->boolean('clear_client_secret')) {
            $values[StripeConnect::PLATFORM_KEYS['live']['secret']] = null;
        }

        if ($request->boolean('clear_client_secret_test')) {
            $values[StripeConnect::PLATFORM_KEYS['test']['secret']] = null;
        }

        $this->credentials->save(StripeConnect::GATEWAY, $values, [
            StripeConnect::PLATFORM_KEYS['live']['secret'],
            StripeConnect::PLATFORM_KEYS['test']['secret'],
        ]);

        $this->credentials->forget(StripeConnect::GATEWAY);

        // The status payload and nothing else, so the screen repaints from one
        // shape whichever endpoint it just called. It carries has_* booleans
        // for the secrets and never a value; see StripeConnect::status().
        $status = $this->connect->status();

        return response()->json([
            'ok' => true,
            'connect_client_id' => $status['connect_client_id'],
            'oauth_available' => $status['oauth_available'],
            'oauth_ready' => $status['oauth_ready'],
            'platform' => $status['platform'],
            'redirect_uri' => $status['redirect_uri'],
        ]);
    }

    /**
     * The setup guide for the platform application, and what is stored now.
     *
     * A read, on its own route, rather than a fifth key on status(): status()
     * is polled on every repaint of the payments screen and this is two
     * kilobytes of unchanging prose. It carries no credential — the guide is
     * static text and the platform block is the same has_* booleans status()
     * returns.
     */
    public function platform(): JsonResponse
    {
        $status = $this->connect->status();

        return response()->json([
            'ok' => true,
            'guide' => StripeConnectConsole::setupGuide(),
            'redirect_uri' => $status['redirect_uri'],
            'platform' => $status['platform'],
            'oauth_available' => $status['oauth_available'],
            'oauth_ready' => $status['oauth_ready'],
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

        /*
         * The state, plus the two things that have to be true of it later.
         *
         *   value      40 unguessable characters, compared with hash_equals.
         *   issued_at  so a state abandoned in a session cannot be paired with
         *              a code days afterwards; see STATE_TTL_SECONDS.
         *   mode       so a code obtained under the sandbox application cannot
         *              be redeemed as though the tab had said Live. Without it
         *              the mode is inferred from the key that comes back, which
         *              means the check can only ever agree with itself.
         *
         * One key, one array. Two session keys would be two things that can
         * fall out of step, and the one that mattered would be the one missing.
         */
        $request->session()->put(StripeConnect::STATE_SESSION_KEY, [
            'value' => $authorize['state'],
            'mode' => $authorize['mode'],
            'issued_at' => time(),
        ]);

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
        /*
         * PULLED FIRST, before anything else is read, so a state is spent by
         * being looked at. Every early return below has already consumed it.
         */
        $stored = $request->session()->pull(StripeConnect::STATE_SESSION_KEY);

        // A bare string is the shape this key held before the package that
        // added the timestamp. A session cookie outlives a deployment, so an
        // owner mid-flow when the package applied still completes rather than
        // being told to start again — with no TTL and no mode to check, which
        // is exactly what that older start() gave him.
        $expected = is_array($stored) ? (string) ($stored['value'] ?? '') : (string) ($stored ?? '');
        $issuedAt = is_array($stored) ? (int) ($stored['issued_at'] ?? 0) : 0;
        $stateMode = is_array($stored) && isset($stored['mode']) ? (string) $stored['mode'] : null;

        $given = (string) $request->query('state', '');

        /*
         * hash_equals on two non-empty strings, and the emptiness is checked
         * separately because hash_equals('', '') is TRUE — a callback carrying
         * no state at all, arriving in a session that has none, would otherwise
         * pass this line. That is the whole attack: an unauthorised callback
         * has no state to send, and a session it did not start has none stored.
         */
        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return $this->closingPage(
                false,
                'This Stripe window could not be matched to the request that opened it, so nothing was changed. '
                    . 'Close it and press Connect again.',
            );
        }

        if ($issuedAt > 0 && (time() - $issuedAt) > StripeConnect::STATE_TTL_SECONDS) {
            return $this->closingPage(
                false,
                'This Stripe window was open too long and the connection request has expired, so nothing was '
                    . 'changed. Close it and press Connect again — it takes a few seconds the second time.',
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

        // The mode the popup was OPENED in, not one taken from the callback's
        // own query string. Everything in that query string came back through
        // the owner's browser and is only as trustworthy as the state that
        // arrived with it; the mode is something this server already knew.
        $result = $this->connect->exchangeCode($code, $stateMode);

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
