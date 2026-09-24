<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\ModuleToggle;
use App\Models\UpdateRelease;
use App\Services\IntegrityChecker;
use App\Services\SecurityModule;
use App\Services\SettingsService;
use App\Services\Update\UpdatePackage;
use App\Services\Update\UpdateRunner;
use App\Support\AdminCapabilities;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * The security module, part two: integrity checking in REPORT-ONLY mode.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG WITH THE SHOP BEFORE THIS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * This host has no shell. The owner cannot diff a file, list a directory or
 * hash anything — the only way code reaches the server is a signed zip applied
 * through Store → Core Updates. And the shop threw away the one record that
 * would have let it answer for itself: every package carries `update.json` with
 * a SHA-256 for every path it installs, UpdatePackage::checkChecksums()
 * verifies each one before a byte is written, and then `update_releases` kept a
 * file COUNT and discarded the manifest.
 *
 * So a package applied twice, half-applied after a timeout, or hand-edited over
 * FTP by a support agent was INVISIBLE. That is not hypothetical on this
 * project: packages 2.60.102–.106 were built against a stale tree, applied
 * anyway, reverted three files and 500'd every product page — and nothing on
 * the server could say which files had moved or what they should have been.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * AND THE HALF THAT IS NOT A FEATURE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Phase 18's sequencing is report before enforce, in every part: audit trail
 * and reporting screen → INTEGRITY CHECKING IN REPORT-ONLY MODE → CSP
 * report-only → the request gate in observe mode → enforcement one rule at a
 * time. This is the second step, so the module's most important properties are
 * still negative ones and are still pinned directly:
 *
 *   - not one request the shop answered before may be refused now, asserted
 *     against the STOREFRONT'S OWN STATUS CODES in absolute terms and not by
 *     comparing the module with itself (see the mutation note on that test —
 *     the comparison alone was green against a forced 403);
 *   - not one byte on disk may be written by a check whose job is to read.
 *     Asserted twice: the file's contents are compared before and after, and
 *     IntegrityChecker's SOURCE may not name copy(), file_put_contents(),
 *     unlink() or rename() at all.
 *
 * Every `it(...)` below states the defect it stands for and carries a mutation
 * note saying what to change to turn it red.
 */

/** An admin of the given role. */
function intAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Integrity '.$role,
        'email' => 'int-'.$role.'-'.uniqid().'@example.test',
        'password' => 'lane-c-password',
        'role' => $role,
    ]);
}

/** The route file the integrator wires, registered exactly as it will be. */
function intRegisterRoutes(): void
{
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/security-admin.php'));
}

/**
 * A real file under the application root, and its relative path.
 *
 * Under storage/app/ because that is writable on every host this suite runs on
 * and is not part of the shipped tree; the checker does not care which
 * directory a manifest names, only that base_path() plus the path is a file.
 *
 * @return array{0: string, 1: string, 2: string}  [relative, absolute, sha256]
 */
function intProbeFile(string $body): array
{
    $relative = 'storage/app/kbb-integrity-probe/'.uniqid('f', true).'.txt';
    $absolute = base_path($relative);

    if (! is_dir(dirname($absolute))) {
        mkdir(dirname($absolute), 0755, true);
    }

    file_put_contents($absolute, $body);

    return [$relative, $absolute, (string) hash('sha256', $body)];
}

/** An applied release carrying this manifest. */
function intRelease(string $version, array $files, string $status = 'applied'): UpdateRelease
{
    return UpdateRelease::create([
        'name' => 'Probe '.$version,
        'version' => $version,
        'status' => $status,
        'file_count' => count($files),
        'manifest' => json_encode($files, JSON_UNESCAPED_SLASHES),
    ]);
}

