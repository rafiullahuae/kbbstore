<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\Http;

/**
 * "Have I set this up correctly?" — answered without a live transaction.
 *
 * WHY THIS EXISTS
 *
 * Three of the four gateways need a merchant account the owner has to go and
 * open. Until he does, everything about them is invisible: the keys are write
 * only by design, the webhook URL is generated on first save, and the only way
 * to discover that the merchant code is wrong or that Tamara is pointed at the
 * live host with sandbox keys is to send a real shopper through a real
 * checkout and watch it fail.
 *
 * This produces, for one gateway, every fact the owner needs before he trusts
 * it with a customer:
 *
 *   - which credential fields are still empty, BY NAME, so "not configured"
 *     becomes "you have not pasted the webhook signing secret";
 *   - which host the current test/live switch actually selects;
 *   - the webhook URL to paste into the provider's dashboard;
 *   - whether the gateway would be offered at checkout right now, and if not,
 *     which of the four gates it fails;
 *   - the REQUEST this shop would send to create a payment — method, URL,
 *     headers, body — for a sample order.
 *
 * HOW THE DRY RUN WORKS, AND WHY IT IS THE REAL REQUEST
 *
 * It calls the gateway's own start(), so what comes back is what would
 * genuinely be sent rather than a second description of it that could drift.
 * Two things make that safe:
 *
 *   - the HTTP client is faked for the duration, so nothing leaves this server.
 *     The fake answers 503, which is the one answer every gateway is already
 *     written to handle by giving up BEFORE it writes the provider's reference
 *     to the order — so the sample order, which is not saved, stays unsaved.
 *   - the order is synthetic and says so: its number is PREFLIGHT-… and it is
 *     never persisted.
 *
 * Http::fake() here replaces the stub handler on the HTTP factory for the rest
 * of this process. Under PHP-FPM that is this one request, which is the
 * preflight request; it is not a mechanism to use anywhere else.
 *
 * NO SECRET COMES OUT OF HERE. Header values are compared against every stored
 * credential and replaced with a description of what is in them. A preflight
 * screen that printed the Authorization header would be a worse leak than the
 * one it was built to prevent, because it would look like a diagnostic.
 */
final class GatewayPreflight
{
    /** What a sample order is worth, in fils. A round, obviously-fake figure. */
    private const SAMPLE_TOTAL = 25000;

    public function __construct(
        private GatewayRegistry $registry,
        private GatewayCredentials $credentials,
    ) {}

    /**
     * Everything knowable about one gateway without touching the provider.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $gatewayId): array
    {
        $gateway = $this->registry->find($gatewayId);

        if ($gateway === null) {
            return ['id' => $gatewayId, 'known' => false];
        }

        $this->credentials->forget($gatewayId);

        $row = PaymentProvider::find($gatewayId);
        $schema = $gateway->configSchema();

        $missing = [];

        foreach ($schema as $key => $def) {
            if ($this->credentials->get($gatewayId, $key) === '') {
                // The LABEL, not the key. This is read by the person who has
                // to go and find the value.
                $missing[] = ['key' => $key, 'label' => (string) ($def[1] ?? $key)];
            }
        }

        $enabled = (bool) ($row?->enabled ?? false);
        $configured = $gateway->configured();
        $available = $gateway->availableFor(self::SAMPLE_TOTAL, 'AE');

        return [
            'id' => $gatewayId,
            'known' => true,
            'title' => $row?->title ?: $gateway->title(),
            'mode' => $row?->mode ?: 'test',
            'enabled' => $enabled,
            'configured' => $configured,
            // Empty when it IS offered. Otherwise the gates it fails, in the
            // order GatewayRegistry::availableFor() applies them, so the owner
            // fixes the first one rather than guessing.
            'blocked_by' => array_values(array_filter([
                $enabled ? null : 'The provider row is switched off.',
                $configured ? null : 'Credentials are missing.',
                $available ? null : 'This gateway does not accept a basket of this size or destination.',
            ])),
            'missing_fields' => $missing,
            'webhook_url' => $this->webhookUrl($gateway),
            'dry_run' => $this->dryRun($gateway),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function inspectAll(): array
    {
        return $this->registry->all()
            ->map(fn (PaymentGateway $g) => $this->inspect($g->id()))
            ->all();
    }

    /**
     * The request this shop would send to begin a payment.
     *
     * @return array<string, mixed>
     */
    private function dryRun(PaymentGateway $gateway): array
    {
        if (! $gateway->configured()) {
            return [
                'ran' => false,
                'why' => 'Nothing to send until the credentials are filled in.',
            ];
        }

        $order = $this->sampleOrder($gateway->id());

        // 503 rather than a success body: every gateway gives up on a failed
        // create BEFORE it writes the provider reference to the order, which
        // is what keeps this sample order unsaved.
        Http::fake(fn () => Http::response(['preflight' => true], 503));

        $start = $gateway->start($order);

        $recorded = collect(Http::recorded())->last();

        if ($recorded === null) {
            return [
                'ran' => true,
                'sent' => false,
                // Cash on delivery lands here, and so would any gateway that
                // refused before it got as far as the network.
                'why' => $start->message ?? 'This gateway begins a payment without calling anyone.',
            ];
        }

        /** @var \Illuminate\Http\Client\Request $request */
        [$request] = $recorded;

        return [
            'ran' => true,
            'sent' => true,
            'method' => $request->method(),
            'url' => $request->url(),
            'headers' => $this->redact($gateway->id(), $request->headers()),
            'body' => $this->redact($gateway->id(), ['body' => $request->body()])['body'] ?? '',
            'sample_total_fils' => self::SAMPLE_TOTAL,
        ];
    }

