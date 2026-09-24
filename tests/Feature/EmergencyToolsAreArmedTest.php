<?php

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * THE DEFECT, ON THE SHOP, 24 SEPTEMBER 2026
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Both emergency tools shipped useless, in opposite directions, and nobody
 * found out until the day they were needed.
 *
 * kbb-recover.php SHIPPED DEAD. Its token was a placeholder and it refused to
 * run until somebody opened the file and edited it. Nobody ever did. So when
 * the storefront was 500ing and the updater could not install its own fix --
 * the exact situation it was written for -- the recovery script answered 503
 * and the recovery was done by hand in a database console.
 *
 * kbb-doctor.php SHIPPED OPEN, which is worse and was not noticed at all.
 * It had the same placeholder constant, but -- unlike kbb-recover.php, which
 * explicitly refused to run while the placeholder was still in place -- it had
 * NO SUCH GUARD. It simply compared the request's token against the
 * placeholder, so the placeholder WAS the live token. That string was committed
 * to this repository and the file's own docblock published the full URL with
 * the token already in it. Anyone who had ever seen the file could read the
 * live shop's log tails and stack traces, enumerate every table in its
 * database, list its web root and clear its caches.
 *
 * MUTATION, run: put a DOCTOR_TOKEN constant holding the old placeholder, and a
 * hash_equals gate, back into kbb-doctor.php in place of its SAPI check. Two
 * tests go green-to-red: `it refuses every web request for the doctor` and
 * `it carries no token constant that can ship as its own password`.
 *
 * SECOND MUTATION, run: put a placeholder RECOVER_TOKEN and the 503 guard back
 * at the top of kbb-recover.php, which is exactly how it shipped. FIVE tests go
 * red, starting with `it lists the backups from the shell with nothing edited`
 * -- the whole defect, reproduced: the recovery tool answers 503 to the person
 * trying to recover.
 *
 * ---------------------------------------------------------------------------
 * These tools cannot travel in an update package: BuildPackage excludes
 * public-web-root/ and UpdateGuard lists kbb-recover.php as never writable,
 * both deliberately, so that a bad update cannot damage its own escape route.
 * `it cannot be shipped by a package, so the fix has to be uploaded` pins that
 * -- it is the reason the report has to tell the owner to act over SSH.
 * ═══════════════════════════════════════════════════════════════════════════
 */

use App\Services\Update\UpdateGuard;
use Symfony\Component\Process\Process;

function emergencyTool(string $name): string
{
    return base_path('public-web-root/'.$name);
}

/**
 * A throwaway web root with an application beside it, laid out the way the
 * live server is, so the scripts can be run for real rather than read.
 *
 * @return array{web: string, app: string}
 */
function emergencyLayout(): array
{
    $root = sys_get_temp_dir().'/kbb-emergency-'.bin2hex(random_bytes(6));

    $web = $root.'/public_html';
    $app = $root.'/private_html/kbb-app';

    foreach ([$web, $app.'/bootstrap/cache', $app.'/storage/framework/views', $app.'/storage/logs'] as $dir) {
        @mkdir($dir, 0775, true);
    }

    foreach (['kbb-recover.php', 'kbb-doctor.php'] as $tool) {
        copy(emergencyTool($tool), $web.'/'.$tool);
    }

    return ['web' => $web, 'app' => $app];
}

/** One update backup holding one replaced file. */
function emergencyBackup(string $app, string $id, string $relative, string $restoredContents): void
{
    $dir = $app.'/storage/app/updates/backups/'.$id;

    @mkdir($dir.'/files/'.dirname($relative), 0775, true);
    file_put_contents($dir.'/files/'.$relative, $restoredContents);
    file_put_contents($dir.'/manifest.json', json_encode([
        'created_at' => '2026-09-24 11:00:00',
        'replaced' => [$relative],
        'added' => [],
    ]));
}

function runTool(string $web, string $tool, array $arguments = []): Process
{
    $process = new Process([PHP_BINARY, $tool, ...$arguments], $web);
    $process->run();

    return $process;
}

