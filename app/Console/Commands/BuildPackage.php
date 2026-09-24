<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Update\PackageSignature;
use App\Services\Update\SigningKeyFile;
use App\Services\Update\UpdateGuard;
use Illuminate\Console\Command;
use ZipArchive;

/**
 * Build an update package for Store → Core Updates.
 *
 * Every package before this one was assembled by hand from a working copy of
 * the server. That is how 2.60.102-.106 happened: the copy was stale for three
 * files, the packages carried the old versions forward, and applying them
 * reverted work that was already live and 500'd every product page.
 *
 * So this reads file contents from `git show <ref>:<path>` rather than from
 * disk. A dirty working tree, a half-finished edit or a stale copy cannot reach
 * a package, because the package is built from committed history by
 * construction. The manifest it writes is generated from the same bytes that go
 * into the zip, so the checksums cannot disagree with the payload.
 *
 *   php artisan kbb:package 2.60.108 --since=2.60.107
 *   php artisan kbb:package 2.60.108 --file=app/Models/Product.php
 */
class BuildPackage extends Command
{
    protected $signature = 'kbb:package
        {version : The version this package installs, e.g. 2.60.108}
        {--since= : Include every file changed since this git ref (tag, branch or commit)}
        {--file=* : Include this path explicitly; repeatable}
        {--ref=HEAD : The git ref to take file contents from}
        {--notes= : Release notes stored in update.json}
        {--out=storage/app/packages : Directory to write the zip into}
        {--key= : Ed25519 private key file to sign with (default: $KBB_UPDATE_SIGNING_KEY, then ~/.config/kbb/package-signing.key)}
        {--unsigned : Build without a signature. The escape hatch, not a convenience — see docs/PACKAGE-SIGNING.md §6}';

    protected $description = 'Build a Core Updates zip from committed git history';

    /** Never shipped to the server: repo-only, build-time, or a different machine's concern. */
    private const NEVER_SHIP = [
        '.github/', '.gitignore', 'tests/', 'docs/', 'tools/', 'phpunit.xml', 'phpunit-mysql.xml',
        'CLAUDE.md', 'README.md', 'KBB-Master-Plan.md', 'KBB-Progress-Dashboard.html',
        'env.staging.txt', 'package.json', 'package-lock.json', 'composer.lock',
        'public-web-root/', 'vendor/', 'node_modules/', '.env',
        // WordPress code, not shop code. App\Services\Update\UpdateGuard
        // already refuses it -- `wordpress-plugin/` is not an allowed prefix,
        // so checkPath() answers "Path outside the permitted areas" and the
        // whole package is rejected -- and tests/Feature/GeWpExporterTest.php
        // measures that rather than assuming it. This is the second lock: the
        // guard refusing a zip that should never have been built is a worse
        // outcome than the zip not containing it.
        'wordpress-plugin/',
        // Repo-only. UpdateGuard rejects it twice over -- no allowed prefix and
        // no extension -- and the server does not read it anyway: the installed
        // version is the newest applied row in update_releases.
        'VERSION',
        // Generated, never source: the phone-sized copies App\Support\
        // ImageVariants writes into the web root. They are gitignored, so
        // --since can never select one; this is the second lock, for --file.
        // A package that carried them would be a package that could DELETE
        // them on the next install, and deleting files the product pages
        // depend on is what 2.60.102-.106 did to this shop.
        'public/img-cache/',
        // UpdateGuard forbids bootstrap/ outright, and rightly: a bad
        // bootstrap/app.php stops the application booting at all, which would
        // leave the updater unable to roll itself back. Changes there reach the
        // server by hand, not by package.
        'bootstrap/',
    ];

    public function handle(): int
    {
        $version = (string) $this->argument('version');
        $ref = (string) $this->option('ref');

        if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            $this->error("Version must look like 2.60.108, got: {$version}");

            return self::FAILURE;
        }

        $paths = $this->collectPaths();
        if ($paths === []) {
            $this->error('No files selected. Pass --since=<ref> or --file=<path>.');

            return self::FAILURE;
        }

        [$kept, $skipped] = $this->partition($paths);
        foreach ($skipped as $p) {
            $this->line("  skipped (never shipped)  {$p}");
        }
        if ($kept === []) {
            $this->error('Every selected file is on the never-ship list.');

            return self::FAILURE;
        }

