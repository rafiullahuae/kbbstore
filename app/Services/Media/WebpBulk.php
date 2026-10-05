<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Support\ImageVariants;
use App\Support\MediaRegistrar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Turn the shop's JPEG and PNG files into WebP and re-point every use of them.
 * (Lane WP)
 *
 * The owner: "also need function to look for jpg and [png] images and convert
 * auto in bulk, and also replace them where the images are actually used in
 * the website."
 *
 * FOUR STEPS, EACH A BOUNDED BATCH. The admin screen calls one batch per
 * request and the artisan command calls them in a loop; neither can time out,
 * because no batch does more than MAX_FILES files or MAX_SECONDS of work.
 *
 *   plan()            the dry run: what would convert, the bytes saved, the
 *                     references that would change. Writes nothing.
 *   run()             convert, then re-point. Resumable and idempotent: every
 *                     file it has looked at has a row in webp_conversions and
 *                     is never looked at again, and a batch that died between
 *                     converting and re-pointing is finished by the next one.
 *   restore()         the undo: references back to the original, WebP deleted.
 *   removeOriginals() the one destructive step, and the owner's to confirm.
 *
 * ORIGINALS ARE KEPT UNTIL removeOriginals(). Until then the old .jpg URL keeps
 * answering, so a cached page, an email already sent, a Google Images result
 * or a link somebody shared still shows the picture. Even then an original is
 * deleted only if no allowlisted column AND no read-only table (reviews, UGC,
 * Instagram, sent campaigns) still mentions it.
 *
 * WHAT IT WALKS. public/uploads and public/wp-content/uploads — the two roots
 * MediaRegistrar::ROOTS names — minus the directories that belong to customers
 * or to other modules' own bookkeeping (EXCLUDED). Never img-cache, which is a
 * function of the originals and is regenerated for each new WebP.
 */
final class WebpBulk
{
    public const ROOTS = MediaRegistrar::ROOTS;

    /**
     * Customer uploads (reviews), the shoppable-video and Instagram modules'
     * own files, and the demo shots: each has its own writer and its own
     * ledger keyed by path, and none of them is the owner's picture to convert.
     */
    public const EXCLUDED = ['uploads/reviews/', 'uploads/ugc/', 'uploads/instagram/', 'uploads/demo-shots/'];

    public const MAX_FILES = 25;

    public const MAX_SECONDS = 8.0;

    /** The walk stops here rather than eating a worker on a runaway tree. */
    private const MAX_WALK = 50000;

    private const LOCK = 'kbb.webp.batch';

    private const SKIP_REASONS = ['webp_not_smaller', 'not_jpeg_or_png', 'too_many_pixels', 'not_enough_memory', 'unreadable'];

    /* ------------------------------------------------------------ status */

    /**
     * @return array{available: bool, reason: ?string, settings: array, remaining: int, truncated: bool, counts: array<string, int>, bytes_saved: int, originals_bytes: int, recent: list<array>}
     */
    public static function status(): array
    {
        $counts = ['pending' => 0, 'converted' => 0, 'removed' => 0, 'skipped' => 0, 'failed' => 0];
        $saved = $originals = 0;
        $recent = [];

        if (self::ready()) {
            foreach (DB::table('webp_conversions')->selectRaw('status, count(*) as n, sum(bytes_before) as b, sum(bytes_after) as a')->groupBy('status')->get() as $row) {
                $counts[(string) $row->status] = (int) $row->n;

                if (in_array($row->status, ['converted', 'removed'], true)) {
                    $saved += (int) $row->b - (int) $row->a;
                }

                if ($row->status === 'converted') {
                    $originals += (int) $row->b;
                }
            }

            $recent = DB::table('webp_conversions')->orderByDesc('id')->limit(15)
                ->get(['from_path', 'to_path', 'origin', 'status', 'reason', 'bytes_before', 'bytes_after', 'refs', 'updated_at'])
                ->map(fn ($r) => (array) $r)->all();
        }

        $walk = self::candidates('', PHP_INT_MAX);

        return [
            'available' => WebpConverter::available(),
            'reason' => WebpConverter::unavailableReason(),
            'settings' => WebpSettings::all(),
            'remaining' => count($walk['files']),
            'truncated' => $walk['truncated'],
            'counts' => $counts,
            'bytes_saved' => $saved,
            'originals_bytes' => $originals,
            'recent' => $recent,
        ];
    }