/* ══════════════════════════ kbb-recover.php: alive when it is needed ══════ */

it('lists the backups from the shell with nothing edited', function () {
    /*
     * THE DEFECT. The old file exited 503 here -- "Recovery is disabled. Edit
     * kbb-recover.php and set RECOVER_TOKEN" -- on a freshly uploaded copy,
     * which is every copy, because editing it was a step nobody performed.
     * The shell is the auth now: reaching it means holding the server's own
     * credentials, which is a higher bar than any string in a URL.
     */
    $layout = emergencyLayout();
    emergencyBackup($layout['app'], '20260924110000', 'app/Services/Thing.php', "<?php // the good one\n");

    $process = runTool($layout['web'], 'kbb-recover.php');

    expect($process->getOutput())
        ->toContain('20260924110000')
        ->toContain('1 files')
        ->not->toContain('Recovery is disabled')
        ->not->toContain('Not found');
});

it('actually restores a file from the shell', function () {
    $layout = emergencyLayout();
    emergencyBackup($layout['app'], '20260924110000', 'app/Services/Thing.php', "<?php // the good one\n");

    // What a bad update left behind.
    @mkdir($layout['app'].'/app/Services', 0775, true);
    file_put_contents($layout['app'].'/app/Services/Thing.php', "<?php // the broken one\n");
    file_put_contents($layout['app'].'/storage/framework/down', '{"retry":60}');
    file_put_contents($layout['app'].'/bootstrap/cache/routes-v7.php', '<?php return [];');

    $process = runTool($layout['web'], 'kbb-recover.php', ['20260924110000']);

    expect($process->getOutput())->toContain('Restored backup 20260924110000')
        ->and(file_get_contents($layout['app'].'/app/Services/Thing.php'))->toContain('the good one')
        // A restore that leaves the site in maintenance mode behind a stale
        // compiled route cache has not restored anything the shopper can see.
        ->and(is_file($layout['app'].'/storage/framework/down'))->toBeFalse()
        ->and(is_file($layout['app'].'/bootstrap/cache/routes-v7.php'))->toBeFalse();
});

it('reports whether the web door is open, so nobody has to guess', function () {
    $layout = emergencyLayout();

    expect(runTool($layout['web'], 'kbb-recover.php', ['--status'])->getOutput())
        ->toContain('closed');

    file_put_contents($layout['app'].'/.env', "APP_ENV=production\nKBB_RECOVER_TOKEN=".str_repeat('a', 32)."\n");

    expect(runTool($layout['web'], 'kbb-recover.php', ['--status'])->getOutput())
        ->toContain('OPEN');
});

it('carries no token of its own, so there is nothing to edit and nothing to leak', function () {
    $source = file_get_contents(emergencyTool('kbb-recover.php'));

    expect($source)
        ->not->toContain('REDACTED-ROTATE-AND-SET-YOUR-OWN')
        ->not->toContain('const RECOVER_TOKEN')
        // The web door's token lives in .env, where a token belongs, and where
        // setting one does not mean editing code during an incident.
        ->toContain('KBB_RECOVER_TOKEN');
});

it('keeps the web door shut by default and silent when shut', function () {
    $source = file_get_contents(emergencyTool('kbb-recover.php'));

    expect($source)
        ->toContain('hash_equals($webToken,')
        ->toContain('strlen($webToken) >= 24')
        // 404, not the old 503 with "set a token" on it: a refusal that
        // explains itself confirms to a scanner that the file is here.
        ->toContain("http_response_code(404)")
        ->not->toContain('Recovery is disabled');
});

/* ══════════════════════════ kbb-doctor.php: shut, not ajar ════════════════ */

it('refuses every web request for the doctor', function () {
    $source = file_get_contents(emergencyTool('kbb-doctor.php'));

    // The guard is the FIRST thing that runs, before the app root is located,
    // before .env is parsed, before anything is printed.
    $firstStatement = strpos($source, "if (PHP_SAPI !== 'cli')");

    expect($firstStatement)->not->toBeFalse('the doctor no longer refuses web requests outright');

    expect(substr($source, $firstStatement))->toContain('http_response_code(404)');

    // And no gate that a leaked or guessed string can open.
    expect($source)->not->toContain('hash_equals');
});

