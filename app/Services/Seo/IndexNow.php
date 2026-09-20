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

    /**
     * The key resolved for this process, or null if it has not been asked for.
     *
     * A class property rather than a `static` inside key(), for the same reason
     * Setting::$memo is one: a function-local static cannot be reached from
     * anywhere, so nothing could ever clear it. That made key() an order
     * dependency with no way out — whichever test asked first minted or read a
     * key, and every test afterwards got THAT key however the settings table
     * had since been seeded. StorefrontRouteWalkTest has a comment recording
     * the workaround it had to adopt (ask the application, never the seeder).
     * forgetKey() is the seam that was missing; the suite calls it between
     * tests, and nothing in the application needs to.
     */
    private static ?string $resolved = null;

    /** The key, generating and persisting one on first use rather than requiring a manual step. */
    public static function key(): string
    {
        // Once resolved once in this request, stick with it — Setting::map()
        // caches its own result in a process-level memo, so without this,
        // generating a key and then reading it back in the same request (e.g.
        // the key-file route calling this right after a submission just
        // generated one) could see the stale, pre-write settings snapshot and
        // mint a second, different key.
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $settings = Setting::map();
        $key = $settings['indexnow_key'] ?? '';

        if (self::validKey($key)) {
            return self::$resolved = $key;
        }

        $key = Str::lower(Str::random(32));
        Setting::query()->updateOrCreate(['key' => 'indexnow_key'], ['value' => $key]);
        Setting::flushMap();

        return self::$resolved = $key;
    }

    /** Drop the resolved key so the next key() reads the settings table again. */
    public static function forgetKey(): void
    {
        self::$resolved = null;
    }

    /** 8–128 chars, letters/numbers/dashes only — the protocol's own constraint on the key. */
    public static function validKey(string $key): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9\-]{8,128}$/', $key);
    }

    /**
     * NEVER ON A PRIVATE INSTALL, whatever the setting says.
     *
     * This is the hole a noindex header does not close, and it is the worst
     * one, because it is not passive. Every other protection waits for a
     * crawler to arrive and then tells it to go away. IndexNow is an outbound
     * POST that hands Bing a list of URLs and asks it to come and look.
     *
     * A staging site cloned from production arrives with `indexnow_on` already
     * '1' and a key already minted, because it is a copy of a live shop's
     * settings table. The first product saved on it would announce the staging
     * URL to a search engine -- from the one site that is meant to be invisible,
     * by the one mechanism that reaches out rather than waiting.
     *
     * So the check is here, in the single place both submit() and submitOne()
     * pass through, rather than at either call site.
     */
    public static function enabled(): bool
    {
        if (\App\Support\SiteHost::isPrivate()) {
            return false;
        }

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
