<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Services\DomainMove\DomainReadiness;
use App\Services\DomainMove\DomainSwitch;
use App\Services\DomainMove\SwitchInstaller;
use App\Support\ImageVariants;
use App\Support\MediaRegistrar;
use App\Support\MediaUsage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fetch the pictures the shop names and does not have FROM THE OLD SERVER BY
 * ITS ADDRESS, keeping the real name. (Lane PX, 2.60.453)
 *
 * THE DEFECT, AS IT LOOKED ON THE SHOP. kbeautybliss.com moved from Hostinger
 * (177.202.242.149) to this server. Some files under /wp-content/uploads/ were
 * never copied — measured on the live server: 2024/11/ has
 * medicube-Deep-Vita-C-Capsule-Cream.webp, -1, -2 and -4, and NOT -3, which is
 * the one the owner reported as a 404. Before the switch the browser fetched
 * it from Hostinger through the kbeautybliss.com name; now that name is this
 * server, the picture is a blank frame, and MediaSideloader cannot help: it
 * fetches by the URL's host, which now resolves here and answers 404 to itself.
 *
 * THE ANSWER. Ask Hostinger directly: curl's CURLOPT_RESOLVE pins
 * kbeautybliss.com (and www.) to the old address for this one request, so the
 * TCP connection goes to Hostinger while the TLS SNI, the certificate check and
 * the Host header all stay kbeautybliss.com. Verification stays ON. When
 * Hostinger's certificate does not validate, the run stops and SAYS so; plain
 * HTTP to the same address is a separate choice the owner makes, never a
 * silent fallback.
 *
 * Each file lands at the SAME path under the web root, so every stored URL
 * starts working with no database change, and gets its img-cache copies the
 * way an upload does (ImageVariants::generate) and a Media Library row the way
 * the sideloader does (MediaRegistrar::record).
 *
 * THE WORK LIST is every /wp-content/uploads/ picture the database names:
 * MediaAudit's rows (product, gallery, variant, description, category, brand,
 * article, block, review, share image) plus a sweep of every other text column
 * of every live table (banners, pages, menus, settings, …). It is built by
 * scan() — the Check button, no network — and kept in `old_picture_fetches`,
 * so a run resumes onto exactly the rows still `pending`.
 *
 * GUARDS, each one a test in OldServerPicturesTest:
 *  · the address must be a PUBLIC IPv4/IPv6 and not this server's own;
 *  · only the shop's own names are asked for, only wp-content/uploads/ image
 *    paths are requested, and MediaSideloader::targetPath()/absolute() decide
 *    where bytes may land (no traversal, no .php/.svg part, inside the web root);
 *  · declared type, magic bytes and extension must agree; 12 MB cap per file;
 *  · a file already on disk is NEVER overwritten (link(), which fails on an
 *    existing name, then a re-check before any rename);
 *  · a pause between requests, a few files per step, so Hostinger is asked
 *    politely.
 */
final class OldServerPictures
{
    public const TABLE = 'old_picture_fetches';

    public const PENDING = 'pending';

    public const FETCHED = 'fetched';

    /** Retryable: the network, a 5xx, a certificate. */
    public const FAILED = 'failed';

    /** 404/410 on the old server too: the product needs a new picture. */
    public const GONE = 'gone';

    /** Not a picture, too big, or a path this will not write. */
    public const REFUSED = 'refused';

    /** On disk by the time it was tried (copied by FTP): the row is dropped. */
    public const PRESENT = 'present';

    public const DEFAULT_FILES = 8;

    public const DEFAULT_SECONDS = 12;

    /** Milliseconds between two requests to the old server. */
    public const DEFAULT_PAUSE_MS = 300;

    public const MAX_REDIRECTS = 3;

    public const CONNECT_TIMEOUT = 8;

    public const TIMEOUT = 40;

    private const ROOT = 'wp-content/uploads/';

    private const RUN_KEY = 'kbb.old_pictures.run';

    /** curl errors that mean "the certificate did not validate". */
    private const TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    /**
     * Columns MediaAudit already reads with a proper owner label; the sweep
     * leaves them alone so one row is not listed twice under two names.
     */
    private const AUDITED = [
        'products' => ['image', 'images', 'seo', 'description', 'short_description'],
        'product_variants' => ['image'],
        'brands' => ['logo', 'header_image'],
        'categories' => ['image', 'header_image'],
        'posts' => ['cover', 'body'],
        'reviews' => ['images'],
    ];

