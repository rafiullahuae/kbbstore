<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\PaymentProvider;
use App\Services\Payments\Gateways\StripeGateway;
use App\Services\Payments\Gateways\TabbyGateway;
use App\Services\Payments\Gateways\TamaraGateway;
use App\Services\PayShipRules;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\SiteHost;
use App\Support\SiteUrl;
use App\Support\Url;
use Illuminate\Support\Facades\Http;

/**
 * Platform -> Domain switch -> "Payments ready?": one button, every gateway,
 * green / amber / red with a plain-English fix for every red. (Lane DS)
 *
 * The owner, 8 October 2026: "before switch, i need to finalized check for
 * payment gateways."
 *
 * ── READ ONLY, BY CONSTRUCTION ───────────────────────────────────────────
 *
 * Every provider call in this class is a GET (self::get() is the only
 * transport and it cannot send anything else). Nothing is stored, nothing is
 * registered, nothing is deleted: the existing buttons on Store -> Payments,
 * Store -> Gateway webhooks and the wizard's step 7 do that, and every fix
 * below names the one to press.
 *
 * ── NO SECRET LEAVES ─────────────────────────────────────────────────────
 *
 * A key is named by its last four characters at most; a webhook address,
 * whose tail IS a secret, by its host and path with the tail cut to four.
 * A provider's error body is never echoed -- only its status.
 *
 * ── "THE MAIN ADDRESS" ───────────────────────────────────────────────────
 *
 * Providers are told the address the shop USES (APP_URL): today extrabeauty.ae,
 * after step 6 kbeautybliss.com. The main address is the one the shop is
 * moving to (Platform -> Site address). Before the switch the two differ and a
 * webhook on today's address is AMBER -- right for now, to be re-registered at
 * step 7. After the switch they are the same, and "points at the main address"
 * is the check that turns green or red.
 *
 * Network only when the button is pressed, short timeouts, one call per
 * question (Tabby: the merchant country only, not all five).
 */
final class PaymentsReadiness
{
    public const GREEN = 'green';

    public const AMBER = 'amber';

    public const RED = 'red';

    /** Seconds. A check screen that hangs is worse than one that says "try again". */
    public const TIMEOUT = 6;

    public const CONNECT_TIMEOUT = 3;

    /** The providers' hosts, pinned against the gateways' own constants by PaymentsReadinessTest. */
    public const STRIPE_API = 'https://api.stripe.com';

    public const TABBY_API = 'https://api.tabby.ai';

    public const TAMARA_LIVE = 'https://api.tamara.co';

    public const TAMARA_SANDBOX = 'https://api-sandbox.tamara.co';

    private const TABBY_COUNTRIES = ['AE' => 'AED', 'SA' => 'SAR', 'BH' => 'BHD', 'KW' => 'KWD', 'QA' => 'QAR'];

    private const TAMARA_COUNTRY = ['AED' => 'AE', 'SAR' => 'SA', 'KWD' => 'KW', 'BHD' => 'BH', 'QAR' => 'QA', 'OMR' => 'OM'];

    /** COD fee above this (fils) is worth a second look. */
    private const COD_FEE_HIGH = 5000;

    private string $serving = '';

    private string $main = '';

    public function __construct(private GatewayCredentials $credentials) {}

