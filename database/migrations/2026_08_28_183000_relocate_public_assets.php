<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Move packaged public assets into the real public directory, then clear the
 * compiled view cache.
 *
 * Not a schema change. It rides in as a migration because migrations run AFTER
 * the updater copies files, which is the only point where this can be fixed
 * inside a single package.
 *
 * The problem it solves: the updater writes every packaged file under the app
 * root, so public/build lands in kbb-upgrade-app/public/build — a directory
 * Laravel never reads, because the public path is public_html/kbb-upgrade.
 * The corrected updater ships in this same package, but the OLD copy is what
 * performs this apply, so the assets need relocating once, here.
 *
 * It is safe to run when there is nothing to move: it simply does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $moved = $this->relocateAssets();
        $cleared = $this->clearCompiled();

        if (app()->runningInConsole()) {
            echo "Relocated {$moved} asset files; cleared {$cleared} compiled files.\n";
        }
    }

    /** @return int files moved */
    private function relocateAssets(): int
    {
        $stray = base_path('public/build');
        $real = rtrim(public_path(), '/') . '/build';

        // Nothing misplaced, or the two paths are the same directory anyway.
        if (! is_dir($stray) || realpath($stray) === realpath($real)) {
            return 0;
        }

        /*
         * NOT IN A SOURCE CHECKOUT. This is a guard, not an optimisation.
         *
         * base_path('public/build') is a stray copy only on the host, where the
         * updater unpacked the package under the app root and the real web root
         * is somewhere else entirely. In anybody's working copy it is the
         * opposite: the tracked original, 37 files that git knows about — and
         * the tail of this method deletes the directory it just copied.
         *
         * So `php artisan migrate` with KBB_PUBLIC_PATH pointing anywhere other
         * than the repo's own public/ removed those tracked files from the
         * working copy. Three lanes lost the directory that way. Worse, two
         * tests do it on every run: PaymentsGatewayTabsTest and
         * AdminMobileOverflowTest boot a preview whose app root is a symlink to
         * base_path() and whose KBB_PUBLIC_PATH is a throwaway directory, then
         * rm -rf that directory on the way out — so `KBB_BROWSER_TESTS=1
         * vendor/bin/pest` deleted tracked files and the deletion left with the
         * temp dir.
         *
         * The site does not deploy from git (signed zip packages, see
         * CLAUDE.md), so .git under the app root means a checkout and never the
         * host. Inside a git worktree .git is a FILE rather than a directory,
         * which is why this is file_exists() and not is_dir(): the worktrees
         * several lanes work in are exactly the case that has to be covered.
         *
         * Production is unaffected — there is no .git there, so the relocation
         * this migration exists to perform still happens.
         */
        if (file_exists(base_path('.git'))) {
            if (app()->runningInConsole()) {
                echo "Skipped relocating public/build: this is a git checkout, where it is the tracked original.\n";
            }

            return 0;
        }

        $moved = 0;

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stray, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            $target = $real . '/' . substr($item->getPathname(), strlen($stray) + 1);

            if ($item->isDir()) {
                if (! is_dir($target)) {
                    @mkdir($target, 0755, true);
                }

                continue;
            }

            if (! is_dir(dirname($target))) {
                @mkdir(dirname($target), 0755, true);
            }

            if (@copy($item->getPathname(), $target)) {
                $moved++;
            }
        }

        // Remove the stray tree so it cannot be mistaken for the live assets.
        $this->deleteTree($stray);

        return $moved;
    }

    /** @return int files removed */
    private function clearCompiled(): int
    {
        $cleared = 0;

        $patterns = [
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/events.php'),
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return $cleared;
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    public function down(): void
    {
        // Nothing to reverse: assets are regenerated by the next deploy.
    }
};
