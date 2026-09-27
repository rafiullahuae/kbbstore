<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UgcVideo;
use App\Services\UgcDerivedFiles;
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
        {--dry-run : List what would be cut and change nothing.}';

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

        foreach ($clips as $clip) {
            $this->line(sprintf('#%d  %s', $clip->id, (string) $clip->title));

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
            }
        }

        $this->newLine();
        $this->info(sprintf('%d cut, %d could not be.', $cut, $failed));

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
             * Without --force, only clips that have no cover. That is what
             * makes this safe to run twice, and safe to put on a schedule: a
             * second run finds nothing rather than re-cutting the shop.
             */
            ->when(! $this->option('force'), fn ($q) => $q->where(function ($q) {
                $q->whereNull('poster_path')->orWhere('poster_path', '');
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