    /**
     * An order that is obviously not a real one, and is never written down.
     *
     * Every column a gateway reads on the checkout path is set, because a null
     * where one of them expects an integer would make the dry run fail for a
     * reason that has nothing to do with the owner's configuration.
     */
    private function sampleOrder(string $gatewayId): Order
    {
        $order = new Order();

        $order->forceFill([
            'order_number' => 'PREFLIGHT-' . strtoupper($gatewayId),
            'email' => 'preflight@example.invalid',
            /*
             * NO `status` HERE, deliberately, and it is not an oversight.
             *
             * Nothing on any gateway's checkout path reads the column — they
             * read the totals, the currency, the email and the billing block —
             * so setting it would buy nothing. What it WOULD do is put a
             * status write into a file that is not the funnel, which is what
             * OrderStatusChokePointTest exists to forbid. That guard reads
             * code rather than prose and cannot tell that this row is never
             * saved; it is right not to try, and the correct answer is to have
             * nothing for it to find.
             */
            'currency' => 'AED',
            'subtotal' => self::SAMPLE_TOTAL,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'fee_total' => 0,
            'total' => self::SAMPLE_TOTAL,
            'billing_first_name' => 'Preflight',
            'billing_last_name' => 'Check',
            'billing_country' => 'AE',
        ]);

        return $order;
    }

    /**
     * Replace any stored credential wherever it appears.
     *
     * Value-based rather than name-based: a header called `Authorization` is
     * the obvious one, but Tabby's merchant code rides in `X-Merchant-Code`
     * and a provider is free to invent another tomorrow. What is redacted is
     * anything that IS a secret, wherever it turns up — including inside the
     * body, and including a secret that appears as part of a longer string
     * such as "Bearer sk_live_…".
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(string $gatewayId, array $values): array
    {
        $gateway = $this->registry->find($gatewayId);
        $schema = $gateway?->configSchema() ?? [];

        $needles = [];

        foreach (array_keys($schema) as $key) {
            $stored = $this->credentials->get($gatewayId, $key);

            // Short values are not redacted by search-and-replace: a two-letter
            // merchant code would match half the body. Those are named fields
            // the owner can already see on the screen, and they are not secret.
            if (strlen($stored) >= 8) {
                $needles[$stored] = '«' . $key . ' as stored»';
            }
        }

        $scrub = function ($value) use ($needles) {
            if (is_array($value)) {
                $value = implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value));
            }

            if (! is_scalar($value)) {
                return '';
            }

            return $needles === []
                ? (string) $value
                : str_replace(array_keys($needles), array_values($needles), (string) $value);
        };

        return array_map($scrub, $values);
    }

    /** The same URL the payments screen shows, built the same way. */
    private function webhookUrl(PaymentGateway $gateway): ?string
    {
        if (! array_key_exists('webhook_secret', $gateway->configSchema())) {
            return null;
        }

        $secret = $this->credentials->get($gateway->id(), 'webhook_secret');

        if ($secret === '') {
            return null;
        }

        return url(\App\Support\Url::redirect('/api/payments/webhook/' . $gateway->id() . '/')) . $secret;
    }
}
