<?php

declare(strict_types=1);

namespace App\Services\Update;

use RuntimeException;

/**
 * Where the private signing key lives, and the rules for reading it.
 *
 * THE RULE THIS CLASS ENFORCES: the private key is a file on the build machine,
 * outside the repository, readable only by the user that builds. It is never
 * committed, never packaged and never present on a shop. Getting that wrong is
 * worse than not signing at all — an unsigned package at least tells the truth
 * about how much it has been checked, whereas a leaked key produces forgeries
 * that every shop accepts as genuine and that nothing downstream can question.
 *
 * So the checks below are refusals, not warnings:
 *
 *   - INSIDE THE REPOSITORY is refused. Every other protection is one `git add
 *     -A` away from being undone, and this project has already shipped a token
 *     into its own history once (public_html/kbb-doctor.php, 2.60.266).
 *   - GROUP- OR WORLD-READABLE is refused. On the shared hosting this product
 *     targets, "other" is other customers.
 *   - A SYMLINK is refused, because what it points at is not what was checked.
 *
 * Nothing here can reach a shop even if it did ship: `.key` is not on
 * UpdateGuard::ALLOWED_EXTENSIONS and the home directory is not on
 * ALLOWED_PREFIXES, so a package carrying a key file is rejected at the door by
 * two independent rules. PackageSigningTest pins both.
 */
final class SigningKeyFile
{
    /** Deliberately outside any checkout. */
    public static function defaultPath(): string
    {
        $home = (string) (getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir()));

        return rtrim($home, '/').'/.config/kbb/package-signing.key';
    }

    /**
     * The path a build will sign with, or null when there is none.
     *
     * Explicit argument, then KBB_UPDATE_SIGNING_KEY, then the default
     * location. A PATH and not the key itself: an environment variable holding
     * a private key leaks into `ps`, into crash dumps, into phpinfo() and into
     * any library that logs its environment.
     */
    public static function resolve(?string $explicit = null): ?string
    {
        foreach ([$explicit, getenv('KBB_UPDATE_SIGNING_KEY') ?: null, self::defaultPath()] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Read the raw 64-byte secret key, refusing anything that is not stored
     * safely.
     *
     * @throws RuntimeException with a message meant for the person building
     */
    public static function read(string $path): string
    {
        if (is_link($path)) {
            throw new RuntimeException("{$path} is a symbolic link. A signing key must be a real file, so that what is checked is what is read.");
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("No signing key at {$path}.");
        }

        $real = realpath($path) ?: $path;

        if (str_starts_with($real, rtrim(base_path(), '/').'/')) {
            throw new RuntimeException(
                "{$real} is inside the application directory. A signing key must live outside the repository — "
                .'one `git add -A` away from being committed is not far enough. Move it to '.self::defaultPath().'.'
            );
        }

        $perms = fileperms($real);

        if ($perms !== false && ($perms & 0o077) !== 0) {
            throw new RuntimeException(sprintf(
                '%s is mode %04o — readable by somebody other than you. Run: chmod 600 %s',
                $real,
                $perms & 0o777,
                $real,
            ));
        }

        $raw = base64_decode(trim((string) file_get_contents($real)), true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException("{$real} does not contain an Ed25519 secret key. Regenerate it with `php artisan kbb:signing-key`.");
        }

        return $raw;
    }
}
