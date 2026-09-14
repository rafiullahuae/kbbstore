<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\PaymentProvider;

/**
 * Where gateway credentials live.
 *
 * `payment_providers.config`, which the schema created for exactly this and
 * which the model already casts `encrypted:array`. Not the `settings` table,
 * and the difference is not stylistic:
 *
 *   - `/api/settings` is public and unauthenticated. It returned the whole
 *     settings table to anyone who asked until the PUBLIC_KEYS allowlist
 *     landed, and that is the kind of mistake that gets made twice. A secret
 *     key that is not in `settings` cannot leak from `settings` however that
 *     controller is rewritten later.
 *   - `config` is encrypted at rest by the model's cast. A row in `settings`
 *     is plain text in the database and in every backup of it.
 *
 * Nothing here ever returns a secret to a caller that only wanted to know
 * whether one is set — use filled() for that.
 */
class GatewayCredentials
{
    /**
     * Read once per request per gateway. A checkout page asks four gateways
     * whether they are configured, and each answer is a row read.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $memo = [];

    /** @return array<string, mixed> */
    public function all(string $gatewayId): array
    {
        if (array_key_exists($gatewayId, $this->memo)) {
            return $this->memo[$gatewayId];
        }

        $row = PaymentProvider::find($gatewayId);

        // A decrypt failure (the app key was rotated, the row was written by
        // another install) must not take the checkout page down. An empty
        // config reads as "not configured", which is the safe answer.
        try {
            $config = $row?->config;
        } catch (\Throwable) {
            $config = null;
        }

        return $this->memo[$gatewayId] = is_array($config) ? $config : [];
    }

    public function get(string $gatewayId, string $key, string $default = ''): string
    {
        $value = $this->all($gatewayId)[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** Is every one of these keys present and non-empty? */
    public function filled(string $gatewayId, string ...$keys): bool
    {
        foreach ($keys as $key) {
            if ($this->get($gatewayId, $key) === '') {
                return false;
            }
        }

        return $keys !== [];
    }

    public function mode(string $gatewayId): string
    {
        return PaymentProvider::find($gatewayId)?->mode === 'live' ? 'live' : 'test';
    }

    public function live(string $gatewayId): bool
    {
        return $this->mode($gatewayId) === 'live';
    }

    /**
     * Merge changes into a gateway's config.
     *
     * A secret arriving as an empty string means "leave the stored one alone",
     * which is what lets the admin screen render secrets as blank boxes
     * without a save wiping them. Passing null clears a key deliberately.
     *
     * @param array<string, mixed> $values
     */
    public function save(string $gatewayId, array $values, array $secretKeys = []): void
    {
        $row = PaymentProvider::firstOrNew(['id' => $gatewayId]);

        try {
            $config = is_array($row->config) ? $row->config : [];
        } catch (\Throwable) {
            $config = [];
        }

        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($config[$key]);

                continue;
            }

            if (in_array($key, $secretKeys, true) && trim((string) $value) === '') {
                continue;   // blank secret box = unchanged
            }

            $config[$key] = is_string($value) ? trim($value) : $value;
        }

        $row->config = $config;
        $row->save();

        unset($this->memo[$gatewayId]);
    }

    public function forget(?string $gatewayId = null): void
    {
        if ($gatewayId === null) {
            $this->memo = [];

            return;
        }

        unset($this->memo[$gatewayId]);
    }
}
