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

    $driver->start('live', ['adopt_by_slug' => true, 'force' => true]);

    // Two rows: enough that several files are untouched and the bar is honestly
    // part-way, which is the state the reconciliation must refuse to conclude on.
    $driver->step(2);

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
