<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Support\DescriptionVideos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * The video files product copy names, brought off the old WordPress into this
 * shop's web root. (Lane PD)
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * The two Collagen Booster clips live at
 * kbeautybliss.com/wp-content/uploads/2024/11/…webm, and kbeautybliss.com is
 * about to be pointed at this application (docs/CUTOVER-EXTRABEAUTY.md §4.1).
 * The picture pass (kbb:import-media-fetch) brings photographs across and is
 * deliberately images-only -- its SAFE_TYPES refuse anything else -- so a
 * video would 404 the day the old site went dark. This brings each one to the
 * SAME PATH under this web root (`wp-content/uploads/2024/11/…webm`), and
 * DescriptionVideos::local() plays that copy from then on. No row is
 * rewritten: the copy keeps the address it was written with, and after the
 * cutover that address resolves here anyway.
 *
 * ── THE GUARDS, THE SAME ONES THE PICTURE PASS HOLDS ────────────────────────
 *
 *  1. ONLY ADDRESSES THE PAGE WOULD PLAY. DescriptionVideos::addresses() is
 *     the storefront's own parser and allowlist, so nothing is fetched that the
 *     shop would not draw.
 *  2. NOT FROM THIS SHOP. A host this application answers is skipped -- there
 *     is nothing to bring across from yourself.
 *  3. NEVER INTO THE PRIVATE NETWORK. MediaSideloader::hostRefusal() on the
 *     address and on every redirect hop; a redirect to another host is
 *     refused; three hops at most.
 *  4. A VIDEO OR NOTHING. The declared type must be a video type for the
 *     extension, and the first bytes must be a WebM (EBML) or an MP4/QuickTime
 *     (`ftyp`, `moov`, `mdat`, `wide`, `free`) header. An HTML error page
 *     called video/webm fails the sniff.
 *  5. A NAME THAT CANNOT EXECUTE. The path is cut at the uploads root, every
 *     segment is [A-Za-z0-9._-], `..` is refused, and any dot-separated part
 *     on MediaSideloader::DANGEROUS_PARTS refuses the file.
 *  6. CAPPED. MAX_BYTES per file, checked against Content-Length first and
 *     against every chunk read; free space for the file plus a reserve
 *     before the first byte; a wall clock per file.
 *  7. WHOLE OR NOT AT ALL. Written to a `.part` beside the target and renamed
 *     into place; unlinked on every way out.
 *  8. RE-RUNNABLE. A file already on disk is not fetched again.
 */
final class DescriptionVideoFetcher
{
    /** One clip, at most. Product clips are a few MB; this is a guard, not a budget. */
    public const MAX_BYTES = 200 * 1024 * 1024;

    /** Kept free on the volume after the file is written. */
    public const RESERVE = 64 * 1024 * 1024;

    public const MAX_REDIRECTS = 3;

    public const MAX_SECONDS = 300;

    /** Extension => the content types the old host may call it. */
    public const TYPES = [
        'mp4' => ['video/mp4'],
        'm4v' => ['video/x-m4v', 'video/mp4'],
        'mov' => ['video/quicktime', 'video/mp4'],
        'webm' => ['video/webm'],
    ];

    /** Where copy that can name a video lives: table => columns. */
    private const DOCUMENTS = [
        'products' => ['description', 'short_description'],
        'blocks' => ['content'],
        'product_tabs' => ['body'],
    ];

    public function __construct(
        private readonly MediaSideloader $guard = new MediaSideloader,
        private readonly ?\Closure $freeSpace = null,
    ) {}

    /**
     * Every video the copy names, with where it would land and whether it is
     * there already. One pass over the rows that mention a video extension.
     *
     * @return list<array{url: string, path: ?string, state: string, reason: string}>
     */
    public function plan(): array
    {
        $own = $this->ownHosts();
        $seen = [];

        foreach (self::DOCUMENTS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                $query = DB::table($table)->select(['id', $column])->where(function ($q) use ($column): void {
                    foreach ([...array_map(static fn (string $e): string => '%.' . $e . '%', DescriptionVideos::EXTENSIONS), '%[video%'] as $like) {
                        $q->orWhere($column, 'like', $like);
                    }
                });

                foreach ($query->orderBy('id')->cursor() as $row) {
                    foreach (DescriptionVideos::addresses((string) $row->{$column}) as $url) {
                        $seen[$url] ??= $url;
                    }
                }
            }
        }

        $out = [];

        foreach ($seen as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $path = DescriptionVideos::relative($url);

            if (in_array($host, $own, true)) {
                $out[] = ['url' => $url, 'path' => $path, 'state' => 'own', 'reason' => 'already this shop\'s address'];
            } elseif ($path === null || ($refusal = $this->nameRefusal($path)) !== null) {
                $out[] = ['url' => $url, 'path' => $path, 'state' => 'refused', 'reason' => $refusal ?? 'not under wp-content/uploads or uploads, so it has no place in this web root'];
            } elseif (is_file(public_path($path))) {
                $out[] = ['url' => $url, 'path' => $path, 'state' => 'here', 'reason' => 'already in this shop\'s web root'];
            } else {
                $out[] = ['url' => $url, 'path' => $path, 'state' => 'todo', 'reason' => 'on the old site only'];
            }
        }

