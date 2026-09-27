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
use App\Support\Locale;
use App\Support\Url;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Tabby — buy now, pay later.
 *
 * Shapes taken from the merchant's working WooCommerce plugin, which is the
 * only reliable description of this API; the implementation is our own.
 *
 *   POST /api/v2/checkout          create a session, get a hosted web_url
 *   GET  /api/v2/payments/{id}     the authoritative state of a payment
 *   GET  /api/v2/payments?…        the same collection, filtered and paged
 *   POST /api/v1/payments/{id}/captures   take the money after authorisation
 *   POST /api/v1/payments/{id}/refunds    give some or all of it back
 *   POST /api/v1/payments/{id}/close      VOID an authorisation nobody captured
 *   GET/POST/PUT/DELETE /api/v1/webhooks  where Tabby is told to call us
 *   Note the version split, which is not a convention but a rule the plugin
 *   encodes literally: a GET is v2 unless it is `webhooks`, `checkout` is v2
 *   although it is a POST, and every other write is v1.
 *   Auth: Authorization: Bearer <secret_key>, plus X-Merchant-Code per country
 *   Statuses: CREATED, AUTHORIZED, CLOSED, REJECTED, EXPIRED
 *   Amounts: decimal strings in major units
 *
 * ---------------------------------------------------------------------------
 * CLOSED MEANS TWO DIFFERENT THINGS AND THE DIFFERENCE IS THE WHOLE SALE
 *
 * Tabby has no VOIDED status. `POST /payments/{id}/close` voids an
 * authorisation and a successful capture also ends at CLOSED, so the status
 * alone cannot tell "the money was taken" from "the hold was released and
 * nobody was charged". The only thing that separates them is whether
 * `captures[]` carries an entry.
 *
 * Three places in this class used to read CLOSED as "captured" flatly, and each
 * one turned a void into a lie in a different direction:
 *
 *   - handleWebhook() marked the order PAID on the close notice, so an
 *     authorisation the merchant had just released came back as a live,
 *     paid, stock-committed order;
 *   - capture() answered `already_captured`, which is an ok() — PaymentCapturer
 *     writes `captured_at`, `captured_total` and a null `capture_ref` on an ok,
 *     so the order read as fully captured with nothing behind it and the next
 *     refund was measured against a ceiling that did not exist;
 *   - listRemotePayments() reported it SETTLED, so reconciliation found Tabby
 *     money against an unpaid order and said the two agreed.
 *
 * Every one of them now asks `captures[]` first. hasCapture() is the single
 * reader and the mutation note on TabbyGatewayTest's void cases is "make
 * hasCapture() return true unconditionally".
 *
 * ---------------------------------------------------------------------------
 * ONE TABBY PRODUCT, NAMED
 *
 * Tabby sells three: `installments` (pay in 4), `payLater` (pay in 14 days)
 * and `creditCardInstallments`. The merchant's own plugin ships all three as
 * gateway classes and then overrides `is_available()` to return a flat FALSE on
 * payLater and on creditCardInstallments — so the store it was taken from
 * offers exactly one, `installments`, and the other two are code that cannot be
 * reached.
 *
 * That matters here because `available_products` is a MAP keyed by product, and
 * this class used to walk it and take the first `web_url` it found. Tabby
 * offering a product this merchant has no agreement for was therefore enough to
 * send a shopper into a Pay-in-14-days flow from a radio button that says pay
 * in 4 — a different contract, a different repayment schedule, and one nobody
 * on this side chose. The product is named now, and a response that does not
 * carry it is a decline.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS DELIBERATELY NOT HERE, AND WHY
 *
 *   - **Datadog telemetry.** Every logged event in the plugin also goes to
 *     `logs.browser-intake-datadoghq.eu` under a hard-coded API key, carrying
 *     the store hostname, the full request URL, the request BODY (buyer name,
 *     email, phone, address) and the response body. None of that leaves this
 *     server. RemoteGateway::log() is the only logger and it writes no bodies.
 *   - **The product feed.** `WC_Tabby_Feed_Sharing` is ON by default in the
 *     plugin and pushes the whole catalogue to `plugins-api.tabby.ai`,
 *     registering itself by POSTing the merchant's SECRET KEY to that host.
 *     A shop's secret key may not travel anywhere but api.tabby.ai, and its
 *     catalogue is not Tabby's to hold. Refused outright, not made optional.
 *   - **The promo widget.** `tabby-promo.js`, third-party script on every
 *     product and cart page, configured with the signed-in customer's email and
 *     every phone number on their user record. The storefront already prints a
 *     Tabby payment mark (App\Support\PaymentMarkArt) with no third-party
 *     request and no customer data in it.
 *   - **The unpaid-order cron.** The plugin's cron CANCELS and then DELETES or
 *     trashes unpaid orders after `order_timeout` minutes, force-delete being
 *     the default. Destroying a customer's order record on a timer is not a
 *     behaviour to port; abandoned Tabby orders stay `pending` here and
 *     reconciliation is what finds them.
 *   - **A native refund idempotency key.** Tabby documents no idempotency
 *     header and the plugin sends none, so $idempotencyKey is accepted and
 *     unused. The unique index behind PaymentRefunder is the real guard.
 *
 * ---------------------------------------------------------------------------
 * How the webhook is trusted, which is the part that matters
 *
 * Tabby's own plugin registers its webhook endpoint with
 * `permission_callback => '__return_true'` — the callback is completely
 * unauthenticated — and then re-fetches the payment from the API before
 * believing anything. That is the right instinct and it is what this does too,
 * but on its own it makes the endpoint a free oracle: anyone can POST an id
 * and make us call Tabby.
 *
 * So there are two independent checks here, and BOTH must pass:
 *
 *   1. A shared secret in the URL, compared with hash_equals. It is generated
 *      once, stored in the encrypted config, and is what makes the endpoint
 *      address unguessable rather than merely obscure. This is what rejects an
 *      unsigned or wrongly-signed request.
 *   2. The payment is re-fetched from Tabby over an authenticated GET, and the
 *      amount, currency, reference and status in THAT response — never the
 *      ones in the POST body — decide whether the order is paid.
 *
 * Point 2 is why a forged body cannot mark an order paid even if the secret
 * ever leaked: the body is used for exactly one thing, reading the payment id
 * to go and ask about.
 */
class TabbyGateway extends RemoteGateway implements HandlesWebhooks, ListsTransactions, SettlesPayments, VoidsAuthorisation
{
    private const API = 'https://api.tabby.ai';

    /** Tabby operates in these markets, and only these. */
    private const COUNTRIES = ['AE', 'SA', 'BH', 'KW', 'QA'];

    /**
     * The currencies Tabby settles in, index-aligned with COUNTRIES.
     *
     * Alignment is the point rather than tidiness: the plugin derives a default
     * merchant code by looking the shop's currency up in one list and reading
     * the same position out of the other, and this class does the same in
     * merchantCode(). A shop selling in SAR with no merchant code stored is an
     * SA account, not an AE one, and getting that wrong is a 403 from Tabby on
     * every checkout.
     */
    private const CURRENCIES = ['AED', 'SAR', 'BHD', 'KWD', 'QAR'];

    /**
     * The one product this shop offers, as Tabby keys `available_products`.
     *
     * See the class comment. `payLater` and `creditCardInstallments` are the
     * two the source plugin disables, and offering one of them from a control
     * labelled "pay in 4" would put a shopper on a contract nobody chose.
     */
    private const PRODUCT = 'installments';

    /** The languages Tabby's hosted checkout renders. Anything else is 'en'. */
    private const LANGUAGES = ['en', 'ar'];

    /** How many previous orders may be described to Tabby, as the plugin caps it. */
    private const HISTORY_LIMIT = 10;

    /**
     * Order statuses Tabby understands, keyed by ours.
     *
     * Only terminal ones, exactly as the plugin maps them: an order still in
     * flight tells Tabby nothing about whether this buyer pays.
     */
    private const HISTORY_STATUSES = [
        'completed' => 'complete',
        'cancelled' => 'canceled',
        'refunded' => 'refunded',
        'failed' => 'canceled',
    ];

    /**
     * The merchant code one call is scoped to, or null for the stored one.
     *
     * Webhook administration is per country — Tabby answers a different list of
     * registered hooks for each X-Merchant-Code, and a code this merchant has no
     * agreement for answers `not_authorized`. authHeaders() is the only place
     * that header is built, so this is what lets one call be aimed at a country
     * without a second copy of the HTTP plumbing. Always set and cleared by
     * forCountry(), which restores it in a finally.
     */
    private ?string $merchantCodeOverride = null;

    /**
     * Default days an AUTHORIZED payment stays capturable.
     *
     * A default, not a constant of the API: Tabby sets the real figure per
     * merchant agreement, so this is overridable from the payments screen.
     * Whatever the number, it is advisory here — capture() re-reads the
     * payment's live status and Tabby's own answer is what decides.
     *
     * THIS IS NOT THE PLUGIN'S `order_timeout` AND THE NOTE THAT SAID IT WAS
     * WAS WRONG. `tabby_checkout_order_timeout` is twenty MINUTES by default
     * and measures how long a CREATED checkout session may sit before the
     * plugin's cron destroys the unpaid order behind it — a session-expiry
     * figure about a shopper who never finished, on a timer this port
     * deliberately does not have. The capture window is a different quantity
     * about a different state, and reading one number as the other would have
     * warned the merchant that a 30-day hold lapses in twenty minutes.
     */
    private const DEFAULT_CAPTURE_DAYS = 30;