    /** Catalogues of files, not references to them. */
    private const NOT_REFERENCES = [self::TABLE, 'media', 'media_usage_records', 'redirects', 'translations_cache'];

    private const OWNERS_KEPT = 6;

    /**
     * @param  \Closure(array<int, mixed>): array{status: int, headers: array<string, string>, body: string, errno: int, error: string}|null  $transport
     */
    public function __construct(
        private readonly MediaSideloader $guards = new MediaSideloader,
        private readonly MediaAudit $audit = new MediaAudit,
        private readonly ?\Closure $transport = null,
    ) {}

    /* ================================================================ names */

    /** The name Hostinger serves the old shop under: kbeautybliss.com. */
    public function fetchHost(): string
    {
        return app(DomainSwitch::class)->newBare();
    }

    /** @return list<string> the names a reference may carry and still be the shop's own */
    public function shopHosts(): array
    {
        $switch = app(DomainSwitch::class);
        $out = [];

        foreach ([$switch->newBare(), ...$switch->oldHosts()] as $bare) {
            $out[] = $bare;
            $out[] = 'www.'.$bare;
        }

        return array_values(array_unique($out));
    }

    private function isShopHost(string $host): bool
    {
        $host = strtolower($host);

        return in_array($host, $this->shopHosts(), true) || $this->audit->isOwnHost($host);
    }

    /**
     * Null when the address may be used, else the sentence that refuses it.
     * The SSRF guard: a PUBLIC address only, and never this server.
     */
    public function ipRefusal(string $ip): ?string
    {
        $ip = trim($ip, " \t[]");

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '"'.mb_substr($ip, 0, 60).'" is not an IP address. Use the old server\'s address, e.g. '.DomainSwitch::PREVIOUS_IP.'.';
        }

        if (! MediaSideloader::isPublicAddress($ip)) {
            return $ip.' is a private, local or reserved address. Only the old server\'s public address is allowed.';
        }

        if (in_array($ip, $this->serverIps(), true)) {
            return $ip.' is THIS server. The pictures have to come from the old one (Hostinger, '.DomainSwitch::PREVIOUS_IP.').';
        }

