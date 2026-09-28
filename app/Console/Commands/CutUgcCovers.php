<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UgcVideo;
use App\Services\UgcDerivedFiles;
use App\Services\UgcMedia;
use App\Services\UgcPath;
use App\Services\UgcTranscoder;
use Illuminate\Console\Command;

/**
 * Cut the cover and the teaser for clips that have neither — from the CLI.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── WHY A COMMAND, WHEN THE WEB REQUEST ALREADY DOES THIS ───────────────────
 *
 * Because on the shop this was written for, the web request CANNOT. Its
 * PHP-FPM pool disables `proc_open`, so `Symfony\Component\Process\Process`
 * throws from its constructor and nothing can start ffmpeg. Read out of the
 * shop's own log, five times over seven hours:
 *
 *   LogicException: The Process class relies on proc_open, which is not
 *   available on your PHP installation.
 *     #0 UgcTranscoder.php(303): Process->__construct()
 *     #1 UgcTranscoder.php(224): UgcTranscoder->durationMs()
 *     #2 UgcVideoController.php(285): UgcTranscoder->derive()
 *
 * **The CLI on that same machine does not disable it** — `php -i | grep
 * disable_functions` over SSH answered "no value", which is what made the two
 * readings look contradictory until the SAPI difference was the answer. ffmpeg
 * itself is present and executable; only the web process is forbidden to start
 * it.
 *
 * So this exists to reach the covers through the door that IS open. It is not a
 * workaround for a bug in this application — the upload path is correct and,
 * since the fix that accompanies this, degrades honestly. It is the route to
 * the feature on a host configured this way, and it needs no server change at
 * all.
 *
 * ── IT IS ALSO THE BETTER PLACE FOR THIS WORK ───────────────────────────────
 *
 * Transcoding an 8MB clip inside the request that uploaded it was always the
 * wrong shape: it holds a web worker for the duration and it races
 * `max_execution_time`. A host that forbids it is forcing a correction. Run
 * this after an upload, or on a schedule if the host has cron.
 *
 * Run with no options it changes nothing that already has a cover.
 */
class CutUgcCovers extends Command
{
    protected $signature = 'ugc:cut-covers
        {--id=* : Only these clip ids. Repeatable.}
        {--limit=50 : How many clips to attempt in one run.}
        {--force : Re-cut clips that already have a cover, replacing it.}
        {--dry-run : List what would be cut and change nothing.}
        {--unattended : Running from the scheduler. A machine that cannot cut is a no-op, not a failure.}';

    protected $description = 'Cut the cover and 2.5s teaser for shoppable-video clips, from the CLI';