afterEach(function () {
    $dir = base_path('storage/app/kbb-integrity-probe');

    foreach (glob($dir.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($dir);
});

/* ════════════════════════════════════ 1. it notices what it is for ═══ */

it('reports a shipped file that no longer matches the package that installed it', function () {
    /*
     * THE DEFECT. The shop installed this file, verified its hash on the way
     * in, and then had no way of ever knowing it had changed. On a host with no
     * shell that is the whole of the problem: an injected line, a half-applied
     * package and a support agent's edit all look exactly like nothing at all.
     */
    [$relative, $absolute, $hash] = intProbeFile("<?php\n// as the package shipped it\n");

    intRelease('2.60.300', [$relative => $hash]);

    // Something that is not the shop writes to it.
    file_put_contents($absolute, "<?php\n// and now with an extra line\neval(\$_GET['x']);\n");

    $state = app(IntegrityChecker::class)->scan();

    expect($state['findings'])->toBe(1)
        ->and($state['checked'])->toBe(1)
        ->and($state['paths'])->toBe([$relative]);

    $row = AuditEvent::query()->where('event', IntegrityChecker::E_CHANGED)->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->subject)->toBe($relative)
        ->and($row->severity)->toBe('alert')
        ->and($row->summary)->toContain($relative)
        // BOTH HASHES ARE THE EVIDENCE. A finding that says "this changed"
        // and cannot say from what to what is a finding nobody can act on.
        ->and($row->before)->toContain(substr($hash, 0, 16))
        ->and($row->after)->toContain(substr((string) hash_file('sha256', $absolute), 0, 16));

    /*
     * MUTATION NOTE. Change the `hash_equals` comparison in
     * IntegrityChecker::scan() to `true` — or drop the `manifest` key from
     * intRelease() so there is nothing to compare against — and this is red
     * with zero findings.
     */
});

it('reports a file a package installed that is no longer on the server', function () {
    // The other half, and the one a half-applied package actually produces:
    // the manifest says the file landed and the file is not there.
    [$relative, $absolute, $hash] = intProbeFile('one line');

    intRelease('2.60.301', [$relative => $hash]);
    unlink($absolute);

    $state = app(IntegrityChecker::class)->scan();

    $row = AuditEvent::query()->where('event', IntegrityChecker::E_MISSING)->latest('id')->first();

    expect($state['findings'])->toBe(1)
        ->and($row)->not->toBeNull()
        ->and($row->subject)->toBe($relative)
        ->and($row->after)->toContain('not on the server');

    /*
     * MUTATION NOTE. Replace the `! is_file($target)` branch in scan() with a
     * `continue` and this is red: a file that was deleted reports as clean,
     * which is the worst possible answer.
     */
});

it('says nothing at all when every shipped file still matches', function () {
    // "Nothing happened" and "nothing was checked" must never render the same,
    // which is the rule the verdict line already follows. This is the clean
    // case: files checked, none found wanting, and no row written.
    [$a, , $ha] = intProbeFile('alpha');
    [$b, , $hb] = intProbeFile('beta');

    intRelease('2.60.302', [$a => $ha, $b => $hb]);

    $state = app(IntegrityChecker::class)->scan();

    expect($state['checked'])->toBe(2)
        ->and($state['findings'])->toBe(0)
        ->and(AuditEvent::query()->whereIn('event', IntegrityChecker::EVENTS)->count())->toBe(0);
});

/* ═══════════════════════════ 2. which package's hash is the right one ═══ */

it('compares against the newest package that shipped the file, not the first', function () {
    /*
     * THE BUG THIS EXISTS FOR, and it is the one that would have made the
     * feature useless on day two. A file shipped in 2.60.100 and shipped AGAIN
     * in 2.60.300 must be compared against the second hash. Against the first,
     * every package after the first would light up the whole screen — and a
     * security report that cries wolf on every release is one that gets
     * switched off, which is the failure mode this whole module is sequenced to
     * avoid.
     */
    [$relative, , $newHash] = intProbeFile('the second version, which is on disk');

    intRelease('2.60.100', [$relative => str_repeat('0', 64)]);   // the old hash
    intRelease('2.60.300', [$relative => $newHash]);              // what is there now

    expect(app(IntegrityChecker::class)->scan()['findings'])->toBe(0);

    /*
     * MUTATION NOTE. Reverse the usort in
     * IntegrityChecker::appliedReleases() — or order by id, which happens to
     * agree here but not after a rollback and re-apply — and this is red with
     * one finding against the 2.60.100 hash.
     */
});

it('ignores a release that was rolled back', function () {
    /*
     * A rolled-back release had its files put back by
     * BackupService::restoreFiles(), so its hashes describe bytes that are
     * DELIBERATELY no longer on disk. Counting them would report every
     * successful rollback — the shop's own safety net working exactly as
     * designed — as an intrusion.
     */
    [$relative, , $hash] = intProbeFile('what is actually on disk');

    intRelease('2.60.100', [$relative => $hash]);
    intRelease('2.60.310', [$relative => str_repeat('a', 64)], 'rolled_back');
    intRelease('2.60.311', [$relative => str_repeat('b', 64)], 'failed');

    $state = app(IntegrityChecker::class)->scan();

    expect($state['findings'])->toBe(0)
        ->and($state['releases'])->toBe(1);

    /*
     * MUTATION NOTE. Drop the `where('status', 'applied')` in
     * appliedReleases() and this is red with one finding blaming a release that
     * was undone.
     */
});

it('recovers a manifest from the archived package when the column is empty', function () {
    /*
     * WHY THIS MATTERS ON DAY ONE. `update_releases.manifest` is new, so every
     * package this shop has ever applied — including the one running right now
     * — has none. A checker that could only speak about packages shipped from
     * today would be useless for a year.
     *
     * UpdateRunner::archivePackage() has kept every applied package's zip, and
     * `update.json` inside it holds the hashes. So the past is recoverable, and
     * it is written back into the column on first read so the zip is opened
     * once ever.
     */
    Storage::fake('local');

    [$relative, , $hash] = intProbeFile('shipped, and still as shipped');

    $zipPath = sys_get_temp_dir().'/kbb-integrity-'.uniqid().'.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('update.json', (string) json_encode([
        'name' => 'Probe', 'version' => '2.60.320', 'files' => [$relative => $hash],
    ]));
    $zip->close();

    Storage::disk('local')->put('kbb-patch-archive/2.60.320.zip', (string) file_get_contents($zipPath));
    @unlink($zipPath);

    $release = UpdateRelease::create([
        'name' => 'Probe', 'version' => '2.60.320', 'status' => 'applied',
        'file_count' => 1, 'archive_path' => 'kbb-patch-archive/2.60.320.zip',
    ]);

    expect($release->manifest)->toBeNull();

    $state = app(IntegrityChecker::class)->scan();

    expect($state['expected'])->toBe(1)
        ->and($state['checked'])->toBe(1)
        ->and($state['findings'])->toBe(0)
        // Written back, so the zip is read once and never again.
        ->and($release->fresh()->manifest)->toContain($hash);

    /*
     * MUTATION NOTE. Make manifestOf() return [] when the column is empty
     * instead of falling back to the archive, and this is red: expected drops
     * to 0 and the shop can say nothing about any package applied before this
     * release.
     */
});