        return null;
    }

    /** @return list<string> this server's own public addresses, as the installer learned them (no network). */
    private function serverIps(): array
    {
        $out = [];

        try {
            $out = app(SwitchInstaller::class)->serverIps();
        } catch (\Throwable) {
            // The installer's table may not exist yet.
        }

        $local = $_SERVER['SERVER_ADDR'] ?? null;

        if (is_string($local) && $local !== '') {
            $out[] = $local;
        }

        return array_values(array_unique($out));
    }

    /** The address the screen and the command start from. */
    public function defaultIp(): string
    {
        $run = $this->run();

        return is_string($run['ip'] ?? null) && $run['ip'] !== '' ? $run['ip'] : DomainSwitch::PREVIOUS_IP;
    }

    /* ================================================================= scan */

    /**
     * CHECK: every referenced picture, whether it is on disk, and who names it.
     * Reads the database and the disk; makes no network call.
     *
     * @return array<string, mixed>
     */
    public function scan(): array
    {
        $found = [];      // relative path => [host, owners, refs]
        $foreign = [];    // host => count, references to other sites (not this job)

        $add = function (string $url, string $owner) use (&$found, &$foreign): void {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            if (! str_contains(strtolower($url), self::ROOT)) {
                return;
            }

            if ($host !== '' && ! $this->isShopHost($host)) {
                $foreign[$host] = ($foreign[$host] ?? 0) + 1;

                return;
            }

            $target = $this->guards->targetPath($url);
            $path = (string) ($target['path'] ?? '');

            // An unsafe name is refused below; one under the shop's own uploads/ is not this job.
            if ($target['refusal'] !== null) {
                $plain = ltrim(MediaUsage::normalise($url), '/');
                $at = strpos($plain, self::ROOT);
                $path = 'refused:'.($at === false ? $plain : substr($plain, $at));
            } elseif (! str_starts_with($path, self::ROOT)) {
                return;
            }

            $entry = $found[$path] ?? ['host' => $host === '' ? '(a path, no host)' : $host, 'owners' => [], 'refs' => 0, 'refusal' => $target['refusal']];
            $entry['refs']++;

            if (count($entry['owners']) < self::OWNERS_KEPT && ! in_array($owner, $entry['owners'], true)) {
                $entry['owners'][] = $owner;
            }

            $found[$path] = $entry;
        };

        foreach ($this->audit->audit() as $row) {
            $add($row['url'], $row['owner'].' · '.$row['field']);
        }

        $this->sweep($add);

        $present = 0;
        $rows = [];
        $now = now();
        $token = bin2hex(random_bytes(8));

        foreach ($found as $path => $entry) {
            // A name this will never WRITE (an .svg logo) can still be on disk already.
            $full = $this->guards->absolute(str_replace('refused:', '', $path));

            if ($full !== null && is_file($full)) {
                $present++;

                continue;
            }

            $rows[] = [
                'path_hash' => sha1($path),
                'path' => $path,
                'host' => mb_substr($entry['host'], 0, 255),
                'owners' => json_encode($entry['owners'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'refs' => $entry['refs'],
                'state' => $entry['refusal'] === null ? self::PENDING : self::REFUSED,
                'reason' => $entry['refusal'],
                'seen_scan' => $token,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            // A row already tried keeps its state and reason; only who names it is refreshed.
            DB::table(self::TABLE)->upsert($chunk, ['path_hash'], ['host', 'owners', 'refs', 'seen_scan', 'updated_at']);
        }

        // Rows this scan did not see -- no longer named, or on disk now (FTP) --
        // are not missing any more; fetched ones stay as the record of the run.
        DB::table(self::TABLE)->where('state', '!=', self::FETCHED)
            ->where(fn ($q) => $q->whereNull('seen_scan')->orWhere('seen_scan', '!=', $token))
            ->delete();

        $this->remember(['scanned_at' => $now->toIso8601String(), 'referenced' => count($found), 'present' => $present,
            'foreign' => array_sum($foreign)]);

        return $this->summary();
    }

    /**
     * Every other text column of every live table, for /wp-content/uploads/
     * pictures: banners, pages, menus, settings, page builder blocks.
     *
     * @param  \Closure(string, string): void  $add
     */
    private function sweep(\Closure $add): void
    {
        $skip = [...DomainReadiness::HISTORY, ...self::NOT_REFERENCES];

        foreach (Schema::getTables() as $table) {
            $name = (string) ($table['name'] ?? '');

            if ($name === '' || str_starts_with($name, 'sqlite_') || in_array($name, $skip, true)) {
                continue;
            }

            try {
                $columns = Schema::getColumns($name);
            } catch (\Throwable) {
                continue;
            }

            $text = [];
            $key = null;

            foreach ($columns as $column) {
                $col = (string) $column['name'];
                $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));

                if ($key === null && in_array($col, ['id', 'key'], true)) {
                    $key = $col;
                }

                if (preg_match('/char|text|json|clob|string/', $type) === 1 && ! in_array($col, self::AUDITED[$name] ?? [], true)) {
                    $text[] = $col;
                }
            }

            if ($text === []) {
                continue;
            }

            try {
                $query = DB::table($name)->select(array_values(array_unique(array_filter([$key, ...$text]))))
                    ->where(function ($q) use ($text): void {
                        foreach ($text as $col) {
                            $q->orWhere($col, 'like', '%wp-content%');
                        }
                    });

                foreach ($query->cursor() as $row) {
                    $row = (array) $row;
                    $id = $key === null ? '' : ' #'.$row[$key];

                    foreach ($text as $col) {
                        $value = $row[$col] ?? null;

                        if (! is_string($value) || ! str_contains($value, 'wp-content')) {
                            continue;
                        }

                        foreach (self::addressesIn($value) as $url) {
                            $add($url, self::label($name).$id.' · '.$col);
                        }
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }
    }

    /** @return list<string> every uploads picture address in a text value, JSON-escaped or not */
    public static function addressesIn(string $value): array
    {
        $value = str_replace('\/', '/', $value);

        preg_match_all(
            '~(?:https?:)?(?://[a-z0-9.-]+(?::\d+)?)?[^\s"\'<>()\\\\,;]*?wp-content/uploads/[^\s"\'<>()\\\\,;?#]+?\.(?:jpe?g|png|gif|webp|avif)(?=$|[\s"\'<>()\\\\,;?#&])~i',
            $value,
            $m,
        );

        return array_values(array_unique($m[0]));
    }

    private static function label(string $table): string
    {
        return [
            'banner_cards' => 'banner', 'banner_sets' => 'banner set', 'pages' => 'page', 'menu_items' => 'menu item',
            'settings' => 'setting', 'blocks' => 'block', 'grid_sections' => 'grid section', 'template_parts' => 'template part',
            'products' => 'product', 'categories' => 'category', 'brands' => 'brand', 'posts' => 'article',
        ][$table] ?? $table;
    }

    /* =============================================================== counts */

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $run = $this->run();
        $by = DB::table(self::TABLE)->selectRaw('state, COUNT(*) AS n')->groupBy('state')->pluck('n', 'state')->all();
        $n = fn (string $s): int => (int) ($by[$s] ?? 0);

        $hosts = [];

        foreach (DB::table(self::TABLE)->where('state', '!=', self::FETCHED)->selectRaw('host, COUNT(*) AS n')->groupBy('host')->get() as $row) {
            $hosts[(string) $row->host] = (int) $row->n;
        }

        arsort($hosts);
        $missing = $n(self::PENDING) + $n(self::FAILED) + $n(self::GONE) + $n(self::REFUSED);

        return [
            'ok' => true,
            'scanned_at' => $run['scanned_at'] ?? null,
            'referenced' => (int) ($run['referenced'] ?? 0),
            'present' => (int) ($run['present'] ?? 0),
            'missing' => $missing,
            'remaining' => $n(self::PENDING),
            'fetched' => $n(self::FETCHED),
            'failed' => $n(self::FAILED),
            'gone' => $n(self::GONE),
            'refused' => $n(self::REFUSED),
            'foreign' => (int) ($run['foreign'] ?? 0),
            'by_host' => $hosts,
            'from_ip' => $this->defaultIp(),
            'fetch_host' => $this->fetchHost(),
            'run' => $run,
            'warning' => 'Do not cancel Hostinger until this shows 0 missing.',
        ];
    }

    /**
     * Every picture still missing, with why and who names it.
     *
     * @return list<array{path: string, state: string, reason: string, status: int|null, owners: list<string>, host: string}>
     */
    public function missingList(int $limit = 500): array
    {
        $order = [self::GONE, self::FAILED, self::REFUSED, self::PENDING];
        $out = [];

        foreach (DB::table(self::TABLE)->where('state', '!=', self::FETCHED)->orderBy('id')->limit(max(1, $limit))->get() as $row) {
            $owners = json_decode((string) $row->owners, true);
            $out[] = [
                'path' => '/'.ltrim(str_replace('refused:', '', (string) $row->path), '/'),
                'state' => (string) $row->state,
                'reason' => (string) ($row->reason ?? ''),
                'status' => $row->status_code === null ? null : (int) $row->status_code,
                'owners' => is_array($owners) ? array_values(array_map('strval', $owners)) : [],
                'host' => (string) $row->host,
            ];
        }

        usort($out, fn ($a, $b) => array_search($a['state'], $order, true) <=> array_search($b['state'], $order, true));

        return $out;
    }

    /* ================================================================ batch */

    /**
     * One bounded step: a few files, a time budget, a pause between requests.
     *
     * @param  array{ip?: string, http?: bool, files?: int, seconds?: int, pause_ms?: int, continue?: bool}  $o
     * @return array<string, mixed>
     */
    public function batch(array $o = []): array
    {
        $ip = trim((string) ($o['ip'] ?? $this->defaultIp()), " \t[]");
        $refusal = $this->ipRefusal($ip);

        if ($refusal !== null) {
            return ['ok' => false, 'message' => $refusal, 'fetched' => 0, 'results' => []] + ['summary' => $this->summary()];
        }

        if (($o['continue'] ?? false) && ($this->run()['state'] ?? '') === 'stopped') {
            return ['ok' => true, 'stopped' => true, 'more' => false, 'fetched' => 0, 'results' => [],
                'message' => 'Stopped. Press Resume to carry on from where it stopped.', 'summary' => $this->summary()];
        }

        $http = (bool) ($o['http'] ?? false);
        $files = max(1, min(50, (int) ($o['files'] ?? self::DEFAULT_FILES)));
        $seconds = max(1, min(120, (int) ($o['seconds'] ?? self::DEFAULT_SECONDS)));
        $pause = max(0, min(5000, (int) ($o['pause_ms'] ?? self::DEFAULT_PAUSE_MS)));

        // A fresh press re-reads the catalogue (no network); a continuing step
        // works the list it already has.
        if (! ($o['continue'] ?? false) || ($this->run()['scanned_at'] ?? null) === null) {
            $this->scan();
        }

        $this->remember(['state' => 'running', 'ip' => $ip, 'http' => $http, 'heartbeat_at' => now()->toIso8601String(), 'stopped_reason' => null]);

        $started = microtime(true);
        $results = [];
        $fetched = 0;
        $tls = false;
        $doneIds = [];

        while (count($results) < $files && (microtime(true) - $started) < $seconds) {
            $row = DB::table(self::TABLE)->where('state', self::PENDING)
                ->when($doneIds !== [], fn ($q) => $q->whereNotIn('id', $doneIds))
                ->orderBy('id')->first();

            if ($row === null) {
                break;
            }

            $doneIds[] = (int) $row->id;

            if ($results !== [] && $pause > 0) {
                usleep($pause * 1000);
            }

            $outcome = $this->fetchOne((string) $row->path, $ip, $http);
            $results[] = ['path' => '/'.$row->path] + $outcome;

            if ($outcome['state'] === self::PRESENT) {
                DB::table(self::TABLE)->where('id', $row->id)->delete();

                continue;
            }

            DB::table(self::TABLE)->where('id', $row->id)->update([
                'state' => $outcome['state'],
                'reason' => $outcome['reason'],
                'status_code' => $outcome['status'],
                'bytes' => $outcome['bytes'],
                'attempts' => (int) $row->attempts + 1,
                'attempted_at' => now(),
                'updated_at' => now(),
            ]);

            if ($outcome['state'] === self::FETCHED) {
                $fetched++;
            }

            if ($outcome['tls'] ?? false) {
                $tls = true;

                break;
            }
        }

        $summary = $this->summary();
        $more = ! $tls && $summary['remaining'] > 0;
        $message = $tls
            ? 'Stopped: the old server\'s certificate for '.$this->fetchHost().' did not validate, so nothing more was asked. '
                .'Tick "Use plain HTTP" to fetch over HTTP from the same address ('.$ip.') instead — only the pictures are fetched '
                .'that way, and each one is still checked to be a real image.'
            : ($more ? 'Fetched '.$fetched.' this step; '.$summary['remaining'].' still to try.'
                : 'Finished. '.$summary['missing'].' picture(s) still missing.');

        $this->remember([
            'state' => $tls ? 'stopped' : ($more ? 'running' : 'finished'),
            'stopped_reason' => $tls ? 'tls' : null,
            'heartbeat_at' => now()->toIso8601String(),
        ]);

        return ['ok' => true, 'fetched' => $fetched, 'results' => $results, 'more' => $more, 'tls_failed' => $tls,
            'stopped' => false, 'message' => $message, 'summary' => $this->summary()];
    }

    /** Stop: the next continuing step (the screen's loop, or the command) does nothing. */
    public function stop(): array
    {
        $this->remember(['state' => 'stopped', 'stopped_reason' => 'stopped by you', 'heartbeat_at' => now()->toIso8601String()]);

        return $this->summary();
    }

    /** Put every failure (and, if asked, every 404) back in the queue. */
    public function retry(bool $gone = false): int
    {
        return DB::table(self::TABLE)->whereIn('state', $gone ? [self::FAILED, self::GONE] : [self::FAILED])
            ->update(['state' => self::PENDING, 'updated_at' => now()]);
    }

    /* ============================================================= one file */

    /**
     * @return array{state: string, reason: string, status: int|null, bytes: int, tls?: bool}
     */
    public function fetchOne(string $path, string $ip, bool $http = false): array
    {
        // The same guards the sideloader applies, re-asked at the moment of writing.
        $target = $this->guards->targetPath('/'.$path);

        if ($target['refusal'] !== null || $target['path'] !== $path || ! str_starts_with($path, self::ROOT)) {
            return $this->out(self::REFUSED, (string) ($target['refusal'] ?? 'only files under /wp-content/uploads/ are fetched'));
        }

        $full = $this->guards->absolute($path);

        if ($full === null) {
            return $this->out(self::REFUSED, 'this path does not resolve inside the web root; nothing is written for it');
        }

        if (file_exists($full)) {
            return $this->out(self::PRESENT, 'already on this server; left exactly as it was');
        }

        if ($this->ipRefusal($ip) !== null) {
            return $this->out(self::REFUSED, (string) $this->ipRefusal($ip));
        }

        $host = $this->fetchHost();
        $url = ($http ? 'http' : 'https').'://'.$host.'/'.implode('/', array_map('rawurlencode', explode('/', $path)));

        for ($hop = 0; ; $hop++) {
            $r = $this->request($url, $ip);

            if ($r['errno'] !== 0) {
                if (in_array($r['errno'], self::TLS_ERRORS, true)) {
                    return $this->out(self::FAILED, 'the old server\'s certificate for '.$host.' did not validate ('.self::short($r['error']).')', null, 0, true);
                }

                if ($r['errno'] === 23 || $r['errno'] === 63) {
                    return $this->out(self::REFUSED, 'larger than the '.$this->guards->bytes(MediaSideloader::MAX_FILE_BYTES).' limit for one picture');
                }

                return $this->out(self::FAILED, 'the old server ('.$ip.') did not answer: '.self::short($r['error']).'. Press Retry later.');
            }

            $status = $r['status'];

            if ($status >= 300 && $status < 400) {
                $next = $this->redirectTarget($url, (string) ($r['headers']['location'] ?? ''));

                if ($next === null) {
                    return $this->out(self::REFUSED, 'the old server redirected somewhere this will not follow', $status);
                }

                if ($hop >= self::MAX_REDIRECTS) {
                    return $this->out(self::FAILED, 'more than '.self::MAX_REDIRECTS.' redirects', $status);
                }

                $url = $next;

                continue;
            }

            if ($status === 404 || $status === 410) {
                return $this->out(self::GONE, 'not on the old server either ('.$status.'). This picture is lost: give the product a new one.', $status);
            }

            if ($status < 200 || $status >= 300) {
                return $this->out(self::FAILED, 'the old server answered '.$status.'. Press Retry later.', $status);
            }

            return $this->land($path, $full, $r, $status);
        }
    }

    /**
     * @param  array{status: int, headers: array<string, string>, body: string, errno: int, error: string}  $r
     * @return array{state: string, reason: string, status: int|null, bytes: int}
     */
    private function land(string $path, string $full, array $r, int $status): array
    {
        $declared = strtolower(trim(explode(';', (string) ($r['headers']['content-type'] ?? ''))[0]));
        $body = $r['body'];

        if (! array_key_exists($declared, MediaSideloader::SAFE_TYPES)) {
            return $this->out(self::REFUSED, 'the old server called this "'.($declared ?: 'nothing').'", which is not a picture type', $status);
        }

        if (strlen($body) > MediaSideloader::MAX_FILE_BYTES) {
            return $this->out(self::REFUSED, 'larger than the '.$this->guards->bytes(MediaSideloader::MAX_FILE_BYTES).' limit for one picture', $status);
        }

        $sniffed = MediaSideloader::sniff(substr($body, 0, 4096));

        if ($sniffed === null || $sniffed !== $declared) {
            return $this->out(self::REFUSED, 'the bytes are not the '.$declared.' picture the old server said they were; nothing was written', $status);
        }

        if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), MediaSideloader::SAFE_TYPES[$sniffed], true)) {
            return $this->out(self::REFUSED, 'the file name\'s extension does not match its '.$sniffed.' bytes', $status);
        }

        $dir = dirname($full);

        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return $this->out(self::FAILED, 'could not create the folder under the web root', $status);
        }

        $temp = $dir.'/.kbb-oldpic-'.bin2hex(random_bytes(8)).'.part';

        try {
            if (@file_put_contents($temp, $body) !== strlen($body)) {
                return $this->out(self::FAILED, 'could not write the file (is the disk full?)', $status);
            }

            // NEVER OVERWRITE: link() refuses an existing name atomically.
            $placed = @link($temp, $full);

            if (! $placed) {
                if (file_exists($full)) {
                    return $this->out(self::PRESENT, 'already on this server; left exactly as it was', $status);
                }

                $placed = @rename($temp, $full);
            }

            if (! $placed) {
                return $this->out(self::FAILED, 'could not move the file into place', $status);
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }

        @chmod($full, 0o644);

        try {
            MediaRegistrar::record($path, basename($path), $sniffed);
            ImageVariants::generate('/'.$path);
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->out(self::FETCHED, 'fetched '.$this->guards->bytes(strlen($body)).' from the old server', $status, strlen($body));
    }

    /** A redirect only to the shop's own name, http/https, still under uploads. */
    private function redirectTarget(string $from, string $location): ?string
    {
        $next = $this->guards->resolveRedirect($from, trim($location));

        if ($next === null) {
            return null;
        }

        $host = strtolower((string) parse_url($next, PHP_URL_HOST));
        $bare = $this->fetchHost();
        $port = parse_url($next, PHP_URL_PORT);

        if (! in_array($host, [$bare, 'www.'.$bare], true) || $port !== null) {
            return null;
        }

        $target = $this->guards->targetPath($next);

        return $target['refusal'] === null && str_starts_with((string) $target['path'], self::ROOT) ? $next : null;
    }

    /**
     * One request, its name pinned to the old address. Verification is never
     * switched off; plain HTTP is a URL the owner chose, not a weaker TLS.
     *
     * @return array{status: int, headers: array<string, string>, body: string, errno: int, error: string}
     */
    private function request(string $url, string $ip): array
    {
        $bare = $this->fetchHost();
        $pin = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $resolve = [];

        foreach ([$bare, 'www.'.$bare] as $name) {
            $resolve[] = $name.':443:'.$pin;
            $resolve[] = $name.':80:'.$pin;
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => $resolve,
            CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => ['Accept: image/*'],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; KBB-OldServerPictures/1.0; +migration)',
            CURLOPT_HTTPGET => true,
        ];

        return ($this->transport ?? self::curl(...))($options);
    }

    /**
     * @param  array<int, mixed>  $options
     * @return array{status: int, headers: array<string, string>, body: string, errno: int, error: string}
     */
    private static function curl(array $options): array
    {
        $headers = [];
        $body = '';
        $ch = curl_init();
        curl_setopt_array($ch, $options + [
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $headers = [];   // a new response (100 Continue) starts over
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body): int {
                $body .= $chunk;

                // Over the cap: returning a short count aborts the transfer (errno 23).
                return strlen($body) > MediaSideloader::MAX_FILE_BYTES ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $out = ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body,
            'errno' => curl_errno($ch), 'error' => curl_error($ch)];
        curl_close($ch);

        return $out;
    }

    /** @return array{state: string, reason: string, status: int|null, bytes: int, tls?: bool} */
    private function out(string $state, string $reason, ?int $status = null, int $bytes = 0, bool $tls = false): array
    {
        return ['state' => $state, 'reason' => $reason, 'status' => $status, 'bytes' => $bytes] + ($tls ? ['tls' => true] : []);
    }

    private static function short(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);

        return strlen($s) > 200 ? substr($s, 0, 197).'...' : $s;
    }

    /* ============================================================ run state */

    /** @return array<string, mixed> */
    public function run(): array
    {
        try {
            $run = Cache::get(self::RUN_KEY);
        } catch (\Throwable) {
            $run = null;
        }

        return is_array($run) ? $run : ['state' => 'never', 'scanned_at' => null];
    }

    /** @param  array<string, mixed>  $patch */
    private function remember(array $patch): void
    {
        try {
            Cache::forever(self::RUN_KEY, array_merge($this->run(), $patch));
        } catch (\Throwable) {
            // The table is the record; this is only the button's state.
        }
    }
}