    /** @return array<string, mixed> */
    public function run(): array
    {
        $this->credentials->forget();
        $this->serving = SiteHost::normalise(SiteUrl::configuredHost());
        $this->main = SiteHost::canonical() ?: $this->serving;

        $providers = [];

        foreach (['stripe' => 'Card (Stripe)', 'tabby' => 'Tabby', 'tamara' => 'Tamara', 'cod' => 'Cash on delivery'] as $id => $title) {
            try {
                $checks = match ($id) {
                    'stripe' => $this->stripe(),
                    'tabby' => $this->tabby(),
                    'tamara' => $this->tamara(),
                    'cod' => $this->cod(),
                };
            } catch (\Throwable) {
                $checks = [self::line(self::AMBER, 'Check', 'This check stopped unexpectedly. Nothing was changed; press the button again.')];
            }

            $providers[] = ['id' => $id, 'title' => $title, 'level' => self::worst($checks), 'checks' => $checks];
        }

        $all = array_merge(...array_map(fn ($p) => $p['checks'], $providers));

        return [
            'ok' => true,
            'checked_at' => now()->toIso8601String(),
            'main' => $this->main,
            'serving' => $this->serving,
            'switching' => $this->main !== $this->serving,
            'level' => self::worst($all),
            'counts' => [
                self::GREEN => count(array_filter($all, fn ($c) => $c['level'] === self::GREEN)),
                self::AMBER => count(array_filter($all, fn ($c) => $c['level'] === self::AMBER)),
                self::RED => count(array_filter($all, fn ($c) => $c['level'] === self::RED)),
            ],
            'providers' => $providers,
        ];
    }

    /* ═══════════════════════════════════════════════════════════ Stripe ══ */

