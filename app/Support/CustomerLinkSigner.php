<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Signs the email-verification link, deliberately NOT with Laravel's signed URLs.
 *
 * WHY NOT `URL::signedRoute()` / `hasValidSignature()`
 * ----------------------------------------------------
 * Two reasons, and both are load-bearing on this install.
 *
 * 1. The open advisory. CLAUDE.md records signed-URL path confusion as one of
 *    the three Laravel 11 advisories this project carries and cannot fix
 *    without a 12.x upgrade. Laravel's signature is computed over a rendered
 *    absolute URL and re-derived from the incoming request, and the two do not
 *    always agree about what the path is. An email-verification link is exactly
 *    the artefact that advisory is about, so this lane does not use the
 *    vulnerable primitive at all rather than shipping a link and hoping.
 *
 * 2. KBB_BASE_PATH. Every route on this install is prefixed with
 *    `/kbb-upgrade` on the server and with nothing in CI, and APP_URL already
 *    ends in that prefix (see env.staging.txt and Support\Url::redirect(), which
 *    exists because the prefix got applied twice). A signature computed over the
 *    URL therefore changes when the prefix changes, or when a proxy rewrites it,
 *    or when APP_URL is edited. That is not a theoretical failure: it is a link
 *    in an email that was valid when it was sent and rejected when it was
 *    clicked, for every customer at once, with nothing in the logs to say why.
 *
 * WHAT IS SIGNED INSTEAD
 * ----------------------
 * A canonical claim string that contains no scheme, no host, no base path and
 * no URL at all — just the purpose, the claims, and the expiry. The signature
 * is therefore IDENTICAL whether the site is served from a domain root or from
 * /kbb-upgrade, which is precisely the property a link that travels through an
 * inbox needs. CustomerAuthSignatureTest pins that equality.
 *
 * The claims still cover everything that must not be swapped: the customer id,
 * a digest of the address being verified (so a link cannot be replayed after
 * the address is changed) and the expiry. Change any one and the MAC changes.
 */
final class CustomerLinkSigner
{
    /** How the digest is compared. Never `===` on a MAC. */
    public static function verify(string $purpose, array $claims, int $expiresAt, string $signature): bool
    {
        // A malformed or absent signature must cost the same as a wrong one.
        if ($signature === '' || ! ctype_xdigit($signature)) {
            $signature = str_repeat('0', 64);
        }

        if ($expiresAt <= 0) {
            return false;
        }

        $valid = hash_equals(self::sign($purpose, $claims, $expiresAt), $signature);

        // Expiry is checked AFTER the MAC, and both results are folded into one
        // boolean, so a caller cannot tell a forged link from a stale one.
        return $valid && $expiresAt >= time();
    }

    public static function sign(string $purpose, array $claims, int $expiresAt): string
    {
        return hash_hmac('sha256', self::canonical($purpose, $claims, $expiresAt), self::key());
    }

    /**
     * The signed string.
     *
     * Every component is length-prefixed so that no pair of different claim
     * lists can flatten to the same bytes — without it, ['a','bc'] and
     * ['ab','c'] would sign identically and a customer id could be traded
     * against an address digest.
     */
    private static function canonical(string $purpose, array $claims, int $expiresAt): string
    {
        $parts = [$purpose];

        foreach ($claims as $key => $value) {
            $parts[] = (string) $key;
            $parts[] = is_scalar($value) || $value === null ? (string) $value : '';
        }

        $parts[] = (string) $expiresAt;

        return implode('|', array_map(
            static fn (string $p): string => strlen($p) . ':' . $p,
            $parts,
        ));
    }

    /**
     * The application key, decoded.
     *
     * APP_KEY is stored base64-encoded; signing over the encoded text would
     * still be a secret, but decoding keeps this consistent with everything
     * else in the framework that derives from it. A missing key is fatal here
     * rather than silently producing a MAC over an empty secret — a
     * verification link anybody can forge is worse than a verification link
     * that does not exist.
     */
    private static function key(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        if ($key === '') {
            // No key material, no token. Deliberately not logged with any
            // customer detail attached.
            Log::error('CustomerLinkSigner: APP_KEY is not set; verification links cannot be signed.');

            throw new \RuntimeException('APP_KEY is not set.');
        }

        return $key;
    }
}
