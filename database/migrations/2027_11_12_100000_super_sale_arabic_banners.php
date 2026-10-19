<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 19 October 2026, the owner: "can you please apply this super sale banners on
 * arabic version. but don't disturb anything else. give us choice to use this
 * exact banner, or upload our own one simple image." and "must be edit from the
 * super page front-end".
 *
 * 1. Copies the two Arabic Super Sale banners shipped in resources/banners/
 *    into the web root's uploads/appearance/ (beside the English ones) and
 *    records them in the Media Library, so the on-page "Edit header" panel
 *    can pick them again after he tries his own.
 * 2. Sets them as the Super Sale header's Arabic desktop / Arabic phone
 *    pictures -- ONLY where both are still empty, so a picture he chose is
 *    never replaced, only on the Super Sale page's own header, and only while
 *    that header still shows the English banners these two translate
 *    (kbb-super-sale-desktop / kbeautybliss-2026-super-sale).
 *
 * English pages never read these two fields (PageHeaders::picture), so
 * nothing outside /ar/super-sale/ changes. Any failure leaves the page exactly
 * as it was: the Arabic page then shows the English banners, as today.
 */
return new class extends Migration
{
    private const FILES = [
        'img_ar' => 'kbb-super-sale-desktop-ar.webp',
        'img_m_ar' => 'kbeautybliss-2026-super-sale-ar.webp',
    ];

    private const PAGE = 'collection:super-sale';

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        try {
            $row = DB::table('settings')->where('key', 'page_header')->value('value');
            $all = is_string($row) ? json_decode($row, true) : null;

            if (! is_array($all)) {
                $all = \App\Services\PageHeaders::defaults();
            }

            $bag = $all['pages'][self::PAGE] ?? null;

            if (! is_array($bag)) {
                return;
            }

            if (trim((string) ($bag['img_ar'] ?? '')) !== '' || trim((string) ($bag['img_m_ar'] ?? '')) !== '') {
                return;
            }

            // Only where the page still shows the English banners these two
            // translate. Another English banner gets no Arabic one that does
            // not match it.
            if (! str_contains((string) ($bag['img'] ?? ''), '/kbb-super-sale-desktop.')
                && ! str_contains((string) ($bag['img_m'] ?? ''), '/kbeautybliss-2026-super-sale.')) {
                return;
            }

            $dir = rtrim(public_path(), '/').'/uploads/appearance';

            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return;
            }

            $base = $this->base((string) ($bag['img'] ?? ''), (string) ($bag['img_m'] ?? ''));

            foreach (self::FILES as $field => $name) {
                $dest = $dir.'/'.$name;

                if (! is_file($dest)) {
                    $tmp = $dest.'.'.getmypid().'.part';

                    if (! @copy(resource_path('banners/'.$name), $tmp) || ! @rename($tmp, $dest)) {
                        @unlink($tmp);

                        return;
                    }

                    @chmod($dest, 0644);
                }

                if (class_exists(\App\Support\MediaRegistrar::class)) {
                    \App\Support\MediaRegistrar::record('uploads/appearance/'.$name, $name, 'image/webp');
                }

                $bag[$field] = $base.'/uploads/appearance/'.$name;
            }

            $all['pages'][self::PAGE] = $bag;
            $now = now();

            DB::table('settings')->updateOrInsert(
                ['key' => 'page_header'],
                ['value' => json_encode($all, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'autoload' => true, 'updated_at' => $now],
            );

            foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings'] as $cacheKey) {
                try {
                    Cache::forget($cacheKey);
                } catch (\Throwable) {
                    // A cache that cannot forget expires on its own.
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The address the English banners are served from (scheme and host), so the
     * Arabic ones are written the same way; else the site's own address.
     */
    private function base(string ...$english): string
    {
        foreach ($english as $url) {
            $at = strpos($url, '/uploads/appearance/');

            if ($at !== false && preg_match('#^https?://[^/\s"\'<>]+$#i', substr($url, 0, $at)) === 1) {
                return substr($url, 0, $at);
            }
        }

        $site = DB::table('settings')->where('key', 'site_url')->value('value');

        return rtrim(is_string($site) && $site !== '' ? $site : (string) config('app.url'), '/');
    }

    public function down(): void {}
};
