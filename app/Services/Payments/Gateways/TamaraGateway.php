<?php

declare(strict_types=1);

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use App\Services\Payments\HandlesWebhooks;
use App\Services\Payments\PaymentStart;
use App\Services\Payments\Reconciliation\ListsTransactions;
use App\Services\Payments\Reconciliation\ReconcileWindow;
use App\Services\Payments\Reconciliation\RemotePage;
use App\Services\Payments\Reconciliation\RemoteTxn;
use App\Services\Payments\SettlementResult;
use App\Services\Payments\SettlesPayments;
use App\Services\Payments\Signature;
use App\Services\Payments\VoidsAuthorisation;
use App\Services\Payments\WebhookOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Tamara — buy now, pay later.
 *
 * Shapes taken from the merchant's working WooCommerce plugin and the SDK
 * bundled with it; the implementation is our own.
 *
 *   POST /checkout                        create a session -> checkout_url
 *   GET  /merchants/orders/{id}           the authoritative state of an order
 *   POST /orders/{id}/authorise           confirm an approved order
 *   POST /payments/capture                take the money after authorisation
 *   POST /payments/refund                 give some or all of it back
 *   Settlement endpoints are flat: the order id travels in the BODY.
 *   Auth: Authorization: Bearer <api_token>
 *   https://api.tamara.co  |  https://api-sandbox.tamara.co
 *   Amounts: {amount, currency} with amount in MAJOR units
 *
 * ---------------------------------------------------------------------------
 * Verification
 *
 * Tamara signs its notifications properly, which makes this simpler than
 * Tabby: an HS256 JWT in `Authorization: Bearer`, keyed on the merchant's
 * notification token. Signature::verifyJwtHs256 fixes the algorithm at HS256
 * rather than reading it out of the header — trusting the header's `alg` is
 * the classic JWT forgery, where "alg":"none" or an RS256 token verified as
 * HMAC against a public key both sail through.
 *
 * Three gates, all of which must pass:
 *
 *   1. the URL secret (hash_equals), same as every other gateway here
 *   2. the JWT signature against the notification token
 *   3. the order is re-fetched from Tamara over an authenticated GET, and the
 *      amount and currency in THAT response are what get compared to ours
 *
 * Gate 3 is not redundant given gate 2. The signed payload carries an order
 * status but no trustworthy total, and the thing being protected is "is this
 * the amount we asked for" — so the figure has to come from an authenticated
 * call, not from a body, however well signed the body is.
 *
 * Two kinds of callback arrive at the same endpoint. The IPN carries
 * `order_status` and is how an approval is announced; the webhook carries
 * `event_type` and is how expiry and decline are. Both are handled.
 */
class TamaraGateway extends RemoteGateway implements HandlesWebhooks, ListsTransactions, SettlesPayments, VoidsAuthorisation
{
    private const LIVE = 'https://api.tamara.co';

    private const SANDBOX = 'https://api-sandbox.tamara.co';

    /** Tamara operates in these markets. */
    private const COUNTRIES = ['AE', 'SA', 'KW', 'BH', 'QA', 'OM'];

    /**
     * Currency -> country, for an order whose address carries no country.
     *
     * The merchant's plugin derives the country from the SHOP CURRENCY and
     * nothing else (`getCurrencyToCountryMapping`), which is right for a
     * single-currency shop and is the only signal available when the billing
     * address is incomplete. Here it is the fallback rather than the primary:
     * the order's own billing country is asked first, because this shop can
     * take an AED order from a Saudi address and Tamara scores the consumer on
     * where they are, not on what they are paying in.
     */
    private const CURRENCY_COUNTRY = [
        'AED' => 'AE',
        'SAR' => 'SA',
        'KWD' => 'KW',
        'BHD' => 'BH',
        'QAR' => 'QA',
        'OMR' => 'OM',
    ];

    /**
     * The payment types Tamara sells, and the only strings this class will put
     * in a `payment_type` field.
     *
     * The plugin ships a separate WooCommerce gateway class per variant —
     * `WCTamaraGatewayPayNow`, `…PayNextMonth`, `…PayByInstalments` and
     * `…PayIn2` through `…PayIn12` — which is how WooCommerce makes one
     * provider appear as several radio buttons. That is a WooCommerce shape,
     * not a Tamara one: on the wire the fifteen classes collapse to four
     * `payment_type` values, and PayIn2..PayIn12 are PAY_BY_INSTALMENTS with
     * an `instalments` count beside it. So this ships as ONE gateway with a
     * setting rather than fifteen registry entries.
     *
     * READ THROUGH paymentType(), WHICH ALLOWLISTS. A `payment_type` this shop
     * invented is a 400 from Tamara on every checkout, and the setting is a
     * text box on a screen — CLAUDE.md rule 5, "a select stores one of its own
     * options or the default", enforced here rather than in the browser.
     */
    private const PAYMENT_TYPES = [
        'PAY_BY_LATER',
        'PAY_NOW',
        'PAY_NEXT_MONTH',
        'PAY_BY_INSTALMENTS',
    ];

    /** The default, and what every existing install keeps. */
    private const DEFAULT_PAYMENT_TYPE = 'PAY_BY_LATER';

    /**
     * The webhook events this shop asks Tamara to deliver.
     *
     * Exactly the two the plugin registers, and exactly the two
     * handleWebhook() acts on. Registering an event nothing handles would put
     * deliveries on the endpoint that can only be answered "nothing to do for
     * this event", and a provider retrying those is noise that hides a real
     * failure.
     */
    public const WEBHOOK_EVENTS = ['order_expired', 'order_declined'];

    /**
     * Default days an authorised order stays capturable.
     *
     * Taken from the outer bound the merchant's own Tamara plugin sweeps to:
     * its "force capture" job hunts orders authorised but not captured within
     * 180 days, and gives up past that. Overridable on the payments screen,
     * because Tamara agrees this per merchant. Advisory either way — capture()
     * reads the order's live status from Tamara and that is what decides.
     */
    private const DEFAULT_CAPTURE_DAYS = 180;

    public function id(): string
    {
        return 'tamara';
    }

    public function title(): string
    {
        return 'Pay later with Tamara';
    }

    public function configured(): bool
    {
        return $this->credentials->filled($this->id(), 'api_token', 'notification_token');
    }

    public function description(int $totalFils): ?string
    {
        return 'Split in up to 4 payments, or pay in 30 days. No interest.';
    }

    /**
     * May this basket use Tamara?
     *
     * Three gates now, where there was one.
     *
     * THE ORDER LIMITS ARE THE GAP THIS CLOSES. Tamara agrees a minimum and a
     * maximum basket value with each merchant, per market, and refuses a
     * `POST /checkout` outside them. Until these two settings existed this
     * class offered Tamara on every basket down to one fil: the shopper picked
     * it, was told "Tamara is not available for this order" by start() AFTER
     * pressing Place order, and had to choose again. The plugin does not have
     * that behaviour — `isCartTotalValid()` checks the basket against the
     * limits it pulled from `/checkout/payment-types` before the radio button
     * is drawn.
     *
     * WHERE THE NUMBERS COME FROM, AND WHY NOT FROM TAMARA ON EVERY PAGE. The
     * plugin asks Tamara for the limits while rendering the checkout, cached in
     * a transient. This asks the stored settings, which
     * TamaraLimits::refresh() fills from the same endpoint on demand. That is
     * a deliberate divergence: availableFor() is called for every gateway on
     * every checkout render and on every cart update, and CLAUDE.md rule 4
     * forbids putting a third party's latency on that path. A stale limit costs
     * one refused session; a synchronous call costs every shopper on every
     * page, and an outage at Tamara would cost the checkout itself.
     *
     * BOTH LIMITS SHIP EMPTY, so nothing moves when the package is applied
     * (rule 1) — an empty limit is not enforced, which is precisely the
     * behaviour this method had before. The owner fills them, or presses the
     * button that fills them, and only then does anything change.
     */
    public function availableFor(int $totalFils, ?string $country = null): bool
    {
        if ($totalFils <= 0) {
            return false;
        }

        if ($country !== null && ! in_array(strtoupper($country), self::COUNTRIES, true)) {
            return false;
        }

        $min = $this->limitFils('min_limit');
        $max = $this->limitFils('max_limit');

        if ($min !== null && $totalFils < $min) {
            return false;
        }

        if ($max !== null && $totalFils > $max) {
            return false;
        }

        return true;
    }