    /** @return list<array<string, mixed>> */
    private function stripe(): array
    {
        $row = PaymentProvider::find('stripe');
        $connect = app(StripeConnect::class);
        $mode = $connect->currentMode();
        $config = $this->credentials->all('stripe');
        $secret = StripeKeys::get($config, $mode, 'secret_key');
        $publishable = StripeKeys::get($config, $mode, 'publishable_key');
        $where = 'Store → Payments → Stripe';
        $out = [];

        if ($row === null || ($config === [] && ! $row->enabled)) {
            return [self::line(self::AMBER, 'Set up', 'Stripe is not set up, so the checkout offers no card payment.', 'If you want cards: '.$where.' → paste your keys.')];
        }

        $out[] = $row->enabled
            ? self::line(self::GREEN, 'Offered at checkout', 'Card payment is switched on. Mode: '.($mode === 'live' ? 'Live' : 'Sandbox / test').'.')
            : self::line(self::AMBER, 'Offered at checkout', 'Card payment is switched OFF at the checkout.', 'Switch it on in '.$where.' when you are ready.');

        $problems = StripeKeys::problems($config, $mode);

        foreach ($problems as $problem) {
            $out[] = self::line(self::RED, 'Keys', $problem, 'Fix the key in '.$where.'.');
        }

        if ($problems === []) {
            $out[] = self::line(self::GREEN, 'Keys', 'Secret key '.self::last4($secret).' and publishable key '.self::last4($publishable)
                .' are both '.($mode === 'live' ? 'LIVE' : 'TEST').' keys, matching Mode.');
        }

        if ($mode === 'test') {
            $out[] = self::line(self::AMBER, 'Mode', 'Stripe is in Sandbox / test mode: no real card is charged.', 'Switch Mode to Live in '.$where.' when your test orders pass.');
        }

        if ($secret === '') {
            $out[] = self::line(self::AMBER, 'Stripe account', 'Not checked: there is no secret key for this mode.');

            return $out;
        }

        $auth = ['Authorization' => 'Bearer '.$secret];

        // ── the account
        $account = $this->get(self::STRIPE_API.'/v1/account', $auth);

        if ($account['status'] === null) {
            $out[] = self::line(self::AMBER, 'Stripe account', 'Stripe could not be reached just now.', 'Press the button again in a minute.');
        } elseif ($account['status'] === 401) {
            $out[] = self::line(self::RED, 'Stripe account', 'Stripe refused the secret key '.self::last4($secret).'.', 'Copy the secret key again from Stripe → Developers → API keys and paste it in '.$where.'.');
        } elseif (! $account['ok']) {
            $out[] = self::line(self::AMBER, 'Stripe account', 'Stripe answered '.$account['status'].'.', 'Press the button again in a minute.');
        } else {
            $charges = (bool) ($account['body']['charges_enabled'] ?? false);
            $name = trim((string) ($account['body']['business_profile']['name'] ?? $account['body']['settings']['dashboard']['display_name'] ?? ''));
            $out[] = $charges
                ? self::line(self::GREEN, 'Stripe account', 'Reachable'.($name !== '' ? ' ('.$name.')' : '').', and Stripe allows it to take payments.')
                : self::line($mode === 'live' ? self::RED : self::AMBER, 'Stripe account', 'Stripe has not enabled payments on this account yet.', 'Finish Stripe’s own account activation in the Stripe dashboard.');
        }

        // ── the webhook
        $ours = $connect->webhookUrl();
        $endpointId = StripeKeys::get($config, $mode, 'webhook_endpoint_id');
        $button = 'press “Set up webhook automatically” in '.$where.' (or step 7 of this page)';

        if ($ours === null) {
            $out[] = self::line(self::RED, 'Webhook', 'This shop has no webhook address yet, so Stripe cannot tell it a payment succeeded.', ucfirst($button).'.');
        } else {
            $endpoint = null;
            $read = null;

            if ($endpointId !== '') {
                $read = $this->get(self::STRIPE_API.'/v1/webhook_endpoints/'.rawurlencode($endpointId), $auth);
                $endpoint = $read['ok'] && is_array($read['body']) ? $read['body'] : null;
            } else {
                $read = $this->get(self::STRIPE_API.'/v1/webhook_endpoints?limit=100', $auth);
                $endpoint = $read['ok'] ? $this->pick((array) ($read['body']['data'] ?? []), $ours, 'stripe') : null;
            }

            if ($endpoint === null) {
                $out[] = $read['status'] === null || ($read['status'] >= 500)
                    ? self::line(self::AMBER, 'Webhook', 'Stripe’s webhook list could not be read just now.', 'Press the button again in a minute.')
                    : self::line(self::RED, 'Webhook', 'Stripe has no webhook for this shop, so it cannot tell the shop a card payment succeeded.', ucfirst($button).'.');
            } else {
                $out[] = $this->pointsAt('Webhook', (string) ($endpoint['url'] ?? ''), $ours, 'stripe', $button);

                if (($endpoint['status'] ?? 'enabled') !== 'enabled') {
                    $out[] = self::line(self::RED, 'Webhook switched on', 'The webhook is DISABLED in Stripe.', 'Enable it in Stripe → Developers → Webhooks, or '.$button.'.');
                }

                $events = array_map('strval', (array) ($endpoint['enabled_events'] ?? []));
                $missing = in_array('*', $events, true) ? [] : array_values(array_diff(StripeConnect::EVENTS, $events));

                $out[] = $missing === []
                    ? self::line(self::GREEN, 'Webhook events', 'Listens to all '.count(StripeConnect::EVENTS).' events the shop acts on.')
                    : self::line(self::RED, 'Webhook events', 'Missing: '.implode(', ', $missing).'.', ucfirst($button).': it subscribes the right events.');
            }
        }

        $out[] = StripeKeys::get($config, $mode, 'webhook_signing_secret') !== ''
            ? self::line(self::GREEN, 'Signing secret', 'Present, so the shop can prove a notice really came from Stripe.')
            : self::line(self::RED, 'Signing secret', 'Missing: every notice from Stripe would be refused.', ucfirst($button).'.');

        // ── Apple Pay / Google Pay
        if (app(Wallets::class)->any()) {
            foreach (array_values(array_unique([$this->main, $this->serving])) as $host) {
                if ($host === '') {
                    continue;
                }

                $domains = $this->get(self::STRIPE_API.'/v1/payment_method_domains?'.http_build_query(['domain_name' => self::bare($host)]), $auth);
                $row = $domains['ok'] ? ((array) ($domains['body']['data'] ?? []))[0] ?? null : null;
                $active = is_array($row) && ($row['enabled'] ?? true) !== false && ($row['apple_pay']['status'] ?? 'active') === 'active';

                $out[] = match (true) {
                    $domains['status'] === null => self::line(self::AMBER, 'Apple Pay / Google Pay · '.self::bare($host), 'Stripe could not be reached just now.', 'Press the button again in a minute.'),
                    $active => self::line(self::GREEN, 'Apple Pay / Google Pay · '.self::bare($host), self::bare($host).' is registered with Stripe for wallets.'),
                    // Red where the shop takes payments now; amber for the address it is moving to.
                    default => self::line($host === $this->serving ? self::RED : self::AMBER,
                        'Apple Pay / Google Pay · '.self::bare($host),
                        self::bare($host).' is not registered with Stripe for wallets, so the Apple Pay button will not show there.',
                        'Stripe dashboard → Settings → Payment method domains → Add '.self::bare($host).'.'),
                };
            }
        } else {
            $out[] = self::line(self::GREEN, 'Apple Pay / Google Pay', 'Not offered, so there is no domain to register.');
        }

        // ── the statement text
        $gateway = app(GatewayRegistry::class)->find('stripe');
        $full = $this->credentials->get('stripe', 'statement_descriptor');
        $suffix = $this->credentials->get('stripe', 'statement_descriptor_suffix');
        $prefix = $gateway instanceof StripeGateway ? $gateway->accountPrefixLength() : null;
        $error = StripePaymentText::fullDescriptorError($full) ?? StripePaymentText::suffixError($suffix, $prefix);

        $out[] = $error === null
            ? self::line(self::GREEN, 'Card statement text', 'Valid'.($gateway instanceof StripeGateway ? ': “'.$gateway->fullDescriptor().'”' : '').'.')
            : self::line(self::RED, 'Card statement text', $error, 'Fix it in '.$where.' → Statement.');

        // ── the last failure
        $failed = PaymentLog::latest('stripe', 'intent.failed');
        $opened = PaymentLog::latest('stripe', 'intent.created');

        $out[] = $failed !== null && ($opened === null || $opened['id'] < $failed['id'])
            ? self::line(self::AMBER, 'Last card payment', 'The last card payment that tried to open failed ('.(string) $failed['at'].'): '.mb_substr((string) $failed['message'], 0, 300),
                'Read it in Store → Payments → Payment log. A test order that opens clears this.')
            : self::line(self::GREEN, 'Last card payment', $opened !== null ? 'The last card payment opened normally.' : 'No card payment has been tried yet.');

        return $out;
    }

