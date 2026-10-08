<?php

declare(strict_types=1);

namespace App\Services\Instagram;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * "Connect with Facebook", from the code Meta hands back to a grid (Lane IG2).
 *
 * code → short-lived user token → long-lived user token → /me/accounts → the
 * Page whose `instagram_business_account` is set → that Page's token → the IG
 * user id → store → fetch. Every Graph call is FacebookGraphClient's; this class
 * only decides what happens between them.
 *
 * THREE ENDINGS, and the owner sees exactly one of them:
 *
 *   connected  exactly one granted Page has an Instagram account — stored and
 *              fetched with nothing more to press.
 *   pick       several do — the screen draws a picker; pick() finishes.
 *   unlinked   none do — the screen says the Instagram account is not linked to
 *              a Facebook Page, where to fix that in the Instagram app, and offers
 *              "Check again", which is checkAgain().
 *
 * ── THE USER TOKEN BETWEEN THE CALLBACK AND THE PICK ────────────────────────
 *
 * The picker and "Check again" need to ask /me/accounts again, which needs the
 * long-lived USER token. It is held in the admin SESSION — encrypted with the
 * application key, stamped, and dropped after PENDING_TTL or the moment a Page
 * is chosen. It is never written to the settings table and never sent to the
 * browser: the screen is handed Page and account NAMES only (pending()).
 *
 * A Page id from the browser is never trusted on its own: pick() asks Meta for
 * the Page list again and accepts only an id Meta itself returned, with the
 * Page token Meta returned beside it.
 */
final class FacebookConnect
{
    public const PENDING_KEY = 'kbb.instagram.fb.pending';

    /** Thirty minutes to pick a Page or link one and press Check again. */
    public const PENDING_TTL = 1800;

    public function __construct(
        private FacebookGraphClient $graph,
        private InstagramSync $sync,
    ) {}

    /**
     * The callback leg: the code is already matched to a single-use state.
     *
     * @return array{ok: bool, state?: string, message?: string, error?: string, detail?: string}
     */
    public function callback(Request $request, string $code): array
    {
        $short = $this->graph->exchangeCode($code, InstagramAuth::redirectUri());

        if (! ($short['ok'] ?? false)) {
            return $this->refusal($short, 'The Facebook login could not be completed.');
        }

        $long = $this->graph->exchangeForLongLived((string) ($short['data']['access_token'] ?? ''));

        if (! ($long['ok'] ?? false)) {
            return $this->refusal($long, 'Facebook did not give this shop a lasting connection.');
        }

        $userToken = trim((string) ($long['data']['access_token'] ?? ''));

        if ($userToken === '') {
            return ['ok' => false, 'error' => FacebookGraphClient::REASONS['malformed'], 'detail' => 'The long-lived token came back empty.'];
        }

        return $this->decide($request, $userToken);
    }

    /** "Check again": the same decision, from the user token still in the session. */
    public function checkAgain(Request $request): array
    {
        $token = $this->pendingToken($request);

        if ($token === null) {
            return ['ok' => false, 'error' => 'The Facebook login has timed out. Press Connect with Facebook again '
                .'— it takes a few seconds the second time.'];
        }

        return $this->decide($request, $token);
    }

    /** The picker's choice. */
    public function pick(Request $request, string $pageId): array
    {
        $token = $this->pendingToken($request);

        if ($token === null) {
            return ['ok' => false, 'error' => 'The Facebook login has timed out. Press Connect with Facebook again.'];
        }

        $pages = $this->graph->pages($token);

        if (! ($pages['ok'] ?? false)) {
            return $this->refusal($pages, 'Facebook did not return your Pages.');
        }

        foreach ($pages['pages'] as $page) {
            if (hash_equals($page['page_id'], $pageId) && $page['ig_id'] !== '' && $page['page_token'] !== '') {
                return $this->finish($request, $page);
            }
        }

        return ['ok' => false, 'error' => 'That Page is not one Facebook returned for this login, or it no longer '
            .'has an Instagram account linked. Press Check again.'];
    }

    /**
     * What the screen may know about a login in progress: names, never tokens.
     *
     * @return array{state: string, candidates: list<array{page_id: string, page_name: string, ig_username: string}>, pages: list<string>}|null
     */
    public static function pending(Request $request): ?array
    {
        $held = self::held($request);

        if ($held === null) {
            return null;
        }

        return [
            'state' => $held['candidates'] === [] ? 'unlinked' : 'pick',
            'candidates' => $held['candidates'],
            'pages' => $held['pages'],
        ];
    }

