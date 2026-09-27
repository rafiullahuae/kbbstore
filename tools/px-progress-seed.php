<?php
/*
 * Drive a REAL import in the preview database, so the progress page has real
 * numbers to render for the Lane PX screenshots.
 *
 *   php tools/px-progress-seed.php <export-dir> partial|finish
 *
 * `partial` puts the export in the workspace, starts a live run and takes ONE
 * small step, so the page is photographed with an import genuinely in flight.
 * `finish` steps the same run to completion.
 *
 * It does not fake any state. The whole value of the picture is that every
 * number in it came out of the checkpoints the importer really wrote.
 */
require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$self, $from, $phase] = [...$argv, null, null];

if (! is_dir((string) $from)) {
    fwrite(STDERR, "usage: px-progress-seed.php <export-dir> partial|finish\n");
    exit(1);
}

$workspace = new App\Services\ImportConsole\ImportWorkspace;
$driver = new App\Services\ImportConsole\ImportDriver;

if ($phase === 'partial') {
    $directory = $workspace->directory();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    foreach (glob($from.'/*') ?: [] as $file) {
        copy($file, $directory.'/'.basename($file));
    }

    /*
     * confirm_duplicate, because re-photographing the SAME fixture is exactly
     * what this tool does and ImportDriver rightly refuses a second live import
     * of byte-identical files without being told. Being refused there is not the
     * check that matters here -- it fires only while the previous run's archive
     * record survives, so it cannot be relied on -- and it made the tool
     * un-re-runnable while hiding the state that actually goes wrong. The guard
     * below is the check, and it asks the run itself.
     */
    /*
     * restart, AND THAT IS THE HALF THAT MATTERS. Without it `start()` RESUMES:
     * `import_checkpoints` is keyed on one fixed run_key, so a second run over an
     * export the first one finished has nothing left to do, and the page then
     * shows -- correctly -- every file finished and a green reconciliation. The
     * page is not lying there; the SCREENSHOT is, because it is captioned
     * "mid-run". This tool produced four identical finished pictures that way,
     * with two of them presented as evidence for a card whose whole subject is
     * refusing to conclude.
     *
     * confirm_duplicate because re-photographing the same fixture is exactly what
     * this tool does, and ImportDriver rightly refuses a second live import of
     * byte-identical files unless told.
     */
    $driver->start('live', [
        'adopt_by_slug' => true, 'force' => true, 'confirm_duplicate' => true, 'restart' => true,
    ]);

    // Two rows: enough that several files are untouched and the bar is honestly
    // part-way, which is the state the reconciliation must refuse to conclude on.
    $driver->step(2);

    /*
     * ── AND IT HAS TO BE PART-DONE, OR SAY SO AND STOP ──────────────────────
     *
     * This produced FOUR IDENTICAL FINISHED SCREENSHOTS once, in a preview
     * database that already held a completed run: start(force) reused it, step(2)
     * had nothing left to step, and the "mid-run" pictures showed a green
     * "everything arrived" card. Two of the four deliverables were then evidence
     * for the opposite of what they were captioned -- and nothing said so, because
     * the script exited 0 either way.
     *
     * The mid-run picture's whole subject is a reconciliation that REFUSES to
     * conclude. A tool that can quietly photograph the wrong state is worse than
     * one that cannot run: boot the preview fresh and try again.
     */
    /*
     * ── AND THE GUARD ASKS THE PICTURE'S OWN QUESTION, NOT THE RUN'S ────────
     *
     * `$driver->run()->status === 'running'` was the obvious check and it is the
     * wrong one: a resumed run with nothing left to do is still 'running', so it
     * passes while the page shows a concluded reconciliation. The thing the
     * mid-run screenshot exists to show is `reconciliation.ready === false` --
     * the card refusing to conclude -- so that is what is asserted, straight off
     * the same ImportChain the page itself polls.
     *
     * A tool that can quietly photograph the opposite of its caption is worse
     * than one that will not run.
     */
    $chain = app(App\Services\ImportConsole\ImportChain::class);
    $reconciliation = $chain->progress()['reconciliation'] ?? ['ready' => true];

    if (($reconciliation['ready'] ?? true) !== false) {
        fwrite(STDERR, "the reconciliation already reads as CONCLUDED, so the mid-run screenshots would show "
            ."a finished import under a mid-run caption:\n  "
            .($reconciliation['sentence'] ?? '(no sentence)')."\n"
            ."Boot a clean preview with tools/px-progress-preview.sh and try again.\n");
        exit(2);
    }

    echo "seeded a part-done run\n";
    exit(0);
}

for ($i = 0; $i < 400; $i++) {
    $run = $driver->run();

    if ($run === null || $run->status !== 'running') {
        break;
    }

    $driver->step(500);
}

echo "run is now: ".($driver->run()->status ?? 'gone')."\n";