        /* The same guard the server runs, rather than a copy of its rules.
         * A package that fails here would have been rejected at upload with
         * "Path outside the permitted areas", after the operator had already
         * downloaded it -- so fail now, with the whole list at once. */
        $verdict = app(UpdateGuard::class)->check($kept);
        if (! ($verdict['ok'] ?? false)) {
            $this->error('UpdateGuard would reject this package:');
            foreach ((array) ($verdict['errors'] ?? []) as $e) {
                $this->line('  '.$e);
            }

            return self::FAILURE;
        }

        $outDir = base_path((string) $this->option('out'));
        if (! is_dir($outDir) && ! mkdir($outDir, 0775, true) && ! is_dir($outDir)) {
            $this->error("Could not create {$outDir}");

            return self::FAILURE;
        }
        $zipPath = $outDir.'/kbb-update-'.$version.'.zip';
        @unlink($zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            $this->error("Could not create {$zipPath}");

            return self::FAILURE;
        }

        $manifest = [];
        $sizes = [];
        foreach ($kept as $path) {
            $contents = $this->gitShow($ref, $path);
            if ($contents === null) {
                $zip->close();
                @unlink($zipPath);
                $this->error("Not in git at {$ref}: {$path}");

                return self::FAILURE;
            }

            $zip->addFromString('files/'.$path, $contents);
            $manifest[$path] = hash('sha256', $contents);
            $sizes[$path] = strlen($contents);
        }

        /* Shape fixed by UpdatePackage: `files` is a path => sha256 map, not a
         * list, and `signature` must be present even when empty. Every OTHER
         * key is inside the signed payload -- the signature is computed over
         * this manifest with `signature` removed -- so a key added here is a
         * key that is signed and verified, and a key added on the server side
         * only is a key an attacker can set freely. Add to both or to neither. */
        $hasMigrations = false;
        foreach (array_keys($manifest) as $path) {
            if (str_starts_with($path, 'database/migrations/')) {
                $hasMigrations = true;
                break;
            }
        }

        /*
         * A package that changes code must also carry a migration the server
         * has not run yet.
         *
         * 2.60.121 shipped a corrected controller and no new migration. Every
         * migration in it had already run under 2.60.120, so applying it ran
         * none — and every opcache_reset() in this project lives inside a
         * clear_caches_* migration. The fixed file landed on disk and the
         * server kept executing the previous compiled copy, reproducing the
         * exact error the release was meant to end and making a correct fix
         * look like a wrong one.
         *
         * Builds here are cumulative, so "ships a migration" is not the test —
         * every build ships all of them. The test is whether this package
         * carries one NEWER than the newest in the previous package, since
         * that is the only kind the server will actually run.
         */
        $this->warnIfNoFreshMigration($manifest, $outDir, $version);

        /* Assembled first, signed second, written third. The signature covers
         * the other five keys and cannot cover itself, so the manifest has to
         * exist in full before there is anything to sign — and `signature` is
         * added to it afterwards rather than being present-but-empty while the
         * payload is computed, because an empty-string key IN the payload and a
         * missing key are different bytes and only one of them is what the
         * server will canonicalise. */
        $manifestDocument = [
            'name' => 'KBB Storefront',
            'version' => $version,
            'requires_php' => '8.2',
            'notes' => (string) ($this->option('notes') ?? ''),
            'files' => $manifest,
            /*
             * UpdateRunner only runs migrations when this flag is truthy --
             * hasMigrations() reads the manifest, it does not look at the
             * files. No package ever set it, so `php artisan migrate` had
             * never run through the updater: migration files were copied to
             * disk and left there.
             *
             * That is why the orders table was missing is_gift. The migration
             * that adds it shipped in 2.60.85, arrived on the server, and was
             * never executed -- and why two migration-only packages sent to
             * fix it changed nothing at all.
             *
             * It is inside the signed payload, which is not incidental: a flag
             * that decides whether migrations run at all must not be editable
             * in a package without invalidating its signature.
             */
            'migrations' => $hasMigrations,
        ];

        $signature = $this->signature($manifestDocument);