        return $out;
    }

    /**
     * Fetch one file into place.
     *
     * @return array{ok: bool, reason: string, bytes: int}
     */
    public function fetch(string $url, string $path): array
    {
        $full = public_path($path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $temp = null;
        $began = microtime(true);

        try {
            if (($refusal = $this->guard->hostRefusal($url)) !== null) {
                return self::no($refusal);
            }

            $hop = $url;
            $response = null;

            for ($n = 0; $n <= self::MAX_REDIRECTS; $n++) {
                $response = Http::withOptions(['stream' => true, 'allow_redirects' => false])
                    ->connectTimeout(10)
                    ->timeout(60)
                    ->withHeaders(['Accept' => 'video/*', 'User-Agent' => 'KBB-Sideloader/1.0 (+migration)'])
                    ->get($hop);

                $status = $response->status();

                if ($status < 300 || $status >= 400) {
                    break;
                }

                $next = trim((string) $response->header('Location'));
                $next = $next !== '' && ! preg_match('#^https?://#i', $next)
                    ? preg_replace('#^(https?://[^/]+).*$#i', '$1', $hop) . '/' . ltrim($next, '/')
                    : $next;

                if ($next === '' || strtolower((string) parse_url($next, PHP_URL_HOST)) !== strtolower((string) parse_url($hop, PHP_URL_HOST))) {
                    return self::no('refused to follow a redirect off ' . parse_url($hop, PHP_URL_HOST) . ' (to "' . $next . '")');
                }

                if (($refusal = $this->guard->hostRefusal($next)) !== null) {
                    return self::no('refused to follow a redirect to ' . $next . ': ' . $refusal);
                }

                if ($n === self::MAX_REDIRECTS) {
                    return self::no('more than ' . self::MAX_REDIRECTS . ' redirects; gave up');
                }

                $hop = $next;
            }

            if ($response === null || $response->status() < 200 || $response->status() >= 300) {
                return self::no('the old host answered ' . ($response?->status() ?? 'nothing'));
            }

            $declared = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

            if (! in_array($declared, self::TYPES[$extension] ?? [], true)) {
                return self::no('the old host called this "' . ($declared === '' ? '(nothing)' : $declared) . '", which is not a video type for a .' . $extension);
            }

            $length = $response->header('Content-Length');

            if (is_numeric($length) && (int) $length > self::MAX_BYTES) {
                return self::no('the old host says this is ' . (int) $length . ' bytes, over the ' . self::MAX_BYTES . '-byte limit for one clip');
            }

            $directory = dirname($full);

            if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
                return self::no('could not create ' . $directory);
            }

            $free = $this->freeSpace !== null ? ($this->freeSpace)() : @disk_free_space($directory);

            if (is_numeric($free) && (int) $free < (is_numeric($length) ? (int) $length : self::MAX_BYTES) + self::RESERVE) {
                return self::no('not enough free space on this server for the clip and a ' . (self::RESERVE >> 20) . ' MB reserve');
            }

            $temp = $directory . '/.kbb-video-' . bin2hex(random_bytes(8)) . '.part';
            $handle = @fopen($temp, 'wb');

            if ($handle === false) {
                return self::no('could not open a temporary file in ' . $directory);
            }

            $body = $response->toPsrResponse()->getBody();
            $written = 0;
            $head = '';

            while (! $body->eof()) {
                if (microtime(true) - $began > self::MAX_SECONDS) {
                    fclose($handle);

                    return self::no('took longer than ' . self::MAX_SECONDS . ' seconds; gave up');
                }

                $chunk = $body->read(65536);
                $written += strlen($chunk);

                if ($written > self::MAX_BYTES) {
                    fclose($handle);

                    return self::no('over the ' . self::MAX_BYTES . '-byte limit for one clip');
                }

                if (strlen($head) < 16) {
                    $head .= substr($chunk, 0, 16 - strlen($head));
                }

                fwrite($handle, $chunk);
            }

            fclose($handle);

            if (! self::sniff($head, $extension)) {
                return self::no('the bytes are not a ' . $extension . ' video (the old host may have answered with a page)');
            }

            if (! @rename($temp, $full)) {
                return self::no('could not move the file into ' . $full);
            }

            $temp = null;

            return ['ok' => true, 'reason' => 'fetched', 'bytes' => $written];
        } catch (\Throwable $e) {
            return self::no('the request failed: ' . $e->getMessage());
        } finally {
            if ($temp !== null && is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /** Do the first bytes say what the extension says? */
    public static function sniff(string $head, string $extension): bool
    {
        if ($extension === 'webm') {
            return str_starts_with($head, "\x1A\x45\xDF\xA3");
        }

        return in_array(substr($head, 4, 4), ['ftyp', 'moov', 'mdat', 'wide', 'free'], true);
    }

    /** Why this relative path may not be written, or null. */
    public function nameRefusal(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! array_key_exists($extension, self::TYPES)) {
            return 'the name ends in .' . $extension . ', which is not a video this writes';
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $segment) !== 1) {
                return 'the path has a part ("' . $segment . '") this will not write into the web root';
            }
        }

        foreach (array_slice(explode('.', strtolower(basename($path))), 1) as $part) {
            if (in_array($part, MediaSideloader::DANGEROUS_PARTS, true)) {
                return 'the name carries ".' . $part . '", which a web server could hand to an interpreter';
            }
        }

        return null;
    }

    /** Hosts this application answers, which are never fetched from. @return list<string> */
    private function ownHosts(): array
    {
        $out = [];

        foreach ([(string) (\App\Models\Setting::map()['site_url'] ?? ''), (string) config('app.url')] as $url) {
            $host = parse_url($url, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $out[] = strtolower($host);
            }
        }

        $canonical = \App\Support\SiteHost::canonical();

        if ($canonical !== '') {
            $out[] = $canonical;
        }

        return array_values(array_unique($out));
    }

    /** @return array{ok: false, reason: string, bytes: 0} */
    private static function no(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'bytes' => 0];
    }
}