    /* ----------------------------------------------------------- the walk */

    /**
     * JPEG/PNG files under the upload roots that have no row yet, sorted,
     * strictly after $after.
     *
     * @return array{files: list<string>, truncated: bool}
     */
    public static function candidates(string $after = '', int $limit = self::MAX_FILES): array
    {
        // Every row, pending included: a pending row is a conversion in flight
        // (an upload, or another batch) and must not be started twice.
        $known = self::ready() ? array_flip(DB::table('webp_conversions')->pluck('from_path')->all()) : [];
        $files = [];
        $truncated = false;
        $walked = 0;
        $root = rtrim(str_replace('\\', '/', (string) realpath(public_path())), '/');

        foreach (self::ROOTS as $prefix) {
            $dir = public_path(rtrim($prefix, '/'));

            if ($root === '' || ! is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            foreach ($iterator as $entry) {
                if (++$walked > self::MAX_WALK) {
                    $truncated = true;
                    break 2;
                }

                if (! $entry->isFile() || $entry->isLink()) {
                    continue;
                }

                $ext = strtolower($entry->getExtension());

                if ($ext !== 'jpg' && $ext !== 'jpeg' && $ext !== 'png') {
                    continue;
                }

                $relative = self::relative($entry->getPathname(), $root);

                if ($relative === null || isset($known[$relative]) || self::excluded($relative)) {
                    continue;
                }

                if ($after !== '' && strcmp($relative, $after) <= 0) {
                    continue;
                }

                $files[] = $relative;
            }
        }

        sort($files, SORT_STRING);

        return ['files' => array_slice($files, 0, max(0, $limit)), 'truncated' => $truncated];
    }

    /* ---------------------------------------------------------- dry run */

    /**
     * Measure the next files after $after. Nothing on disk or in the database
     * changes.
     */
    public static function plan(string $after = '', int $maxFiles = self::MAX_FILES, float $maxSeconds = self::MAX_SECONDS): array
    {
        $started = microtime(true);
        $settings = WebpSettings::all();
        $batch = self::candidates($after, $maxFiles + 1);
        $files = array_slice($batch['files'], 0, $maxFiles);

        $out = ['files' => [], 'convert' => 0, 'skip' => 0, 'bytes_before' => 0, 'bytes_after' => 0,
            'references' => null, 'cursor' => $after, 'done' => true];
        $map = [];

        foreach ($files as $i => $relative) {
            $result = WebpConverter::convert(public_path($relative), null, $settings['quality'], $settings['max_width']);
            $out['cursor'] = $relative;

            if ($result['ok']) {
                $out['convert']++;
                $out['bytes_before'] += $result['bytes_before'];
                $out['bytes_after'] += $result['bytes_after'];
                $map[$relative] = self::webpName($relative);
            } else {
                $out['skip']++;
            }

            $out['files'][] = ['path' => $relative, 'ok' => $result['ok'], 'reason' => $result['reason'],
                'bytes_before' => $result['bytes_before'], 'bytes_after' => $result['bytes_after']];

            if ((microtime(true) - $started) >= $maxSeconds && $i < count($files) - 1) {
                break;
            }
        }

        $out['references'] = WebpReferences::apply($map, false);

        foreach ($out['files'] as &$file) {
            $file['refs'] = $out['references']['paths'][$file['path']] ?? 0;
        }
        unset($file);

        $out['done'] = count($out['files']) === count($batch['files']);

        return $out;
    }

    /* ------------------------------------------------------------- run */

    /**
     * Convert the next files and re-point their references.
     */
    public static function run(int $maxFiles = self::MAX_FILES, float $maxSeconds = self::MAX_SECONDS): array
    {
        return self::locked(function () use ($maxFiles, $maxSeconds): array {
            $started = microtime(true);
            $settings = WebpSettings::all();
            $out = ['converted' => 0, 'skipped' => 0, 'failed' => 0, 'bytes_before' => 0, 'bytes_after' => 0,
                'references' => null, 'files' => [], 'done' => false];

            if (! WebpConverter::available()) {
                return ['error' => WebpConverter::unavailableReason()] + $out;
            }

            // A batch that died mid-conversion left a pending row: start it
            // again. Only an old one — a fresh one is an upload in progress.
            foreach (DB::table('webp_conversions')->where('status', 'pending')->where('updated_at', '<', now()->subMinutes(5))->get() as $stale) {
                self::discardReservation((string) $stale->to_path);
                DB::table('webp_conversions')->where('id', $stale->id)->delete();
            }

            $files = self::candidates('', $maxFiles)['files'];

            foreach ($files as $relative) {
                $result = self::convertOne($relative, 'bulk', $settings);
                $out['files'][] = ['path' => $relative] + $result;
                $out[$result['status'] === 'converted' ? 'converted' : ($result['status'] === 'skipped' ? 'skipped' : 'failed')]++;

                if ($result['status'] === 'converted') {
                    $out['bytes_before'] += $result['bytes_before'];
                    $out['bytes_after'] += $result['bytes_after'];
                }

                if ((microtime(true) - $started) >= $maxSeconds) {
                    break;
                }
            }

            $out['references'] = self::repoint();
            $out['done'] = self::candidates('', 1)['files'] === []
                && ! DB::table('webp_conversions')->where('status', 'converted')->where('refs_done', false)->exists();

            Log::info('kbb:webp run', [
                'converted' => $out['converted'], 'skipped' => $out['skipped'], 'failed' => $out['failed'],
                'bytes_saved' => $out['bytes_before'] - $out['bytes_after'],
                'references' => $out['references']['columns'] ?? [],
            ]);

            return $out;
        });
    }

    /**
     * Re-point every converted file whose references have not been moved yet,
     * update its library row and make its phone-sized copies.
     */
    private static function repoint(): array
    {
        $rows = DB::table('webp_conversions')->where('status', 'converted')->where('refs_done', false)->get();

        if ($rows->isEmpty()) {
            return WebpReferences::apply([], false);
        }

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row->from_path] = (string) $row->to_path;
        }