it('records what a package contained as the package applies', function () {
    /*
     * THE OTHER END OF THE SAME WIRE, and the reason the archive fallback is a
     * fallback rather than the design. UpdateRunner threw the manifest away
     * after every apply; `update_releases` recorded that 23 files landed and
     * nothing about which 23.
     *
     * Exercised through UpdateRunner's own method rather than a whole apply(),
     * because apply() puts the site into maintenance mode and runs migrations.
     * It is the real method on the real class writing the real column.
     */
    $release = UpdateRelease::create([
        'name' => 'Probe', 'version' => '2.60.330', 'status' => 'running', 'file_count' => 2,
    ]);

    $package = new UpdatePackage('/dev/null', '/dev/null', app(\App\Services\Update\UpdateGuard::class));
    $package->manifest = ['files' => ['app/A.php' => str_repeat('c', 64), 'app/B.php' => str_repeat('d', 64)]];

    $method = new ReflectionMethod(UpdateRunner::class, 'recordManifest');
    $method->setAccessible(true);
    $method->invoke(app(UpdateRunner::class), $release, $package);

    expect(json_decode((string) $release->fresh()->manifest, true))
        ->toBe(['app/A.php' => str_repeat('c', 64), 'app/B.php' => str_repeat('d', 64)]);

    // And it is actually called, from the one place it can be.
    expect((string) file_get_contents(app_path('Services/Update/UpdateRunner.php')))
        ->toContain('$this->recordManifest($release, $package);');

    /*
     * MUTATION NOTE. Delete the `$release->update(['manifest' => ...])` line in
     * recordManifest() and this is red with a null column — which is the state
     * every release before this one is in, and the reason the archive fallback
     * above exists.
     */
});

/* ══════════════════════════════════ 3. it blocks and restores nothing ═══ */

it('changes not one byte of a file it finds fault with', function () {
    /*
     * THE NON-NEGOTIABLE OF THIS ROUND. Phase 18 records "Restore
     * automatically, or alert and wait?" under "Open, for the owner", with a
     * recommendation of alert-by-default. A lane does not answer an owner's
     * open question by shipping code for one of the branches, and an automatic
     * restore would also silently undo a legitimate hand-edit.
     *
     * So: a file that fails the check is left exactly as it was found.
     */
    [$relative, $absolute, $hash] = intProbeFile('as shipped');

    intRelease('2.60.340', [$relative => $hash]);

    $tampered = "tampered with, and this is what must still be here afterwards\n";
    file_put_contents($absolute, $tampered);
    $mtime = filemtime($absolute);

    app(IntegrityChecker::class)->scan();

    expect((string) file_get_contents($absolute))->toBe($tampered)
        ->and(filemtime($absolute))->toBe($mtime);

    /*
     * MUTATION NOTE. Add a copy() from anywhere to $target inside scan() and
     * this is red — and so is the source assertion below it, which is the one
     * that survives a restore written some other way.
     */
});

