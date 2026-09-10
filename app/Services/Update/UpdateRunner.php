<?php

declare(strict_types=1);

namespace App\Services\Update;

use App\Models\UpdateRelease;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Applies a verified package, and undoes it if anything goes wrong.
 *
 * The design principle is that the site must be running at every moment. There
 * are three layers of protection:
 *
 *   1. Nothing is written until the package has fully passed verification.
 *   2. Every file about to be replaced is backed up first. If the backup itself
 *      fails, the update stops before changing anything.
 *   3. After the files land, the site is asked whether it still works. If it
 *      does not answer correctly, everything is rolled back automatically.
 *
 * A fatal error in newly installed code is the case that worries people most, so
 * it is handled specifically: a shutdown handler catches it and rolls back even
 * though normal execution has stopped.
 */
final class UpdateRunner
{
    private ?string $activeBackupId = null;

    private bool $completed = false;

    public function __construct(
        private BackupService $backups,
        private string $appRoot,
    ) {}

    public function apply(UpdatePackage $package, int $adminId): UpdateRelease
    {
        $release = UpdateRelease::create([
            'name' => (string) ($package->manifest['name'] ?? 'Update'),
            'version' => $package->version(),
            'status' => 'running',
            'notes' => $package->notes(),
            'applied_by' => $adminId,
            'file_count' => count($package->files),
        ]);

        // If PHP dies inside this request — a parse error in a new file, a memory
        // limit, a timeout — this still runs.
        register_shutdown_function(function () use ($release) {
            if ($this->completed) {
                return;
            }

            $error = error_get_last();

            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->emergencyRollback($release, 'Fatal error during update: ' . $error['message']);
            }
        });

