<?php

declare(strict_types=1);

namespace App\Services\Instagram;

use Illuminate\Support\Facades\Http;

/**
 * Instagram API with Facebook Login — the only thing in this application that
 * talks to graph.facebook.com (Lane IG2).
 *
 * The owner's Meta app offers "API setup with Facebook login" and not "API setup
 * with Instagram login", so this is the route he can actually use. The flow, as
 * Meta documents it for a Facebook Login for Business configuration of type
 * "User access token":
 *
 *   1. www.facebook.com/{v}/dialog/oauth?client_id&redirect_uri&state
 *      &response_type=code&config_id   (or &scope=… when no configuration id)
 *   2. GET  /{v}/oauth/access_token?client_id&redirect_uri&client_secret&code
 *                                                → short-lived user token
 *   3. GET  /{v}/oauth/access_token?grant_type=fb_exchange_token&client_id
 *           &client_secret&fb_exchange_token     → long-lived user token (~60 d)
 *   4. GET  /{v}/me/accounts?fields=id,name,access_token,instagram_business_account{…}
 *                                                → each Page with ITS token; a
 *           Page token read with a long-lived user token has no expiry of its own
 *   5. the Page's instagram_business_account.id  → the IG user id
 *   then GET /{v}/{ig-user-id}?fields=…  and  /{v}/{ig-user-id}/media?fields=…
 *   with the Page token, for the profile and the posts.
 *
 * Same contract as InstagramClient: NO METHOD THROWS, every answer is
 * ['ok' => true, 'data' => …] or ['ok' => false, 'reason', 'error', 'detail'],
 * and nothing secret is ever inside an `error` or `detail` (scrub()).
 *
 * ── OUTBOUND ONLY TO graph.facebook.com ─────────────────────────────────────
 *
 * Every URL here is built from GRAPH, a constant. A `paging.next` URL from a
 * response body is never followed (it carries the token, and its host is
 * whatever the body says); the opaque `after` cursor is re-sent to our own
 * pinned endpoint instead, exactly as InstagramClient::media() does. The
 * owner's browser is the only thing that visits www.facebook.com (DIALOG).
 *
 * ── appsecret_proof ON EVERY TOKEN-BEARING CALL ─────────────────────────────
 *
 * HMAC-SHA256 of the token keyed with the app secret. Meta checks it when the
 * app has "Require app secret" switched on (App settings → Advanced), and it
 * means a token that leaked from this shop on its own is useless to whoever has
 * it. Sent always, so turning that switch on later breaks nothing.
 */
class FacebookGraphClient implements InstagramSource
{
    /** The Graph host. One constant, so outbound can only ever go here. */
    public const GRAPH = 'https://graph.facebook.com';

    /**
     * The Graph version, pinned. v26.0 is the current version (released 29 July
     * 2026) and the one the owner's own app dashboard offers. An upgrade is a
     * one-line diff in a package with a test run behind it.
     */
    public const VERSION = 'v26.0';

    /** The Facebook login dialog the owner's browser is sent to. */
    public const DIALOG = 'https://www.facebook.com/'.self::VERSION.'/dialog/oauth';

    /**
     * The permissions, used ONLY when no configuration id is saved. With a
     * Facebook Login for Business configuration, `config_id` replaces `scope` and
     * the permissions are the ones ticked in that configuration.
     *
     *   instagram_basic           the account's profile and media
     *   instagram_manage_insights `shares` / `views` on the Spotted cards
     *   pages_show_list           /me/accounts — which Pages he manages
     *   pages_read_engagement     the Page's instagram_business_account
     *   business_management       a Page that sits in a business portfolio
     */
    public const SCOPE = 'instagram_basic,instagram_manage_insights,pages_show_list,pages_read_engagement,business_management';

    /** Same as InstagramClient: a person is watching a button. */
    public const TIMEOUT = 8;

    /** Graph's ceiling for a media page. */
    public const PAGE = 100;

    /*
     * Meta's error codes that mean "this token is no longer accepted" — the
     * Reconnect case. 190 is OAuthException (expired, password changed, app
     * removed, session invalidated: subcodes 458/460/463/464/467/492 all ride on
     * it). 102 is the older "session key invalid".
     */
    public const TOKEN_DEAD_CODES = [190, 102];

    /** Rate limits: app (4), user (17), page (32), resource (613), IG business (80002). */
    public const RATE_CODES = [4, 17, 32, 613, 80002];

    /** One word per cause; permission refusals are code 10 and the 200–299 range. */
    public const REASONS = [
        'no_app' => 'No Facebook app ID and app secret are saved yet.',
        'no_token' => 'This shop is not connected through Facebook yet.',
        'expired' => 'Facebook no longer accepts the stored connection — press Reconnect.',
        'permission' => 'Facebook refused a permission this shop needs.',
        'rate_limited' => 'Facebook is limiting requests from this app for a while. Try again in an hour.',
        'refused' => 'Facebook refused the request.',
        'unreachable' => 'Facebook could not be reached from this server.',
        'malformed' => 'Facebook answered with something this shop could not read.',
    ];