it('may not name a call that writes to a file it checks', function () {
    /*
     * The structural half, and the one that survives a later lane. "Report
     * only" is a property of what this class is ALLOWED to do, not of what its
     * code happens to do today — the same argument
     * SecurityModuleTest's "registers no middleware anywhere" makes for the
     * gate. A future round that adds restore has to delete this assertion by
     * hand, which is exactly the moment somebody should be reading the owner's
     * open question again.
     */
    /*
     * CODE, NOT PROSE. The comments are stripped before the search, and that is
     * not a convenience -- the class docblock says in as many words that it may
     * not name copy(), file_put_contents(), unlink() or rename(), and a naive
     * grep over the whole file finds its own prohibition and calls it a
     * violation. Tokenising is also the stricter reading: what matters is what
     * the class can DO, and a promise in a comment is exactly the thing this
     * assertion exists to stop anybody relying on.
     */
    $code = '';

    foreach (token_get_all((string) file_get_contents(app_path('Services/IntegrityChecker.php'))) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    foreach (['copy(', 'file_put_contents(', 'unlink(', 'rename(', 'fwrite(', 'chmod(', 'mkdir(', 'rmdir('] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse(
            "IntegrityChecker names {$forbidden}: this round reports, and restoring is the owner's decision"
        );
    }

    // And it is not a middleware and does not run before the router, the same
    // two properties SecurityModule carries.
    foreach (['function handle(', 'prependMiddleware', 'pushMiddleware'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("IntegrityChecker names {$forbidden}");
    }

    /*
     * MUTATION NOTE. Add `@copy($target, $target . '.bak');` anywhere in
     * scan() and this is red -- and it stays red however the comments around it
     * are worded.
     */
});

it('offers no way to switch on a restore that does not exist', function () {
    /*
     * The seam, and the proof that it is a seam rather than a decision. The
     * plan's open question gets a control with exactly ONE option today;
     * SecurityModule::cast() stores a select value only when it is one of that
     * field's own options and otherwise stores the default, which is the
     * project's standing rule for a select. So a hand-rolled POST asking for a
     * behaviour nobody built is stored as the behaviour that exists.
     */
    intRegisterRoutes();

    $owner = intAdmin('owner');

    $this->actingAs($owner, 'admin')
        ->postJson('/admin-api/security', ['settings' => ['integrity_action' => 'restore']])
        ->assertOk();

    expect(app(SecurityModule::class)->get('integrity_action'))->toBe('alert')
        ->and(array_keys(IntegrityChecker::ACTIONS))->toBe(['alert']);

    /*
     * MUTATION NOTE. Add 'restore' to IntegrityChecker::ACTIONS and this is red
     * — which is the point: the option cannot appear without somebody deciding
     * it should.
     */
});

it('refuses no request the shop answered before', function () {
    /*
     * THE RULE THAT OUTRANKS THE FEATURE, carried forward from round one and
     * re-run against the round that adds a filesystem walk. A security layer
     * that starts blocking blocks the owner, the payment provider's webhooks
     * and Google's crawler, gets switched off, and leaves the shop worse off
     * than before because everyone now believes it is protected.
     */
    $paths = ['/', '/shop', '/cart', '/checkout', '/wishlist', '/int-probe-not-a-page'];

    // A release and a finding in place, so the module has something to say
    // while the storefront is walked — an empty module proving it blocks
    // nothing would prove nothing.
    [$relative, $absolute, $hash] = intProbeFile('as shipped');
    intRelease('2.60.350', [$relative => $hash]);
    file_put_contents($absolute, 'and not as shipped');
    app(IntegrityChecker::class)->scan();

    expect(app(SecurityModule::class)->report()['integrity']['findings'])->toBe(1);

    $with = [];

    foreach ($paths as $path) {
        $with[$path] = $this->get($path)->getStatusCode();
    }

    app(SecurityModule::class)->save(['integrity_on' => false]);

    $without = [];

    foreach ($paths as $path) {
        $without[$path] = $this->get($path)->getStatusCode();
    }

    expect($with)->toBe($without);

    /*
     * AND THE SAME THING IN ABSOLUTE TERMS, which is the half that actually
     * bites — and the flaw this lane found and fixed in its own round-one
     * proof. The comparison above measures the module AGAINST ITSELF: a gate
     * that refuses in both passes matches itself perfectly and the comparison
     * reports all clear. Verified by mutation in round one — forcing a 403 on
     * /cart from inside the module left the two walks identical and the
     * comparison green.
     *
     * So the codes are also named against the STOREFRONT'S OWN behaviour: every
     * page the shop serves still answers, and the only refusal is the address
     * that does not exist.
     */
    foreach ($paths as $path) {
        if ($path === '/int-probe-not-a-page') {
            expect($with[$path])->toBe(404, 'a missing page stopped being a 404');

            continue;
        }

        expect($with[$path])->toBeLessThan(400, "{$path} answered {$with[$path]}");
    }

    /*
     * MUTATION NOTE. Make SecurityController::integrity() or the
     * RequestHandled listener call setStatusCode(403) for any path and this is
     * red on the loop above — not on the comparison.
     */
});

it('runs nowhere near a request the storefront serves', function () {
    /*
     * A file-by-file hash on the hot path would be the slowest thing in the
     * shop, and — worse — reading this module's own settings before knowing
     * whether an admin is signed in is the bug that cost this lane five
     * unrelated tests in round one: it filled the forever-cache from a
     * half-migrated `settings` table and /shop began paginating from settings
     * that were not there yet.
     *
     * So the checker is reachable from ONE place, the owner-only controller,
     * and this asserts that structurally rather than by walking pages.
     */
    expect((string) file_get_contents(app_path('Providers/AppServiceProvider.php')))
        ->not->toContain('IntegrityChecker');

    expect((string) file_get_contents(app_path('Services/SecurityModule.php')))
        ->not->toContain('IntegrityChecker::class)->scan');

    foreach (glob(app_path('Http/Middleware/*.php')) ?: [] as $middleware) {
        expect((string) file_get_contents($middleware))->not->toContain('IntegrityChecker');
    }

    // And a storefront walk writes no integrity row, because nothing ran.
    foreach (['/', '/shop', '/cart'] as $path) {
        $this->get($path);
    }

    expect(AuditEvent::query()->whereIn('event', IntegrityChecker::EVENTS)->count())->toBe(0);

    /*
     * MUTATION NOTE. Add `app(IntegrityChecker::class)->scan();` to the
     * RequestHandled listener in SecurityModule::listen() and this is red on
     * the SecurityModule source assertion.
     */
});

/* ════════════════════════════════════════════ 4. the screen and the cost ═══ */

it('files a finding with no actor, no address and no request path', function () {
    /*
     * THE ROW THAT WOULD HAVE BEEN A LIBEL. record() fills actor, IP and
     * request path from whoever is signed in — and the admin who opened Store →
     * Security is not the person who changed the file. That is the entire
     * reason the check exists. A row reading "by <owner>, from 127.0.0.1, GET
     * /admin-api/security" would put the one person the check can prove
     * innocent in the "by" column of an alert.
     */
    [$relative, $absolute, $hash] = intProbeFile('as shipped');
    intRelease('2.60.360', [$relative => $hash]);
    file_put_contents($absolute, 'not as shipped');

    intRegisterRoutes();
    $owner = intAdmin('owner');

    $this->actingAs($owner, 'admin')->postJson('/admin-api/security/integrity')->assertOk();

    $row = AuditEvent::query()->where('event', IntegrityChecker::E_CHANGED)->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->actor_id)->toBeNull()
        ->and($row->actor_label)->toBeNull()
        ->and($row->actor_role)->toBeNull()
        ->and($row->ip)->toBeNull()
        ->and($row->path)->toBeNull()
        ->and($row->method)->toBeNull();

    // And the screen would not print one even if a row carried it.
    expect((string) file_get_contents(resource_path('views/admin/partials/security-screen.blade.php')))
        ->toContain('unattributed');

    /*
     * MUTATION NOTE. Drop `'anonymous' => true` from
     * IntegrityChecker::recordOne() and this is red on four assertions at once,
     * each naming the owner who ran the check.
     */
});

it('does not write the same finding again every time the screen is opened', function () {
    /*
     * THE DEFECT A DAILY HABIT WOULD HAVE PRODUCED. An owner who opens this
     * screen every morning would have thirty rows for one modified file by the
     * end of the month, and the thirtieth would read as thirty separate
     * intrusions. A repeated finding is the SAME finding: it bumps `hits` and
     * `last_seen_at` on the row that already says so, the way a repeated 404
     * and a repeated rate-limit trip already do on this project.
     */
    [$relative, $absolute, $hash] = intProbeFile('as shipped');
    intRelease('2.60.370', [$relative => $hash]);
    file_put_contents($absolute, 'changed once');

    $checker = app(IntegrityChecker::class);

    $checker->scan();
    $checker->scan();
    $checker->scan();

    $rows = AuditEvent::query()->where('event', IntegrityChecker::E_CHANGED)->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->hits)->toBe(3);

    // A SECOND, DIFFERENT edit is a second event and gets its own row: the
    // first row's evidence must not be overwritten by it.
    file_put_contents($absolute, 'changed again, differently');
    $checker->scan();

    expect(AuditEvent::query()->where('event', IntegrityChecker::E_CHANGED)->count())->toBe(2);

    /*
     * MUTATION NOTE. Remove the `$existing` lookup in recordOne() and this is
     * red with three rows and hits = 1 on each.
     */
});

