<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Support\Money;
use App\Support\Url;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Connecting Stripe without pasting four values into four boxes.
 *
 * =============================================================================
 * WHICH MECHANISM, AND WHY
 * =============================================================================
 *
 * The owner asked for what WooCommerce's Stripe extension gives him: press
 * Connect, a Stripe window opens, press one button there, come back configured.
 * There are two ways to build that and only one of them works on this shop as
 * it stands today.
 *
 *   A. STRIPE CONNECT OAUTH. The shop registers itself as a Stripe *platform*,
 *      obtains a `client_id` (ca_...), and sends the owner to
 *      connect.stripe.com/oauth/authorize. He approves, Stripe calls our
 *      redirect back with a code, we exchange it for the connected account's
 *      id and, for a Standard account, an access token that IS a secret key.
 *
 *      This is genuinely "click, popup, done" — but only AFTER he has
 *      registered a platform application, accepted Stripe's platform terms and
 *      whitelisted our redirect URL. WooCommerce hides that step because
 *      Automattic runs the platform application on every Woo shop's behalf.
 *      Nobody runs one on this shop's behalf, and standing one up turns a
 *      single-merchant storefront into its own payments platform for no gain.
 *
 *      It also does not finish the job. An OAuth grant carries no webhook
 *      signing secret, so even under Connect the endpoint still has to be
 *      created through the API — every line of ensureWebhookEndpoint() below is
 *      needed either way.
 *
 *   B. ONE-PASTE AUTO-CONFIGURATION. The owner pastes his secret key once. We
 *      authenticate it against Stripe, read the account behind it, create the
 *      webhook endpoint programmatically (which is the only call that ever
 *      returns a signing secret), and store the lot.
 *
 *      Prerequisite: an ordinary Stripe account, which he already has. Four
 *      pastes and a dashboard visit become one paste.
 *
 * B is therefore the default and the path that works today. A is built and
 * kept, dormant, behind `connect_client_id`: the moment a platform application
 * exists the OAuth button lights up, and because both paths end in the same
 * store() call and the same disconnect(), there is one storage shape and one
 * reset — not two half-features that disagree.
 *
 * =============================================================================
 * WHAT IS STORED
 * =============================================================================
 *
 * All of it in `payment_providers.config` for `stripe`, which the model casts
 * `encrypted:array`. Nothing here goes near `settings` — /api/settings is
 * public. Nothing here is returned to the browser, logged, or put in an
 * exception message; see the redaction note on stripeCall().
 *
 *   secret_key, publishable_key, webhook_signing_secret   credentials
 *   webhook_secret            the unguessable tail of OUR webhook URL
 *   connect_account_id        acct_... — who we are connected to
 *   connect_link              'key' (path B) or 'oauth' (path A)
 *   connect_client_id         ca_... — the platform app, if one is registered
 *   connected_at              ISO 8601
 *   account_name, account_country, account_currency, charges_enabled, livemode
 *                             the report card, so the screen can say WHAT it
 *                             connected to rather than just "connected"
 *   webhook_endpoint_id       we_... — the endpoint we are responsible for
 *   webhook_endpoint_managed  did WE create it? only then may we delete it
 *
 * =============================================================================
 * WHAT IS NOT PROVEN HERE
 * =============================================================================
 *
 * This repository has no Stripe account and no keys. Every test against this
 * class fakes the HTTP client and asserts the request we would have sent. That
 * proves the wire format, the ordering, the rollback and the redaction. It
 * cannot prove that Stripe accepts those requests. The first real connection
 * is the first real evidence, and the screen is built to report exactly what
 * Stripe says rather than to claim success on its behalf.
 */
final class StripeConnect
{
    public const GATEWAY = 'stripe';

    /**
     * Does this gateway have a one-paste connect flow?
     *
     * Asked by PaymentsApiController so the console never has to name a
     * gateway to decide where the Connect panel goes. PaymentsGatewayTabsTest
     * forbids the screen naming one, and it is right to: a screen carrying its
     * own gateway list stops following the registry the moment one is added or
     * renamed. When a second provider gains a connect flow, this is the one
     * line that changes.
     */
    public static function supports(string $gatewayId): bool
    {
        return $gatewayId === self::GATEWAY;
    }

    private const API = 'https://api.stripe.com';

    private const OAUTH_AUTHORIZE = 'https://connect.stripe.com/oauth/authorize';

    private const TIMEOUT = 20;

    /** The admin session key holding the single-use OAuth state. */
    public const STATE_SESSION_KEY = 'kbb.stripe.connect.state';