    /* ------------------------------------------------------------- the login */

    /**
     * Step 2: the code becomes a short-lived user token.
     *
     * A GET, because that is the form Meta documents for this endpoint. The
     * secret is in the query string of an HTTPS request to graph.facebook.com,
     * which only Meta can read; it is never in anything this shop logs.
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $appId = InstagramCredentials::fbAppId();
        $secret = InstagramCredentials::fbSecret();

        if ($appId === null || $secret === null) {
            return $this->fail('no_app');
        }

        return $this->request(
            fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH.'/'.self::VERSION.'/oauth/access_token', [
                'client_id' => $appId,
                'redirect_uri' => $redirectUri,
                'client_secret' => $secret,
                'code' => $code,
            ]),
            ['access_token'],
        );
    }

    /** Step 3: short-lived → long-lived user token (about sixty days). */
    public function exchangeForLongLived(string $shortToken): array
    {
        $appId = InstagramCredentials::fbAppId();
        $secret = InstagramCredentials::fbSecret();

        if ($appId === null || $secret === null) {
            return $this->fail('no_app');
        }

        return $this->request(
            fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH.'/'.self::VERSION.'/oauth/access_token', [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $appId,
                'client_secret' => $secret,
                'fb_exchange_token' => $shortToken,
            ]),
            ['access_token'],
            [$shortToken],
        );
    }

    /**
     * Step 4: the Pages the owner granted, each with its own token and the
     * Instagram account linked to it (if any).
     *
     * @return array{ok: bool, pages?: list<array{page_id: string, page_name: string, page_token: string, ig_id: string, ig_username: string}>, error?: string, reason?: string, detail?: string}
     */
    public function pages(string $userToken): array
    {
        $answer = $this->request(
            fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH.'/'.self::VERSION.'/me/accounts', $this->signed($userToken, [
                'fields' => 'id,name,access_token,instagram_business_account{id,username}',
                'limit' => 100,
            ])),
            ['data'],
            [$userToken],
        );

        if (! ($answer['ok'] ?? false)) {
            return $answer;
        }

        $pages = [];

        foreach ((array) ($answer['data']['data'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $pageId = (string) ($row['id'] ?? '');

            if (preg_match('/^[0-9]{1,32}$/', $pageId) !== 1) {
                continue;
            }

            $ig = is_array($row['instagram_business_account'] ?? null) ? $row['instagram_business_account'] : [];
            $igId = (string) ($ig['id'] ?? '');

            $pages[] = [
                'page_id' => $pageId,
                'page_name' => mb_substr(trim((string) ($row['name'] ?? '')), 0, 120),
                'page_token' => (string) ($row['access_token'] ?? ''),
                'ig_id' => preg_match('/^[0-9]{1,32}$/', $igId) === 1 ? $igId : '',
                'ig_username' => preg_match('/^[A-Za-z0-9._]{1,30}$/', (string) ($ig['username'] ?? '')) === 1
                    ? (string) $ig['username'] : '',
            ];
        }

        return ['ok' => true, 'pages' => $pages];
    }

    /**
     * What Meta says about a token: whose app it belongs to and when it stops
     * working. Read with the APP token (`{app-id}|{app-secret}`), which is the
     * form Meta documents for debug_token.
     *
     * @return array{ok: bool, app_id?: string, valid?: bool, expires_at?: int, data_access_expires_at?: int, error?: string, reason?: string}
     */
    public function debugToken(string $token): array
    {
        $appId = InstagramCredentials::fbAppId();
        $secret = InstagramCredentials::fbSecret();

        if ($appId === null || $secret === null) {
            return $this->fail('no_app');
        }

        $answer = $this->request(
            fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH.'/'.self::VERSION.'/debug_token', [
                'input_token' => $token,
                'access_token' => $appId.'|'.$secret,
            ]),
            ['data'],
            [$token],
        );

        if (! ($answer['ok'] ?? false)) {
            return $answer;
        }

        $d = (array) ($answer['data']['data'] ?? []);

        return [
            'ok' => true,
            'app_id' => (string) ($d['app_id'] ?? ''),
            'valid' => (bool) ($d['is_valid'] ?? false),
            'expires_at' => (int) ($d['expires_at'] ?? 0),
            'data_access_expires_at' => (int) ($d['data_access_expires_at'] ?? 0),
        ];
    }

    /* ---------------------------------------------- the account (InstagramSource) */

    public function profile(string $token): array
    {
        $igId = $this->igUserId();

        if ($igId === null) {
            return $this->fail('no_token');
        }

        $answer = $this->request(
            fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH.'/'.self::VERSION.'/'.$igId, $this->signed($token, [
                'fields' => 'id,username,name,profile_picture_url,followers_count,follows_count,media_count',
            ])),
            ['id'],
            [$token],
        );

        if (! ($answer['ok'] ?? false)) {
            return $answer;
        }

        /*
         * `account_type` is not a field on the IG User node of this API — only an
         * Instagram Business or Creator account can be linked to a Page and be
         * read here at all — so it is filled with PROFESSIONAL, which is true of
         * both and which the screen prints as "Professional account".
         */
        $answer['data']['account_type'] = 'PROFESSIONAL';

        return $answer;
    }

    public function media(string $token, int $limit, ?string $after = null): array
    {
        $igId = $this->igUserId();

        if ($igId === null) {
            return $this->fail('no_token');
        }

        $query = [
            'fields' => 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,'
                .'like_count,comments_count,children{media_url,media_type,thumbnail_url}',
            'limit' => max(1, min(self::PAGE, $limit)),
        ];

        if ($after !== null && preg_match('/^[A-Za-z0-9_=-]{1,512}$/', $after) === 1) {
            $query['after'] = $after;
        }

        return $this->request(
            fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH.'/'.self::VERSION.'/'.$igId.'/media', $this->signed($token, $query)),
            ['data'],
            [$token],
        );
    }

    public function insights(string $token, string $mediaId, bool $video): array
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $mediaId) !== 1) {
            return $this->fail('malformed', 'Not a media id.');
        }

        $answer = $this->request(
            fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH.'/'.self::VERSION.'/'.$mediaId.'/insights', $this->signed($token, [
                'metric' => $video ? 'shares,views' : 'shares',
            ])),
            ['data'],
            [$token],
        );

        if (! ($answer['ok'] ?? false)) {
            return $answer;
        }

        $found = ['shares' => null, 'views' => null];

        foreach ((array) ($answer['data']['data'] ?? []) as $metric) {
            if (! is_array($metric) || ! array_key_exists((string) ($metric['name'] ?? ''), $found)) {
                continue;
            }

            $value = $metric['values'][0]['value'] ?? ($metric['total_value']['value'] ?? null);

            $found[(string) $metric['name']] = is_int($value) || (is_string($value) && ctype_digit($value))
                ? max(0, (int) $value)
                : null;
        }

        return ['ok' => true] + $found;
    }

    /* ------------------------------------------------------------------ plumbing */

    /** The stored IG user id, only if it is a Graph id (digits). */
    private function igUserId(): ?string
    {
        $id = InstagramCredentials::userId();

        return preg_match('/^[0-9]{1,32}$/', $id) === 1 ? $id : null;
    }

    /**
     * The token and its appsecret_proof added to a query.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function signed(string $token, array $query): array
    {
        $query['access_token'] = $token;

        $secret = InstagramCredentials::fbSecret();

        if ($secret !== null) {
            $query['appsecret_proof'] = hash_hmac('sha256', $token, $secret);
        }

        return $query;
    }

    /**
     * One call, one answer, nothing escapes. `$secrets` are extra values (a
     * token passed in rather than stored) to redact from any error text.
     *
     * @param  callable(): \Illuminate\Http\Client\Response  $call
     * @param  list<string>  $required
     * @param  list<string>  $secrets
     */
    private function request(callable $call, array $required, array $secrets = []): array
    {
        try {
            $response = $call();
        } catch (\Throwable $e) {
            return $this->fail('unreachable', $e->getMessage(), $secrets);
        }

        try {
            $body = $response->json();
        } catch (\Throwable) {
            $body = null;
        }

        if (! $response->successful()) {
            $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
            $code = (int) ($error['code'] ?? 0);
            $message = (string) ($error['message'] ?? '');

            $reason = match (true) {
                in_array($code, self::TOKEN_DEAD_CODES, true) => 'expired',
                in_array($code, self::RATE_CODES, true) => 'rate_limited',
                $code === 10 || ($code >= 200 && $code <= 299) => 'permission',
                default => 'refused',
            };

            return $this->fail($reason, $message !== '' ? $message : 'HTTP '.$response->status(), $secrets);
        }

        if (! is_array($body)) {
            return $this->fail('malformed', 'The response was not JSON.', $secrets);
        }

        foreach ($required as $key) {
            if (! array_key_exists($key, $body)) {
                return $this->fail('malformed', 'The response carried no “'.$key.'”.', $secrets);
            }
        }

        return ['ok' => true, 'data' => $body];
    }

    /** @param list<string> $secrets */
    private function fail(string $reason, string $detail = '', array $secrets = []): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'error' => self::REASONS[$reason] ?? self::REASONS['refused'],
            'detail' => $this->scrub(trim($detail), $secrets),
        ];
    }

    /**
     * The secret and every token we know of, cut out of text about to be shown.
     * A blunt substring replace, for the reason InstagramClient::scrub() gives.
     *
     * @param  list<string>  $extra
     */
    private function scrub(string $text, array $extra = []): string
    {
        if ($text === '') {
            return '';
        }

        foreach (array_merge([InstagramCredentials::fbSecret(), InstagramCredentials::token()], $extra) as $value) {
            if (is_string($value) && strlen($value) >= 8) {
                $text = str_replace($value, '[redacted]', $text);
            }
        }

        return mb_substr($text, 0, 400);
    }
}