    public function configSchema(): array
    {
        return [
            'api_token' => ['secret', 'API token', 'From Tamara merchant portal -> Settings -> API. Long JWT-looking string.', 'keys'],
            'notification_token' => ['secret', 'Notification token', 'Separate from the API token. This is the key Tamara signs webhooks with; without it no webhook can be verified.', 'keys'],
            /*
             * NOT READ BY ANY CODE PATH IN THIS BUILD, and the help text says so.
             * It used to read "for the product-page widget only", which promised a
             * widget this shop does not have — a setting whose help describes a
             * feature that is not there is worse than no setting, because the
             * owner fills it in and believes something happened. The widget is
             * named in the lane report as deliberately not shipped: it is a script
             * served from Tamara's CDN and there is no way to verify it from here.
             */
            'public_key' => ['text', 'Public key', 'Not used yet. Tamara issues this for the on-page "pay in 4" widget, which this shop does not display; storing it now does nothing and costs nothing.', 'keys'],
            'webhook_secret' => ['secret', 'Webhook secret', 'Generated for you. Forms part of the webhook URL below.', 'keys'],
            'capture_days' => ['text', 'Capture window (days)', 'How long Tamara leaves an authorised order capturable on your account. Default 180. Used only to warn you before it lapses — Tamara itself decides.', 'settings'],
            /*
             * A text box rather than a `select`, and not because a select would
             * be wrong: the payments screen in resources/views/admin/
             * app.blade.php renders `secret`, `bool` and then everything else as
             * a text input, and CLAUDE.md forbids this lane from editing that
             * file. Declaring `select` here would paint a text box anyway and
             * lie about it in configSchema(). The allowlist that a select would
             * give is enforced server-side in paymentType() instead, which is
             * the stronger place for it — it holds whatever the box contains and
             * whatever a future screen posts.
             */
            'payment_type' => ['text', 'Payment type', 'One of PAY_BY_LATER (pay in 30 days), PAY_NOW, PAY_NEXT_MONTH or PAY_BY_INSTALMENTS. Leave empty for PAY_BY_LATER. Anything else is ignored and PAY_BY_LATER is used.', 'settings'],
            'instalments' => ['text', 'Instalments', 'Only read when the payment type is PAY_BY_INSTALMENTS: how many instalments to ask Tamara for, 2 to 12. Leave empty to let Tamara choose. Your account has to be enabled for the number you ask for.', 'settings'],
            /*
             * The two exclusion boxes. Comma-separated, and matched on product id
             * OR sku — see basketAllowed() for why both. EMPTY ON EVERY EXISTING
             * INSTALL, so nothing is excluded until somebody types into them.
             */
            'excluded_products' => ['text', 'Products Tamara may not be used for', 'Comma-separated product ids or SKUs. A basket containing one of these is not offered Tamara. Leave empty to exclude nothing — which is what the shop does today.', 'settings'],
            'excluded_categories' => ['text', 'Categories Tamara may not be used for', 'Comma-separated category ids. A basket containing any product in one of these is not offered Tamara. Leave empty to exclude nothing.', 'settings'],
            'min_limit' => ['text', 'Minimum basket', 'In whole currency units as Tamara\'s portal shows them (e.g. 100 for AED 100.00). Baskets below this are not offered Tamara. Leave empty for no minimum. "Refresh limits from Tamara" fills this in for you.', 'settings'],
            'max_limit' => ['text', 'Maximum basket', 'In whole currency units, as above. Baskets above this are not offered Tamara. Leave empty for no maximum.', 'settings'],
            /*
             * Not a credential — the id Tamara hands back from POST /webhooks,
             * kept so the registration can be removed again. `text` rather than
             * `secret` deliberately: it is not a key, it is unusable without
             * one, and the owner needs to SEE whether a webhook is registered.
             */
            'webhook_id' => ['text', 'Registered webhook id', 'Filled in by "Register webhook with Tamara". Until a webhook is registered, Tamara never sends the expiry and decline notices this shop is written to act on.', 'keys'],
        ];
    }

    /**
     * Products and categories the merchant's Tamara contract does not cover.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * A REAL SETTING IN THE PLUGIN, AND NOT A CONVENIENCE. Tamara agrees per
     * merchant what may be bought on credit, and `excluded_products` /
     * `excluded_product_categories` are how the plugin honours that:
     * `adjustTamaraGatewayOnCheckout()` takes Tamara off the checkout entirely
     * when the basket contains one, by product id or by category id.
     *
     * This shop had no such control at all, so a basket the merchant's contract
     * excludes went through Tamara exactly like any other. Nothing crashes;
     * the shop is simply selling on terms it has not agreed.
     *
     * BOTH SHIP EMPTY, so applying the package excludes nothing (rule 1). An
     * empty list is not enforced, which is precisely the behaviour this class had
     * before these settings existed.
     *
     * @return array<int, string> trimmed, non-empty, lower-cased tokens
     */
    private function excluded(string $key): array
    {
        $raw = $this->credentials->get($this->id(), $key);

        if (trim($raw) === '') {
            return [];
        }

        /*
         * Comma-separated, like the plugin's own boxes, and split on whitespace
         * and semicolons too: the owner will paste a list out of a spreadsheet
         * and the shape it arrives in is not something to be fussy about when
         * the cost of being fussy is a silently un-enforced exclusion.
         */
        $tokens = preg_split('/[\s,;]+/', strtolower(trim($raw))) ?: [];

        return array_values(array_filter($tokens, fn ($t) => $t !== ''));
    }

    /**
     * Is every line on this order one Tamara may be used for?
     *
     * TWO QUERIES AT MOST, AND ONLY WHEN A LIST IS SET. A shop that has excluded
     * nothing — which is every shop until somebody types into the box — does no
     * work here at all beyond reading two settings it has already loaded. That
     * matters because this is on the path of a shopper pressing Place order.
     *
     * The category half is ONE `whereIn` against the pivot, not a relation walk
     * per line: `$order->items` has the product ids already, and asking each
     * product for its categories is the N+1 rule 4 forbids and the obvious way
     * to write this.
     *
     * MATCHED ON PRODUCT ID **AND** SKU, because those are the two things an
     * operator can actually copy. The plugin only takes ids, which is a
     * WooCommerce habit — an id is what its admin shows. This shop's own
     * catalogue screens show SKUs, and an owner told "paste product ids" will
     * paste SKUs about half the time. Accepting both costs one comparison and
     * removes the failure mode where the box looks filled in and excludes
     * nothing.
     */
    private function basketAllowed(Order $order): bool
    {
        $products = $this->excluded('excluded_products');
        $categories = $this->excluded('excluded_categories');

        if ($products === [] && $categories === []) {
            return true;
        }

        $productIds = [];

        foreach ($order->items as $item) {
            if ($products !== []) {
                $id = strtolower(trim((string) $item->product_id));
                $sku = strtolower(trim((string) $item->sku));

                if (($id !== '' && in_array($id, $products, true))
                    || ($sku !== '' && in_array($sku, $products, true))) {
                    return false;
                }
            }

            if ($item->product_id !== null) {
                $productIds[] = (int) $item->product_id;
            }
        }

        if ($categories === [] || $productIds === []) {
            return true;
        }

        $inBasket = \Illuminate\Support\Facades\DB::table('category_product')
            ->whereIn('product_id', array_unique($productIds))
            ->pluck('category_id')
            ->map(fn ($id) => strtolower(trim((string) $id)))
            ->all();

        return array_intersect($inBasket, $categories) === [];
    }

    /**
     * A basket limit in fils, or null when the setting is empty.
     *
     * Stored in MAJOR units because that is the number printed in Tamara's own
     * merchant portal ("Min Amount: 100 AED"), and a screen that asks the owner
     * to convert to fils is a screen that gets a hundredfold error typed into
     * it. Converted through toFils(), so `100.50` is 10050 and never 10049 —
     * see the note on that method about (int) casts on floats.
     *
     * A non-numeric value reads as "no limit" rather than as zero. Zero would
     * be a real minimum of nought, which is harmless, but a max_limit of zero
     * read off the word "none" would take Tamara off every basket in the shop.
     */
    private function limitFils(string $key): ?int
    {
        $raw = $this->credentials->get($this->id(), $key);

        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $fils = $this->toFils($raw);

        return $fils > 0 ? $fils : null;
    }

    /**
     * The payment type to ask Tamara for — always one Tamara sells.
     *
     * The stored value is trimmed and upper-cased before the allowlist test, so
     * `pay_now` typed in lower case works and `PAY_LATER` (a plausible typo for
     * PAY_BY_LATER) falls back to the default rather than 400ing every
     * checkout.
     */
    private function paymentType(): string
    {
        $stored = strtoupper($this->credentials->get($this->id(), 'payment_type'));

        return in_array($stored, self::PAYMENT_TYPES, true) ? $stored : self::DEFAULT_PAYMENT_TYPE;
    }

    /**
     * How many instalments, or null.
     *
     * Only meaningful with PAY_BY_INSTALMENTS, and only sent then: Tamara
     * rejects an `instalments` count against PAY_BY_LATER. Clamped to the 2–12
     * range the plugin's own PayIn2..PayIn12 classes cover, because a count
     * outside it is not a plan Tamara offers anybody.
     */
    private function instalments(): ?int
    {
        if ($this->paymentType() !== 'PAY_BY_INSTALMENTS') {
            return null;
        }

        $raw = $this->credentials->get($this->id(), 'instalments');

        if ($raw === '' || ! ctype_digit($raw)) {
            return null;
        }

        $count = (int) $raw;

        return $count >= 2 && $count <= 12 ? $count : null;
    }

