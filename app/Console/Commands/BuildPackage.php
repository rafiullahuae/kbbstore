<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
        {--out=storage/app/packages : Directory to write the zip into}';

    protected $description = 'Build a Core Updates zip from committed git history';

    /** Never shipped to the server: repo-only, build-time, or a different machine's concern. */
    private const NEVER_SHIP = [
        '.github/', '.gitignore', 'tests/', 'docs/', 'tools/', 'phpunit.xml',
        'CLAUDE.md', 'README.md', 'KBB-Master-Plan.md', 'KBB-Progress-Dashboard.html',
        'env.staging.txt', 'package.json', 'package-lock.json', 'composer.lock',
        'public-web-root/', 'vendor/', 'node_modules/', '.env',
        // Repo-only. UpdateGuard rejects it twice over -- no allowed prefix and
        // no extension -- and the server does not read it anyway: the installed
        // version is the newest applied row in update_releases.
        'VERSION',
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
         * list, and `signature` must be present even when empty -- an unsigned
         * package is accepted only while KBB_UPDATE_SECRET is unset. Keys are
         * kept to exactly these six because the HMAC, when signing is turned
         * back on, is computed over this payload with `signature` removed; an
         * extra key here would change the digest and reject every package. */
        $zip->addFromString('update.json', (string) json_encode([
            'name' => 'KBB Storefront',
            'version' => $version,
            'requires_php' => '8.2',
            'notes' => (string) ($this->option('notes') ?? ''),
            'files' => $manifest,
            'signature' => '',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $zip->close();

        $this->verify($zipPath, $manifest);

        $this->newLine();
        $this->info("Built {$zipPath}");
        $this->line('  '.count($manifest).' files, '.number_format(filesize($zipPath) / 1024, 1).' KB');
        foreach ($manifest as $path => $sha) {
            $this->line(sprintf('  %-60s %6d B', $path, $sizes[$path]));
        }

        return self::SUCCESS;
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
}