    /**
     * The events this shop acts on.
     *
     * Read off StripeGateway::handleWebhook() rather than copied from a
     * changelog: the two it confirms payment on, and the two it fails an order
     * on. Subscribing to more would mean deliveries this shop ignores; to
     * fewer would mean a payment it never hears about.
     */
    public const EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.expired',
        'payment_intent.payment_failed',
    ];

    /**
     * Statuses an order can be in and still be waiting on Stripe.
     *
     * Deliberately the complement of "resolved": anything cancelled, refunded,
     * failed or completed has had its answer, whether or not it was the one the
     * shopper wanted.
     */
    private const SETTLED_STATUSES = ['cancelled', 'refunded', 'failed', 'completed'];

    public function __construct(private GatewayCredentials $credentials) {}

    /* ====================================================================== */
    /* Reading the current state                                              */
    /* ====================================================================== */

    /**
     * What the screen shows: are we connected, to what, and is anything in
     * flight that a disconnect would strand.
     *
     * Reaches Stripe not at all. Safe to poll, safe before a confirm dialog.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $this->credentials->forget(self::GATEWAY);

        $row = PaymentProvider::find(self::GATEWAY);
        $connected = $this->credentials->get(self::GATEWAY, 'secret_key') !== '';
        $clientId = $this->credentials->get(self::GATEWAY, 'connect_client_id');

        return [
            'connected' => $connected,
            'link' => $connected ? ($this->credentials->get(self::GATEWAY, 'connect_link') ?: 'key') : null,
            'mode' => $row?->mode ?: 'test',
            'enabled' => (bool) ($row?->enabled ?? false),
            'account' => $connected ? $this->storedAccount() : null,
            'webhook_url' => $this->webhookUrl(),
            'webhook_endpoint_id' => $connected ? ($this->credentials->get(self::GATEWAY, 'webhook_endpoint_id') ?: null) : null,
            'webhook_events' => self::EVENTS,
            // Empty string when no platform application is registered, which is
            // what the screen reads to decide whether to offer the OAuth button
            // at all. Not a secret: it travels in the authorize URL.
            'connect_client_id' => $clientId,
            'oauth_available' => $clientId !== '',
            'redirect_uri' => $this->redirectUri(),
            'in_flight' => $this->inFlight(),
            'shop_currency' => Money::currency(),
        ];
    }

    /** @return array<string, mixed> */
    private function storedAccount(): array
    {
        $currency = strtoupper($this->credentials->get(self::GATEWAY, 'account_currency'));

        return [
            'id' => $this->credentials->get(self::GATEWAY, 'connect_account_id') ?: null,
            'name' => $this->credentials->get(self::GATEWAY, 'account_name') ?: null,
            'country' => $this->credentials->get(self::GATEWAY, 'account_country') ?: null,
            'currency' => $currency !== '' ? $currency : null,
            'charges_enabled' => $this->credentials->get(self::GATEWAY, 'charges_enabled') === '1',
            'livemode' => $this->credentials->get(self::GATEWAY, 'livemode') === '1',
            'connected_at' => $this->credentials->get(self::GATEWAY, 'connected_at') ?: null,
        ];
    }

    /**
     * Orders that have been sent to Stripe and have had no answer.
     *
     * `payment_method` is written when the order is placed (Store\
     * CheckoutController), `transaction_id` when the Checkout session is
     * created, and `paid_at` only when a verified webhook confirms. An order
     * with the first two and not the third is a shopper who may be on Stripe's
     * payment page right now.
     *
     * Bounded to three days because a Checkout session expires after 24 hours
     * and Stripe's retry schedule runs out well inside that; older rows are
     * abandoned carts, not pending money, and counting them would make the
     * warning cry wolf on every shop with a history.
     *
     * @return array<string, mixed>
     */
    public function inFlight(): array
    {
        $since = now()->subDays(3);

        $query = Order::query()
            ->where('payment_method', self::GATEWAY)
            ->whereNull('paid_at')
            ->whereNotNull('transaction_id')
            ->whereNotIn('status', self::SETTLED_STATUSES)
            ->where('created_at', '>=', $since);

        $count = (clone $query)->count();

        return [
            'count' => $count,
            'orders' => $count === 0 ? [] : (clone $query)
                // `id` after `created_at`, and not for tidiness: several orders
                // placed in the same second tie on the timestamp, and a sliced
                // query that ties returns a different ten rows each time it is
                // asked. The list on the confirm dialog would then change under
                // the owner between reading it and pressing the button.
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get(['order_number', 'status', 'total', 'currency', 'created_at'])
                ->map(fn (Order $o) => [
                    'order_number' => (string) $o->order_number,
                    'status' => (string) $o->status,
                    'total' => (int) $o->total,
                    'currency' => strtoupper((string) ($o->currency ?: 'AED')),
                    'created_at' => optional($o->created_at)->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * The webhook URL for this shop, or null before a URL secret exists.
     *
     * Same construction as PaymentsApiController::webhookUrl(). It is the
     * identity of this shop's endpoint inside the Stripe account, which is why
     * adoption and deletion both compare against it exactly.
     */
    public function webhookUrl(): ?string
    {
        $secret = $this->credentials->get(self::GATEWAY, 'webhook_secret');

        if ($secret === '') {
            return null;
        }

        return url(Url::redirect('/api/payments/webhook/' . self::GATEWAY . '/')) . $secret;
    }

    /* ====================================================================== */
    /* Path B — one paste                                                     */
    /* ====================================================================== */

    /**
     * Connect using a secret key the owner pasted.
     *
     * The order is the point. Nothing is written until every remote step has
     * succeeded, and the one remote step that CREATES something is undone if
     * the write then fails.
     *
     *   1. shape       is this even a secret key, and whose mode is it?
     *   2. mode        does the key's mode agree with the tab's switch?
     *   3. account     GET /v1/account — does Stripe accept the key, and what
     *                  is behind it?
     *   4. url secret  ensure this shop has a webhook URL to register
     *   5. endpoint    find/replace/create, capturing the signing secret
     *   6. store       one write, last
     *
     * A failure at any step returns ['ok' => false, 'step' => ..., 'error' =>
     * Stripe's own words] and leaves the stored configuration exactly as it was.
     *
     * @param  string  $secretKey  never logged, never echoed, never stored
     *                             until step 6
     * @return array<string, mixed>
     */
    public function connectWithKey(string $secretKey, ?string $tabMode = null): array
    {
        $key = trim($secretKey);

        /* ---- 1. shape ---------------------------------------------------- */

        if ($key === '') {
            return $this->fail('key', 'Paste your Stripe secret key first.');
        }

        if (str_starts_with($key, 'pk_')) {
            return $this->fail(
                'key',
                'That is the publishable key. The secret key is the other one on the same Stripe page and starts with sk_.',
            );
        }

        // sk_ is the ordinary secret key; rk_ is a restricted key, which works
        // here provided it carries write access to webhook endpoints.
        if (! preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $key)) {
            return $this->fail(
                'key',
                'That does not look like a Stripe secret key. It should start with sk_test_, sk_live_, rk_test_ or rk_live_ and have no spaces.',
            );
        }

        $keyLive = str_contains($key, '_live_');

        /* ---- 2. mode ----------------------------------------------------- */

        // The `mode` column is a LABEL on this shop's row. Stripe decides test
        // or live from the key prefix alone and will never consult it, so the
        // two can disagree in silence — a live key under a tab that says
        // Sandbox takes real money from real cards on the first order.
        $tabMode = $tabMode === 'live' || $tabMode === 'test'
            ? $tabMode
            : (PaymentProvider::find(self::GATEWAY)?->mode === 'live' ? 'live' : 'test');

        if ($keyLive !== ($tabMode === 'live')) {
            return $this->fail('mode', $keyLive
                ? 'This is a LIVE key, but this gateway is set to Sandbox / test. Real cards would be charged. '
                    . 'Switch Mode to Live and press Connect again, or paste the sk_test_ key instead.'
                : 'This is a TEST key, but this gateway is set to Live. No real payment could ever complete. '
                    . 'Switch Mode to Sandbox / test, or paste the sk_live_ key instead.');
        }

        /* ---- 3. account -------------------------------------------------- */

        $account = $this->stripeCall('GET', '/v1/account', [], $key);

        if (! $account['ok']) {
            return $this->fail('account', $account['error']
                ?? 'Stripe would not accept that key. Copy it again from Developers -> API keys.');
        }

        $body = $account['body'] ?? [];
        $accountId = (string) ($body['id'] ?? '');

        if ($accountId === '') {
            return $this->fail('account', 'Stripe accepted the key but did not say which account it belongs to.');
        }

        /* ---- 4. our own URL secret --------------------------------------- */

        $webhookUrl = $this->ensureWebhookUrl();

        if ($webhookUrl === null) {
            return $this->fail('webhook', 'This shop could not generate its own webhook address. Save the Stripe tab once and try again.');
        }

        /* ---- 5. the endpoint --------------------------------------------- */

        $endpoint = $this->ensureWebhookEndpoint($key, $webhookUrl);

        if (! $endpoint['ok']) {
            return $this->fail('webhook', $endpoint['error'] ?? 'Stripe refused to register this shop for payment notifications.', [
                'replaced_endpoint' => $endpoint['replaced'] ?? false,
            ]);
        }

        /* ---- 6. store, last ---------------------------------------------- */

        return $this->store(
            key: $key,
            publishableKey: null,
            account: $body,
            endpoint: $endpoint,
            link: 'key',
            mode: $tabMode,
        );
    }

    /* ====================================================================== */
    /* Path A — Connect OAuth, dormant until a client_id exists               */
    /* ====================================================================== */

    /**
     * The Stripe-hosted URL to open in the popup, plus the state to remember.
     *
     * The state is 40 random characters, held in the ADMIN SESSION and used
     * once. It is what stops a callback that this admin never asked for from
     * rewriting the shop's payment credentials: an attacker who can make the
     * owner's browser fetch our callback still cannot guess the value, and
     * hash_equals compares it without leaking where it diverged.
     *
     * @return array<string, mixed>
     */
    public function authorizeUrl(string $tabMode = 'test'): array
    {
        $clientId = $this->credentials->get(self::GATEWAY, 'connect_client_id');

        if ($clientId === '') {
            return $this->fail(
                'client_id',
                'No Stripe Connect application is registered for this shop, so the one-click flow is not available. '
                    . 'Paste your secret key instead, or register a Connect application at '
                    . 'Stripe Dashboard -> Settings -> Connect and put its client id (ca_...) in the Connect application id box.',
            );
        }

        $state = Str::random(40);

        return [
            'ok' => true,
            'state' => $state,
            'url' => self::OAUTH_AUTHORIZE . '?' . http_build_query([
                'response_type' => 'code',
                'client_id' => $clientId,
                'scope' => 'read_write',
                'redirect_uri' => $this->redirectUri(),
                'state' => $state,
                // Stripe reads this to decide which screen to land the owner
                // on; the key that comes back still decides test or live, and
                // exchangeCode() re-checks it against the tab.
                'stripe_landing' => 'login',
            ]),
            'redirect_uri' => $this->redirectUri(),
            'mode' => $tabMode === 'live' ? 'live' : 'test',
        ];
    }

    /** The URL that must be whitelisted in the Connect application's settings. */
    public function redirectUri(): string
    {
        return url(Url::redirect('/admin-api/payments/stripe/connect/callback'));
    }

    /**
     * Exchange the authorisation code for the connected account's credentials.
     *
     * The caller has already compared the state with hash_equals and consumed
     * it; this method is never reached otherwise.
     *
     * For a Standard connected account Stripe returns `access_token`, which is
     * that account's own secret key, and `stripe_publishable_key`. The webhook
     * signing secret is NOT returned — hence the same ensureWebhookEndpoint()
     * call as path B.
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code, ?string $tabMode = null): array
    {
        $clientId = $this->credentials->get(self::GATEWAY, 'connect_client_id');

        if ($clientId === '') {
            return $this->fail('client_id', 'No Stripe Connect application is registered for this shop.');
        }

        // The platform's own secret key authenticates the token exchange. On a
        // single-merchant install the platform and the merchant are the same
        // Stripe account, so this is the key already stored — and when there is
        // none stored yet there is nothing to authenticate with. Say so rather
        // than sending an unauthenticated request and relaying Stripe's less
        // specific refusal.
        $platformKey = $this->credentials->get(self::GATEWAY, 'secret_key');

        if ($platformKey === '') {
            return $this->fail(
                'platform_key',
                'The one-click flow needs this shop\'s own Stripe secret key stored first, because Stripe authenticates '
                    . 'the final step with it. Connect once by pasting the key, and the one-click flow works from then on.',
            );
        }

        $token = $this->stripeCall('POST', '/v1/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
        ], $platformKey);

        if (! $token['ok']) {
            return $this->fail('oauth', $token['error'] ?? 'Stripe would not complete the connection.');
        }

        $body = $token['body'] ?? [];
        $key = trim((string) ($body['access_token'] ?? ''));
        $accountId = trim((string) ($body['stripe_user_id'] ?? ''));

        if ($key === '' || $accountId === '') {
            return $this->fail('oauth', 'Stripe completed the connection but returned no usable key for the account.');
        }

        $keyLive = str_contains($key, '_live_');
        $tabMode = $tabMode === 'live' || $tabMode === 'test'
            ? $tabMode
            : ($keyLive ? 'live' : 'test');

        // The same disagreement path B refuses, refused here too: an owner who
        // authorised his live account while the tab says Sandbox has made the
        // identical mistake by a different route.
        if ($keyLive !== ($tabMode === 'live')) {
            return $this->fail('mode', $keyLive
                ? 'You authorised a LIVE Stripe account while this gateway is set to Sandbox / test. Switch Mode to Live and connect again.'
                : 'You authorised a TEST Stripe account while this gateway is set to Live. Switch Mode to Sandbox / test and connect again.');
        }

        $account = $this->stripeCall('GET', '/v1/account', [], $key);

        if (! $account['ok']) {
            return $this->fail('account', $account['error'] ?? 'Stripe would not describe the account that was just connected.');
        }

        $webhookUrl = $this->ensureWebhookUrl();

        if ($webhookUrl === null) {
            return $this->fail('webhook', 'This shop could not generate its own webhook address.');
        }

        $endpoint = $this->ensureWebhookEndpoint($key, $webhookUrl);

        if (! $endpoint['ok']) {
            return $this->fail('webhook', $endpoint['error'] ?? 'Stripe refused to register this shop for payment notifications.');
        }

        return $this->store(
            key: $key,
            publishableKey: trim((string) ($body['stripe_publishable_key'] ?? '')) ?: null,
            account: ($account['body'] ?? []) + ['id' => $accountId],
            endpoint: $endpoint,
            link: 'oauth',
            mode: $tabMode,
        );
    }

    /* ====================================================================== */
    /* The webhook endpoint                                                   */
    /* ====================================================================== */

    /**
     * This shop's webhook URL, generating the random tail if it has none.
     *
     * Same generation PaymentsApiController::save() performs on first save, so
     * connecting before ever saving the tab works and produces the same shape.
     */
    private function ensureWebhookUrl(): ?string
    {
        if ($this->credentials->get(self::GATEWAY, 'webhook_secret') === '') {
            $this->credentials->save(self::GATEWAY, [
                'webhook_secret' => 'whsec-' . self::GATEWAY . '-' . Str::random(32),
            ]);
        }

        return $this->webhookUrl();
    }

    /**
     * Make sure exactly one Stripe webhook endpoint points at this shop.
     *
     * The rule the owner cares about: never create a second endpoint for the
     * same URL, because every delivery would then arrive twice.
     *
     * The rule that needed thinking about: Stripe returns an endpoint's signing
     * secret ONLY in the response that creates it. It is not readable
     * afterwards. So an endpoint that already points at our URL splits two ways:
     *
     *   - it is the one we made last time AND we still hold its signing secret
     *     -> reuse it. Add any missing events, change nothing else, and keep
     *        the secret we have.
     *   - we hold no usable secret for it -> it cannot verify a single delivery
     *     in its present state, so it is replaced: deleted, then recreated, and
     *     the report says so.
     *
     * Replacing is safe precisely because of WHICH endpoint it is. Our webhook
     * URL ends in a 32-character random tail that exists nowhere but this
     * install, so an endpoint carrying that URL can only have been created to
     * serve this shop. Endpoints the owner made for anything else have
     * different URLs, are never listed as a match, and are never touched.
     *
     * Delete-then-create rather than create-then-delete: Stripe rejects a
     * second endpoint on a URL that already has one, and the endpoint being
     * replaced was, by definition, one no delivery could be verified against.
     * There is no working state to lose.
     *
     * @return array<string, mixed>
     */
    private function ensureWebhookEndpoint(string $key, string $webhookUrl): array
    {
        $list = $this->stripeCall('GET', '/v1/webhook_endpoints?limit=100', [], $key);

        if (! $list['ok']) {
            return $this->fail('webhook', $list['error']
                ?? 'Stripe would not list this account\'s webhook endpoints, so this shop cannot be registered safely.');
        }

        $existing = null;

        foreach (($list['body']['data'] ?? []) as $candidate) {
            if (is_array($candidate) && (string) ($candidate['url'] ?? '') === $webhookUrl) {
                $existing = $candidate;
                break;
            }
        }

        $storedId = $this->credentials->get(self::GATEWAY, 'webhook_endpoint_id');
        $storedSigning = $this->credentials->get(self::GATEWAY, 'webhook_signing_secret');

        /* ---- reuse ------------------------------------------------------- */

        if ($existing !== null
            && $storedSigning !== ''
            && $storedId !== ''
            && $storedId === (string) ($existing['id'] ?? '')) {
            $have = array_values(array_filter(
                (array) ($existing['enabled_events'] ?? []),
                fn ($e) => is_string($e),
            ));

            // '*' is Stripe's "every event"; it already covers ours.
            $covered = in_array('*', $have, true) || array_diff(self::EVENTS, $have) === [];

            if (! $covered) {
                $update = $this->stripeCall('POST', '/v1/webhook_endpoints/' . urlencode($storedId), [
                    'enabled_events' => self::EVENTS,
                ], $key);

                if (! $update['ok']) {
                    return $this->fail('webhook', $update['error']
                        ?? 'Stripe refused to update the events this shop listens for.');
                }
            }

            return [
                'ok' => true,
                'id' => $storedId,
                'signing_secret' => $storedSigning,
                'managed' => $this->credentials->get(self::GATEWAY, 'webhook_endpoint_managed') === '1',
                'action' => $covered ? 'reused' : 'reused_events_updated',
                'replaced' => false,
            ];
        }

        /* ---- replace ----------------------------------------------------- */

        $replaced = false;

        if ($existing !== null) {
            $oldId = (string) ($existing['id'] ?? '');

            if ($oldId !== '') {
                $delete = $this->stripeCall('DELETE', '/v1/webhook_endpoints/' . urlencode($oldId), [], $key);

                // A 404 means somebody removed it between the list and now,
                // which is the outcome we wanted anyway.
                if (! $delete['ok'] && ($delete['status'] ?? null) !== 404) {
                    return $this->fail('webhook', $delete['error']
                        ?? 'Stripe would not remove the stale webhook endpoint already pointing at this shop.');
                }

                $replaced = true;
            }
        }

        /* ---- create ------------------------------------------------------ */

        $create = $this->stripeCall('POST', '/v1/webhook_endpoints', [
            'url' => $webhookUrl,
            'enabled_events' => self::EVENTS,
            'description' => 'KBB Storefront — created automatically by Store > Payments > Stripe.',
        ], $key);

        if (! $create['ok']) {
            return $this->fail(
                'webhook',
                $create['error']
                    ?? 'Stripe refused to create the webhook endpoint. A restricted key needs write access to Webhook Endpoints.',
                ['replaced' => $replaced],
            );
        }

        $id = (string) ($create['body']['id'] ?? '');
        $signing = (string) ($create['body']['secret'] ?? '');

        if ($id === '' || $signing === '') {
            // Created but unusable. Undo it rather than leave a live endpoint
            // whose deliveries this shop can never verify.
            if ($id !== '') {
                $this->stripeCall('DELETE', '/v1/webhook_endpoints/' . urlencode($id), [], $key);
            }

            return $this->fail('webhook', 'Stripe created the webhook endpoint but returned no signing secret, so it was removed again.', [
                'replaced' => $replaced,
            ]);
        }

        return [
            'ok' => true,
            'id' => $id,
            'signing_secret' => $signing,
            'managed' => true,
            'action' => $replaced ? 'replaced' : 'created',
            'replaced' => $replaced,
        ];
    }

    /* ====================================================================== */
    /* The single write                                                       */
    /* ====================================================================== */

    /**
     * Write everything at once, and undo the endpoint if the write fails.
     *
     * This is the only place in the class that stores a credential. Up to here
     * a failure has cost the owner nothing but a message; past here the shop is
     * connected. If the write itself throws — an encryption failure, a database
     * that has gone away — an endpoint created moments ago would otherwise
     * survive in his Stripe account with a signing secret nobody holds, so it
     * is deleted before the failure is reported.
     *
     * @param  array<string, mixed>  $account
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private function store(
        string $key,
        ?string $publishableKey,
        array $account,
        array $endpoint,
        string $link,
        string $mode,
    ): array {
        $accountId = (string) ($account['id'] ?? '');
        $chargesEnabled = ! empty($account['charges_enabled']);
        $accountCurrency = strtoupper(trim((string) ($account['default_currency'] ?? '')));
        $country = strtoupper(trim((string) ($account['country'] ?? '')));
        $livemode = array_key_exists('livemode', $account)
            ? ! empty($account['livemode'])
            : str_contains($key, '_live_');

        $name = trim((string) (
            $account['business_profile']['name']
            ?? $account['settings']['dashboard']['display_name']
            ?? $account['email']
            ?? ''
        ));

        $values = [
            'secret_key' => $key,
            'webhook_signing_secret' => $endpoint['signing_secret'],
            'connect_account_id' => $accountId,
            'connect_link' => $link,
            'connected_at' => now()->toIso8601String(),
            'account_name' => $name,
            'account_country' => $country,
            'account_currency' => $accountCurrency,
            'charges_enabled' => $chargesEnabled ? '1' : '0',
            'livemode' => $livemode ? '1' : '0',
            'webhook_endpoint_id' => $endpoint['id'],
            'webhook_endpoint_managed' => ! empty($endpoint['managed']) ? '1' : '0',
        ];

        if ($publishableKey !== null && $publishableKey !== '') {
            $values['publishable_key'] = $publishableKey;
        }

        try {
            $this->credentials->save(self::GATEWAY, $values);

            $row = PaymentProvider::firstOrNew(['id' => self::GATEWAY]);
            $row->fill([
                'title' => $row->title ?: 'Credit or debit card',
                'mode' => $mode,
                'position' => $row->position ?? 0,
                // Not switched on here. Connecting proves the credentials work;
                // offering the method at the till is the owner's decision and
                // stays on the toggle he already has.
                'enabled' => (bool) ($row->enabled ?? false),
            ]);
            $row->save();
        } catch (\Throwable) {
            if (! empty($endpoint['managed']) && ! str_starts_with((string) ($endpoint['action'] ?? ''), 'reused')) {
                $this->stripeCall('DELETE', '/v1/webhook_endpoints/' . urlencode((string) $endpoint['id']), [], $key);
            }

            // The message is ours and the exception is dropped. An exception
            // raised while handling a secret key can quote that key in its own
            // text, and this one would be rendered straight onto the screen.
            return $this->fail('store', 'The connection to Stripe succeeded but this shop could not save it. Nothing was changed; try again.');
        }

        $this->credentials->forget(self::GATEWAY);

        $shopCurrency = strtoupper(Money::currency());

        return [
            'ok' => true,
            'link' => $link,
            'mode' => $mode,
            'webhook_action' => $endpoint['action'] ?? 'created',
            'webhook_url' => $this->webhookUrl(),
            'webhook_events' => self::EVENTS,
            'account' => [
                'id' => $accountId,
                'name' => $name !== '' ? $name : null,
                'country' => $country !== '' ? $country : null,
                'currency' => $accountCurrency !== '' ? $accountCurrency : null,
                'charges_enabled' => $chargesEnabled,
                'livemode' => $livemode,
            ],
            // Not failures — facts the owner has to be told, because each one is
            // silent until a real customer hits it.
            'warnings' => array_values(array_filter([
                $chargesEnabled ? null
                    : 'Stripe has not enabled charges on this account yet. Card payments will be refused until you finish Stripe\'s own account activation.',
                ($accountCurrency !== '' && $accountCurrency !== $shopCurrency)
                    ? sprintf(
                        'This shop prices in %s but the Stripe account settles in %s. Stripe will convert every payment and its fee, '
                        . 'and the amounts on your Stripe dashboard will not match the order totals here.',
                        $shopCurrency,
                        $accountCurrency,
                    )
                    : null,
                ($endpoint['action'] ?? '') === 'replaced'
                    ? 'A webhook endpoint was already pointing at this shop, but its signing secret could not be read back from Stripe '
                        . '(Stripe only ever shows it once). It was replaced with a new one. No other endpoint in your Stripe account was touched.'
                    : null,
            ])),
        ];
    }

    /* ====================================================================== */
    /* Disconnect / reset                                                     */
    /* ====================================================================== */

    /**
     * Disconnect this shop from Stripe.
     *
     * Idempotent by construction: every step is "if this is still there, remove
     * it". Pressing it twice, or pressing it on a shop that was never
     * connected, does the same work and returns the same shape.
     *
     * WHAT IT DOES TO MONEY IN FLIGHT. Nothing is refunded and nothing is
     * cancelled — this shop stops being able to hear from Stripe, it does not
     * reach into Stripe and undo anything. So:
     *
     *   - a shopper part-way through Stripe's payment page can still pay. The
     *     money lands in the owner's Stripe account exactly as it would have.
     *   - the notification that would have marked the order paid can no longer
     *     be verified, so that order stays unpaid on this screen.
     *
     * That is the honest risk, and it is why in_flight is shown before the
     * confirm. It is also why it cannot be permanent: the order is not
     * destroyed, its `transaction_id` is kept, and Store -> Orders can still
     * mark it paid by hand against the payment visible in the Stripe dashboard.
     * Reconnecting the same Stripe account restores the automatic route, and
     * Stripe retries a failed delivery for up to three days — so an order
     * stranded by a disconnect and reconnected inside that window settles
     * itself.
     *
     * @return array<string, mixed>
     */
    public function disconnect(): array
    {
        $this->credentials->forget(self::GATEWAY);

        $key = $this->credentials->get(self::GATEWAY, 'secret_key');
        $endpointId = $this->credentials->get(self::GATEWAY, 'webhook_endpoint_id');
        $managed = $this->credentials->get(self::GATEWAY, 'webhook_endpoint_managed') === '1';
        $link = $this->credentials->get(self::GATEWAY, 'connect_link');
        $accountId = $this->credentials->get(self::GATEWAY, 'connect_account_id');
        $clientId = $this->credentials->get(self::GATEWAY, 'connect_client_id');
        $webhookUrl = $this->webhookUrl();

        $steps = [];
        $warnings = [];

        /* ---- 1. the endpoint we are responsible for ---------------------- */

        if ($key !== '' && $endpointId !== '' && $managed) {
            // Read it back before deleting. "Only the one this shop created" is
            // checked rather than assumed: the id is ours, and its URL must
            // still be ours too, or it is not the endpoint we think it is.
            $read = $this->stripeCall('GET', '/v1/webhook_endpoints/' . urlencode($endpointId), [], $key);

            if (($read['status'] ?? null) === 404) {
                $steps[] = 'webhook_already_gone';
            } elseif (! $read['ok']) {
                $steps[] = 'webhook_unreachable';
                $warnings[] = 'Stripe could not be reached to remove this shop\'s webhook endpoint. '
                    . 'It is harmless — with the keys cleared, nothing it sends can be accepted — but you can delete it '
                    . 'yourself under Developers -> Webhooks.';
            } elseif ($webhookUrl !== null && (string) ($read['body']['url'] ?? '') !== $webhookUrl) {
                $steps[] = 'webhook_not_ours';
                $warnings[] = 'The webhook endpoint recorded against this shop now points somewhere else, so it was left alone.';
            } else {
                $delete = $this->stripeCall('DELETE', '/v1/webhook_endpoints/' . urlencode($endpointId), [], $key);

                if ($delete['ok'] || ($delete['status'] ?? null) === 404) {
                    $steps[] = 'webhook_deleted';
                } else {
                    $steps[] = 'webhook_delete_failed';
                    $warnings[] = 'Stripe refused to delete this shop\'s webhook endpoint. Remove it yourself under '
                        . 'Developers -> Webhooks; until then it simply delivers to an address that no longer accepts anything.';
                }
            }
        } elseif ($endpointId !== '' && ! $managed) {
            $steps[] = 'webhook_left_alone';
            $warnings[] = 'The webhook endpoint for this shop was not created by this screen, so it was left in your Stripe account.';
        }

        /* ---- 2. Connect deauthorise -------------------------------------- */

        if ($link === 'oauth' && $accountId !== '' && $clientId !== '' && $key !== '') {
            $deauth = $this->stripeCall('POST', '/v1/oauth/deauthorize', [
                'client_id' => $clientId,
                'stripe_user_id' => $accountId,
            ], $key);

            if ($deauth['ok']) {
                $steps[] = 'deauthorized';
            } else {
                // Not a blocker. Refusing to clear the local credentials
                // because Stripe's API hiccuped would mean the Disconnect
                // button does not disconnect, which is worse.
                $steps[] = 'deauthorize_failed';
                $warnings[] = 'Stripe would not revoke the connection at its end. This shop has stopped using the account '
                    . 'either way; you can remove the authorisation under Settings -> Connected accounts in Stripe.';
            }
        }

        /* ---- 3. wipe ----------------------------------------------------- */

        // null clears the key outright — GatewayCredentials::save() treats an
        // empty string as "leave it alone", which is exactly wrong here.
        $this->credentials->save(self::GATEWAY, [
            'secret_key' => null,
            'publishable_key' => null,
            'webhook_signing_secret' => null,
            // The URL tail goes too, so a reset is a reset: the next connection
            // gets a fresh address, and nothing that was ever pasted into a
            // dashboard still reaches this shop.
            'webhook_secret' => null,
            'webhook_endpoint_id' => null,
            'webhook_endpoint_managed' => null,
            'connect_account_id' => null,
            'connect_link' => null,
            'connected_at' => null,
            'account_name' => null,
            'account_country' => null,
            'account_currency' => null,
            'charges_enabled' => null,
            'livemode' => null,
            // connect_client_id is NOT cleared. It identifies the platform
            // application this install owns, not the connection that was just
            // ended, and making him find it again after every reset would be a
            // small cruelty.
        ]);

        // A gateway with no credentials must not sit on the checkout page
        // looking available. configured() already hides it; switching the row
        // off makes that visible on the screen instead of implicit.
        $row = PaymentProvider::find(self::GATEWAY);

        if ($row !== null && $row->enabled) {
            $row->enabled = false;
            $row->save();
        }

        $this->credentials->forget(self::GATEWAY);

        $steps[] = 'credentials_cleared';

        return [
            'ok' => true,
            'was_connected' => $key !== '',
            'steps' => $steps,
            'warnings' => $warnings,
            'in_flight' => $this->inFlight(),
        ];
    }

    /* ====================================================================== */
    /* HTTP                                                                   */
    /* ====================================================================== */

    /**
     * One call to Stripe, with the key in the header and never anywhere else.
     *
     * Form-encoded, because Stripe's API is — the same reason StripeGateway has
     * its own form() rather than using RemoteGateway::call().
     *
     * NOTHING IS LOGGED FROM HERE. Not the request, not the response, not the
     * exception message. A transport exception raised by the HTTP client can
     * quote the request it was making, and that request carries an
     * Authorization header with a live secret key in it; this is a money
     * surface, and a log file is read by more people than a database is. The
     * caller gets a status and Stripe's own `error.message`, which is written
     * for merchants and is the most useful thing we could show anyway.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int|null, body: array<string, mixed>|null, error: string|null}
     */
    private function stripeCall(string $method, string $path, array $payload, string $key): array
    {
        try {
            $request = Http::withHeaders(['Authorization' => 'Bearer ' . $key])
                ->timeout(self::TIMEOUT)
                ->asForm();

            $url = self::API . $path;

            $response = match (strtoupper($method)) {
                'GET' => $request->get($url),
                'DELETE' => $request->delete($url),
                default => $request->post($url, $this->flatten($payload)),
            };
        } catch (\Throwable) {
            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => 'Stripe could not be reached from this server. Nothing was changed; try again in a moment.',
            ];
        }

        $decoded = $response->json();
        $decoded = is_array($decoded) ? $decoded : null;

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => $response->successful() ? $decoded : null,
            'error' => $response->successful() ? null : $this->stripeMessage($decoded, $response->status()),
        ];
    }

    /**
     * Stripe's own words, which are written for merchants.
     *
     * "Invalid API Key provided: sk_test_***" is more useful than anything we
     * could write, and Stripe already masks the key inside it. The fallback
     * covers a response that is not Stripe's JSON at all — a proxy error page,
     * an outage splash — where there is no message to quote.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function stripeMessage(?array $body, ?int $status): string
    {
        $message = trim((string) ($body['error']['message'] ?? $body['error_description'] ?? ''));

        if ($message !== '') {
            return $message;
        }

        return $status === 401
            ? 'Stripe rejected that key. Copy it again from Developers -> API keys.'
            : 'Stripe returned an error (HTTP ' . ($status ?? 0) . ').';
    }

    /** ['a' => ['b' => 1]] -> ['a[b]' => 1]; a list becomes a[0], a[1], ... */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';

            if (is_array($value)) {
                $out += $this->flatten($value, $name);

                continue;
            }

            $out[$name] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function fail(string $step, string $error, array $extra = []): array
    {
        return ['ok' => false, 'step' => $step, 'error' => $error] + $extra;
    }
}
