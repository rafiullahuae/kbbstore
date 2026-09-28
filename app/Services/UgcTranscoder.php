<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UgcVideo;
use App\Support\MediaRegistrar;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * The derivative cutter — and the answer to "what if it cannot run".
 *
 * ── THE QUESTION NOBODY CAN ANSWER FROM HERE ────────────────────────────────
 *
 * docs/UGC-VIDEO-PLAN.md §8 question 4 is *does `ffmpeg` exist on that
 * Cloudways box*, and it is still open: it is a ten-second `which ffmpeg` over
 * SSH that nobody has run, and Cloudways managed hosting gives no root, so it
 * may not be installable if it is absent. §3.4 says plainly that nobody should
 * design the resolver around ffmpeg until somebody has run that command.
 *
 * SO THIS IS DESIGNED FOR BOTH, and neither is the error case:
 *
 *   ffmpeg present  the owner uploads ONE file. The poster and the 2.5s teaser
 *                   are cut from it, on the request that uploaded it. He is
 *                   never asked for a second video — §0b.1.
 *   ffmpeg absent   available() answers false, the admin screen says so in one
 *                   sentence, and the two derivative inputs become uploads of
 *                   their own. A poster is required; a teaser is not, and a
 *                   library with no teasers is the poster-only rail at ~22 KB a
 *                   tile, which is what the previews already draw under
 *                   Save-Data.
 *
 * The second is not a degraded mode with a warning triangle on it. It is a
 * supported way to run this feature, and it is the way it runs until somebody
 * types eight characters into an SSH session.
 *
 * ── IN THE REQUEST, BECAUSE THERE IS NOWHERE ELSE ───────────────────────────
 *
 * This host has no cron and no queue worker — docs/LC-SECURITY-MODULE.md says
 * it in as many words for retention, and ProductVisibility sets the same
 * precedent by comparing against now() instead of scheduling. So a background
 * transcode job would be a job that never runs. It happens on the upload
 * request, which is already authenticated, owner-adjacent and off every hot
 * path, exactly as MediaUploadController makes its phone-sized copies there and
 * for the same stated reason: THIS IS THE ONLY MOMENT A COPY CAN CHEAPLY BE
 * MADE.
 *
 * It is bounded by a timeout and it never fails the upload. The file is already
 * written and already being served; a failed derivative costs bytes, not a
 * video, and the screen reports which way it went instead of leaving it silent.
 *
 * ── NO SHELL, EVER ──────────────────────────────────────────────────────────
 *
 * Symfony's Process with an ARGUMENT ARRAY, which execs the binary directly —
 * there is no shell to quote for, so no filename, brand or operator string can
 * become an argument boundary. The binary itself is never taken from a request:
 * it is KBB_FFMPEG from the environment or one of four fixed absolute paths,
 * and each is checked with is_file() and is_executable() before it is run.
 *
 * ── WHY THIS CLASS IS NOT `final` ANY MORE ──────────────────────────────────
 *
 * UgcUpload500Test extends it to make derive() throw, which is the only way to
 * pin the property this file now turns on: THAT A FAILING TRANSCODE CANNOT FAIL
 * AN UPLOAD. The precedent is ServerMailTransport, which ServerMailDefaultTest
 * extends for the same reason. Nothing in the application subclasses it and the
 * container is not rebound outside that test.
 *
 * ── AND WHY IT COULD FAIL AN UPLOAD, WHICH IS THE DEFECT THIS ROUND ─────────
 *
 * `new Process($command)` sat OUTSIDE the try that guards `run()`, and its
 * constructor throws before any check in this class is reached:
 *
 *     if (!\function_exists('proc_open')) {
 *         throw new LogicException('The Process class relies on proc_open, ...');
 *     }
 *
 * On a host whose `disable_functions` carries `proc_open` — an ordinary shape
 * for managed hosting — ffmpeg is still a real executable file, so binary()
 * answered with a path and available() answered TRUE. The screen then promised
 * a cover this server could never cut, and the first clip upload threw
 * Symfony's LogicException out of derive(), straight past a
 * `catch (ProcessException)` that cannot see it, and into Laravel's handler as a
 * bare "Server Error" — AFTER the row had already been saved. Measured, not
 * guessed: run()'s eight lines under `php -d disable_functions=proc_open`
 * reproduce it exactly, and UgcUpload500Test runs that measurement as a test.
 *
 * Two independent fixes, because either alone leaves a hole:
 *
 *   canSpawn()   is now part of the question available() answers, so a box that
 *                cannot start a program says so BEFORE anything is uploaded and
 *                never constructs a Process at all. That is the fix the owner's
 *                server needs, and it is what turns his 500 into the
 *                already-supported "uploaded, no cover" state.
 *   the guard    covers construction as well as run(), and catches \Throwable
 *                rather than ProcessException, so nothing else out of that layer
 *                — a ValueError from proc_open(), an Error from a disabled
 *                function — can cost anybody a clip again.
 */
