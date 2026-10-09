<?php

declare(strict_types=1);

namespace App\Services\Pixels;

use App\Services\Analytics;
use App\Support\Url;
use Illuminate\Http\Request;

/**
 * The optional "Connect with Facebook" button: log in with the owner's OWN
 * Meta app and pick the pixel from a list, instead of copying its number.
 * (Lane MP)
 *
 * ── WHY NOT META'S ONE-CLICK (FBE) ─────────────────────────────────────────
 *
 * Meta's WordPress and WooCommerce plugins open Meta Business Extension in a
 * popup on business.facebook.com under META'S app (221646389321681 in plugin
 * 5.2.2), and the popup hands the pixel id and a token back by postMessage.
 * That flow needs the private `manage_business_extension` permission, which
 * Meta grants to an app only through a partner allow-list and App Review. This
 * shop has no such app, and borrowing Meta's or WooCommerce's app id would be
 * impersonating their software. docs/MARKETING-PIXELS-RESEARCH.md has sources.
 *
 * ── WHAT AN OWNER'S OWN APP CAN DO ─────────────────────────────────────────
 *
 * An app that only touches its OWNER'S ad accounts needs no App Review: the
 * default (now "Limited") access to ads_read and business_management covers
 * accounts where the person logging in has a role on the app. So: the owner's
 * app — its id and secret saved on the Meta tab (meta_app_id, and
 * meta_app_secret encrypted) — logs him in,
 * this class asks Graph for the pixels on his ad accounts, and he picks one.
 * The ID is written; NO token is kept: the user token from this login expires
 * in hours or weeks, and the Conversions API uses the never-expiring token
 * Events Manager generates, which stays a paste.
 *
 * The state is single-use, session-bound and 15 minutes long, checked before
 * the code is looked at (the same reasoning as every OAuth callback in this shop). A pixel id
 * from the browser is accepted only if it is one Meta returned to this
 * session.
 */
final class MetaConnect
{
    public const CALLBACK_PATH = '/admin-api/marketing-pixels/meta/callback';

    private const STATE_KEY = 'kbb.pixels.meta.state';

    private const PIXELS_KEY = 'kbb.pixels.meta.pixels';

    /** The callback's one-line outcome, read once by the console's next GET (never carried in the address). */
    private const MESSAGE_KEY = 'kbb.pixels.meta.message';

    private const TTL = 900;

    public const SCOPES = 'ads_read,business_management';

    public function __construct(private PixelConfig $config, private PlatformClient $client, private Analytics $analytics) {}

    /*
     * Marketing Pixels' OWN app settings. This used to fall back to the app the
     * owner set up for the Instagram module; that module is retired (Lane IGR)
     * and its credentials deleted, so the id and secret were copied here once
     * by 2027_10_15_130200_copy_meta_app_to_marketing_pixels and this class
     * reads nothing else.
     */
    public function appId(): ?string
    {
        $own = $this->config->get('meta_app_id');

        return preg_match(PixelConfig::SHAPES['meta_app_id'][0], $own) === 1 ? $own : null;
    }

    private function appSecret(): ?string
    {
        return $this->config->secret('meta_app_secret');
    }

    /** 'own' when both are saved, else 'none' — for the screen. */
    public function source(): string
    {
        return $this->appId() !== null && $this->appSecret() !== null ? 'own' : 'none';
    }

    public static function redirectUri(): string
    {
        return Url::externalise(self::CALLBACK_PATH);
    }

    /** @return array{ok: bool, url?: string, error?: string} */
    public function start(Request $request): array
    {
        $appId = $this->appId();

        if ($appId === null || $this->appSecret() === null) {
            return ['ok' => false, 'error' => 'Add your Meta App ID and App Secret first (step 1 of “Connect with Facebook”).'];
        }

        $state = bin2hex(random_bytes(20));
        $request->session()->put(self::STATE_KEY, ['value' => $state, 'at' => time()]);

        return ['ok' => true, 'url' => 'https://www.facebook.com/' . PlatformClient::GRAPH_VERSION . '/dialog/oauth?' . http_build_query([
            'client_id' => $appId,
            'redirect_uri' => self::redirectUri(),
            'state' => $state,
            'response_type' => 'code',
            'scope' => self::SCOPES,
        ])];
    }

