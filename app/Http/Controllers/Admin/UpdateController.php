<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UpdateRelease;
use App\Services\Update\BackupService;
use App\Services\Update\UpdateGuard;
use App\Services\Update\UpdatePackage;
use App\Services\Update\UpdateRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * The admin screen.
 *
 * Deliberately a two-step flow. Upload runs every check and shows what would
 * change; nothing is written. Applying is a separate, password-confirmed action.
 * Splitting them means a mistaken upload costs nothing, and it gives a moment to
 * read the file list before committing.
 */
class UpdateController extends Controller
{
    public function __construct(
        private UpdateGuard $guard,
        private BackupService $backups,
        private UpdateRunner $runner,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.updates', [
            'releases' => UpdateRelease::latest()->limit(20)->get(),
            'currentVersion' => \App\Services\Update\InstalledVersion::get(),
            'signedMode' => (string) config('kbb.update_secret', '') !== '',
            'pending' => $this->currentPending($request),
        ]);
    }

    /**
     * The pending record, self-healing if it's stale — same reasoning and
     * same fix as UpdateApiController::currentPending(). A pending record
     * whose version already has a successful entry in the release history
     * is definitionally stale, whatever caused it to survive; this catches
     * that regardless of cause; kept here too since this fallback exists
     * specifically for when the other one isn't reachable.
     *
     * Also checks the main console's own session key (kbb_update_pending_api)
     * when this page's own key is empty. The two screens were writing to
     * completely separate session keys — a package uploaded through one was
     * invisible to the other. In practice that meant this fallback page,
     * built specifically for when the main console is broken, could show an
     * empty upload form while a real package sat stuck and pending in the
     * other screen's own state, with no way to see or clear it from here.
     * Confirmed the two arrays share an identical shape (zip, scratch, name,
     * version, notes, migrations, changes) before relying on this.
     */
    private function currentPending(Request $request): ?array
    {
        $pending = $request->session()->get('kbb_update_pending')
            ?? $request->session()->get('kbb_update_pending_api');

        if (! $pending) {
            return null;
        }

        $alreadyApplied = UpdateRelease::where('version', $pending['version'] ?? null)
            ->where('status', 'applied')
            ->exists();

        if ($alreadyApplied) {
            @unlink(\Illuminate\Support\Facades\Storage::disk('local')->path($pending['zip']));
            $this->deleteTree($pending['scratch']);
            $request->session()->forget('kbb_update_pending');
            $request->session()->forget('kbb_update_pending_api');

            return null;
        }

        return $pending;
    }

    /**
     * Discards whatever is pending, from either screen's session key, and
     * cleans up its uploaded zip and scratch directory. This screen never
     * had this at all before — its apply() comments referenced Cancel as
     * the expected way out of a failed re-verify, but no such button, form,
     * or route existed on this page. That gap is what left an admin with no
     * way to clear a stuck pending package and no way to upload anything
     * else, on the one page meant to work when everything else doesn't.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $pending = $this->currentPending($request);

        if ($pending) {
            @unlink(\Illuminate\Support\Facades\Storage::disk('local')->path($pending['zip']));
            $this->deleteTree($pending['scratch']);
        }

        $request->session()->forget('kbb_update_pending');
        $request->session()->forget('kbb_update_pending_api');

        return back()->with('kbb_update_message', 'Package discarded.');
    }

    /** Step 1 — verify only. Nothing on the site changes. */
    public function upload(Request $request): RedirectResponse
    {
        $this->throttle($request, 'kbb-update-upload', 10);

        $request->validate([
            'package' => ['required', 'file', 'mimes:zip', 'max:65536'],   // 64 MB
        ]);

        $scratch = storage_path('app/updates/scratch/' . bin2hex(random_bytes(6)));
        $zipPath = $request->file('package')->store('updates/incoming');
        $absoluteZip = \Illuminate\Support\Facades\Storage::disk('local')->path($zipPath);

        $package = new UpdatePackage($absoluteZip, $scratch, $this->guard);

        if (! $package->verify()) {
            @unlink($absoluteZip);
            $this->deleteTree($scratch);

            return back()->with('kbb_update_errors', $package->errors);
        }

        // Show what will change before anything does.
        $changes = [];
        foreach (array_keys($package->files) as $relative) {
            $changes[] = [
                'path' => $relative,
                'action' => is_file(base_path($relative)) ? 'replace' : 'add',
            ];
        }

        return $this->pending($request, [
            'zip' => $zipPath,
            'scratch' => $scratch,
            'version' => $package->version(),
            'name' => $package->manifest['name'] ?? 'Update',
            'notes' => $package->notes(),
            'migrations' => $package->hasMigrations(),
            'changes' => $changes,
        ]);
    }

    /** Step 2 — apply, with the admin's password as confirmation. */
    public function apply(Request $request): RedirectResponse
    {
        $this->throttle($request, 'kbb-update-apply', 5);

        $request->validate(['password' => ['required', 'string']]);

        $admin = Auth::guard('admin')->user();

        if (! $admin || ! Hash::check($request->string('password')->toString(), $admin->password)) {
            return back()->with('kbb_update_errors', ['That password was not correct. Nothing has been changed.']);
        }

        $pending = $this->currentPending($request);

        if (! $pending) {
            return back()->with('kbb_update_errors', ['That update is no longer pending. Please upload it again.']);
        }

        // Re-verify from scratch. The session could be stale, and a package must
        // never be applied on the strength of an earlier check alone.
        $package = new UpdatePackage(\Illuminate\Support\Facades\Storage::disk('local')->path($pending['zip']), $pending['scratch'], $this->guard);

        if (! $package->verify()) {
            // Same fix as UpdateApiController::apply() — a failure here used to
            // leave the zip, the scratch directory, and the pending session all
            // in place, so the page kept showing the same package as pending
            // and re-failed the same way on every retry, with no way out but
            // Cancel. Clean up and force a fresh upload instead.
            @unlink(\Illuminate\Support\Facades\Storage::disk('local')->path($pending['zip']));
            $this->deleteTree($pending['scratch']);
            $request->session()->forget('kbb_update_pending');
            $request->session()->forget('kbb_update_pending_api');

            return back()->with('kbb_update_errors', $package->errors);
        }

        $release = $this->runner->apply($package, (int) $admin->id);

        // $this->runner->apply() runs Artisan commands mid-request (config:clear,
        // route:clear, view:clear, cache:clear — the config:cache/route:cache
        // re-warm from 2.60.4 was removed in 2.60.11 after it broke the live
        // homepage; the *:clear commands carry the same container-rebinding
        // risk, so this fix still matters), some of which rebind the
        // container's session service to a second, disconnected instance.
        // The bare session() helper below this point
        // would operate on that new instance, not the one this request started
        // with and that Laravel actually persists when the response finishes —
        // see UpdateApiController::apply() for the full explanation and how
        // this was confirmed directly, not assumed.
        @unlink(\Illuminate\Support\Facades\Storage::disk('local')->path($pending['zip']));
        $this->deleteTree($pending['scratch']);
        $request->session()->forget('kbb_update_pending');
        $request->session()->forget('kbb_update_pending_api');

        $message = match ($release->status) {
            'applied' => "Update {$release->version} applied successfully.",
            'rolled_back' => "Update failed and was rolled back automatically. The site is unchanged. Reason: {$release->error}",
            default => "Update failed: {$release->error}",
        };

        return back()->with('kbb_update_message', $message);
    }

    public function rollback(Request $request, UpdateRelease $release): RedirectResponse
    {
        $this->throttle($request, 'kbb-update-rollback', 5);

        $request->validate(['password' => ['required', 'string']]);

        $admin = Auth::guard('admin')->user();

        if (! $admin || ! Hash::check($request->string('password')->toString(), $admin->password)) {
            return back()->with('kbb_update_errors', ['That password was not correct.']);
        }

        if (! $release->canRollBack()) {
            return back()->with('kbb_update_errors', ['That release cannot be rolled back.']);
        }

        $result = $this->backups->restoreFiles((string) $release->backup_id);
        $release->update(['status' => 'rolled_back', 'error' => 'Rolled back manually by admin.']);

        return back()->with('kbb_update_message', sprintf(
            'Rolled back: %d files restored, %d added files removed.',
            $result['restored'],
            $result['removed']
        ));
    }

    /**
     * Persist the verified package across the redirect AND the Apply request
     * that follows it. ->with() flashes for a single request, which is why the
     * package appeared to vanish between the two screens.
     */
    private function pending(Request $request, array $data): \Illuminate\Http\RedirectResponse
    {
        $request->session()->put('kbb_update_pending', $data);

        return back();
    }

    private function throttle(Request $request, string $key, int $max): void
    {
        $signature = $key . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($signature, $max)) {
            abort(429, 'Too many attempts. Try again in ' . RateLimiter::availableIn($signature) . ' seconds.');
        }

        RateLimiter::hit($signature, 600);
    }

    /**
     * Same purpose as UpdateApiController::download() — this page exists
     * for when that one isn't reachable, so it needs its own copy of
     * everything that matters, not a dependency on the other screen.
     */
    public function download(UpdateRelease $release)
    {
        if (! $release->archive_path || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($release->archive_path)) {
            abort(404, 'This release has no archived package on this server.');
        }

        return \Illuminate\Support\Facades\Storage::disk('local')->download($release->archive_path, $release->version . '.zip');
    }

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