class UgcTranscoder
{
    /**
     * Where a managed host puts it. Absolute, fixed, and never joined with
     * anything from a request — `which` is not consulted, because `which`
     * reads $PATH and $PATH is environment this application does not own.
     */
    private const CANDIDATES = [
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        '/opt/homebrew/bin/ffmpeg',
        '/snap/bin/ffmpeg',
    ];

    /**
     * Seconds. A 15-second clip at 720x1280 is a couple of seconds of work; a
     * minute is generous and is short enough that a pathological input cannot
     * hold a PHP-FPM worker on a shared plan.
     */
    private const TIMEOUT = 60;

    /**
     * Seconds of the request's OWN budget left untouched around a run.
     *
     * ── THE CANDIDATE THIS BOUNDS, AND WHAT WAS MEASURED ABOUT IT ───────────
     *
     * A fatal from `max_execution_time` is not a catchable exception, so no
     * try/catch anywhere in this file could save an upload from it. On THIS
     * container it does not fire during a child process at all — measured:
     * `set_time_limit(2)` with a `sleep 8` child through Symfony Process, and
     * the script lived 8.01s and shut down with no error, because a non-ZTS
     * Linux build without `--enable-zend-max-execution-timers` measures CPU time
     * and a sleeping child spends none of it.
     *
     * That is NOT true of every build. Where the timers extension IS compiled in
     * the same limit is wall clock, and there a 60-second ffmpeg under a
     * 30-second limit is a fatal that costs the upload. So the process timeout is
     * the SMALLER of TIMEOUT and what PHP has left, less this margin, and when
     * there is not even a floor's worth left nothing is started and the clip is
     * returned with a note. timeoutSeconds() is a pure function so both arms are
     * asserted without a clock anywhere near them.
     */
    private const TIMEOUT_MARGIN = 5;

    /** Below this there is no point starting ffmpeg at all. */
    private const TIMEOUT_FLOOR = 8;

    /**
     * What an operator is told when this server cannot cut anything, per reason.
     *
     * CONSTANTS, and the two are DIFFERENT SENTENCES on purpose. "There is no
     * cover" has two completely different remedies — install ffmpeg, or stop
     * PHP refusing to start programs — and the owner was previously told neither
     * and could not tell which box he was on. The first keeps the words
     * "no ffmpeg" verbatim because UgcTranscoderTest reads for them.
     */
    public const NOTE_NO_FFMPEG = 'This server has no ffmpeg, so the poster and the teaser cannot be cut here. '
        .'Upload a poster image instead — the video can be published with a poster and no teaser.';

    public const NOTE_NO_SPAWN = 'ffmpeg is installed on this server but PHP is not allowed to start it '
        .'(proc_open is switched off in this server’s PHP configuration), so the poster and the teaser '
        .'cannot be cut here. Upload a poster image instead — the video can be published with a poster '
        .'and no teaser. Nothing is wrong with the clip, and re-uploading it will not change this.';

    public const NOTE_NO_TIME = 'There was not enough of this request’s time budget left to cut a poster '
        .'or a teaser, so neither was made. The video itself is uploaded and being served. Press the cut '
        .'button on this clip to try again on a request of its own.';

    /**
     * The two ways this server can be unable to cut, as keys.
     *
     * Constants rather than bare strings because they cross a boundary: the
     * admin payload carries one and resources/views/admin/partials/
     * ugc-library-screen.blade.php switches on it. A typo on either side would
     * fall through to the safe arm and say nothing, which is the failure this
     * pair exists to end.
     */
    public const REASON_NO_FFMPEG = 'no_ffmpeg';

    public const REASON_NO_SPAWN = 'no_spawn';

    /** §0b.1: 2.5 seconds, the length the byte table was measured at. */
    public const TEASER_SECONDS = '2.5';

    /** A rail tile is 158 CSS px, 316 device px at 2x. 360x640 is the encode for it. */
    public const TEASER_WIDTH = 360;

