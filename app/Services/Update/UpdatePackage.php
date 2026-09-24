<?php

declare(strict_types=1);

namespace App\Services\Update;

use ZipArchive;

/**
 * Reads and verifies an update package before a single file is touched.
 *
 * Everything here is read-only. The package is extracted to a scratch directory,
 * every claim in its manifest is checked against reality, and only a package that
 * passes every test is handed to the runner. A package that fails is deleted and
 * the site is exactly as it was.
 *
 * Package layout:
 *
 *   update.json     manifest
 *   files/          payload, mirroring the application root
 */
final class UpdatePackage
{
    public array $manifest = [];

    public array $files = [];        // relative path => absolute path in scratch

    public array $errors = [];

    public function __construct(
        private string $zipPath,
        private string $scratchDir,
        private UpdateGuard $guard,
    ) {}

    /** The uploaded package's own path, needed to archive it after a
     *  successful apply — everything else here is about its contents. */
    public function zipPath(): string
    {
        return $this->zipPath;
    }

    public function verify(): bool
    {
        return $this->extract()
            && $this->readManifest()
            && $this->checkCompatibility()
            && $this->checkSignature()
            && $this->collectFiles()
            && $this->checkPaths()
            && $this->checkChecksums()
            && $this->checkMigrationsAreDeclared();
    }

    /**
     * A package carrying migrations MUST say so, or it is refused at the door.
     *
     * THE FAILURE THIS EXISTS FOR, 24 September 2026. UpdateRunner runs
     * migrations only when `update.json` says `"migrations": true` --
     * hasMigrations() reads that key and never looks at the files. Five
     * packages were built by a hand-written script instead of
     * `php artisan kbb:package`, and it omitted the key. Every migration in
     * them was copied to the server and NONE ran, while each package reported
     * "applied".
     *
     * 2.60.260 was one of them. It installed an UpdateRunner that writes
     * `update_releases.manifest` and, because its own migration never ran, did
     * not add the column. The next apply threw on that column, the swallowed
     * throw left the attribute on the model, every later write re-sent it
     * including the two inside rollback(), and the shop's updater stopped
     * applying anything at all -- with the fix for a live storefront outage
     * sitting in a zip that could not be installed.
     *
     * The same shape had already happened once: `orders.is_gift` shipped in
     * 2.60.85, arrived, never ran, and two migration-only packages sent to
     * repair it changed nothing. PackageMigrationFlagTest was written then and
     * says so in its header. A test in the repository could not stop a builder
     * outside it, so the check belongs HERE -- on the server, at the moment a
     * package asks to be applied, whoever built it and however.
     *
     * REFUSED RATHER THAN CORRECTED, deliberately. Inferring the flag from the
     * file list would make a mis-built package apply silently and quietly
     * forgive the builder; the owner would never learn that the thing producing
     * his packages is wrong. A refusal names the fault and costs one rebuild.
     */
    private function checkMigrationsAreDeclared(): bool
    {
        $carried = array_values(array_filter(
            array_keys($this->files),
            static fn (string $path): bool => str_starts_with($path, 'database/migrations/'),
        ));

        if ($carried === [] || $this->hasMigrations()) {
            return true;
        }

        $this->errors[] = sprintf(
            'This package contains %d migration%s but update.json does not declare "migrations": true, '
            .'so they would be copied to the server and never run. Rebuild it with '
            .'`php artisan kbb:package`. First one: %s',
            count($carried),
            count($carried) === 1 ? '' : 's',
            $carried[0],
        );

        return false;
    }

    private function extract(): bool
    {
        $zip = new ZipArchive();

        if ($zip->open($this->zipPath) !== true) {
            $this->errors[] = 'That file could not be opened as a zip archive.';

            return false;
        }

        // Inspect entries before extracting. A zip can name entries "../../.env"
        // and a naive extractTo() would happily write there.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false) {
                continue;
            }