    /* ════════════════════════════════════════════════════════════ Tabby ══ */

    /** @return list<array<string, mixed>> */
    private function tabby(): array
    {
        $row = PaymentProvider::find('tabby');
        $public = $this->credentials->get('tabby', 'public_key');
        $secret = $this->credentials->get('tabby', 'secret_key');
        $where = 'Store → Payments → Tabby';
        $out = [];

        if ($row === null || ($public === '' && $secret === '' && ! $row->enabled)) {
            return [self::line(self::AMBER, 'Set up', 'Tabby is not set up, so the checkout does not offer it.', 'If you want Tabby: '.$where.' → paste your keys.')];
        }

        $out[] = $row->enabled
            ? self::line(self::GREEN, 'Offered at checkout', 'Tabby is switched on.')
            : self::line(self::AMBER, 'Offered at checkout', 'Tabby is switched OFF at the checkout.', 'Switch it on in '.$where.' when you are ready.');

        if ($public === '' || $secret === '') {
            $out[] = self::line(self::RED, 'Keys', ($secret === '' ? 'The secret key' : 'The public key').' is empty.', 'Paste both keys in '.$where.'.');

            return $out;
        }

        $sandbox = str_starts_with($secret, 'sk_test');
        $shape = str_starts_with($secret, 'sk_') && str_starts_with($public, 'pk_');

        if (! $shape) {
            $out[] = self::line(self::RED, 'Keys', 'The keys are in the wrong boxes or are not Tabby keys (the secret starts sk_, the public pk_).', 'Paste them again in '.$where.'.');
        } elseif (str_starts_with($public, 'pk_test') !== $sandbox) {
            $out[] = self::line(self::RED, 'Keys', 'One key is a sandbox key and the other a live key.', 'Paste the matching pair in '.$where.'.');
        } else {
            $out[] = self::line(self::GREEN, 'Keys', 'Secret key '.self::last4($secret).' and public key '.self::last4($public).' are both '.($sandbox ? 'SANDBOX' : 'LIVE').' keys.');
        }

        $label = (string) ($row->mode ?? 'test');
        $out[] = $sandbox
            ? self::line(self::AMBER, 'Mode', 'These are sandbox keys: no real instalment is taken'.($label === 'live' ? ', although Mode says Live' : '').'.', 'Paste your live keys in '.$where.' when your test orders pass.')
            : self::line(self::GREEN, 'Mode', 'Live keys'.($label !== 'live' ? ' (Mode says Sandbox, but Tabby goes by the keys)' : '').'.');

        $code = strtoupper($this->credentials->get('tabby', 'merchant_code'));
        $derived = $code === '';

        if ($derived) {
            $code = (string) (array_search(strtoupper(Money::currency()), self::TABBY_COUNTRIES, true) ?: 'AE');
        }

        if (! array_key_exists($code, self::TABBY_COUNTRIES)) {
            $out[] = self::line(self::RED, 'Merchant code', '“'.$code.'” is not a Tabby country.', 'Type AE in Merchant code in '.$where.'.');

            return $out;
        }

        $out[] = self::line(self::GREEN, 'Merchant code', $code.($derived ? ' (from the shop’s currency)' : '').'.');

        $hooks = $this->get(self::TABBY_API.'/api/v1/webhooks', ['Authorization' => 'Bearer '.$secret, 'X-Merchant-Code' => $code]);
        $ours = app(TabbyGateway::class)->ourWebhookUrl();
        $button = 'press “Register / re-sync” in Store → Gateway webhooks → Tabby (or step 7 of this page)';

        if ($hooks['status'] === null || $hooks['status'] >= 500) {
            $out[] = self::line(self::AMBER, 'Tabby account', 'Tabby could not be reached just now.', 'Press the button again in a minute.');

            return $out;
        }

        if (in_array($hooks['status'], [401, 403], true)) {
            $out[] = self::line(self::RED, 'Tabby account', 'Tabby refused the secret key '.self::last4($secret).' for merchant code '.$code.'.',
                'Check the secret key and the merchant code in '.$where.' (Tabby dashboard → Business profile → Stores shows both).');

            return $out;
        }

        if (! $hooks['ok']) {
            $out[] = self::line(self::AMBER, 'Tabby account', 'Tabby answered '.$hooks['status'].'.', 'Press the button again in a minute.');

            return $out;
        }

        $out[] = self::line(self::GREEN, 'Tabby account', 'Tabby accepted the secret key for '.$code.'.');

        $list = $hooks['body'];
        $list = is_array($list) && isset($list['id']) ? [$list] : array_values(array_filter((array) ($list['data'] ?? $list['webhooks'] ?? $list ?? []), 'is_array'));

        if ($ours === null) {
            $out[] = self::line(self::RED, 'Webhook', 'This shop has no Tabby webhook address yet.', 'Save the Tabby tab once in '.$where.', then '.$button.'.');

            return $out;
        }

        $hook = $this->pick($list, $ours, 'tabby');

        if ($hook === null) {
            $out[] = self::line(self::RED, 'Webhook', 'Tabby has no webhook for this shop, so it cannot tell the shop an instalment plan was approved.', ucfirst($button).'.');

            return $out;
        }

        $out[] = $this->pointsAt('Webhook', (string) ($hook['url'] ?? ''), $ours, 'tabby', $button);

        $isTest = $hook['is_test'] ?? false;
        $isTest = is_string($isTest) ? in_array(strtolower($isTest), ['1', 'true', 'yes'], true) : (bool) $isTest;

        if ($isTest !== $sandbox) {
            $out[] = self::line(self::RED, 'Webhook environment', 'The webhook is flagged '.($isTest ? 'sandbox' : 'live').' but the keys are '.($sandbox ? 'sandbox' : 'live').' keys, so Tabby will not send it real notices.', ucfirst($button).'.');
        }

        return $out;
    }

