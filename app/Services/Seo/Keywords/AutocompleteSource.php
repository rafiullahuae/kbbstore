<?php

declare(strict_types=1);

namespace App\Services\Seo\Keywords;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Autocomplete — "what do people type after this?", the related and
 * highly searched signal.
 *
 * UNOFFICIAL. suggestqueries.google.com has no contract, no key and no quota
 * page; it can change shape or refuse us at any time. So:
 *   - ONE request per second at most, across requests too (the last call's time
 *     is in the cache, and a step that starts within a second of the previous
 *     one waits);
 *   - each seed is cached for 30 days, so a re-sync asks Google nothing it
 *     asked this month;
 *   - 5-second timeout, and ANY failure — DNS, timeout, 429, a body that is not
 *     the JSON we expect — is logged once and that seed is skipped. A sync
 *     never fails because Google did not answer; it carries on with the bank it
 *     has, which on a box with no internet is the shop's own data alone.
 */
class AutocompleteSource
{
    public const ENDPOINT = 'https://suggestqueries.google.com/complete/search';

    public const CACHE_DAYS = 30;

    public const MIN_GAP_MS = 1000;

    private const LAST_KEY = 'seo_kw_ac_last';

    /** @var \Closure(int): void */
    private \Closure $sleep;

    public int $requests = 0;

    public int $failures = 0;

    public function __construct(?\Closure $sleep = null)
    {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /** @return list<string> cleaned suggestions, best first; [] on any failure. */
    public function suggest(string $seed, string $locale): array
    {
        $seed = KeywordText::clean($seed);
        if ($seed === null) {
            return [];
        }

        $locale = $locale === 'ar' ? 'ar' : 'en';
        $cacheKey = 'seo_kw_ac:'.$locale.':'.sha1($seed);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $this->throttle();
        $this->requests++;

        try {
            $response = Http::timeout(5)->connectTimeout(5)->acceptJson()->get(self::ENDPOINT, [
                'client' => 'firefox',
                'hl' => $locale,
                'gl' => 'ae',
                'ie' => 'utf-8',
                'oe' => 'utf-8',
                'q' => $seed,
            ]);
            Cache::put(self::LAST_KEY, (int) floor(microtime(true) * 1000), 60);

            if (! $response->successful()) {
                throw new \RuntimeException('HTTP '.$response->status());
            }

            $out = self::parse($response->body());
        } catch (\Throwable $e) {
            Cache::put(self::LAST_KEY, (int) floor(microtime(true) * 1000), 60);
            $this->failures++;
            Log::info('SEO keywords: autocomplete skipped a seed', ['seed' => $seed, 'reason' => mb_substr($e->getMessage(), 0, 160)]);

            return [];
        }

        Cache::put($cacheKey, $out, now()->addDays(self::CACHE_DAYS));

        return $out;
    }

    /** ["seed", ["s1", "s2", …], …] → cleaned list. Anything else → []. */
    public static function parse(string $body): array
    {
        $data = json_decode($body, true);

        if (! is_array($data) || ! is_array($data[1] ?? null)) {
            // Some edges answer in Latin-1 despite oe=utf-8.
            $data = json_decode((string) mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1'), true);
            if (! is_array($data) || ! is_array($data[1] ?? null)) {
                return [];
            }
        }

        $out = [];
        foreach (array_slice($data[1], 0, 10) as $s) {
            $clean = KeywordText::clean($s);
            if ($clean !== null && ! in_array($clean, $out, true)) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    private function throttle(): void
    {
        $last = Cache::get(self::LAST_KEY);
        if (! is_int($last)) {
            return;
        }

        $wait = self::MIN_GAP_MS - ((int) floor(microtime(true) * 1000) - $last);
        if ($wait > 0) {
            ($this->sleep)(min($wait, self::MIN_GAP_MS));
        }
    }
}
