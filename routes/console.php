<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Wired from bootstrap/app.php (`commands: __DIR__.'/../routes/console.php'`).
|
| ── ONE CRON LINE DRIVES EVERYTHING BELOW ───────────────────────────────────
|
| Laravel's scheduler is not a daemon. It needs exactly one crontab entry, and
| every schedule in this file is then decided inside PHP rather than in cron:
|
|     * * * * * cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app \
|               && php artisan schedule:run >> /dev/null 2>&1
|
| On Cloudways that is Application Settings → Cron Job Management → Add New
| Cron. docs/SERVER-PROC-OPEN.md §5 carries the walkthrough.
|
| Adding a second crontab line per command is the mistake this comment exists
| to prevent: the schedule below would then run twice, and `withoutOverlapping`
| is a lock, not a licence to double the work.
*/

/*
| ── CUT THE COVERS THE BROWSER ON THIS HOST CANNOT CUT ──────────────────────
|
| THIS IS THE WHOLE REASON THE SCHEDULE EXISTS, and it turns on one fact that
| was measured on the owner's own server rather than assumed: `php -i` over SSH
| answers `disable_functions => no value`, while the PHP-FPM pool serving the
| shop has proc_open switched off. ffmpeg is installed and runnable there — the
| shop's log proves it, because `new Process` was REACHED, which only happens
| after the binary has been found on disk.
|
| So the web request genuinely cannot start ffmpeg and THIS PROCESS CAN. A
| cover that could not be cut at upload time is cut here, within a minute,
| with no change to the server at all.
|
| Every minute, not every five: an operator who uploads a clip and switches to
| the Details step should find the cover waiting when he gets back. The run
| costs one indexed query when there is nothing to do — `whereNull(poster_path)`
| over a table with a handful of rows — so a minute is not expensive, and the
| lock below means a long transcode simply holds the next tick off.
|
| --limit=20 rather than the default 50: this shares a machine with the shop,
| and twenty is far more than a minute of hand-uploading can produce while
| still bounding the worst case after a bulk import.
|
| --unattended turns "this machine cannot cut" from a failure into a no-op, so
| a host without ffmpeg does not mail its owner 1,440 times a day. The command's
| own comment carries that argument.
*/
Schedule::command('ugc:cut-covers --limit=20 --unattended')
    ->everyMinute()
    // Ten minutes is the lock's EXPIRY, not a timeout on the work: it is what
    // releases the lock if this process is killed mid-transcode, and without
    // it a hard kill would stop covers being cut until somebody noticed.
    ->withoutOverlapping(10)
    // The scheduler waits for a foreground command, and a 30-second transcode
    // would hold `schedule:run` — and therefore every later entry in this file
    // — for those 30 seconds.
    ->runInBackground();
