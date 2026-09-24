<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Update\PackageSignature;
use App\Services\Update\SigningKeyFile;
use Illuminate\Console\Command;

/**
 * Make the Ed25519 key pair that signs update packages.
 *
 *   php artisan kbb:signing-key
 *
 * RUN THIS ON THE BUILD MACHINE AND NOWHERE ELSE. The private half it writes is
 * the only thing standing between a customer's shop and an attacker who can
 * push arbitrary PHP through the update screen. It never goes in this
 * repository, never goes in a package, and never goes in a shop's .env — the
 * whole reason for moving off the HMAC is that a symmetric scheme puts the
 * signing key on every server that verifies. docs/PACKAGE-SIGNING.md §3 is the
 * long version.
 *
 * The default location is outside the repository on purpose: ~/.config/kbb/
 * package-signing.key, written 0600. SigningKeyFile refuses to read a key that
 * lives inside the working tree, so the easy mistake — dropping it next to the
 * code "just to get a build out" — fails loudly rather than quietly committing
 * it later.
 */
class MakeSigningKey extends Command
{
    protected $signature = 'kbb:signing-key
        {--out= : Where to write the private key (default ~/.config/kbb/package-signing.key)}
        {--force : Overwrite an existing key file}';

    protected $description = 'Generate the Ed25519 key pair that signs update packages';

    public function handle(): int
    {
        $path = (string) ($this->option('out') ?: SigningKeyFile::defaultPath());

        /* Refused rather than overwritten. A key file that is silently replaced
         * is every package signed before it becoming unverifiable, on every
         * shop that trusts only the old public key — and the person who did it
         * finds out when a shop refuses an update, not now. */
        if (is_file($path) && ! $this->option('force')) {
            $this->error("A key already exists at {$path}.");
            $this->line('  Back it up before doing anything else. Replacing it means every shop that trusts');
            $this->line('  the old public key must be given the new one before it can take another package.');
            $this->line('  Pass --force only when that is what you mean.');

            return self::FAILURE;
        }

        if (str_starts_with(realpath(dirname($path)) ?: dirname($path), base_path())) {
            $this->error('That path is inside the application. A signing key must never live in the repository.');
            $this->line('  Suggested: '.SigningKeyFile::defaultPath());

            return self::FAILURE;
        }

        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error("Could not create {$dir}");

            return self::FAILURE;
        }

        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);

        /* 0600 set BEFORE the bytes are written. touch-then-chmod leaves a
         * window in which the key is on disk at the umask's permissions, and on
         * shared hosting that window is the whole exposure. */
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            $this->error("Could not write {$path}");

            return self::FAILURE;
        }

        chmod($path, 0600);
        fwrite($handle, base64_encode($secret)."\n");
        fclose($handle);

        sodium_memzero($secret);

        $encoded = base64_encode($public);

        $this->newLine();
        $this->info('Private key written to '.$path.' (0600). It must never leave this machine.');
        $this->line('  Back it up offline now. Losing it means no shop that requires signatures can be');
        $this->line('  updated again until it is given a new public key by hand.');
        $this->newLine();
        $this->info('PUBLIC key — paste this into config/kbb.php, in update_public_keys, and commit it:');
        $this->newLine();
        $this->line("            '".$encoded."',");
        $this->newLine();
        $this->line('  fingerprint '.PackageSignature::fingerprint($public));
        $this->line('  The Core Updates screen prints the same fingerprint, so the shop and this machine');
        $this->line('  can be checked against each other by eye.');

        return self::SUCCESS;
    }
}