    public function handle(UgcTranscoder $transcoder, UgcDerivedFiles $derivedFiles): int
    {
        /*
         * ASKED BEFORE ANY WORK, and it is the whole value of running here: if
         * the CLI cannot start a program either, every clip below would fail
         * identically and the operator would watch fifty of them do it. The
         * blocker sentence is the transcoder's own, so this cannot drift from
         * what the admin screen says.
         */
        if (! $transcoder->available()) {
            /*
             * ── WHY THE SCHEDULER GETS A DIFFERENT ANSWER ──────────────────
             *
             * An operator who TYPES this wants to be told it cannot run, and
             * loudly: a silent exit 0 would leave him waiting for covers that
             * are never coming.
             *
             * Cron does not. This runs every minute; a shop whose host has no
             * ffmpeg would mail its owner a failure 1,440 times a day, and the
             * one reliable outcome of that is a mail filter that also hides
             * the failure he needed to see. "This machine cannot cut" is a
             * standing condition of the machine, not an event, and a standing
             * condition reported once a minute is noise.
             *
             * It still SAYS so -- at normal verbosity, in one line -- so
             * `schedule:run` run by hand tells the truth. Only the exit code
             * differs, and the exit code is the only part cron reads.
             */
            if ($this->option('unattended')) {
                $this->line('Nothing cut: '.(string) $transcoder->blocker(
                    $transcoder->canSpawn(), $transcoder->binary()
                ));

                return self::SUCCESS;
            }

            $this->error('This machine cannot cut anything.');
            $this->line('  '.(string) $transcoder->blocker($transcoder->canSpawn(), $transcoder->binary()));
            $this->newLine();
            $this->line('If the SHOP says the same thing but this command works, the two PHPs differ:');
            $this->line('the web is PHP-FPM and this is the CLI. That is the case this command is for.');

            return self::FAILURE;
        }

        $clips = $this->clips();

        if ($clips->isEmpty()) {
            $this->info($this->option('force')
                ? 'No clips with a video file to cut from.'
                : 'Nothing to do — every clip with a video already has a cover.');

            return self::SUCCESS;
        }

        $this->info($clips->count().' clip(s) to cut.');

        if ($this->option('dry-run')) {
            foreach ($clips as $clip) {
                $this->line(sprintf('  #%d  %s', $clip->id, (string) $clip->title));
            }

            $this->newLine();
            $this->comment('--dry-run: nothing was changed.');

            return self::SUCCESS;
        }

        $cut = 0;
        $failed = 0;
        $missing = 0;

        foreach ($clips as $clip) {
            $this->line(sprintf('#%d  %s', $clip->id, (string) $clip->title));

            /*
             * CLASSIFIED BEFORE THE ATTEMPT, for the report at the foot of this
             * method and nothing else — derive() makes this same check and its
             * answer is the one that counts. See the note below for why "the
             * file is not on disk" is the one failure worth separating out.
             */
            $stored = UgcPath::stored($clip->file_path);
            if ($stored === null || ! is_file(public_path(ltrim($stored, '/')))) {
                $missing++;
            }

            try {
                $derived = $transcoder->derive(
                    $clip,
                    remakePoster: (bool) $this->option('force'),
                    remakeTeaser: (bool) $this->option('force'),
                );
            } catch (\Throwable $e) {
                /*
                 * derive() is written not to throw and its runners catch
                 * \Throwable, so reaching here means something under them
                 * changed. One clip failing is not a reason to abandon the
                 * other forty-nine.
                 */
                $failed++;
                $this->error('   could not cut: '.$e->getMessage());

                continue;
            }

            $derivedFiles->apply($clip, $derived);

            if ($clip->isDirty()) {
                $clip->save();
                $cut++;
                $this->line('   <info>cut</info>  '
                    .($derived['poster'] !== null ? 'cover ' : '')
                    .($derived['teaser'] !== null ? 'teaser' : ''));
            } else {
                $failed++;
                $this->warn('   nothing was produced'
                    .($derived['notes'] !== [] ? ' — '.implode(' ', $derived['notes']) : ''));

                /*
                 * AND WHAT FFMPEG ITSELF SAID, under -v.
                 *
                 * The line above is this application's summary of the failure,
                 * and it is the same sentence whatever went wrong -- it cannot
                 * tell "the output directory is not writable" from "this file
                 * is not a video". ffmpeg knows, in one sentence, every time.
                 * UgcTranscoder::run() used to discard it; it keeps the last
                 * one now, and an operator at a terminal is exactly who should
                 * see it.
                 *
                 * Behind -v deliberately. The plain run is read by somebody who
                 * wants to know whether his clips got covers; a C library's
                 * diagnostics belong to the person who asked for detail.
                 */
                $said = $transcoder->lastProcessError();

                if ($said !== null && $this->output->isVerbose()) {
                    foreach (explode("\n", $said) as $saidLine) {
                        $this->line('     <comment>'.$saidLine.'</comment>');
                    }
                }
            }
        }

        $this->newLine();
        $this->info(sprintf('%d cut, %d could not be.', $cut, $failed));

        /*
         * ── THE ONE WRONG ANSWER THIS COMMAND CAN GIVE ─────────────────────
         *
         * public_path() is not `<app>/public` on this shop. bootstrap/app.php
         * ends in usePublicPath(), reading $KBB_PUBLIC_PATH first, then
         * bootstrap/public-path.php, then a hardcoded fallback that belongs to
         * a DIFFERENT, older server. That chain is evaluated while the
         * application is being built, BEFORE .env is read — so a KBB_PUBLIC_PATH
         * set in the FPM pool (`env[KBB_PUBLIC_PATH] = ...`) is seen by the web
         * and NOT by this command, and a value in .env is seen by neither.
         *
         * When that happens every clip here reports its file missing, which is
         * true of the directory this process is looking in and false of the
         * shop. An operator reading "the stored clip is missing" would go and
         * look for lost uploads that were never lost. So when nothing could be
         * found, say WHERE this process looked: a path that is not the web root
         * is the answer, visible in one line.
         *
         * Only when the misses are ALL of them — one clip whose file really did
         * go missing is not a configuration problem, and saying so there would
         * be noise on an otherwise good run.
         */
        if ($missing > 0 && $missing === $clips->count()) {
            $this->newLine();
            $this->warn('Every clip\'s file was missing, so check WHERE this looked before hunting for them:');
            $this->line('  '.public_path(UgcMedia::DIR));
            $this->line('If that is not inside your web root, this command resolved a different public');
            $this->line('path than the shop does. bootstrap/public-path.php is the fix — it is a FILE, so');
            $this->line('the web and the command line read the same value. $KBB_PUBLIC_PATH set in an FPM');
            $this->line('pool, or in .env, reaches one of them at most.');
        }

        /*
         * A non-zero exit ONLY when nothing worked at all. A run that cut
         * nineteen of twenty succeeded, and a cron that mails on failure should
         * not be woken for the twentieth.
         */
        return $cut === 0 && $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, UgcVideo> */
    private function clips()
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));

        return UgcVideo::query()
            ->whereNotNull('file_path')
            ->where('file_path', '!=', '')
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            /*
             * Without --force, any clip MISSING EITHER DERIVATIVE. That is what
             * makes this safe to run twice and safe to schedule: a clip that
             * has both is skipped, so a second run finds nothing rather than
             * re-cutting the shop.
             *
             * ── IT USED TO ASK ABOUT THE POSTER ALONE, AND THAT STRANDED THE
             *    TEASER PERMANENTLY ──────────────────────────────────────────
             *
             * The two cuts are separate ffmpeg runs and the teaser is the one
             * that fails: it encodes 2.5 seconds of H.264 where the poster
             * grabs one frame. So the real sequence on a server where the
             * teaser cannot be made was:
             *
             *   minute 1   no poster -> SELECTED. Poster written. Teaser fails.
             *   minute 2   HAS a poster -> not selected.
             *   for ever   not selected.
             *
             * One attempt at the teaser, ever, and the retry the every-minute
             * schedule exists to provide never came. Measured on the owner's
             * live shop on 28 September 2026: `poster-20260928-203448.jpg` in
             * the uploads directory, no `teaser-*.mp4` beside it, the directory
             * mtime three minutes later than the poster (the failed teaser
             * being cleaned up), and nothing new in the half hour after that
             * from a cron running sixty times. The rail then loops the FULL
             * clip instead, which is the fallback working exactly as designed
             * and costing about 11 MB a tile on a phone instead of 97 KB.
             *
             * A clip that can never produce a teaser is now retried each run.
             * That is bounded by --limit (20 on the schedule) and is the right
             * trade against a silent permanent gap: a retry costs one ffmpeg
             * start, and the alternative cost the shop every teaser it has.
             */
            ->when(! $this->option('force'), fn ($q) => $q->where(function ($q) {
                $q->whereNull('poster_path')->orWhere('poster_path', '')
                    ->orWhereNull('teaser_path')->orWhere('teaser_path', '');
            }))
            // Oldest first: the backlog before the clip uploaded a minute ago.
            // `id` after it because this query is SLICED — StableOrderingTest
            // refuses a limited query whose last ORDER BY key can tie.
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, min(500, (int) $this->option('limit'))))
            ->get();
    }
}