it('carries no token constant that can ship as its own password', function () {
    /*
     * The live defect: DOCTOR_TOKEN was the placeholder, there was no guard
     * refusing to run while it still was, and the docblock printed the URL
     * with that token in it. The file was open to anyone who had read it.
     */
    $source = file_get_contents(emergencyTool('kbb-doctor.php'));

    expect($source)
        ->not->toContain('DOCTOR_TOKEN')
        ->not->toContain('REDACTED-ROTATE-AND-SET-YOUR-OWN')
        ->not->toContain('?token=');
});

it('still prints a report from the shell', function () {
    // Shut on the web is only acceptable if it is alive somewhere. It is.
    $layout = emergencyLayout();
    file_put_contents($layout['app'].'/.env', "APP_ENV=production\nAPP_KEY=base64:x\n");
    file_put_contents($layout['app'].'/storage/logs/laravel.log', "[2026-09-24 11:00:00] production.ERROR: Class App\\Services\\VariantPricing not found\n");
    emergencyBackup($layout['app'], '20260924110000', 'app/Services/Thing.php', "<?php\n");

    $output = runTool($layout['web'], 'kbb-doctor.php')->getOutput();

    expect($output)
        ->toContain('KBB Doctor')
        ->toContain('STATE')
        ->toContain('VariantPricing')            // the errors section
        ->toContain('20260924110000')            // the backups section
        ->toContain('kbb-recover.php');          // the web root listing
});

it('does not print secrets even into a shell it trusts', function () {
    // Not a security boundary — the shell can read .env directly. It is so
    // that a report pasted into a chat or an issue does not carry the
    // database password with it.
    $layout = emergencyLayout();
    file_put_contents(
        $layout['app'].'/.env',
        "APP_KEY=base64:supersecretkeyvalue\nDB_PASSWORD=hunter2\nKBB_HEALTH_TOKEN=tokenvalue123\n"
    );

    $output = runTool($layout['web'], 'kbb-doctor.php')->getOutput();

    expect($output)
        ->not->toContain('hunter2')
        ->not->toContain('supersecretkeyvalue')
        ->not->toContain('tokenvalue123')
        // but it still says WHETHER they are set, which is the diagnosis.
        ->toContain('Health token');
});

/* ══════════════════════════ and why this has to be done by hand ═══════════ */

it('cannot be shipped by a package, so the fix has to be uploaded', function () {
    /*
     * Deliberate, and the reason the report has to tell the owner to act over
     * SSH rather than wait for a release: a bad update must not be able to
     * damage its own escape route. The consequence is that the OLD, OPEN
     * kbb-doctor.php stays live on the server until somebody removes or
     * replaces it there.
     */
    $guard = new UpdateGuard();

    expect($guard->checkPath('public/kbb-recover.php'))->not->toBeNull()
        ->and($guard->checkPath('public-web-root/kbb-doctor.php'))->not->toBeNull()
        ->and($guard->checkPath('public-web-root/kbb-recover.php'))->not->toBeNull();

    expect(file_get_contents(app_path('Console/Commands/BuildPackage.php')))
        ->toContain("'public-web-root/'");
});

it('tells the owner, in the folder they will open, what is still live', function () {
    // Rule 3: say where it sits. The one action that cannot be automated is
    // the one that has to be written down where it will be found.
    $readme = file_get_contents(base_path('public-web-root/READ-ME.txt'));

    expect($readme)
        ->toContain('rm public_html/kbb-doctor.php')
        ->toContain('KBB_RECOVER_TOKEN')
        ->toContain('php kbb-recover.php')
        // The old READ-ME published a live recovery token in plain text and
        // pointed at the retired Hostinger box.
        ->not->toContain('b2708bbeb964a31445b33634e3916b3875eba7a792094c23');
});