    /* ═══════════════════════════════════════════════════════════ Tamara ══ */

    /** @return list<array<string, mixed>> */
    private function tamara(): array
    {
        $row = PaymentProvider::find('tamara');
        $token = $this->credentials->get('tamara', 'api_token');
        $where = 'Store → Payments → Tamara';
        $out = [];

        if ($row === null || ($token === '' && ! $row->enabled)) {
            return [self::line(self::AMBER, 'Set up', 'Tamara is not set up, so the checkout does not offer it.', 'If you want Tamara: '.$where.' → paste the API token.')];
        }

        $live = $this->credentials->live('tamara');

        $out[] = $row->enabled
            ? self::line(self::GREEN, 'Offered at checkout', 'Tamara is switched on.')
            : self::line(self::AMBER, 'Offered at checkout', 'Tamara is switched OFF at the checkout.', 'Switch it on in '.$where.' when you are ready.');

        $out[] = $live
            ? self::line(self::GREEN, 'Mode', 'Live: the shop talks to api.tamara.co.')
            : self::line(self::AMBER, 'Mode', 'Sandbox: the shop talks to api-sandbox.tamara.co and no real plan is taken.', 'Switch Mode to Live in '.$where.' (with your live token) when your test orders pass.');

        if ($token === '') {
            $out[] = self::line(self::RED, 'API token', 'Empty.', 'Paste the API token from Tamara’s merchant portal → Settings → API in '.$where.'.');

            return $out;
        }

        $out[] = $this->credentials->get('tamara', 'notification_token') !== ''
            ? self::line(self::GREEN, 'Notification token', 'Present, so the shop can check a notice really came from Tamara.')
            : self::line(self::RED, 'Notification token', 'Missing: every notice from Tamara would be refused.', 'Paste it from Tamara’s merchant portal → Settings → API in '.$where.'.');

        $base = $live ? self::TAMARA_LIVE : self::TAMARA_SANDBOX;
        $auth = ['Authorization' => 'Bearer '.$token];
        $currency = strtoupper(Money::currency());
        $country = self::TAMARA_COUNTRY[$currency] ?? 'AE';
        $types = $this->get($base.'/checkout/payment-types?'.http_build_query(['country' => $country, 'currency' => $currency]), $auth);

        if ($types['status'] === null || $types['status'] >= 500) {
            $out[] = self::line(self::AMBER, 'Tamara account', 'Tamara could not be reached just now.', 'Press the button again in a minute.');
        } elseif (in_array($types['status'], [401, 403], true)) {
            $out[] = self::line(self::RED, 'Tamara account', 'Tamara’s '.($live ? 'LIVE' : 'SANDBOX').' server refused the API token '.self::last4($token).'.',
                'A sandbox token works only with Mode = Sandbox, a live one only with Live. Fix the token or Mode in '.$where.'.');
        } elseif (! $types['ok']) {
            $out[] = self::line(self::AMBER, 'Tamara account', 'Tamara answered '.$types['status'].'.', 'Press the button again in a minute.');
        } else {
            $out[] = self::line(self::GREEN, 'Tamara account', 'Tamara’s '.($live ? 'live' : 'sandbox').' server accepted the API token '.self::last4($token).' for '.$country.' / '.$currency.'.');
        }

        $secret = $this->credentials->get('tamara', 'webhook_secret');
        $ours = $secret === '' ? null : url(Url::external('/api/payments/webhook/tamara/')).$secret;
        $id = $this->credentials->get('tamara', 'webhook_id');
        $button = 'press “Remove the registration”, then “Register the webhook” in Store → Gateway webhooks → Tamara (or step 7 of this page)';

        if ($ours !== null) {
            $out[] = $this->pointsAt('Order notices', $ours, $ours, 'tamara', '');
        }

        if ($ours === null) {
            $out[] = self::line(self::RED, 'Webhook', 'This shop has no Tamara webhook address yet.', 'Save the Tamara tab once in '.$where.', then '.$button.'.');
        } elseif ($id === '') {
            $out[] = self::line(self::RED, 'Webhook', 'No webhook is registered, so a declined or expired Tamara order is never cancelled here.', 'Press “Register the webhook” in Store → Gateway webhooks → Tamara (or step 7 of this page).');
        } else {
            $hook = $this->get($base.'/webhooks/'.rawurlencode($id), $auth);
            $body = is_array($hook['body']) ? (is_array($hook['body']['data'] ?? null) ? $hook['body']['data'] : $hook['body']) : [];

            if ($hook['status'] === 404) {
                $out[] = self::line(self::RED, 'Webhook', 'Tamara no longer knows the registration this shop holds.', ucfirst($button).'.');
            } elseif (! $hook['ok'] || ! is_string($body['url'] ?? null)) {
                $out[] = self::line(self::AMBER, 'Webhook', 'Tamara’s registration could not be read just now'.($hook['status'] !== null ? ' ('.$hook['status'].')' : '').'.', 'Press the button again in a minute.');
            } else {
                $out[] = $this->pointsAt('Webhook', (string) $body['url'], $ours, 'tamara', $button);
                $events = array_map('strval', (array) ($body['events'] ?? TamaraGateway::WEBHOOK_EVENTS));
                $missing = array_values(array_diff(TamaraGateway::WEBHOOK_EVENTS, $events));

                if ($missing !== []) {
                    $out[] = self::line(self::RED, 'Webhook events', 'Missing: '.implode(', ', $missing).'.', ucfirst($button).'.');
                }
            }
        }

        return $out;
    }