it('puts the finding at the top of the verdict, above everything else', function () {
    /*
     * A wrong password is somebody trying to get in. A shipped file that no
     * longer matches its package is somebody who already did — or a package
     * that did not apply cleanly, which on a host with no shell is the same
     * emergency. It is the most serious sentence this screen can say, so it
     * must not be buried under "12 failed sign-ins".
     */
    $module = app(SecurityModule::class);

    expect($module->report()['verdict']['line'])->toBe('Nothing to act on.');

    [$relative, $absolute, $hash] = intProbeFile('as shipped');
    intRelease('2.60.380', [$relative => $hash]);
    file_put_contents($absolute, 'not as shipped');

    // Plenty of failed sign-ins as well, so the branch order is what is being
    // measured rather than the only thing there is to say.
    for ($i = 0; $i < 30; $i++) {
        AuditEvent::create([
            'occurred_at' => Carbon::now(), 'last_seen_at' => Carbon::now(),
            'event' => SecurityModule::E_SIGNIN_FAILED, 'severity' => 'notice',
            'summary' => 'Failed sign-in',
        ]);
    }

    app(IntegrityChecker::class)->scan();

    $verdict = $module->report()['verdict'];

    expect($verdict['tone'])->toBe('act')
        ->and($verdict['line'])->toContain('1 shipped file does not match')
        ->and($verdict['detail'])->toContain($relative)
        ->and($verdict['detail'])->toContain('Nothing has been restored');

    /*
     * MUTATION NOTE. Move the integrity branch in SecurityModule::verdict()
     * below the fail_threshold branch and this is red: the line reads "30
     * failed sign-ins" and the modified file is not mentioned at all.
     */
});