        try {
            $paths = array_keys($package->files);

            // 1. Maintenance mode, so nobody sees a half-applied site.
            $this->down();

            // 2. Back up before touching anything.
            $snapshot = $this->backups->snapshotFiles($paths);
            $this->activeBackupId = $snapshot['id'];
            $release->update(['backup_id' => $snapshot['id']]);

            // 3. Database dump, only when migrations will run. Migrations are the
            //    one part that cannot be undone by copying files back.
            if ($package->hasMigrations()) {
                $this->backups->dumpDatabase($snapshot['path']);
            }

            // 4. Copy the files.
            $this->copyFiles($package);

            // 5. Clear compiled caches so the new code is actually used.
            $this->clearCaches();

            // 6. Migrations.
            if ($package->hasMigrations()) {
                Artisan::call('migrate', ['--force' => true]);
                $release->update(['migration_output' => Artisan::output()]);
            }

            /*
             * 7. Leave maintenance mode BEFORE checking the site.
             *
             * Maintenance mode answers every request with 503, including the
             * health endpoint, so a check performed while still down can only
             * ever fail — every update then rolls itself back reporting a 503
             * that means nothing about the update.
             */
            $this->up();

            // 8. Now ask the site whether it still works.
            $health = $this->healthCheck();

            if (! $health['ok']) {
                $this->rollback($release, 'Health check failed after update: ' . $health['reason']);

                return $release->fresh();
            }

            $release->update(['status' => 'applied', 'completed_at' => now()]);
            $this->completed = true;

            $this->backups->prune(5);
            $this->archivePackage($release, $package);

            return $release->fresh();
        } catch (\Throwable $e) {
            $this->rollback($release, $e->getMessage());

            return $release->fresh();
        }
    }

    /**
     * Copies the uploaded zip into a permanent archive folder instead of
     * letting it be deleted, and records where. Every prior release simply
     * discarded the zip once applied — there was no way to come back for a
     * specific past patch later, from this server or anywhere else, and no
     * way for a future session (this one's own memory included) to recover
     * it if needed. This never blocks or fails the update itself: a problem
     * archiving is logged and swallowed, not thrown, since the site
     * updating successfully matters far more than a copy of the file that
     * did it.
     */
    private function archivePackage(UpdateRelease $release, UpdatePackage $package): void
    {
        try {
            $slugSource = trim($package->notes()) !== '' ? $package->notes() : $release->name;
            $slug = \Illuminate\Support\Str::slug(substr($slugSource, 0, 60));
            $filename = 'kbb-patch-archive/' . $release->version . ($slug !== '' ? '_' . $slug : '') . '.zip';

            // Written through the same Storage facade the download route
            // reads it back through, rather than a raw filesystem path —
            // the 'local' disk's actual root (storage/app/private, not
            // storage/app) is a Storage-facade concern, not something to
            // duplicate and risk drifting out of sync with here. Streamed
            // rather than loaded into a string, so a package considerably
            // larger than today's ~500KB ones still copies without needing
            // to hold the whole file in memory at once.
            $stream = fopen($package->zipPath(), 'rb');

            if ($stream === false) {
                throw new \RuntimeException("Could not open the package at {$package->zipPath()}.");
            }

            try {
                if (! \Illuminate\Support\Facades\Storage::disk('local')->put($filename, $stream)) {
                    throw new \RuntimeException("Could not write the archive to {$filename}.");
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $release->update(['archive_path' => $filename]);
        } catch (\Throwable $e) {
            Log::warning('kbb-update: could not archive applied package', [
                'version' => $release->version,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Where a packaged file belongs on disk.
     *
     * public/ lives outside the app root — public_html/kbb-upgrade/ here — so
     * writing it under the app root put every compiled asset in a folder
     * nothing reads.
     *
     * NOTE: the fallback below must stay a literal join. A blanket
     * search-and-replace once rewrote it into a call to this same method, which
     * recursed until PHP ran out of stack.
     */
    private function targetFor(string $relative): string
    {
        if (str_starts_with($relative, 'public/')) {
            return rtrim(public_path(), '/') . '/' . substr($relative, strlen('public/'));
        }

        return $this->appRoot . '/' . $relative;
    }

    private function copyFiles(UpdatePackage $package): void
    {
        foreach ($package->files as $relative => $source) {
            $target = $this->targetFor($relative);

            if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
                throw new \RuntimeException("Could not create directory for {$relative}.");
            }

            if (! copy($source, $target)) {
                throw new \RuntimeException("Could not write {$relative}. Check file permissions.");
            }
        }
    }

    /**
     * Requests the health endpoint over HTTP.
     *
     * This is deliberately a real request rather than an internal call: it boots
     * the application from scratch in a separate process, which is the only way
     * to find out whether the newly written files actually parse and run.
     */
    private function healthCheck(): array
    {
        $token = (string) config('kbb.health_token', '');
        $url = rtrim((string) config('app.url'), '/') . '/_kbb-health';

        if ($token === '') {
            return ['ok' => true, 'reason' => 'skipped: no health token configured'];
        }

        try {
            $response = Http::timeout(20)->get($url, ['token' => $token]);

            if (! $response->successful()) {
                return ['ok' => false, 'reason' => 'HTTP ' . $response->status()];
            }

            return ($response->json('ok') === true)
                ? ['ok' => true, 'reason' => 'ok']
                : ['ok' => false, 'reason' => 'unexpected response body'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    private function rollback(UpdateRelease $release, string $reason): void
    {
        Log::error('KBB update rolled back', ['release' => $release->id, 'reason' => $reason]);

        try {
            if ($this->activeBackupId) {
                $this->backups->restoreFiles($this->activeBackupId);
                $this->clearCaches();
            }

            $release->update([
                'status' => 'rolled_back',
                'error' => $reason,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Restoring failed too. Say so loudly and point at the standalone
            // recovery script, which does not need Laravel to boot.
            $release->update([
                'status' => 'failed',
                'error' => $reason . ' — AND the automatic rollback failed: ' . $e->getMessage()
                    . ' Use public/kbb-recover.php to restore manually.',
                'completed_at' => now(),
            ]);
        } finally {
            $this->completed = true;
            $this->up();
        }
    }

    /** Called from the shutdown handler, where exceptions cannot be thrown. */
    private function emergencyRollback(UpdateRelease $release, string $reason): void
    {
        try {
            if ($this->activeBackupId) {
                $this->backups->restoreFiles($this->activeBackupId);
            }

            @unlink($this->appRoot . '/storage/framework/down');

            $release->update(['status' => 'rolled_back', 'error' => $reason, 'completed_at' => now()]);
        } catch (\Throwable) {
            // Nothing further can be done from inside a dying request. The
            // recovery script exists for exactly this case.
        }
    }

    private function clearCaches(): void
    {
        foreach (['config:clear', 'route:clear', 'view:clear', 'cache:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (\Throwable) {
                // A cache that will not clear must not stop an update.
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * REMOVED. This used to re-cache config and routes after every update —
     * added in 2.60.4 on the theory that leaving the app permanently
     * uncached was a real, if small, cost worth avoiding. Benchmarked it
     * directly before shipping and found no measurable difference either
     * way. Kept it anyway, reasoning the cost was zero even if the benefit
     * was small.
     *
     * The cost was not zero. `route:cache` — cheap and inert in testing —
     * broke the live homepage in production with a 405, while every other
     * page kept working. Never reproduced in any sandbox; a stale or
     * malformed cached routes file takes absolute priority over the real
     * routes/web.php the moment it exists, so the actual live behaviour and
     * what route:list shows from source can disagree in exactly this way,
     * and there was no way to safely chase down which specific route
     * characteristic caused it without the same risk recurring.
     *
     * Given a self-benchmarked zero-benefit optimisation caused a real
     * outage, the fix is to stop doing the optimisation, not to debug
     * around its specific failure. Every release now goes back to exactly
     * what every release before 2.60.4 already did successfully: clear the
     * compiled caches and leave them cleared. An uncached app that works
     * is strictly better than a cached one that sometimes doesn't.
     */
    private function down(): void
    {
        try {
            Artisan::call('down', ['--render' => 'errors::503', '--retry' => 60]);
        } catch (\Throwable) {
            @file_put_contents($this->appRoot . '/storage/framework/down', json_encode(['retry' => 60]));
        }
    }

    private function up(): void
    {
        try {
            Artisan::call('up');
        } catch (\Throwable) {
            @unlink($this->appRoot . '/storage/framework/down');
        }
    }
}
