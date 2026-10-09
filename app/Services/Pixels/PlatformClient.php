<?php

declare(strict_types=1);

namespace App\Services\Pixels;

use Illuminate\Support\Facades\Http;

/**
 * The only outbound HTTP in Marketing Pixels. (Lane MP)
 *
 * Three platform hosts, fixed here as constants and never read from a setting:
 * a stored ID or token can change WHAT is sent, never WHERE. Short timeouts
 * (2 s to connect, 4 s in all) because a slow platform must never hold a PHP
 * worker for long, even after the response has gone. Nothing here throws: every
 * answer is ['ok', 'status', 'message', 'data'], and the message is the
 * platform's own error text with every credential cut out of it.
 */
final class PlatformClient
{
    /** Meta's Graph API version: the one Meta's own WordPress plugin 5.2.2 pins. */
    public const GRAPH_VERSION = 'v25.0';

    public const META_HOST = 'https://graph.facebook.com';

    public const GA4_HOST = 'https://www.google-analytics.com';

    public const TIKTOK_HOST = 'https://business-api.tiktok.com';

    public const HOSTS = ['graph.facebook.com', 'www.google-analytics.com', 'business-api.tiktok.com'];

    private const CONNECT_TIMEOUT = 2;

    private const TIMEOUT = 4;

    /** POST /{pixel}/events. */
    public function metaEvents(string $pixelId, string $token, array $events, ?string $testCode = null): array
    {
        $body = ['data' => $events, 'access_token' => $token];

        if ($testCode !== null && $testCode !== '') {
            $body['test_event_code'] = $testCode;
        }

        return $this->call('post', self::META_HOST . '/' . self::GRAPH_VERSION . '/' . rawurlencode($pixelId) . '/events', $body, [], [$token]);
    }

    /** GET /{pixel}?fields=id,name — proves the token can reach the pixel. */
    public function metaPixel(string $pixelId, string $token): array
    {
        return $this->call('get', self::META_HOST . '/' . self::GRAPH_VERSION . '/' . rawurlencode($pixelId),
            ['fields' => 'id,name,last_fired_time', 'access_token' => $token], [], [$token]);
    }

    /** Graph GET for the optional OAuth path. */
    public function metaGet(string $path, array $query, array $secrets): array
    {
        return $this->call('get', self::META_HOST . '/' . self::GRAPH_VERSION . '/' . ltrim($path, '/'), $query, [], $secrets);
    }

    /** GA4 Measurement Protocol: /mp/collect, or /debug/mp/collect to validate. */
    public function ga4(string $measurementId, string $apiSecret, array $body, bool $debug = false): array
    {
        $url = self::GA4_HOST . ($debug ? '/debug/mp/collect' : '/mp/collect')
            . '?measurement_id=' . rawurlencode($measurementId) . '&api_secret=' . rawurlencode($apiSecret);

        return $this->call('post', $url, $body, [], [$apiSecret]);
    }

    /** TikTok Events API 2.0: /open_api/v1.3/event/track/. */
    public function tiktok(string $token, array $body): array
    {
        $answer = $this->call('post', self::TIKTOK_HOST . '/open_api/v1.3/event/track/', $body, ['Access-Token' => $token], [$token]);

        // TikTok answers HTTP 200 with a non-zero `code` for a refusal.
        if ($answer['ok'] && (int) ($answer['data']['code'] ?? -1) !== 0) {
            $answer['ok'] = false;
            $answer['message'] = self::scrub((string) ($answer['data']['message'] ?? 'TikTok refused the event.'), [$token]);
        }

        return $answer;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $secrets
     * @return array{ok: bool, status: int, message: string, data: array}
     */
    private function call(string $verb, string $url, array $payload, array $headers, array $secrets): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! in_array($host, self::HOSTS, true) || ! str_starts_with($url, 'https://')) {
            return ['ok' => false, 'status' => 0, 'message' => 'Refused: not a platform address.', 'data' => []];
        }

        try {
            $pending = Http::connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT)
                ->withHeaders($headers)->acceptJson()->withoutRedirecting();
            $response = $verb === 'get' ? $pending->get($url, $payload) : $pending->asJson()->post($url, $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'message' => self::scrub('Could not reach ' . $host . ': ' . $e->getMessage(), $secrets), 'data' => []];
        }

        $data = [];

        try {
            $json = $response->json();
            $data = is_array($json) ? $json : [];
        } catch (\Throwable) {
        }

        if (! $response->successful()) {
            $err = is_array($data['error'] ?? null) ? $data['error'] : [];
            $message = (string) ($err['error_user_msg'] ?? $err['message'] ?? $data['message'] ?? ('HTTP ' . $response->status()));

            return ['ok' => false, 'status' => $response->status(), 'message' => self::scrub($message, $secrets), 'data' => self::scrubData($data, $secrets)];
        }

        return ['ok' => true, 'status' => $response->status(), 'message' => '', 'data' => self::scrubData($data, $secrets)];
    }

    /** @param list<string|null> $secrets */
    public static function scrub(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 6) {
                $text = str_replace([$secret, rawurlencode($secret)], '[redacted]', $text);
            }
        }

        return mb_substr(trim($text), 0, 280);
    }

    private static function scrubData(array $data, array $secrets): array
    {
        $json = json_encode($data);

        if (! is_string($json)) {
            return [];
        }

        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 6 && str_contains($json, $secret)) {
                return [];
            }
        }

        return $data;
    }
}
