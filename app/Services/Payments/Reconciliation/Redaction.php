<?php

declare(strict_types=1);

namespace App\Services\Payments\Reconciliation;

use App\Services\Payments\GatewayCredentials;

/**
 * No stored credential reaches a finding, a log line or an exception message.
 *
 * VALUE-BASED, AND VISIBLE — the two properties GatewayPreflight established
 * on this project, applied to a different pipeline.
 *
 *   Value-based, because name-based redaction only ever covers the field you
 *   thought of. `Authorization` is the obvious one; Tabby's merchant code
 *   rides in `X-Merchant-Code`, a provider is free to invent a third tomorrow,
 *   and an error string is not a named field at all. What is removed here is
 *   anything that IS a secret, wherever it turns up, including as part of a
 *   longer string such as "Bearer sk_live_…".
 *
 *   Visible, because a value that silently vanished reads as "this call sends
 *   no credentials", which is the wrong lesson for anyone debugging a 401. It
 *   is replaced with «field as stored», which says both that something was
 *   there and which box on the payments screen it came from.
 *
 * This is a second implementation of the same idea rather than a call into
 * GatewayPreflight's private method, and that is a deliberate trade: the
 * alternative was to widen a class Lane EF owns, on a lane that does not own
 * it, to serve a caller it was not built for. Two small copies of "replace
 * every stored secret, visibly" are cheaper than one shared one that grew a
 * parameter. If they ever disagree, the failure is that a secret is redacted
 * TWICE differently, not that one path stops redacting.
 *
 * The minimum length is the same 8 characters and for the same reason: a
 * two-letter merchant code would match half of every string it was swept
 * through, and it is a named field the owner can already read off his own
 * screen.
 */
final class Redaction
{
    private const MIN_SECRET_LENGTH = 8;

    public function __construct(private GatewayCredentials $credentials) {}

    /**
     * Scrub one string.
     *
     * @param  array<int, string>  $configKeys  the gateway's configSchema keys
     */
    public function scrub(string $gatewayId, array $configKeys, string $value): string
    {
        $needles = $this->needles($gatewayId, $configKeys);

        return $needles === []
            ? $value
            : str_replace(array_keys($needles), array_values($needles), $value);
    }

    /**
     * Scrub a whole structure, keys included.
     *
     * Keys as well as values because a payload is allowed to be shaped
     * ['sk_live_…' => 'used'], and an array key is a string like any other.
     *
     * @param  array<int, string>  $configKeys
     */
    public function scrubArray(string $gatewayId, array $configKeys, array $data): array
    {
        $needles = $this->needles($gatewayId, $configKeys);

        if ($needles === []) {
            return $data;
        }

        $swap = fn (string $s): string => str_replace(array_keys($needles), array_values($needles), $s);

        $walk = function ($value) use (&$walk, $swap) {
            if (is_array($value)) {
                $out = [];

                foreach ($value as $k => $v) {
                    $out[is_string($k) ? $swap($k) : $k] = $walk($v);
                }

                return $out;
            }

            return is_string($value) ? $swap($value) : $value;
        };

        return $walk($data);
    }

    /**
     * @param  array<int, string>  $configKeys
     * @return array<string, string>
     */
    private function needles(string $gatewayId, array $configKeys): array
    {
        $needles = [];

        foreach ($configKeys as $key) {
            $stored = $this->credentials->get($gatewayId, (string) $key);

            if (strlen($stored) >= self::MIN_SECRET_LENGTH) {
                $needles[$stored] = '«' . $key . ' as stored»';
            }
        }

        return $needles;
    }
}
