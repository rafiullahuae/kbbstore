<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Webhook signature primitives.
 *
 * Every comparison in here is hash_equals. A `==` on a signature leaks the
 * position of the first wrong byte through timing, and with a retryable
 * endpoint that is a practical attack, not a theoretical one — and the thing
 * being protected is "mark this order paid".
 *
 * Written by hand rather than pulled from a package on purpose. This app ships
 * as signed zip packages to shared hosting with no shell, so `composer
 * require` is not available at deploy time; a new runtime dependency would
 * have to be vendored into the package by hand on every update.
 */
final class Signature
{
    /** Timestamped signatures older than this are refused, in seconds. */
    public const TOLERANCE = 300;

    /** Constant-time compare that tolerates either side being absent. */
    public static function equals(?string $known, ?string $given): bool
    {
        if ($known === null || $given === null || $known === '' || $given === '') {
            return false;
        }

        return hash_equals($known, $given);
    }

    /** hex HMAC, compared in constant time. */
    public static function hmacMatches(string $algo, string $payload, string $key, string $given): bool
    {
        if ($key === '') {
            return false;     // never let a missing key verify anything
        }

        return self::equals(hash_hmac($algo, $payload, $key), $given);
    }

    /**
     * Verify a compact HS256 JWT and return its claims, or null.
     *
     * Tamara signs its IPN and webhook calls this way: the merchant's
     * notification token is the HMAC key. Only HS256 is accepted — reading
     * the algorithm out of the header and trusting it is the classic JWT
     * forgery ("alg":"none", or RS256 verified as HMAC against the public
     * key), so the expected algorithm is fixed here and the header's claim
     * about it is checked against that, never used to choose.
     *
     * @return array<string, mixed>|null
     */
    public static function verifyJwtHs256(string $token, string $key, int $leeway = 180): ?array
    {
        if ($key === '') {
            return null;
        }

        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$head64, $body64, $sig64] = $parts;

        $header = json_decode((string) self::b64decode($head64), true);

        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $expected = hash_hmac('sha256', $head64 . '.' . $body64, $key, true);
        $given = self::b64decode($sig64);

        if ($given === null || ! hash_equals($expected, $given)) {
            return null;
        }

        $claims = json_decode((string) self::b64decode($body64), true);

        if (! is_array($claims)) {
            return null;
        }

        $now = time();

        if (isset($claims['exp']) && is_numeric($claims['exp']) && $now > ((int) $claims['exp'] + $leeway)) {
            return null;
        }

        if (isset($claims['nbf']) && is_numeric($claims['nbf']) && $now < ((int) $claims['nbf'] - $leeway)) {
            return null;
        }

        return $claims;
    }

    /** Extract the token from an `Authorization: Bearer x` header. */
    public static function bearer(?string $header): ?string
    {
        if ($header === null || ! preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return null;
        }

        return $m[1];
    }

    private static function b64decode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
