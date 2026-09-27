<?php

declare(strict_types=1);

namespace App\Services\Instagram;

use App\Support\Url;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The OAuth handshake: the URL out, the state, and the check on the way back.
 *
 * Phase 21, Lane IG. This is App\Services\Payments\StripeConnect's shape, on
 * purpose and almost line for line — that class is this application's own worked
 * example of an admin-initiated OAuth round trip, its docblocks carry the reasons,
 * and its history records the two things an earlier version got wrong ("the state
 * had no expiry and carried no mode"). Following it is cheaper and safer than
 * having a second opinion about CSRF.
 *
 * ── THE REDIRECT URI IS FIXED, AND THAT IS A DECISION ───────────────────────
 *
 * `/admin-api/instagram/callback`. Meta requires the redirect URI to match what is
 * registered in the app dashboard EXACTLY, so anything about it that can change is
 * a thing that will one day break the connection with a message about a
 * `redirect_uri` mismatch — docs/IG-PROFILE.md §7 item 4 says this is the commonest
 * way the flow fails.
 *
 * Two things it deliberately is not:
 *
 *   - NOT under the secret admin path. `admin_path` is a setting the owner can
 *     change, and it is not on SettingController::PUBLIC_KEYS because this shop
 *     treats it as semi-secret. Building the redirect URI from it would put it in a
 *     third party's dashboard AND break the connection the day he changed it.
 *   - NOT taken from the request. A redirect URI that arrives in a query string is
 *     an open redirect with extra steps. It is built here, from this shop's own
 *     confirmed origin, and the callback rebuilds it the same way to send to Meta —
 *     so the two cannot disagree.
 *
 * It sits inside the `admin-api` group, which already carries `web`, `auth:admin`
 * and NoStoreAdminApi — the same group StripeConnect's callback is in. Instagram
 * redirects the owner's own browser there as a top-level navigation, so the admin
 * session cookie is sent (SameSite=Lax permits exactly that), and an unauthenticated
 * hit is a login redirect rather than a reachable endpoint.
 */
final class InstagramAuth
{
    /** The admin session key holding the single-use OAuth state. */
    public const STATE_SESSION_KEY = 'kbb.instagram.oauth.state';

    /**
     * How long a minted state stays usable, in seconds.
     *
     * Fifteen minutes: long enough for an owner to read Instagram's permission
     * screen, log in on his phone and press Allow, and short enough that a state
     * abandoned in a session cannot be paired with a code days afterwards.
     *
     * It is NOT the primary defence — hash_equals against 40 unguessable characters
     * is — and StripeConnect's own comment says the same about its TTL.
     */
    public const STATE_TTL_SECONDS = 900;

    /** The path Meta must have registered, with no origin on it. */
    public const CALLBACK_PATH = '/admin-api/instagram/callback';

    /**
     * The exact string the owner pastes into Meta's OAuth redirect URIs box.
     *
     * Printed on the screen with a Copy button beside it, because "type this
     * carefully" is how the commonest failure in this flow happens.
     */
    public static function redirectUri(): string
    {
        return Url::externalise(self::CALLBACK_PATH);
    }

    /**
     * Where to send the owner, and the state to remember — or a refusal.
     *
     * @return array{ok: bool, url?: string, state?: string, error?: string, reason?: string}
     */
    public static function authorizeUrl(): array
    {
        $appId = InstagramCredentials::appId();

        if ($appId === null || InstagramCredentials::secret() === null) {
            return [
                'ok' => false,
                'reason' => 'no_app',
                'error' => 'Save your Instagram app id and app secret first — step 2 above.',
            ];
        }

        $state = Str::random(40);

        return [
            'ok' => true,
            'state' => $state,
            'url' => InstagramClient::AUTHORIZE_URL.'?'.http_build_query([
                'client_id' => $appId,
                'redirect_uri' => self::redirectUri(),
                'response_type' => 'code',
                'scope' => InstagramClient::SCOPE,
                'state' => $state,
            ]),
        ];
    }

    /**
     * Remember a minted state against this admin's session.
     *
     * One key holding an array rather than two keys. StripeConnect's comment: "Two
     * session keys would be two things that can fall out of step, and the one that
     * mattered would be the one missing."
     */
    public static function remember(Request $request, string $state): void
    {
        $request->session()->put(self::STATE_SESSION_KEY, [
            'value' => $state,
            'issued_at' => time(),
        ]);
    }

    /**
     * Spend the stored state and say whether the one that came back matches.
     *
     * ── PULLED FIRST, BEFORE ANYTHING ELSE IS READ ──────────────────────────
     *
     * `pull()` removes it, so the state is spent by being LOOKED AT. Every refusal
     * below has already consumed it, which is what stops a replayed callback
     * getting a second code exchange out of the same state. The caller must call
     * this before it looks at `code`, and InstagramController does.
     *
     * ── WHY THE EMPTINESS IS CHECKED SEPARATELY FROM hash_equals ────────────
     *
     * Because `hash_equals('', '')` is TRUE. A callback carrying no state at all,
     * arriving in a session that has none stored, would otherwise pass this line —
     * and that is the whole attack: an unauthorised callback has no state to send,
     * and a session it did not start has none stored. StripeConnect's callback
     * carries the identical comment because it is the identical trap.
     *
     * @return array{ok: bool, error?: string}
     */
    public static function consume(Request $request, string $given): array
    {
        $stored = $request->session()->pull(self::STATE_SESSION_KEY);

        $expected = is_array($stored) ? (string) ($stored['value'] ?? '') : '';
        $issuedAt = is_array($stored) ? (int) ($stored['issued_at'] ?? 0) : 0;

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return [
                'ok' => false,
                'error' => 'This Instagram window could not be matched to the request that opened it, so nothing '
                    .'was changed. Close it and press Configure now again.',
            ];
        }

        if ($issuedAt > 0 && (time() - $issuedAt) > self::STATE_TTL_SECONDS) {
            return [
                'ok' => false,
                'error' => 'This Instagram window was open too long and the request has expired, so nothing was '
                    .'changed. Press Configure now again — it takes a few seconds the second time.',
            ];
        }

        return ['ok' => true];
    }
}