    public const TEASER_HEIGHT = 640;

    public const TEASER_BITRATE = '400k';

    /** The absolute path to ffmpeg on this box, or null. */
    public function binary(): ?string
    {
        $configured = (string) env('KBB_FFMPEG', '');

        if ($configured !== '') {
            return $this->usable($configured) ? $configured : null;
        }

        foreach (self::CANDIDATES as $path) {
            if ($this->usable($path)) {
                return $path;
            }
        }

        return null;
    }

    /** ffprobe, looked for beside ffmpeg. Optional: it only fills in dimensions. */
    public function prober(): ?string
    {
        $ffmpeg = $this->binary();

        if ($ffmpeg === null) {
            return null;
        }

        $probe = dirname($ffmpeg).'/ffprobe';

        return $this->usable($probe) ? $probe : null;
    }

    /**
     * Can this PHP start a program at all?
     *
     * Asked separately from "is ffmpeg on the disk", because on the owner's host
     * the answer to the two is different and only one of them is about ffmpeg.
     * `disable_functions=proc_open` leaves /usr/bin/ffmpeg exactly where it was
     * and makes it unreachable, and Symfony's Process constructor is the thing
     * that discovers it — by throwing.
     *
     * A method rather than an inline function_exists() so that blocker() can be
     * a pure function of the two facts, and so the test can assert what THIS box
     * answers without needing a box whose proc_open is really switched off.
     */
    public function canSpawn(): bool
    {
        return function_exists('proc_open');
    }

    /**
     * Why this server cannot cut derivatives, in a sentence, or null when it can.
     *
     * A PURE FUNCTION OF THE TWO FACTS, in the same spirit as posterCommand() and
     * teaserCommand() above: every branch is assertable without a transcoder,
     * without a clock and without a box in a particular state. The arm that
     * matters is (false, '/usr/bin/ffmpeg') — ffmpeg present, unreachable — which
     * is the owner's server and which no test on this container could otherwise
     * reach.
     */
    public function blocker(bool $canSpawn, ?string $binary): ?string
    {
        return match ($this->reason($canSpawn, $binary)) {
            self::REASON_NO_FFMPEG => self::NOTE_NO_FFMPEG,
            self::REASON_NO_SPAWN => self::NOTE_NO_SPAWN,
            default => null,
        };
    }

    /**
     * The same question as blocker(), answered as a KEY rather than a sentence.
     *
     * ── WHY A SCREEN NEEDS THE KEY AND NOT ONLY THE SENTENCE ────────────────
     *
     * blocker() is a paragraph, which is right beside the thing it explains and
     * wrong in a chip. So the clips list wrote its own three-word summary — and
     * it wrote `No ffmpeg here — you choose the cover`, HARD-CODED, on every box
     * that could not cut. On the owner's server that sentence is FALSE:
     * /usr/bin/ffmpeg is installed and PHP-FPM is not allowed to start it
     * (docs/SERVER-PROC-OPEN.md §1, reproduced there). The first thing he sees
     * when he opens Content → Shoppable video → All clips has therefore been
     * sending him to install a program he already has — which is the exact
     * mistake blocker() was added to stop, surviving one layer further out
     * because the screen had a bool and no way to ask which.
     *
     * A key, so the screen picks from ITS OWN fixed set of words instead of
     * guessing or printing a paragraph in a pill. Rule 5's shape: the server
     * returns one of a closed set, and the client maps that set to markup it
     * owns — never the other way round.
     *
     * PURE, exactly as blocker() is, and blocker() is now defined in terms of
     * it so the two can never disagree about which world a box is in.
     */
    public function reason(bool $canSpawn, ?string $binary): ?string
    {
        /*
         * ORDER MATTERS, and this is the order that tells the truth. A box with
         * neither ffmpeg nor proc_open is a box whose first job is to get
         * ffmpeg — naming proc_open there sends somebody to edit a PHP setting
         * that would still leave nothing to run. So the missing binary is
         * reported first and the spawn fault only when there IS something to run.
         */
        if ($binary === null) {
            return self::REASON_NO_FFMPEG;
        }

        if (! $canSpawn) {
            return self::REASON_NO_SPAWN;
        }

        return null;
    }

