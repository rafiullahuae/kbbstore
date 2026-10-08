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
use App\Services\Payments\SettlesBeforeRelease;
use App\Services\Payments\SettlesPayments;
use App\Services\Payments\Signature;
use App\Services\Payments\PaymentLog;
use App\Services\Payments\StripeKeys;
use App\Services\Payments\StripePaymentText;
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
class StripeGateway extends RemoteGateway implements HandlesWebhooks, ListsTransactions, SettlesPayments, SettlesBeforeRelease
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

    /**
     * Stripe's smallest charge in AED, the only currency an order is placed
     * in (CheckoutController writes 'AED'): "The minimum amount is $0.50 US
     * or equivalent in charge currency", which Stripe's minimums table puts
     * at AED 2.00. Below it POST /v1/payment_intents is amount_too_small, so
     * the card is not offered. (Lane ST.)
     */
    public const MIN_FILS = 200;

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
        return $this->key('secret_key') !== '';
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
            && $totalFils >= self::MIN_FILS
            && $this->key('publishable_key') !== ''
            // The secret key too (Lane ST): StripeKeys::get() now reads '' for
            // a key of the wrong kind or mode, and a card form whose Place
            // order can only fail is worse than no card form.
            && $this->key('secret_key') !== '';
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
        return $this->key('publishable_key');
    }

    /**
     * The credential in force for the shop's current Mode. (Lane SR.)
     *
     * Every read of a key, a signing secret or a webhook endpoint id goes
     * through here, so test and live cannot be crossed by one caller reading
     * the raw box. See App\Services\Payments\StripeKeys for the rules,
     * including the one that keeps a shop set up with a single key set working.
     */
    public function key(string $name): string
    {
        return StripeKeys::get($this->credentials->all($this->id()), $this->mode(), $name);
    }

    /** 'test' or 'live' — the Mode switch on Store → Payments → Stripe. */
    public function mode(): string
    {
        return $this->credentials->mode($this->id());
    }

    /* ------------------------------------------------ the owner's settings */

    /** A plain setting from the schema's `settings` column, trimmed. */
    private function setting(string $name): string
    {
        return $this->credentials->get($this->id(), $name);
    }

    /** Authorise at checkout, take the money on the order screen. Off unless switched on. */
    public function captureLater(): bool
    {
        return $this->setting('capture_later') === '1';
    }

    /**
     * The order number as Stripe shows it: the owner's prefix, then ours.
     *
     * DISPLAY ONLY. reference() — the plain number — is still what goes in
     * metadata.order_number, the idempotency key and every lookup, so a prefix
     * added, changed or removed later cannot strand a payment.
     */
    public function displayReference(Order $order): string
    {
        $prefix = $this->setting('order_reference_prefix');

        return (StripePaymentText::referencePrefixError($prefix) === null ? $prefix : '') . $this->reference($order);
    }

    public function paymentDescription(Order $order): string
    {
        $template = $this->setting('payment_description');

        return StripePaymentText::description(
            $template,
            $this->displayReference($order),
            $this->reference($order),
            // Read only when the template asks for it: the default sends no
            // shop name and so costs no settings read.
            str_contains($template, '{shop}') ? $this->shopName() : '',
        );
    }

    /** The full descriptor in force: the owner's, else one made from the shop name. */
    public function fullDescriptor(): string
    {
        $own = $this->setting('statement_descriptor');

        return $own !== '' && StripePaymentText::fullDescriptorError($own) === null
            ? $own
            : StripePaymentText::defaultFullDescriptor($this->shopName());
    }

    /** The account's shortened-descriptor prefix length, when connect read it. */
    public function accountPrefixLength(): ?int
    {
        $prefix = $this->setting('account_descriptor_prefix');

        return $prefix !== '' ? strlen($prefix) : null;
    }

    public function statementSuffix(Order $order): ?string
    {
        return StripePaymentText::suffix(
            $this->setting('statement_descriptor_suffix'),
            $this->setting('statement_descriptor_order_number') === '1',
            $this->reference($order),
            $this->accountPrefixLength(),
        );
    }

    private function shopName(): string
    {
        $name = trim((string) (app(\App\Services\SettingsService::class)->get('store_name', '') ?? ''));

        return $name !== '' ? $name : \App\Support\BrandName::appName();
    }

    /**
     * The PaymentIntent parameters the owner's settings add, and ONLY those
     * that are in use. An empty array is today's request, unchanged.
     *
     * @return array<string, mixed>
     */
    private function intentSettings(Order $order): array
    {
        $extra = [];

        /*
         * `statement_descriptor_suffix`, never `statement_descriptor`: the
         * intent is card-only, and Stripe's PaymentIntent reference says a
         * full descriptor on a card charge "returns an error".
         */
        $suffix = $this->statementSuffix($order);

        if ($suffix !== null) {
            $extra['statement_descriptor_suffix'] = $suffix;
        }

        if ($this->captureLater()) {
            $extra['capture_method'] = 'manual';
        }

        $email = trim((string) $order->email);

        if ($this->setting('receipt_email') === '1' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $extra['receipt_email'] = $email;
        }

        if ($this->displayReference($order) !== $this->reference($order)) {
            $extra['metadata'] = ['order_reference' => $this->displayReference($order)];
        }

        return $extra;
    }

    /**
     * Admin-side validation of a save, before anything is written.
     *
     * Called by PaymentsApiController::save() for any gateway that has it. A
     * message per field the owner can act on; the save is refused whole.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public function validateConfig(array $values): array
    {
        $errors = [];
        $text = static fn (string $key): ?string => array_key_exists($key, $values) && is_scalar($values[$key]) ? trim((string) $values[$key]) : null;

        foreach ([
            'statement_descriptor' => fn (string $v) => StripePaymentText::fullDescriptorError($v),
            'statement_descriptor_suffix' => fn (string $v) => StripePaymentText::suffixError($v, $this->accountPrefixLength()),
            'order_reference_prefix' => fn (string $v) => StripePaymentText::referencePrefixError($v),
            'payment_description' => fn (string $v) => StripePaymentText::descriptionError($v),
        ] as $key => $check) {
            $value = $text($key);

            if ($value !== null && ($error = $check($value)) !== null) {
                $errors[$key] = $error;
            }
        }

        /*
         * A key in the box for the other mode. Stripe decides test or live from
         * the key alone, so a pk_live_ in the Test box would take real money
         * with the switch reading Sandbox. Refused with the fix in the message.
         */
        foreach (['publishable_key', 'secret_key'] as $name) {
            foreach (['test', 'live'] as $mode) {
                $value = $text(StripeKeys::slot($name, $mode));

                if ($value === null || $value === '') {
                    continue;
                }

                $keyMode = StripeKeys::keyMode($value);
                $kind = StripeKeys::keyKind($value);

                /*
                 * THE RIGHT KIND OF KEY, not only the right mode. (Lane ST.)
                 * pk_test_ in "Test secret key" is test-mode and passed the
                 * check below, then every Place order sent it as the Bearer
                 * and Stripe answered 401 — "We could not reach our card
                 * processor." The reverse would print a secret key into the
                 * checkout page.
                 */
                if ($kind !== null && $kind !== $name) {
                    $errors[StripeKeys::slot($name, $mode)] = $name === 'secret_key'
                        ? sprintf('That is your publishable key (pk_…) in the %s secret key box. The secret key is the other one on Stripe → Developers → API keys and starts sk_%s_.', $mode === 'test' ? 'Test' : 'Live', $mode)
                        : sprintf('That is a SECRET key in the %s publishable key box, and this box is shown to shoppers. Paste the key that starts pk_%s_ here.', $mode === 'test' ? 'Test' : 'Live', $mode);

                    continue;
                }

                if ($keyMode === null) {
                    $errors[StripeKeys::slot($name, $mode)] = sprintf(
                        'That does not look like a Stripe %s key: it should start %s.',
                        $name === 'publishable_key' ? 'publishable' : 'secret',
                        $name === 'publishable_key' ? 'pk_' . $mode . '_' : 'sk_' . $mode . '_ (or rk_' . $mode . '_)',
                    );
                } elseif ($keyMode !== $mode && ! ($mode === 'live' && $this->testKeyMayLandInLiveBox($name, $value, $values))) {
                    $errors[StripeKeys::slot($name, $mode)] = sprintf(
                        'That is a %s key in the %s box. Paste it into the %s box instead.',
                        strtoupper($keyMode), $mode === 'test' ? 'Test' : 'Live', $keyMode === 'test' ? 'Test' : 'Live',
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * A TEST key arriving in a LIVE box is the old single-set shape, and it is
     * accepted in exactly the two cases where nothing can be crossed:
     *
     *   - it is the value already stored there, re-posted: the screen sends
     *     every plain field back with the value it painted, and a shop set up
     *     before the two sets existed paints its old pk_test_ key in the Live
     *     box. The owner did not type it and must not be refused a save.
     *   - the Test boxes are empty, stored and posted: the key then belongs to
     *     the only test set there is, and PaymentsApiController moves the lot
     *     into the Test boxes straight after the save (StripeKeys::normalise()).
     *
     * With a test set already in place it is refused, because the move would
     * have nowhere to go and the key would be dropped.
     *
     * @param  array<string, mixed>  $posted
     */
    private function testKeyMayLandInLiveBox(string $name, string $value, array $posted): bool
    {
        if ($value === $this->credentials->get($this->id(), $name)) {
            return true;
        }

        foreach (['publishable_key_test', 'secret_key_test', 'webhook_signing_secret_test'] as $key) {
            $incoming = is_scalar($posted[$key] ?? null) ? trim((string) $posted[$key]) : '';

            if ($incoming !== '' || $this->credentials->get($this->id(), $key) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * What is still missing for the CURRENT mode, for GatewayPreflight.
     *
     * @return list<array{key: string, label: string}>
     */
    public function missingCredentials(): array
    {
        $mode = $this->mode();
        $schema = $this->configSchema();
        $missing = [];

        foreach (['publishable_key', 'secret_key', 'webhook_signing_secret'] as $name) {
            if ($this->key($name) === '') {
                $slot = StripeKeys::slot($name, $mode);
                $missing[] = ['key' => $slot, 'label' => (string) ($schema[$slot][1] ?? $slot)];
            }
        }

        return $missing;
    }

    /**
     * Was this order's card only AUTHORISED, and is the money still to take?
     *
     * Read off the ledger row PaymentConfirmer wrote when it applied the
     * payment, which carries `capture_method: manual` when the intent was
     * `requires_capture` — so an order keeps the answer it was placed under,
     * whatever the setting says today. One indexed query, asked only on the
     * order screen and only for a Stripe order that is paid and not captured.
     */
    public function awaitingCapture(Order $order): bool
    {
        $ref = trim((string) $order->transaction_id);

        if ($order->paid_at === null || $order->captured_at !== null || ! str_starts_with($ref, 'pi_')) {
            return false;
        }

        return \App\Models\PaymentEvent::query()
            ->where('provider', $this->id())
            ->where('external_id', $ref)
            ->where('type', 'paid')
            ->where('payload', 'like', '%"capture_method":"manual"%')
            ->exists();
    }

    /** One line in Store → Payments → Stripe → Payment log. Never throws. */
    private function journal(string $level, string $event, string $message, array $context = []): void
    {
        PaymentLog::record($this->id(), $level, $event, $message, $context, $this->mode());
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
            /*
             * TWO KEY SETS, ONE SWITCH. (Lane SR.) Test first, because that is
             * the set the owner fills in first. The Mode switch at the top of
             * this column picks which set the shop uses; App\Services\Payments\
             * StripeKeys is the one place that answers "which value is in
             * force", including for a shop set up before there were two sets.
             *
             * All six are `optional` to the generic preflight loop, which cannot
             * know that only the active set is required. missingCredentials()
             * below reports the active set's gaps instead, by mode.
             */
            'publishable_key_test' => ['text', 'Test publishable key', 'Starts pk_test_. Used while Mode is Sandbox / test. Stripe Dashboard → Developers → API keys, with "Test mode" on.', 'keys', 'optional'],
            'secret_key_test' => ['secret', 'Test secret key', 'Starts sk_test_ (or rk_test_). Used while Mode is Sandbox / test. Never leaves the server.', 'keys', 'optional'],
            'webhook_signing_secret_test' => ['secret', 'Test webhook signing secret', 'Starts whsec_. Filled in for you by "Set up webhook automatically" below, in test mode.', 'keys', 'optional'],
            'publishable_key' => ['text', 'Live publishable key', 'Starts pk_live_. Used while Mode is Live. Safe to appear in the page.', 'keys', 'optional'],
            'secret_key' => ['secret', 'Live secret key', 'Starts sk_live_ (or rk_live_). Used while Mode is Live. Never leaves the server, and is never returned by any API.', 'keys', 'optional'],
            'webhook_signing_secret' => ['secret', 'Live webhook signing secret', 'Starts whsec_. Filled in for you by "Set up webhook automatically" below, in live mode. Without it no live webhook can be verified.', 'keys', 'optional'],
            'webhook_secret' => ['secret', 'URL secret', 'Generated for you. Forms part of the webhook URL below.', 'keys'],
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
            'link_enabled' => ['bool', 'Stripe Link', 'Stripe’s own one-click autofill, offered inside the card number field. Off by default: it asks the shopper to save their card with Stripe rather than with this shop, and it puts a second sign-in in the middle of the checkout.', 'settings'],
            /*
             * THE TWO WALLETS, AND THEY LIVE HERE RATHER THAN IN A GATEWAY OF
             * THEIR OWN BECAUSE THAT IS WHAT THEY ARE.
             *
             * Apple Pay and Google Pay are CARD wallets. What Stripe hands back
             * when a shopper authorises one is a PaymentMethod of type `card`
             * carrying `card.wallet.type`, charged against the same
             * PaymentIntent a typed card is charged against. So they are two
             * switches on the card gateway, not two gateways — see
             * App\Services\Payments\Wallets, which is the one place that
             * answers "can this shop take it", and the note on
             * `payment_method_types` in openIntent() for why the intent needs
             * no widening at all.
             *
             * BOTH OFF BY DEFAULT, and for Apple Pay that is a fact about the
             * world rather than caution: Apple Pay on the web does not work
             * until extrabeauty.ae is registered with Apple through the Stripe
             * dashboard and the domain-association file is being served. Only
             * the owner can do either. docs/WALLETS-APPLE-GOOGLE-PAY.md is the
             * numbered list.
             */
            'wallet_apple_pay' => ['bool', 'Apple Pay', 'Draws the Apple Pay button on the checkout for Safari and iOS shoppers, and the Apple Pay mark in the footer, the basket and the product page. Off until you have registered this domain with Apple in the Stripe dashboard and the association file is being served — see docs/WALLETS-APPLE-GOOGLE-PAY.md. It rides this same card account: same payment, same capture, same refund.', 'settings'],
            'wallet_google_pay' => ['bool', 'Google Pay', 'Draws the Google Pay button on the checkout for Chrome and Android shoppers, and the Google Pay mark in the footer, the basket and the product page. Needs no registration anywhere — only HTTPS and these live keys. It rides this same card account: same payment, same capture, same refund.', 'settings'],
            /*
             * THE ONE THING ONLY APPLE CAN BE SATISFIED BY, AND IT IS OPTIONAL.
             *
             * Apple will not draw a payment sheet on a domain it has not
             * verified, and it verifies by fetching one file from the site
             * root. Stripe hands that file over when the domain is added in its
             * dashboard. This box is where the file's contents are pasted, and
             * App\Http\Controllers\Store\AppleDomainController serves them at
             * /.well-known/apple-developer-merchantid-domain-association.
             *
             * WHY IT IS A BOX ON A SCREEN RATHER THAN A FILE IN THE REPOSITORY:
             * `public/` in this repository is not the directory this server
             * serves — bootstrap/app.php's usePublicPath() points at a sibling
             * of the application root — so a committed file would land
             * somewhere no request can reach. See the controller's docblock.
             *
             * `optional`, the fifth element, and it earns its place: without it
             * GatewayPreflight would print "Still to paste in: Apple Pay domain
             * association file" on every shop that has never wanted Apple Pay,
             * which is a false alarm on the one screen whose job is to remove
             * them — the identical argument the `bool` skip there already makes.
             *
             * 'settings' and not 'keys' for the same reason: the keys column
             * counts "N of M filled in", and a field nobody has to fill in
             * would hold that count below full forever.
             */
            'apple_domain_association' => [
                'text',
                'Apple Pay domain file',
                'Only needed for Apple Pay. In Stripe: Settings → Payments → Payment method domains → add your shop’s domain (and its www form), then download the association file it offers and paste the whole contents here. This shop then serves it at /.well-known/apple-developer-merchantid-domain-association, which is where Apple looks. Full steps: docs/WALLETS-APPLE-GOOGLE-PAY.md.',
                'settings',
                'optional',
            ],
            /*
             * ─── WHAT THE WOOCOMMERCE STRIPE PLUGIN OFFERS, AND THIS SHOP NOW
             *     DOES TOO (Lane SR) ──────────────────────────────────────────
             *
             * Every one of these ships at the value that sends Stripe exactly
             * what it was sent before: empty boxes and Off switches. The owner
             * asked for the controls, not for different payments.
             * StripePaymentText holds the rules and the reasons.
             */
            'statement_descriptor' => ['text', 'Statement descriptor (full)', '5–22 characters, Latin letters, at least one letter, none of < > \\ \' " *. Stripe refuses a per-payment full descriptor on CARD payments, so card statements show your Stripe account\'s own descriptor (Stripe Dashboard → Settings → Business → Public details). This is the name you want there; the Stripe status block below compares it with what your account actually says. Left empty, your shop name is used.', 'settings', 'optional'],
            'statement_descriptor_suffix' => ['text', 'Statement descriptor suffix (cards)', 'Added after your Stripe account\'s shortened descriptor on card statements, as "PREFIX* SUFFIX" (22 characters in all). Empty sends no suffix, which is how this shop has always worked.', 'settings', 'optional'],
            'statement_descriptor_order_number' => ['bool', 'Add the order number to card statements', 'Puts the order number in the suffix, e.g. "KBB* ORDER 10234" (Stripe needs a letter in the suffix, so a bare number gets ORDER, or ORD where space is short, in front), so a customer can match the line on their statement to their order. Off by default.', 'settings'],
            'order_reference_prefix' => ['text', 'Order number prefix shown in Stripe', 'e.g. KBB- makes order 10234 read "KBB-10234" in the payment description and in Stripe\'s metadata (order_reference). The shop\'s own order numbers do not change, and Stripe keeps the plain number too (order_number), which is what this shop matches payments on.', 'settings', 'optional'],
            'payment_description' => ['text', 'Payment description', 'What Stripe shows beside each payment. Placeholders: {number} (with the prefix above), {order_number} (plain), {shop}. Empty means "Order {number}", as before.', 'settings', 'optional'],
            'capture_later' => ['bool', 'Authorise only, capture later', 'On: a card is only AUTHORISED at checkout and the money is taken when you press Capture on the order (Store → Orders → the order → Payment). Stripe releases an authorisation that is not captured within 7 days. Off (default): the money is taken at checkout, as before.', 'settings'],
            'receipt_email' => ['bool', 'Stripe email receipts', 'On: Stripe emails its own payment receipt to the shopper\'s order email as well as this shop\'s order email. Off by default. Stripe does not send receipts for test-mode payments.', 'settings'],
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
        return ['Authorization' => 'Bearer ' . $this->key('secret_key')];
    }

    /* ------------------------------------------------------------- checkout */

    /**
     * `confirm`. The card fields, Apple Pay and Google Pay all finish the
     * payment on THIS page against a client secret — nobody leaves the shop,
     * and the order is not confirmed until Stripe says the intent succeeded.
     */
    public function journey(): string
    {
        return 'confirm';
    }

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
             *
             * ─── AND APPLE PAY AND GOOGLE PAY NEED NO WIDENING HERE ─────────
             *
             * Read before changing this line for the wallets, because the
             * obvious edit is wrong. Apple Pay and Google Pay are not payment
             * method TYPES at Stripe. They are card wallets: the PaymentMethod
             * a shopper authorises in either sheet has `type: card` and carries
             * `card.wallet.type = apple_pay` or `google_pay`. `['card']`
             * already admits both.
             *
             * So the wallets ride this intent exactly as written — same amount,
             * same currency, same metadata, same idempotency key, same capture,
             * same refund, same reconciliation — and the two reasons above are
             * respected rather than overwritten. Moving to
             * `automatic_payment_methods` to "turn the wallets on" would buy
             * nothing and would hand the box above the card fields to whatever
             * is switched on in a dashboard nobody here can see, which is the
             * first of those two reasons.
             *
             * `payment_method_types` and the Elements group that confirms
             * against it have to agree, which is why
             * partials/checkout/express-wallets passes `paymentMethodTypes:
             * ['card']` to its own elements() call. If this list ever changes,
             * that one changes with it.
             */
            'payment_method_types' => ['card'],
            /*
             * "Order 10234" unless the owner wrote his own wording — see
             * paymentDescription(). With nothing set this is byte-identical to
             * the string this line always sent.
             */
            'description' => $this->paymentDescription($order),
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
         * ─── THE OWNER'S STRIPE SETTINGS (Lane SR) ─────────────────────────
         *
         * Each key is added ONLY when its setting is in use, so a shop that has
         * not touched them sends Stripe exactly the request it always sent —
         * and the idempotency key below stays exactly what it always was.
         */
        $extra = $this->intentSettings($order);
        $payload = array_merge($payload, $extra);

        if (isset($extra['metadata'])) {
            // order_number stays the PLAIN number whatever the prefix: it is
            // the key handleWebhook() looks the order up by, on this payment
            // and on every payment made before the prefix existed.
            $payload['metadata'] = ['order_number' => $this->reference($order)] + $extra['metadata'];
        }

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
        $idempotencyKey = 'kbb-intent-' . $this->reference($order) . ($stripeCustomer === null ? '' : '-save')
            // A settings change between a lost response and its retry is a
            // different request; Stripe would 400 a reused key. Only present
            // when a setting is in use, so the default key is unchanged.
            . ($extra === [] ? '' : '-' . substr(sha1((string) json_encode($extra)), 0, 10));

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
            $idempotencyKey,
        );

        /*
         * ▲ TWO REFUSALS THE SHOP CAN ANSWER ITSELF, ONCE EACH. (Lane ST.)
         *
         * Both ended in "We could not reach our card processor." on every
         * attempt, whatever card the shopper typed, because neither is about
         * the card:
         *
         *   - idempotency_error. An Idempotency-Key is scoped to the Stripe
         *     ACCOUNT, not to this install. Another copy of this shop on the
         *     same keys (the old staging box) whose order 10234 was another
         *     basket has already spent 'kbb-intent-10234', and Stripe refuses
         *     it with other parameters for 24 hours. The request never ran,
         *     so a key that also carries this payload's hash is safe.
         *   - resource_missing on `customer`. The cus_ stored for this mode
         *     was made with another Stripe account's keys (a new sandbox, keys
         *     re-entered). It is dropped and made again in THIS account; if
         *     that fails the payment goes ahead without saving the card,
         *     which is stripeCustomerFor()'s rule already.
         *
         * Exactly one retry, always under a fresh key, so a retry cannot meet
         * the first attempt's stored answer. Anything else is the owner's to
         * fix and is explained in the payment log below.
         *
         * MUTATION: delete this block and StripeCardProcessorTest's "retries
         * once with a fresh key" and "recreates a saved Stripe customer" both
         * answer 422 with the owner's sentence.
         */
        if (! $attempt['ok'] && $attempt['status'] === 400) {
            $retry = null;

            if ($attempt['error_type'] === 'idempotency_error') {
                $retry = $payload;
            } elseif ($stripeCustomer !== null && $attempt['error'] === 'resource_missing' && $attempt['error_param'] === 'customer') {
                $stripeCustomer = $this->stripeCustomerFor($order, stale: $stripeCustomer);
                $retry = $payload;
                unset($retry['customer'], $retry['setup_future_usage']);

                if ($stripeCustomer !== null) {
                    $retry['customer'] = $stripeCustomer;
                    $retry['setup_future_usage'] = 'on_session';
                }
            }

            if ($retry !== null) {
                $this->journal('info', 'intent.retry', $attempt['error_type'] === 'idempotency_error'
                    ? 'Stripe had seen this order\'s payment reference before with other details (another copy of this shop on the same Stripe keys?). Tried again with a fresh reference.'
                    : 'The saved Stripe customer does not exist in this Stripe account. Made a new one and tried again.', [
                    'order' => $this->reference($order),
                    'error_code' => $attempt['error'],
                ]);

                $payload = $retry;
                $attempt = $this->stripeAttempt(
                    'POST',
                    '/v1/payment_intents',
                    $payload,
                    $idempotencyKey . '-r' . substr(sha1((string) json_encode($payload)), 0, 16),
                );
            }
        }

        $result = $attempt['body'];
        $secret = $result['client_secret'] ?? null;
        $intentId = $result['id'] ?? null;

        if (! is_string($secret) || $secret === '' || ! is_string($intentId) || $intentId === '') {
            /*
             * THE OWNER READS THE CAUSE HERE, in plain words, and the shopper
             * does not. (Lane ST.) The message is the "What happened" column
             * of Store → Payments → Stripe → Payment log, and the line the
             * Stripe status block shows until a payment opens again; Stripe's
             * own sentence and code ride in the details.
             */
            $this->journal('error', 'intent.failed', $this->failureReason($attempt), [
                'order' => $this->reference($order),
                'http_status' => $attempt['status'],
                'error_code' => $attempt['error'],
                'error_type' => $attempt['error_type'],
                'error_param' => $attempt['error_param'],
                'error_message' => $attempt['error_message'],
            ]);

            return PaymentStart::failed('We could not reach our card processor. Please try another payment method.');
        }

        $order->forceFill(['transaction_id' => $intentId])->save();

        $this->journal('info', 'intent.created', 'Payment started at checkout.', [
            'order' => $this->reference($order),
            'payment_intent' => $intentId,
            'amount' => (int) $order->total,
            'currency' => strtoupper($currency),
            'capture_method' => $this->captureLater() ? 'manual' : 'automatic',
        ]);

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
    private function stripeCustomerFor(Order $order, ?string $stale = null): ?string
    {
        $customer = $order->customer;

        if (! $customer instanceof \App\Models\Customer) {
            return null;
        }

        $mode = $this->credentials->live($this->id()) ? 'live' : 'test';

        /*
         * $stale: Stripe has just said this account has no such customer
         * (Lane ST — see openIntent()). It is forgotten first, so a failure
         * to make the new one below does not leave the dead id to be sent on
         * every later order.
         */
        if ($stale !== null) {
            $customer->forgetStripeCustomerId($mode, $stale);
        }

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
            // Replacing a stale id takes its own key: within those 24 hours
            // the plain one would hand back the very customer that is gone.
            'kbb-customer-' . $mode . '-' . $customer->id
                . ($stale === null ? '' : '-' . substr(sha1($stale), 0, 10)),
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

        /*
         * AND IT MUST CAPTURE THE WAY THE SHOP NOW CAPTURES. (Lane SR.)
         * `capture_method` is fixed at creation like setup_future_usage. An
         * automatic intent reused after "Authorise only" was switched on would
         * take the money the owner asked to hold, and the reverse would leave
         * money authorised that nobody is going to capture.
         */
        $manual = (string) ($intent['capture_method'] ?? '') === 'manual';

        if ($manual !== $this->captureLater()) {
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
            $this->journal('error', 'api.unreachable', 'Stripe could not be reached.', [
                'endpoint' => strtoupper($method) . ' ' . $this->logPath($path),
            ]);

            return ['ok' => false, 'status' => null, 'body' => null, 'error' => 'transport_error',
                'error_type' => null, 'error_param' => null, 'error_message' => null];
        }

        $this->log('api call', $path, $response->status());

        $decoded = $response->json();
        $decoded = is_array($decoded) ? $decoded : null;

        /*
         * Stripe's own error, kept for the payment log: code, decline code,
         * type and its sentence. PaymentLog scrubs and cuts every one of them;
         * a successful call is not logged here at all — the caller says what
         * it meant, once.
         */
        $error = ! $response->successful() && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $said = static fn (string $field): ?string => is_string($error[$field] ?? null) ? $error[$field] : null;

        if (! $response->successful()) {

            $this->journal('error', 'api.error', 'Stripe answered ' . $response->status() . ' to ' . strtoupper($method) . ' ' . $this->logPath($path) . '.', [
                'endpoint' => strtoupper($method) . ' ' . $this->logPath($path),
                'http_status' => $response->status(),
                'error_code' => is_string($error['code'] ?? null) ? $error['code'] : null,
                'decline_code' => is_string($error['decline_code'] ?? null) ? $error['decline_code'] : null,
                'error_type' => is_string($error['type'] ?? null) ? $error['type'] : null,
                'error_param' => $said('param'),
                'error_message' => is_string($error['message'] ?? null) ? $error['message'] : null,
            ]);
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => $response->successful() ? $decoded : null,
            'error' => $response->successful() ? null : $this->errorCode($decoded),
            // Kept for the caller's decision and its plain-words log line
            // (Lane ST); never shown to a shopper.
            'error_type' => $said('type'),
            'error_param' => $said('param'),
            'error_message' => $said('message'),
        ];
    }

    /**
     * Why Stripe did not open the payment, in words the owner can act on.
     * (Lane ST.) Written to the payment log, never shown to a shopper. Each
     * branch is a cause this checkout has actually met or can meet; the last
     * one quotes Stripe, so nothing is ever reduced to "it failed".
     *
     * @param  array{status: int|null, error: string|null, error_type: string|null, error_param: string|null, error_message: string|null}  $attempt
     */
    private function failureReason(array $attempt): string
    {
        $status = $attempt['status'];
        $code = (string) ($attempt['error'] ?? '');
        $type = (string) ($attempt['error_type'] ?? '');
        $param = (string) ($attempt['error_param'] ?? '');
        $said = trim((string) ($attempt['error_message'] ?? ''));
        $quote = $said !== '' ? ' Stripe said: "' . mb_substr($said, 0, 110) . '"' : '';

        $reason = match (true) {
            $status === null => 'This server could not reach Stripe at all (no answer within ' . self::TIMEOUT . ' seconds, or the connection was refused). Not a settings problem: ask the host to allow outgoing HTTPS to api.stripe.com.',
            $status === 401 => 'Stripe refused the secret key for this Mode. Copy it again from Stripe → Developers → API keys and paste it into Store → Payments → Stripe → ' . ($this->mode() === 'test' ? 'Test' : 'Live') . ' secret key.' . $quote,
            $status === 403 => 'The secret key is not allowed to create payments. A restricted key (rk_) needs Write access to PaymentIntents; or use the standard sk_ key.' . $quote,
            $param === 'statement_descriptor_suffix' => 'Stripe refused the card statement text. Change "Statement descriptor suffix" or switch off "Add the order number to card statements".' . $quote,
            $code === 'amount_too_small' => 'The order total is below Stripe\'s minimum card payment (AED 2.00).',
            $type === 'idempotency_error' => 'Stripe had seen this order\'s payment reference before with other details, and the retry was refused too.' . $quote,
            $code === 'resource_missing' && $param === 'customer' => 'The saved Stripe customer does not exist in this Stripe account, and a new one could not be used.' . $quote,
            $status === 429 => 'Stripe asked this shop to slow down (too many requests). It passes on its own.',
            $status >= 500 => 'Stripe had a problem on its side (HTTP ' . $status . '). It usually passes within minutes; status.stripe.com says.',
            default => 'Stripe refused to open the payment (HTTP ' . $status . ($code !== '' ? ', ' . $code : '') . ').' . $quote,
        };

        return mb_substr($reason, 0, 255);
    }

    /** The path without its query string, for the log. */
    private function logPath(string $path): string
    {
        return (string) strtok($path, '?');
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

        /*
         * `requires_capture` IS A PAYMENT TOO, when the owner asked for
         * "Authorise only". (Lane SR.) The bank has approved the card and the
         * money is held for this shop; the order is confirmed exactly like a
         * captured one, and the ledger row says `capture_method: manual` so the
         * order screen offers Capture. The figure is `amount_capturable`, what
         * is actually held — `amount_received` is 0 until the capture.
         */
        if ($status === 'requires_capture') {
            return $this->confirmer->confirm(
                $order,
                $this->id(),
                $intentId,
                (int) ($intent['amount_capturable'] ?? 0),
                (string) ($intent['currency'] ?? ''),
                [
                    'event_type' => 'browser_confirmation',
                    'payment_intent' => $intentId,
                    'reference' => $this->reference($order),
                    'capture_method' => 'manual',
                ],
            );
        }

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

    /**
     * Before an unfinished card or wallet order is let go (Lane BK).
     *
     * abandonIntent() closes the intent at Stripe and answers true only once
     * Stripe says it is `canceled` -- so nothing can be charged against this
     * order afterwards, from any tab. When it will not close because the money
     * has moved (succeeded, processing, held for capture), the payment is
     * applied here, server to server, through the same confirmFromBrowser() the
     * card form calls: the order becomes paid and its basket stays converted.
     * Stripe unreachable is false whatever $shopperCameBack says -- a card
     * intent left confirmable is a payment a stale tab can still take.
     */
    public function settleBeforeRelease(Order $order, bool $shopperCameBack): bool
    {
        if ($this->abandonIntent($order)) {
            return true;
        }

        $this->confirmFromBrowser($order);

        return false;
    }

    /* -------------------------------------------------------------- webhook */

    /**
     * Every webhook, with its outcome written to the payment log. (Lane SR.)
     *
     * The log is what lets the owner see "Last event received" on the Stripe
     * status block — which is how he can tell the endpoint works without a
     * shell. A delivery that fails the URL secret is NOT logged: that is a
     * stranger guessing at the address, and logging it would let anybody fill
     * the log. One that passes the URL secret and fails Stripe's signature IS
     * logged, because only someone holding our URL can produce it, and in
     * practice that is Stripe itself with a signing secret this shop does not
     * have — the one misconfiguration the owner can fix.
     */
    public function handleWebhook(Request $request): WebhookOutcome
    {
        $outcome = $this->processWebhook($request, $logged);

        if ($logged !== null) {
            $this->journal(
                $outcome->accepted ? 'info' : 'error',
                $logged['event'],
                $logged['message'],
                $logged['context'] + ['outcome' => $outcome->message, 'http_status' => $outcome->status],
            );
        }

        return $outcome;
    }

    private function processWebhook(Request $request, ?array &$logged): WebhookOutcome
    {
        $logged = null;

        // (1) URL secret, before anything else.
        $urlSecret = $this->credentials->get($this->id(), 'webhook_secret');

        if ($urlSecret === '' || ! Signature::equals($urlSecret, (string) $request->route('secret'))) {
            return WebhookOutcome::rejected();
        }

        // (2) Stripe's own signature, over the RAW body, with the signing
        // secret of the CURRENT mode — test and live endpoints are separate
        // objects at Stripe, each with its own secret.
        $signing = $this->key('webhook_signing_secret');
        $raw = $request->getContent();

        if (! $this->signatureValid($request->header('Stripe-Signature'), $raw, $signing)) {
            $logged = [
                'event' => 'webhook.signature_failed',
                'message' => $signing === ''
                    ? 'A webhook arrived but no signing secret is stored for ' . $this->mode() . ' mode, so it could not be verified.'
                    : 'A webhook arrived with a signature that does not match the ' . $this->mode() . ' signing secret.',
                'context' => ['reason' => $request->header('Stripe-Signature') === null ? 'no Stripe-Signature header' : 'signature mismatch or too old'],
            ];

            return WebhookOutcome::rejected();
        }

        $event = json_decode($raw, true);

        if (! is_array($event)) {
            return WebhookOutcome::refused('unparseable body');
        }

        $type = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? [];
        $object = is_array($object) ? $object : [];

        $logged = [
            'event' => 'webhook.received',
            'message' => 'Webhook ' . ($type !== '' ? $type : '(no type)') . ' received.',
            'context' => [
                'event_type' => $type,
                'event_id' => is_string($event['id'] ?? null) ? $event['id'] : null,
                'payment_intent' => is_string($object['id'] ?? null) ? $object['id'] : null,
            ],
        ];

        /*
         * THE PLAIN ORDER NUMBER, AND ONLY THAT. metadata.order_number has
         * always carried our own number and still does; the prefixed form the
         * owner may have set travels in metadata.order_reference for display
         * and is never looked up. So a payment made before the prefix existed,
         * after it was set, and after it was changed all find their order.
         */
        $reference = $object['client_reference_id']
            ?? ($object['metadata']['order_number'] ?? null);

        $order = $this->findOrderByReference(is_string($reference) ? $reference : null);

        if ($order === null) {
            /*
             * `stripe trigger` and the Dashboard's "Send test webhook" both
             * produce events with no order behind them. Answering 200 and
             * logging it lets the owner see a test event arrive; it changes
             * nothing, because nothing was found to change.
             */
            if ($reference === null) {
                return WebhookOutcome::ignored('event carries no order number (a test event?)');
            }

            return WebhookOutcome::refused('no order for that reference');
        }

        $logged['context']['order'] = $this->reference($order);

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
        /*
         * AUTHORISED, NOT YET CAPTURED — "Authorise only" is on. (Lane SR.)
         * A manual-capture intent never emits `payment_intent.succeeded` at
         * authorisation; it emits this, with status `requires_capture`, and
         * `succeeded` only arrives after the owner presses Capture (where it is
         * refused as already applied, which is correct). Without this arm a
         * shopper whose browser closed after the bank approved would leave an
         * order the shop never hears about, holding money it can no longer
         * capture once the week is out.
         */
        if ($type === 'payment_intent.amount_capturable_updated') {
            if ((string) ($object['status'] ?? '') !== 'requires_capture') {
                return WebhookOutcome::ignored('nothing is held for capture');
            }

            return $this->confirmer->confirm(
                $order,
                $this->id(),
                (string) ($object['id'] ?? ''),
                (int) ($object['amount_capturable'] ?? 0),
                (string) ($object['currency'] ?? ''),
                $summary + ['capture_method' => 'manual'],
            );
        }

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
        if ($this->captureLater()) {
            return sprintf(
                'Card payments are only authorised at checkout ("Authorise only, capture later" is on). '
                . 'Press Capture to take the money; Stripe releases an authorisation left uncaptured after about %d days.',
                self::CAPTURE_DAYS,
            );
        }

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
            /*
             * `amount_received`, NOT `amount`, AND NOT THE AMOUNT WE ASKED FOR.
             *
             * The same distinction handleWebhook() makes on
             * `payment_intent.succeeded`, and for the same reason written out
             * there: `amount` is what the intent asked for, `amount_received`
             * is what was actually taken, and on a partially captured intent
             * the two differ. A manual-capture intent captured short — from the
             * Stripe dashboard, by the merchant's own tooling, by anything that
             * is not this button — reaches `succeeded` and lands here.
             *
             * Until this figure travelled, PaymentCapturer wrote the whole
             * order total against this ok(), and `captured_total` is the
             * ceiling PaymentRefunder measures a refund against: 120.00 taken
             * of a 300.00 order, 300.00 refundable, 180.00 of the shop's own
             * money paid to a buyer who never paid it.
             *
             * Already integer minor units, which is how this schema stores
             * money — no conversion here, so none to get wrong. A body with no
             * numeric `amount_received` reports null rather than zero, and
             * PaymentCapturer then writes the requested amount exactly as it
             * always has.
             */
            $received = $read['body']['amount_received'] ?? null;

            return SettlementResult::ok(
                'already_captured',
                $intentId,
                ['provider' => $this->id(), 'payment_intent' => $intentId, 'status' => $status],
                'Stripe captured this card payment at authorisation; there was nothing left to take.',
                capturedFils: is_numeric($received) ? (int) $received : null,
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

        $this->journal(
            $attempt['ok'] && (string) ($attempt['body']['status'] ?? '') === 'succeeded' ? 'info' : 'error',
            'capture',
            $attempt['ok'] ? 'Capture sent to Stripe.' : 'Stripe refused the capture.',
            ['order' => $this->reference($order), 'payment_intent' => $intentId, 'amount' => $amountFils,
                'status' => (string) ($attempt['body']['status'] ?? ''), 'error_code' => $attempt['error']],
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

        $this->journal(
            in_array($refundStatus, ['succeeded', 'pending'], true) ? 'info' : 'error',
            'refund',
            in_array($refundStatus, ['succeeded', 'pending'], true) ? 'Refund accepted by Stripe.' : 'Stripe refused the refund.',
            ['order' => $this->reference($order), 'payment_intent' => $intentId, 'refund' => is_string($refundId) ? $refundId : null,
                'amount' => $amountFils, 'status' => $refundStatus, 'error_code' => $attempt['error']],
        );

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
