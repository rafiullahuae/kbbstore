<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What this server will ACTUALLY accept, as opposed to what the app allows.
 *
 * ── THE DEFECT THIS CLASS EXISTS FOR ────────────────────────────────────────
 *
 * The owner uploaded an 8.4 MB .mp4 at Content → Shoppable video → All clips →
 * step 2. The bar reached 100% and the screen answered "That file was not
 * accepted. 8.4 MB — nothing on the clip was changed." His file was fine. This
 * box reads:
 *
 *     upload_max_filesize = 2M
 *     post_max_size       = 8M
 *
 * while that screen advertised "MP4 or WebM, up to 64 MB", which is
 * UgcMedia::MAX_BYTES[KIND_CLIP] and nothing else. The application had never
 * asked PHP what PHP would take, so every number it printed was a number it
 * could not honour, and every refusal it printed blamed the file.
 *
 * ── THE TWO FAILURE MODES, REPRODUCED RATHER THAN REASONED ──────────────────
 *
 * Measured against a bare `php -S` on this box with the values above:
 *
 *   A. file over upload_max_filesize, whole body under post_max_size (3 MB):
 *      CONTENT_LENGTH=3146044  count($_POST)=1  count($_FILES)=1
 *      $_FILES['file']['error'] = 1 (UPLOAD_ERR_INI_SIZE), ['size'] = 0
 *
 *   B. whole body over post_max_size (9 MB — the owner's 8.4 MB is this one):
 *      CONTENT_LENGTH=9437499  count($_POST)=0  count($_FILES)=0
 *      PHP discards the entire body. There is nothing to validate.
 *
 * App\Support\UploadArrival turns both into a sentence; this class is where the
 * numbers in that sentence come from.
 *
 * ── THIS LESSON WAS LEARNED ONCE ALREADY AND NEVER CARRIED ACROSS ───────────
 *
 * ImportConsole\ImportWorkspace::serverLimits() has existed for months and its
 * docblock reads, in full: "What this PHP install will actually accept, which is
 * often less than we allow." That is the defect above, understood, written down,
 * and applied to exactly one screen — Store → Store Import / Export, which
 * really does print "This server accepts uploads up to 2M each (form limit 8M)"
 * on the live shop today. The video screen a lane wrote afterwards printed 64 MB
 * and never asked.
 *
 * So this class lives in Support rather than beside either screen, and
 * ImportWorkspace::serverLimits() now reads its two ini values THROUGH it: one
 * place reads upload_max_filesize and post_max_size, and the next screen that
 * needs a real ceiling finds arithmetic rather than a third echo of ini_get.
 * What that method returns is unchanged, key for key and value for value —
 * UploadLimitsTest pins that, because the import screen is not this lane's to
 * move.
 *
 * What it does NOT reuse is the parsing, because there was none to reuse:
 * serverLimits() hands back the raw strings "2M" and "8M" and every arithmetic
 * question below — the shorthand, the min() across three ceilings, the multipart
 * overhead — is new work.
 *
 * ── THE SHORTHAND PARSER IS NOT A (int) CAST ────────────────────────────────
 *
 * `(int) '8M'` is 8. PHP's own parser (zend_ini_parse_quantity) reads the
 * leading integer and then looks at the LAST character for a k/m/g multiplier,
 * and its edge cases are strange enough to be worth measuring rather than
 * assuming. Both of these were run against a real `php -S` with a real upload,
 * not taken from the manual:
 *
 *   upload_max_filesize = "1.5M"  enforces 1 MiB. A 1.5 MiB file came back
 *                                 error=1; a 1,048,000-byte file came back
 *                                 error=0. The fraction is dropped, the
 *                                 multiplier is kept.
 *   upload_max_filesize = "8MB"   enforces EIGHT BYTES. 'B' is not a multiplier,
 *                                 so the suffix is ignored entirely and a 1 MB
 *                                 file came back error=1. PHP warns about it at
 *                                 startup and then does it anyway.
 *
 * bytes() reproduces exactly that, because a reader that disagrees with the
 * engine is worse than no reader: it would print a limit the server does not
 * keep, which is the bug being fixed.
 *
 * ── 0 AND -1 ARE BOTH "NO LIMIT", FOR BOTH DIRECTIVES ───────────────────────
 *
 * Also measured, four ways, with a 1,048,000-byte upload that arrived intact
 * every time: upload_max_filesize=0, upload_max_filesize=-1, post_max_size=0,
 * post_max_size=-1. PHP's rfc1867 handler guards its comparison with `> 0` and
 * so does Illuminate\Http\Middleware\ValidatePostSize, so a non-positive value
 * is an absent ceiling and not a ceiling of zero. null is how that is carried
 * here — the same answer an unreadable or empty ini value gets, because "no
 * ceiling I can see" and "no ceiling" are the same instruction to a caller.
 */
final class ServerUploadLimits
{
    /**
     * What a multipart body costs on top of the file itself.
     *
     * MEASURED, then rounded up hard. A `kind=clip` field plus one file part
     * named `one.bin` cost 314 bytes on this box (CONTENT_LENGTH 1,048,890 for a
     * 1,048,576-byte file). A 255-character filename and a longer boundary add a
     * few hundred more, so the real figure lives somewhere under 1 KB.
     *
     * 4 KB is deliberately generous, and the direction of the error is the whole
     * point: UNDER-estimating the overhead advertises a ceiling the request
     * cannot fit inside, which is this bug again with a smaller number. 4 KB is
     * 0.004 MB, invisible at the tenth-of-a-megabyte the screen prints.
     */
    public const MULTIPART_OVERHEAD = 4096;

    /** Why a ceiling is where it is. A closed vocabulary — see CLAUDE.md rule 5. */
    public const BY_APP = 'app';

    public const BY_PER_FILE = 'upload_max_filesize';

    public const BY_PER_REQUEST = 'post_max_size';

    public const REASONS = [self::BY_APP, self::BY_PER_FILE, self::BY_PER_REQUEST];

    /**
     * An ini quantity in bytes, or null for "no limit and none readable".
     *
     * PHP's own algorithm, and the two odd cases above are why it is written out
     * rather than delegated to a cast:
     *
     *   1. the leading integer is read and the rest of the string ignored;
     *   2. the LAST non-space character decides the multiplier, k/m/g only;
     *   3. anything else in between is dropped, silently here and with a
     *      startup warning in PHP.
     */
    public static function bytes(?string $shorthand): ?int
    {
        $raw = trim((string) $shorthand);

        if ($raw === '') {
            return null;
        }

        // Step 1: the leading integer, sign included. preg_match rather than a
        // cast so a value with no digits at all ('M', 'none') is recognised as
        // unreadable instead of silently becoming zero — which this class would
        // otherwise report as a hard ceiling of nothing.
        if (preg_match('/^[+-]?\d+/', $raw, $m) !== 1) {
            return null;
        }

        $value = (int) $m[0];

        // Step 2: the multiplier, from the last character and from nowhere else.
        $suffix = strtolower(substr($raw, -1));

        $value *= match ($suffix) {
            'k' => 1024,
            'm' => 1048576,
            'g' => 1073741824,
            default => 1,
        };

        // Step 3: 0 and -1 are "no limit", for both directives, measured above.
        return $value > 0 ? $value : null;
    }

    /**
     * Live off this process's ini by default, which is the only configuration
     * production ever uses.
     *
     * ── WHY THERE IS A SECOND WAY TO BUILD ONE ──────────────────────────────
     *
     * upload_max_filesize and post_max_size are both PHP_INI_PERDIR, so
     * ini_set() cannot move them and a test cannot arrange the arithmetic this
     * class exists for. Without of() the only assertions possible about
     * ceiling() and reason() are the ones this box happens to make true, which
     * is a suite that proves nothing on a differently configured runner and
     * would have passed against the defect on a generous one.
     *
     * The container resolves the no-argument form, so nothing in the shop is
     * reachable through the seam.
     */
    public function __construct(
        private ?int $fixedPerFile = null,
        private ?int $fixedPerRequest = null,
        private bool $live = true,
    ) {}

    /** A reader with the two ceilings stated rather than read. null is "no limit". */
    public static function of(?int $perFile, ?int $perRequest): self
    {
        return new self($perFile, $perRequest, false);
    }

    /** The biggest single file PHP will hand over, or null for no limit. */
    public function perFile(): ?int
    {
        return $this->live
            ? self::bytes((string) ini_get('upload_max_filesize'))
            : $this->fixedPerFile;
    }

    /** The biggest whole request body PHP will read, or null for no limit. */
    public function perRequest(): ?int
    {
        return $this->live
            ? self::bytes((string) ini_get('post_max_size'))
            : $this->fixedPerRequest;
    }

    /**
     * How many files one request may carry.
     *
     * Not a ceiling this module can hit — the clip editor sends one file per
     * request — but it is the third of the three numbers an operator needs when
     * he is told to go and change them, and leaving it out would send him back
     * for it.
     */
    public function maxFileUploads(): int
    {
        return $this->live ? max(0, (int) ini_get('max_file_uploads')) : 1;
    }

    /**
     * The ini strings themselves, for a screen and a refusal that have to name
     * what to edit.
     *
     * THE REAL TEXT WHERE THERE IS ANY, deliberately, rather than a number
     * reconstructed from the parsed bytes: an operator is about to go and find
     * `upload_max_filesize = 1.5M` in a file, and telling him it says 1M — which
     * is what it MEANS — sends him looking for a line that is not there. A reader
     * built by of() has no ini text to quote, so it prints the size instead.
     *
     * @return array{upload_max_filesize: string, post_max_size: string, max_file_uploads: string}
     */
    public function raw(): array
    {
        if (! $this->live) {
            return [
                'upload_max_filesize' => $this->limitLabel($this->fixedPerFile),
                'post_max_size' => $this->limitLabel($this->fixedPerRequest),
                'max_file_uploads' => (string) $this->maxFileUploads(),
            ];
        }

        return [
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'max_file_uploads' => (string) $this->maxFileUploads(),
        ];
    }

    /** A ceiling as a sentence can carry it, including when there is not one. */
    private function limitLabel(?int $bytes): string
    {
        return $bytes === null ? 'no limit' : $this->label($bytes);
    }

    /**
     * The largest file that can really arrive, given the app's own cap.
     *
     *     min( app cap, upload_max_filesize, post_max_size - multipart overhead )
     *
     * post_max_size is the one that needs the subtraction: it bounds the WHOLE
     * body — every field, every boundary and the part headers — so a file
     * exactly post_max_size big cannot fit in a request that carries it. That
     * arithmetic is mode B, and it is the mode the owner hit.
     */
    public function ceiling(int $appCap): int
    {
        $limit = $appCap;

        $perFile = $this->perFile();

        if ($perFile !== null) {
            $limit = min($limit, $perFile);
        }

        $perRequest = $this->perRequest();

        if ($perRequest !== null) {
            $limit = min($limit, $perRequest - self::MULTIPART_OVERHEAD);
        }

        // A post_max_size smaller than the overhead is a server on which no
        // multipart upload can succeed at all. 0 says so honestly; a negative
        // number would read as a bug in this class.
        return max(0, $limit);
    }

    /** Which of the three is doing the capping. One of REASONS, never anything else. */
    public function reason(int $appCap): string
    {
        $ceiling = $this->ceiling($appCap);

        if ($ceiling >= $appCap) {
            return self::BY_APP;
        }

        $perFile = $this->perFile();

        // Checked in the order the screen explains them, and per-file first
        // because it is the smaller of the two on nearly every shared plan. A
        // tie is attributed to upload_max_filesize, which is the directive an
        // operator reaches for first anyway.
        if ($perFile !== null && $perFile === $ceiling) {
            return self::BY_PER_FILE;
        }

        return self::BY_PER_REQUEST;
    }

    /**
     * Everything a screen needs about one cap, in one shape.
     *
     * An explicit list, iterated — the SettingController::PUBLIC_KEYS habit.
     * `capped_by` is one of REASONS and is asserted to be so by
     * UploadLimitsTest, because it is printed into markup that switches on it.
     *
     * @return array{app_bytes: int, app_mb: int, effective_bytes: int, effective_mb: int, effective_label: string, capped_by: string, capped: bool}
     */
    public function describe(int $appCap): array
    {
        $ceiling = $this->ceiling($appCap);
        $reason = $this->reason($appCap);

        return [
            'app_bytes' => $appCap,
            'app_mb' => (int) floor($appCap / 1048576),
            'effective_bytes' => $ceiling,
            /*
             * FLOORED, never rounded. This integer is what the screen prints as
             * "up to N MB" and what its own pre-flight refuses against, so
             * rounding 2,097,151 bytes up to 2 MB would put the lie back in a
             * smaller font. floor() of a ceiling is always a ceiling.
             */
            'effective_mb' => (int) floor($ceiling / 1048576),
            'effective_label' => $this->label($ceiling),
            'capped_by' => $reason,
            'capped' => $reason !== self::BY_APP,
        ];
    }

    /**
     * A byte count as an operator would say it, and never rounded up.
     *
     * KB below a megabyte, because a ceiling of 512K printed as "0 MB" tells
     * somebody nothing except that the screen is broken.
     */
    public function label(int $bytes): string
    {
        if ($bytes < 1048576) {
            return max(0, (int) floor($bytes / 1024)).' KB';
        }

        $tenths = (int) floor($bytes / 1048576 * 10);

        return rtrim(rtrim(number_format($tenths / 10, 1), '0'), '.').' MB';
    }
}