it('gives the check to the owner alone, on a capability of its own', function () {
    /*
     * Reading a report that is already written and making a shared plan hash
     * every file a package installed are different acts with different costs.
     * CLAUDE.md's rule is that every new admin endpoint gets its own capability
     * and fails closed; this one earns it as well as obeying it, because the
     * day a manager may READ this screen that must not hand them a few thousand
     * file reads on demand.
     */
    expect(AdminCapabilities::forPath('POST', 'admin-api/security/integrity'))
        ->toBe('security.integrity')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/security'))
        ->toBe('security.view')
        ->and(AdminCapabilities::roleCan('manager', 'security.integrity'))->toBeFalse()
        ->and(AdminCapabilities::roleCan('support', 'security.integrity'))->toBeFalse()
        ->and(AdminCapabilities::roleCan('editor', 'security.integrity'))->toBeFalse()
        ->and(AdminCapabilities::roleCan('owner', 'security.integrity'))->toBeTrue();

    intRegisterRoutes();

    $this->actingAs(intAdmin('owner'), 'admin')
        ->postJson('/admin-api/security/integrity')->assertOk();

    foreach (['manager', 'support', 'editor'] as $role) {
        $this->actingAs(intAdmin($role), 'admin')
            ->postJson('/admin-api/security/integrity')->assertStatus(403);
    }

    // And with no admin session at all.
    auth()->guard('admin')->logout();
    $this->post('/admin-api/security/integrity')->assertStatus(302);

    /*
     * MUTATION NOTE. Move the ['POST', 'admin-api/security/integrity', …] rule
     * BELOW the ['*', 'admin-api/security/**', 'security.view'] wildcard in
     * AdminCapabilities::RULES — RULES is first-match-wins — and the first
     * expectation is red with 'security.view'.
     */
});

it('draws the findings and its own limits on the screen it already had', function () {
    $screen = (string) file_get_contents(resource_path('views/admin/partials/security-screen.blade.php'));

    expect($screen)
        ->toContain('Integrity of the files packages installed')
        ->toContain("api('/security/integrity'")
        // THE HONEST LIMITS, printed beside the findings rather than buried in
        // a help text. The owner's ask was "no bot can inject code anywhere",
        // and a screen that let him believe this covered a file somebody ADDED
        // would be worse than no screen at all.
        ->toContain('cannot see a file that')
        ->toContain('It reports and stops there')
        // Nothing on this screen may measure layout: two tests in this repo
        // forbid the element-measuring APIs by name.
        ->not->toContain('getBoundingClientRect')
        ->not->toContain('offsetWidth')
        ->not->toContain('scrollWidth')
        // A grid or flex child's default min-width is auto, which is the defect
        // AdminScreenGridOverflowTest exists for. The new state row is a flex
        // container and its children carry it.
        ->toMatch('/\.sx-state\{[^}]*min-width:0/');
});