        $result = DB::transaction(function () use ($map, $rows): array {
            $result = WebpReferences::apply($map, true);

            foreach ($rows as $row) {
                self::moveLibraryRow((string) $row->from_path, (string) $row->to_path);
                DB::table('webp_conversions')->where('id', $row->id)->update([
                    'refs_done' => true,
                    'refs' => (int) ($result['paths'][$row->from_path] ?? 0),
                    'updated_at' => now(),
                ]);
            }

            return $result;
        });

        foreach ($map as $to) {
            try {
                ImageVariants::generate('/'.$to);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($result['rows'] > 0) {
            self::flushCaches();
        }

        return $result;
    }

    /* --------------------------------------------------------- one file */

    /**
     * Convert one file in place: `<stem>.webp` beside it, a row recording it.
     *
     * @return array{status: string, reason: ?string, to: ?string, bytes_before: int, bytes_after: int, width: int, height: int}
     */
    private static function convertOne(string $relative, string $origin, array $settings, bool $always = false): array
    {
        $absolute = self::absolute($relative);
        $now = now();

        if ($absolute === null) {
            return ['status' => 'failed', 'reason' => 'outside_uploads', 'to' => null, 'bytes_before' => 0, 'bytes_after' => 0, 'width' => 0, 'height' => 0];
        }

        $reserved = WebpConverter::reserveName($absolute);
        $to = $reserved === null ? null : self::relative($reserved, rtrim(str_replace('\\', '/', (string) realpath(public_path())), '/'));

        $id = DB::table('webp_conversions')->insertGetId([
            'from_path' => $relative, 'to_path' => $to, 'origin' => $origin, 'status' => 'pending',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($reserved === null || $to === null) {
            DB::table('webp_conversions')->where('id', $id)->update(['status' => 'failed', 'reason' => 'no_free_name']);

            return ['status' => 'failed', 'reason' => 'no_free_name', 'to' => null, 'bytes_before' => 0, 'bytes_after' => 0, 'width' => 0, 'height' => 0];
        }

        $result = WebpConverter::convert($absolute, $reserved, $settings['quality'], $settings['max_width'], $always);

        $status = $result['ok'] ? 'converted' : (in_array($result['reason'], self::SKIP_REASONS, true) ? 'skipped' : 'failed');

        if (! $result['ok']) {
            self::discardReservation($to);
        }

        DB::table('webp_conversions')->where('id', $id)->update([
            'status' => $status,
            'reason' => $result['ok'] ? null : $result['reason'],
            'to_path' => $result['ok'] ? $to : null,
            'bytes_before' => $result['bytes_before'],
            'bytes_after' => $result['ok'] ? $result['bytes_after'] : null,
            'width' => $result['ok'] ? $result['width'] : null,
            'height' => $result['ok'] ? $result['height'] : null,
            'updated_at' => now(),
        ]);

        return ['status' => $status, 'reason' => $result['ok'] ? null : $result['reason'], 'to' => $result['ok'] ? $to : null,
            'bytes_before' => $result['bytes_before'], 'bytes_after' => $result['bytes_after'],
            'width' => $result['width'], 'height' => $result['height']];
    }

    /* ---------------------------------------------------------- upload */

    /**
     * Called by the one upload endpoint after the file is in place. Null when
     * nothing applies (switched off, no WebP on this server, not JPEG/PNG),
     * and then the upload goes on exactly as it always has.
     *
     * @return array{converted: bool, path: string, reason: ?string, bytes_before: int, bytes_after: int}|null
     */
    public static function onUpload(string $relative): ?array
    {
        $settings = WebpSettings::all();

        if (! $settings['enabled'] || ! WebpConverter::available() || ! self::ready()) {
            return null;
        }

        $absolute = self::absolute($relative);

        if ($absolute === null || ! in_array(WebpConverter::sniff($absolute), [WebpConverter::TYPE_JPEG, WebpConverter::TYPE_PNG], true)) {
            return null;
        }

        try {
            // Uploads always end as WebP (2.60.388, the owner: "must converted").
            $result = self::convertOne($relative, 'upload', $settings, true);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if ($result['status'] !== 'converted') {
            return ['converted' => false, 'path' => $relative, 'reason' => $result['reason'],
                'bytes_before' => $result['bytes_before'], 'bytes_after' => $result['bytes_after']];
        }

        // Nothing has ever been given the original's address, so it can go.
        if (! $settings['keep_original'] && @unlink($absolute)) {
            DB::table('webp_conversions')->where('from_path', $relative)->update(['status' => 'removed']);
        }

        DB::table('webp_conversions')->where('from_path', $relative)->update(['refs_done' => true]);

        return ['converted' => true, 'path' => (string) $result['to'], 'reason' => null,
            'bytes_before' => $result['bytes_before'], 'bytes_after' => $result['bytes_after']];
    }

    /**
     * Why an upload of a JPEG or PNG did NOT become WebP, in the owner's words,
     * or null when it would have. (2.60.388) The upload answered nothing at all
     * when it skipped, so a switch left off and a server without WebP looked
     * the same as a conversion that never ran.
     */
    public static function whyNot(): ?string
    {
        if (! WebpSettings::all()['enabled']) {
            return 'WebP conversion is switched off (Content → Media Library → WebP images).';
        }

        if (($reason = WebpConverter::unavailableReason()) !== null) {
            return $reason;
        }

        if (! self::ready()) {
            return 'The WebP update has not finished installing: run php artisan migrate --force.';
        }

        return null;
    }

    /* --------------------------------------------------------- restore */

    /**
     * Undo: references back to the original, the WebP deleted, the row gone
     * so the file is a candidate again. Only where the original still exists.
     */
    public static function restore(int $maxFiles = self::MAX_FILES): array
    {
        return self::locked(function () use ($maxFiles): array {
            $rows = DB::table('webp_conversions')->where('status', 'converted')
                ->orderBy('id')->limit($maxFiles)->get();
            $map = [];
            $restored = 0;

            foreach ($rows as $row) {
                if (self::absolute((string) $row->from_path) !== null) {
                    $map[(string) $row->to_path] = (string) $row->from_path;
                }
            }

            $result = DB::transaction(function () use ($map, $rows, &$restored): array {
                $result = WebpReferences::apply($map, true);

                foreach ($rows as $row) {
                    if (! isset($map[(string) $row->to_path])) {
                        // The original is gone: nothing to go back to.
                        DB::table('webp_conversions')->where('id', $row->id)->update(['status' => 'removed', 'updated_at' => now()]);

                        continue;
                    }

                    self::moveLibraryRow((string) $row->to_path, (string) $row->from_path);
                    DB::table('webp_conversions')->where('id', $row->id)->delete();
                    $restored++;
                }

                return $result;
            });

            foreach (array_keys($map) as $webp) {
                ImageVariants::forget('/'.$webp);
                self::discardReservation($webp);
            }

            if ($result['rows'] > 0) {
                self::flushCaches();
            }

            $left = DB::table('webp_conversions')->where('status', 'converted')->exists();

            return ['restored' => $restored, 'references' => $result, 'done' => ! $left];
        });
    }

    /* -------------------------------------------------- remove originals */

    /**
     * Delete the originals of converted files — the owner's step, confirmed by
     * him. An original anything still mentions is kept.
     */
    public static function removeOriginals(int $afterId = 0, int $maxFiles = 200): array
    {
        return self::locked(function () use ($afterId, $maxFiles): array {
            $rows = DB::table('webp_conversions')->where('status', 'converted')->where('refs_done', true)
                ->where('id', '>', $afterId)->orderBy('id')->limit($maxFiles)->get();
            $removed = $kept = $bytes = 0;
            $cursor = $afterId;

            foreach ($rows as $row) {
                $cursor = (int) $row->id;
                $from = (string) $row->from_path;
                $absolute = self::absolute($from);

                if ($absolute !== null && WebpReferences::referenced($from)) {
                    $kept++;
                    DB::table('webp_conversions')->where('id', $row->id)->update(['reason' => 'still_referenced', 'updated_at' => now()]);

                    continue;
                }

                if ($absolute !== null) {
                    $size = (int) @filesize($absolute);

                    if (! @unlink($absolute)) {
                        $kept++;

                        continue;
                    }

                    $bytes += $size;
                }

                ImageVariants::forget('/'.$from);
                DB::table('webp_conversions')->where('id', $row->id)->update(['status' => 'removed', 'reason' => null, 'updated_at' => now()]);
                $removed++;
            }

            Log::info('kbb:webp remove-originals', ['removed' => $removed, 'kept' => $kept, 'bytes' => $bytes]);

            return ['removed' => $removed, 'kept' => $kept, 'bytes_freed' => $bytes, 'cursor' => $cursor,
                'done' => $rows->count() < $maxFiles];
        });
    }

    /* ---------------------------------------------------------- helpers */

    /**
     * Originals the converter is keeping, among $paths — so the Media
     * Library's "Rescan folder" does not catalogue them as new pictures.
     *
     * @param  list<string>  $paths
     * @return array<string, int>
     */
    public static function keptOriginals(array $paths): array
    {
        if ($paths === [] || ! self::ready()) {
            return [];
        }

        try {
            return array_flip(DB::table('webp_conversions')->whereIn('from_path', $paths)
                ->whereIn('status', ['converted', 'pending'])->pluck('from_path')->all());
        } catch (\Throwable) {
            return [];
        }
    }

    /** The relative path a WebP of $relative would get, if the name is free. */
    private static function webpName(string $relative): string
    {
        $dir = dirname($relative);
        $stem = pathinfo($relative, PATHINFO_FILENAME);

        for ($n = 0; $n < 1000; $n++) {
            $candidate = ($dir === '.' ? '' : $dir.'/').$stem.($n === 0 ? '' : '-'.$n).'.webp';

            if (! file_exists(public_path($candidate))) {
                return $candidate;
            }
        }

        return $relative;
    }

    /**
     * The absolute path of a relative path, only if it is a regular file
     * strictly inside one of the upload roots. Nothing else is ever read,
     * written or deleted by this class.
     */
    public static function absolute(string $relative): ?string
    {
        $normal = MediaRegistrar::normalise($relative);

        if ($normal === null) {
            return null;
        }

        $public = realpath(public_path());
        $file = realpath(public_path($normal));

        if ($public === false || $file === false || ! is_file($file) || is_link(public_path($normal))) {
            return null;
        }

        $file = str_replace('\\', '/', $file);
        $public = rtrim(str_replace('\\', '/', $public), '/');

        foreach (self::ROOTS as $root) {
            if (str_starts_with($file, $public.'/'.$root)) {
                return $file;
            }

            // (2.60.388) An uploads folder that is itself a link (a hosting
            // panel's shared storage) resolves outside the web root; inside
            // that folder's own real path is still inside the uploads.
            $real = realpath(public_path(rtrim($root, '/')));
            if ($real !== false && str_starts_with($file, rtrim(str_replace('\\', '/', $real), '/').'/')) {
                return $file;
            }
        }

        return null;
    }

    private static function relative(string $absolute, string $root): ?string
    {
        $absolute = str_replace('\\', '/', $absolute);

        if ($root === '' || ! str_starts_with($absolute, $root.'/')) {
            return null;
        }

        return MediaRegistrar::normalise(substr($absolute, strlen($root) + 1));
    }

    private static function excluded(string $relative): bool
    {
        foreach (self::EXCLUDED as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Delete a WebP this class reserved or wrote, and nothing else. */
    private static function discardReservation(?string $relative): void
    {
        if ($relative === null || $relative === '' || ! str_ends_with(strtolower($relative), '.webp')) {
            return;
        }

        $absolute = self::absolute($relative);

        if ($absolute !== null) {
            @unlink($absolute);
        }
    }

    /** Point the library's row for $from at $to, with $to's own facts. */
    private static function moveLibraryRow(string $from, string $to): void
    {
        $absolute = self::absolute($to);

        if ($absolute === null) {
            return;
        }

        $size = @getimagesize($absolute);
        $type = WebpConverter::sniff($absolute);
        $mime = match ($type) {
            WebpConverter::TYPE_WEBP => 'image/webp',
            WebpConverter::TYPE_JPEG => 'image/jpeg',
            WebpConverter::TYPE_PNG => 'image/png',
            default => null,
        };

        DB::table('media')->where('path', $from)->update(array_filter([
            'path' => $to,
            'filename' => basename($to),
            'mime' => $mime,
            'size' => (int) @filesize($absolute) ?: null,
            'width' => is_array($size) ? (int) $size[0] : null,
            'height' => is_array($size) ? (int) $size[1] : null,
            'sizes' => null,
            'updated_at' => now(),
        ], static fn ($v, $k) => $v !== null || $k === 'sizes', ARRAY_FILTER_USE_BOTH));
    }

    /**
     * The storefront caches rendered fragments that carry image URLs. The same
     * clear the admin's own Platform -> Cache -> application button does.
     */
    private static function flushCaches(): void
    {
        try {
            Cache::flush();
        } catch (\Throwable $e) {
            report($e);
        }

        \App\Models\Setting::flushMap();
        \App\Services\SettingsService::forgetMemo();
    }

    /** One batch at a time, across admins and the command. */
    private static function locked(callable $work): array
    {
        $lock = Cache::lock(self::LOCK, 120);

        if (! $lock->get()) {
            return ['error' => 'Another WebP batch is running. Wait for it to finish.', 'busy' => true, 'done' => false];
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    private static function ready(): bool
    {
        try {
            return Schema::hasTable('webp_conversions');
        } catch (\Throwable) {
            return false;
        }
    }
}
