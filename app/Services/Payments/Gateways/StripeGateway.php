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
use App\Services\Payments\WebhookOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stripe — cards, entered on our own checkout page.
 *
 * The card fields are on /checkout/ and there is no redirect to Stripe. The
 * fields themselves are Stripe Elements: cross-origin iframes served by
 * js.stripe.com and mounted into our page, so the shopper types into Stripe's
 * document and the card number is posted from there straight to Stripe. It
 * never enters this application's DOM and it is never sent to this server.
 *
 * What that costs, stated once because it changes an obligation rather than a
 * preference: an integration that serves the page the card is entered on is
 * SAQ-A-EP rather than SAQ-A. Elements is the arrangement that keeps it as
 * close to SAQ-A as an on-site form can be — it is what WooCommerce's own
 * Stripe plugin uses for its inline mode, which is the behaviour this store
 * is being matched against.
 *
 *   POST /v1/payment_intents        create an intent -> client_secret
 *   GET  /v1/payment_intents/{id}
 *   POST /v1/payment_intents/{id}/capture
 *   GET  /v1/checkout/sessions/{id} still read: orders placed through the
 *                                   previous hosted flow carry `cs_...` in
 *                                   `transaction_id` and must stay refundable
 *   Auth: Authorization: Bearer sk_...
 *   Form-encoded, NOT JSON — Stripe's API takes application/x-www-form-urlencoded
 *   Amounts are integer MINOR units, which is already how this schema stores
 *   money, so unlike Tabby and Tamara there is no conversion to get wrong.
 *
 * ---------------------------------------------------------------------------
 * Verification
 *
 * Stripe signs with `Stripe-Signature: t=<unix>,v1=<hex hmac>`, where the MAC
 * is HMAC-SHA256 of "<t>.<raw body>" keyed on the endpoint's signing secret
 * (whsec_...). Three things that are easy to get wrong and are each load
 * bearing:
 *
 *   - the RAW body, byte for byte. Re-encoding the decoded JSON changes key
 *     order and spacing and the MAC will never match.
 *   - hash_equals, not ==.
 *   - the timestamp must be checked. Without it a captured-and-replayed
 *     delivery stays valid forever, because the signature itself never
 *     expires.
 *
 * Unlike Tabby and Tamara there is no re-fetch here. A verified Stripe
 * signature is a strong statement about the body's integrity, and the event
 * carries `amount_total` from Stripe rather than from the client. That amount
 * is still compared against the order's own total by PaymentConfirmer, which
 * is what actually protects us.
 */
class StripeGateway extends RemoteGateway implements HandlesWebhooks, ListsTransactions, SettlesPayments
{
    private const API = 'https://api.stripe.com';

    /**
     * Days an uncaptured PaymentIntent survives before Stripe releases it.
     *
     * Only reachable at all if the intent is created with manual capture,
     * which this build does not do — see capture(). Kept because the window is
     * real whenever an intent IS created that way, and because a
     * capture screen that quietly reported "no window" for cards would be
     * telling the merchant something that stops being true the moment
     * somebody sets capture_method.
     */
    private const CAPTURE_DAYS = 7;

    public function id(): string
    {
        return 'stripe';
    }

    public function title(): string
    {
        return 'Credit or debit card';
    }

    /**
     * Can this gateway talk to Stripe at all? The secret key, and only that.
     *
     * Deliberately NOT widened to include the publishable key, although the
     * checkout now needs one. `configured()` gates settlement as well as
     * checkout — PaymentCapturer and PaymentRefunder both ask it — and an
     * account that is missing a publishable key must not thereby lose the
     * ability to refund money it has already taken. Whether a card can be
     * TYPED is a different question from whether this shop can reach Stripe,
     * and it is answered by availableFor() below.
     */
    public function configured(): bool
    {
        return $this->credentials->filled($this->id(), 'secret_key');
    }

    /**
     * On offer at the checkout — which now also needs the publishable key.
     *
     * The publishable key is what boots Stripe.js, and without it the card
     * iframes never mount. A shop with only the secret key filled in would
     * offer "Credit or debit card", draw an empty box under it and refuse
     * every Place order — the exact shape of the complaint that started this
     * work, with the fields missing for a different reason. Taking the option
     * off the list instead leaves the shopper something they can act on, and
     * leaves the merchant a gateway whose old orders are still refundable.
     *
     * The parent's rule still applies on top: nothing is offered for a
     * zero-total order.
     */
    public function availableFor(int $totalFils, ?string $country = null): bool
    {
        return parent::availableFor($totalFils, $country)
            && $this->credentials->filled($this->id(), 'publishable_key');
    }

    /**
     * The publishable key, for the checkout page to boot Stripe.js with.
     *
     * Public by design and by name — it identifies the account to Stripe and
     * authorises nothing. The secret key has no accessor here and never leaves
     * GatewayCredentials; PaymentSecretsTest pins that.
     */
    public function publishableKey(): string
    {
        return $this->credentials->get($this->id(), 'publishable_key');
    }

    /**
     * NOTHING, DELIBERATELY, AND THIS IS A REMOVAL RATHER THAN AN OVERSIGHT.
     *
     * Three sentences used to stand here and print as a paragraph directly
     * above the card fields. The owner asked for them to go — "make the field
     * more nice and clear" — and for one short line with a padlock in their
     * place. That line is `store.checkout.card_secure_line`, drawn by
     * partials/checkout/stripe-card at the top of the fields it describes,
     * where it is keyed for translation like the rest of the checkout. A
     * sentence returned from here could not be: this method is read by the
     * admin and by the API as well as by the page, and it has never gone
     * through __().
     *
     * partials/checkout/payment-methods draws the .payment_box for the card
     * gateway whether or not there is a description, precisely so that this
     * returning null cannot take the card fields off the page with it.
     */
    public function description(int $totalFils): ?string
    {
        return null;
    }