    /* ═══════════════════════════════════════════════════════════════ COD ══ */

    /** @return list<array<string, mixed>> */
    private function cod(): array
    {
        $row = PaymentProvider::find('cod');
        $settings = app(SettingsService::class);
        $fee = max(0, (int) $settings->get('cod_fee', 0));
        $where = 'Store → Payments → Cash on delivery';
        $out = [];

        $out[] = $row !== null && $row->enabled
            ? self::line(self::GREEN, 'Offered at checkout', 'Cash on delivery is switched on.')
            : self::line(self::AMBER, 'Offered at checkout', 'Cash on delivery is switched OFF at the checkout.', 'Switch it on in '.$where.' if you want it.');

        $out[] = $fee > self::COD_FEE_HIGH
            ? self::line(self::AMBER, 'Fee', 'The cash-on-delivery fee is '.Money::plain($fee).', which is unusually high.', 'Check it in Store → Ecommerce → Checkout.')
            : self::line(self::GREEN, 'Fee', $fee > 0 ? Money::plain($fee).' per order.' : 'No fee.');

        if (! $settings->moduleEnabled('pay_ship_rules', false)) {
            $out[] = self::line(self::GREEN, 'Limits', 'No lower or upper limit: offered on every basket.');

            return $out;
        }

        $c = app(PayShipRules::class)->all();
        $min = (int) ($c['cod_min'] ?? 0);
        $max = (int) ($c['cod_max'] ?? 0);

        $out[] = $min > 0 && $max > 0 && $min > $max
            ? self::line(self::RED, 'Limits', 'Hidden below '.Money::plain($min).' and above '.Money::plain($max).', so it is never offered.', 'Fix the two limits in Store → Payments & shipping rules.')
            : self::line(self::GREEN, 'Limits', ($min > 0 ? 'From '.Money::plain($min) : 'No lower limit').', '.($max > 0 ? 'up to '.Money::plain($max) : 'no upper limit').'.');

        return $out;
    }