it('reads the report in the same number of queries however many findings there are', function () {
    /*
     * No N+1, measured rather than asserted. The integrity half adds ONE
     * LIMITed list query and nothing else — the last scan's summary is a cache
     * read, which is the whole reason it is not a table.
     */
    $module = app(SecurityModule::class);

    $seed = function (int $n) {
        for ($i = 0; $i < $n; $i++) {
            AuditEvent::create([
                'occurred_at' => Carbon::now()->subMinutes($i), 'last_seen_at' => Carbon::now(),
                'event' => IntegrityChecker::E_CHANGED, 'severity' => 'alert',
                'subject' => 'app/Probe'.$i.'.php', 'summary' => 'differs',
                'before' => 'sha '.$i, 'after' => 'sha '.($i + 1),
            ]);
        }
    };

    $count = function () use ($module) {
        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });
        $module->report();

        return $queries;
    };

    // A warm-up pass first: several caches here live for the life of the
    // PROCESS rather than the request, so the first report() pays for reads no
    // later one repeats.
    $seed(5);
    $module->report();
    $small = $count();

    $seed(120);
    $large = $count();

    expect($large)->toBe($small, "the report went {$small} -> {$large} queries as the findings grew");
});

it('agrees with the runner about where a packaged file lands on disk', function () {
    /*
     * ▲ THE ONE THAT WOULD HAVE BROKEN THE LIVE SERVER AND NOTHING ELSE.
     *
     * `public/` is a DIFFERENT DIRECTORY from the application root on this
     * install — bootstrap/app.php ends with
     * usePublicPath('/home/.../public_html/kbb-upgrade') — which is why
     * compiled assets under public/build/ do not exist in the app folder there.
     * UpdateRunner::targetFor() knows that; a checker that joined every
     * manifest path onto base_path() would hash nothing for any asset a package
     * ships and report the whole of public/ as MISSING, on the server only, on
     * a screen whose job is to tell the owner something is wrong.
     *
     * So the two are compared directly rather than trusted to agree.
     */
    $runner = new ReflectionMethod(UpdateRunner::class, 'targetFor');
    $runner->setAccessible(true);

    $checker = app(IntegrityChecker::class);
    $instance = app(UpdateRunner::class);

    foreach ([
        'app/Services/Thing.php',
        'public/build/assets/app.css',
        'public/index.php',
        'resources/views/x.blade.php',
        'database/migrations/2026_01_01_000000_x.php',
        'publicly-named-but-not-public.php',
    ] as $relative) {
        expect($checker->targetFor($relative))->toBe(
            $runner->invoke($instance, $relative),
            "IntegrityChecker and UpdateRunner disagree about where {$relative} lives"
        );
    }

    /*
     * MUTATION NOTE. Delete the `str_starts_with($relative, 'public/')` branch
     * from IntegrityChecker::targetFor() and this is red on the three public/
     * paths.
     */
});

/* ══════════════════════════════════ 5. the two things left open in round one ═══ */

it('records a module being switched off, which changed the shop and left no row', function () {
    /*
     * THE GAP THIS LANE NAMED IN ROUND ONE AND LEFT OPEN. `module_toggles` is a
     * table of its own and not a row in `settings`, so the Setting::saved hook
     * never saw it. Store → Modules is where `mega_menu`, `marketing_pixels`,
     * `cart_coupon_field` and `pay_ship_rules` are switched on and off — every
     * one of which changes what a visitor is served — and turning one off left
     * no record anywhere of who did it or when.
     */
    $admin = intAdmin('owner');
    $settings = app(SettingsService::class);

    $settings->setModule('kbb_probe_module', true);   // nobody signed in yet

    expect(AuditEvent::query()->where('event', SecurityModule::E_MODULE)->count())->toBe(0);

    $this->actingAs($admin, 'admin');

    $settings->setModule('kbb_probe_module', false);

    $row = AuditEvent::query()->where('event', SecurityModule::E_MODULE)->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->subject)->toBe('kbb_probe_module')
        ->and($row->actor_label)->toBe($admin->email)
        ->and($row->before)->toBe('on')
        ->and($row->after)->toBe('off')
        ->and($row->summary)->toContain('switched off');

    // And a save that changes nothing writes nothing: every module screen
    // posts its whole list, so without this one click would write forty rows.
    $settings->setModule('kbb_probe_module', false);

    expect(AuditEvent::query()->where('event', SecurityModule::E_MODULE)->count())->toBe(1);

    /*
     * MUTATION NOTE. Delete the ModuleToggle::saved hook from
     * SecurityModule::listen() and the first assertion is red with no row.
     * Delete only its `wasChanged('enabled')` guard and the last is red with
     * two.
     */
});