    public function id(): string
    {
        return 'tabby';
    }

    public function title(): string
    {
        return 'Pay in 4 with Tabby';
    }

    public function configured(): bool
    {
        return $this->credentials->filled($this->id(), 'public_key', 'secret_key');
    }

    public function description(int $totalFils): ?string
    {
        return 'Split into 4 interest-free payments. No fees, no interest.';
    }

    public function availableFor(int $totalFils, ?string $country = null): bool
    {
        if ($totalFils <= 0) {
            return false;
        }

        /*
         * The shop's own currency, before the destination.
         *
         * Tabby settles in five currencies and this shop's is a single stored
         * value, so a shop configured in USD can never take a Tabby payment —
         * and offering the radio button anyway means the shopper picks it, gets
         * redirected, and comes back to a failure that reads as our outage.
         * PaymentGateway::availableFor() has no currency parameter to pass one
         * in (widening it would reach Stripe, Tamara and cash on delivery), so
         * it is read from the store here and again from the ORDER in start(),
         * which is the one that is authoritative for a specific sale.
         */
        if (! $this->supportsCurrency($this->storeCurrency())) {
            return false;
        }

        // Tabby only operates in the Gulf. Offering it to a shopper it will
        // certainly decline wastes a redirect and looks like our bug.
        return $country === null || in_array(strtoupper($country), self::COUNTRIES, true);
    }

    /** Is this a currency Tabby settles in? */
    public function supportsCurrency(?string $currency): bool
    {
        return $currency !== null
            && in_array(strtoupper(trim($currency)), self::CURRENCIES, true);
    }

    /**
     * The shop's configured currency.
     *
     * Falls back to AED rather than to "unsupported": the `orders.currency`
     * column defaults to AED in the schema and every other money path in this
     * application reads the same default, so a shop that has never set the
     * value is an AED shop, not a shop with no currency.
     */
    private function storeCurrency(): string
    {
        try {
            $map = \App\Models\Setting::map();
            $currency = strtoupper(trim((string) ($map['currency'] ?? '')));
        } catch (\Throwable) {
            // configured() promises never to throw and availableFor() is asked
            // on the same page. A settings table that cannot be read is not a
            // reason to 500 the checkout.
            $currency = '';
        }

        return $currency !== '' ? $currency : \App\Support\Money::DEFAULT_CURRENCY;
    }

    public function configSchema(): array
    {
        return [
            'public_key' => ['text', 'Public key', 'Starts pk_test_ on sandbox, pk_ live. Format pk_[test_]xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx. Safe to appear in the page.', 'keys'],
            'secret_key' => ['secret', 'Secret key', 'Starts sk_test_ on sandbox, sk_ live. Format sk_[test_]xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx. Never leaves the server.', 'keys'],
            'merchant_code' => ['text', 'Merchant code', 'The country code Tabby issued the account under — AE for this store. Left blank, the store currency decides it.', 'keys'],
            'webhook_secret' => ['secret', 'Webhook secret', 'Generated for you. It forms part of the webhook URL below; regenerate it by clearing this field and saving. Re-register the webhook with Tabby afterwards or the old address keeps being called.', 'keys'],
            'capture_days' => ['text', 'Capture window (days)', 'How long Tabby leaves an authorisation capturable on your account. Default 30. Used only to warn you before it lapses — Tabby itself decides.', 'settings'],
            /*
             * OFF as it ships, per CLAUDE.md rule 1, and this one is not
             * caution: turning it on sends Tabby a description of up to ten of
             * this buyer's PREVIOUS orders — the name, phone, email and
             * delivery address on each, and their line items. Tabby asks for it
             * because it raises approval rates, and that is a real benefit the
             * owner may well want; it is still somebody's purchase history
             * leaving this server, so it is the owner's decision to take and
             * not a default to inherit.
             *
             * `buyer_history` is sent either way and is NOT this switch. It is
             * two aggregate numbers about the person buying right now — when
             * they registered, and how many orders they have completed — which
             * is the ordinary fraud signal any payment provider is given.
             */
            'share_order_history' => ['bool', 'Send past-order history to Tabby', 'Raises Tabby approval rates by telling them how this customer has paid before. Sends up to 10 previous orders, each with the name, phone, email, delivery address and items on it. Off by default — a deliberate choice, not a recommendation.', 'settings'],
        ];
    }

