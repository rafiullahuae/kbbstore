<?php

declare(strict_types=1);

namespace App\Services\Seo\Keywords;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Search Console — the best signal there is: the searches that ALREADY
 * show this shop, with impressions, clicks and average position, and the page
 * Google showed for each.
 *
 * Authentication is a service account, signed here with openssl (RS256) — no
 * Google SDK, no new composer dependency. The access token lives in the cache
 * for its own lifetime minus a minute; the key itself never leaves
 * KeywordConfig except into openssl_sign() below.
 *
 * Optional. Not configured, or failing, it returns [] and the module runs on
 * Autocomplete and the shop's own data.
 */
class SearchConsoleSource
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    public const API = 'https://www.googleapis.com/webmasters/v3/sites/';

    public const ROW_LIMIT = 5000;

    public string $error = '';

    public static function validProperty(string $p): bool
    {
        return (bool) preg_match('#^(sc-domain:[a-z0-9.-]{3,190}|https?://[a-z0-9.-]{3,190}(:\d{2,5})?/)$#i', $p);
    }

    public function configured(): bool
    {
        return KeywordConfig::hasGscKey() && self::validProperty(KeywordConfig::options()['gsc_property']);
    }

    /**
     * The last 90 days of queries, by query and page.
     *
     * @return list<array{term: string, impressions: int, clicks: int, position: float, page: string}>
     */
    public function rows(int $days = 90): array
    {
        $this->error = '';
        $key = KeywordConfig::gscKey();
        $property = KeywordConfig::options()['gsc_property'];

        if ($key === null || ! self::validProperty($property)) {
            $this->error = 'Search Console is not connected.';

            return [];
        }

        try {
            $token = $this->token($key);
            $response = Http::timeout(15)->connectTimeout(5)->withToken($token)->acceptJson()
                ->post(self::API.rawurlencode($property).'/searchAnalytics/query', [
                    'startDate' => now()->subDays($days)->toDateString(),
                    'endDate' => now()->subDay()->toDateString(),
                    'dimensions' => ['query', 'page'],
                    'rowLimit' => self::ROW_LIMIT,
                    'dataState' => 'final',
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Search Console answered HTTP '.$response->status().'.');
            }

            return self::parse($response->json());
        } catch (\Throwable $e) {
            $this->error = mb_substr($e->getMessage(), 0, 200);
            Log::info('SEO keywords: Search Console skipped', ['reason' => $this->error]);

            return [];
        }
    }

    /** @return list<array{term: string, impressions: int, clicks: int, position: float, page: string}> */
    public static function parse(mixed $json): array
    {
        $out = [];

        foreach ((is_array($json) && is_array($json['rows'] ?? null)) ? $json['rows'] : [] as $row) {
            if (! is_array($row) || ! is_array($row['keys'] ?? null)) {
                continue;
            }

            $term = KeywordText::clean($row['keys'][0] ?? null);
            if ($term === null) {
                continue;
            }

            $page = parse_url((string) ($row['keys'][1] ?? ''), PHP_URL_PATH);

            $out[] = [
                'term' => $term,
                'impressions' => max(0, (int) ($row['impressions'] ?? 0)),
                'clicks' => max(0, (int) ($row['clicks'] ?? 0)),
                'position' => round(max(0.0, (float) ($row['position'] ?? 0)), 1),
                'page' => is_string($page) ? mb_substr($page, 0, 200) : '',
            ];
        }

        return $out;
    }

    /** RS256 service-account assertion → access token. */
    private function token(array $key): string
    {
        $cacheKey = 'seo_kw_gsc_token:'.sha1($key['client_email']);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $unsigned = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.$b64((string) json_encode([
                'iss' => $key['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URI,
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

        $signature = '';
        if (! openssl_sign($unsigned, $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('The service-account key could not sign a request.');
        }

        $response = Http::asForm()->timeout(10)->connectTimeout(5)->post(self::TOKEN_URI, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned.'.'.$b64($signature),
        ]);

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new \RuntimeException('Google refused the service account (HTTP '.$response->status().').');
        }

        Cache::put($cacheKey, $token, max(60, (int) $response->json('expires_in', 3600) - 60));

        return $token;
    }
}