    /* ═════════════════════════════════════════════════════════ helpers ══ */

    /**
     * Does this registered URL point at this shop, on the main address?
     *
     * @return array<string, mixed>
     */
    private function pointsAt(string $title, string $registered, string $ours, string $gateway, string $button): array
    {
        $host = SiteHost::normalise((string) parse_url($registered, PHP_URL_HOST));
        $shown = self::maskUrl($registered);

        if ($registered !== '' && hash_equals($ours, $registered)) {
            if ($host === $this->main) {
                return self::line(self::GREEN, $title, 'Points at '.$shown.', the main address.');
            }

            return self::line(self::AMBER, $title, 'Points at '.$shown.': '.$host.' is the address the shop uses today, and the main address is '.$this->main.'.',
                $button !== ''
                    ? 'Right until the switch. After step 6 of this page, '.$button.', then run this check again.'
                    : 'Follows the shop’s address by itself after step 6; nothing to press.');
        }

        $path = (string) parse_url($registered, PHP_URL_PATH);

        if (str_starts_with($path, '/api/payments/webhook/'.$gateway.'/') && $host === SiteHost::normalise((string) parse_url($ours, PHP_URL_HOST))) {
            return self::line(self::RED, $title, 'Points at this shop with an old webhook secret ('.$shown.'), so every notice is refused.', ucfirst($button).'.');
        }

        return self::line(self::RED, $title, 'Still points at '.($host !== '' ? $host : 'another address').' ('.$shown.'), not at '.$this->main.'.', ucfirst($button).'.');
    }