    /** @return array{ok: bool, pixels?: list<array{id: string, name: string}>, error?: string} */
    public function callback(Request $request, string $state, string $code, string $error = ''): array
    {
        $stored = $request->session()->pull(self::STATE_KEY);
        $expected = is_array($stored) ? (string) ($stored['value'] ?? '') : '';

        if ($expected === '' || $state === '' || ! hash_equals($expected, $state)) {
            return ['ok' => false, 'error' => 'This Facebook window could not be matched to the request that opened it. Nothing was changed — press Connect with Facebook again.'];
        }

        if (time() - (int) ($stored['at'] ?? 0) > self::TTL) {
            return ['ok' => false, 'error' => 'The Facebook window was open too long. Press Connect with Facebook again.'];
        }

        /*
         * Facebook's own refusal is believed only AFTER the state has matched,
         * the order StripeConnectController::callback() keeps (integrator,
         * 2.60.449). Read first, any link to this GET could put a sentence of
         * its choosing in the owner's console under "Facebook:".
         */
        if ($error !== '') {
            return ['ok' => false, 'error' => 'Facebook: ' . $error];
        }

        if ($code === '' || strlen($code) > 2048) {
            return ['ok' => false, 'error' => 'Facebook did not send a login code back.'];
        }

        $secret = (string) $this->appSecret();
        $token = $this->client->metaGet('oauth/access_token', [
            'client_id' => $this->appId(), 'client_secret' => $secret, 'redirect_uri' => self::redirectUri(), 'code' => $code,
        ], [$secret, $code]);

        $userToken = (string) ($token['data']['access_token'] ?? '');

        if (! $token['ok'] || $userToken === '') {
            return ['ok' => false, 'error' => 'Facebook refused the login: ' . ($token['message'] ?: 'no token came back.')];
        }

        $proof = hash_hmac('sha256', $userToken, $secret);
        $accounts = $this->client->metaGet('me/adaccounts', [
            'fields' => 'name,adspixels.limit(50){id,name}', 'limit' => 50, 'access_token' => $userToken, 'appsecret_proof' => $proof,
        ], [$secret, $userToken]);

        if (! $accounts['ok']) {
            return ['ok' => false, 'error' => 'Logged in, but Meta would not list your ad accounts: ' . $accounts['message']];
        }

        $pixels = [];

        foreach ((array) ($accounts['data']['data'] ?? []) as $account) {
            foreach ((array) ($account['adspixels']['data'] ?? []) as $px) {
                $id = (string) ($px['id'] ?? '');
                if (preg_match('/^\d{8,20}$/', $id) === 1 && ! isset($pixels[$id])) {
                    $pixels[$id] = ['id' => $id, 'name' => mb_substr((string) ($px['name'] ?? $id), 0, 80) . ' — ' . mb_substr((string) ($account['name'] ?? ''), 0, 60)];
                }
            }
        }

        $pixels = array_values($pixels);
        $request->session()->put(self::PIXELS_KEY, ['list' => $pixels, 'at' => time()]);

        if (count($pixels) === 1) {
            $this->analytics->setId('meta', $pixels[0]['id']);
        }

        return ['ok' => true, 'pixels' => $pixels];
    }

    /** Hold the callback's outcome for the console to read once. */
    public function tell(Request $request, string $message): void
    {
        $request->session()->put(self::MESSAGE_KEY, mb_substr($message, 0, 200));
    }

    /** The held outcome, spent by being read. */
    public function told(Request $request): ?string
    {
        $message = $request->session()->pull(self::MESSAGE_KEY);

        return is_string($message) && $message !== '' ? $message : null;
    }

    /** @return list<array{id: string, name: string}> */
    public function pending(Request $request): array
    {
        $held = $request->session()->get(self::PIXELS_KEY);

        return is_array($held) && time() - (int) ($held['at'] ?? 0) <= self::TTL ? (array) ($held['list'] ?? []) : [];
    }

    public function pick(Request $request, string $pixelId): bool
    {
        foreach ($this->pending($request) as $px) {
            if (hash_equals($px['id'], $pixelId)) {
                $this->analytics->setId('meta', $px['id']);
                $request->session()->forget(self::PIXELS_KEY);

                return true;
            }
        }

        return false;
    }
}
