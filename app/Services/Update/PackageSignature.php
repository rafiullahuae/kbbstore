<?php

declare(strict_types=1);

namespace App\Services\Update;

use RuntimeException;

/**
 * Ed25519 signatures for update packages — the one place the bytes that get
 * signed are decided.
 *
 * WHY THIS EXISTS. `BuildPackage.php` wrote `'signature' => ''` unconditionally
 * for the whole life of this project, so no package has ever been signed, and
 * the scheme waiting behind that empty string was a SYMMETRIC HMAC against
 * `KBB_UPDATE_SECRET`. Symmetric is the wrong shape the moment a second install
 * exists: to verify a package a customer's server needs the same secret that
 * signs one, in a `.env` file they can read, and with it they can forge a
 * package this updater accepts as genuine. PHP 8.4 carries Ed25519 in core
 * (`sodium_crypto_sign_*`), so a shared host needs nothing installed — the
 * private half never leaves the build machine and the public half can be
 * printed on a billboard.
 *
 * WHAT THE SIGNATURE COVERS, and why that is enough.
 *
 * It covers the manifest — every key of update.json except `signature` itself —
 * and NOT the file bytes directly. That is sound only because of a chain, and
 * the chain is worth spelling out because breaking any link turns this into
 * theatre:
 *
 *   signature  ->  binds the manifest, including the `files` map
 *   `files`    ->  binds each payload file by SHA-256
 *   UpdatePackage::checkChecksums()
 *              ->  binds the bytes on disk to that map, IN BOTH DIRECTIONS
 *
 * The both-directions part is the link people drop. checkChecksums() refuses a
 * file the manifest declares and the package does not contain, AND refuses a
 * file the package contains and the manifest does not declare. Without the
 * second half an attacker takes a genuinely signed package, adds one PHP file
 * the manifest never mentions, and the signature still verifies — because
 * nothing he touched is in the signed payload. `PackageSigningTest` pins both
 * halves for exactly that reason.
 *
 * The other things that would break the chain, all pinned by tests:
 *   - signing a payload with some manifest key excluded (then that key is
 *     attacker-controlled; `migrations` decides whether migrations run at all);
 *   - the builder and the server canonicalising differently — which is why both
 *     call canonical() below and neither has a copy of it;
 *   - verify() dropping checkChecksums() from its chain.
 *
 * WHAT IT DOES NOT COVER, deliberately: the zip container, entry order, and
 * timestamps. None of them reach the server — UpdatePackage reads files out of
 * the extracted tree and matches them against the manifest, so a re-zipped
 * package with identical contents is identical as far as this updater is
 * concerned.
 *
 * WHAT IT DOES NOT PROVE, and this needs saying plainly after 24 September 2026:
 * a signature proves ORIGIN, never CORRECTNESS. Every one of the five packages
 * that shipped eight migrations without declaring them would have been signed,
 * verified and applied. `checkMigrationsAreDeclared()` and `ClassDependencyScan`
 * are what catch that class of fault. This catches a different one: a package
 * that did not come from the build machine cannot be applied at all, which is
 * the difference between a mistake and an attack.
 */
final class PackageSignature
{
    /**
     * Every Ed25519 signature carries this prefix.
     *
     * It is what lets one `signature` field hold two schemes without ambiguity
     * during the retirement of the HMAC: a legacy signature is 64 bare hex
     * characters, this one cannot be mistaken for it, and a signature in
     * neither form is refused rather than ignored.
     */
    public const PREFIX = 'ed25519:';

    public static function looksEd25519(string $signature): bool
    {
        return str_starts_with($signature, self::PREFIX);
    }

    /**
     * The exact bytes that are signed and verified.
     *
     * Shared by the builder and the server on purpose. A signing scheme fails
     * far more often from the two sides canonicalising differently than from
     * anything cryptographic, and the failure mode is a fleet that refuses
     * every package — so there is one function and no second copy of it.
     *
     * Sorted recursively, so the order keys happen to appear in update.json can
     * never decide whether a package installs; `signature` removed, because it
     * cannot cover itself; JSON_UNESCAPED_SLASHES to match what the HMAC used,
     * so a reviewer comparing the two sees one change and not two.
     */
    public static function canonical(array $manifest): string
    {
        unset($manifest['signature']);

        self::sortDeep($manifest);

        $json = json_encode($manifest, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            // Fail closed. An unencodable manifest (invalid UTF-8 in the notes,
            // most likely) must refuse to sign and refuse to verify, never
            // silently canonicalise to the empty string — which would make
            // every such manifest have the same signature as every other.
            throw new RuntimeException('This manifest cannot be canonicalised: '.json_last_error_msg());
        }

        return $json;
    }

    private static function sortDeep(array &$value): void
    {
        ksort($value);

        foreach ($value as &$child) {
            if (is_array($child)) {
                self::sortDeep($child);
            }
        }
    }

    /** @param string $secretKey raw 64-byte Ed25519 secret key */
    public static function sign(array $manifest, string $secretKey): string
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException(sprintf(
                'An Ed25519 secret key is %d bytes; this one is %d. The key file is wrong or truncated.',
                SODIUM_CRYPTO_SIGN_SECRETKEYBYTES,
                strlen($secretKey),
            ));
        }

        return self::PREFIX.base64_encode(
            sodium_crypto_sign_detached(self::canonical($manifest), $secretKey)
        );
    }

    /**
     * True only when $signature is a valid Ed25519 signature over $manifest by
     * one of $publicKeys.
     *
     * A list rather than one key so a key can be rotated without a flag day:
     * the shop trusts the old and the new at once for one release, then the old
     * is dropped. An EMPTY list trusts nothing and this returns false — a shop
     * that holds no public key cannot verify anything, and saying "fine" there
     * would be the whole scheme quietly switching itself off.
     *
     * @param  list<string>  $publicKeys  raw 32-byte Ed25519 public keys
     */
    public static function verify(array $manifest, string $signature, array $publicKeys): bool
    {
        if (! self::looksEd25519($signature) || $publicKeys === []) {
            return false;
        }

        $raw = base64_decode(substr($signature, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            $payload = self::canonical($manifest);
        } catch (RuntimeException) {
            return false;
        }

        foreach ($publicKeys as $key) {
            if (strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }

            try {
                if (sodium_crypto_sign_verify_detached($raw, $payload, $key)) {
                    return true;
                }
            } catch (\SodiumException) {
                continue;
            }
        }

        return false;
    }

    /**
     * Decode a base64 public key, or null if it is not one.
     *
     * Null rather than an exception because these come from configuration: a
     * typo in one shipped key must not throw out of the update screen, it must
     * make that key untrusted and leave the others working.
     */
    public static function decodePublicKey(string $base64): ?string
    {
        $raw = base64_decode(trim($base64), true);

        return ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) ? $raw : null;
    }

    /** A short, human-comparable name for a key, so the shop and the builder can be checked against each other by eye. */
    public static function fingerprint(string $rawPublicKey): string
    {
        return strtoupper(substr(hash('sha256', $rawPublicKey), 0, 16));
    }
}