    /**
     * The registration that is this shop's: the exact URL, else one on this
     * shop's webhook path at any host (an old address).
     *
     * @param  array<int, mixed>  $list
     * @return array<string, mixed>|null
     */
    private function pick(array $list, string $ours, string $gateway): ?array
    {
        $fallback = null;

        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = (string) ($item['url'] ?? '');

            if ($url !== '' && hash_equals($ours, $url)) {
                return $item;
            }

            if ($fallback === null && str_contains((string) parse_url($url, PHP_URL_PATH), '/api/payments/webhook/'.$gateway.'/')) {
                $fallback = $item;
            }
        }

        return $fallback;
    }

    /**
     * The only transport: a GET, short timeouts, the body decoded, never the
     * exception's text (it can carry the request headers).
     *
     * @param  array<string, string>  $headers
     * @return array{ok: bool, status: int|null, body: mixed}
     */
    private function get(string $url, array $headers): array
    {
        try {
            $response = Http::withHeaders($headers)->acceptJson()->timeout(self::TIMEOUT)->connectTimeout(self::CONNECT_TIMEOUT)->get($url);
        } catch (\Throwable) {
            return ['ok' => false, 'status' => null, 'body' => null];
        }

        return ['ok' => $response->successful(), 'status' => $response->status(), 'body' => $response->successful() ? $response->json() : null];
    }

    /** @return array{level: string, title: string, detail: string, fix: string|null} */
    private static function line(string $level, string $title, string $detail, ?string $fix = null): array
    {
        return ['level' => $level, 'title' => $title, 'detail' => $detail, 'fix' => $level === self::GREEN ? null : $fix];
    }

    /** @param  list<array<string, mixed>>  $checks */
    private static function worst(array $checks): string
    {
        $levels = array_column($checks, 'level');

        return in_array(self::RED, $levels, true) ? self::RED : (in_array(self::AMBER, $levels, true) ? self::AMBER : self::GREEN);
    }

    /** "…a1b2" -- never more of a secret than that. */
    public static function last4(string $secret): string
    {
        return $secret === '' ? '(empty)' : '…'.substr($secret, -4);
    }

    /** https://host/api/payments/webhook/stripe/…a1b2: the address, with its secret tail cut. */
    public static function maskUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return '(unreadable address)';
        }

        $path = (string) ($parts['path'] ?? '/');
        $cut = strrpos(rtrim($path, '/'), '/');
        $tail = $cut === false ? '' : substr($path, $cut + 1);
        $head = $cut === false ? $path : substr($path, 0, $cut + 1);

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].$head.($tail !== '' ? '…'.substr($tail, -4) : '');
    }

    private static function bare(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