        if ($signature === false) {
            $zip->close();
            @unlink($zipPath);

            return self::FAILURE;
        }

        $manifestDocument['signature'] = $signature;

        $zip->addFromString('update.json', (string) json_encode(
            $manifestDocument,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));

        $zip->close();

        $this->verify($zipPath, $manifest);
        $this->verifySignature($zipPath);

        $this->newLine();
        $this->info("Built {$zipPath}");
        $this->line('  '.count($manifest).' files, '.number_format(filesize($zipPath) / 1024, 1).' KB');
        foreach ($manifest as $path => $sha) {
            $this->line(sprintf('  %-60s %6d B', $path, $sizes[$path]));
        }

        return self::SUCCESS;
    }


    /**
     * Sign the manifest, or return the empty string when this build is
     * deliberately unsigned. False means stop: the operator asked for a signed
     * build and it could not be produced, and a package that quietly ships
     * unsigned in that case is the exact outcome this work exists to end.
     *
     * NOT SIGNING IS LOUD. For the whole life of this project
     * `'signature' => ''` was written unconditionally, with no output saying
     * so, which is why nobody noticed that no package had ever been signed. An
     * unsigned build now says it is unsigned, every time, on its own line.
     *
     * @return string|false
     */
    private function signature(array $manifest)
    {
        if ($this->option('unsigned')) {
            $this->newLine();
            $this->warn('UNSIGNED BUILD. This package carries no signature.');
            $this->line('  A shop set to `required` will refuse it. That is the point of --unsigned: it is');
            $this->line('  the way back in for a shop whose trusted key is wrong or whose key is lost, and it');
            $this->line('  needs storage/app/'.\App\Services\Update\SigningMode::HATCH_FILE.' on that shop first.');

            return '';
        }

        $keyPath = SigningKeyFile::resolve((string) $this->option('key') ?: null);

        if ($keyPath === null) {
            /* Not an error. This is the state every build was in before today,
             * and a lane with no key must still be able to produce a package --
             * `permissive` on the server accepts it. But it says so. */
            $this->newLine();
            $this->warn('No signing key found, so this package is UNSIGNED.');
            $this->line('  Looked at: --key, $KBB_UPDATE_SIGNING_KEY, '.SigningKeyFile::defaultPath());
            $this->line('  Make one with `php artisan kbb:signing-key` — on the build machine only.');

            return '';
        }

        try {
            $secret = SigningKeyFile::read($keyPath);
            $signature = PackageSignature::sign($manifest, $secret);
            $public = sodium_crypto_sign_publickey_from_secretkey($secret);
            sodium_memzero($secret);
        } catch (\Throwable $e) {
            $this->error('Could not sign this package: '.$e->getMessage());

            return false;
        }

        $this->line('  signed with '.$keyPath);
        $this->line('  public key  '.base64_encode($public));
        $this->line('  fingerprint '.PackageSignature::fingerprint($public)
            .'  — this must be one of the keys the shop lists on Store → Core Updates');

        return $signature;
    }

    /**
     * Read update.json back out of the finished zip and verify its signature
     * the way the SERVER will, from the decoded JSON rather than from the array
     * in memory.
     *
     * THIS IS THE MOST VALUABLE CHECK IN THE FILE and it is three lines of
     * work. The failure mode of a signing scheme is almost never cryptographic;
     * it is the builder and the verifier canonicalising differently, and the
     * symptom is a fleet of shops refusing every package — discovered on the
     * shop, during an incident, by the one person who cannot get a fix in. A
     * round trip through json_encode/json_decode here catches that on the build
     * machine, before the zip exists for anyone to apply.
     *
     * It verifies with the public key DERIVED FROM THE SIGNING KEY, not with
     * config: this asks "is this signature valid over these bytes", which is
     * the builder's question. Whether the shop trusts that key is the shop's
     * question, and the fingerprint printed above is how the two are compared.
     */
    private function verifySignature(string $zipPath): void
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            return;
        }

        $manifest = json_decode((string) $zip->getFromName('update.json'), true);
        $zip->close();

        if (! is_array($manifest)) {
            throw new \RuntimeException('update.json is not readable back out of the package just built.');
        }

        $signature = (string) ($manifest['signature'] ?? '');

        if ($signature === '') {
            $this->line('  unsigned — nothing to verify');

            return;
        }

        $keyPath = SigningKeyFile::resolve((string) $this->option('key') ?: null);
        $public = sodium_crypto_sign_publickey_from_secretkey(SigningKeyFile::read((string) $keyPath));

        if (! PackageSignature::verify($manifest, $signature, [$public])) {
            throw new \RuntimeException(
                'The signature this build just wrote does not verify against the manifest as the server will read '
                .'it. Do not ship this package: every shop would refuse it. The two sides are canonicalising '
                .'differently — PackageSignature::canonical() is the only place that decides, and both sides must '
                .'call it.'
            );
        }

        $this->line('  signature verified against the manifest as the server reads it');
    }

    /** @return list<string> */
    private function collectPaths(): array
    {
        $paths = array_values(array_filter((array) $this->option('file')));

        if ($since = $this->option('since')) {
            $out = shell_exec('git diff --name-only --diff-filter=ACMR '
                .escapeshellarg((string) $since).'..'.escapeshellarg((string) $this->option('ref')).' 2>/dev/null');
            foreach (preg_split('/\R/', (string) $out) ?: [] as $line) {
                if (trim($line) !== '') {
                    $paths[] = trim($line);
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function partition(array $paths): array
    {
        $kept = $skipped = [];
        foreach ($paths as $p) {
            $block = false;
            foreach (self::NEVER_SHIP as $prefix) {
                if ($p === $prefix || str_starts_with($p, $prefix)) {
                    $block = true;
                    break;
                }
            }
            $block ? $skipped[] = $p : $kept[] = $p;
        }

        return [$kept, $skipped];
    }

    private function gitShow(string $ref, string $path): ?string
    {
        $cmd = 'git show '.escapeshellarg($ref.':'.$path).' 2>/dev/null';
        $out = shell_exec($cmd);

        return ($out === null || $out === '') ? null : $out;
    }

    /** Read the zip back off disk and re-hash it, so a corrupt write cannot ship. */
    private function verify(string $zipPath, array $manifest): void
    {
        $zip = new ZipArchive();
        $zip->open($zipPath);
        foreach ($manifest as $path => $sha) {
            $actual = hash('sha256', (string) $zip->getFromName('files/'.$path));
            if ($actual !== $sha) {
                $zip->close();
                throw new \RuntimeException("Checksum mismatch after write: {$path}");
            }
        }
        $zip->close();
        $this->line('  verified '.count($manifest).' files against the manifest');
    }

    /**
     * Compare this package's migrations with the previous package's and warn
     * when nothing new would run. Advisory, not fatal: a genuinely
     * assets-only or docs-only release is legitimate.
     */
    private function warnIfNoFreshMigration(array $manifest, string $out, string $version): void
    {
        $changesCode = false;
        foreach (array_keys($manifest) as $path) {
            if (str_starts_with($path, 'app/') || str_starts_with($path, 'routes/')
                || str_starts_with($path, 'resources/views/')) {
                $changesCode = true;
                break;
            }
        }

        if (! $changesCode) {
            return;
        }

        $mine = [];
        foreach (array_keys($manifest) as $path) {
            if (str_starts_with($path, 'database/migrations/')) {
                $mine[] = basename($path);
            }
        }

        $previous = collect(glob(rtrim($out, '/').'/kbb-update-*.zip') ?: [])
            ->reject(fn (string $f): bool => str_contains($f, $version))
            ->sort()
            ->last();

        if ($previous === null) {
            return;
        }

        $zip = new ZipArchive();

        if ($zip->open($previous) !== true) {
            return;
        }

        $raw = $zip->getFromName('update.json');
        $zip->close();

        $theirs = [];
        foreach (array_keys((array) (json_decode((string) $raw, true)['files'] ?? [])) as $path) {
            if (str_starts_with((string) $path, 'database/migrations/')) {
                $theirs[] = basename((string) $path);
            }
        }

        if (array_diff($mine, $theirs) !== []) {
            return;
        }

        $this->warn('This package changes code but carries no migration newer than '
            .basename($previous).'. Applying it will run no migration, so no '
            .'opcache_reset() fires and the server may keep executing the '
            .'previous compiled copy. Add a clear_caches_* migration.');
    }
}
