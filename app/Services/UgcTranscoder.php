<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UgcVideo;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
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
 */
final class UgcTranscoder
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
     * Can this server cut a poster and a teaser?
     *
     * The honest question, asked in one call, so the admin screen can say which
     * of the two worlds the owner is in BEFORE he uploads anything rather than
     * after.
     */
    public function available(): bool
    {
        return $this->binary() !== null;
    }

    /**
     * Cut whatever is missing, from the clip that is already stored.
     *
     * Returns a report rather than throwing: what it made, what it could not,
     * and why. The caller writes the columns; this touches no model.
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

        if ($ffmpeg === null) {
            $out['notes'][] = 'This server has no ffmpeg, so the poster and the teaser cannot be cut here. '
                .'Upload a poster image instead — the video can be published with a poster and no teaser.';

            return $out;
        }

        $dir = public_path(UgcMedia::DIR);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $out['notes'][] = 'Could not create the uploads/ugc directory.';

            return $out;
        }

        if ($remakePoster || (string) $video->poster_path === '') {
            $name = 'poster-'.date('Ymd-His').'-'.Str::random(10).'.jpg';

            if ($this->run($this->posterCommand($ffmpeg, $source, $dir.'/'.$name))) {
                $out['poster'] = '/'.UgcMedia::DIR.'/'.$name;

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

        if ($remakeTeaser || (string) $video->teaser_path === '') {
            $name = 'teaser-'.date('Ymd-His').'-'.Str::random(10).'.mp4';

            if ($this->run($this->teaserCommand($ffmpeg, $source, $dir.'/'.$name))) {
                $out['teaser'] = '/'.UgcMedia::DIR.'/'.$name;
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

    /** The clip's length in milliseconds, or null when there is no prober. */
    public function durationMs(string $source): ?int
    {
        $probe = $this->prober();

        if ($probe === null) {
            return null;
        }

        $process = new Process([
            $probe, '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $source,
        ]);

        $process->setTimeout(self::TIMEOUT);

        try {
            $process->run();
        } catch (ProcessException) {
            return null;
        }

        $seconds = (float) trim($process->getOutput());

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
     * @param  list<string>  $command
     */
    private function run(array $command): bool
    {
        $destination = $command[count($command) - 1];

        $process = new Process($command);
        $process->setTimeout(self::TIMEOUT);

        try {
            $process->run();
        } catch (ProcessException) {
            return false;
        }

        $wrote = is_file($destination) && (int) @filesize($destination) > 0;

        if ($process->getExitCode() !== 0 || ! $wrote) {
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
