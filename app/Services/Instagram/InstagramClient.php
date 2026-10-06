<?php

declare(strict_types=1);

namespace App\Services\Instagram;

use Illuminate\Support\Facades\Http;

/**
 * The only thing in this application that talks to Meta.
 *
 * Phase 21, Lane IG. Every endpoint, field list and grant type here is written out
 * in docs/IG-PROFILE.md §1 with what it costs and whether it needs App Review.
 *
 * ── NO METHOD ON THIS CLASS THROWS. NOT ONE. ────────────────────────────────
 *
 * docs/UGC-ENGAGEMENT.md set the rule and its wording is the reason: "A DNS
 * failure behind an admin button is a sentence, not a 500, and an operator cannot
 * act on a 500." So every method returns the same three-valued shape —
 *
 *     ['ok' => true,  'data' => [...]]
 *     ['ok' => false, 'error' => 'a sentence for the owner', 'reason' => 'a word for us']
 *
 * — and the `reason` word is the other half of that document's lesson: "'We have no
 * numbers' has at least five causes and the operator can act on four of them." The
 * REASONS list below is that vocabulary, and the screen prints a different sentence
 * and a different next step for each one.
 *
 * ── NOTHING SECRET IS EVER IN A RETURNED `error` ────────────────────────────
 *
 * Meta echoes a good deal back in its error bodies. The sentence handed upward is
 * built from Meta's `error.message` with the token and the secret REDACTED out of
 * it by substring — see scrub(). That is belt and braces on top of never putting
 * them in a URL's query string, and it matters because the screen prints the
 * sentence and somebody will paste the screen into a support thread.
 *
 * ── AND NOTHING HERE IS ON A PAGE RENDER PATH ──────────────────────────────
 *
 * Every caller is an admin endpoint. The storefront reads rows and cached arrays
 * and never resolves this class at all — "a rail that blocks on a third party is a
 * shop that goes down when they do", and this file is the third party.
 */
class InstagramClient
{
    /** Where the authorisation screen the owner sees lives. */
    public const AUTHORIZE_URL = 'https://www.instagram.com/oauth/authorize';

    /** Where an authorisation code becomes a one-hour token. */
    public const TOKEN_URL = 'https://api.instagram.com/oauth/access_token';

    /** The Graph host for everything after that. */
    public const GRAPH = 'https://graph.instagram.com';

    /**
     * The Graph version this lane wrote against, pinned rather than left off.
     *
     * An unversioned Graph path follows Meta's own default, which MOVES — so the
     * shape of a response could change under a shop nobody has touched, on Meta's
     * schedule. Pinned, an upgrade is a one-line diff in a package with a test
     * run behind it.
     */
    public const VERSION = 'v23.0';

    /**
     * The scopes this feature asks for. docs/IG-PROFILE.md §4 lists what it
     * deliberately does NOT ask for: no publishing, no business discovery.
     *
     * (Lane SG, 2.60.417) `instagram_business_manage_insights` was added because
     * the owner asked for SHARES on the #KBeautyBliss Spotted cards, and `shares`
     * is a media INSIGHTS metric -- it is not a field on the media object the way
     * like_count and comments_count are. A connection made before this package
     * does not hold it, so shares stay hidden (never 0) until the owner presses
     * Reconnect once on Content → Instagram. Comma-separated, which is how the
     * Instagram Login authorize URL takes more than one.
     */
    public const SCOPE = 'instagram_business_basic,instagram_business_manage_insights';

    /** (Lane SG) The most a media page can carry; Graph's own ceiling. */
    public const PAGE = 100;

    /**
     * Seconds. Short, because a person is watching a button.
     *
     * Eight is what SiteAddressApiController::probe and CacheApiController already
     * use for an outbound check behind an admin button, and matching them is worth
     * more than a number chosen fresh: an operator who has learned how long this
     * console waits before it gives up has learned it once.
     */
    public const TIMEOUT = 8;

