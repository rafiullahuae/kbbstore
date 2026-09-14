<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Store -> Ecommerce -> Payments.
 *
 * Follows PayShipRulesApiController's shape: show() returns fields plus their
 * current values, save() takes a settings map and validates the keys against a
 * schema. The schema here is per gateway, declared by the gateway class, so
 * adding a gateway does not mean editing this controller.
 *
 * ---------------------------------------------------------------------------
 * The one rule this file exists to enforce: a secret is never returned.
 *
 * Not by this endpoint, even though it sits behind the admin session guard.
 * Secret fields come back as an empty string with `has_value` saying whether
 * one is stored. So:
 *
 *   - the admin screen renders blank boxes, which is what the owner is asked
 *     to fill in
 *   - a blank secret on save means "leave the stored one alone", so a save
 *     after an edit to some other field does not silently wipe the keys
 *   - the response body of this endpoint is not a place a live Stripe secret
 *     key can be read out of, whether from a browser cache, a proxy log, or
 *     over somebody's shoulder
 *
 * None of these keys touch the `settings` table at all. They live in
 * `payment_providers.config`, which is encrypted and which no public endpoint
 * reads -- so /api/settings cannot leak them however its allowlist changes.
 */
class PaymentsApiController extends Controller
{
    public function __construct(
        private GatewayRegistry $registry,
        private GatewayCredentials $credentials,
    ) {}

    public function show(): JsonResponse
    {
        $gateways = $this->registry->all()->map(function (PaymentGateway $gateway) {
            $row = PaymentProvider::find($gateway->id());
            $schema = $gateway->configSchema();

            $fields = [];

            foreach ($schema as $key => $def) {
                [$type, $label, $help] = array_pad($def, 3, '');

                $stored = $this->credentials->get($gateway->id(), $key);
                $secret = $type === 'secret';

                $fields[] = [
                    'key' => $key,
                    'type' => $type,
                    'label' => $label,
                    'help' => $help,
                    // A secret is NEVER sent back. Not masked with asterisks --
                    // empty, with a flag. A mask still tells you the length.
                    'value' => $secret ? '' : $stored,
                    'has_value' => $stored !== '',
                ];
            }

            return [
                'id' => $gateway->id(),
                'title' => $row?->title ?: $gateway->title(),
                'enabled' => (bool) ($row?->enabled ?? false),
                'mode' => $row?->mode ?: 'test',
                'position' => (int) ($row?->position ?? 0),
                'configured' => $gateway->configured(),
                'fields' => $fields,
                // Built here so nobody has to assemble it by hand from the base
                // path, the /api prefix and the secret. Empty until a webhook
                // secret exists, which saving once creates.
                'webhook_url' => $this->webhookUrl($gateway),
            ];
        })->values();

        return response()->json(['gateways' => $gateways]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:40'],
            'enabled' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:120'],
            'mode' => ['nullable', 'string', 'in:test,live'],
            'position' => ['nullable', 'integer', 'min:0', 'max:999'],
            'settings' => ['nullable', 'array'],
        ]);

        $gateway = $this->registry->find($data['id']);

        if ($gateway === null) {
            return response()->json(['ok' => false, 'error' => 'Unknown payment gateway.'], 422);
        }

        $schema = $gateway->configSchema();
        $values = $data['settings'] ?? [];

        // Same fail-closed check PayShipRulesApiController makes: an unknown
        // key is an error, not something quietly written to the config blob.
        $unknown = array_diff(array_keys($values), array_keys($schema));

        if ($unknown !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown setting: ' . implode(', ', $unknown),
            ], 422);
        }

        $secretKeys = array_keys(array_filter($schema, fn ($def) => ($def[0] ?? '') === 'secret'));

        $row = PaymentProvider::firstOrNew(['id' => $gateway->id()]);
        $row->fill([
            'title' => $data['title'] ?? $row->title ?? $gateway->title(),
            'enabled' => array_key_exists('enabled', $data) ? (bool) $data['enabled'] : (bool) $row->enabled,
            'mode' => $data['mode'] ?? $row->mode ?? 'test',
            'position' => $data['position'] ?? $row->position ?? 0,
        ]);
        $row->save();

        $this->credentials->save($gateway->id(), $values, $secretKeys);

        // A webhook secret nobody has to invent. Generated once, on the first
        // save, and only if the gateway has such a field -- it is what makes
        // the endpoint URL unguessable, so a blank one would leave the webhook
        // relying on the provider's signature alone.
        if (array_key_exists('webhook_secret', $schema)
            && $this->credentials->get($gateway->id(), 'webhook_secret') === '') {
            $this->credentials->save(
                $gateway->id(),
                ['webhook_secret' => 'whsec-' . $gateway->id() . '-' . Str::random(32)],
            );
        }

        $this->credentials->forget($gateway->id());

        return response()->json(['ok' => true, 'webhook_url' => $this->webhookUrl($gateway)]);
    }

    /**
     * The URL to paste into the provider's dashboard.
     *
     * Matches routes/payments-webhooks.php mounted inside routes/api.php.
     */
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