    protected function baseUrl(): string
    {
        return $this->credentials->live($this->id()) ? self::LIVE : self::SANDBOX;
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->credentials->get($this->id(), 'api_token'),
        ];
    }

    /* ------------------------------------------------------------- checkout */

    public function start(Order $order): PaymentStart
    {
        if (! $this->configured()) {
            return PaymentStart::failed('Tamara is not available right now.');
        }

        $billing = is_array($order->billing_address) ? $order->billing_address : [];
        $currency = strtoupper((string) ($order->currency ?: 'AED'));

        /*
         * THE CONSUMER'S PHONE, WHICH TAMARA WILL NOT SCORE ANYBODY WITHOUT.
         *
         * A BNPL decision is made against a person, and in these markets the
         * mobile number IS the identity — Tamara refuses `POST /checkout`
         * without one. This used to travel as `(string) (null)`, an empty
         * string, so an order placed without a phone produced a session
         * creation that failed at Tamara and a shopper told "Tamara is not
         * available for this order. Please choose another payment method." That
         * sentence is true of a basket over the merchant's limit and false here:
         * Tamara is available, this order is missing one field, and the shopper
         * can fix it in five seconds if anybody tells them which one.
         *
         * Checked before the call rather than after it, so no round trip is
         * spent discovering something already knowable, and the message names
         * the field. The plugin's answer to the same problem is a setting called
         * `force_billing_phone` that makes the phone box required on the
         * checkout form; this shop's checkout already collects a phone, so the
         * guard is here for the order that reaches this class without one —
         * a manually created order, or an import.
         */
        $phone = trim((string) ($order->phone ?: ($billing['phone'] ?? '')));

        if ($phone === '') {
            return PaymentStart::failed(
                'Tamara needs a mobile number to approve a payment. Please add a phone number and try again.'
            );
        }

        /*
         * IS TAMARA ALLOWED TO PAY FOR THESE LINES?
         *
         * Checked HERE, server-side, off the order's own items — never off
         * anything a request carried. An excluded basket that reaches this method
         * is either a shopper who had Tamara selected before the setting changed,
         * or somebody posting a payment method straight at the endpoint; both get
         * the same answer and neither reaches Tamara.
         *
         * The merchant's plugin also HIDES the radio button for such a basket,
         * which is the better shopper experience and needs a basket-aware
         * availability check this app's PaymentGateway interface does not have —
         * availableFor() is handed a total and a country and nothing else. Adding
         * the basket means changing that interface and all five gateways that
         * implement it, which is not a change to make while another lane is in
         * those same files. Named in the lane report for the integrator.
         *
         * So this is the enforcing half and it is the half that cannot be
         * bypassed: a basket the merchant's contract does not cover is never sent
         * to Tamara, whatever the checkout drew.
         */
        if (! $this->basketAllowed($order)) {
            return PaymentStart::failed(
                'Tamara cannot be used for one of the items in this order. Please choose another payment method.'
            );
        }

        $amounts = $this->amounts($order, (int) $order->total, $currency);
        $instalments = $this->instalments();

        $payload = [
            'order_reference_id' => $this->reference($order),
            'order_number' => $this->reference($order),
            'total_amount' => $this->money((int) $order->total, $currency),
            'shipping_amount' => $this->money($amounts['shipping'], $currency),
            'tax_amount' => $this->money($amounts['tax'], $currency),
            'discount' => [
                'name' => (string) ($order->coupon_code ?: 'Discount'),
                'amount' => $this->money($amounts['discount'], $currency),
            ],
            'description' => 'Order ' . $this->reference($order),
            'country_code' => $this->countryCode($order, $billing, $currency),
            'payment_type' => $this->paymentType(),
            /*
             * The shopper's own language, so Tamara's hosted pages are not in
             * English on the Arabic half of this shop. Tamara wants a full
             * locale; the app carries a two-letter one.
             */
            'locale' => $this->locale(),
            'items' => $amounts['items'],
            'consumer' => [
                'first_name' => (string) ($billing['first_name'] ?? ''),
                'last_name' => (string) ($billing['last_name'] ?? ''),
                'phone_number' => $phone,
                'email' => (string) $order->email,
            ],
            'billing_address' => $this->address($billing),
            'shipping_address' => $this->address(
                is_array($order->shipping_address) ? $order->shipping_address : $billing
            ),
            'merchant_url' => [
                'success' => $this->returnUrl($order, 'success'),
                'failure' => $this->returnUrl($order, 'failure'),
                'cancel' => $this->returnUrl($order, 'cancel'),
                'notification' => $this->webhookUrl(),
            ],
            'platform' => 'KBB Storefront (Laravel)',
            /*
             * WHAT THIS SHOP KNOWS ABOUT THE BUYER, WHICH DECIDES APPROVALS.
             *
             * `risk_assessment` is how Tamara is told that this is a returning
             * customer with four delivered orders rather than an account created
             * ninety seconds ago. The plugin sends it on every session
             * (`populateTamaraRiskAssessment`) and it was absent here entirely,
             * which means every one of this shop's regulars was being scored as
             * a stranger. That is not a crash, which is why it could sit here
             * unnoticed: it is a quietly lower approval rate on the customers
             * the shop most wants to keep.
             *
             * Nothing in the block is new data and none of it is a secret — it
             * is this shop's own order history for this email, counted. See
             * riskAssessment() for exactly what is sent and what is not.
             */
            'risk_assessment' => $this->riskAssessment($order, $currency),
        ];

        if ($instalments !== null) {
            // Only with PAY_BY_INSTALMENTS, and only when a count is set:
            // Tamara rejects the field against the other three payment types,
            // so an unconditional `'instalments' => null` would break the
            // default configuration to support an optional one.
            $payload['instalments'] = $instalments;
        }

        $result = $this->call('POST', '/checkout', $payload);

        $url = $result['checkout_url'] ?? null;

        if ($result === null || ! is_string($url) || $url === '') {
            return PaymentStart::failed('Tamara is not available for this order. Please choose another payment method.');
        }

        $tamaraOrderId = (string) ($result['order_id'] ?? '');

        $order->forceFill(['transaction_id' => $tamaraOrderId])->save();

        $this->log('checkout session created', '/checkout', 200, ['reference' => $this->reference($order)]);

        return PaymentStart::redirect($url, $tamaraOrderId);
    }

    /* -------------------------------------------------------------- webhook */

    public function handleWebhook(Request $request): WebhookOutcome
    {
        // (1) URL secret, constant-time, before anything else happens.
        $expected = $this->credentials->get($this->id(), 'webhook_secret');

        if ($expected === '' || ! Signature::equals($expected, (string) $request->route('secret'))) {
            return WebhookOutcome::rejected();
        }

        // (2) The signature Tamara itself applies. Accepted from the header or
        //     the `tamaraToken` query parameter, which is the other shape
        //     their own SDK looks in.
        $token = Signature::bearer($request->header('Authorization'))
            ?? $request->query('tamaraToken');

        if (! is_string($token) || $token === '') {
            return WebhookOutcome::rejected('no token');
        }

        $claims = Signature::verifyJwtHs256(
            $token,
            $this->credentials->get($this->id(), 'notification_token'),
        );

        if ($claims === null) {
            return WebhookOutcome::rejected();
        }

        $body = $request->json()->all();
        $body = is_array($body) ? $body : [];

        $reference = $body['order_reference_id'] ?? null;
        $tamaraOrderId = (string) ($body['order_id'] ?? '');

        $order = $this->findOrderByReference(is_string($reference) ? $reference : null);

        if ($order === null) {
            return WebhookOutcome::refused('no order for that reference');
        }

        $summary = [
            'tamara_order_id' => $tamaraOrderId,
            'reference' => $reference,
            'event_type' => $body['event_type'] ?? null,
            'order_status' => $body['order_status'] ?? null,
        ];

        // The webhook shape: expiry and decline.
        $event = (string) ($body['event_type'] ?? '');

        if (in_array($event, ['order_expired', 'order_declined'], true)) {
            return $this->confirmer->fail($order, $this->id(), $tamaraOrderId, $event, $summary);
        }

        // The IPN shape: an approval to act on.
        $status = strtolower((string) ($body['order_status'] ?? ''));

        if (! in_array($status, ['approved', 'authorised', 'authorized', 'fully_captured'], true)) {
            return WebhookOutcome::ignored('nothing to do for this event');
        }

        if ($tamaraOrderId === '') {
            return WebhookOutcome::refused('no tamara order id');
        }

        // (3) Ask Tamara what the order actually is. Nothing below comes from
        //     the body -- a signed body still is not an authenticated total.
        $remote = $this->call('GET', '/merchants/orders/' . urlencode($tamaraOrderId));

        if ($remote === null) {
            return WebhookOutcome::failed('could not verify order with Tamara');
        }

        return $this->settleFromRemote($order, $tamaraOrderId, $remote, $summary);
    }

    /**
     * Settle one order against the record Tamara itself holds for it.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * The tail of handleWebhook(), lifted out so the SWEEP runs the identical
     * code — see reconcileAuthorisation(). Two paths that both decide whether
     * money is owed must not be two pieces of code, because the day one of them
     * learns a new Tamara status the other one silently does not.
     *
     * EVERY DECISION BELOW IS MADE ON `$remote`, WHICH CAME BACK OVER AN
     * AUTHENTICATED GET, AND NOTHING IS MADE ON THE CALLBACK BODY. That is a
     * change from what handleWebhook() did before this method existed, and it
     * was a real weakness rather than a tidy-up:
     *
     *   the old code decided whether a FAILED `POST /orders/{id}/authorise`
     *   was fatal by looking at `order_status` in the delivered body. A body
     *   claiming `fully_captured` made the authorise failure non-fatal, so the
     *   order was confirmed as paid on the strength of a field in the payload.
     *
     * The body is JWT-signed, so that was not a forgery an outsider could
     * mount, and it is why this is a weakness and not an incident. But the
     * whole argument for gate 3 — written out at the head of this class — is
     * that a signed body still is not an authenticated total, and the same
     * reasoning applies to an authenticated STATUS. `$remote['status']` costs
     * nothing extra here; it has already been fetched.
     *
     * @param  array<string, mixed>  $remote   the body of GET /merchants/orders/{id}
     * @param  array<string, mixed>  $summary  named fields for the audit row
     */
    private function settleFromRemote(
        Order $order,
        string $tamaraOrderId,
        array $remote,
        array $summary = [],
    ): WebhookOutcome {
        // The reference on the fetched order has to be the one we looked up,
        // or a valid token for order A has been pointed at order B.
        if ((string) ($remote['order_reference_id'] ?? '') !== (string) $order->order_number) {
            return WebhookOutcome::refused('reference does not match');
        }

        $status = strtolower(trim((string) ($remote['status'] ?? '')));
        $summary['remote_status'] = $status;

        /*
         * TERMINAL FAILURES, FROM TAMARA'S OWN MOUTH.
         *
         * The webhook branch above acts on `order_expired` and `order_declined`
         * because those are the two events Tamara pushes. This reads the same
         * facts out of the order itself, which is the only way the SWEEP can
         * learn them — a webhook that was never delivered leaves no event to
         * act on, and the order's status is the durable record of it.
         *
         * PaymentConfirmer::fail() refuses to downgrade an order that is already
         * paid, so a stale `expired` cannot cancel a real sale.
         */
        if (in_array($status, ['declined', 'expired', 'canceled', 'cancelled'], true)) {
            return $this->confirmer->fail($order, $this->id(), $tamaraOrderId, $status, $summary);
        }

        /*
         * `new` IS NOT A FAILURE AND MUST NOT BE TREATED AS ONE.
         *
         * A Tamara order sits `new` from session creation until the shopper
         * finishes Tamara's hosted flow. On the sweep that is the ordinary state
         * of a basket somebody is still looking at, or walked away from and may
         * come back to — Tamara expires it itself and the sweep will see
         * `expired` then. Cancelling our order here would kill a checkout in
         * progress, which is the one mistake in this method that costs a sale
         * that was going to happen.
         */
        if (! in_array($status, ['approved', 'authorised', 'authorized', 'fully_captured', 'partially_captured'], true)) {
            return WebhookOutcome::ignored('tamara reports this order as ' . ($status !== '' ? $status : 'unknown'));
        }

        $amount = $remote['total_amount']['amount'] ?? 0;
        $currency = (string) ($remote['total_amount']['currency'] ?? '');

        /*
         * AUTHORISE BEFORE CONFIRMING. Tamara holds the money until this call;
         * an order marked paid here but never authorised is one we never get.
         *
         * Only from `approved`, which is the one status that still needs it.
         * An order Tamara already reports as authorised or captured has been
         * through this call — calling it again is at best a 409, and treating
         * that 409 as a failure would leave a PAID order unconfirmed for ever,
         * which is the bug the sweep exists to clear rather than to create.
         */
        if ($status === 'approved') {
            $authorised = $this->call('POST', '/orders/' . urlencode($tamaraOrderId) . '/authorise');

            if ($authorised === null) {
                return WebhookOutcome::failed('could not authorise with Tamara');
            }
        }

        return $this->confirmer->confirm(
            $order,
            $this->id(),
            $tamaraOrderId,
            $this->toFils($amount),
            $currency,
            $summary,
        );
    }

    /**
     * Ask Tamara what happened to an order this shop never heard back about.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * ── THE HOLE THIS CLOSES, WHICH IS THE MERCHANT NOT BEING PAID ──────────
     *
     * Everything this class knew about an approval arrived by callback. The
     * shopper is redirected to Tamara, approves, and Tamara POSTs the
     * notification URL handed over at session creation. handleWebhook() does
     * the rest. When that delivery lands, the shop works perfectly.
     *
     * WHEN IT DOES NOT LAND, NOTHING EVER ASKS. The order stays `pending` with
     * `paid_at` NULL for ever. It holds its stock claim and its coupon use, it
     * never appears in a list anybody reads, no capture is possible because
     * PaymentCapturer wants a paid order — and at Tamara's end the buyer was
     * APPROVED, believes they have bought it, and has a payment plan. The shop
     * never ships and never gets paid, and the only evidence is an absence.
     *
     * A delivery goes missing for ordinary reasons, not exotic ones: this host
     * restarting during the POST, a 30-second blip at the egress, Tamara's own
     * retry budget running out, or — the one that has actually bitten this
     * project — a webhook whose URL secret was rotated between session creation
     * and approval, so every gate-1 check rejects a genuine notification.
     *
     * The merchant's own plugin does not rely on the callback either. It sweeps
     * (`forceAuthoriseTamaraOrder`, on cron): every `pending` order with a
     * checkout session and no Tamara order id, going back 180 days, is looked up
     * and authorised. This is that sweep, for one order.
     *
     * ── WHY IT IS SAFE TO RUN OVER AND OVER ─────────────────────────────────
     *
     * It decides nothing itself. It fetches Tamara's own record and hands it to
     * settleFromRemote(), which is the same code the verified webhook runs, so
     * the amount and currency are compared by PaymentConfirmer exactly as they
     * are on a callback and `paid_at` is claimed exactly once. An order that is
     * already paid comes back `ignored`. An order Tamara still reports as `new`
     * is left completely alone.
     *
     * ── THE REFERENCE-ID RECOVERY ───────────────────────────────────────────
     *
     * `transaction_id` is written at the end of start(), after `POST /checkout`
     * has returned. An order can therefore exist at Tamara while this shop holds
     * no id for it: the response was lost, the process died between the call and
     * the save, or the shopper's browser hung up mid-request. Those orders are
     * exactly the ones no callback can rescue either, because handleWebhook()
     * needs an id to verify against.
     *
     * `GET /merchants/orders/reference-id/{ref}` is Tamara's answer and the
     * plugin's SDK carries it (GetOrderByReferenceIdRequestHandler). Our own
     * order number is the reference, so the lookup needs nothing this shop has
     * lost, and the id it returns is saved so the next call is direct.
     */
    public function reconcileAuthorisation(Order $order): WebhookOutcome
    {
        if (! $this->configured()) {
            return WebhookOutcome::failed('tamara is not configured');
        }

        $tamaraOrderId = trim((string) $order->transaction_id);
        $recovered = false;

        if ($tamaraOrderId === '') {
            $byReference = $this->call(
                'GET',
                '/merchants/orders/reference-id/' . urlencode((string) $order->order_number),
            );

            if ($byReference === null) {
                return WebhookOutcome::failed('could not ask tamara about that reference');
            }

            $tamaraOrderId = (string) ($this->stringOrNull($byReference['order_id'] ?? null) ?? '');

            if ($tamaraOrderId === '') {
                return WebhookOutcome::refused('tamara has no order for that reference');
            }

            $recovered = true;
        }

        $remote = $this->call('GET', '/merchants/orders/' . urlencode($tamaraOrderId));

        if ($remote === null) {
            return WebhookOutcome::failed('could not read that order from tamara');
        }

        /*
         * SAVE THE RECOVERED ID ONLY ONCE THE REFERENCE HAS BEEN CHECKED.
         *
         * settleFromRemote() is what compares `order_reference_id` against our
         * order number, and writing the id before that check would staple
         * somebody else's Tamara order to this one permanently on the strength
         * of an unverified lookup. So the guard is repeated here — cheaply, off
         * a response already in hand — and the write happens after it.
         */
        if ($recovered && (string) ($remote['order_reference_id'] ?? '') === (string) $order->order_number) {
            $order->forceFill(['transaction_id' => $tamaraOrderId])->save();
        }

        return $this->settleFromRemote($order, $tamaraOrderId, $remote, [
            'tamara_order_id' => $tamaraOrderId,
            'reference' => (string) $order->order_number,
            'source' => 'sweep',
            'recovered_id' => $recovered,
        ]);
    }

    /* ----------------------------------------------------------- settlement */

    public function captureWindow(): string
    {
        return sprintf(
            'Within about %d days of authorisation. Tamara voids an authorisation that is never captured, and an uncaptured order is one the merchant is never paid for.',
            $this->captureWindowDays(),
        );
    }

    public function captureWindowDays(): ?int
    {
        $configured = (int) $this->credentials->get($this->id(), 'capture_days', (string) self::DEFAULT_CAPTURE_DAYS);

        return $configured > 0 ? $configured : self::DEFAULT_CAPTURE_DAYS;
    }

    /**
     * Take the authorised money.
     *
     * `POST /payments/capture`, with the order id in the BODY rather than the
     * path — Tamara's settlement endpoints are flat, unlike its order
     * endpoints. The response is `{capture_id: ...}`, and that id is what a
     * later refund has to be pointed at.
     *
     * The live order is read first and its `status` decides:
     *
     *   authorised            capturable.
     *   fully_captured        already done. A success on a retry, not an error.
     *   partially_captured    also treated as done here. This build captures
     *                         the whole order in one call and never makes a
     *                         partial one, so a partial capture on the account
     *                         came from elsewhere and silently topping it up
     *                         would be the wrong guess to make with money.
     *   anything else         declined, expired, cancelled. Nothing to take.
     */
    public function capture(Order $order, int $amountFils): SettlementResult
    {
        $tamaraOrderId = trim((string) $order->transaction_id);

        if (! $this->configured() || $tamaraOrderId === '') {
            return SettlementResult::failed(
                'not_configured',
                ['provider' => $this->id()],
                'Tamara is not configured, or this order has no Tamara order on it.',
            );
        }

        $remote = $this->call('GET', '/merchants/orders/' . urlencode($tamaraOrderId));

        if ($remote === null) {
            return SettlementResult::failed(
                'unreachable',
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId],
                'Tamara could not be reached. Nothing was captured; try again.',
            );
        }

        // The same guard the webhook applies: a reference that is not ours
        // means this order id belongs to somebody else's order.
        if ((string) ($remote['order_reference_id'] ?? '') !== (string) $order->order_number) {
            return SettlementResult::failed(
                'reference_mismatch',
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId],
                'Tamara has a different order against that reference. Nothing was captured.',
            );
        }

        $status = strtolower((string) ($remote['status'] ?? ''));

        if (in_array($status, ['fully_captured', 'partially_captured'], true)) {
            return SettlementResult::ok(
                'already_captured',
                $this->existingCaptureId($remote),
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId, 'status' => $status],
                'Tamara had already captured this order.',
            );
        }

        if (! in_array($status, ['authorised', 'authorized'], true)) {
            return SettlementResult::failed(
                'not_authorised',
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId, 'status' => $status],
                'Tamara reports this order as ' . ($status !== '' ? $status : 'unknown') . ', so there is nothing to capture.',
            );
        }

        $currency = strtoupper((string) ($order->currency ?: 'AED'));

        // Balanced against the CAPTURE amount, not the order total — the two
        // are the same today, because PaymentCapturer captures orders.total and
        // nothing else, but the identity Tamara checks is against whatever
        // total_amount this call carries. See amounts().
        $amounts = $this->amounts($order, $amountFils, $currency);

        $attempt = $this->attempt('POST', '/payments/capture', [
            'order_id' => $tamaraOrderId,
            'total_amount' => $this->money($amountFils, $currency),
            'shipping_amount' => $this->money($amounts['shipping'], $currency),
            'tax_amount' => $this->money($amounts['tax'], $currency),
            'discount_amount' => $this->money($amounts['discount'], $currency),
            'items' => $amounts['items'],
            'shipping_info' => [
                'shipped_at' => now()->toAtomString(),
                'shipping_company' => (string) ($order->shipping_method ?: 'Courier'),
            ],
        ]);

        $captureId = $attempt['ok'] ? $this->stringOrNull($attempt['body']['capture_id'] ?? null) : null;

        if (! $attempt['ok'] || $captureId === null) {
            return SettlementResult::failed(
                $attempt['error'] ?? 'capture_rejected',
                [
                    'provider' => $this->id(),
                    'tamara_order_id' => $tamaraOrderId,
                    'http_status' => $attempt['status'],
                    'error' => $attempt['error'],
                ],
                'Tamara refused the capture. Nothing was taken.',
            );
        }

        return SettlementResult::ok(
            'captured',
            $captureId,
            ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId, 'capture_id' => $captureId],
            'Captured through Tamara.',
        );
    }

    /**
     * Refund some or all of a capture.
     *
     * `POST /payments/refund`, shaped as one order id plus a LIST of refunds
     * — Tamara batches them — each pointing at the capture it reverses. We
     * send exactly one per call so that one refund row maps to one provider
     * transaction; batching would make a partial failure inside the batch
     * impossible to attribute to the right row in our own table.
     *
     * The capture id is required, so a refund before capture is reported as
     * `not_captured` rather than as a generic failure: the fix is to capture
     * first, and no other message says so.
     */
    public function refund(
        Order $order,
        int $amountFils,
        ?string $reason,
        ?string $captureRef,
        ?string $idempotencyKey = null,
    ): SettlementResult
    {
        $tamaraOrderId = trim((string) $order->transaction_id);

        if (! $this->configured() || $tamaraOrderId === '') {
            return SettlementResult::failed(
                'not_configured',
                ['provider' => $this->id()],
                'Tamara is not configured, or this order has no Tamara order on it.',
            );
        }

        $captureId = trim((string) ($order->capture_ref ?? ''));

        if ($captureId === '') {
            return SettlementResult::failed(
                'not_captured',
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId],
                'This Tamara order has not been captured, so there is nothing to refund yet. Capture it first.',
            );
        }

        $currency = strtoupper((string) ($order->currency ?: 'AED'));

        $attempt = $this->attempt('POST', '/payments/refund', [
            'order_id' => $tamaraOrderId,
            'refunds' => [[
                'capture_id' => $captureId,
                'total_amount' => $this->money($amountFils, $currency),
                'shipping_amount' => $this->money(0, $currency),
                'tax_amount' => $this->money(0, $currency),
                'discount_amount' => $this->money(0, $currency),
                'items' => [],
                'comment' => $reason !== null && trim($reason) !== '' ? trim($reason) : 'Merchant refund',
            ]],
        ]);

        $refundId = $attempt['ok']
            ? $this->stringOrNull($attempt['body']['refunds'][0]['refund_id'] ?? null)
            : null;

        if (! $attempt['ok'] || $refundId === null) {
            return SettlementResult::failed(
                $attempt['error'] ?? 'refund_rejected',
                [
                    'provider' => $this->id(),
                    'tamara_order_id' => $tamaraOrderId,
                    'http_status' => $attempt['status'],
                    'error' => $attempt['error'],
                ],
                'Tamara refused the refund. Nothing has been returned to the customer.',
            );
        }

        return SettlementResult::ok(
            'refunded',
            $refundId,
            ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId, 'refund_id' => $refundId],
            'Refunded through Tamara.',
        );
    }

    /**
     * Release an authorisation Tamara is still holding.
     *
     * `POST /orders/{id}/cancel`, with the order's own figures echoed back in
     * the body — Tamara validates the amount block on a cancel exactly as it
     * does on a capture, so this goes through amounts() for the same reason and
     * balances the same way. The response is `{order_id, cancel_id}`.
     *
     * THREE REFUSALS BEFORE THE CALL, each with its own code because the
     * operator's next move differs:
     *
     *   not_configured      no keys, or no Tamara order on this order.
     *   already_captured    the money has moved. A cancel cannot take it back
     *                       and Tamara would refuse; the answer is a refund,
     *                       and this is the one message that says so. Read off
     *                       our OWN `capture_ref`, which is the column
     *                       PaymentCapturer writes only after Tamara confirmed
     *                       the capture — the same test the plugin makes
     *                       (`if (empty($captureId) && $tamaraOrderId)`).
     *   reference_mismatch  the Tamara order behind that id belongs to someone
     *                       else's order. Same guard capture() and the webhook
     *                       make, for the same reason.
     *
     * AND ONE AFTER THE READ: a Tamara order already `canceled` is a success,
     * not a failure. That is what makes this safe to call twice — which it
     * will be, because a cancel can reach an order from the order screen, from
     * the orders list and from a customer-service action on the same afternoon.
     *
     * A CAPTURED ORDER IS DETECTED TWICE, deliberately: once from our column
     * before the network call, and once from Tamara's own `status` after it.
     * The first is what makes the common case free; the second is what catches
     * a capture made in Tamara's dashboard that this shop has never seen, which
     * is exactly the case where blindly cancelling would be worst.
     */
    public function void(Order $order, int $amountFils): SettlementResult
    {
        $tamaraOrderId = trim((string) $order->transaction_id);

        if (! $this->configured() || $tamaraOrderId === '') {
            return SettlementResult::failed(
                'not_configured',
                ['provider' => $this->id()],
                'Tamara is not configured, or this order has no Tamara order on it.',
            );
        }

        if (trim((string) ($order->capture_ref ?? '')) !== '') {
            return SettlementResult::failed(
                'already_captured',
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId],
                'This Tamara payment has already been captured, so the authorisation cannot be released. Refund it instead.',
            );
        }

        $remote = $this->call('GET', '/merchants/orders/' . urlencode($tamaraOrderId));

        if ($remote === null) {
            return SettlementResult::failed(
                'unreachable',
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId],
                'Tamara could not be reached. The authorisation is still live; try again.',
            );
        }

        if ((string) ($remote['order_reference_id'] ?? '') !== (string) $order->order_number) {
            return SettlementResult::failed(
                'reference_mismatch',
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId],
                'Tamara has a different order against that reference. Nothing was cancelled.',
            );
        }

        $status = strtolower((string) ($remote['status'] ?? ''));

        // Tamara spells it with one L. Both are accepted rather than picking
        // one and being wrong on a response this project cannot test against a
        // live account -- the same reason authorised/authorized are both read
        // everywhere else in this class.
        if (in_array($status, ['canceled', 'cancelled'], true)) {
            return SettlementResult::ok(
                'already_voided',
                $this->stringOrNull($remote['cancel_id'] ?? null),
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId, 'status' => $status],
                'Tamara had already cancelled this order.',
            );
        }

        if (in_array($status, ['fully_captured', 'partially_captured'], true)) {
            return SettlementResult::failed(
                'already_captured',
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId, 'status' => $status],
                'Tamara reports this order as captured, so the authorisation cannot be released. Refund it instead.',
            );
        }

        /*
         * Expired and declined orders hold nothing. Reported as a success with
         * its own code rather than as a failure: the authorisation this call
         * was asked to release is gone, which is the outcome the caller wanted,
         * and an error here would leave an operator chasing a release that
         * already happened by itself.
         */
        if (in_array($status, ['expired', 'declined'], true)) {
            return SettlementResult::ok(
                'nothing_to_void',
                null,
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId, 'status' => $status],
                'Tamara reports this order as ' . $status . ', so there was no authorisation left to release.',
            );
        }

        $currency = strtoupper((string) ($order->currency ?: 'AED'));
        $amounts = $this->amounts($order, $amountFils, $currency);

        $attempt = $this->attempt('POST', '/orders/' . urlencode($tamaraOrderId) . '/cancel', [
            'total_amount' => $this->money($amountFils, $currency),
            'shipping_amount' => $this->money($amounts['shipping'], $currency),
            'tax_amount' => $this->money($amounts['tax'], $currency),
            'discount_amount' => $this->money($amounts['discount'], $currency),
            'items' => $amounts['items'],
        ]);

        $cancelId = $attempt['ok'] ? $this->stringOrNull($attempt['body']['cancel_id'] ?? null) : null;

        if (! $attempt['ok']) {
            return SettlementResult::failed(
                $attempt['error'] ?? 'void_rejected',
                [
                    'provider' => $this->id(),
                    'tamara_order_id' => $tamaraOrderId,
                    'http_status' => $attempt['status'],
                    'error' => $attempt['error'],
                ],
                'Tamara refused to cancel this order. The authorisation is still live.',
            );
        }

        /*
         * A 2xx with no cancel_id is still a cancel. Unlike a capture — where
         * the id is the handle a later refund has to point at, so its absence
         * makes the capture unusable and capture() treats it as a failure —
         * nothing downstream needs a cancel id. Failing here would report a
         * released authorisation as still live, which is the more expensive of
         * the two mistakes: it invites a second attempt on an order Tamara has
         * already closed.
         */
        return SettlementResult::ok(
            'voided',
            $cancelId,
            ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId, 'cancel_id' => $cancelId],
            'The Tamara authorisation was released.',
        );
    }

    /* ------------------------------------------------ provider-side settings */

    /**
     * Ask Tamara for this market's basket limits and store them.
     *
     * `GET /checkout/payment-types?country=&currency=` returns one block per
     * payment type with `min_limit` and `max_limit` as {amount, currency} in
     * major units. The block for THIS shop's configured payment type is the one
     * that decides, because that is the type start() will ask for — reading
     * PAY_BY_LATER's limits on a shop configured for PAY_BY_INSTALMENTS would
     * store a range that is right for a product the shop does not sell.
     *
     * Written to `min_limit` / `max_limit`, which are the same two settings the
     * owner can type into by hand, so availableFor() has one source of truth
     * and not two. Returns what it stored so the caller can show it; returns
     * null when Tamara could not be read, and writes NOTHING in that case —
     * a failed refresh must leave the working limits alone rather than clear
     * them, which would take Tamara off every basket or put it on all of them.
     *
     * @return array{min: string, max: string, payment_type: string, country: string, currency: string}|null
     */
    public function refreshLimits(?string $country = null, ?string $currency = null): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        // The shop's own configured currency when the caller names none —
        // App\Support\Money is where that lives, and it falls back to AED
        // itself, so there is no second fallback to get wrong here.
        $currency = strtoupper(trim((string) ($currency ?: \App\Support\Money::currency())));
        $country = strtoupper(trim((string) ($country ?: (self::CURRENCY_COUNTRY[$currency] ?? 'AE'))));

        if (! in_array($country, self::COUNTRIES, true)) {
            return null;
        }

        $attempt = $this->attempt('GET', '/checkout/payment-types?' . http_build_query([
            'country' => $country,
            'currency' => $currency,
        ]));

        if (! $attempt['ok'] || ! is_array($attempt['body'])) {
            return null;
        }

        $wanted = $this->paymentType();
        $block = null;

        // The response is a list of payment types. Keyed by `name`, and read by
        // name rather than by position -- the order is not documented and a
        // shop reading element 0 would silently take PAY_NOW's limits the day
        // Tamara reorders them.
        foreach ($this->orderList($attempt['body']) as $candidate) {
            if (is_array($candidate) && strtoupper((string) ($candidate['name'] ?? '')) === $wanted) {
                $block = $candidate;

                break;
            }
        }

        if ($block === null) {
            return null;
        }

        $min = $block['min_limit']['amount'] ?? null;
        $max = $block['max_limit']['amount'] ?? null;

        if (! is_numeric($min) || ! is_numeric($max)) {
            return null;
        }

        // Stored as the major-unit strings the settings hold, through toMajor()
        // so "100" and "100.0" and 100.0 all land as "100.00".
        $stored = [
            'min_limit' => $this->toMajor($this->toFils($min)),
            'max_limit' => $this->toMajor($this->toFils($max)),
        ];

        $this->credentials->save($this->id(), $stored);

        $this->log('basket limits refreshed', '/checkout/payment-types', $attempt['status'], [
            'country' => $country,
            'payment_type' => $wanted,
        ]);

        return [
            'min' => $stored['min_limit'],
            'max' => $stored['max_limit'],
            'payment_type' => $wanted,
            'country' => $country,
            'currency' => $currency,
        ];
    }

    /**
     * Register this shop's webhook endpoint with Tamara.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * THIS CLOSES A GAP THAT MADE WORKING CODE UNREACHABLE. handleWebhook() has
     * always handled `order_expired` and `order_declined` — and Tamara never
     * sent either, because nothing had ever registered a webhook. The
     * `merchant_url.notification` handed over at session creation carries the
     * IPN (the approval), and that is a different channel: expiry and decline
     * are delivered only to an endpoint registered through `POST /webhooks`.
     *
     * So the decline path was dead. An order the buyer was refused for sat
     * `pending` for ever, holding its stock claim and its coupon use, and the
     * only trace was its absence. The code to handle it was written, reviewed
     * and tested; the registration it depended on did not exist.
     *
     * Idempotent at this end: a stored `webhook_id` is returned rather than a
     * second registration created, because two registrations mean every expiry
     * delivered twice and Tamara offers no "replace" call.
     *
     * NO HEADERS ARE SENT. The SDK's RegisterWebhookRequest can carry arbitrary
     * headers and the temptation is to put a shared secret in one. It is not
     * needed and it would be the weakest link in the chain: the URL already
     * carries an unguessable secret that handleWebhook() compares with
     * hash_equals, and the body is signed with an HS256 JWT that is verified
     * against the notification token. A third secret stored at the provider
     * adds nothing to either and adds one more thing that can leak.
     *
     * @return array{ok: bool, webhook_id: string|null, error: string|null, created: bool}
     */
    public function registerWebhook(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'webhook_id' => null, 'error' => 'not_configured', 'created' => false];
        }

        $existing = $this->credentials->get($this->id(), 'webhook_id');

        if ($existing !== '') {
            return ['ok' => true, 'webhook_id' => $existing, 'error' => null, 'created' => false];
        }

        $secret = $this->credentials->get($this->id(), 'webhook_secret');

        if ($secret === '') {
            // Registering the endpoint without its secret would hand Tamara a
            // URL that handleWebhook() rejects on its first gate.
            return ['ok' => false, 'webhook_id' => null, 'error' => 'no_webhook_secret', 'created' => false];
        }

        $attempt = $this->attempt('POST', '/webhooks', [
            'url' => $this->webhookUrl(),
            'events' => self::WEBHOOK_EVENTS,
            'headers' => [],
        ]);

        $id = $attempt['ok'] ? $this->stringOrNull($attempt['body']['webhook_id'] ?? null) : null;

        if (! $attempt['ok'] || $id === null) {
            return [
                'ok' => false,
                'webhook_id' => null,
                'error' => $attempt['error'] ?? 'register_failed',
                'created' => false,
            ];
        }

        $this->credentials->save($this->id(), ['webhook_id' => $id]);

        $this->log('webhook registered', '/webhooks', $attempt['status'], ['webhook_id' => $id]);

        return ['ok' => true, 'webhook_id' => $id, 'error' => null, 'created' => true];
    }

    /**
     * Remove the registration again.
     *
     * `DELETE /webhooks/{id}`. The stored id is cleared on success AND on a 404
     * — a registration Tamara has never heard of is one this shop should stop
     * claiming to have, and leaving the id behind after a 404 means the
     * register button above refuses for ever on the strength of a webhook that
     * does not exist.
     *
     * @return array{ok: bool, error: string|null}
     */
    public function unregisterWebhook(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        $id = $this->credentials->get($this->id(), 'webhook_id');

        if ($id === '') {
            // Nothing registered. A success: the caller asked for there to be
            // no webhook and there is none.
            return ['ok' => true, 'error' => null];
        }

        $attempt = $this->attempt('DELETE', '/webhooks/' . urlencode($id));

        if ($attempt['ok'] || $attempt['status'] === 404) {
            $this->credentials->save($this->id(), ['webhook_id' => null]);

            $this->log('webhook removed', '/webhooks', $attempt['status'], ['webhook_id' => $id]);

            return ['ok' => true, 'error' => null];
        }

        return ['ok' => false, 'error' => $attempt['error'] ?? 'delete_failed'];
    }

    /* -------------------------------------------------------- reconciliation */

    /**
     * Tamara's books, a page at a time.
     *
     *   GET /merchants/orders?startDate=&endDate=&offset=&limit=
     *
     * The same collection the webhook and capture already read one member of
     * (`/merchants/orders/{id}`), which is why the parsing below shares its
     * field names with existingCaptureId() and with handleWebhook():
     * `order_reference_id` is our order number, `total_amount` is
     * {amount, currency} in MAJOR units, and settlement lives under
     * `transactions.captures[]` and `transactions.refunds[]`.
     *
     * REFUNDS COME OUT OF THE SAME LIST, for the same reason they do on Tabby:
     * Tamara keeps a refund inside the order it belongs to. refund() sends one
     * `capture_id` and reads back `refunds[0].refund_id`; there is no separate
     * collection of refunds to page.
     *
     * WHICH HOST. baseUrl() resolves the live/sandbox switch, so this reads the
     * same books the checkout writes to. That is not a detail: sandbox keys
     * pointed at the live host (or the reverse) is the commonest go-live
     * failure on this gateway, and a reconciliation that read the sandbox would
     * report every real payment in the window as money the provider has never
     * heard of. remoteSourceLabel() prints the host it actually used so the
     * screen can show which one answered.
     *
     * THE LIST FORM IS NOT PROVEN HERE, exactly as on Tabby — the by-id read is
     * exercised by the webhook, the collection read is not, and this project
     * has no Tamara merchant account to settle it with. It is confined to this
     * method, and a wrong path returns RemotePage::failed(), which makes the
     * run say "Tamara's list of orders could not be read" and skip every
     * conclusion that depends on having read it. See RemotePage.
     */
    public function listRemotePayments(ReconcileWindow $window, ?string $cursor, int $limit): RemotePage
    {
        return $this->listTamara($window, $cursor, $limit, RemoteTxn::PAYMENT);
    }

    public function listRemoteRefunds(ReconcileWindow $window, ?string $cursor, int $limit): RemotePage
    {
        return $this->listTamara($window, $cursor, $limit, RemoteTxn::REFUND);
    }

    public function remoteSourceLabel(): string
    {
        return rtrim($this->baseUrl(), '/') . '/merchants/orders (refunds are nested in each order)';
    }

    private function listTamara(ReconcileWindow $window, ?string $cursor, int $limit, string $kind): RemotePage
    {
        if (! $this->configured()) {
            return RemotePage::unsupported('Tamara has no API token stored, so its books cannot be read.');
        }

        $limit = max(1, min($limit, 100));
        $offset = $cursor !== null && ctype_digit(trim($cursor)) ? (int) trim($cursor) : 0;

        $attempt = $this->attempt('GET', '/merchants/orders?' . http_build_query([
            'startDate' => $window->from->toIso8601ZuluString(),
            'endDate' => $window->to->toIso8601ZuluString(),
            'offset' => $offset,
            'limit' => $limit,
        ]));

        if (! $attempt['ok']) {
            return RemotePage::failed($attempt['error'] ?? 'unreachable', $attempt['status']);
        }

        $orders = $this->orderList($attempt['body']);
        $items = [];

        foreach ($orders as $remote) {
            if (! is_array($remote)) {
                continue;
            }

            if ($kind === RemoteTxn::PAYMENT) {
                $txn = $this->tamaraOrderToTxn($remote);

                if ($txn !== null) {
                    $items[] = $txn;
                }

                continue;
            }

            foreach ($this->tamaraRefundsToTxns($remote) as $refund) {
                $items[] = $refund;
            }
        }

        // The cursor counts ORDERS in both modes. See the same note on Tabby:
        // advancing by the number of refunds found would skip orders in
        // proportion to how many refunds they happened to carry.
        $more = count($orders) >= $limit;

        return RemotePage::of($items, $more ? (string) ($offset + count($orders)) : null);
    }

    private function orderList(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        foreach (['data', 'orders', 'results', 'content'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                return $body[$key];
            }
        }

        return array_is_list($body) ? $body : [];
    }

    private function tamaraOrderToTxn(array $remote): ?RemoteTxn
    {
        $id = $this->stringOrNull($remote['order_id'] ?? null);

        if ($id === null) {
            return null;
        }

        $status = strtolower((string) ($remote['status'] ?? ''));

        /*
         * Captured is money. Authorised is money Tamara is holding and will
         * release if nobody takes it — its own merchant plugin sweeps for
         * exactly this up to 180 days out. `new`, `approved` and `declined` are
         * all short of a commitment; `expired` and `canceled` are past one.
         *
         * `partially_captured` counts as settled and the amount compared is the
         * order total, which will disagree with a short capture. That
         * disagreement is a finding worth having rather than a false one: a
         * partially captured Tamara order IS a case where the two sides hold
         * different figures, and it is invisible from the orders list.
         */
        $state = match ($status) {
            'fully_captured', 'partially_captured' => RemoteTxn::SETTLED,
            'authorised', 'authorized' => RemoteTxn::AUTHORISED,
            default => RemoteTxn::DEAD,
        };

        return new RemoteTxn(
            provider: $this->id(),
            kind: RemoteTxn::PAYMENT,
            remoteId: $id,
            matchKeys: [],
            reference: $this->stringOrNull($remote['order_reference_id'] ?? null),
            // Major units on this API, through toFils() and its round(). Never
            // an (int) cast on a float.
            amountFils: $this->toFils($remote['total_amount']['amount'] ?? 0),
            currency: strtoupper((string) ($remote['total_amount']['currency'] ?? '')),
            state: $state,
            rawState: $status,
            createdAt: $this->tamaraMoment($remote['created_at'] ?? null),
        );
    }

    /** @return array<int, RemoteTxn> */
    private function tamaraRefundsToTxns(array $remote): array
    {
        $refunds = $remote['transactions']['refunds'] ?? null;

        if (! is_array($refunds)) {
            return [];
        }

        $reference = $this->stringOrNull($remote['order_reference_id'] ?? null);
        $currency = strtoupper((string) ($remote['total_amount']['currency'] ?? ''));
        $out = [];

        foreach ($refunds as $refund) {
            if (! is_array($refund)) {
                continue;
            }

            $id = $this->stringOrNull($refund['refund_id'] ?? null);

            if ($id === null) {
                continue;
            }

            $out[] = new RemoteTxn(
                provider: $this->id(),
                kind: RemoteTxn::REFUND,
                remoteId: $id,
                matchKeys: [],
                reference: $reference,
                amountFils: $this->toFils(
                    $refund['total_amount']['amount'] ?? ($refund['amount'] ?? 0)
                ),
                currency: strtoupper((string) ($refund['total_amount']['currency'] ?? $currency)),
                state: RemoteTxn::SETTLED,
                rawState: 'refunded',
                createdAt: $this->tamaraMoment($refund['created_at'] ?? null),
                // The Tamara order this refund sits inside, which is what
                // `payments.provider_ref` holds for this gateway.
                parentKeys: [(string) ($remote['order_id'] ?? '')],
            );
        }

        return $out;
    }

    private function tamaraMoment(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** The capture id off an already-captured order, if Tamara volunteered one. */
    private function existingCaptureId(array $remote): ?string
    {
        return $this->stringOrNull(
            $remote['transactions']['captures'][0]['capture_id']
                ?? ($remote['capture_id'] ?? null)
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * The four figures Tamara adds up, made to add up.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Every amount block Tamara is sent — on `/checkout`, on
     * `/payments/capture`, on `/orders/{id}/cancel` — is validated against one
     * identity, and the call is refused when it does not hold:
     *
     *     total_amount = Σ items[].total_amount
     *                  + shipping_amount
     *                  + tax_amount
     *                  − discount_amount
     *
     * THE DEFECT THIS METHOD EXISTS TO FIX. The payload used to send
     * `tax_amount = orders.tax_total` alongside item totals that already
     * contained that tax. On this shop the two are the same money whenever VAT
     * is INCLUSIVE, which is the basis the owner said he would use for the UAE
     * in as many words ("for uae the vat i can set inclusive, for Saudi i can
     * set exclusive"). Work it through with a AED 100 basket, 5% inclusive,
     * free delivery:
     *
     *     orders.total      10000   (the tax is already inside it)
     *     orders.tax_total     476   (VatDisplay records what was inside)
     *     Σ items            10000
     *
     *     10000 + 0 + 476 − 0  =  10476  ≠  10000
     *
     * Tamara rejects that session. Not one order — EVERY order, from the
     * moment the owner sets Store → Ecommerce → Tax → tax_mode to `live` with
     * one inclusive country. And it would have presented as "Tamara stopped
     * working when we turned on tax", with nothing in the Tamara payload log to
     * read because RemoteGateway deliberately never logs bodies.
     *
     * HOW THE FIGURE IS DERIVED, RATHER THAN GUESSED. Rearranging the identity
     * for the one term this shop does not separately hold:
     *
     *     tax_amount − discount_amount = total − Σ items − shipping
     *
     * The right-hand side is three columns this order already carries, so the
     * residual is computed and not assumed. It is non-negative in every
     * arrangement this shop can produce — it comes out as the COD surcharge
     * plus any tax that was ADDED on top (`orders.fee_total` plus an exclusive
     * `tax_total`) — and the negative branch is written anyway, because an
     * imported or hand-built order whose line totals exceed its own total is
     * not this method's business to refuse. Either way the block balances.
     *
     * WHAT THIS CHANGES ON THE SHIPPED SHOP: nothing, to the fil. In the
     * default `tax_mode = 'display'` the order has tax_total 0 and no fee, so
     * the residual is exactly 0 and the block sent is byte-identical to the one
     * sent before — which is what TamaraAmountsAddUpTest pins first, before it
     * pins the inclusive case.
     *
     * @param  int  $totalFils  the total to balance against — the order total on
     *                          checkout, the capture amount on a capture.
     * @return array{items: array, items_fils: int, shipping: int, tax: int, discount: int}
     */
    private function amounts(Order $order, int $totalFils, string $currency): array
    {
        $items = $this->items($order, $currency);

        // Read off the blocks actually being sent, not recomputed from the
        // order: if items() ever changes how a line total is derived, the sum
        // has to follow it or the identity breaks again in a new place.
        $itemsFils = 0;

        foreach ($items as $item) {
            $itemsFils += $this->toFils($item['total_amount']['amount'] ?? 0);
        }

        $shipping = max(0, (int) $order->shipping_total);
        $discount = max(0, (int) $order->discount_total);

        $residual = $totalFils - $itemsFils - $shipping + $discount;

        if ($residual >= 0) {
            // tax − discount = total − items − shipping. Holds by construction.
            return [
                'items' => $items,
                'items_fils' => $itemsFils,
                'shipping' => $shipping,
                'tax' => $residual,
                'discount' => $discount,
            ];
        }

        /*
         * The lines come to more than the total. Nothing this shop's checkout
         * produces lands here; a hand-built or imported order can. The balance
         * is taken on the discount side, because the alternative is a negative
         * tax_amount and Tamara has no such thing.
         */
        return [
            'items' => $items,
            'items_fils' => $itemsFils,
            'shipping' => $shipping,
            'tax' => 0,
            'discount' => $discount - $residual,
        ];
    }

    /**
     * What this shop knows about the buyer, for Tamara's risk engine.
     *
     * ONE QUERY, and deliberately so — this runs inside start(), which is on the
     * path of a shopper pressing Place order, and CLAUDE.md rule 4 asks for the
     * slope rather than a total. The seven facts below are seven conditional
     * aggregates over one index scan rather than seven round trips.
     *
     * WHAT IS SENT: counts, sums and dates derived from this shop's own order
     * table for this buyer. WHAT IS NOT: any other buyer's data, any order
     * detail, any address, and nothing at all from a request. The identity is
     * taken from the ORDER — its customer_id when it has one, its email
     * otherwise — never from anything a browser could put in front of this
     * method, so a guest checkout cannot claim somebody else's history by
     * typing their address in.
     *
     * Orders still in flight are not history. `Order::REAL_STATUSES` is the
     * shop's own definition of an order that counted, and it is what the
     * dashboard and the exports already use — a `pending` order is a basket
     * that reached the payment page, and counting those would tell Tamara this
     * buyer has eleven orders when they have one and ten abandoned attempts.
     *
     * @return array<string, mixed>
     */
    private function riskAssessment(Order $order, string $currency): array
    {
        $customerId = $order->customer_id !== null ? (int) $order->customer_id : null;
        $email = trim((string) $order->email);

        if ($customerId === null && $email === '') {
            // A brand-new buyer with nothing to look up. An empty block is
            // omitted rather than sent full of nulls: "I know nothing about
            // this person" and "this person has zero delivered orders" are
            // different claims and only the first one is true.
            return [];
        }

        $threeMonthsAgo = CarbonImmutable::now()->subMonths(3);

        $row = Order::query()
            ->when(
                $customerId !== null,
                fn ($q) => $q->where('customer_id', $customerId),
                fn ($q) => $q->where('email', $email),
            )
            ->whereKeyNot($order->getKey())
            ->whereIn('status', Order::REAL_STATUSES)
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('MIN(created_at) as first_at')
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as delivered', ['shipped', 'completed'])
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as recent_count', [$threeMonthsAgo])
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN total ELSE 0 END) as recent_fils', [$threeMonthsAgo])
            ->first();

        $orderCount = (int) ($row->order_count ?? 0);
        $firstAt = $this->tamaraMoment($row->first_at ?? null);

        $assessment = [
            'is_existing_customer' => $orderCount > 0,
            'total_order_count' => $orderCount,
            'has_delivered_order' => (int) ($row->delivered ?? 0) > 0,
            'order_count_last3months' => (int) ($row->recent_count ?? 0),
            // Major units, like every other amount on this API. Through money()
            // so the shape matches and the rounding is the one rounding.
            'order_amount_last3months' => $this->money((int) ($row->recent_fils ?? 0), $currency),
        ];

        if ($firstAt !== null) {
            $assessment['date_of_first_transaction'] = $firstAt->toAtomString();
        }

        /*
         * The account, when there is one. A guest checkout has no account, and
         * `account_creation_date` for a guest is not "today" — there is no
         * account. Omitted rather than filled with the order's own date, which
         * would tell Tamara every guest is a brand-new account and is the sort
         * of plausible-looking lie a risk engine is entitled to act on.
         */
        $customer = $customerId !== null ? $order->customer : null;

        if ($customer !== null) {
            if ($customer->created_at !== null) {
                $assessment['account_creation_date'] = $customer->created_at->toAtomString();
            }

            $assessment['is_email_verified'] = $customer->email_verified_at !== null;
        }

        return $assessment;
    }

    /**
     * The locale for Tamara's hosted pages.
     *
     * This shop is bilingual and this field was the constant `'en_US'`, so an
     * Arabic shopper was handed an English Tamara checkout in the middle of an
     * Arabic order. Mapped from a fixed table rather than by string-building
     * `$locale . '_' . strtoupper($locale)` — that would turn an unexpected
     * app locale into a made-up locale like `fr_FR` on an account that has
     * never been enabled for it, and English is the safe answer to a question
     * this method cannot answer.
     */
    private function locale(): string
    {
        return match (strtolower(substr((string) app()->getLocale(), 0, 2))) {
            'ar' => 'ar_SA',
            default => 'en_US',
        };
    }

    /**
     * Which market Tamara should score this order in.
     *
     * The order's own billing country first, because that is where the buyer
     * is and it is the answer the risk engine wants. The shop currency second
     * — the plugin uses this and only this (`getCurrencyToCountryMapping`),
     * and it is the only signal left when an address carries no country. `AE`
     * last, which is what this method returned unconditionally before the
     * currency step existed: a SAR order with an incomplete address was being
     * scored in the wrong market.
     */
    private function countryCode(Order $order, array $billing, string $currency): string
    {
        $fromAddress = strtoupper(trim((string) ($billing['country'] ?? '')));

        if ($fromAddress !== '') {
            return $fromAddress;
        }

        return self::CURRENCY_COUNTRY[strtoupper($currency)] ?? 'AE';
    }

    /** Tamara wants {amount, currency}, amount in major units. */
    private function money(int $fils, string $currency): array
    {
        return ['amount' => (float) $this->toMajor($fils), 'currency' => $currency];
    }

    private function items(Order $order, string $currency): array
    {
        return $order->items->map(fn ($item) => [
            'reference_id' => (string) $item->id,
            'type' => 'Physical',
            'name' => (string) $item->name,
            'sku' => (string) ($item->sku ?: $item->product_id),
            'quantity' => (int) $item->quantity,
            'unit_price' => $this->money((int) $item->unit_price, $currency),
            'discount_amount' => $this->money(0, $currency),
            'tax_amount' => $this->money(0, $currency),
            'total_amount' => $this->money((int) $item->total, $currency),
        ])->values()->all();
    }

    private function address(array $a): array
    {
        return [
            'first_name' => (string) ($a['first_name'] ?? ''),
            'last_name' => (string) ($a['last_name'] ?? ''),
            'line1' => (string) ($a['line1'] ?? ''),
            'city' => (string) ($a['city'] ?? ''),
            'region' => (string) ($a['state'] ?? ''),
            'country_code' => strtoupper((string) ($a['country'] ?? 'AE')),
            'phone_number' => (string) ($a['phone'] ?? ''),
        ];
    }

    /**
     * The notification URL Tamara is handed at session creation. Must match
     * the route in routes/payments-webhooks.php, secret included.
     */
    private function webhookUrl(): string
    {
        return url(\App\Support\Url::external('/api/payments/webhook/tamara/'))
            . $this->credentials->get($this->id(), 'webhook_secret');
    }
}