    /**
     * Why we have nothing, in one word per cause — the vocabulary the screen
     * turns into a sentence and a next step.
     *
     * A single `false` flattens five different problems into one shrug, four of
     * which the owner can actually fix. That is docs/UGC-ENGAGEMENT.md's finding,
     * and its deleted MetricsAnswer::STATES had seven of these for the same reason.
     */
    public const REASONS = [
        'no_app' => 'No Instagram app id and secret are saved yet.',
        'no_token' => 'This shop is not connected to Instagram yet.',
        'expired' => 'The stored connection has expired and needs reconnecting.',
        'refused' => 'Instagram refused the request — usually the permission or the account type.',
        'unreachable' => 'Instagram could not be reached from this server.',
        'malformed' => 'Instagram answered with something this shop could not read.',
        'not_professional' => 'The account is not a Business or Creator account, so Instagram returns nothing.',
    ];

    /* ------------------------------------------------------------------ the flow */

    /**
     * Step 2 of docs/IG-PROFILE.md §1: an authorisation code becomes a one-hour
     * token.
     *
     * A POST WITH A FORM BODY, and the secret is in that body rather than in a
     * query string — deliberately. A query string is logged by every proxy and web
     * server between here and Meta, and by this application's own access log if
     * anything ever echoes a request URL. Meta accepts both; only one of them is
     * safe to be wrong about.
     *
     * @return array{ok: bool, data?: array<string, mixed>, error?: string, reason?: string}
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $appId = InstagramCredentials::appId();
        $secret = InstagramCredentials::secret();

        if ($appId === null || $secret === null) {
            return $this->fail('no_app');
        }

        return $this->request(
            fn () => Http::asForm()->timeout(self::TIMEOUT)->post(self::TOKEN_URL, [
                'client_id' => $appId,
                'client_secret' => $secret,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]),
            ['access_token'],
        );
    }

    /**
     * Step 3: the one-hour token becomes a sixty-day one.
     *
     * TWO CALLS AND NOT ONE, because Meta's flow is two calls and pretending
     * otherwise means storing a token that dies in an hour and a shop that looks
     * connected for exactly that long. `ig_exchange_token` is the grant type, and
     * `expires_in` on the response is what InstagramCredentials clamps and stores.
     *
     * @return array{ok: bool, data?: array<string, mixed>, error?: string, reason?: string}
     */
    public function exchangeForLongLived(string $shortToken): array
    {
        $secret = InstagramCredentials::secret();

        if ($secret === null) {
            return $this->fail('no_app');
        }

        return $this->request(
            fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH.'/access_token', [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => $secret,
                'access_token' => $shortToken,
            ]),
            ['access_token'],
        );
    }

    /**
     * Step 4: another sixty days, from a token that is still valid.
     *
     * "Still valid" is the whole constraint and it is why
     * InstagramCredentials::REFRESH_WINDOW_DAYS exists: once the token has lapsed
     * this call fails and the only repair is the full authorisation again. There is
     * no cron on this host (docs/IG-PROFILE.md §5), so this is called
     * opportunistically by every admin endpoint that touches Instagram.
     *
     * @return array{ok: bool, data?: array<string, mixed>, error?: string, reason?: string}
     */
    public function refresh(string $longToken): array
    {
        return $this->request(
            fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH.'/refresh_access_token', [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $longToken,
            ]),
            ['access_token'],
        );
    }

    /**
     * Step 5: our own profile.
     *
     * `followers_count` and `media_count` are on this edge for an account we hold a
     * token for — which is the finding docs/IG-PROFILE.md §1 is built on, and the
     * thing that makes the owner's "our profile box should also show" cost nothing
     * extra.
     *
     * @return array{ok: bool, data?: array<string, mixed>, error?: string, reason?: string}
     */
    public function profile(string $token): array
    {
        return $this->request(
            fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH.'/'.self::VERSION.'/me', [
                'fields' => 'id,username,name,account_type,profile_picture_url,followers_count,follows_count,media_count',
                'access_token' => $token,
            ]),
            ['id'],
        );
    }

    /**
     * Step 6: our own recent media, WITH the counts.
     *
     * `like_count` and `comments_count` are the two fields docs/UGC-ENGAGEMENT.md
     * concluded cost an App Review — true for somebody ELSE's account through
     * business discovery, and not true here. See docs/IG-PROFILE.md §1 for the
     * distinction, and its ▲ warning that this is the file's one load-bearing
     * unverified claim.
     *
     * `children` is asked for so a carousel has a first frame to draw; the rest of
     * a carousel's members are not stored, because the tile shows one picture and
     * the embed shows the album.
     *
     * @return array{ok: bool, data?: array<string, mixed>, error?: string, reason?: string}
     */
    public function media(string $token, int $limit, ?string $after = null): array
    {
        $query = [
            'fields' => 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,'
                .'like_count,comments_count,children{media_url,media_type,thumbnail_url}',
            'limit' => max(1, min(self::PAGE, $limit)),
            'access_token' => $token,
        ];

        /*
         * (Lane SG) The NEXT page, by the `after` cursor -- rebuilt here against
         * our own pinned endpoint rather than by following `paging.next`, which
         * is a full URL Meta hands back WITH THE ACCESS TOKEN IN IT. Requesting
         * whatever URL arrived in a response body would send the token to any
         * host that body named; the cursor is an opaque string and the host is a
         * constant. Only a cursor of the shape Graph issues is passed on.
         */
        if ($after !== null && preg_match('/^[A-Za-z0-9_=-]{1,512}$/', $after) === 1) {
            $query['after'] = $after;
        }

        return $this->request(
            fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH.'/'.self::VERSION.'/me/media', $query),
            ['data'],
        );
    }

    /**
     * (Lane SG) One media object's insights: `shares`, and `views` for a video.
     *
     * GET /{media-id}/insights?metric=… -- needs `instagram_business_manage_
     * insights`. Asked for a metric a media type does not support, Graph refuses
     * the WHOLE call, so a still asks for `shares` alone and only a VIDEO adds
     * `views`. Answers are read from either shape Graph has used
     * (`values[0].value`, or `total_value.value`); anything else is "no value",
     * which the caller stores as null and the card does not draw.
     *
     * UNVERIFIED AGAINST THE LIVE API from this machine (no route to Meta): the
     * metric names are the ones Meta's Instagram API with Instagram Login
     * documents for FEED/REELS media in v22+; `views` replaced `plays` and
     * `video_views`. The tests fake both answer shapes.
     *
     * @return array{ok: bool, shares?: ?int, views?: ?int, error?: string, reason?: string}
     */
    public function insights(string $token, string $mediaId, bool $video): array
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $mediaId) !== 1) {
            return $this->fail('malformed', 'Not a media id.');
        }

        $answer = $this->request(
            fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH.'/'.self::VERSION.'/'.$mediaId.'/insights', [
                'metric' => $video ? 'shares,views' : 'shares',
                'access_token' => $token,
            ]),
            ['data'],
        );

        if (! ($answer['ok'] ?? false)) {
            return $answer;
        }

        $found = ['shares' => null, 'views' => null];

        foreach ((array) ($answer['data']['data'] ?? []) as $metric) {
            if (! is_array($metric)) {
                continue;
            }

            $name = (string) ($metric['name'] ?? '');

            if (! array_key_exists($name, $found)) {
                continue;
            }

            $value = $metric['values'][0]['value'] ?? ($metric['total_value']['value'] ?? null);

            $found[$name] = is_int($value) || (is_string($value) && ctype_digit($value))
                ? max(0, (int) $value)
                : null;
        }

        return ['ok' => true] + $found;
    }

    /**
     * Fetch one thumbnail's BYTES.
     *
     * Separate from request() because this one is not JSON and is not Meta's Graph:
     * it is a signed CDN URL, and the only things we want to know about it are
     * "did it answer 200" and "how many bytes did it send".
     *
     * ── THE SIZE CAP IS ON THE RESPONSE, NOT ON A HEADER ────────────────────
     *
     * A Content-Length is a claim. The check is on what actually arrived, after it
     * arrived, because a remote server that wants to fill this shop's disk will
     * send a header that says otherwise. Eight megabytes is far more than an
     * Instagram thumbnail and far less than a shared plan minds.
     *
     * @return array{ok: bool, body?: string, error?: string, reason?: string}
     */
    public function fetchImage(string $url): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withOptions(['allow_redirects' => ['max' => 3]])
                ->get($url);
        } catch (\Throwable $e) {
            return $this->fail('unreachable', $e->getMessage());
        }

        if (! $response->successful()) {
            return $this->fail('unreachable', 'HTTP '.$response->status());
        }

        $body = (string) $response->body();

        if ($body === '' || strlen($body) > 8 * 1024 * 1024) {
            return $this->fail('malformed', 'The image was empty or larger than 8 MB.');
        }

        return ['ok' => true, 'body' => $body];
    }

    /* ------------------------------------------------------------------ plumbing */

    /**
     * One call, one answer, and NOTHING escapes.
     *
     * `$required` is the keys that make the response usable. A 200 carrying a body
     * that does not have them is a `malformed`, not a success — which is the
     * difference between "Instagram said no" and "we stored an empty token and
     * told the owner it worked".
     *
     * @param  callable(): \Illuminate\Http\Client\Response  $call
     * @param  list<string>  $required
     * @return array{ok: bool, data?: array<string, mixed>, error?: string, reason?: string}
     */
    private function request(callable $call, array $required): array
    {
        try {
            $response = $call();
        } catch (\Throwable $e) {
            // Connection refused, DNS, TLS, timeout. The one case that is
            // certainly not the owner's fault and certainly not fixable from this
            // screen, so it says so rather than blaming his configuration.
            return $this->fail('unreachable', $e->getMessage());
        }

        $body = null;

        try {
            $body = $response->json();
        } catch (\Throwable) {
            $body = null;
        }

        if (! $response->successful()) {
            /*
             * Meta reports everything from a bad code to an unapproved permission
             * in this shape, and the message is the single most useful sentence
             * the owner can be given — docs/IG-PROFILE.md §7 item 4 is there
             * because the commonest failure is a redirect-URI mismatch and Meta
             * says so in as many words.
             */
            $message = is_array($body) ? (string) ($body['error']['message'] ?? ($body['error_message'] ?? '')) : '';
            $type = is_array($body) ? (string) ($body['error']['type'] ?? '') : '';

            /*
             * A token this shop holds that Meta has stopped accepting. Told apart
             * from an ordinary refusal because the REMEDY is different and it is
             * the one an owner will hit: reconnect, rather than go and change a
             * setting in the app dashboard.
             */
            $reason = $response->status() === 401
                || str_contains(strtolower($message.' '.$type), 'access token')
                    ? 'expired'
                    : 'refused';

            return $this->fail($reason, $message !== '' ? $message : 'HTTP '.$response->status());
        }

        if (! is_array($body)) {
            return $this->fail('malformed', 'The response was not JSON.');
        }

        foreach ($required as $key) {
            if (! array_key_exists($key, $body)) {
                return $this->fail('malformed', 'The response carried no “'.$key.'”.');
            }
        }

        return ['ok' => true, 'data' => $body];
    }

    /**
     * @return array{ok: false, error: string, reason: string}
     */
    private function fail(string $reason, string $detail = ''): array
    {
        $detail = $this->scrub(trim($detail));

        return [
            'ok' => false,
            'reason' => $reason,
            'error' => self::REASONS[$reason] ?? self::REASONS['refused'],
            // Meta's own words, kept SEPARATE from our sentence so the screen can
            // print ours and offer his. Never concatenated into one string, because
            // then there is no way to show one without the other.
            'detail' => $detail,
        ];
    }

    /**
     * Take the secret and the token out of anything that is about to be shown.
     *
     * A BLUNT SUBSTRING REPLACE, on purpose. A pattern that tries to recognise a
     * token by its shape is a pattern that is wrong about the next token format,
     * and the value we are protecting is one we already hold — so the exact string
     * is the strongest possible matcher for it. The two `?? ''` guards are because
     * str_replace('', ...) replaces nothing, which is the right behaviour when
     * there is nothing stored.
     */
    private function scrub(string $text): string
    {
        if ($text === '') {
            return '';
        }

        foreach ([InstagramCredentials::secret(), InstagramCredentials::token()] as $value) {
            if (is_string($value) && $value !== '') {
                $text = str_replace($value, '[redacted]', $text);
            }
        }

        return $text;
    }
}
