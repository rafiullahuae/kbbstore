<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Which of Stripe's two key pairs is in force. (Lane SR.)
 *
 * ── THE STORAGE SHAPE ──────────────────────────────────────────────────────
 *
 * Stripe gives every account two complete sets of credentials, test and live,
 * and the WooCommerce plugin the owner is matching keeps both: a "test mode"
 * switch picks one set, and switching back does not mean pasting keys again.
 * This shop now does the same. In `payment_providers.config` for `stripe`:
 *
 *     publishable_key        secret_key        webhook_signing_secret   LIVE
 *     publishable_key_test   secret_key_test   webhook_signing_secret_test  TEST
 *
 * plus `webhook_endpoint_id` / `webhook_endpoint_managed` (and their `_test`
 * twins), because a test endpoint and a live endpoint are different objects in
 * Stripe with different signing secrets. `payment_providers.mode` is the switch.
 *
 * The unsuffixed names are LIVE because that is the convention this codebase
 * already set for Stripe: StripeConnect::PLATFORM_KEYS keeps the live client id
 * in `connect_client_id` and the test one in `connect_client_id_test`.
 *
 * ── THE ONE HAZARD, AND WHAT THE RESOLVER DOES ABOUT IT ────────────────────
 *
 * Until this class there was ONE set of boxes, and a shop that was set up in
 * test mode holds its sk_test_ key in `secret_key` — the slot that is now
 * "live". Two things follow:
 *
 *   - In TEST mode, an empty test slot falls back to the unsuffixed value when
 *     that value is a test key (or carries no mode at all). So a shop set up
 *     before this change keeps working, byte for byte, with nothing re-pasted.
 *   - In LIVE mode, an unsuffixed value that is a TEST key is NOT used. A test
 *     key on a shop whose switch says Live lets a shopper "pay" with Stripe's
 *     public test card 4242 4242 4242 4242 and receive real goods. Reading
 *     nothing instead takes the card option off the till (availableFor()) and
 *     the admin says why, which is the failure a person can see and fix.
 *
 * The mode of the unsuffixed set is read off its secret key's prefix (sk_test_
 * / rk_test_ against sk_live_ / rk_live_), falling back to the publishable
 * key's. A webhook signing secret has no mode in its text, so it follows the
 * set it is stored with.
 *
 * Everything here is a pure function of a config array. Wallets::compute() runs
 * on every page of the shop and already holds the row, so resolving keys must
 * cost it no query.
 */
final class StripeKeys
{
    /** The names that exist once per mode. */
    public const SLOTTED = [
        'publishable_key',
        'secret_key',
        'webhook_signing_secret',
        'webhook_endpoint_id',
        'webhook_endpoint_managed',
    ];

    public static function mode(?string $mode): string
    {
        return $mode === 'live' ? 'live' : 'test';
    }

    /** The config key a name is stored under in a mode. */
    public static function slot(string $name, string $mode): string
    {
        return self::mode($mode) === 'test' ? $name . '_test' : $name;
    }

    /**
     * The value in force for this mode.
     *
     * @param  array<string, mixed>  $config
     */
    public static function get(array $config, ?string $mode, string $name): string
    {
        $mode = self::mode($mode);
        $found = self::raw($config, $mode, $name);

        /*
         * ▲ A KEY THAT CANNOT DO THIS SLOT'S JOB IS NO KEY. (Lane ST.)
         *
         * The checkout boots Stripe.js with the publishable key and opens the
         * payment with the secret key, and both must be of this Mode. A pk_ in
         * a secret slot sent "Authorization: Bearer pk_…", which Stripe refuses
         * with 401 — card fields drawn, every Place order answered "We could
         * not reach our card processor." An sk_ in a publishable slot would be
         * printed into every checkout page. A pk_live_ beside an sk_test_
         * boots Stripe.js on the live account while the server opens test
         * payments. Reading '' instead takes the card off the till
         * (availableFor()) and problems() tells the admin which box is wrong.
         *
         * Only a key whose own prefix says it is the wrong kind or the wrong
         * mode is refused; a value with no recognisable prefix is passed
         * through as before.
         */
        if ($found !== '' && in_array($name, ['publishable_key', 'secret_key'], true)) {
            $kind = self::keyKind($found);
            $keyMode = self::keyMode($found);

            if (($kind !== null && $kind !== $name) || ($keyMode !== null && $keyMode !== $mode)) {
                return '';
            }
        }

        return $found;
    }

    /** 'publishable_key' for pk_, 'secret_key' for sk_ / rk_, else null. */
    public static function keyKind(string $key): ?string
    {
        $key = trim($key);

        if (str_starts_with($key, 'pk_')) {
            return 'publishable_key';
        }

        if (str_starts_with($key, 'sk_') || str_starts_with($key, 'rk_')) {
            return 'secret_key';
        }

        return null;
    }