            if (preg_match('#(^/|(^|/)\.\.(/|$))#', $name)) {
                $this->errors[] = "Unsafe entry in archive: {$name}";
                $zip->close();

                return false;
            }
        }

        if (! is_dir($this->scratchDir) && ! mkdir($this->scratchDir, 0755, true)) {
            $this->errors[] = 'Could not create a working directory. Check permissions on storage/app.';
            $zip->close();

            return false;
        }

        if (! $zip->extractTo($this->scratchDir)) {
            $this->errors[] = 'The archive could not be extracted — it may be truncated.';
            $zip->close();

            return false;
        }

        $zip->close();

        return true;
    }

    private function readManifest(): bool
    {
        $path = $this->scratchDir . '/update.json';

        if (! is_file($path)) {
            $this->errors[] = 'No update.json found. This does not look like a KBB update package.';

            return false;
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        if (! is_array($manifest)) {
            $this->errors[] = 'update.json is not valid JSON.';

            return false;
        }

        foreach (['name', 'version', 'files'] as $key) {
            if (! isset($manifest[$key])) {
                $this->errors[] = "update.json is missing \"{$key}\".";

                return false;
            }
        }

        $this->manifest = $manifest;

        return true;
    }

    private function checkCompatibility(): bool
    {
        $requiresPhp = $this->manifest['requires_php'] ?? null;

        if ($requiresPhp && version_compare(PHP_VERSION, (string) $requiresPhp, '<')) {
            $this->errors[] = sprintf('Needs PHP %s or newer; this server runs %s.', $requiresPhp, PHP_VERSION);

            return false;
        }

        $requiresVersion = $this->manifest['requires_version'] ?? null;
        // The installed version, read from update_releases rather than from
        // env('KBB_VERSION') — which is unset here, so this compared against
        // '1.0.0' and would have refused any package declaring a prerequisite
        // the server already met.
        $current = InstalledVersion::get();

        if ($requiresVersion && version_compare($current, (string) $requiresVersion, '<')) {
            $this->errors[] = sprintf(
                'This update needs version %s installed first; you are on %s. Apply the earlier update before this one.',
                $requiresVersion,
                $current
            );

            return false;
        }

        return true;
    }

    /**
     * Optional HMAC signature.
     *
     * When KBB_UPDATE_SECRET is set, only packages signed with that secret are
     * accepted. This matters more than it might seem: it means that even if
     * someone gets hold of an admin password, they still cannot push arbitrary
     * PHP onto the server through this screen.
     */
    private function checkSignature(): bool
    {
        $secret = (string) config('kbb.update_secret', '');

        if ($secret === '') {
            return true;   // unsigned mode; the UI warns about this
        }

        $signature = (string) ($this->manifest['signature'] ?? '');

        if ($signature === '') {
            $this->errors[] = 'This package is unsigned, but this site only accepts signed updates.';

            return false;
        }

        $payload = $this->manifest;
        unset($payload['signature']);
        ksort($payload);

        $expected = hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES), $secret);

        if (! hash_equals($expected, $signature)) {
            $this->errors[] = 'Signature does not match. This package was not produced for this site.';

            return false;
        }

        return true;
    }

    private function collectFiles(): bool
    {
        $root = $this->scratchDir . '/files';

        if (! is_dir($root)) {
            $this->errors[] = 'The package has no files/ directory.';

            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            // A symlink inside a package could point anywhere on the server.
            if ($file->isLink()) {
                $this->errors[] = 'Symbolic links are not permitted in update packages.';

                return false;
            }

            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
            $this->files[$relative] = $file->getPathname();
        }

        return true;
    }

    private function checkPaths(): bool
    {
        $result = $this->guard->check(array_keys($this->files));

        if (! $result['ok']) {
            $this->errors = array_merge($this->errors, $result['errors']);

            return false;
        }

        foreach ($this->files as $relative => $absolute) {
            $error = $this->guard->checkSize($relative, (int) filesize($absolute));
            if ($error !== null) {
                $this->errors[] = $error;
            }
        }

        return $this->errors === [];
    }

    /**
     * Every file must match the checksum declared in the manifest, and the
     * manifest must not list files the package does not contain. This catches a
     * truncated upload, which is the most common real-world failure — a half
     * transferred zip that would otherwise install half an update.
     */
    private function checkChecksums(): bool
    {
        $declared = (array) $this->manifest['files'];

        foreach ($declared as $relative => $expected) {
            if (! isset($this->files[$relative])) {
                $this->errors[] = "Manifest lists {$relative} but it is missing from the package.";

                continue;
            }

            $actual = hash_file('sha256', $this->files[$relative]);

            if (! hash_equals((string) $expected, (string) $actual)) {
                $this->errors[] = "Checksum mismatch on {$relative} — the upload is damaged. Try again.";
            }
        }

        foreach (array_keys($this->files) as $relative) {
            if (! isset($declared[$relative])) {
                $this->errors[] = "Package contains {$relative}, which the manifest does not declare.";
            }
        }

        return $this->errors === [];
    }

    public function version(): string
    {
        return (string) ($this->manifest['version'] ?? '0.0.0');
    }

    public function hasMigrations(): bool
    {
        return (bool) ($this->manifest['migrations'] ?? false);
    }

    public function notes(): string
    {
        return (string) ($this->manifest['notes'] ?? '');
    }
}
