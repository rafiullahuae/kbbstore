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

/*
| ── CAPTURE THE TAMARA ORDERS THAT HAVE ALREADY SHIPPED ─────────────────────
|
| The owner turned this on in as many words on 28 September 2026, and the
| migration 2027_03_21_000000_tamara_auto_capture_on_by_default writes the
| switch. Without a schedule that switch does nothing: TamaraCaptureSweep is
| only reached by this command, and until now nothing ran it, so an install
| with auto-capture On would still have been waiting for somebody to type the
| command over SSH.
|
| WHY HOURLY AND NOT EVERY MINUTE. This one makes network calls to Tamara and
| moves money, which the cover cutter above does neither of. The thing it is
| racing is Tamara voiding an authorisation after ~180 days; an hour of latency
| against a 180-day deadline is nothing, and an hourly run keeps the shop's
| API traffic to Tamara proportionate to what it is doing.
|
| THE COMMAND IS SAFE TO RUN WITH NOTHING TO DO, which is what it does almost
| every hour: `run()` returns early with a reason when the gateway is not
| configured or the switch is off, and the candidate query is one indexed
| SELECT bounded by `--limit`. `--minutes` defaults to 30, so an operator who
| marks the wrong order shipped has half an hour to undo it before this takes
| the money.
|
| withoutOverlapping, because a slow Tamara is the case where two runs would
| otherwise ask about the same orders; runInBackground, because the scheduler
| waits for a foreground command and this one talks to the network.
*/
Schedule::command('payments:tamara-capture')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground();

/*
| ── "COMPLETE YOUR ORDER" REMINDERS (Lane RL) ───────────────────────────────
|
| 30 minutes and 24 hours after an order nobody has paid for. Every minute, so
| the 30-minute one is never more than a minute late. With no cron line, the
| same sweep runs after ordinary page requests instead
| (App\Services\Mail\OrderReminderTick), and each send is claimed in
| `order_emails`, so both running is harmless. Costs two indexed queries a
| minute when nothing is due.
*/
Schedule::command('kbb:order-reminders')
    ->everyMinute()
    ->withoutOverlapping(5);

/*
| ── MARKETING EMAILS: SCHEDULED SENDS (Lane MK) ─────────────────────────────
|
| Growth & Marketing → Marketing Emails. Starts every scheduled campaign that
| has come due — once, because CampaignSender::start() flips the status with a
| conditional UPDATE — and sends the next batch of every campaign that is
| sending, within the per-minute rate and the daily cap. Costs two indexed
| queries a minute when nothing is due.
|
| Driver A (the admin's open tab on Review & send) does the same steps without
| this line; with it, a campaign keeps going after the tab is closed, and a
| scheduled one starts on time. The Campaigns screen notices when no tick has
| been seen for ten minutes and shows the cron line above.
|
| NEVER on a shopper's request: unlike the reminders above there is no
| page-view fallback, by design (docs/EMAILS-PLAN.md §4.5).
*/
Schedule::command('kbb:campaigns-step')
    ->everyMinute()
    ->withoutOverlapping(5);

/*
|--------------------------------------------------------------------------
| Cart Tracking retention (Lane CT)
|--------------------------------------------------------------------------
|
| Deletes cart events older than Growth & Marketing → Cart Tracking →
| Settings → "Keep cart events for" (default 180 days), counting them into the
| all-time product totals first. Carts themselves are kept. Without a cron
| line, App\Services\CartTracking\CartTrackingTick does the same after an
| ordinary response at most every six hours; both touch one marker.
*/
Schedule::command('kbb:cart-tracking-prune')
    ->dailyAt('03:17')
    ->withoutOverlapping(30);
