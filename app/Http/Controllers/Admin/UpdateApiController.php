<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UpdateRelease;
use App\Services\AdminPathService;
use App\Services\Update\BackupService;
use App\Services\Update\UpdateGuard;
use App\Services\Update\UpdatePackage;
use App\Services\Update\UpdateRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * JSON API for the Core Updates panel.
 *
 * A deliberate sibling of UpdateController rather than a replacement. That
 * controller has been repaired in place on the server several times — upload
 * path, session persistence, rate limits — and overwriting it would silently
 * undo all of it. This class wraps the same services and leaves it alone.
 *
 * The standalone page also stays: if the admin bundle ever fails to load, you
 * still need a way to install the update that fixes it.
 */
class UpdateApiController extends Controller
{
    public function __construct(
        private UpdateGuard $guard,
        private BackupService $backups,
        private UpdateRunner $runner,
    ) {}

    /** Everything the panel renders on load. */
    public function status(Request $request): JsonResponse
    {
        $pending = $this->currentPending($request);

        return response()->json([
            'version' => \App\Services\Update\InstalledVersion::get(),
            /* Kept exactly as it was: the panel's JS reads it and this patch
             * changes no existing field. `signing` is the new, fuller answer. */
            'signed' => (string) config('kbb.update_secret', '') !== '',
            'signing' => \App\Services\Update\SigningMode::describe(),
            'admin_path' => AdminPathService::current(),
            'admin_path_locked' => AdminPathService::isLockedByEnv(),
            'pending' => $pending,
            'releases' => UpdateRelease::latest()->limit(15)->get()->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'version' => $r->version,
                'status' => $r->status,
                'error' => $r->error,
                'files' => $r->file_count,
                'backup_id' => $r->backup_id,
                'when' => $r->created_at?->diffForHumans(),
                'has_archive' => (bool) ($r->archive_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($r->archive_path)),
                'superseded_by' => $r->superseded_by,
            ]),
            'backups' => $this->listBackups(),
        ]);
    }

    /**
     * The pending record, self-healing if it's stale.
     *
     * 2.60.9 fixed the reason a pending record could survive a successful
     * apply — but that fix has to apply cleanly *before* it can protect
     * anything, and the update that carries a fix is always applied by the
     * code that came before it. Exactly one update ever pays that cost: the
     * fix itself, applied by the version it's fixing. That is expected and
     * cannot be avoided from inside the package that's fixing it.
     *
     * This is the belt underneath that suspender, for this and for any
     * future bug shaped the same way: whatever the actual cause, a pending
     * record whose version already has a successful entry in the release
     * history is definitionally stale — the thing it's waiting to apply has
     * already happened — and checking that costs nothing while removing an
     * entire category of "it says applied, why does it still say pending"
     * confusion, permanently, regardless of what causes the next one.
     */
    private function currentPending(Request $request): ?array
    {
        // Also checks the fallback page's own session key when this one is
        // empty — see UpdateController::currentPending() for the full
        // explanation. The two screens used to be entirely blind to each
        // other's pending state; this and the matching fix there make
        // either screen able to see and clear a package regardless of
        // which one it was uploaded through.
        $pending = $request->session()->get('kbb_update_pending_api')
            ?? $request->session()->get('kbb_update_pending');

        if (! $pending) {
            return null;
        }

        $alreadyApplied = UpdateRelease::where('version', $pending['version'] ?? null)
            ->where('status', 'applied')
            ->exists();

        if ($alreadyApplied) {
            @unlink(Storage::disk('local')->path($pending['zip']));
            $this->deleteTree($pending['scratch']);
            $request->session()->forget('kbb_update_pending_api');
            $request->session()->forget('kbb_update_pending');

            return null;
        }

        return $pending;
    }

    /** Step 1 — verify. Writes nothing. */
    public function check(Request $request): JsonResponse
    {
        $request->validate(['package' => ['required', 'file', 'mimes:zip', 'max:65536']]);

        $scratch = storage_path('app/updates/scratch/' . bin2hex(random_bytes(6)));
        $stored = $request->file('package')->store('updates/incoming');
        $zipPath = Storage::disk('local')->path($stored);

        $package = new UpdatePackage($zipPath, $scratch, $this->guard);

        if (! $package->verify()) {
            @unlink($zipPath);
            $this->deleteTree($scratch);

            return response()->json(['ok' => false, 'errors' => $package->errors], 422);
        }

        $changes = [];
        foreach (array_keys($package->files) as $relative) {
            $changes[] = [
                'path' => $relative,
                'action' => is_file(base_path($relative)) ? 'replace' : 'add',
            ];
        }

        $pending = [
            'zip' => $stored,
            'scratch' => $scratch,
            'name' => $package->manifest['name'] ?? 'Update',
            'version' => $package->version(),
            'notes' => $package->notes(),
            'migrations' => $package->hasMigrations(),
            'changes' => $changes,
        ];

        // The container's session binding is not reliable to reuse after this
        // point: check() itself doesn't call Artisan commands, but apply()
        // does (config:clear, cache:clear, the cache re-warm added later),
        // and running those mid-request can rebind the session service to a
        // second, disconnected instance. $request->session() stays tied to
        // the actual request object whose data gets saved when the response
        // finishes, regardless of what the bare session() helper resolves to
        // by then — see apply() below for where this was actually caught.
        $request->session()->put('kbb_update_pending_api', $pending);

        return response()->json(['ok' => true, 'pending' => $pending]);
    }

    /**
     * Step 2 — apply.
     *
     * The password step was removed at the owner's request. What still protects
     * this: an authenticated admin session, CSRF, the package signature, the
     * path allow-list, per-file checksums, and an automatic rollback if the
     * site fails its health check afterwards. The confirmation was a speed bump
     * on top of those, not the control itself.
     */
    public function apply(Request $request): JsonResponse
    {
        $pending = $this->currentPending($request);

        if (! $pending) {
            return response()->json(['ok' => false, 'errors' => ['That package is no longer pending. Check it again.']], 422);
        }

        // Re-verify from scratch: a package is never applied on the strength of
        // an earlier check alone.
        $package = new UpdatePackage(Storage::disk('local')->path($pending['zip']), $pending['scratch'], $this->guard);

        if (! $package->verify()) {
            // This used to return the error here and stop — leaving the zip,
            // the scratch directory, and the pending session all still in
            // place. The next page load found that same session still
            // "pending" and showed the identical Ready to Apply card;
            // clicking Apply re-ran this same check against the same file
            // and failed the same way, every time, with no way out except
            // Cancel. If the file was ever going to pass, it already would
            // have — a failure here means clean up and force a fresh upload,
            // the same as check()'s own failure path already does.
            @unlink(Storage::disk('local')->path($pending['zip']));
            $this->deleteTree($pending['scratch']);
            $request->session()->forget('kbb_update_pending_api');
            $request->session()->forget('kbb_update_pending');

            return response()->json(['ok' => false, 'errors' => $package->errors], 422);
        }

        $release = $this->runner->apply($package, (int) Auth::guard('admin')->id());

        // $this->runner->apply() runs several Artisan commands mid-request
        // (config:clear, route:clear, view:clear, cache:clear — the
        // config:cache/route:cache re-warm from 2.60.4 was removed in
        // 2.60.11 after it broke the live homepage; this container-rebinding
        // risk applies to the *:clear commands too, so the fix below still
        // matters). Several of those rebuild parts of the service container,
        // including, it turns out, the session binding: the bare session()
        // helper below would resolve a *different* session object after this
        // line than the one this request started with, one that never
        // received this forget() call.
        // $request itself keeps referring to the original session throughout
        // — the one Laravel actually saves when the response is done — so
        // that's what has to be used here, not the helper. Confirmed directly:
        // before this fix, calling apply() and immediately reading
        // $request->session() afterward, in the very same request, still
        // showed the "cleared" pending record sitting there unchanged.
        @unlink(Storage::disk('local')->path($pending['zip']));
        $request->session()->forget('kbb_update_pending_api');
        $request->session()->forget('kbb_update_pending');

        return response()->json([
            'ok' => $release->status === 'applied',
            'status' => $release->status,
            'message' => match ($release->status) {
                'applied' => "Update {$release->version} applied.",
                'rolled_back' => "Update failed and was rolled back. The site is unchanged. {$release->error}",
                default => "Update failed. {$release->error}",
            },
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $pending = $this->currentPending($request);

        if ($pending) {
            @unlink(Storage::disk('local')->path($pending['zip']));
            $request->session()->forget('kbb_update_pending_api');
            $request->session()->forget('kbb_update_pending');
        }

        return response()->json(['ok' => true]);
    }

    /** Restore any backup on disk. */
    public function restore(Request $request, string $backup): JsonResponse
    {
        try {
            $result = $this->backups->restoreFiles(basename($backup));
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'errors' => [$e->getMessage()]], 422);
        }

        foreach (glob(base_path('bootstrap/cache/*.php')) ?: [] as $cached) {
            @unlink($cached);
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        UpdateRelease::create([
            'name' => 'Manual restore',
            'version' => basename($backup),
            'status' => 'rolled_back',
            'notes' => 'Restored from the Core Updates panel.',
            'applied_by' => (int) Auth::guard('admin')->id(),
            'file_count' => $result['restored'],
            'completed_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => "Restored {$backup} — {$result['restored']} files put back, {$result['removed']} removed.",
        ]);
    }

    public function adminPath(Request $request): JsonResponse
    {
        $request->validate([
            'admin_path' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string'],
        ]);

        if (! $this->passwordOk($request)) {
            return response()->json(['ok' => false, 'errors' => ['That password was not correct.']], 422);
        }

        if (AdminPathService::isLockedByEnv()) {
            return response()->json(['ok' => false, 'errors' => [
                'KBB_ADMIN_PATH is set in .env, which always wins. Remove that line to manage it here.',
            ]], 422);
        }

        $path = $request->string('admin_path')->toString();
        $result = AdminPathService::set($path);

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'errors' => [$result['error']]], 422);
        }

        return response()->json([
            'ok' => true,
            'redirect' => '/' . trim($path, '/'),
            'message' => "Admin moved to /{$path}. The old address now returns 404.",
        ]);
    }

    private function passwordOk(Request $request): bool
    {
        $admin = Auth::guard('admin')->user();

        return $admin && Hash::check($request->string('password')->toString(), $admin->password);
    }

    /**
     * Backups read from disk, not from the releases table: an update that
     * crashed hard may have left a backup with no usable database row, and that
     * is exactly when you need it.
     */
    private function listBackups(): array
    {
        $dirs = array_filter(glob(storage_path('app/updates/backups') . '/*') ?: [], 'is_dir');
        rsort($dirs);

        return array_map(function ($dir) {
            $m = json_decode((string) @file_get_contents($dir . '/manifest.json'), true) ?: [];

            return [
                'id' => basename($dir),
                'created_at' => $m['created_at'] ?? null,
                'replaced' => count($m['replaced'] ?? []),
                'added' => count($m['added'] ?? []),
                'database' => is_file($dir . '/database.sql'),
            ];
        }, $dirs);
    }

    /**
     * Downloads a previously-applied release's own package, from the
     * permanent archive on this server — independent of any chat session,
     * any upload, anything on Claude's side at all. Exists specifically so
     * "what did version X actually contain" has an answer months later.
     */
    public function download(UpdateRelease $release)
    {
        if (! $release->archive_path || ! Storage::disk('local')->exists($release->archive_path)) {
            abort(404, 'This release has no archived package on this server.');
        }

        $safeName = $release->version . '.zip';

        return Storage::disk('local')->download($release->archive_path, $safeName);
    }

    /** Same as UpdateController's own copy — no shared base class between them to hang it on. */
    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