    protected function baseUrl(): string
    {
        return self::API;
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->credentials->get($this->id(), 'secret_key'),
            'X-Merchant-Code' => $this->merchantCodeOverride ?? $this->merchantCode(),
        ];
    }

    /**
     * Run one call against a specific country's merchant account.
     *
     * finally, not a trailing assignment: `attempt()` cannot throw but
     * everything around it can, and an override left set would silently aim
     * every later call in the request — including a capture — at the wrong
     * country's account.
     */
    private function forCountry(string $country, callable $call): mixed
    {
        $previous = $this->merchantCodeOverride;
        $this->merchantCodeOverride = strtoupper($country);

        try {
            return $call();
        } finally {
            $this->merchantCodeOverride = $previous;
        }
    }

    /**
     * The merchant code for ordinary calls.
     *
     * The stored value wins. Absent one, the store's currency names the country
     * the way WC_Tabby_Config::getDefaultMerchantCode() does — same two lists,
     * same index — rather than assuming AE, because an SAR shop sending
     * X-Merchant-Code: AE is refused on every single call and the error says
     * nothing about currency.
     *
     * A KNOWN AND DELIBERATE DIFFERENCE FROM THE PLUGIN, stated because silence
     * would read as an oversight. `WC_Tabby_Config::getMerchantCode($order)`
     * derives the code from the ORDER's billing (then shipping) country, so a
     * multi-country merchant's SA buyer is sent under X-Merchant-Code: SA. This
     * reads the SHOP's configured code instead, for every order.
     *
     * The two agree exactly for a single-country merchant, which is what this
     * shop is (`merchant_code` = AE, currency AED, and Tabby issues the account
     * per country). They diverge for a merchant holding several country
     * agreements — and there the plugin's behaviour is the right one. It is not
     * adopted here because the failure modes are asymmetric and untestable from
     * this project: deriving the code from a buyer's address sends
     * X-Merchant-Code: SA on behalf of an AE-only account, which Tabby answers
     * `not_authorized` — a 403 on the checkout call, for every Saudi shopper, on
     * a shop that works perfectly today. Egress to api.tabby.ai is blocked here,
     * so that branch could not be driven against the real API either way.
     *
     * If the owner adds a second country agreement, the change is this method
     * taking an optional Order and preferring a billing country that is BOTH in
     * self::COUNTRIES and one the account is authorised for — and
     * webhookStatus() already reports the authorised set per country, so the
     * information needed to do it safely is on the screen.
     */
    private function merchantCode(): string
    {
        $code = strtoupper($this->credentials->get($this->id(), 'merchant_code'));

        if ($code !== '') {
            return $code;
        }

        $index = array_search($this->storeCurrency(), self::CURRENCIES, true);

        return $index === false ? 'AE' : self::COUNTRIES[$index];
    }

    /**
     * Are the stored keys sandbox keys?
     *
     * Read off the SECRET key's prefix, which is what the plugin does and what
     * Tabby's `is_test` flag on a webhook registration has to agree with. NOT
     * the `payment_providers.mode` column: that is a label the owner types and
     * Tabby has never seen it, so a shop with live keys and the switch left on
     * "Sandbox" would otherwise register a test webhook against a live account
     * and never be called about a real payment.
     */
    public function sandboxKeys(): bool
    {
        return str_starts_with($this->credentials->get($this->id(), 'secret_key'), 'sk_test');
    }

    /**
     * Do the public and secret keys belong to the same environment?
     *
     * A live secret with a sandbox public key (or the reverse) is the
     * misconfiguration that produces the least useful error: server-to-server
     * calls succeed, the hosted checkout loads, and the shopper is declined for
     * reasons nobody can see. Reported by the webhook screen rather than
     * enforced in configured(), because refusing to be configured would take a
     * working shop dark the day Tabby changes a prefix.
     */
    public function keysDisagree(): bool
    {
        $public = $this->credentials->get($this->id(), 'public_key');
        $secret = $this->credentials->get($this->id(), 'secret_key');

        if ($public === '' || $secret === '') {
            return false;
        }

        return str_starts_with($public, 'pk_test') !== str_starts_with($secret, 'sk_test');
    }

    /* ------------------------------------------------------------- checkout */

    public function start(Order $order): PaymentStart
    {
        if (! $this->configured()) {
            return PaymentStart::failed('Tabby is not available right now.');
        }

        $currency = strtoupper((string) ($order->currency ?: \App\Support\Money::DEFAULT_CURRENCY));

        /*
         * THE ORDER'S currency, not the shop's, and checked here as well as in
         * availableFor(). availableFor() is asked while the basket is being
         * priced and answers about the shop; this is the last gate before money
         * is committed and answers about the row that will be charged. Tabby
         * refuses a currency it does not settle in with a 400, which reaches the
         * shopper as "we could not reach Tabby" — true of nothing, and
         * unactionable.
         */
        if (! $this->supportsCurrency($currency)) {
            return PaymentStart::failed('Tabby cannot be used for this currency. Please choose another payment method.');
        }

        $result = $this->call('POST', '/api/v2/checkout', [
            'payment' => $this->paymentObject($order, $currency),
            'lang' => $this->language(),
            'merchant_code' => $this->merchantCode(),
            'merchant_urls' => [
                'success' => $this->returnUrl($order, 'success'),
                'cancel' => $this->returnUrl($order, 'cancel'),
                'failure' => $this->returnUrl($order, 'failure'),
            ],
        ]);

        if ($result === null || ($result['status'] ?? null) !== 'created') {
            return PaymentStart::failed('We could not reach Tabby. Please try another payment method.');
        }

        /*
         * THE PRODUCT THIS SHOP SELLS, BY NAME. See the class comment:
         * `available_products` is a map and taking the first entry out of it was
         * how a pay-in-4 button could open a pay-in-14-days contract.
         *
         * An absent key is a DECLINE and not an outage — it is how Tabby says
         * "not this buyer, not this basket" — so the shopper is told to pick
         * another method rather than to try again.
         */
        $products = $result['configuration']['available_products'] ?? null;
        $offers = is_array($products) ? ($products[self::PRODUCT] ?? null) : null;
        $url = is_array($offers) ? ($offers[0]['web_url'] ?? null) : null;

        if (! is_string($url) || $url === '' || ! $this->isTabbyUrl($url)) {
            return PaymentStart::failed('Tabby is not available for this order. Please choose another payment method.');
        }

        /*
         * `payment.id`, NOT the top-level `id`.
         *
         * `POST /checkout` answers with a CHECKOUT SESSION whose own id sits at
         * the top level and whose `payment` block carries the id every other
         * endpoint in this class is keyed by. Storing the session id in
         * `orders.transaction_id` left capture(), void() and the
         * refund path all calling `/payments/<a session id>`, which is a 404 —
         * and the only reason it was ever survivable is that PaymentConfirmer
         * overwrites the column with the id the WEBHOOK carries, so the bug was
         * invisible on any shop whose webhook was registered and fatal on one
         * whose webhook was not. The plugin reads `$result->payment->id` here
         * and so does this.
         *
         * The top level is the fallback rather than the primary, and only so
         * that a response shape Tabby has not shown us yet degrades to today's
         * behaviour instead of to no redirect at all.
         */
        $paymentId = trim((string) ($result['payment']['id'] ?? $result['id'] ?? ''));

        if ($paymentId === '') {
            return PaymentStart::failed('Tabby did not return a payment reference. Please try another payment method.');
        }

        $order->forceFill(['transaction_id' => $paymentId])->save();

        $this->log('checkout session created', '/api/v2/checkout', 200, [
            'reference' => $this->reference($order),
            'product' => self::PRODUCT,
            // Which field the id came out of, so a shape change is visible in
            // the log before it is visible as a failed capture a week later.
            'id_source' => isset($result['payment']['id']) ? 'payment.id' : 'id',
        ]);

        return PaymentStart::redirect($url, $paymentId);
    }

    /**
     * Is this a URL we are willing to send a shopper to?
     *
     * The redirect target comes out of an API response, and a response is not a
     * trusted source just because it was authenticated — a compromised or
     * mis-proxied answer that carried `javascript:` or an attacker's host would
     * otherwise be handed straight to the browser as a redirect. https only, and
     * the host must be Tabby's, which is where their hosted checkout lives and
     * the only place it has ever lived.
     */
    private function isTabbyUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        return $host === 'tabby.ai' || str_ends_with($host, '.tabby.ai');
    }

    /** 'ar' on an Arabic page, 'en' everywhere else — the two Tabby renders. */
    private function language(): string
    {
        $locale = strtolower(substr(Locale::current(), 0, 2));

        return in_array($locale, self::LANGUAGES, true) ? $locale : 'en';
    }

    /**
     * The `payment` block, which is the same shape in the checkout call and in
     * the availability probe the plugin makes with it.
     *
     * @return array<string, mixed>
     */
    private function paymentObject(Order $order, string $currency): array
    {
        $payment = [
            'amount' => $this->toMajor((int) $order->total),
            'currency' => $currency,
            'description' => 'Order ' . $this->reference($order),
            'buyer' => $this->buyer($order),
            'order' => [
                'reference_id' => $this->reference($order),
                /*
                 * shipping_total PLUS its tax, as the plugin sends it
                 * (`get_shipping_total() + get_shipping_tax()`). It reads as a
                 * double count beside `tax_amount` and is not one: Tabby
                 * validates that amount = items + shipping + tax - discount, and
                 * `orders.tax_total` on this shop is the whole order's tax with
                 * VAT display-only, so the two agree at zero today and agree at
                 * the plugin's arithmetic the day tax is switched on.
                 */
                'shipping_amount' => $this->toMajor((int) $order->shipping_total),
                'discount_amount' => $this->toMajor((int) $order->discount_total),
                'tax_amount' => $this->toMajor((int) $order->tax_total),
                'items' => $this->items($order),
            ],
            /*
             * Two aggregate numbers about the person buying. See the note on
             * `share_order_history` in configSchema(): this is not that switch
             * and carries nobody's purchase history.
             */
            'buyer_history' => $this->buyerHistory($order),
            'shipping_address' => $this->shippingAddress($order),
            /*
             * What Tabby's own support reads to tell a plugin problem from a
             * merchant one. Our name and our version — no hostname, no store
             * data, and nothing that leaves this call.
             */
            'meta' => [
                'tabby_plugin_platform' => 'kbb-storefront',
                'tabby_plugin_version' => (string) config('kbb.version', '1.0.0'),
            ],
        ];

        $history = $this->orderHistory($order);

        // Omitted rather than sent empty when the switch is off, so a shop that
        // has not opted in sends Tabby no key at all about it.
        if ($history !== []) {
            $payment['order_history'] = $history;
        }

        return $payment;
    }

    /* -------------------------------------------------------------- webhook */

    public function handleWebhook(Request $request): WebhookOutcome
    {
        // (1) Shared secret, constant-time. Before anything is parsed, before
        //     the database is touched, before a line is logged.
        $expected = $this->credentials->get($this->id(), 'webhook_secret');

        if ($expected === '' || ! Signature::equals($expected, (string) $request->route('secret'))) {
            return WebhookOutcome::rejected();
        }

        $paymentId = (string) ($request->input('id') ?? '');

        if ($paymentId === '') {
            return WebhookOutcome::refused('no payment id');
        }

        // (2) Ask Tabby what actually happened. The POST body is used for the
        //     id and nothing else -- every figure below comes from this call.
        $payment = $this->call('GET', '/api/v2/payments/' . urlencode($paymentId));

        if ($payment === null) {
            // Could not verify. 503 so Tabby retries rather than us guessing.
            return WebhookOutcome::failed('could not verify payment with Tabby');
        }

        $order = $this->findOrderByReference($payment['order']['reference_id'] ?? null);

        if ($order === null) {
            return WebhookOutcome::refused('no order for that reference');
        }

        $status = strtoupper((string) ($payment['status'] ?? ''));
        $captured = $this->hasCapture($payment);

        /*
         * THE VERIFIED ID, not the posted one, wherever there is a choice.
         *
         * The body is used to decide what to ask about and for nothing else, so
         * what gets written into `payments.provider_ref` and the audit row is the
         * id Tabby's own authenticated answer carries. Storing the caller's
         * string would put an unverified value in the column the merchant later
         * searches Tabby's dashboard by.
         */
        $verifiedId = trim((string) ($payment['id'] ?? ''));
        $reference = $verifiedId !== '' ? $verifiedId : $paymentId;

        $summary = [
            'payment_id' => $reference,
            'status' => $status,
            'reference' => $payment['order']['reference_id'] ?? null,
            // Named, and it is the field that decides the branch below. An
            // audit row that recorded CLOSED without it could not be read back
            // to say whether the money moved.
            'captured' => $captured ? 'yes' : 'no',
        ];

        /*
         * A FAILURE NOTICE ABOUT A SUPERSEDED ATTEMPT IS NOT A FAILURE.
         *
         * One order can carry more than one Tabby payment. start() is reachable
         * again on the same order — a shopper who abandons the Tabby page and
         * picks Tabby a second time, or opens it in two tabs — and each call
         * creates a NEW payment and overwrites `orders.transaction_id` with it.
         * The abandoned one then EXPIRES on Tabby's own timer and Tabby delivers
         * an `EXPIRED` notice for it.
         *
         * Without this guard that notice was applied to the order: PaymentConfirmer
         * ::fail() moved it to `failed`, returned the stock and released the
         * coupon. Webhook delivery is not ordered, so the `AUTHORIZED` notice for
         * the payment that DID succeed could then arrive second, find the order
         * in `failed` — which is in PaymentConfirmer's VOID list — and be refused
         * as "order is no longer live". Net effect: real money authorised on
         * Tabby, stock back on the shelf, order dead, and the only trace a status
         * note. The merchant's own plugin guards the same thing by comparing
         * `get_tabby_payment_id($order)` with the notice's id before acting.
         *
         * ONLY the failure branches are guarded, and that asymmetry is deliberate.
         * A SUCCESS notice for an unexpected id is money the customer really has
         * committed, and dropping it would be the same silence in the other
         * direction — so it falls through to confirm(), which checks the amount
         * and the currency against the order before it believes anything.
         */
        $stored = trim((string) ($order->transaction_id ?? ''));
        $superseded = $stored !== '' && $verifiedId !== '' && ! hash_equals($stored, $verifiedId);

        if (in_array($status, ['REJECTED', 'EXPIRED'], true)) {
            if ($superseded) {
                return WebhookOutcome::ignored('notice concerns a superseded payment attempt');
            }

            return $this->confirmer->fail($order, $this->id(), $reference, strtolower($status), $summary);
        }

        /*
         * A CLOSED PAYMENT WITH NO CAPTURES IS A VOID, AND THIS USED TO MARK IT
         * PAID.
         *
         * See the class comment for why CLOSED is ambiguous. The reachable
         * sequence is ordinary rather than exotic: an order is cancelled, the
         * authorisation is released (by void() here, by the
         * merchant in Tabby's dashboard, or by Tabby's own timer), Tabby
         * delivers the close notice, and the branch below treated it as a
         * successful payment. The order came back to `processing` with `paid_at`
         * set, against a hold that had just been released — a live, paid,
         * stock-committed order for money that will never arrive.
         *
         * fail() is the right handler and not merely the opposite one: it
         * refuses to touch an order that IS paid (a late close after a genuine
         * capture changes nothing), refuses to move one already dispatched, and
         * otherwise returns the stock and releases the coupon, which is exactly
         * what a released authorisation means.
         */
        if ($status === 'CLOSED' && ! $captured) {
            // Guarded exactly as REJECTED and EXPIRED are: a released hold on an
            // attempt this order has already moved past says nothing about the
            // attempt it is actually waiting on.
            if ($superseded) {
                return WebhookOutcome::ignored('notice concerns a superseded payment attempt');
            }

            return $this->confirmer->fail($order, $this->id(), $reference, 'voided', $summary);
        }

        if (! in_array($status, ['AUTHORIZED', 'CLOSED'], true)) {
            // CREATED means the shopper has not finished. Nothing to do, and
            // nothing wrong -- a 200 so it is not retried forever.
            return WebhookOutcome::ignored('payment not authorised yet');
        }

        return $this->confirmer->confirm(
            $order,
            $this->id(),
            $reference,
            $this->toFils($payment['amount'] ?? 0),
            (string) ($payment['currency'] ?? ''),
            $summary,
        );
    }

    /* ----------------------------------------------------------- settlement */

    public function captureWindow(): string
    {
        return sprintf(
            'Within about %d days of authorisation. Tabby auto-voids an authorisation that is never captured, and the money is then gone.',
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
     * `POST /api/v1/payments/{id}/captures` — v1, not the v2 the checkout and
     * the webhook read use. Tabby splits its API that way and a capture posted
     * to v2 is a 404, so the version is spelled out here rather than inherited
     * from baseUrl().
     *
     * The status is re-read from Tabby first, over an authenticated GET, and
     * that answer decides:
     *
     *   AUTHORIZED  capturable. Capture it.
     *   CLOSED      already captured. A success, not an error — this is what a
     *               retry after a timeout hits, and treating it as a failure
     *               is how a merchant ends up capturing twice by hand.
     *   anything    REJECTED, EXPIRED or still CREATED. Nothing to take, and
     *   else        the reason is recorded rather than guessed at.
     */
    public function capture(Order $order, int $amountFils): SettlementResult
    {
        $paymentId = trim((string) $order->transaction_id);

        if (! $this->configured() || $paymentId === '') {
            return SettlementResult::failed(
                'not_configured',
                ['provider' => $this->id()],
                'Tabby is not configured, or this order has no Tabby payment on it.',
            );
        }

        $payment = $this->call('GET', '/api/v2/payments/' . urlencode($paymentId));

        if ($payment === null) {
            return SettlementResult::failed(
                'unreachable',
                ['provider' => $this->id(), 'payment_id' => $paymentId],
                'Tabby could not be reached. Nothing was captured; try again.',
            );
        }

        $status = strtoupper((string) ($payment['status'] ?? ''));

        if ($status === 'CLOSED') {
            $existing = $this->lastId($payment['captures'] ?? null);

            /*
             * CLOSED AND EMPTY IS A VOID, NOT A CAPTURE, AND ok() HERE WAS A
             * FABRICATED SETTLEMENT.
             *
             * PaymentCapturer treats an ok() as done: it keeps the `captured_at`
             * it claimed before this call and writes `captured_total` = the full
             * order amount with `capture_ref` = this method's reference, which on
             * a voided payment was NULL. The order then read as fully captured
             * with no provider transaction behind it — and `captured_total` is
             * the ceiling PaymentRefunder::capturedFils() measures a refund
             * against, so the next refund was authorised against money the shop
             * had never received.
             *
             * The distinction is `captures[]` and nothing else, because Tabby
             * ends a void and a capture at the same status.
             */
            if ($existing === null || ! $this->hasCapture($payment)) {
                return SettlementResult::failed(
                    'voided',
                    ['provider' => $this->id(), 'payment_id' => $paymentId, 'status' => $status, 'captured' => 'no'],
                    'This Tabby authorisation has been released and carries no capture, so there is nothing to take. '
                    . 'The customer has not been charged and cannot be from this payment.',
                );
            }

            return SettlementResult::ok(
                'already_captured',
                $existing,
                ['provider' => $this->id(), 'payment_id' => $paymentId, 'status' => $status, 'captured' => 'yes'],
                'Tabby had already captured this payment.',
            );
        }

        if ($status !== 'AUTHORIZED') {
            return SettlementResult::failed(
                'not_authorised',
                ['provider' => $this->id(), 'payment_id' => $paymentId, 'status' => $status],
                'Tabby reports this payment as ' . ($status !== '' ? strtolower($status) : 'unknown') . ', so there is nothing to capture.',
            );
        }

        /*
         * The breakdown rides along ONLY on a full capture.
         *
         * Tabby checks that a capture's `amount` agrees with the tax, shipping
         * and line items sent beside it. PaymentCapturer captures the whole
         * order total today and nothing else calls this, but the interface takes
         * an amount and a partial one would have been sent with the WHOLE
         * order's tax, shipping and items — arithmetic that cannot balance, and
         * a rejection whose message would be about items rather than about the
         * amount that caused it. A partial capture sends the amount alone, which
         * is the one shape that is true whatever it is.
         */
        $body = ['amount' => $this->toMajor($amountFils)];

        if ($amountFils === (int) $order->total) {
            $body['tax_amount'] = $this->toMajor((int) $order->tax_total);
            $body['shipping_amount'] = $this->toMajor((int) $order->shipping_total);
            $body['items'] = $this->items($order);
        }

        $attempt = $this->attempt('POST', '/api/v1/payments/' . urlencode($paymentId) . '/captures', $body);

        $captureId = $attempt['ok'] ? $this->lastId($attempt['body']['captures'] ?? null) : null;

        if (! $attempt['ok'] || $captureId === null) {
            return SettlementResult::failed(
                $attempt['error'] ?? 'capture_rejected',
                [
                    'provider' => $this->id(),
                    'payment_id' => $paymentId,
                    'http_status' => $attempt['status'],
                    'error' => $attempt['error'],
                ],
                'Tabby refused the capture. Nothing was taken.',
            );
        }

        return SettlementResult::ok(
            'captured',
            $captureId,
            ['provider' => $this->id(), 'payment_id' => $paymentId, 'capture_id' => $captureId],
            'Captured through Tabby.',
        );
    }

    /**
     * Refund some or all of a capture.
     *
     * `POST /api/v1/payments/{id}/refunds`, and `capture_id` is not optional:
     * Tabby refunds against a capture, not against a payment, so a refund
     * attempted on an authorisation that was never captured has nothing to
     * point at. That is reported as its own code rather than as a generic
     * failure, because the fix is "capture it first" and no other message
     * says so.
     *
     * TWO PARAMETERS ARE DELIBERATELY NOT USED, and silence about that would
     * read as an oversight:
     *
     *   $captureRef      PaymentRefunder passes `capture_ref ?: transaction_id`,
     *                    and the fallback half of that is the PAYMENT id. Sent
     *                    to Tabby as `capture_id` it names nothing, so the
     *                    order's own `capture_ref` is read here instead and an
     *                    empty one is refused above rather than papered over
     *                    with an id that cannot be right.
     *   $idempotencyKey  Tabby documents no idempotency header and the
     *                    merchant's plugin sends none, so there is nowhere to
     *                    put it. Inventing a header name would be inventing an
     *                    API. The unique index behind PaymentRefunder is the
     *                    real guard and it is on our side of the wire.
     *
     * The plugin also sends an `items` array on a refund, built from the
     * WooCommerce refund object's own line items. This application's refunds
     * are an amount and a reason — there is no per-line refund model to build
     * one from — so it is left out rather than fabricated from the order's
     * lines, which would describe a whole-order return on a partial refund.
     */
    public function refund(
        Order $order,
        int $amountFils,
        ?string $reason,
        ?string $captureRef,
        ?string $idempotencyKey = null,
    ): SettlementResult
    {
        $paymentId = trim((string) $order->transaction_id);

        if (! $this->configured() || $paymentId === '') {
            return SettlementResult::failed(
                'not_configured',
                ['provider' => $this->id()],
                'Tabby is not configured, or this order has no Tabby payment on it.',
            );
        }

        $captureId = trim((string) ($order->capture_ref ?? ''));

        if ($captureId === '') {
            return SettlementResult::failed(
                'not_captured',
                ['provider' => $this->id(), 'payment_id' => $paymentId],
                'This Tabby payment has not been captured, so there is nothing to refund yet. Capture it first.',
            );
        }

        $attempt = $this->attempt('POST', '/api/v1/payments/' . urlencode($paymentId) . '/refunds', [
            'capture_id' => $captureId,
            'amount' => $this->toMajor($amountFils),
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : 'Merchant refund',
        ]);

        $refundId = $attempt['ok'] ? $this->lastId($attempt['body']['refunds'] ?? null) : null;

        if (! $attempt['ok'] || $refundId === null) {
            return SettlementResult::failed(
                $attempt['error'] ?? 'refund_rejected',
                [
                    'provider' => $this->id(),
                    'payment_id' => $paymentId,
                    'http_status' => $attempt['status'],
                    'error' => $attempt['error'],
                ],
                'Tabby refused the refund. Nothing has been returned to the customer.',
            );
        }

        return SettlementResult::ok(
            'refunded',
            $refundId,
            ['provider' => $this->id(), 'payment_id' => $paymentId, 'refund_id' => $refundId],
            'Refunded through Tabby.',
        );
    }

    /* ----------------------------------------------------------------- void */

    /**
     * Release an authorisation nobody captured.
     *
     * `POST /api/v1/payments/{id}/close`, which is Tabby's only word for it —
     * there is no VOIDED status and no `/void` endpoint, and a closed payment is
     * distinguishable from a captured one only by its `captures[]`. See the
     * class comment.
     *
     * The live status decides, as it does in capture():
     *
     *   AUTHORIZED            releasable. Release it.
     *   CLOSED, no captures   already released. ok(), so a retry after a
     *                         dropped connection settles rather than alarms.
     *   CLOSED, captured      REFUSED, and this is the case worth writing down:
     *                         the money has moved, so what the caller is asking
     *                         for is a refund and doing it as a close would
     *                         either be rejected or — worse, if Tabby ever
     *                         accepts it — reverse a capture through a path
     *                         with no refund row, no ledger entry and no
     *                         `refunded_total` behind it.
     *   REJECTED / EXPIRED    nothing is held. ok(), with its own code, because
     *                         the caller's goal ("this order holds no money")
     *                         is already true and failing would make a
     *                         cancellation look broken.
     *   CREATED               the shopper never finished, so nothing is held
     *                         and nothing is closable. ok(), same reasoning.
     */
    /**
     * @param  int  $amountFils  NOT SENT, and the signature is right anyway.
     *   Tabby's close endpoint takes the payment id in the path and no body at
     *   all — there is nothing to echo an amount back to. Tamara's cancel wants
     *   the order's figures echoed and they have to balance, so the amount is on
     *   the interface; an interface written to Tabby's narrower shape would have
     *   forced the Tamara implementation to reach back into the order for a
     *   number the caller already had. It is read here only to be refused by
     *   name in the docblock, which is cheaper than a reader wondering.
     */
    public function void(Order $order, int $amountFils): SettlementResult
    {
        $paymentId = trim((string) $order->transaction_id);

        if (! $this->configured() || $paymentId === '') {
            return SettlementResult::failed(
                'not_configured',
                ['provider' => $this->id()],
                'Tabby is not configured, or this order has no Tabby payment on it.',
            );
        }

        $payment = $this->call('GET', '/api/v2/payments/' . urlencode($paymentId));

        if ($payment === null) {
            return SettlementResult::failed(
                'unreachable',
                ['provider' => $this->id(), 'payment_id' => $paymentId],
                'Tabby could not be reached. The authorisation is still open; try again.',
            );
        }

        $status = strtoupper((string) ($payment['status'] ?? ''));
        $summary = ['provider' => $this->id(), 'payment_id' => $paymentId, 'status' => $status];

        if ($status === 'CLOSED') {
            if ($this->hasCapture($payment)) {
                return SettlementResult::failed(
                    'already_captured',
                    $summary + ['captured' => 'yes'],
                    'This Tabby payment has been captured, so the money has already moved. '
                    . 'Refund it instead — releasing an authorisation cannot give captured money back.',
                );
            }

            return SettlementResult::ok(
                'already_voided',
                $this->lastId($payment['captures'] ?? null),
                $summary + ['captured' => 'no'],
                'This Tabby authorisation was already released. Nothing was charged.',
            );
        }

        if (in_array($status, ['REJECTED', 'EXPIRED', 'CREATED'], true)) {
            return SettlementResult::ok(
                'nothing_held',
                null,
                $summary,
                'Tabby reports this payment as ' . strtolower($status) . ', so no money is being held. Nothing to release.',
            );
        }

        if ($status !== 'AUTHORIZED') {
            return SettlementResult::failed(
                'not_authorised',
                $summary,
                'Tabby reports this payment as ' . ($status !== '' ? strtolower($status) : 'unknown')
                . ', which is not a state an authorisation can be released from.',
            );
        }

        $attempt = $this->attempt('POST', '/api/v1/payments/' . urlencode($paymentId) . '/close');

        if (! $attempt['ok']) {
            return SettlementResult::failed(
                $attempt['error'] ?? 'void_rejected',
                $summary + ['http_status' => $attempt['status'], 'error' => $attempt['error']],
                'Tabby refused to release this authorisation. It is still open.',
            );
        }

        /*
         * Tabby answers a close with the payment, and its status is the proof.
         * Trusting a 200 alone would report a release that had not happened on
         * any future response shape where the call is accepted and ignored.
         */
        $closed = strtoupper((string) ($attempt['body']['status'] ?? ''));

        if ($closed !== '' && $closed !== 'CLOSED') {
            return SettlementResult::failed(
                'void_not_applied',
                $summary + ['status_after' => $closed],
                'Tabby accepted the request but still reports this payment as ' . strtolower($closed)
                . '. The authorisation may still be open — check it in the Tabby dashboard.',
            );
        }

        return SettlementResult::ok(
            'voided',
            $paymentId,
            $summary + ['status_after' => $closed !== '' ? $closed : 'CLOSED'],
            'The Tabby authorisation has been released. The customer has not been charged.',
        );
    }

    /* ------------------------------------------------------------- webhooks */

    /**
     * What Tabby has been told to call, per country, without changing anything.
     *
     * THE GAP THIS CLOSES IS THE ONE THAT MADE EVERYTHING ELSE MOOT. Tabby does
     * not take a webhook URL from a dashboard field — the endpoint is REGISTERED
     * THROUGH THE API, one registration per merchant country, and until that
     * POST is made Tabby never calls this shop at all. handleWebhook() below was
     * complete and correct and could not run, so every Tabby order sat `pending`
     * with the money authorised until somebody captured it by hand. The payments
     * screen said "paste this into the provider dashboard", which for Stripe and
     * Tamara is right and for Tabby names a field that does not exist.
     *
     * `webhooks` is the one GET on v1: everything else reads from v2, and this
     * collection answers 404 there. The plugin encodes the exception literally
     * (`$method == 'GET' && $endpoint != 'webhooks'`) and so does this.
     *
     * Countries the merchant has no agreement for answer `not_authorized` or
     * `not_found`, which is not an error — it is how Tabby says "this account is
     * not an SA account". Those are reported as `not_authorised` per country and
     * skipped, never surfaced as a failure, because a UAE-only merchant would
     * otherwise see four failures on a screen that had just worked.
     *
     * @return array<string, mixed>
     */
    public function webhookStatus(): array
    {
        $blocked = $this->webhookPrecondition();

        if ($blocked !== null) {
            return $blocked;
        }

        $url = (string) $this->ourWebhookUrl();
        $countries = [];

        foreach (self::COUNTRIES as $country) {
            $countries[] = $this->countryWebhookStatus($country, $url) + ['country' => $country];
        }

        return [
            'ok' => true,
            'url' => $url,
            'is_test' => $this->sandboxKeys(),
            'keys_disagree' => $this->keysDisagree(),
            'countries' => $countries,
        ];
    }

    /**
     * Make Tabby's registration agree with this shop, in every country the
     * account is authorised for.
     *
     * Four outcomes per country, and the ordering is the whole design:
     *
     *   registered   nothing pointed here. One POST.
     *   updated      a hook points here with the wrong `is_test`. One PUT,
     *                which is what makes moving a shop from sandbox keys to
     *                live keys work: Tabby delivers TEST events to a test hook
     *                and live events to a live one, so a hook left flagged test
     *                after the keys went live is a hook that is never called
     *                about a real payment.
     *   current      a hook points here and agrees. Nothing is sent.
     *   pruned       a hook points at THIS shop's webhook path under a
     *                DIFFERENT secret. Deleted.
     *
     * Pruning is the half that is easy to leave out and expensive to. The URL
     * ends in a 32-character secret, so regenerating it makes a new URL: without
     * a prune Tabby keeps both, delivers every event twice, and the stale
     * delivery is answered 401 forever by a gateway that is working perfectly.
     * It is also safe precisely BECAUSE the path is ours — a candidate has to
     * match this install's host, base path and `/api/payments/webhook/tabby/`
     * prefix before it is a candidate at all, so a hook the merchant registered
     * for anything else is never seen, never listed and never touched.
     *
     * @return array<string, mixed>
     */
    public function syncWebhooks(): array
    {
        /*
         * The precondition, NOT webhookStatus().
         *
         * Calling the read first was the obvious composition and it made this
         * method list every country TWICE — ten round trips to answer a
         * five-country question, with the second list able to disagree with the
         * first and the whole plan having been made against the stale one.
         * syncCountry() does its own list because it has to act on it.
         */
        $blocked = $this->webhookPrecondition();

        if ($blocked !== null) {
            return $blocked;
        }

        $url = (string) $this->ourWebhookUrl();
        $isTest = $this->sandboxKeys();
        $results = [];

        foreach (self::COUNTRIES as $country) {
            $results[] = $this->syncCountry($country, $url, $isTest) + ['country' => $country];
        }

        $reached = array_values(array_filter($results, fn (array $r) => ($r['state'] ?? '') !== 'not_authorised'));

        return [
            'ok' => true,
            'url' => $url,
            'is_test' => $isTest,
            'keys_disagree' => $this->keysDisagree(),
            'countries' => $results,
            // Zero authorised countries means the keys are wrong or the account
            // is not live yet, and the screen has to say so rather than report
            // a successful sync that registered nothing anywhere.
            'registered_anywhere' => $reached !== []
                && array_filter($reached, fn (array $r) => in_array($r['state'] ?? '', ['registered', 'updated', 'current'], true)) !== [],
        ];
    }

    /**
     * The address Tabby should call, built exactly as the payments screen and
     * GatewayPreflight build it.
     *
     * external(), not redirect(): the reader is a server at Tabby, so what is
     * registered has to be the address this shop is configured at rather than
     * whatever host the admin happens to be signed in on. If these three ever
     * disagreed, syncWebhooks() would prune the live registration as a stale one
     * on every run.
     */
    /**
     * Why neither webhook call can proceed, or null when both can.
     *
     * One reader for both, so the read and the write cannot disagree about when
     * the shop is ready — and each answer names the box that is empty rather
     * than saying "not configured", because the fix for each is different.
     *
     * @return array<string, mixed>|null
     */
    private function webhookPrecondition(): ?array
    {
        if (! $this->configured()) {
            return [
                'ok' => false,
                'error' => 'not_configured',
                'message' => 'Paste the Tabby public and secret keys in first.',
            ];
        }

        if ($this->ourWebhookUrl() === null) {
            return [
                'ok' => false,
                'error' => 'no_webhook_secret',
                'message' => 'Save the Tabby tab once to generate its webhook secret, then register the webhook.',
            ];
        }

        return null;
    }

    public function ourWebhookUrl(): ?string
    {
        $secret = $this->credentials->get($this->id(), 'webhook_secret');

        if ($secret === '') {
            return null;
        }

        return url(Url::external('/api/payments/webhook/' . $this->id() . '/')) . $secret;
    }

    /** The prefix every webhook URL of ours shares, whatever the secret is. */
    private function webhookUrlPrefix(): string
    {
        return url(Url::external('/api/payments/webhook/' . $this->id() . '/'));
    }

    /** @return array<string, mixed> */
    private function countryWebhookStatus(string $country, string $url): array
    {
        $hooks = $this->listWebhooks($country);

        if ($hooks === null) {
            return ['state' => 'unreadable', 'message' => 'Tabby would not list this country\'s webhooks.'];
        }

        if ($hooks === 'not_authorised') {
            return ['state' => 'not_authorised', 'message' => 'This account is not authorised for this country.'];
        }

        $ours = $this->matchingHook($hooks, $url);
        $stale = $this->staleHooks($hooks, $url);

        if ($ours === null) {
            return [
                'state' => 'missing',
                'stale' => count($stale),
                'message' => $stale === []
                    ? 'Tabby has no webhook pointing at this shop, so it cannot tell us a payment succeeded.'
                    : 'Tabby is calling an old address for this shop. Re-register to move it and remove the old one.',
            ];
        }

        $agrees = $this->hookIsTest($ours) === $this->sandboxKeys();

        return [
            'state' => $agrees ? 'current' : 'wrong_mode',
            'stale' => count($stale),
            'message' => $agrees
                ? 'Registered and pointing at this shop.'
                : 'Registered, but flagged for the wrong environment — Tabby will not deliver live events to a test webhook.',
        ];
    }

    /** @return array<string, mixed> */
    private function syncCountry(string $country, string $url, bool $isTest): array
    {
        $hooks = $this->listWebhooks($country);

        if ($hooks === null) {
            return ['state' => 'unreadable', 'message' => 'Tabby would not list this country\'s webhooks, so nothing was changed.'];
        }

        if ($hooks === 'not_authorised') {
            return ['state' => 'not_authorised', 'message' => 'This account is not authorised for this country.'];
        }

        $ours = $this->matchingHook($hooks, $url);
        $pruned = 0;

        /*
         * PRUNE BEFORE REGISTERING, not after. If the create fails, a shop with
         * a stale hook has still had the hook that could not verify anything
         * removed and a screen that says so — whereas pruning after a successful
         * create and then failing leaves two hooks and no record of which is
         * which. And Tabby, unlike Stripe, has never refused a second hook on a
         * second URL, so there is no state being destroyed to make room.
         */
        foreach ($this->staleHooks($hooks, $url) as $hook) {
            $id = trim((string) ($hook['id'] ?? ''));

            if ($id === '') {
                continue;
            }

            $delete = $this->forCountry(
                $country,
                fn () => $this->attempt('DELETE', '/api/v1/webhooks/' . urlencode($id)),
            );

            // A 404 means somebody removed it between the list and now, which is
            // the outcome that was wanted.
            if ($delete['ok'] || ($delete['status'] ?? null) === 404) {
                $pruned++;
            }
        }

        if ($ours !== null) {
            if ($this->hookIsTest($ours) === $isTest) {
                return ['state' => 'current', 'pruned' => $pruned, 'message' => 'Already registered and correct.'];
            }

            $id = trim((string) ($ours['id'] ?? ''));
            $update = $this->forCountry($country, fn () => $this->attempt(
                'PUT',
                '/api/v1/webhooks/' . urlencode($id),
                ['url' => $url, 'is_test' => $isTest],
            ));

            return $update['ok']
                ? ['state' => 'updated', 'pruned' => $pruned, 'message' => 'Moved to the ' . ($isTest ? 'sandbox' : 'live') . ' environment.']
                : ['state' => 'failed', 'pruned' => $pruned, 'error' => $update['error'], 'message' => 'Tabby refused to update the registration.'];
        }

        $create = $this->forCountry($country, fn () => $this->attempt(
            'POST',
            '/api/v1/webhooks',
            ['url' => $url, 'is_test' => $isTest],
        ));

        return $create['ok']
            ? ['state' => 'registered', 'pruned' => $pruned, 'message' => 'Registered with Tabby.']
            : ['state' => 'failed', 'pruned' => $pruned, 'error' => $create['error'], 'message' => 'Tabby refused to register this shop.'];
    }

    /**
     * One country's registered webhooks.
     *
     * Three answers, deliberately distinguishable: a list, the string
     * `not_authorised` for a country this account does not hold, and null for
     * "could not read". Collapsing the last two would make an outage look like a
     * country the merchant never had, which is the one shape that would let
     * syncWebhooks() report success having registered nothing.
     *
     * @return array<int, array<string, mixed>>|'not_authorised'|null
     */
    private function listWebhooks(string $country): array|string|null
    {
        $attempt = $this->forCountry(
            $country,
            fn () => $this->attempt('GET', '/api/v1/webhooks'),
        );

        if (! $attempt['ok']) {
            /*
             * `not_authorized` and `not_found` are Tabby's errorType for "not
             * your country". errorCode() has already reduced the body to that
             * one named field, so nothing else from the response is read here.
             */
            if (in_array((string) $attempt['error'], ['not_authorized', 'not_found'], true)) {
                return 'not_authorised';
            }

            return null;
        }

        $body = $attempt['body'];

        if (! is_array($body)) {
            return [];
        }

        // Tabby answers this collection as a bare list, and has been seen to
        // answer a single registration as one object. Both, plus the wrapped
        // shapes paymentList() already handles, reduce to a list here.
        if (isset($body['id'])) {
            return [$body];
        }

        $list = $this->paymentList($body);

        return array_values(array_filter($list, 'is_array'));
    }

    /**
     * The hook registered for exactly this URL.
     *
     * hash_equals rather than ==, and not because a webhook list is an attack
     * surface: the URL ends in this install's webhook secret, so this comparison
     * is a secret comparison whatever it is being used for, and a secret
     * compared with == in one place is how the habit of comparing them with ==
     * gets established.
     *
     * @param  array<int, array<string, mixed>>  $hooks
     * @return array<string, mixed>|null
     */
    private function matchingHook(array $hooks, string $url): ?array
    {
        foreach ($hooks as $hook) {
            $candidate = (string) ($hook['url'] ?? '');

            if ($candidate !== '' && hash_equals($url, $candidate)) {
                return $hook;
            }
        }

        return null;
    }

    /**
     * Hooks pointing at THIS shop's webhook path under a different secret.
     *
     * The prefix test is what keeps this from touching anything that is not
     * ours: it carries this install's host and base path as well as the
     * `/api/payments/webhook/tabby/` route, so a hook the merchant registered
     * for another system, another store or another environment does not match
     * and is never a candidate for deletion.
     *
     * @param  array<int, array<string, mixed>>  $hooks
     * @return array<int, array<string, mixed>>
     */
    private function staleHooks(array $hooks, string $url): array
    {
        $prefix = $this->webhookUrlPrefix();
        $stale = [];

        foreach ($hooks as $hook) {
            $candidate = (string) ($hook['url'] ?? '');

            if ($candidate === '' || hash_equals($url, $candidate)) {
                continue;
            }

            if (str_starts_with($candidate, $prefix)) {
                $stale[] = $hook;
            }
        }

        return $stale;
    }

    /** Tabby has answered `is_test` as a bool and as the strings "true"/"1". */
    private function hookIsTest(array $hook): bool
    {
        $value = $hook['is_test'] ?? false;

        return is_string($value)
            ? in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true)
            : (bool) $value;
    }

    /* -------------------------------------------------------- reconciliation */

    /**
     * Tabby's books, a page at a time.
     *
     *   GET /api/v2/payments?created_at__gte=&created_at__lte=&offset=&limit=
     *
     * REFUNDS COME OUT OF THE SAME LIST, and that is not a shortcut. Tabby does
     * not keep refunds anywhere else: a refund is an entry in the `refunds[]`
     * array of the payment it reverses, exactly as a capture is an entry in
     * `captures[]` — which is why refund() has to send a `capture_id` and why
     * lastId() reads the whole array back. Inventing a `/refunds` endpoint to
     * make this symmetrical with Stripe would be inventing an endpoint, and a
     * reconciliation whose refund half 404s is a reconciliation that reports
     * every refund this shop has made as unconfirmed.
     *
     * ---------------------------------------------------------------------
     * THE ONE THING HERE THAT IS NOT PROVEN
     *
     * The single-payment read (`GET /api/v2/payments/{id}`) is exercised by
     * the webhook and by capture, and is known good. The LIST form above —
     * the same collection without an id, with a date filter and an offset — is
     * taken from the same v2 API and cannot be verified from this project,
     * which has no Tabby merchant account. It is deliberately confined to this
     * one method for that reason.
     *
     * If it is wrong, the failure is safe and loud rather than quiet and
     * wrong: a 404 or a 401 returns RemotePage::failed(), the run records
     * `payments_source_unavailable` against Tabby, and every conclusion that
     * would have been drawn from Tabby's silence is SKIPPED. What the owner
     * sees is "Tabby's list of payments could not be read", not a clean bill
     * of health and not a page of invented discrepancies. Read RemotePage's
     * class comment; that property is the reason it is shaped the way it is.
     */
    public function listRemotePayments(ReconcileWindow $window, ?string $cursor, int $limit): RemotePage
    {
        return $this->listTabby($window, $cursor, $limit, RemoteTxn::PAYMENT);
    }

    public function listRemoteRefunds(ReconcileWindow $window, ?string $cursor, int $limit): RemotePage
    {
        return $this->listTabby($window, $cursor, $limit, RemoteTxn::REFUND);
    }

    public function remoteSourceLabel(): string
    {
        return 'api.tabby.ai /api/v2/payments (refunds are nested in each payment)';
    }

    private function listTabby(ReconcileWindow $window, ?string $cursor, int $limit, string $kind): RemotePage
    {
        if (! $this->configured()) {
            return RemotePage::unsupported('Tabby has no keys stored, so its books cannot be read.');
        }

        $limit = max(1, min($limit, 100));
        $offset = $cursor !== null && ctype_digit(trim($cursor)) ? (int) trim($cursor) : 0;

        $attempt = $this->attempt('GET', '/api/v2/payments?' . http_build_query([
            // RFC3339 with an explicit Z. Tabby timestamps in UTC and so does
            // ReconcileWindow, so there is no conversion here and none to get
            // wrong — see that class for why the boundary is padded instead.
            'created_at__gte' => $window->from->toIso8601ZuluString(),
            'created_at__lte' => $window->to->toIso8601ZuluString(),
            'offset' => $offset,
            'limit' => $limit,
        ]));

        if (! $attempt['ok']) {
            return RemotePage::failed($attempt['error'] ?? 'unreachable', $attempt['status']);
        }

        $payments = $this->paymentList($attempt['body']);
        $items = [];

        foreach ($payments as $payment) {
            if (! is_array($payment)) {
                continue;
            }

            if ($kind === RemoteTxn::PAYMENT) {
                $txn = $this->tabbyPaymentToTxn($payment);

                if ($txn !== null) {
                    $items[] = $txn;
                }

                continue;
            }

            foreach ($this->tabbyRefundsToTxns($payment) as $refund) {
                $items[] = $refund;
            }
        }

        /*
         * Offset paging, so "is there more" is "was this page full". The cursor
         * counts PAYMENTS in both modes, never refunds: the refund pass walks
         * the same collection, and advancing by the number of refunds found
         * would skip payments in proportion to how many refunds they happened
         * to carry.
         */
        $more = count($payments) >= $limit;

        return RemotePage::of($items, $more ? (string) ($offset + count($payments)) : null);
    }

    /** Tabby has answered collections as a bare array and as {payments:[…]}. */
    private function paymentList(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        foreach (['payments', 'data', 'results'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                return $body[$key];
            }
        }

        return array_is_list($body) ? $body : [];
    }

    private function tabbyPaymentToTxn(array $payment): ?RemoteTxn
    {
        $id = trim((string) ($payment['id'] ?? ''));

        if ($id === '') {
            return null;
        }

        $status = strtoupper((string) ($payment['status'] ?? ''));

        /*
         * CLOSED is Tabby's word for BOTH "captured" and "voided", and reading
         * it as settled flatly was a reconciliation that agreed with itself.
         *
         * A voided authorisation — cancelled order, released hold, nobody
         * charged — arrived here as SETTLED money. Reconciliation then matched
         * it against the order it belonged to and reported the pair as agreed,
         * which is the single worst answer available: not a discrepancy anybody
         * could chase, but a clean bill of health over money that does not
         * exist. The captures list is the only thing that separates the two.
         *
         * AUTHORIZED is money committed and not yet taken, which Tabby
         * auto-voids; it is not settled and must not be counted as such.
         * CREATED is a shopper who never finished.
         */
        $state = match (true) {
            $status === 'CLOSED' && $this->hasCapture($payment) => RemoteTxn::SETTLED,
            $status === 'CLOSED' => RemoteTxn::DEAD,
            $status === 'AUTHORIZED' => RemoteTxn::AUTHORISED,
            default => RemoteTxn::DEAD,
        };

        return new RemoteTxn(
            provider: $this->id(),
            kind: RemoteTxn::PAYMENT,
            remoteId: $id,
            matchKeys: [],
            reference: $this->referenceOf($payment),
            // Major-unit decimal string on this API. Through toFils(), which
            // goes via round() rather than an (int) cast on a float — (int)
            // (10.10 * 100) is 1009, and that is a one-fil discrepancy
            // reported against a perfectly correct order.
            amountFils: $this->toFils($payment['amount'] ?? 0),
            currency: strtoupper((string) ($payment['currency'] ?? '')),
            state: $state,
            rawState: $status,
            createdAt: $this->tabbyMoment($payment['created_at'] ?? null),
        );
    }

    /** @return array<int, RemoteTxn> */
    private function tabbyRefundsToTxns(array $payment): array
    {
        $refunds = $payment['refunds'] ?? null;

        if (! is_array($refunds)) {
            return [];
        }

        $reference = $this->referenceOf($payment);
        $currency = strtoupper((string) ($payment['currency'] ?? ''));
        $out = [];

        foreach ($refunds as $refund) {
            if (! is_array($refund)) {
                continue;
            }

            $id = trim((string) ($refund['id'] ?? ''));

            if ($id === '') {
                continue;
            }

            $out[] = new RemoteTxn(
                provider: $this->id(),
                kind: RemoteTxn::REFUND,
                remoteId: $id,
                matchKeys: [],
                reference: $reference,
                amountFils: $this->toFils($refund['amount'] ?? 0),
                currency: $currency,
                // A refund that appears in Tabby's list is a refund Tabby made.
                // There is no pending state on these entries.
                state: RemoteTxn::SETTLED,
                rawState: 'refunded',
                createdAt: $this->tabbyMoment($refund['created_at'] ?? null),
                // The payment this refund sits inside. Tabby does carry the
                // order reference too, so this is belt as well as braces — but
                // a reference is a string somebody could have changed, and the
                // payment id is what `payments.provider_ref` actually holds.
                parentKeys: [(string) ($payment['id'] ?? '')],
            );
        }

        return $out;
    }

    private function referenceOf(array $payment): ?string
    {
        $reference = $payment['order']['reference_id'] ?? null;

        return is_string($reference) && trim($reference) !== '' ? trim($reference) : null;
    }

    private function tabbyMoment(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            // A timestamp we cannot read is not a reason to drop a transaction
            // out of a reconciliation. It is used for display only.
            return null;
        }
    }

    /**
     * Tabby answers a capture or a refund with the payment's WHOLE list of
     * them, newest last, rather than with the one just made. The last id is
     * the transaction this call created.
     */
    private function lastId(mixed $list): ?string
    {
        if (! is_array($list) || $list === []) {
            return null;
        }

        $last = end($list);
        $id = is_array($last) ? ($last['id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Did money actually move on this payment?
     *
     * THE SINGLE READER FOR THE CLOSED AMBIGUITY, and the reason it is one
     * method rather than four `!empty($payment['captures'])` checks: this is the
     * difference between a sale and a released hold, it was read three different
     * ways before (and wrongly in all three), and the next place that needs the
     * answer must not be free to invent a fourth.
     *
     * An entry with no id does not count. Tabby has never answered one, but
     * `captures: [[]]` would otherwise report captured money with no transaction
     * to point at — which is the exact state capture() was writing when it
     * treated a void as a capture, reached by a different route.
     */
    private function hasCapture(array $payment): bool
    {
        $captures = $payment['captures'] ?? null;

        if (! is_array($captures)) {
            return false;
        }

        foreach ($captures as $capture) {
            if (is_array($capture) && trim((string) ($capture['id'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /* -------------------------------------------------------------- helpers */

    private function buyer(Order $order): array
    {
        $billing = is_array($order->billing_address) ? $order->billing_address : [];

        $name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));

        return [
            'email' => (string) $order->email,
            'name' => $name !== '' ? $name : (string) $order->email,
            // Tabby rejects a leading +.
            'phone' => ltrim((string) ($order->phone ?? $billing['phone'] ?? ''), '+'),
        ];
    }

    /**
     * Where the goods are going.
     *
     * One of Tabby's fraud signals and part of the same transaction they are
     * being asked to finance, so it travels with the buyer block rather than
     * behind the `share_order_history` switch — that switch is about OTHER
     * orders. Shipping address, falling back to billing, exactly as the plugin
     * falls back.
     *
     * Two fields and no more: `address` and `city` are what Tabby's schema
     * carries here, and a postcode or a phone added "while we are at it" would
     * be buyer data sent for no purpose.
     *
     * @return array<string, string>
     */
    private function shippingAddress(Order $order): array
    {
        $shipping = is_array($order->shipping_address) ? $order->shipping_address : [];
        $billing = is_array($order->billing_address) ? $order->billing_address : [];

        $line = $this->addressLine($shipping);

        if ($line === '') {
            $line = $this->addressLine($billing);
        }

        $city = trim((string) ($shipping['city'] ?? ''));

        if ($city === '') {
            $city = trim((string) ($billing['city'] ?? ''));
        }

        return ['address' => $line, 'city' => $city];
    }

    /** @param array<string, mixed> $address */
    private function addressLine(array $address): string
    {
        $parts = array_filter([
            trim((string) ($address['address_1'] ?? $address['address'] ?? '')),
            trim((string) ($address['address_2'] ?? '')),
        ], fn (string $part) => $part !== '');

        return implode(', ', $parts);
    }

    /**
     * Two aggregate numbers about the person buying.
     *
     * `registered_since` and `loyalty_level` — when this customer's account was
     * created and how many orders they have finished — which is the ordinary
     * fraud signal any payment provider is given, and is NOT behind
     * `share_order_history`: it names no order, no address and no amount.
     *
     * Null for a guest. A guest has no registration date and inventing one (the
     * order's own timestamp is the tempting candidate) would tell Tabby every
     * guest registered moments ago, which is a signal that is both false and
     * adverse.
     *
     * @return array<string, mixed>|null
     */
    private function buyerHistory(Order $order): ?array
    {
        $customerId = $order->customer_id;

        if ($customerId === null) {
            return null;
        }

        $customer = \App\Models\Customer::query()
            ->select(['id', 'created_at'])
            ->find($customerId);

        if ($customer === null || $customer->created_at === null) {
            return null;
        }

        return [
            'registered_since' => $customer->created_at->toIso8601String(),
            /*
             * Completed and refunded, which is how the plugin counts it: a
             * refunded order was still a real purchase that was really paid for,
             * and it is evidence about this buyer either way.
             */
            'loyalty_level' => (int) \App\Models\Order::query()
                ->where('customer_id', $customerId)
                ->whereIn('status', ['completed', 'refunded'])
                ->count(),
        ];
    }

    /**
     * Up to ten of this buyer's previous orders, and ONLY behind the switch.
     *
     * This is the payload half of `share_order_history`. Off — which is how it
     * ships — the key is absent from the request entirely rather than sent
     * empty.
     *
     * Matched to the customer by EMAIL and never by phone, although the plugin
     * offers both. A phone number is not unique on this shop's data (imported
     * WooCommerce orders share household numbers, and the column is nullable and
     * unvalidated), so a phone match would describe a stranger's purchases to
     * Tabby as this buyer's — which is both a worse signal and a disclosure
     * nobody consented to. The plugin has a `use_phone` switch for the same
     * reason and it is the one setting of theirs this refuses outright.
     *
     * Terminal statuses only, as HISTORY_STATUSES maps them: an order still in
     * flight says nothing about whether this buyer pays.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orderHistory(Order $order): array
    {
        if ($this->credentials->get($this->id(), 'share_order_history') !== '1') {
            return [];
        }

        $email = trim((string) $order->email);

        if ($email === '') {
            return [];
        }

        // One query for the orders and one for their items — never one per
        // order. Capped before the join, so the cost does not move with how many
        // orders this customer has.
        $previous = \App\Models\Order::query()
            ->where('email', $email)
            ->whereKeyNot($order->getKey())
            ->whereIn('status', array_keys(self::HISTORY_STATUSES))
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->with(['items:id,order_id,name,quantity,unit_price,total,sku,product_id'])
            ->get();

        return $previous->map(fn (Order $past) => [
            'amount' => $this->toMajor((int) $past->total),
            'payment_method' => (string) ($past->payment_method ?? ''),
            'purchased_at' => optional($past->created_at)->toIso8601String(),
            'status' => self::HISTORY_STATUSES[(string) $past->status] ?? 'canceled',
            'buyer' => $this->buyer($past),
            'shipping_address' => $this->shippingAddress($past),
            'items' => $this->items($past),
        ])->values()->all();
    }

    /**
     * The line items, in Tabby's shape.
     *
     * `unit_price` is the line TOTAL divided by the quantity, not the list
     * price, which is what the plugin sends (`get_total() / get_quantity()`).
     * It matters when a coupon or a line discount is on the order: Tabby checks
     * that the items, the shipping and the tax account for the amount, and list
     * prices on a discounted basket add up to more than is being charged. The
     * division is integer-safe because both sides are fils and the quantity
     * cannot be zero here — `order_items.quantity` is an unsigned default-1
     * column — but it is guarded anyway, because a divide by zero on the
     * checkout path is a 500 at the till.
     *
     * `reference_id` is the SKU when there is one and the product id otherwise,
     * which is how the shop's own exports key an item, so a line Tabby queries
     * can be found here.
     *
     * The product-derived fields the plugin also sends — `category`,
     * `image_url`, `product_url` and the full product `description` — are NOT
     * here. Reaching them means loading every product and its category behind
     * every item on the checkout POST, and the description in particular is a
     * page of HTML per line sent to a scoring API that does not read it. Tabby
     * accepts the payload without them; if approval rates ever argue for the
     * first three, `with('items.product.category')` is the shape to add and it
     * is two queries rather than a pattern.
     *
     * @return array<int, array<string, mixed>>
     */
    private function items(Order $order): array
    {
        return $order->items->map(function ($item) {
            $quantity = max(1, (int) $item->quantity);
            $lineTotal = (int) $item->total;

            return [
                'title' => (string) $item->name,
                'quantity' => (int) $item->quantity,
                'unit_price' => $this->toMajor(
                    $lineTotal > 0 ? (int) round($lineTotal / $quantity) : (int) $item->unit_price,
                ),
                'reference_id' => (string) ($item->sku ?: $item->product_id),
            ];
        })->values()->all();
    }
}