    /**
     * What is wrong with the keys for this Mode, in words the owner can act
     * on, naming the box as the screen labels it. (Lane ST.) Pure: the Stripe
     * status block reads it; nothing on a shop page does.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function problems(array $config, ?string $mode): array
    {
        $mode = self::mode($mode);
        $box = static fn (string $name): string => ($mode === 'test' ? 'Test ' : 'Live ')
            . ($name === 'secret_key' ? 'secret key' : 'publishable key');
        $modeWord = $mode === 'test' ? 'Sandbox / test' : 'Live';

        // The old single key set, holding TEST keys, read under Live: one
        // sentence says all of it (it was StripeConnect::settingsStatus()'s).
        if ($mode === 'live' && self::legacyMode($config) === 'test') {
            return ['Mode is Live but the Live key boxes hold TEST keys, so card payments are switched off until you paste your sk_live_ and pk_live_ keys. '
                . 'Saving the Stripe tab once moves the test keys into the Test boxes.'];
        }

        $out = [];

        foreach (['secret_key', 'publishable_key'] as $name) {
            $found = self::raw($config, $mode, $name);

            if ($found === '') {
                $out[] = sprintf('The %s box is empty, so card payments are switched off at the checkout. Paste the key from Stripe → Developers → API keys%s.', $box($name), $mode === 'test' ? ' (with Test mode on)' : '');

                continue;
            }

            $kind = self::keyKind($found);
            $keyMode = self::keyMode($found);

            if ($kind !== null && $kind !== $name) {
                $out[] = $name === 'secret_key'
                    ? sprintf('The %s box holds a PUBLISHABLE key (it starts pk_). Stripe refuses to open a payment with it, so card payments are switched off. Paste the key that starts sk_%s_ instead.', $box($name), $mode)
                    : sprintf('The %s box holds a SECRET key (it starts sk_ or rk_). It would be shown to every shopper, so card payments are switched off. Paste the key that starts pk_%s_ there, and roll the secret key in Stripe if this page was ever live.', $box($name), $mode);
            } elseif ($keyMode !== null && $keyMode !== $mode) {
                $out[] = sprintf('Your %s is a %s key but Mode is %s, so card payments are switched off. Either switch Mode to %s or paste the %s key.', $name === 'secret_key' ? 'secret key' : 'publishable key', strtoupper($keyMode), $modeWord, $keyMode === 'test' ? 'Sandbox / test' : 'Live', $mode === 'test' ? 'pk_test_ / sk_test_' : 'pk_live_ / sk_live_');
            }
        }

        return $out;
    }

    /**
     * The value stored for this mode, before the kind/mode check in get().
     *
     * @param  array<string, mixed>  $config
     */
    private static function raw(array $config, string $mode, string $name): string
    {
        $value = static fn (string $key): string => is_scalar($config[$key] ?? null) ? trim((string) $config[$key]) : '';

        if (! in_array($name, self::SLOTTED, true)) {
            return $value($name);
        }

        $legacy = self::legacyMode($config);

        if ($mode === 'test') {
            $own = $value($name . '_test');

            if ($own !== '') {
                return $own;
            }

            /*
             * The test slot is empty. Fall back to the unsuffixed value ONLY
             * when the test set is not in use at all — a half-filled test set
             * must not borrow the other half from somewhere else — and only
             * when that value is not a live credential.
             */
            if (self::slotInUse($config, 'test') || $legacy === 'live') {
                return '';
            }

            return $value($name);
        }

        return $legacy === 'test' ? '' : $value($name);
    }

    /**
     * Which mode the unsuffixed set belongs to, by the text of its keys.
     *
     * @param  array<string, mixed>  $config
     * @return 'test'|'live'|null
     */
    public static function legacyMode(array $config): ?string
    {
        foreach (['secret_key', 'publishable_key'] as $key) {
            $mode = self::keyMode(is_scalar($config[$key] ?? null) ? (string) $config[$key] : '');

            if ($mode !== null) {
                return $mode;
            }
        }

        return null;
    }

    /** test / live / null for a pk_, sk_ or rk_ key; null for anything else. */
    public static function keyMode(string $key): ?string
    {
        $key = trim($key);

        if (preg_match('/^(pk|sk|rk)_test_/', $key)) {
            return 'test';
        }

        if (preg_match('/^(pk|sk|rk)_live_/', $key)) {
            return 'live';
        }

        return null;
    }

    /**
     * The changes that move a legacy TEST set out of the live boxes and into
     * the test boxes, or [] when there is nothing to move.
     *
     * Only ever moves: never invents a value, never drops one that has nowhere
     * to go. If the test boxes already hold a set, the legacy test values are
     * shadowed (get() never reads them in either mode) and are cleared so the
     * live boxes stop displaying a key that is not live.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string|null>  for GatewayCredentials::save()
     */
    public static function normalise(array $config): array
    {
        if (self::legacyMode($config) !== 'test') {
            return [];
        }

        $testInUse = self::slotInUse($config, 'test');
        $patch = [];

        foreach (self::SLOTTED as $name) {
            $legacy = is_scalar($config[$name] ?? null) ? trim((string) $config[$name]) : '';

            /*
             * A LIVE key beside the legacy test set stays where it is: it is
             * in the right box already. Moving it into the Test box paired a
             * pk_live_ with an sk_test_. (Lane ST.)
             */
            if ($legacy === '' || self::keyMode($legacy) === 'live') {
                continue;
            }

            if (! $testInUse) {
                $patch[$name . '_test'] = $legacy;
            }

            $patch[$name] = null;
        }

        return $patch;
    }

    /** @param  array<string, mixed>  $config */
    private static function slotInUse(array $config, string $mode): bool
    {
        foreach (['publishable_key', 'secret_key', 'webhook_signing_secret'] as $name) {
            $key = self::slot($name, $mode);

            if (is_scalar($config[$key] ?? null) && trim((string) $config[$key]) !== '') {
                return true;
            }
        }

        return false;
    }
}