it('records a module being removed from the list', function () {
    $this->actingAs(intAdmin('owner'), 'admin');

    ModuleToggle::create(['module' => 'kbb_probe_gone', 'enabled' => true]);
    ModuleToggle::query()->where('module', 'kbb_probe_gone')->first()->delete();

    $row = AuditEvent::query()->where('event', SecurityModule::E_MODULE)->latest('id')->first();

    expect($row->summary)->toContain('removed from the list')
        ->and($row->before)->toBe('on')
        ->and($row->after)->toBeNull();
});

it('cannot grow the trail past its ceiling on a shop nobody opens the screen on', function () {
    /*
     * THE OTHER GAP FROM ROUND ONE, and the honest half of it.
     *
     * Retention runs when the screen is opened, because this host has NO CRON
     * AND NO QUEUE WORKER — that is still the only honest answer and it is now
     * written down as such in the doc rather than left as a silent property.
     * But "a shop nobody opens the screen on keeps its rows" is only safe until
     * you ask WHICH rows. Administrative rows need a signed-in admin, so they
     * are bounded by how much work a person does. Failed sign-ins are not: the
     * login throttle allows five a minute, which is 7,200 rows a day for as
     * long as somebody cares to keep trying, into a table nothing was trimming.
     *
     * So the count is now bounded on the WRITE path, independently of the
     * calendar and independently of anybody opening anything.
     */
    $module = app(SecurityModule::class);
    $module->save(['max_rows' => 1000]);

    // Straight to the ceiling's own sweep, so the test does not have to write
    // a thousand rows to prove the arithmetic.
    for ($i = 0; $i < 1200; $i++) {
        AuditEvent::create([
            'occurred_at' => Carbon::now()->subSeconds($i), 'last_seen_at' => Carbon::now(),
            'event' => SecurityModule::E_SIGNIN_FAILED, 'severity' => 'notice',
            'summary' => 'Failed sign-in '.$i,
        ]);
    }

    expect(AuditEvent::query()->count())->toBe(1200);

    $removed = $module->enforceCap();

    expect($removed)->toBe(200)
        ->and(AuditEvent::query()->count())->toBe(1000)
        // The NEWEST thousand, not the oldest: the ceiling drops the far end of
        // the trail, never the far end that is still being written.
        ->and(AuditEvent::query()->min('id'))->toBe(
            (int) AuditEvent::query()->max('id') - 999
        );

    /*
     * MUTATION NOTE. Change the delete in enforceCap() from `where('id','<=')`
     * to `where('id','>=')` and this is red with the newest thousand gone and
     * the oldest two hundred kept — which is the trail nobody wants.
     */
});

it('sweeps the ceiling as rows are written, with nobody opening anything', function () {
    /*
     * The half that matters, and the one prune() cannot do: it has to happen
     * WITHOUT the screen. record() fires the sweep on one write in
     * SecurityModule::CAP_EVERY, keyed on the row's own id — no counter to
     * keep, no static to leak into the next test in the process, and no extra
     * read on the other ninety-nine writes.
     */
    $module = app(SecurityModule::class);
    $module->save(['max_rows' => 1000]);

    $this->actingAs(intAdmin('owner'), 'admin');

    // Rows written the way a flood writes them, through record() and nothing
    // else. No screen is opened anywhere in this test.
    for ($i = 0; $i < 1150; $i++) {
        $module->record(SecurityModule::E_SIGNIN_FAILED, 'Failed sign-in '.$i, ['group' => 'signin']);
    }

    // At most CAP_EVERY - 1 rows of overshoot before the next sweep pulls it
    // back, which is the cost of not reading a count on every write.
    expect(AuditEvent::query()->count())
        ->toBeLessThanOrEqual(1000 + SecurityModule::CAP_EVERY - 1)
        ->and(AuditEvent::query()->count())->toBeLessThan(1150);

    /*
     * MUTATION NOTE. Remove the `% self::CAP_EVERY` sweep from record() and
     * this is red with all 1,150 rows — which is what the table did before this
     * round for as long as nobody opened Store → Security.
     */
});
