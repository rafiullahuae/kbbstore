<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * IndexNow — instant-indexing protocol adopted by Bing, Yandex, Naver, Seznam,
 * and Yep (not Google — Google relies on the sitemap instead, which is why
 * this is a separate mechanism, not a replacement for sitemap.xml).
 *
 * Submitting to api.indexnow.org's generic endpoint is sufficient — the
 * protocol requires participating engines to share submissions with each
 * other, so there's no need to POST separately to each one.
 */
class IndexNow
{
    private const ENDPOINT = 'https://api.indexnow.org/IndexNow';

    /** The key, generating and persisting one on first use rather than requiring a manual step. */
    public static function key(): string
    {
        static $resolved = null;

        // Once resolved once in this request, stick with it — Setting::map()
        // caches its own result in a function-local static that flushMap()
        // can't reach, so without this, generating a key and then reading it
        // back in the same request (e.g. the key-file route calling this
        // right after a submission just generated one) could see the stale,
        // pre-write settings snapshot and mint a second, different key.
        if ($resolved !== null) {
            return $resolved;
        }

        $settings = Setting::map();
        $key = $settings['indexnow_key'] ?? '';

        if (self::validKey($key)) {
            return $resolved = $key;
        }

        $key = Str::lower(Str::random(32));
        Setting::query()->updateOrCreate(['key' => 'indexnow_key'], ['value' => $key]);
        Setting::flushMap();

        return $resolved = $key;
    }

    /** 8–128 chars, letters/numbers/dashes only — the protocol's own constraint on the key. */
    public static function validKey(string $key): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9\-]{8,128}$/', $key);
    }

    public static function enabled(): bool
    {
        return (Setting::map()['indexnow_on'] ?? '') === '1';
    }

    /**
     * Fire-and-forget: failures are logged, never thrown. A search-engine
     * ping failing must never block or fail the product/page save that
     * triggered it — indexing is a nice-to-have on top of a successful
     * save, not a precondition for one.
     */
    public static function submit(array $urls, ?string $host = null, ?string $keyLocation = null): bool
    {
        $urls = array_values(array_filter($urls));

        if (empty($urls) || !self::enabled()) {
            return false;
        }

        $key = self::key();
        $host ??= self::hostFromSettings();
        $keyLocation ??= self::keyLocationUrl($host, $key);

        if ($host === null) {
            return false;
        }

        try {
            $response = Http::timeout(5)->post(self::ENDPOINT, [
                'host' => $host,
                'key' => $key,
                'keyLocation' => $keyLocation,
                'urlList' => $urls,
            ]);

            if (!$response->successful()) {
                Log::warning('indexnow: submission rejected', [
                    'status' => $response->status(),
                    'urls' => $urls,
                ]);
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('indexnow: submission failed', ['error' => $e->getMessage(), 'urls' => $urls]);

            return false;
        }
    }

    public static function submitOne(string $url): bool
    {
        return self::submit([$url]);
    }

    private static function hostFromSettings(): ?string
    {
        $base = rtrim((string) (Setting::map()['site_url'] ?? ''), '/');

        if ($base === '') {
            return null;
        }

        return (string) parse_url($base, PHP_URL_HOST);
    }

    private static function keyLocationUrl(?string $host, string $key): ?string
    {
        if ($host === null) {
            return null;
        }

        return 'https://' . $host . '/' . $key . '.txt';
    }
}
