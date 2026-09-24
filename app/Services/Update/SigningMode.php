<?php

declare(strict_types=1);

namespace App\Services\Update;

/**
 * Which signature policy this shop is running, and the one way out of it.
 *
 * TWO MODES AND NO THIRD.
 *
 *   permissive  a signature that is PRESENT is verified, and a bad one is
 *               refused; a package with no signature is accepted.
 *   required    a package with no signature is refused as well.
 *
 * There is deliberately no "off". Verifying a signature that is there costs a
 * millisecond and can never be the reason a shop is stuck, because a shop that
 * is stuck gets out by applying an UNSIGNED package, which permissive accepts.
 * An "off" mode would only ever exist to ignore a signature that failed, and
 * ignoring a signature that failed is the one thing this code must not do.
 *
 * PERMISSIVE IS THE SHIPPED DEFAULT, and that is what makes this patch inert.
 * Every package this project has ever built carries `"signature": ""`, so every
 * one of them is accepted before and after — nothing about which packages
 * install changes on the day this lands. That is not timidity, it is the only
 * safe rollout: the package that teaches a shop to REQUIRE signatures is itself
 * applied by the verifier that came before it, and a shop must never learn to
 * require something it has not yet been shown it can check. `docs/PACKAGE-
 * SIGNING.md` has the sequence in numbered steps.
 *
 * THE ESCAPE HATCH IS A FILE, and the reasons are all scars:
 *
 *   storage/app/kbb-accept-unsigned
 *
 * Present, whatever it contains, and this shop drops back to permissive. It is
 * a file and not a setting because of three separate traps this project has
 * already paid for:
 *
 *   - `.env` IS NOT READ WHEN THE CONFIG CACHE EXISTS. Laravel's
 *     LoadEnvironmentVariables returns early on configurationIsCached(), the
 *     cache always exists on this host, and every package ships a clear_caches
 *     migration that rebuilds it. That is exactly how KBB_NOINDEX read `false`
 *     on the live server for its entire life. An escape hatch that needs a
 *     cache clear is an escape hatch that needs the thing that is broken.
 *   - A SETTING IN THE DATABASE is reachable only through the admin panel,
 *     which is reachable only if the app boots, which is what the escape hatch
 *     exists for when it does not.
 *   - NO PACKAGE CAN CREATE OR DELETE IT. `storage/` is on
 *     UpdateGuard::FORBIDDEN_PREFIXES, so an update cannot damage its own way
 *     out — the same reason public/kbb-recover.php is forbidden there. Someone
 *     with SFTP, cPanel's file manager or SSH can make it in ten seconds and
 *     needs no shell, no migration and no cache clear.
 *
 * It is read with is_file() on each verification rather than through config(),
 * for the first of those reasons: anything that travels through the config
 * cache is unavailable precisely when it is wanted.
 */
final class SigningMode
{
    public const REQUIRED = 'required';

    public const PERMISSIVE = 'permissive';

    /** Relative to storage/app/, so it is inside what UpdateGuard forbids a package to touch. */
    public const HATCH_FILE = 'kbb-accept-unsigned';

    public static function hatchPath(): string
    {
        return storage_path('app/'.self::HATCH_FILE);
    }

    public static function hatchActive(): bool
    {
        return is_file(self::hatchPath());
    }

    /**
     * The policy in force.
     *
     * Anything unrecognised in configuration resolves to permissive, never to
     * required. A typo must not be able to lock a shop out of its own updater;
     * failing open here is safe because the signature of a package that HAS one
     * is still checked in both modes.
     */
    public static function current(): string
    {
        if (self::hatchActive()) {
            return self::PERMISSIVE;
        }

        return ((string) config('kbb.update_signing', self::PERMISSIVE)) === self::REQUIRED
            ? self::REQUIRED
            : self::PERMISSIVE;
    }

    /**
     * The trusted Ed25519 public keys, raw 32-byte strings.
     *
     * Public halves only. The private key that matches one of these exists on
     * the build machine and nowhere else — not in this repository, not in a
     * package, and not in any shop's .env. See docs/PACKAGE-SIGNING.md §3.
     *
     * @return list<string>
     */
    public static function trustedKeys(): array
    {
        $keys = [];

        foreach ((array) config('kbb.update_public_keys', []) as $encoded) {
            $raw = PackageSignature::decodePublicKey((string) $encoded);

            if ($raw !== null) {
                $keys[] = $raw;
            }
        }

        return array_values(array_unique($keys));
    }

    /** The legacy symmetric secret, being retired. Empty on every install that never set it. */
    public static function legacySecret(): string
    {
        return (string) config('kbb.update_secret', '');
    }

    /**
     * What the Core Updates screen prints, so the owner can read the shop's
     * actual policy off the page instead of inferring it from a .env file he
     * may not be able to open.
     *
     * @return array{mode: string, label: string, emergency: bool, keys: int, legacy: bool, fingerprints: list<string>}
     */
    public static function describe(): array
    {
        $emergency = self::hatchActive();
        $mode = self::current();
        $keys = count(self::trustedKeys());
        $legacy = self::legacySecret() !== '';

        $label = match (true) {
            $emergency => 'EMERGENCY: unsigned packages accepted — delete storage/app/'.self::HATCH_FILE,
            $mode === self::REQUIRED => 'signed packages only',
            $legacy => 'signed packages only (legacy shared secret)',
            $keys > 0 => 'signatures verified · unsigned packages still accepted',
            default => 'unsigned packages accepted',
        };

        return [
            'mode' => $mode,
            'label' => $label,
            'emergency' => $emergency,
            'keys' => $keys,
            'legacy' => $legacy,
            /* Printed so the shop and the build machine can be compared by eye.
             * `kbb:package` prints the same fingerprint for the key it signed
             * with, and "they do not match" is the single most likely reason a
             * signed package is refused. A public key's fingerprint is not a
             * secret -- the key itself is meant to be published. */
            'fingerprints' => array_map(
                static fn (string $raw): string => PackageSignature::fingerprint($raw),
                self::trustedKeys(),
            ),
        ];
    }
}
