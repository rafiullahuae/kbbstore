<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Support\MediaUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Take the shop's photographs off the old site BY FETCHING THEM, one bounded
 * batch per HTTP request, until there are none left.
 *
 * =============================================================================
 * THE OBJECTION THIS ANSWERS, IN THE WORDS IT WAS MADE IN
 * =============================================================================
 *
 * `MediaAudit` and `ImportMediaAudit` both refused to build this, and the
 * reason they gave is worth quoting because it is a good one:
 *
 *     "a downloader that silently half-succeeds would leave the owner worse off
 *      than a list of names."
 *
 * That is true of a downloader that half-succeeds SILENTLY. It is not a
 * property of downloading. A half-finished fetch is worse than a list only when
 * two things are true of it: it cannot be resumed, so the half that failed has
 * to be guessed at; and it cannot be told apart from a finished one, so nobody
 * knows to look.
 *
 * `ImportRunner` met exactly this objection for rows and answered it with
 * checkpoints — it was SIGKILLed mid-run eight times during the volume work
 * (`docs/FV-IMPORT-AT-VOLUME.md`) and resumed onto exactly the rows it had not
 * done. This is the same answer for bytes:
 *
 *   RESUMABLE. The work list is re-derived from the catalogue on every single
 *   request. It is never cached, never serialised into a job, never held across
 *   a request. So there is no queue to lose.
 *
 *   IDEMPOTENT, and by the strongest available test. "Already done" means
 *   `is_file(public_path($path))` — the file itself, not a row claiming there
 *   is one. A batch killed by the host's execution limit leaves either a
 *   complete file (the rename committed) or no file at all (it did not), never
 *   a truncated one, because every fetch is validated in a temporary file and
 *   moved into place with a single rename(). Re-running re-fetches nothing.
 *
 *   COUNTABLE AT ANY POINT. `plan()` answers "N still to go" by walking the
 *   catalogue and the disk, with no reference to any tally this class kept. A
 *   number that is recomputed from the two things that actually matter cannot
 *   drift away from them.
 *
 *   AND DISTINGUISHABLE FROM A FINISHED ONE. `media_sideload_runs` carries a
 *   heartbeat, and `progress()` reports IDLE, RUNNING and STALLED as three
 *   different states. A progress bar frozen at 118/530 because the last request
 *   was killed is precisely the failure the old header feared, so it is named
 *   on the screen rather than left to be inferred.
 *
 * The ledger table records only what the disk cannot: WHY a file is not there.
 * Without it a URL that 404s on the old host is retried on every batch forever
 * and "remaining" never reaches zero.
 *
 * =============================================================================
 * THE SECURITY MODEL. THIS WRITES ATTACKER-INFLUENCEABLE BYTES INTO THE WEB ROOT
 * =============================================================================
 *
 * That sentence is the whole threat model. Everything downstream of it — every
 * byte of the body, every header, every `Location:` — is chosen by whoever
 * controls the old host, and the old host is a WordPress installation that is
 * being migrated away from precisely because nobody is maintaining it any more.
 * Assume it is hostile. The guards, each of which has a test that goes red when
 * it is removed (docs/GD-MEDIA-SIDELOADER.md lists the mutations):
 *
 *  1. HOSTS COME FROM THE CATALOGUE, NEVER FROM THE REQUEST. `hosts()` returns
 *     the hosts this shop's own image columns name, minus its own. A caller may
 *     NARROW that set and may not add to it. An admin who can post a hostname
 *     that is then fetched server-side owns an SSRF into the host's private
 *     network; an admin who can only pick from a list the database already
 *     contains owns nothing new. This is the single most important guard here.
 *
 *  2. NO REDIRECT OFF THE HOST. Redirects are followed manually, at most
 *     MAX_REDIRECTS of them, and every hop must keep the same host and stay on
 *     http/https. Guzzle's own follower would have walked a `Location:` to
 *     169.254.169.254 without comment. Same host, different scheme is allowed —
 *     a WordPress host forcing http->https is not an attack.
 *
 *  3. THE RESPONSE MUST REALLY BE AN IMAGE, proved twice and not by its name.
 *     The declared `Content-Type` must be in SAFE_TYPES, AND the first bytes
 *     must sniff to the same family, AND the head of the body is searched for
 *     the markers of a thing that executes. A PHP payload served as
 *     `Content-Type: image/jpeg` fails the sniff; an HTML error page served as
 *     `image/jpeg` fails it too.
 *
 *  4. THE EXTENSION IS FORCED, BY REFUSAL RATHER THAN BY RENAMING. Only the
 *     extensions in SAFE_TYPES can ever be written, and the URL's extension
 *     must agree with the sniffed bytes. The alternative — renaming the file to
 *     match the sniff — was rejected: `MediaRewrite` re-points a row at
 *     `uploadsRelative($url)`, so a file saved under a different name than the
 *     URL implies is a file the rest of this importer cannot find. Refusing the
 *     mismatch keeps both properties, and the mismatch is reported by URL so
 *     the owner can look at it.
 *
 *  5. NOTHING THAT COULD EXECUTE. Every dot-separated part of the basename is
 *     checked against DANGEROUS_PARTS, not just the last one — `photo.php.jpg`
 *     is refused on the `php` in the middle, which is the shape that gets past
 *     a naive "check the extension" and past Apache's `AddHandler` on a
 *     misconfigured host. `.htaccess` is in the list for the same reason: a
 *     single dropped `.htaccess` turns the uploads folder back into a place
 *     where `.jpg` executes.
 *
 *  6. THE PATH IS NORMALISED AND TRAVERSAL IS REFUSED. `MediaUsage::normalise()`
 *     already rawurldecodes, so `%2e%2e%2f` arrives here as `../` rather than
 *     sneaking past a check for the literal. Any `.` or `..` segment, any
 *     backslash, any NUL or control byte, any absolute path, and anything not
 *     under one of the two upload roots is refused. The resolved absolute path
 *     is then checked to be inside `public_path()` as a last line, because a
 *     path guard that is only a string test has been wrong before.
 *
 *  7. BYTES ARE CAPPED THREE WAYS: per file (MAX_FILE_BYTES), per batch
 *     (the caller's byte budget), and against the free space on the volume
 *     before anything is written at all. The body is read in chunks and the
 *     read is abandoned the moment it passes the per-file cap, so a server that
 *     streams forever costs one cap's worth of disk and nothing more. A
 *     declared `Content-Length` is used as an early refusal and never trusted as
 *     the real size.
 *
 *  8. CONNECT AND READ TIMEOUTS, both set. A host that accepts the connection
 *     and then says nothing is the cheapest way to hold a PHP-FPM worker open
 *     until the pool is exhausted, and the default for both in Guzzle is
 *     "wait".
 *
 * WHERE THE FILES GO. `public_path()`, which on this server is
 * `public_html/kbb-upgrade` and is A DIFFERENT DIRECTORY from the application
 * root — see `bootstrap/app.php`'s `usePublicPath()` and MediaAudit's own note.
 * The path under it is the URL's path relative to the uploads root, unchanged,
 * so `MediaRewrite::propose()` finds the file exactly where it already looks.
 *
 * ONE BAD FILE DOES NOT STOP THE RUN. Every failure is recorded against its URL
 * with the reason, the way `EntityReport::reject()` records a refused row, and
 * the batch carries on. A run ends when there is nothing left, not when
 * something goes wrong.
 */
final class MediaSideloader
{
    public const ITEMS = 'media_sideload_items';

    public const RUNS = 'media_sideload_runs';

    /* ------------------------------------------------------------- item states */

    /** Fetched, validated, and on disk where the catalogue says it should be. */
    public const FETCHED = 'fetched';

    /** It went wrong this time. Retried on a later batch. */
    public const FAILED = 'failed';

    /**
     * It will never be fetched, and trying again cannot change that.
     *
     * Kept apart from FAILED because the two ask different things of the owner.
     * A failure is "the old host was busy, press it again". A refusal is "this
     * reference cannot be satisfied safely and needs a decision" — a URL under
     * no uploads root, an extension that is not an image, a name that could
     * execute. Folding them together would mean either retrying a refusal
     * forever or quietly giving up on a transient error.
     */
    public const REFUSED = 'refused';

    /* --------------------------------------------------------------- the caps */

    /** Per file. A WooCommerce product photograph is tens of kilobytes. */
    public const MAX_FILE_BYTES = 12 * 1024 * 1024;

    /** Default bytes per browser request. Shared hosting, not a workstation. */
    public const DEFAULT_BATCH_BYTES = 8 * 1024 * 1024;

    /** Default files per browser request. */
    public const DEFAULT_BATCH_FILES = 10;

    /** Default wall clock per browser request, seconds. */
    public const DEFAULT_BATCH_SECONDS = 15;

    public const CONNECT_TIMEOUT = 5;

    public const READ_TIMEOUT = 20;

    public const MAX_REDIRECTS = 3;

    /** Read size. Small enough that the per-file cap is honoured promptly. */
    private const CHUNK = 65536;

    /**
     * A run whose heartbeat is older than this is STALLED, not running.
     *
     * Longer than a batch's wall clock by a wide margin, because the point is
     * to catch a request that never came back, not one that is taking its time.
     */
    public const STALE_SECONDS = 90;

    /**
     * Free space this refuses to eat into, and the estimate it plans with.
     *
     * There is no way to know the total size of 2,600 remote files without
     * asking the old host 2,600 times, which is itself the run. So the estimate
     * is stated AS an estimate on the screen, and the hard guard is the one
     * that matters: before every single write, the volume must have room for
     * one more capped file plus this reserve, or the run stops and says so.
     * Half-filling a shared host's volume takes the whole site down, not just
     * the pictures.
     */
    public const FREE_SPACE_RESERVE = 64 * 1024 * 1024;

    /** Bytes assumed per outstanding file when reporting the estimate. */
    public const ESTIMATED_BYTES_PER_FILE = 350 * 1024;

    /**
     * The only content types that can ever be written, and the extensions each
     * one is allowed to wear.
     *
     * SVG IS ABSENT ON PURPOSE. An SVG is a document: it carries `<script>`, it
     * carries `<foreignObject>`, and a browser executes it same-origin when it
     * is navigated to directly. Putting one in the web root of the shop that
     * holds the admin session is a stored XSS with extra steps. No WooCommerce
     * product photograph is an SVG; a brand logo occasionally is, and that one
     * is worth uploading by hand.
     *
     * @var array<string, list<string>>
     */
    public const SAFE_TYPES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/avif' => ['avif'],
    ];

    /**
     * Any of these appearing as ANY dot-separated part of the basename refuses
     * the file. See guard 5 in the class comment.
     *
     * @var list<string>
     */
    public const DANGEROUS_PARTS = [
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phps',
        'phtml', 'phar', 'pht', 'inc',
        'htaccess', 'htpasswd', 'user', 'ini',
        'shtml', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'dll', 'so',
        'asp', 'aspx', 'jsp', 'jspx', 'cfm',
        'js', 'mjs', 'cjs', 'html', 'htm', 'xhtml', 'svg', 'svgz', 'xml', 'swf',
    ];

    /** The two upload roots this application has, longest first. */
    private const UPLOAD_ROOTS = ['wp-content/uploads/', 'uploads/'];

    /**
     * @param  (\Closure(): ?int)|null  $freeSpace  test seam; see freeBytes()
     */
    public function __construct(
        private readonly MediaAudit $audit = new MediaAudit,
        private readonly ?\Closure $freeSpace = null,
    ) {}

    /* ====================================================================== */
    /*  THE WORK LIST                                                          */
    /* ====================================================================== */

    /**
     * Every host the catalogue's own image columns name, minus this shop's.
     *
     * THIS IS THE ALLOWLIST, and the reason it is derived rather than
     * configured is guard 1: a host that arrives in a request body is a host an
     * attacker chose. A host that is already written on 2,600 product rows was
     * chosen by whoever exported the catalogue.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        $seen = [];

        foreach ($this->audit->audit() as $row) {
            if ($row['verdict'] !== MediaAudit::REMOTE) {
                continue;
            }

            $host = parse_url($row['url'], PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                continue;
            }

            $host = strtolower($host);

            if ($this->audit->isOwnHost($host)) {
                continue;
            }

            $seen[$host] = true;
        }

        $out = array_keys($seen);
        sort($out);

        return $out;
    }

    /**
     * Narrow the catalogue's host list by what the caller asked for.
     *
     * An empty or absent request means "every host the catalogue names". A host
     * the catalogue does not name is DROPPED, not fetched, and the caller is
     * told which ones were dropped — silently ignoring it would be the same
     * behaviour with less evidence.
     *
     * @param  list<string>  $requested
     * @return array{hosts: list<string>, ignored: list<string>}
     */
    public function resolveHosts(array $requested): array
    {
        $catalogue = $this->hosts();

        $requested = array_values(array_filter(array_map(
            static fn ($h): string => strtolower(trim((string) $h)),
            $requested,
        ), static fn (string $h): bool => $h !== ''));

        if ($requested === []) {
            return ['hosts' => $catalogue, 'ignored' => []];
        }

        return [
            'hosts' => array_values(array_intersect($catalogue, $requested)),
            'ignored' => array_values(array_diff($requested, $catalogue)),
        ];
    }

    /**
     * Every remote image reference, deduplicated by URL, with its target path.
     *
     * Deduplicated because the same photograph is commonly the `image` of one
     * product and an entry in another's gallery, and fetching it twice is two
     * requests to a host that is being retired for the same one file.
     *
     * @param  list<string>|null  $hosts  already resolved; null means every catalogue host
     * @return list<array{url: string, host: string, path: string|null, refusal: string|null, owners: list<string>}>
     */
    public function references(?array $hosts = null): array
    {
        $hosts = $hosts ?? $this->hosts();
        $byUrl = [];

        foreach ($this->audit->audit() as $row) {
            if ($row['verdict'] !== MediaAudit::REMOTE) {
                continue;
            }

            $host = strtolower((string) parse_url($row['url'], PHP_URL_HOST));

            if ($host === '' || ! in_array($host, $hosts, true)) {
                continue;
            }

            if (isset($byUrl[$row['url']])) {
                $byUrl[$row['url']]['owners'][] = $row['owner'].' ['.$row['field'].']';

                continue;
            }

            $target = $this->targetPath($row['url']);

            $byUrl[$row['url']] = [
                'url' => $row['url'],
                'host' => $host,
                'path' => $target['path'],
                'refusal' => $target['refusal'],
                'owners' => [$row['owner'].' ['.$row['field'].']'],
            ];
        }

        return array_values($byUrl);
    }

    /**
     * What is left to do, recomputed from the catalogue and the disk.
     *
     * NOTHING HERE IS READ FROM A TALLY THIS CLASS KEPT. That is the point: a
     * count derived from the two things that decide the answer cannot drift
     * away from them, so "N still to go" is true after a killed request, after
     * an FTP upload somebody did by hand, and after a database restore.
     *
     * @param  list<string>|null  $hosts
     * @return array{total: int, present: int, remaining: int, failed: int, refused: int,
     *               repointed: int, bytes_on_disk: int, bytes_fetched: int, estimated_bytes: int,
     *               free_bytes: int|null, hosts: list<string>, enough_room: bool}
     */
    public function plan(?array $hosts = null): array
    {
        $hosts = $hosts ?? $this->hosts();
        $references = $this->references($hosts);
        $ledger = $this->ledger();

        $present = 0;
        $remaining = 0;
        $failed = 0;
        $refused = 0;
        $bytesOnDisk = 0;

        foreach ($references as $reference) {
            if ($reference['refusal'] !== null) {
                $refused++;

                continue;
            }

            $full = $this->absolute((string) $reference['path']);

            if ($full !== null && is_file($full)) {
                $present++;
                $bytesOnDisk += (int) (@filesize($full) ?: 0);

                continue;
            }

            $state = $ledger[self::hash($reference['url'])]['state'] ?? null;

            if ($state === self::REFUSED) {
                $refused++;

                continue;
            }

            if ($state === self::FAILED) {
                $failed++;
            }

            $remaining++;
        }

        /*
         * THE BYTES COME WITH THE COUNT, and they have to. A re-pointed row
         * leaves `references()`, so the file it named stopped being added to
         * `bytes_on_disk` at the same moment it stopped being counted — and the
         * screen read "4 of 10 fetched … on disk 0 B" with four photographs
         * plainly on the disk. Same walk, same is_file() check, one filesize()
         * more.
         */
        $repointed = $this->repointed($hosts, $references);

        $free = $this->freeBytes();
        $estimate = $remaining * self::ESTIMATED_BYTES_PER_FILE;

        return [
            'total' => count($references),
            'present' => $present,
            'remaining' => $remaining,
            'failed' => $failed,
            'refused' => $refused,
            /*
             * WORK THAT IS DONE AND IS NO LONGER VISIBLE FROM THE CATALOGUE.
             *
             * Every count above is recomputed from the catalogue and the disk,
             * which is this class's whole resume story — and it stopped being
             * able to see its own finished work the moment a batch began
             * re-pointing the rows it landed. A re-pointed row is not remote
             * any more, so it leaves `references()` entirely: `total` shrinks
             * by one at the same moment `present` would have grown by one, and
             * a bar drawn from the two sits at zero all the way through a run
             * that is going perfectly.
             *
             * This is the one number the ledger has to answer, and it is still
             * not taken on trust: a row counts only when the FILE IT NAMES IS
             * ON DISK. So it is "fetched, landed, and the catalogue has stopped
             * mentioning the old host" — which is exactly what it claims.
             */
            'repointed' => $repointed['files'],
            'bytes_on_disk' => $bytesOnDisk + $repointed['bytes'],
            'bytes_fetched' => (int) DB::table(self::ITEMS)->where('state', self::FETCHED)->sum('bytes'),
            'estimated_bytes' => $estimate,
            'free_bytes' => $free,
            'hosts' => $hosts,
            /*
             * The early, loud disk answer the brief asks for. Reported BEFORE
             * anything is written and re-checked before every individual write,
             * because an estimate is an estimate and the per-write check is the
             * one that cannot be wrong.
             */
            'enough_room' => self::hasRoom($free, $estimate),
        ];
    }

    /**
     * Ledger rows that were fetched, are still on disk, and have since been
     * re-pointed out of the catalogue's remote set.
     *
     * The host filter follows the caller's: a narrowed `hosts` narrows this
     * too. An EMPTY host list means the catalogue names no remote host at all
     * any more — which is the finished state, not an empty question — so every
     * landed file counts and the page can say "N of N" instead of "0 of 0".
     *
     * @param  list<string>  $hosts
     * @param  list<array{url: string, host: string, path: string|null, refusal: string|null, owners: list<string>}>  $references
     * @return array{files: int, bytes: int}
     */
    private function repointed(array $hosts, array $references): array
    {
        $stillReferenced = [];

        foreach ($references as $reference) {
            $stillReferenced[self::hash($reference['url'])] = true;
        }

        $files = 0;
        $bytes = 0;

        foreach (DB::table(self::ITEMS)->where('state', self::FETCHED)
            ->select(['url_hash', 'host', 'target_path'])->cursor() as $row) {
            if (isset($stillReferenced[(string) $row->url_hash])) {
                continue;
            }

            if ($hosts !== [] && ! in_array(strtolower((string) $row->host), $hosts, true)) {
                continue;
            }

            $full = $this->absolute((string) $row->target_path);

            if ($full !== null && is_file($full)) {
                $files++;
                $bytes += (int) (@filesize($full) ?: 0);
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    /**
     * Free space on the volume the web root is on, or null when it cannot say.
     *
     * THE CONSTRUCTOR ARGUMENT IS A SEAM, and it is one deliberately.
     * `disk_free_space()` cannot be made to return a chosen number, so a guard
     * written directly against it is a guard no test can enter — and an
     * untested disk guard is exactly the kind of branch
     * `Api\ProductController`'s dead `status` filter already cost this
     * repository once. A closure argument rather than a `protected` method a
     * subclass overrides, because this class stays `final`: everything else in
     * this namespace is, and a class that can be extended is a class whose
     * guards can be extended away.
     *
     * Nothing in the application passes it. It defaults to the real answer.
     */
    private function freeBytes(): ?int
    {
        if ($this->freeSpace !== null) {
            return ($this->freeSpace)();
        }

        $free = @disk_free_space(public_path());

        return is_float($free) || is_int($free) ? (int) $free : null;
    }

    /**
     * Is there room for what is left, with the reserve still untouched?
     *
     * Pure, so the arithmetic can be pinned on its own. A volume that cannot
     * report its free space (`null`) is treated as having room: refusing to run
     * because a filesystem does not implement statvfs would break the feature
     * on a host where nothing is actually wrong, and the per-write check still
     * stands behind this one.
     */
    public static function hasRoom(?int $free, int $estimate): bool
    {
        return $free === null || $free > ($estimate + self::FREE_SPACE_RESERVE);
    }

    /**
     * Failures and refusals with their reasons, newest first.
     *
     * The owner has no shell and no log access, so "9 failed" is not an answer;
     * which nine and why is.
     *
     * @return list<array{url: string, host: string|null, state: string, reason: string,
     *                    attempts: int, status_code: int|null, attempted_at: string|null}>
     */
    public function failures(int $limit = 200): array
    {
        $rows = DB::table(self::ITEMS)
            ->whereIn('state', [self::FAILED, self::REFUSED])
            ->orderByDesc('attempted_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'url' => (string) $row->url,
                'host' => $row->host === null ? null : (string) $row->host,
                'state' => (string) $row->state,
                'reason' => (string) ($row->reason ?? ''),
                'attempts' => (int) $row->attempts,
                'status_code' => $row->status_code === null ? null : (int) $row->status_code,
                'attempted_at' => $row->attempted_at === null ? null : (string) $row->attempted_at,
            ];
        }

        return $out;
    }

    /* ====================================================================== */
    /*  ONE BOUNDED BATCH                                                      */
    /* ====================================================================== */

    /**
     * Fetch up to `files` pictures, or `bytes` of them, or for `seconds`,
     * whichever ends first. Then return and let the browser ask again.
     *
     * This is `ImportDriver`'s shape and for `ImportDriver`'s reason: the host's
     * execution ceiling cannot be discovered from inside PHP, there is no queue
     * worker, so a long job has to be many short requests and the only question
     * that matters is what is true when one of them is killed. Here the answer
     * is "every file that finished is on disk and every file that did not is
     * not", which needs no reconciliation at all.
     *
     * @param  array{hosts?: list<string>, files?: int, bytes?: int, seconds?: int}  $options
     * @return array{ok: bool, fetched: int, failed: int, refused: int, bytes: int,
     *               stopped: string, results: list<array<string, mixed>>, plan: array<string, mixed>,
     *               ignored_hosts: list<string>, run: array<string, mixed>}
     */
    public function batch(array $options = []): array
    {
        $resolved = $this->resolveHosts($options['hosts'] ?? []);
        $hosts = $resolved['hosts'];

        $maxFiles = max(1, min(200, (int) ($options['files'] ?? self::DEFAULT_BATCH_FILES)));
        $maxBytes = max(1024, (int) ($options['bytes'] ?? self::DEFAULT_BATCH_BYTES));
        $maxSeconds = max(1, min(120, (int) ($options['seconds'] ?? self::DEFAULT_BATCH_SECONDS)));

        $plan = $this->plan($hosts);

        /*
         * FAIL LOUDLY AND EARLY RATHER THAN HALF-FILLING THE VOLUME. This is
         * checked before the first byte and again before each write; the first
         * check is the one that tells the owner something useful, because at
         * that point nothing has been written and nothing has to be cleaned up.
         */
        if (! $plan['enough_room']) {
            $reason = 'not enough free space. '.$this->bytes($plan['estimated_bytes'])
                .' is the estimate for '.$plan['remaining'].' outstanding pictures, plus '
                .$this->bytes(self::FREE_SPACE_RESERVE).' held back, and the volume has '
                .$this->bytes((int) ($plan['free_bytes'] ?? 0)).' free. Nothing was written.';

            $run = $this->stopRun($reason);

            return [
                'ok' => false,
                'fetched' => 0,
                'failed' => 0,
                'refused' => 0,
                'bytes' => 0,
                'stopped' => $reason,
                'results' => [],
                'plan' => $plan,
                'ignored_hosts' => $resolved['ignored'],
                'run' => $run,
            ];
        }

        $run = $this->startOrContinueRun();

        $started = microtime(true);
        $fetched = 0;
        $failed = 0;
        $refused = 0;
        $bytes = 0;
        $results = [];
        $landed = [];
        $stopped = 'nothing left to fetch';

        $ledger = $this->ledger();

        foreach ($this->untriedFirst($this->references($hosts), $ledger) as $reference) {
            if (count($results) >= $maxFiles) {
                $stopped = 'this batch\'s file limit ('.$maxFiles.'); press again to continue';

                break;
            }

            if ($bytes >= $maxBytes) {
                $stopped = 'this batch\'s byte budget ('.$this->bytes($maxBytes).'); press again to continue';

                break;
            }

            if ((microtime(true) - $started) >= $maxSeconds) {
                $stopped = 'this batch\'s time budget ('.$maxSeconds.'s); press again to continue';

                break;
            }

            $hash = self::hash($reference['url']);
            $state = $ledger[$hash]['state'] ?? null;

            // A path that cannot be made safe is refused once, permanently.
            if ($reference['refusal'] !== null) {
                if ($state !== self::REFUSED) {
                    $this->record($reference, self::REFUSED, $reference['refusal']);
                    $refused++;
                    $results[] = $this->result($reference, self::REFUSED, $reference['refusal'], 0);
                }

                continue;
            }

            if ($state === self::REFUSED) {
                continue;
            }

            $full = $this->absolute((string) $reference['path']);

            if ($full === null) {
                // absolute() has already decided this escapes the web root.
                $reason = 'this path does not resolve to a location inside the web root, so nothing will be '
                    .'written for it';
                $this->record($reference, self::REFUSED, $reason);
                $refused++;
                $results[] = $this->result($reference, self::REFUSED, $reason, 0);

                continue;
            }

            /*
             * ALREADY ON DISK IS THE WHOLE IDEMPOTENCY STORY. Checked here, per
             * file, immediately before the fetch — not from the plan taken at
             * the top of the batch, because a concurrent batch in another tab
             * may have landed it since.
             */
            if (is_file($full)) {
                continue;
            }

            // Room for one more capped file, checked against the real volume.
            $free = $this->freeBytes();

            if ($free !== null && $free < (self::MAX_FILE_BYTES + self::FREE_SPACE_RESERVE)) {
                $stopped = 'the volume is down to '.$this->bytes($free)
                    .' free, which is not enough room for another picture. Nothing further was written.';
                $this->stopRun($stopped);

                return [
                    'ok' => false,
                    'fetched' => $fetched,
                    'failed' => $failed,
                    'refused' => $refused,
                    'bytes' => $bytes,
                    'stopped' => $stopped,
                    'results' => $results,
                    'plan' => $this->plan($hosts),
                    'ignored_hosts' => $resolved['ignored'],
                    'run' => $this->run(),
                ];
            }

            $outcome = $this->fetchOne($reference['url'], $reference['host'], (string) $reference['path'], $full);

            if ($outcome['state'] === self::FETCHED) {
                $fetched++;
                $bytes += $outcome['bytes'];
                $landed[] = $reference['url'];
            } elseif ($outcome['state'] === self::REFUSED) {
                $refused++;
            } else {
                $failed++;
            }

            $this->record($reference, $outcome['state'], $outcome['reason'], $outcome['bytes'], $outcome['status'], $outcome['type']);
            $results[] = $this->result($reference, $outcome['state'], $outcome['reason'], $outcome['bytes'], $outcome['status']);
        }

        /*
         * AND THE ROWS ARE RE-POINTED IN THE SAME REQUEST THAT LANDED THE FILE.
         * See repoint(). This is before plan() deliberately: the plan the
         * caller gets back must describe the shop as it is when the response is
         * written, not as it was one statement earlier.
         */
        $repointed = $this->repoint($landed);

        $after = $this->plan($hosts);
        $run = $this->endBatch($fetched, $failed, $bytes, $after['remaining'] === 0 ? 'nothing left to fetch' : null);

        return [
            'ok' => true,
            'fetched' => $fetched,
            'failed' => $failed,
            'refused' => $refused,
            'bytes' => $bytes,
            'repointed' => $repointed,
            'stopped' => $stopped,
            'results' => $results,
            'plan' => $after,
            'ignored_hosts' => $resolved['ignored'],
            'run' => $run,
        ];
    }

    /**
     * Take the rows that named the addresses THIS BATCH just landed off the
     * old host.
     *
     * =========================================================================
     * THE DEFECT THIS CLOSES, WHICH WAS A PROCEDURE AND NOT A BUG
     * =========================================================================
     *
     * Fetching and re-pointing were two steps with a gap between them, and the
     * gap had a property nobody could see from the shop: the photograph was on
     * this server's disk and the product row still said `https://<old
     * host>/…`. Everything rendered perfectly, from the old site. The whole
     * instruction that came out of that was **"do not switch the old site off
     * between the two steps"** — which is a sentence in a runbook standing in
     * for a fix, on a migration whose entire point is that the old site gets
     * switched off.
     *
     * So the step that lands the bytes re-points the rows that referenced them,
     * in the same request, before it answers. After a batch there is no state
     * in which the file is here and the row points there.
     *
     * =========================================================================
     * ONLY WHAT THIS BATCH FETCHED, AND WHY THAT RESTRAINT IS DELIBERATE
     * =========================================================================
     *
     * `MediaRewrite::propose()` would happily offer every row on those hosts
     * whose file is on disk — including files that arrived by FTP months ago,
     * which is a job the owner starts himself from Store → Import → Addresses &
     * pictures → apply. Re-pointing those as a side effect of pressing Fetch
     * would be this step doing something nobody asked it for, so the proposals
     * are filtered down to the addresses this batch actually wrote. The manual
     * apply still exists and still does the rest.
     *
     * IT INHERITS EVERY GUARD `MediaRewrite` ALREADY HAS, because it goes
     * through `propose()` rather than around it: only a file that is really on
     * disk, only a path under one of the two upload roots, only a value that is
     * still byte-identical to what the proposal was built from, and the whole
     * set in one transaction.
     *
     * BOTH SHAPES. A cell (`products.image`, `posts.cover`) goes through
     * `MediaRewrite`; an `<img>` inside `posts.body` goes through
     * `DocumentMediaRewrite`. The Journal's pictures are fetched by this class
     * exactly like a product's, and leaving the document half out would land
     * the file and leave the article hot-linked — the same gap one level down.
     *
     * @param  list<string>  $urls  the addresses this batch wrote to disk
     * @return array{rows: int, documents: int}
     */
    private function repoint(array $urls): array
    {
        $none = ['rows' => 0, 'documents' => 0];

        if ($urls === []) {
            return $none;
        }

        $hosts = [];
        $wanted = [];

        foreach ($urls as $url) {
            $host = parse_url($url, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[strtolower($host)] = true;
            }

            $wanted[$url] = true;
        }

        if ($hosts === []) {
            return $none;
        }

        $hosts = array_keys($hosts);

        $mine = static fn (array $proposal): bool => $proposal['decision'] === MediaRewrite::REWRITE
            && isset($wanted[$proposal['from']]);

        $cells = new MediaRewrite;
        $documents = new DocumentMediaRewrite;

        return [
            'rows' => $cells->apply(array_values(array_filter($cells->propose($hosts), $mine))),
            'documents' => $documents->apply(array_values(array_filter($documents->propose($hosts), $mine))),
        ];
    }

    /* ====================================================================== */
    /*  ONE FILE                                                               */
    /* ====================================================================== */

    /**
     * References nobody has attempted yet, then the ones that failed before.
     *
     * FOUND BY A REAL MULTI-BATCH RUN, not by reading the code. In catalogue
     * order the eleven deliberately broken references sat in a block, so one
     * batch consisted entirely of failures — and the live page's loop, which
     * quite correctly stops when a whole batch fetched nothing rather than
     * hammering a dying host for ever, stopped there. Four perfectly good
     * photographs behind that block were never reached until somebody pressed
     * the button again.
     *
     * Ordering untried work first makes "a batch that fetched nothing" mean
     * what the loop assumes it means: everything still outstanding has already
     * been tried and failed. The retry of those is then the last thing a run
     * does, which is also the right order for the owner — new work before old
     * disappointments.
     *
     * A stable partition, not a sort: within each half the catalogue's own
     * order is kept, so the run is still predictable and a resumed run does not
     * reshuffle what it was part-way through.
     *
     * @param  list<array{url: string, host: string, path: string|null, refusal: string|null, owners: list<string>}>  $references
     * @param  array<string, array{state: string}>  $ledger
     * @return list<array{url: string, host: string, path: string|null, refusal: string|null, owners: list<string>}>
     */
    private function untriedFirst(array $references, array $ledger): array
    {
        $untried = [];
        $tried = [];

        foreach ($references as $reference) {
            if (isset($ledger[self::hash($reference['url'])])) {
                $tried[] = $reference;

                continue;
            }

            $untried[] = $reference;
        }

        return array_merge($untried, $tried);
    }

    /**
     * Fetch, validate and land exactly one picture.
     *
     * Returns rather than throws, because ONE BAD FILE MUST NOT STOP THE RUN —
     * the same contract `EntityReport::reject()` gives a refused row.
     *
     * @return array{state: string, reason: string, bytes: int, status: int|null, type: string|null}
     */
    public function fetchOne(string $url, string $host, string $path, string $full): array
    {
        $temp = null;

        try {
            $hop = $url;
            $status = null;
            $response = null;

            for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
                $response = Http::withOptions([
                    'stream' => true,
                    // Guard 2. Guzzle's own follower does not know which hosts
                    // this shop is willing to talk to; this loop does.
                    'allow_redirects' => false,
                ])
                    ->connectTimeout(self::CONNECT_TIMEOUT)   // guard 8
                    ->timeout(self::READ_TIMEOUT)             // guard 8
                    ->withHeaders([
                        'Accept' => 'image/*',
                        'User-Agent' => 'KBB-Sideloader/1.0 (+migration)',
                    ])
                    ->get($hop);

                $status = $response->status();

                if ($status < 300 || $status >= 400) {
                    break;
                }

                $location = (string) $response->header('Location');

                if (trim($location) === '') {
                    return $this->fail('the old host answered '.$status.' with no Location to follow', $status);
                }

                $next = $this->resolveRedirect($hop, $location);

                if ($next === null) {
                    return $this->fail('the old host redirected to something this will not follow: '.$location, $status);
                }

                $nextHost = strtolower((string) parse_url($next, PHP_URL_HOST));

                if ($nextHost !== strtolower((string) parse_url($hop, PHP_URL_HOST))) {
                    // Guard 2. The loud one: this is the SSRF hop.
                    return $this->fail(
                        'refused to follow a redirect from '.parse_url($hop, PHP_URL_HOST).' to '.$nextHost
                        .'. A picture is fetched from the host the catalogue names and from nowhere else.',
                        $status,
                    );
                }

                $hop = $next;

                if ($redirects === self::MAX_REDIRECTS) {
                    return $this->fail('more than '.self::MAX_REDIRECTS.' redirects; gave up', $status);
                }
            }

            if ($response === null) {
                return $this->fail('no response at all from the old host', null);
            }

            if ($status === null || $status < 200 || $status >= 300) {
                return $this->fail(
                    'the old host answered '.$status.'. If that is 404 the picture is gone from WordPress too '
                    .'and the row needs a new one.',
                    $status,
                );
            }

            $declared = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

            // Guard 3, first half: the declared type.
            if (! array_key_exists($declared, self::SAFE_TYPES)) {
                return $this->fail(
                    'the old host called this "'.($declared === '' ? '(nothing)' : $declared).'", which is not an '
                    .'image type this will write into the web root',
                    $status,
                    $declared,
                );
            }

            // Guard 7, early refusal on a declared length. Never trusted as the
            // real size — the cap below is what actually holds.
            $length = $response->header('Content-Length');

            if (is_numeric($length) && (int) $length > self::MAX_FILE_BYTES) {
                return $this->fail(
                    'the old host says this is '.$this->bytes((int) $length).', over the '
                    .$this->bytes(self::MAX_FILE_BYTES).' limit for one picture',
                    $status,
                    $declared,
                );
            }

            $directory = dirname($full);

            if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
                return $this->fail('could not create '.$directory.' under the web root', $status, $declared);
            }

            /*
             * The temporary file is a sibling of the final one so the rename is
             * atomic — a rename across filesystems is a copy, and a copy can be
             * interrupted halfway, which would produce exactly the truncated
             * file this design promises cannot exist. Its name carries no
             * extension a web server would ever hand to an interpreter, and it
             * is unlinked on every path out of here.
             */
            $temp = $directory.'/.kbb-sideload-'.bin2hex(random_bytes(8)).'.part';
            $handle = @fopen($temp, 'wb');

            if ($handle === false) {
                return $this->fail('could not open a temporary file in '.$directory, $status, $declared);
            }

            $body = $response->toPsrResponse()->getBody();
            $written = 0;
            $head = '';

            // Guard 7: read in chunks, abandon the moment the cap is passed.
            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK);

                if ($chunk === '') {
                    break;
                }

                $written += strlen($chunk);

                if (strlen($head) < 4096) {
                    $head .= substr($chunk, 0, 4096 - strlen($head));
                }

                if ($written > self::MAX_FILE_BYTES) {
                    fclose($handle);
                    @unlink($temp);
                    $temp = null;

                    return $this->fail(
                        'this is larger than the '.$this->bytes(self::MAX_FILE_BYTES).' limit for one picture; '
                        .'the download was abandoned',
                        $status,
                        $declared,
                    );
                }

                fwrite($handle, $chunk);
            }

            fclose($handle);

            if ($written === 0) {
                @unlink($temp);
                $temp = null;

                return $this->fail('the old host returned an empty body', $status, $declared);
            }

            // Guard 3, second half: what the bytes actually are.
            $sniffed = self::sniff($head);

            if ($sniffed === null) {
                return $this->fail(
                    'the body is not an image. The old host declared '.$declared.' and sent '
                    .$this->describe($head).' — nothing was written.',
                    $status,
                    $declared,
                );
            }

            if ($sniffed !== $declared) {
                return $this->fail(
                    'the old host declared '.$declared.' and sent '.$sniffed.'. A body that is not what its '
                    .'header claims is not written into the web root.',
                    $status,
                    $declared,
                );
            }

            // Guard 4: the URL's extension must agree with the bytes.
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (! in_array($extension, self::SAFE_TYPES[$sniffed], true)) {
                return $this->fail(
                    'the address ends in .'.$extension.' and the bytes are '.$sniffed.'. The file is saved under '
                    .'the name the catalogue rows point at, so a name that disagrees with its contents is '
                    .'refused rather than renamed — renaming it would leave every row pointing at a file that '
                    .'is not there.',
                    $status,
                    $declared,
                );
            }

            if (! @rename($temp, $full)) {
                return $this->fail('could not move the finished file into '.$full, $status, $declared);
            }

            $temp = null;
            @chmod($full, 0o644);

            return [
                'state' => self::FETCHED,
                'reason' => 'fetched '.$this->bytes($written).' from '.$host,
                'bytes' => $written,
                'status' => $status,
                'type' => $sniffed,
            ];
        } catch (ConnectionException $e) {
            /*
             * THE TRANSPORT'S OWN WORDING IS NOT THE HEADLINE, and that is a
             * correction a real run forced. A host that accepted the connection
             * and then said nothing for the full read timeout came back as
             * "Connection refused for URI ...", which is Guzzle describing a
             * cURL failure class and not what happened: nothing was refused,
             * the old host simply never answered. An owner with no shell and no
             * log reads that sentence and goes looking for a firewall.
             *
             * So the sentence that is true of every case in this branch comes
             * first, and the transport's message is kept after it, in brackets,
             * for whoever does have a log.
             */
            return $this->fail(
                $host.' did not answer within '.self::CONNECT_TIMEOUT.'s to connect or '
                .self::READ_TIMEOUT.'s to read. The old host may be slow, down, or blocking this shop — '
                .'press Fetch again later and it will pick this one up. ('.$this->short($e->getMessage()).')',
                null,
            );
        } catch (\Throwable $e) {
            // One bad file does not stop the run — not even one that throws.
            return $this->fail($this->short($e->getMessage()), null);
        } finally {
            if ($temp !== null && is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /* ====================================================================== */
    /*  PATHS                                                                  */
    /* ====================================================================== */

    /**
     * The path under the WEB ROOT this URL's file belongs at, or a refusal.
     *
     * Guards 4, 5 and 6 live here, and the order matters: normalise first
     * (which rawurldecodes, so an escaped traversal is a real one by the time
     * it is checked), then reject the shapes, then find the uploads root.
     *
     * DELIBERATELY NOT `MediaRewrite::uploadsRelative()`, and the duplication is
     * on purpose rather than by oversight. That method answers "which rows may
     * I rewrite", where a wrong answer writes a string into a column. This one
     * answers "where may I write bytes into the web root", where a wrong answer
     * is a remote code execution. It agrees with MediaRewrite on the two upload
     * roots — it has to, or the file lands where nothing looks for it — and is
     * strictly harsher about everything else. Unifying them would mean either
     * loosening this or refusing rows MediaRewrite can safely handle.
     *
     * @return array{path: string|null, refusal: string|null}
     */
    public function targetPath(string $url): array
    {
        $path = MediaUsage::normalise($url);

        if ($path === '') {
            return ['path' => null, 'refusal' => 'this value carries no path at all'];
        }

        // Guard 6: bytes that have no business in a filename.
        if (str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return ['path' => null, 'refusal' => 'this address contains a control character'];
        }

        if (str_contains($path, '\\')) {
            return ['path' => null, 'refusal' => 'this address contains a backslash'];
        }

        $path = ltrim($path, '/');
        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return [
                    'path' => null,
                    'refusal' => 'this address contains a "'.$segment.'" segment. A path that walks out of the '
                        .'uploads folder is refused outright; nothing is written for it.',
                ];
            }
        }

        $relative = null;

        // Longest root first: "wp-content/uploads/" contains "uploads/", and
        // matching the shorter one first cuts in the middle of it.
        foreach (self::UPLOAD_ROOTS as $root) {
            $at = strpos($path, $root);

            if ($at !== false) {
                $relative = substr($path, $at);

                break;
            }
        }

        if ($relative === null) {
            return [
                'path' => null,
                'refusal' => 'this address is not under wp-content/uploads/ or uploads/, so there is nowhere under '
                    .'the web root it belongs. MediaRewrite skips these for the same reason — it may be a supplier\'s '
                    .'photograph or an address typed by hand, and neither is this shop\'s file to copy.',
            ];
        }

        $basename = basename($relative);

        if ($basename === '' || str_starts_with($basename, '.')) {
            return ['path' => null, 'refusal' => 'this address names a dotfile, not a picture'];
        }

        // Guard 5: EVERY dot-separated part, not only the last.
        foreach (explode('.', strtolower($basename)) as $part) {
            if (in_array($part, self::DANGEROUS_PARTS, true)) {
                return [
                    'path' => null,
                    'refusal' => 'the filename contains a ".'.$part.'" part. Anything that a web server might hand '
                        .'to an interpreter is refused whatever else its name says — "photo.php.jpg" is refused on '
                        .'the "php" in the middle.',
                ];
            }
        }

        // Guard 4: only the extensions in SAFE_TYPES may ever be written.
        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        $allowed = [];

        foreach (self::SAFE_TYPES as $extensions) {
            $allowed = array_merge($allowed, $extensions);
        }

        if (! in_array($extension, $allowed, true)) {
            return [
                'path' => null,
                'refusal' => 'the filename ends in "'.($extension === '' ? '(no extension)' : '.'.$extension)
                    .'", which is not one of '.implode(', ', $allowed).'. SVG is excluded on purpose: it is a '
                    .'document that executes script in this shop\'s own origin.',
            ];
        }

        return ['path' => $relative, 'refusal' => null];
    }

    /**
     * The absolute path under the web root, or null if it would escape it.
     *
     * The last line of guard 6, and it is a separate check on the RESOLVED
     * path rather than a second opinion about the string: a string test that
     * has already passed is not evidence about where the filesystem will
     * actually put the bytes. `public_path()` is the only correct way to ask
     * where the web root is on this server — it is a different directory from
     * the application root.
     */
    public function absolute(string $relative): ?string
    {
        if ($relative === '') {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', public_path()), '/');
        $candidate = $root.'/'.ltrim($relative, '/');

        /*
         * realpath() on the deepest directory that exists, because the file
         * itself does not exist yet and realpath(nonexistent) is false. A
         * symlinked year folder pointing outside the web root is the case this
         * catches and the string test above does not.
         */
        $probe = dirname($candidate);

        while ($probe !== '' && $probe !== '/' && ! is_dir($probe)) {
            $probe = dirname($probe);
        }

        $realProbe = realpath($probe);
        $realRoot = realpath($root);

        if ($realProbe === false || $realRoot === false) {
            // Nothing resolvable. In a checkout the web root may not exist yet;
            // the string test is then all there is, and it has already passed.
            return str_starts_with($candidate, $root.'/') ? $candidate : null;
        }

        $realProbe = rtrim(str_replace('\\', '/', $realProbe), '/');
        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');

        if ($realProbe !== $realRoot && ! str_starts_with($realProbe.'/', $realRoot.'/')) {
            return null;
        }

        return $candidate;
    }

    /**
     * Where a `Location:` actually points, or null if this will not go there.
     *
     * Only http and https. A `Location: file:///etc/passwd` or a
     * `Location: gopher://…` is a redirect this refuses to resolve at all
     * rather than one it resolves and then rejects.
     */
    public function resolveRedirect(string $from, string $location): ?string
    {
        $location = trim($location);

        if ($location === '' || preg_match('/[\x00-\x1F\x7F]/', $location) === 1) {
            return null;
        }

        $parts = parse_url($location);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['scheme'])) {
            if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
                return null;
            }

            return $location;
        }

        $base = parse_url($from);

        if (! is_array($base) || ! isset($base['scheme'], $base['host'])) {
            return null;
        }

        // Protocol-relative: //other-host/path — carries its own host, so it is
        // a host change and the caller's host check must see it as one.
        if (str_starts_with($location, '//')) {
            return $base['scheme'].':'.$location;
        }

        $authority = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');

        if (str_starts_with($location, '/')) {
            return $authority.$location;
        }

        $directory = rtrim(dirname((string) ($base['path'] ?? '/')), '/');

        return $authority.$directory.'/'.$location;
    }

    /* ====================================================================== */
    /*  SNIFFING                                                               */
    /* ====================================================================== */

    /**
     * What these bytes ACTUALLY are, by magic number. Null for anything else.
     *
     * Guard 3's second half. `getimagesizefromstring()` was not used as the
     * only test: it is a large, historically CVE-prone parser being handed
     * hostile bytes, it does not know webp or avif on every build, and it
     * answers "is this parseable" rather than "is this safe to put in the web
     * root". Magic numbers are a fixed, auditable test that does not parse
     * anything.
     *
     * The executable-marker sweep afterwards is belt and braces and it is not
     * redundant: a file can begin with a valid GIF header and carry `<?php`
     * three bytes later, which is the classic polyglot upload. A picture with
     * that string in it is refused, and the cost of that false positive — an
     * owner re-uploading one photograph by hand — is not comparable.
     */
    public static function sniff(string $head): ?string
    {
        if (strlen($head) < 12) {
            return null;
        }

        $type = null;

        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            $type = 'image/jpeg';
        } elseif (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            $type = 'image/png';
        } elseif (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            $type = 'image/gif';
        } elseif (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            $type = 'image/webp';
        } elseif (substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['avif', 'avis'], true)) {
            $type = 'image/avif';
        }

        if ($type === null) {
            return null;
        }

        $lower = strtolower($head);

        foreach (['<?php', '<?=', '<script', '<html', '<!doctype', '<svg', '#!/'] as $marker) {
            if (str_contains($lower, $marker)) {
                return null;
            }
        }

        return $type;
    }

    /** A few readable words about what arrived instead of an image. */
    private function describe(string $head): string
    {
        $lower = strtolower($head);

        if (str_contains($lower, '<?php') || str_contains($lower, '<?=')) {
            return 'PHP source';
        }

        if (str_contains($lower, '<html') || str_contains($lower, '<!doctype')) {
            return 'an HTML page (usually the old site\'s 404 or a login wall)';
        }

        if (str_contains($lower, '<svg')) {
            return 'an SVG, which this will not write into the web root';
        }

        return 'bytes that match no image format this accepts';
    }

    /* ====================================================================== */
    /*  THE LEDGER AND THE RUN                                                 */
    /* ====================================================================== */

    public static function hash(string $url): string
    {
        return hash('sha256', $url);
    }

    /** @return array<string, array{state: string}> */
    private function ledger(): array
    {
        $out = [];

        foreach (DB::table(self::ITEMS)->select(['url_hash', 'state'])->cursor() as $row) {
            $out[(string) $row->url_hash] = ['state' => (string) $row->state];
        }

        return $out;
    }

    /**
     * @param  array{url: string, host: string, path: string|null, refusal: string|null, owners: list<string>}  $reference
     */
    private function record(
        array $reference,
        string $state,
        string $reason,
        int $bytes = 0,
        ?int $status = null,
        ?string $type = null,
    ): void {
        $hash = self::hash($reference['url']);
        $existing = DB::table(self::ITEMS)->where('url_hash', $hash)->first();

        $values = [
            'url' => $reference['url'],
            'host' => $reference['host'],
            'target_path' => $reference['path'],
            'state' => $state,
            'reason' => $reason,
            'bytes' => $bytes,
            'attempts' => (int) ($existing->attempts ?? 0) + 1,
            'status_code' => $status,
            'content_type' => $type,
            'attempted_at' => now(),
            'fetched_at' => $state === self::FETCHED ? now() : ($existing->fetched_at ?? null),
            'updated_at' => now(),
        ];

        if ($existing === null) {
            DB::table(self::ITEMS)->insert($values + ['url_hash' => $hash, 'created_at' => now()]);

            return;
        }

        DB::table(self::ITEMS)->where('id', $existing->id)->update($values);
    }

    /**
     * @param  array{url: string, host: string, path: string|null, owners: list<string>}  $reference
     * @return array<string, mixed>
     */
    private function result(array $reference, string $state, string $reason, int $bytes, ?int $status = null): array
    {
        return [
            'url' => $reference['url'],
            'host' => $reference['host'],
            'path' => $reference['path'],
            'state' => $state,
            'reason' => $reason,
            'bytes' => $bytes,
            'status' => $status,
            'used_by' => array_slice($reference['owners'], 0, 5),
        ];
    }

    /** @return array{state: string, reason: string, bytes: int, status: int|null, type: string|null} */
    private function fail(string $reason, ?int $status, ?string $type = null): array
    {
        return ['state' => self::FAILED, 'reason' => $reason, 'bytes' => 0, 'status' => $status, 'type' => $type];
    }

    /** @return array<string, mixed> */
    private function startOrContinueRun(): array
    {
        $current = DB::table(self::RUNS)->whereNull('finished_at')->orderByDesc('id')->first();

        if ($current === null) {
            $id = DB::table(self::RUNS)->insertGetId([
                'started_at' => now(),
                'heartbeat_at' => now(),
                'finished_at' => null,
                'batches' => 0,
                'fetched' => 0,
                'failed' => 0,
                'bytes' => 0,
                'stopped_reason' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->run($id);
        }

        DB::table(self::RUNS)->where('id', $current->id)->update([
            'heartbeat_at' => now(),
            'stopped_reason' => null,
            'updated_at' => now(),
        ]);

        return $this->run((int) $current->id);
    }

    /** @return array<string, mixed> */
    private function endBatch(int $fetched, int $failed, int $bytes, ?string $finish): array
    {
        $current = DB::table(self::RUNS)->whereNull('finished_at')->orderByDesc('id')->first();

        if ($current === null) {
            return $this->run();
        }

        DB::table(self::RUNS)->where('id', $current->id)->update([
            'heartbeat_at' => now(),
            'finished_at' => $finish === null ? null : now(),
            'stopped_reason' => $finish,
            'batches' => (int) $current->batches + 1,
            'fetched' => (int) $current->fetched + $fetched,
            'failed' => (int) $current->failed + $failed,
            'bytes' => (int) $current->bytes + $bytes,
            'updated_at' => now(),
        ]);

        return $this->run((int) $current->id);
    }

    /** @return array<string, mixed> */
    private function stopRun(string $reason): array
    {
        $current = DB::table(self::RUNS)->whereNull('finished_at')->orderByDesc('id')->first();

        if ($current === null) {
            return $this->run();
        }

        DB::table(self::RUNS)->where('id', $current->id)->update([
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'stopped_reason' => $reason,
            'updated_at' => now(),
        ]);

        return $this->run((int) $current->id);
    }

    /**
     * Mark the run finished because a person said so, not because it ran out.
     *
     * @return array<string, mixed>
     */
    public function stop(string $reason = 'stopped from the admin screen'): array
    {
        return $this->stopRun($reason);
    }

    /**
     * Clear the failure ledger so failed references are tried again.
     *
     * Refusals are NOT cleared by this. A refusal is a decision about a shape
     * that cannot be made safe, and re-trying it produces the identical refusal
     * one batch later; offering a button that appears to do something and
     * cannot is worse than not offering it. `retryRefusals` is a separate,
     * explicit argument for the case where the catalogue itself was corrected.
     */
    public function retry(bool $retryRefusals = false): int
    {
        $query = DB::table(self::ITEMS)->where('state', self::FAILED);

        if ($retryRefusals) {
            $query = DB::table(self::ITEMS)->whereIn('state', [self::FAILED, self::REFUSED]);
        }

        return $query->delete();
    }

    /**
     * The run as the live page reads it: IDLE, RUNNING, STALLED or NEVER.
     *
     * THIS IS HALF THE ANSWER TO THE OLD HEADER'S OBJECTION. "Running, 118 of
     * 530" and "idle, 412 still to fetch" are different facts, and a bar
     * frozen at 118 because the request was killed is a third. A screen that
     * renders all three identically is the silent half-success that was the
     * whole reason a downloader was refused.
     *
     * @return array<string, mixed>
     */
    public function run(?int $id = null): array
    {
        $row = $id === null
            ? DB::table(self::RUNS)->orderByDesc('id')->first()
            : DB::table(self::RUNS)->where('id', $id)->first();

        if ($row === null) {
            return [
                'state' => 'never',
                'note' => 'this shop has never fetched a picture from the old site',
                'started_at' => null,
                'heartbeat_at' => null,
                'finished_at' => null,
                'batches' => 0,
                'fetched' => 0,
                'failed' => 0,
                'bytes' => 0,
                'stopped_reason' => null,
                'seconds_since_heartbeat' => null,
            ];
        }

        $beat = $row->heartbeat_at === null ? null : strtotime((string) $row->heartbeat_at);
        $since = $beat === false || $beat === null ? null : max(0, time() - $beat);

        if ($row->finished_at !== null) {
            $state = 'idle';
            $note = (string) ($row->stopped_reason ?? 'the last run finished');
        } elseif ($since !== null && $since > self::STALE_SECONDS) {
            $state = 'stalled';
            $note = 'a run started and its last batch was '.$since.' seconds ago, which is longer than this '
                .'expects between batches. The request was probably killed by the host\'s execution limit. '
                .'Nothing is lost — press Fetch again and it continues from exactly the pictures that are not '
                .'yet on disk.';
        } else {
            $state = 'running';
            $note = 'a batch finished '.($since ?? 0).' seconds ago';
        }

        return [
            'state' => $state,
            'note' => $note,
            'started_at' => $row->started_at === null ? null : (string) $row->started_at,
            'heartbeat_at' => $row->heartbeat_at === null ? null : (string) $row->heartbeat_at,
            'finished_at' => $row->finished_at === null ? null : (string) $row->finished_at,
            'batches' => (int) $row->batches,
            'fetched' => (int) $row->fetched,
            'failed' => (int) $row->failed,
            'bytes' => (int) $row->bytes,
            'stopped_reason' => $row->stopped_reason === null ? null : (string) $row->stopped_reason,
            'seconds_since_heartbeat' => $since,
        ];
    }

    /* ====================================================================== */
    /*  ODDS AND ENDS                                                          */
    /* ====================================================================== */

    public function bytes(int $n): string
    {
        if ($n < 1024) {
            return $n.' B';
        }

        foreach (['KB', 'MB', 'GB', 'TB'] as $i => $unit) {
            $value = $n / (1024 ** ($i + 1));

            if ($value < 1024 || $unit === 'TB') {
                return (($value < 10) ? number_format($value, 1) : number_format($value, 0)).' '.$unit;
            }
        }

        return $n.' B';
    }

    private function short(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return strlen($message) > 300 ? substr($message, 0, 297).'...' : $message;
    }
}