    public static function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::PENDING_KEY);
        }
    }

    /* ------------------------------------------------------------------ the steps */

    private function decide(Request $request, string $userToken): array
    {
        $answer = $this->graph->pages($userToken);

        if (! ($answer['ok'] ?? false)) {
            return $this->refusal($answer, 'Facebook did not return your Pages.');
        }

        $linked = array_values(array_filter($answer['pages'], fn (array $p) => $p['ig_id'] !== '' && $p['page_token'] !== ''));

        if (count($linked) === 1) {
            return $this->finish($request, $linked[0]);
        }

        $request->session()->put(self::PENDING_KEY, [
            'token' => Crypt::encryptString($userToken),
            'issued_at' => time(),
            'candidates' => array_map(fn (array $p) => [
                'page_id' => $p['page_id'],
                'page_name' => $p['page_name'],
                'ig_username' => $p['ig_username'],
            ], $linked),
            'pages' => array_values(array_slice(array_map(fn (array $p) => $p['page_name'], $answer['pages']), 0, 20)),
        ]);

        if ($linked === []) {
            return [
                'ok' => true,
                'state' => 'unlinked',
                'message' => 'Logged in to Facebook, but no Instagram account is linked to '
                    .($answer['pages'] === [] ? 'any Page you granted' : 'the Pages you granted').'.',
            ];
        }

        return [
            'ok' => true,
            'state' => 'pick',
            'message' => count($linked).' Pages have an Instagram account linked. Choose the one this shop should show.',
        ];
    }

    /** @param array{page_id: string, page_name: string, page_token: string, ig_id: string, ig_username: string} $page */
    private function finish(Request $request, array $page): array
    {
        $expiresAt = null;
        $check = $this->graph->debugToken($page['page_token']);

        if ($check['ok'] ?? false) {
            // A token minted for a DIFFERENT app is refused outright — the one
            // check that tells "this is our login" from "a code that came from
            // somewhere else".
            if ($check['app_id'] !== '' && $check['app_id'] !== (string) InstagramCredentials::fbAppId()) {
                self::forget($request);

                return ['ok' => false, 'error' => 'Facebook says this login belongs to a different app, so nothing '
                    .'was stored. Check the App ID saved here matches App settings → Basic.'];
            }

            $expiresAt = $check['expires_at'] > 0 ? $check['expires_at']
                : ($check['data_access_expires_at'] > 0 ? $check['data_access_expires_at'] : null);
        }

        InstagramCredentials::saveFacebookConnection(
            $page['page_token'], $page['ig_id'], $page['page_id'], $page['page_name'], $expiresAt,
        );

        self::forget($request);

        if (! InstagramCredentials::hasToken()) {
            return ['ok' => false, 'error' => FacebookGraphClient::REASONS['malformed'], 'detail' => 'The Page token came back empty.'];
        }

        $who = $page['ig_username'] !== '' ? '@'.$page['ig_username'] : 'the Instagram account';
        $fetch = $this->sync->run();

        if (! ($fetch['ok'] ?? false)) {
            return [
                'ok' => true,
                'state' => 'connected',
                'message' => 'Connected to '.$who.' through the Facebook Page “'.$page['page_name'].'”. The first '
                    .'fetch did not finish, though: '.lcfirst((string) ($fetch['error'] ?? 'Facebook could not be reached.'))
                    .' Press Refresh posts to try again; you do not have to reconnect.',
            ];
        }

        return [
            'ok' => true,
            'state' => 'connected',
            'message' => 'Connected to '.$who.' through Facebook. '.(int) ($fetch['stored'] ?? 0).' posts fetched, '
                .(int) ($fetch['pictures'] ?? 0).' pictures stored on this shop.',
        ];
    }

    /* ------------------------------------------------------------------ plumbing */

    /** @return array{token: string, candidates: list<array<string, string>>, pages: list<string>}|null */
    private static function held(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $held = $request->session()->get(self::PENDING_KEY);

        if (! is_array($held) || (time() - (int) ($held['issued_at'] ?? 0)) > self::PENDING_TTL) {
            $request->session()->forget(self::PENDING_KEY);

            return null;
        }

        return [
            'token' => (string) ($held['token'] ?? ''),
            'candidates' => array_values((array) ($held['candidates'] ?? [])),
            'pages' => array_values(array_map('strval', (array) ($held['pages'] ?? []))),
        ];
    }

    private function pendingToken(Request $request): ?string
    {
        $held = self::held($request);

        if ($held === null || $held['token'] === '') {
            return null;
        }

        try {
            $token = Crypt::decryptString($held['token']);
        } catch (\Throwable) {
            return null;
        }

        return $token === '' ? null : $token;
    }

    /** @param array<string, mixed> $answer */
    private function refusal(array $answer, string $lead): array
    {
        $reason = (string) ($answer['reason'] ?? 'refused');

        $next = match ($reason) {
            'no_app' => ' Save the Facebook App ID and App secret (App settings → Basic) first.',
            'permission' => ' In your Meta app open Facebook Login for Business → Configurations, edit the '
                .'configuration and make sure instagram_basic, pages_show_list and pages_read_engagement are '
                .'ticked — then press Connect with Facebook again and allow everything it asks for.',
            'expired' => ' Press Connect with Facebook again.',
            'unreachable' => ' This server could not reach graph.facebook.com — try again in a minute.',
            default => ' If Facebook mentions the redirect URI, copy it from this screen into Facebook Login for '
                .'Business → Settings → Valid OAuth Redirect URIs exactly as printed.',
        };

        return [
            'ok' => false,
            'error' => $lead.' '.(string) ($answer['error'] ?? '').$next,
            'detail' => (string) ($answer['detail'] ?? ''),
        ];
    }
}