    public function configSchema(): array
    {
        return [
            'publishable_key' => ['text', 'Publishable key', 'Starts pk_test_ or pk_live_. Safe to appear in the page.'],
            'secret_key' => ['secret', 'Secret key', 'Starts sk_test_ or sk_live_. Never leaves the server, and is never returned by any API.'],
            'webhook_signing_secret' => ['secret', 'Webhook signing secret', 'Starts whsec_. From Stripe Dashboard -> Developers -> Webhooks, after adding the endpoint URL below. Without it no webhook can be verified.'],
            'webhook_secret' => ['secret', 'URL secret', 'Generated for you. Forms part of the webhook URL below.'],
            /*
             * NOT A CREDENTIAL — a switch, and the first entry in any gateway's
             * schema that is one. Two consequences follow, and both are handled
             * rather than assumed:
             *
             *   - GatewayPreflight lists an empty schema key as a field still to
             *     be pasted in. A switch that is off is not a missing
             *     credential, and "Still to paste in: Stripe Link" on a
             *     correctly configured shop would be a false alarm on the one
             *     screen that exists to remove them. It skips `bool` entries.
             *   - The admin console draws an unknown type as a text box. The
             *     anchor and replacement that teach it `bool` are in
             *     docs/FY-CHECKOUT-CARD-AND-PREFILL.md; until they land the
             *     value is still settable as '1' or empty, and the default
             *     below is what a shop that never touches it gets.
             *
             * DEFAULT OFF, which is the owner's stated preference and is what an
             * absent key already reads as — so a shop that has never seen this
             * field has Link off from the moment the package lands, with no save
             * required and nothing to remember.
             */
            'link_enabled' => ['bool', 'Stripe Link', 'Stripe’s own one-click autofill, offered inside the card number field. Off by default: it asks the shopper to save their card with Stripe rather than with this shop, and it puts a second sign-in in the middle of the checkout.'],
        ];
    }

    /**
     * Is Stripe Link offered inside the card number field?
     *
     * Compared as a string rather than cast: GatewayCredentials stores whatever
     * the admin posted, so the honest question is "did somebody switch this
     * on", and every other value — absent, '', '0' — is off.
     */
    public function linkEnabled(): bool
    {
        return $this->credentials->get($this->id(), 'link_enabled') === '1';
    }