    /**
     * Can this server cut a poster and a teaser?
     *
     * The honest question, asked in one call, so the admin screen can say which
     * of the two worlds the owner is in BEFORE he uploads anything rather than
     * after.
     *
     * IT USED TO ASK ONLY WHETHER THE FILE WAS THERE, which is why the screen
     * offered the owner a cut on a box that cannot run a program and the endpoint
     * answered "Server Error". It is the same bool in the same key — the screen
     * reads `transcoder.available` and nothing about it changes — asked of both
     * facts now instead of one.
     */
    public function available(): bool
    {
        return $this->blocker($this->canSpawn(), $this->binary()) === null;
    }

    /**
     * How long a child process may run, given PHP's own limit and what is spent.
     *
     * null means "do not start one" — there is not enough of this request left to
     * finish inside it, and a fatal from the execution limit is the one failure
     * mode nothing in this file could catch.
     *
     * PURE, and the two boundaries it draws are the whole of its behaviour:
     * a limit of 0 or less is PHP saying there is no limit (CLI, and any FPM pool
     * with max_execution_time=0), which leaves TIMEOUT unchanged and is why
     * nothing about this suite or a normally-configured box moves.
     */
    public function timeoutSeconds(int $phpLimit, float $elapsed): ?int
    {
        if ($phpLimit <= 0) {
            return self::TIMEOUT;
        }

        $left = (int) floor($phpLimit - $elapsed - self::TIMEOUT_MARGIN);

        if ($left < self::TIMEOUT_FLOOR) {
            return null;
        }

        return min(self::TIMEOUT, $left);
    }