    protected function baseUrl(): string
    {
        return self::API;
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->credentials->get($this->id(), 'secret_key')];
    }

    /* ------------------------------------------------------------- checkout */

    public function start(Order $order): PaymentStart
    {
        return $this->openIntent($order, saveCard: false);
    }

    /**
     * The same payment, with the card kept for next time.
     *
     * A SEPARATE ENTRY POINT rather than a parameter on start(), because
     * start() is PaymentGateway's and every gateway implements it: widening
     * that signature would ask Cash on Delivery, Tabby and Tamara to carry an
     * argument that means nothing to any of them. CheckoutController names this
     * method only after it has satisfied itself that the shopper has an account
     * to attach the card to — see the guard there, which is the one that
     * matters.
     */
    public function startAndSaveCard(Order $order): PaymentStart
    {
        return $this->openIntent($order, saveCard: true);
    }

    private function openIntent(Order $order, bool $saveCard): PaymentStart
    {
        if (! $this->configured()) {
            return PaymentStart::failed('Card payment is not available right now.');
        }

        $currency = strtolower((string) ($order->currency ?: 'AED'));

        /*
         * The Stripe Customer the card will hang off, made or found now.
         *
         * A NULL HERE DOES NOT REFUSE THE SALE. `setup_future_usage` without a
         * customer is an error at Stripe, so if this shop cannot get one the
         * choice is between taking the payment without saving the card and not
         * taking the payment at all — and losing an order because a convenience
         * failed is the worse of the two by a long way. It is logged, loudly
         * enough to find, and the shopper is charged exactly what they agreed
         * to. The follow-up that offers saved cards back will find nothing
         * saved for that order, which is the truth.
         */
        $stripeCustomer = $saveCard ? $this->stripeCustomerFor($order) : null;

        /*
         * AN INTENT THIS ORDER ALREADY HAS IS REUSED, NEVER REPLACED.
         *
         * This is the first half of the double-submit guard, and it is the
         * half that is about Stripe rather than about us. The second half is
         * older than this change and belongs to the checkout: placing an order
         * marks the cart `converted`, and CartService::resolve() only ever
         * finds an `active` one, so the second of two POSTs arrives with no
         * cart and is turned away before it can mint an order at all.
         *
         * That leaves the case this guard covers: the SAME order reaching
         * start() twice — a retried request, a package that re-runs the step,
         * or the shopper coming back to a payment they abandoned. Creating a
         * second intent there would leave two live authorisations against one
         * order, only one of which `transaction_id` can name, and the other is
         * money nobody is watching.
         *
         * A live intent is handed back with its own client secret, so the
         * browser confirms the one that already exists. A declined card leaves
         * the intent in `requires_payment_method` — alive and retryable, which
         * is Stripe's own model for a retry and the reason a decline needs no
         * new order and no new intent.
         */
        $existing = $this->reusableIntent($order, $currency, $stripeCustomer);

        if ($existing !== null) {
            return $existing;
        }

        /*
         * One amount, not a line-item breakdown — the same reasoning the
         * Checkout session had. A breakdown would have to reproduce this
         * order's discount and shipping apportionment exactly or Stripe's
         * total would disagree with ours by a fil, and the order's own total
         * is the figure the webhook will be checked against.
         */
        $payload = [
            // Already integer minor units. No conversion, no float.
            'amount' => (int) $order->total,
            'currency' => $currency,
            /*
             * CARDS, NAMED EXPLICITLY, rather than automatic_payment_methods.
             *
             * Two reasons and both are about not surprising anybody. The
             * option on the checkout says "Credit or debit card" and that is
             * what it must be — automatic methods would put whatever is
             * switched on in the Stripe dashboard into this box, including
             * methods that navigate away from the page, which is the one thing
             * this build is not allowed to do. And a fixed list makes the
             * Payment Element deterministic: the same fields render for every
             * shopper regardless of a dashboard setting nobody here can see.
             *
             * 3-D Secure is unaffected. It is not a payment method, it is the
             * issuer's authentication step on a card payment, and Stripe runs
             * it in a modal over our page.
             */
            'payment_method_types' => ['card'],
            'description' => 'Order ' . $this->reference($order),
            /*
             * The stamp every other path reads. `metadata` on a PaymentIntent
             * is copied onto its charge, which is what lets reconciliation say
             * "order KBB-1042" instead of "some charge" — see chargeToTxn().
             *
             * Under the Checkout flow this had to be set twice, once on the
             * session and once through `payment_intent_data`, because session
             * metadata does not propagate. There is no session now, so there
             * is one place to set it and no second copy to fall out of step.
             */
            'metadata' => ['order_number' => $this->reference($order)],
        ];

        /*
         * KEEPING THE CARD, when the shopper asked for it and only then.
         *
         * `on_session` rather than `off_session`, and the difference is a
         * promise rather than a preference. It states that this card will be
         * reused with the shopper PRESENT, at a checkout they are looking at —
         * which is what "save this card for future purchases" offers and all
         * this shop will ever do with it. `off_session` claims the right to
         * charge it while they are away, asks the issuer for the stronger
         * authentication that goes with that claim, and would be a larger
         * promise than the tick makes.
         *
         * `customer` is required alongside it: a saved card has to be attached
         * to somebody, and Stripe refuses setup_future_usage without one.
         */
        if ($stripeCustomer !== null) {
            $payload['customer'] = $stripeCustomer;
            $payload['setup_future_usage'] = 'on_session';
        }

        /*
         * Stripe's own replay guard, keyed on the order.
         *
         * The reuse check above reads `orders.transaction_id`, so it cannot
         * see a request that reached Stripe and whose response never reached
         * us — the intent exists, we never learned its id, and nothing was
         * written. This key makes the retry of that request return the
         * ORIGINAL intent rather than create a second one. Same role the
         * unique index on `refunds.idempotency_key` plays for refunds, for the
         * one case an index cannot see.
         */
        $attempt = $this->stripeAttempt(
            'POST',
            '/v1/payment_intents',
            $payload,
            /*
             * THE KEY CARRIES THE SHAPE OF THE REQUEST, not just the order.
             * Stripe refuses a second request that reuses a key with different
             * parameters, and an order whose reusable intent was rejected above
             * for having the wrong setup_future_usage asks for exactly that —
             * same order, different payload. A 400 there would be a checkout
             * that cannot take a card for a shopper who merely changed their
             * mind about a tick.
             */
            'kbb-intent-' . $this->reference($order) . ($stripeCustomer === null ? '' : '-save'),
        );

        $result = $attempt['body'];
        $secret = $result['client_secret'] ?? null;
        $intentId = $result['id'] ?? null;

        if (! is_string($secret) || $secret === '' || ! is_string($intentId) || $intentId === '') {
            return PaymentStart::failed('We could not reach our card processor. Please try another payment method.');
        }

        $order->forceFill(['transaction_id' => $intentId])->save();

        // The id, never the secret. This log goes to a file a support person
        // reads; a client secret in it is a handle to the payment.
        $this->log('payment intent created', '/v1/payment_intents', 200, [
            'reference' => $this->reference($order),
            'payment_intent' => $intentId,
            'saves_card' => $stripeCustomer !== null,
        ]);

        return PaymentStart::confirm($secret, $intentId);
    }

    /**
     * The Stripe Customer this order's card may be attached to, or null.
     *
     * ── WHY THE ID IS STORED PER MODE ──────────────────────────────────────
     *
     * A `cus_...` minted with test keys does not exist to an account using live
     * keys, and vice versa. Stored as one id, the first live order placed by a
     * customer who had ordered in test mode would send Stripe a customer it has
     * never heard of, and the intent — the whole payment — would be refused.
     * That is a checkout outage caused by a switch the merchant is expected to
     * throw exactly once, so the column holds a small map keyed by mode and
     * each half is looked up on its own.
     *
     * ── AND WHY A FAILURE HERE IS NOT AN ERROR ─────────────────────────────
     *
     * Every return of null means "no card will be saved for this order", and
     * openIntent() goes on to take an ordinary payment. Nothing here can refuse
     * a sale.
     */
    private function stripeCustomerFor(Order $order): ?string
    {
        $customer = $order->customer;

        if (! $customer instanceof \App\Models\Customer) {
            return null;
        }

        $mode = $this->credentials->live($this->id()) ? 'live' : 'test';
        $stored = $customer->stripeCustomerId($mode);

        if ($stored !== null) {
            return $stored;
        }

        $attempt = $this->stripeAttempt(
            'POST',
            '/v1/customers',
            array_filter([
                'email' => (string) ($customer->email ?: $order->email),
                'name' => trim((string) $customer->displayName()),
                // So a person looking at the Stripe dashboard can tell which
                // shopper this is without a second lookup. The id, not the
                // email again — the email is already the field above it.
                'metadata' => ['kbb_customer_id' => (string) $customer->id],
            ], fn ($value) => $value !== '' && $value !== []),
            // Keyed on the customer, so the retry of a request whose answer
            // never arrived returns the customer that was made rather than
            // making a second one. Stripe expires these after 24 hours, which
            // is only reachable at all if the write below failed as well.
            'kbb-customer-' . $mode . '-' . $customer->id,
        );

        $id = is_array($attempt['body']) ? ($attempt['body']['id'] ?? null) : null;

        if (! is_string($id) || ! str_starts_with($id, 'cus_')) {
            $this->log('customer not created', '/v1/customers', $attempt['status'], [
                'reference' => $this->reference($order),
                'error' => $attempt['error'],
            ]);

            return null;
        }

        $customer->rememberStripeCustomerId($mode, $id);

        $this->log('customer created', '/v1/customers', $attempt['status'], [
            'reference' => $this->reference($order),
            'stripe_customer' => $id,
        ]);

        return $id;
    }

    /**
     * An intent this order can still be paid with, or null.
     *
     * Deliberately strict about what counts as reusable, because the failure
     * mode of getting it wrong is charging somebody twice:
     *
     *   - it must be a PaymentIntent. An order carrying a `cs_...` was placed
     *     through the old hosted flow; there is nothing on this page that can
     *     confirm one, so it gets a fresh intent.
     *   - the amount and currency must still match the order. A basket that
     *     changed between attempts is a different sum of money, and
     *     PaymentConfirmer would refuse the confirmation anyway — better to
     *     find that out here, where a new intent for the right amount is the
     *     answer, than at the webhook where the money has already moved.
     *   - the status must be one that can still be confirmed. `succeeded`,
     *     `processing` and `requires_capture` are all money that has already
     *     moved and must never be re-offered to a card form; `canceled` is
     *     dead.
     *
     * A read that fails for any reason returns null and a new intent is made.
     * That is the safe direction: at worst one unused intent, which expires
     * without ever having been confirmed, against the alternative of a
     * checkout that cannot take a payment because Stripe was briefly slow.
     */
    private function reusableIntent(Order $order, string $currency, ?string $stripeCustomer): ?PaymentStart
    {
        $ref = trim((string) $order->transaction_id);

        if ($ref === '' || ! str_starts_with($ref, 'pi_')) {
            return null;
        }

        $read = $this->stripeAttempt('GET', '/v1/payment_intents/' . urlencode($ref));

        if (! $read['ok'] || ! is_array($read['body'])) {
            return null;
        }

        $intent = $read['body'];
        $status = (string) ($intent['status'] ?? '');

        $confirmable = ['requires_payment_method', 'requires_confirmation', 'requires_action'];

        if (! in_array($status, $confirmable, true)) {
            return null;
        }

        if ((int) ($intent['amount'] ?? -1) !== (int) $order->total
            || strtolower((string) ($intent['currency'] ?? '')) !== $currency) {
            return null;
        }

        /*
         * AND IT MUST ALREADY BE THE INTENT THE SHOPPER ASKED FOR.
         *
         * `setup_future_usage` is fixed when the intent is created. An intent
         * opened without it cannot save the card however it is confirmed, so
         * reusing one for a shopper who has since ticked "save this card" would
         * take the money, show them their tick, and save nothing — the exact
         * shape of defect this feature was warned about. The reverse matters
         * too: reusing a saving intent for somebody who has since un-ticked it
         * would keep a card they asked us not to keep.
         *
         * Same answer as the amount test above, and the same cost: the old
         * intent is left behind unconfirmed and expires at Stripe without ever
         * having been a charge. In practice the checkout gets there first —
         * either tick releases the order through the change handler in
         * partials/checkout/stripe-elements — so this is the backstop rather
         * than the mechanism.
         */
        $wanted = $stripeCustomer === null ? null : 'on_session';

        if (($intent['setup_future_usage'] ?? null) !== $wanted) {
            return null;
        }

        if ($stripeCustomer !== null && (string) ($intent['customer'] ?? '') !== $stripeCustomer) {
            return null;
        }

        $secret = $intent['client_secret'] ?? null;
        $id = $intent['id'] ?? null;

        if (! is_string($secret) || $secret === '' || ! is_string($id) || $id === '') {
            return null;
        }

        $this->log('payment intent reused', '/v1/payment_intents', 200, [
            'reference' => $this->reference($order),
            'payment_intent' => $id,
            'status' => $status,
        ]);

        return PaymentStart::confirm($secret, $id);
    }

    /*
     * form() stood here: a POST that kept only a successful body, and the only
     * caller was start() creating a Checkout session. start() now needs the
     * failure as well — an intent that could not be created is the difference
     * between "try another method" and a checkout that silently offers a card
     * form with nothing behind it — so it uses stripeAttempt() directly, and a
     * method with no callers is a method that will be wired up to the wrong
     * thing later.
     */

    /**
     * The same form-encoded call, with the failure kept.
     *
     * form() throws away everything but a successful body, which is right for
     * checkout and wrong for settlement: a declined refund has to reach
     * `payment_events` with Stripe's own error code on it. Same wire format,
     * same timeout, same logging rule — no bodies, ever.
     *
     * `Idempotency-Key` is Stripe's native replay guard and is passed through
     * when the caller has one. Our unique index on `refunds.idempotency_key`
     * is the guard that actually protects the ledger; this one covers the case
     * that index cannot see, where our request reached Stripe and the response
     * never reached us.
     *
     * @return array{ok: bool, status: int|null, body: array|null, error: string|null}
     */
    private function stripeAttempt(string $method, string $path, array $payload = [], ?string $idempotencyKey = null): array
    {
        $headers = $this->authHeaders();

        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            // Stripe caps this at 255 characters.
            $headers['Idempotency-Key'] = substr(trim($idempotencyKey), 0, 255);
        }

        try {
            $request = Http::withHeaders($headers)->timeout(self::TIMEOUT)->asForm();
            $url = rtrim($this->baseUrl(), '/') . $path;

            $response = strtoupper($method) === 'GET'
                ? $request->get($url)
                : $request->post($url, $this->flatten($payload));
        } catch (\Throwable $e) {
            $this->log('transport error', $path, null, ['error' => $e->getMessage()]);

            return ['ok' => false, 'status' => null, 'body' => null, 'error' => 'transport_error'];
        }

        $this->log('api call', $path, $response->status());

        $decoded = $response->json();
        $decoded = is_array($decoded) ? $decoded : null;

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => $response->successful() ? $decoded : null,
            'error' => $response->successful() ? null : $this->errorCode($decoded),
        ];
    }

    /** ['a' => ['b' => 1]] -> ['a[b]' => 1] */
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

    /* ---------------------------------------------- the browser's own report */

    /**
     * The page says the card went through. Ask Stripe.
     *
     * WHY THIS EXISTS AT ALL, given the webhook is the authority. Confirming
     * on the webhook alone means the order is still `pending` at the moment
     * the shopper is looking at the order-received page — the receipt, the
     * status and the stock movement all land a few seconds later, and on a
     * shop whose webhook endpoint is not configured yet, never. Under the
     * hosted flow nobody noticed, because the shopper spent those seconds on
     * Stripe's domain. On our own page the gap is in front of them.
     *
     * WHY IT IS SAFE. Nothing the browser says is believed. The order number
     * only selects which order is being asked about; the amount, the currency
     * and the status all come from a server-to-server read of the intent named
     * by `orders.transaction_id`, which the browser has never been in a
     * position to write. A shopper who posts somebody else's order number gets
     * whatever that order's own intent actually says, which for an unpaid
     * order is "not paid" — and CheckoutController gates the call on the
     * session that placed the order before it ever reaches here.
     *
     * WHY IT CANNOT DOUBLE-APPLY. It goes through PaymentConfirmer, exactly as
     * the webhook does, with the same PaymentIntent id as the reference. The
     * confirmer takes its claim under a lock against a null `paid_at`, so of
     * this call and the webhook — in either order, or at the same instant —
     * the first applies and the second reports "already applied".
     */
    public function confirmFromBrowser(Order $order): WebhookOutcome
    {
        $intentId = trim((string) $order->transaction_id);

        if ($intentId === '' || ! str_starts_with($intentId, 'pi_')) {
            return WebhookOutcome::refused('this order has no card payment to confirm');
        }

        $read = $this->stripeAttempt('GET', '/v1/payment_intents/' . urlencode($intentId));

        if (! $read['ok'] || ! is_array($read['body'])) {
            // Not a refusal: we could not ask. The webhook is still coming and
            // is still the authority, so this says "not yet", not "no".
            return WebhookOutcome::failed('could not reach the card processor');
        }

        $intent = $read['body'];
        $status = (string) ($intent['status'] ?? '');

        if ($status !== 'succeeded') {
            return WebhookOutcome::ignored('the payment has not succeeded');
        }

        return $this->confirmer->confirm(
            $order,
            $this->id(),
            $intentId,
            // Stripe's figures, read from Stripe. Never the browser's.
            (int) ($intent['amount_received'] ?? $intent['amount'] ?? 0),
            (string) ($intent['currency'] ?? ''),
            [
                'event_type' => 'browser_confirmation',
                'payment_intent' => $intentId,
                'reference' => $this->reference($order),
            ],
        );
    }

    /**
     * The shopper gave up on this card payment. Close the intent.
     *
     * Returns true only when Stripe has confirmed the intent is `canceled`, or
     * that it was already dead. The caller releases the order's stock and
     * coupon on a true and on nothing else, and that ordering is the whole
     * point: an intent that is still confirmable is one a stale tab, a
     * back-button or a half-finished 3-D Secure window can still put money
     * through, and doing that against an order whose stock has gone back on
     * the shelf is the one outcome that costs a real customer a real product.
     *
     * A payment that has already succeeded is never cancelled here. Stripe
     * would refuse it anyway, but saying so explicitly keeps the reason in the
     * code that depends on it: money that has moved is a refund, which is
     * PaymentRefunder's job and a decision for the merchant.
     */
    public function abandonIntent(Order $order): bool
    {
        $intentId = trim((string) $order->transaction_id);

        if ($intentId === '' || ! str_starts_with($intentId, 'pi_')) {
            // Nothing was ever opened, so there is nothing to keep open.
            return true;
        }

        $read = $this->stripeAttempt('GET', '/v1/payment_intents/' . urlencode($intentId));

        if (! $read['ok'] || ! is_array($read['body'])) {
            return false;
        }

        $status = (string) ($read['body']['status'] ?? '');

        if (in_array($status, ['succeeded', 'processing', 'requires_capture'], true)) {
            return false;
        }

        if ($status === 'canceled') {
            return true;
        }

        $cancel = $this->stripeAttempt(
            'POST',
            '/v1/payment_intents/' . urlencode($intentId) . '/cancel',
            ['cancellation_reason' => 'abandoned'],
        );

        $this->log('payment intent cancelled', '/v1/payment_intents/cancel', $cancel['status'], [
            'reference' => $this->reference($order),
            'payment_intent' => $intentId,
            'ok' => $cancel['ok'] ? 'yes' : 'no',
        ]);

        return $cancel['ok'] && (string) ($cancel['body']['status'] ?? '') === 'canceled';
    }

    /* -------------------------------------------------------------- webhook */

    public function handleWebhook(Request $request): WebhookOutcome
    {
        // (1) URL secret, before anything else.
        $urlSecret = $this->credentials->get($this->id(), 'webhook_secret');

        if ($urlSecret === '' || ! Signature::equals($urlSecret, (string) $request->route('secret'))) {
            return WebhookOutcome::rejected();
        }

        // (2) Stripe's own signature, over the RAW body.
        $signing = $this->credentials->get($this->id(), 'webhook_signing_secret');
        $raw = $request->getContent();

        if (! $this->signatureValid($request->header('Stripe-Signature'), $raw, $signing)) {
            return WebhookOutcome::rejected();
        }

        $event = json_decode($raw, true);

        if (! is_array($event)) {
            return WebhookOutcome::refused('unparseable body');
        }

        $type = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? [];
        $object = is_array($object) ? $object : [];

        $reference = $object['client_reference_id']
            ?? ($object['metadata']['order_number'] ?? null);

        $order = $this->findOrderByReference(is_string($reference) ? $reference : null);

        if ($order === null) {
            return WebhookOutcome::refused('no order for that reference');
        }

        $summary = [
            'event_id' => $event['id'] ?? null,
            'event_type' => $type,
            /*
             * `object_id`, not `session_id`. It was named for the only thing
             * that used to arrive here — a Checkout session — and it now holds
             * a PaymentIntent id on every event a card payment produces. An
             * audit row whose key says `session_id` beside a `pi_...` is the
             * kind of small lie that sends somebody looking in the wrong place
             * in Stripe's dashboard at the worst possible moment. Nothing reads
             * the old name; it was written and never consumed.
             */
            'object_id' => $object['id'] ?? null,
            'reference' => $reference,
        ];

        /*
         * ------------------------------------------------- the card path now
         *
         * `payment_intent.succeeded` is the event that matters since the card
         * fields moved onto our own page: there is no Checkout session, so
         * `checkout.session.completed` never arrives for a new order.
         *
         * IT IS NOT DECORATIVE. The browser also tells us the payment
         * succeeded, and the browser is the half that can vanish — a closed
         * laptop between the bank's approval and the confirmation request
         * leaves money taken and, without this, a shop that never heard about
         * it. That is strictly worse than the redirect this replaced, so the
         * webhook is the authority and the browser's report is the fast path.
         * Both go through PaymentConfirmer, which applies at most once, so
         * whichever arrives second is refused as a replay.
         *
         * `amount_received`, not `amount`. `amount` is what the intent asked
         * for; `amount_received` is what was actually taken, and on a partial
         * capture the two differ. PaymentConfirmer compares this figure with
         * the order's own total and refuses a mismatch, which is precisely the
         * comparison that must be made against money moved rather than money
         * requested.
         */
        if ($type === 'payment_intent.succeeded') {
            return $this->confirmer->confirm(
                $order,
                $this->id(),
                (string) ($object['id'] ?? ''),
                (int) ($object['amount_received'] ?? $object['amount'] ?? 0),
                (string) ($object['currency'] ?? ''),
                $summary,
            );
        }

        /*
         * A DECLINE IS NOT THE END OF THE ORDER, and this distinction is the
         * one the move to on-site fields makes load bearing.
         *
         * Stripe puts a PaymentIntent back to `requires_payment_method` when a
         * charge is declined. The intent is alive; the shopper is still on our
         * checkout, still holding their basket, and the whole point of card
         * fields on the page is that they can try another card into the same
         * form. Stripe emits `payment_intent.payment_failed` for that attempt.
         *
         * Failing the order on it would mean the shopper's second card
         * succeeds against an order that, by then, is `failed` — a status in
         * PaymentConfirmer::VOID, so the confirmation is refused, the order is
         * never marked paid, and the stock and the coupon have already been
         * handed back to somebody else. Money taken, nothing sold. Under the
         * hosted flow this was survivable because Stripe's own page retried
         * internally and the shopper never came back to ours.
         *
         * So the intent's own status decides. Still confirmable means a failed
         * attempt, not a failed order, and is left alone. `canceled` is
         * terminal and is the one that fails the order — as is
         * `checkout.session.expired`, which is the same fact for an order
         * placed through the previous hosted flow.
         */
        if ($type === 'payment_intent.payment_failed') {
            $intentStatus = (string) ($object['status'] ?? '');

            if (in_array($intentStatus, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                return WebhookOutcome::ignored('card declined; the payment can still be retried');
            }
        }

        if (in_array($type, ['checkout.session.expired', 'payment_intent.payment_failed', 'payment_intent.canceled'], true)) {
            return $this->confirmer->fail(
                $order, $this->id(), (string) ($object['id'] ?? ''), str_replace('.', '_', $type), $summary,
            );
        }

        /*
         * ------------------------------------------- the hosted path, kept
         *
         * Orders placed before this package carry a Checkout session and their
         * webhooks are still in flight, still being retried, and still have to
         * be applied. Deleting this arm would strand every payment that was in
         * progress when the update landed.
         */
        if ($type !== 'checkout.session.completed' && $type !== 'checkout.session.async_payment_succeeded') {
            return WebhookOutcome::ignored('event type not acted on');
        }

        // A completed session is not necessarily a paid one -- a delayed method
        // can complete the session and settle later.
        if (($object['payment_status'] ?? '') !== 'paid') {
            return WebhookOutcome::ignored('session completed but not paid');
        }

        return $this->confirmer->confirm(
            $order,
            $this->id(),
            (string) ($object['payment_intent'] ?? $object['id'] ?? ''),
            // Already minor units. Stripe's figure, not the client's.
            (int) ($object['amount_total'] ?? 0),
            (string) ($object['currency'] ?? ''),
            $summary,
        );
    }

    /* ----------------------------------------------------------- settlement */

    public function captureWindow(): string
    {
        return sprintf(
            'Card payments through Checkout are captured by Stripe at authorisation, so there is normally nothing to do. '
            . 'An authorisation deliberately left uncaptured lapses after about %d days.',
            self::CAPTURE_DAYS,
        );
    }

    public function captureWindowDays(): ?int
    {
        return self::CAPTURE_DAYS;
    }

    /**
     * Capture a card payment.
     *
     * This build creates Checkout sessions with Stripe's default automatic
     * capture, so by the time `checkout.session.completed` arrives the money
     * has already moved and the honest answer to "capture this" is "Stripe
     * already did". The PaymentIntent is read rather than assumed, because the
     * alternative is a screen that reports a capture that never happened:
     *
     *   succeeded          already captured. ok(), no write, no second call.
     *   requires_capture   a manual-capture intent. Capture it for real.
     *   anything else      requires_payment_method, canceled — nothing to take.
     */
    public function capture(Order $order, int $amountFils): SettlementResult
    {
        if (! $this->configured()) {
            return SettlementResult::failed('not_configured', ['provider' => $this->id()], 'Stripe is not configured.');
        }

        $intentId = $this->paymentIntentId($order);

        if ($intentId === null) {
            return SettlementResult::failed(
                'no_payment_intent',
                ['provider' => $this->id()],
                'This order has no Stripe payment on it yet.',
            );
        }

        $read = $this->stripeAttempt('GET', '/v1/payment_intents/' . urlencode($intentId));

        if (! $read['ok']) {
            return SettlementResult::failed(
                $read['error'] ?? 'unreachable',
                ['provider' => $this->id(), 'payment_intent' => $intentId, 'http_status' => $read['status']],
                'Stripe could not be reached. Nothing was captured; try again.',
            );
        }

        $status = (string) ($read['body']['status'] ?? '');

        if ($status === 'succeeded') {
            return SettlementResult::ok(
                'already_captured',
                $intentId,
                ['provider' => $this->id(), 'payment_intent' => $intentId, 'status' => $status],
                'Stripe captured this card payment at authorisation; there was nothing left to take.',
            );
        }

        if ($status !== 'requires_capture') {
            return SettlementResult::failed(
                'not_capturable',
                ['provider' => $this->id(), 'payment_intent' => $intentId, 'status' => $status],
                'Stripe reports this payment as ' . ($status !== '' ? $status : 'unknown') . ', so there is nothing to capture.',
            );
        }

        $attempt = $this->stripeAttempt(
            'POST',
            '/v1/payment_intents/' . urlencode($intentId) . '/capture',
            // Already integer minor units, which is how this schema stores
            // money. No conversion here, so none to get wrong.
            ['amount_to_capture' => $amountFils],
            'capture:' . $order->order_number,
        );

        if (! $attempt['ok'] || (string) ($attempt['body']['status'] ?? '') !== 'succeeded') {
            return SettlementResult::failed(
                $attempt['error'] ?? 'capture_rejected',
                [
                    'provider' => $this->id(),
                    'payment_intent' => $intentId,
                    'http_status' => $attempt['status'],
                    'error' => $attempt['error'],
                ],
                'Stripe refused the capture. Nothing was taken.',
            );
        }

        return SettlementResult::ok(
            'captured',
            $intentId,
            ['provider' => $this->id(), 'payment_intent' => $intentId],
            'Captured through Stripe.',
        );
    }

    /**
     * Refund some or all of a card payment.
     *
     * `POST /v1/refunds` against the PaymentIntent, not the Checkout session —
     * a session id is not a thing Stripe will refund.
     *
     * `reason` on this endpoint is an enum of three values, not free text, and
     * sending the merchant's own wording would be a 400. The wording goes in
     * metadata instead, where it is visible in the Stripe dashboard beside the
     * refund it explains.
     */
    public function refund(
        Order $order,
        int $amountFils,
        ?string $reason,
        ?string $captureRef,
        ?string $idempotencyKey = null,
    ): SettlementResult {
        if (! $this->configured()) {
            return SettlementResult::failed('not_configured', ['provider' => $this->id()], 'Stripe is not configured.');
        }

        $intentId = $this->paymentIntentId($order);

        if ($intentId === null) {
            return SettlementResult::failed(
                'no_payment_intent',
                ['provider' => $this->id()],
                'This order has no Stripe payment on it yet.',
            );
        }

        $payload = [
            'payment_intent' => $intentId,
            'amount' => $amountFils,
            'reason' => 'requested_by_customer',
        ];

        if ($reason !== null && trim($reason) !== '') {
            $payload['metadata'] = ['note' => substr(trim($reason), 0, 500)];
        }

        $attempt = $this->stripeAttempt('POST', '/v1/refunds', $payload, $idempotencyKey);

        $refundId = $attempt['ok'] ? ($attempt['body']['id'] ?? null) : null;
        $refundStatus = $attempt['ok'] ? (string) ($attempt['body']['status'] ?? '') : '';

        // `failed` and `canceled` are real Stripe refund states and both come
        // back on a 200. A refund is only a refund when Stripe says pending or
        // succeeded; anything else is money that did not move.
        if (! $attempt['ok'] || ! is_string($refundId) || ! in_array($refundStatus, ['succeeded', 'pending'], true)) {
            return SettlementResult::failed(
                $attempt['error'] ?? ($refundStatus !== '' ? 'refund_' . $refundStatus : 'refund_rejected'),
                [
                    'provider' => $this->id(),
                    'payment_intent' => $intentId,
                    'http_status' => $attempt['status'],
                    'error' => $attempt['error'],
                    'refund_status' => $refundStatus !== '' ? $refundStatus : null,
                ],
                'Stripe refused the refund. Nothing has been returned to the customer.',
            );
        }

        return SettlementResult::ok(
            'refunded',
            $refundId,
            [
                'provider' => $this->id(),
                'payment_intent' => $intentId,
                'refund_id' => $refundId,
                'refund_status' => $refundStatus,
            ],
            'Refunded through Stripe.',
        );
    }

    /**
     * The PaymentIntent for this order.
     *
     * `transaction_id` holds a PaymentIntent (`pi_...`) from the moment
     * start() runs, so capture and refund work on an order the instant it is
     * placed rather than only after its webhook lands.
     *
     * The `cs_...` arm is not dead code and must not be deleted. Every order
     * placed through the previous hosted flow carries a Checkout session id in
     * this column, and those orders stay refundable for as long as Stripe will
     * refund them — years. Settlement needs the intent, so a session id is
     * exchanged for one rather than sent to an endpoint that will reject it.
     */
    private function paymentIntentId(Order $order): ?string
    {
        $ref = trim((string) $order->transaction_id);

        if ($ref === '') {
            return null;
        }

        if (! str_starts_with($ref, 'cs_')) {
            return $ref;
        }

        $session = $this->stripeAttempt('GET', '/v1/checkout/sessions/' . urlencode($ref));
        $intent = $session['body']['payment_intent'] ?? null;

        return is_string($intent) && $intent !== '' ? $intent : null;
    }

    /* -------------------------------------------------------- reconciliation */

    /**
     * Stripe's books, a page at a time.
     *
     *   GET /v1/charges?created[gte]=&created[lte]=&limit=&starting_after=
     *   GET /v1/refunds?created[gte]=&created[lte]=&limit=&starting_after=
     *
     * CHARGES, NOT CHECKOUT SESSIONS, and not PaymentIntents either. A session
     * is an intention — it exists whether or not anybody paid, and the list is
     * mostly abandoned baskets. A charge is money. That is the question this
     * report asks, so that is the object it reads.
     *
     * Stripe pages with `starting_after`, which is the id of the last object
     * you were given, plus a `has_more` flag. The cursor this returns is
     * therefore an object id and nothing else, which is also what makes it safe
     * to checkpoint: it describes a position in Stripe's own ordering rather
     * than a count of rows we think we have seen.
     *
     * `created` is a UNIX INTEGER on this API, both in the filter and in the
     * response. Not a string, not RFC3339. Sending a date string here does not
     * error — Stripe coerces it to 0 — and the request then quietly asks for
     * everything since 1970, which pages until the rate limit rather than
     * failing in a way anyone would notice.
     */
    public function listRemotePayments(ReconcileWindow $window, ?string $cursor, int $limit): RemotePage
    {
        return $this->listStripe('/v1/charges', $window, $cursor, $limit, RemoteTxn::PAYMENT);
    }

    public function listRemoteRefunds(ReconcileWindow $window, ?string $cursor, int $limit): RemotePage
    {
        return $this->listStripe('/v1/refunds', $window, $cursor, $limit, RemoteTxn::REFUND);
    }

    public function remoteSourceLabel(): string
    {
        return 'api.stripe.com /v1/charges and /v1/refunds';
    }

    private function listStripe(string $path, ReconcileWindow $window, ?string $cursor, int $limit, string $kind): RemotePage
    {
        if (! $this->configured()) {
            return RemotePage::unsupported('Stripe has no secret key stored, so its books cannot be read.');
        }

        $query = [
            'created' => [
                'gte' => $window->from->getTimestamp(),
                'lte' => $window->to->getTimestamp(),
            ],
            // Stripe's own ceiling. Asking for more is a 400, not a clamp.
            'limit' => max(1, min($limit, 100)),
        ];

        if ($cursor !== null && trim($cursor) !== '') {
            $query['starting_after'] = trim($cursor);
        }

        $attempt = $this->stripeAttempt('GET', $path . '?' . http_build_query($query));

        if (! $attempt['ok'] || ! is_array($attempt['body'] ?? null)) {
            return RemotePage::failed($attempt['error'] ?? 'unreachable', $attempt['status']);
        }

        $data = $attempt['body']['data'] ?? [];
        $data = is_array($data) ? $data : [];

        $items = [];
        $lastId = null;

        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (string) ($row['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $lastId = $id;
            $items[] = $kind === RemoteTxn::PAYMENT ? $this->chargeToTxn($row, $id) : $this->refundToTxn($row, $id);
        }

        $hasMore = ($attempt['body']['has_more'] ?? false) === true;

        return RemotePage::of($items, $hasMore ? $lastId : null);
    }

    private function chargeToTxn(array $charge, string $id): RemoteTxn
    {
        $status = (string) ($charge['status'] ?? '');
        $captured = ($charge['captured'] ?? true) === true;

        /*
         * `succeeded` is not the same as "captured". A PaymentIntent created
         * with manual capture produces a charge that is succeeded and NOT
         * captured, which is an authorisation Stripe will release in about a
         * week. Calling that settled money would put it in the same list as
         * money actually taken, and the owner would go looking for funds that
         * are not there.
         */
        $state = match (true) {
            $status === 'succeeded' && $captured => RemoteTxn::SETTLED,
            $status === 'succeeded' => RemoteTxn::AUTHORISED,
            $status === 'pending' => RemoteTxn::AUTHORISED,
            default => RemoteTxn::DEAD,
        };

        /*
         * The PaymentIntent is what `payments.provider_ref` holds — the webhook
         * writes `$object['payment_intent']` — so it is the key that matches,
         * and the charge id is what a human pastes into Stripe's own search.
         * Both are carried; matching on one of them alone is how a
         * reconciliation invents a crisis.
         */
        $intent = $charge['payment_intent'] ?? null;
        $reference = $charge['metadata']['order_number'] ?? null;

        return new RemoteTxn(
            provider: $this->id(),
            kind: RemoteTxn::PAYMENT,
            remoteId: $id,
            matchKeys: is_string($intent) && $intent !== '' ? [$intent] : [],
            reference: is_string($reference) && $reference !== '' ? $reference : null,
            // Stripe amounts are already integer MINOR units, which is how this
            // schema stores money. No conversion here, so none to get wrong.
            amountFils: (int) ($charge['amount'] ?? 0),
            currency: strtoupper((string) ($charge['currency'] ?? '')),
            state: $state,
            rawState: $status . ($captured ? '' : ' (uncaptured)'),
            createdAt: $this->stripeMoment($charge['created'] ?? null),
        );
    }

    private function refundToTxn(array $refund, string $id): RemoteTxn
    {
        $status = (string) ($refund['status'] ?? '');

        // `pending` counts, and matches PaymentRefunder::COUNTED on our side: a
        // refund Stripe has accepted is money the merchant no longer has, even
        // before the card network finishes with it.
        $state = in_array($status, ['succeeded', 'pending'], true) ? RemoteTxn::SETTLED : RemoteTxn::DEAD;

        /*
         * A Stripe refund carries no order reference of any kind — not
         * `client_reference_id`, not our metadata. What it does carry is the
         * PaymentIntent and the charge it reverses, and `payments.provider_ref`
         * holds that intent. Passed as PARENT keys rather than match keys: they
         * identify the payment, not the refund, and letting them match a refund
         * would make a refund the provider never made look confirmed. See
         * RemoteTxn::parents().
         */
        $parents = array_values(array_filter([
            $refund['payment_intent'] ?? null,
            $refund['charge'] ?? null,
        ], fn ($v) => is_string($v) && $v !== ''));

        return new RemoteTxn(
            provider: $this->id(),
            kind: RemoteTxn::REFUND,
            remoteId: $id,
            matchKeys: [],
            reference: null,
            amountFils: (int) ($refund['amount'] ?? 0),
            currency: strtoupper((string) ($refund['currency'] ?? '')),
            state: $state,
            rawState: $status,
            createdAt: $this->stripeMoment($refund['created'] ?? null),
            parentKeys: $parents,
        );
    }

    /** A Stripe `created` is a unix integer, and occasionally a numeric string. */
    private function stripeMoment(mixed $created): ?CarbonImmutable
    {
        return is_numeric($created) ? CarbonImmutable::createFromTimestampUTC((int) $created) : null;
    }

    /**
     * `t=<unix>,v1=<hex>[,v1=<hex>]` — more than one v1 during a secret
     * rollover, so any one matching is a pass.
     */
    private function signatureValid(?string $header, string $raw, string $secret): bool
    {
        if ($header === null || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        // Replay window. The signature itself never expires, so without this a
        // delivery captured today stays valid indefinitely.
        if (abs(time() - (int) $timestamp) > Signature::TOLERANCE) {
            return false;
        }

        foreach ($signatures as $candidate) {
            // The raw body, byte for byte -- re-encoding the parsed JSON would
            // change key order and never match.
            if (Signature::hmacMatches('sha256', $timestamp . '.' . $raw, $secret, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