    /** The same question against the live request: what is left, right now. */
    private function budget(): ?int
    {
        return $this->timeoutSeconds(
            (int) ini_get('max_execution_time'),
            // Wall clock since PHP started serving this request. REQUEST_TIME_FLOAT
            // is absent under some SAPIs, and treating that as "nothing spent" is
            // the safe reading: it can only make the budget larger, never negative.
            max(0.0, microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))),
        );
    }

    /**
     * Cut whatever is missing, from the clip that is already stored.
     *
     * Returns a report rather than throwing: what it made, what it could not,
     * and why. The caller writes the columns; this touches no model.
     *
     * "RATHER THAN THROWING" IS NOW TRUE AND USED NOT TO BE, which is the whole
     * of this round. See the class docblock: it threw Symfony's LogicException on
     * a host with proc_open disabled, from OUTSIDE the guard, on the upload
     * request, after the row was saved. Every path out of this method is a
     * report, and run() and durationMs() are the two places that make that so.
     *
     * @return array{poster: ?string, teaser: ?string, width: ?int, height: ?int,
     *               duration_ms: ?int, notes: list<string>}
     */
    public function derive(UgcVideo $video, bool $remakePoster = false, bool $remakeTeaser = false): array
    {
        $out = ['poster' => null, 'teaser' => null, 'width' => null, 'height' => null,
            'duration_ms' => null, 'notes' => []];

        $stored = UgcPath::stored($video->file_path);

        if ($stored === null) {
            $out['notes'][] = 'There is no uploaded clip to cut from yet.';

            return $out;
        }

        $source = public_path(ltrim($stored, '/'));

        if (! is_file($source)) {
            // The row says there is a file and the disk disagrees. Said out
            // loud: a silent null here reads as "ffmpeg is missing", which
            // would send somebody to the wrong problem.
            $out['notes'][] = 'The stored clip is missing from the server at '.$stored.'.';

            return $out;
        }

        $ffmpeg = $this->binary();

        /*
         * BOTH REASONS, TOLD APART. This used to be `if ($ffmpeg === null)` and a
         * single sentence about ffmpeg, so the box that HAS ffmpeg and cannot
         * start it fell through this check, reached `new Process` and 500'd the
         * upload. It is reported here now, in its own words, and no Process is
         * constructed at all.
         */
        $blocked = $this->blocker($this->canSpawn(), $ffmpeg);

        if ($blocked !== null || $ffmpeg === null) {
            // The `|| $ffmpeg === null` is for the type checker, not for the
            // logic: blocker() already returns non-null for a null binary.
            $out['notes'][] = $blocked ?? self::NOTE_NO_FFMPEG;

            return $out;
        }

        /*
         * AND THE TIME. Nothing is started that cannot finish inside what PHP
         * allows this request, because the one failure this file cannot catch is
         * the execution limit — see TIMEOUT_MARGIN for what was measured.
         */
        $budget = $this->budget();

        if ($budget === null) {
            $out['notes'][] = self::NOTE_NO_TIME;

            return $out;
        }

        $dir = public_path(UgcMedia::DIR);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $out['notes'][] = 'Could not create the uploads/ugc directory.';

            return $out;
        }

        if ($remakePoster || (string) $video->poster_path === '') {
            $name = 'poster-'.date('Ymd-His').'-'.Str::random(10).'.jpg';

            if ($this->run($this->posterCommand($ffmpeg, $source, $dir.'/'.$name), $budget)) {
                $out['poster'] = '/'.UgcMedia::DIR.'/'.$name;

                /*
                 * AND INTO THE MEDIA LIBRARY, like every other file this shop
                 * writes into its own web root — the owner's rule, applied to
                 * a file ffmpeg made rather than one somebody uploaded, because
                 * the library cannot tell the difference and neither should it.
                 * No original name: nobody ever called this frame anything, so
                 * the tile falls back to the stored filename rather than being
                 * given a name that pretends to be his.
                 */
                MediaRegistrar::record($out['poster'], null, 'image/jpeg');

                /*
                 * THE BOX, WITHOUT ffprobe. getimagesize() reads the poster's
                 * own header, so width and height are known from the frame we
                 * just wrote whether or not this box has a prober. That is what
                 * keeps §2's zero-layout-shift budget reachable on a server
                 * with half a toolchain.
                 */
                $size = @getimagesize($dir.'/'.$name);

                if (is_array($size)) {
                    $out['width'] = (int) $size[0];
                    $out['height'] = (int) $size[1];
                }
            } else {
                $out['notes'][] = 'ffmpeg could not read a poster frame out of that clip.';
            }
        }

        /*
         * ASKED AGAIN, because the poster cut has just spent some of it. Two
         * stages sharing one budget computed before the first is how a second
         * stage overruns a limit the first left no room under — and the teaser is
         * the more expensive of the two.
         */
        $budget = $this->budget();

        if ($remakeTeaser && $budget === null) {
            $out['notes'][] = self::NOTE_NO_TIME;
        }

        if ($budget !== null && ($remakeTeaser || (string) $video->teaser_path === '')) {
            $name = 'teaser-'.date('Ymd-His').'-'.Str::random(10).'.mp4';

            if ($this->run($this->teaserCommand($ffmpeg, $source, $dir.'/'.$name), $budget)) {
                $out['teaser'] = '/'.UgcMedia::DIR.'/'.$name;

                /* Catalogued for the same reason the poster above is. */
                MediaRegistrar::record($out['teaser'], null, 'video/mp4');
            } else {
                // Not an error state. The rail falls back to the poster, which
                // is drawn and budgeted.
                $out['notes'][] = 'ffmpeg could not cut a teaser from that clip. '
                    .'The video still works — its tile shows the poster instead of a loop.';
            }
        }

        $duration = $this->durationMs($source);

        if ($duration !== null) {
            $out['duration_ms'] = $duration;
        }

        return $out;
    }

    /**
     * The poster command.
     *
     * One frame at 0.6s rather than at 0, because frame zero of a phone video
     * is very often a black or half-exposed frame — the sensor is still
     * settling. `-frames:v 1` and a quality of 3 give a ~40 KB JPEG at
     * 720x1280, which the Media Library's own sizing can shrink later.
     *
     * A PURE FUNCTION RETURNING THE ARGV ARRAY, so the arguments can be
     * asserted by a test without a transcoder anywhere near it —
     * UgcTranscoderTest pins the length, the scale and the silence, because
     * those three are what the 12.1x byte saving in §0b.1 actually is.
     *
     * @return list<string>
     */
    public function posterCommand(string $ffmpeg, string $source, string $destination): array
    {
        return [
            $ffmpeg,
            '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-ss', '0.6',
            '-i', $source,
            '-frames:v', '1',
            '-q:v', '3',
            $destination,
        ];
    }

    /**
     * The teaser command — §0b.1, verbatim: `-ss 0 -t 2.5 -vf scale=360:640
     * -b:v 400k`, no audio.
     *
     * `-an` is not a nicety. A teaser plays muted by policy (every browser
     * refuses an unmuted autoplay), so its audio track is bytes that can never
     * be heard, on the file whose entire reason for existing is that it is
     * 12.1x smaller than the clip.
     *
     * `+faststart` puts the moov atom at the front, so the first bytes a phone
     * receives are the ones it needs to start decoding rather than the ones at
     * the end of the file.
     *
     * @return list<string>
     */
    public function teaserCommand(string $ffmpeg, string $source, string $destination): array
    {
        return [
            $ffmpeg,
            '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-ss', '0',
            '-i', $source,
            '-t', self::TEASER_SECONDS,
            '-an',
            '-vf', 'scale='.self::TEASER_WIDTH.':'.self::TEASER_HEIGHT.':force_original_aspect_ratio=increase'
                .',crop='.self::TEASER_WIDTH.':'.self::TEASER_HEIGHT,
            '-b:v', self::TEASER_BITRATE,
            '-c:v', 'libx264', '-preset', 'veryfast', '-profile:v', 'baseline', '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            $destination,
        ];
    }

    /**
     * The clip's length in milliseconds, or null when there is no prober.
     *
     * Null for every other reason too, and that is the contract: this fills in one
     * optional column and may never be the thing that costs an upload. It had the
     * same defect run() had — `new Process` above the try — and on a box with
     * ffmpeg AND ffprobe AND no proc_open it was the SECOND place the same
     * LogicException escaped from.
     */
    public function durationMs(string $source): ?int
    {
        $probe = $this->prober();

        if ($probe === null || ! $this->canSpawn()) {
            return null;
        }

        $budget = $this->budget();

        if ($budget === null) {
            return null;
        }

        try {
            // INSIDE the try, all of it. The constructor is the line that threw.
            $process = new Process([
                $probe, '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $source,
            ]);

            $process->setTimeout($budget);
            $process->run();

            $seconds = (float) trim($process->getOutput());
        } catch (\Throwable) {
            /*
             * \Throwable, not ProcessException. ProcessTimedOutException and
             * ProcessStartFailedException both reach it either way — both extend
             * Symfony's RuntimeException, which implements its ExceptionInterface,
             * so the narrow catch was right about the two failures it was written
             * for. It was the constructor's LogicException, a ValueError out of
             * proc_open() and an Error from a disabled function that it could not
             * see, and a duration column is never worth any of them.
             */
            return null;
        }

        return $seconds > 0 ? (int) round($seconds * 1000) : null;
    }

    private function usable(string $path): bool
    {
        return $path !== '' && str_starts_with($path, '/') && is_file($path) && is_executable($path);
    }

    /**
     * Run one command and say whether it produced the file it was asked for.
     *
     * The exit code is not enough on its own: ffmpeg can exit 0 having written
     * a zero-byte container when the input has no decodable video stream, and a
     * zero-byte .mp4 in a `<video src>` is a tile that spins forever. So the
     * destination is checked for existence AND for size, and a file that is
     * there but empty is removed rather than recorded.
     *
     * ── THE ONE LINE THIS ROUND MOVED ───────────────────────────────────────
     *
     * `new Process($command)` was above the try. Its constructor throws
     * Symfony\Component\Process\Exception\LogicException when proc_open is
     * unavailable, and that exception is the 500 the owner saw: it left this
     * method, left derive(), left the controller's clip branch AFTER the row had
     * been saved, and reached Laravel as "Server Error". Construction, setTimeout
     * and run() are all inside one try now, and the catch is \Throwable.
     *
     * @param  int  $timeout  seconds, already bounded against PHP's own limit
     * @param  list<string>  $command
     */
    private function run(array $command, int $timeout): bool
    {
        $destination = $command[count($command) - 1];

        try {
            $process = new Process($command);
            $process->setTimeout($timeout);
            $process->run();
            $exit = $process->getExitCode();
        } catch (\Throwable) {
            /*
             * \Throwable rather than ProcessException, for the reason spelt out
             * on durationMs(): the narrow catch was right about a timeout and a
             * failed exec and blind to everything else the process layer can
             * raise. A derivative is worth no exception at all — the clip is
             * already stored and already being served, and a video with a cover
             * and no teaser is a published, working state.
             *
             * A process that threw may still have left a part-written file, and
             * the cleanup below is skipped on this path, so it is done here.
             */
            if (is_file($destination)) {
                @unlink($destination);
            }

            return false;
        }

        $wrote = is_file($destination) && (int) @filesize($destination) > 0;

        if ($exit !== 0 || ! $wrote) {
            // Whatever it left behind goes with it. A half-written derivative
            // that nothing recorded is a file nobody will ever delete.
            if (is_file($destination)) {
                @unlink($destination);
            }

            return false;
        }

        return true;
    }
}
