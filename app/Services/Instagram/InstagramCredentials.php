<?php

declare(strict_types=1);

namespace App\Services\Instagram;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Where the Instagram app secret and the long-lived access token live.
 *
 * Phase 21, Lane IG. docs/IG-PROFILE.md §6 is the security section; this is it in
 * code.
 *
 * ── IN THE DATABASE, ENCRYPTED, NOT IN .env ─────────────────────────────────
 *
 * App\Services\Translation\TranslationCredentials is the precedent and its reason
 * carries over exactly: `.env` cannot be written by an update package
 * (UpdateGuard::FORBIDDEN_PREFIXES), so a credential that had to go in `.env` is a
 * credential the owner cannot save from the admin — and the whole of this feature
 * is a button he presses in the admin.
 *
 * Encrypted with the application key, because a settings row is in every database
 * dump and every nightly backup, and these two are worth something: the app secret
 * plus a 60-day token is read access to our Instagram account for two months.
 *
 * ▲ docs/UGC-ENGAGEMENT.md argued the other way — "a credential belongs in `.env`,
 * not in `settings`" — and the difference is worth naming rather than quietly
 * departing from. That round was talking about a value an operator types once and
 * a shell can edit. This one is a token this application MINTS and REFRESHES for
 * itself: a `.env` the app has to rewrite every sixty days is a `.env` an update
 * package will eventually clobber, and `.env` is not read at all while the config
 * cache exists. A token that can only be renewed by SSH is a token that lapses.
 *
 * ── AND NEITHER IS READABLE THROUGH ANYTHING THAT SERVES A PAYLOAD ──────────
 *
 * App\Services\SecretStore's docblock makes the argument this class obeys: the
 * answer to "will somebody remember not to print it" is not to remember, it is to
 * hand the printer something it cannot read. So the three questions a SCREEN may
 * ask are booleans and a date — hasAppId(), hasSecret(), hasToken(), expiresAt() —
 * and the two methods that return a plaintext value, secret() and token(), are
 * called from exactly two places in this application: InstagramClient, which puts
 * them in an outbound request body, and nothing else. Neither is reachable from a
 * controller's response.
 *
 * `SecretStore` itself is not implemented, and that is deliberate rather than an
 * omission: that interface is ModuleSchema's seam, has no getter at all by design,
 * and this feature needs one. Implementing it and then adding a getter beside it
 * would be the narrowing removed while the name that promises it stayed.
 *
 * ── NOT ON SettingController::PUBLIC_KEYS, AND ASSERTED BY NAME ─────────────
 *
 * CLAUDE.md: `/api/*` is unauthenticated and the settings endpoint has leaked three
 * times. That allowlist is explicit, so a new key is private by default — but "by
 * default" is how the last three got out, so InstagramSecurityTest asserts by name
 * that neither key is on it and that the public settings endpoint does not return
 * them. The shape BilingualFoundationTest already uses for the translation key.
 */
final class InstagramCredentials
{
    /** The Instagram app id. Not a secret — it travels in an authorise URL. */
    public const APP_ID_KEY = 'instagram_app_id';

    /** The Instagram app secret. Encrypted. */
    public const SECRET_KEY = 'instagram_app_secret';

    /** The long-lived user access token. Encrypted. */
    public const TOKEN_KEY = 'instagram_token';

    /** When that token stops working, as a unix timestamp. Not a secret. */
    public const EXPIRES_KEY = 'instagram_token_expires';

    /** The Instagram account id the token belongs to. Not a secret. */
    public const USER_KEY = 'instagram_user_id';

    /**
     * How close to expiry a token is refreshed on the next admin call.
     *
     * Seven days. Meta's long-lived token lasts sixty and can only be refreshed
     * while it is STILL VALID, and this host has no cron (docs/UGC-ENGAGEMENT.md
     * established that for retention; docs/IG-PROFILE.md §5 carries it forward),
     * so the refresh has to ride on something a person does. Seven days means an
     * owner who opens the admin once a week never sees it happen and one who opens
     * it once a month still lands inside the window with fifty days of slack.
     */
    public const REFRESH_WINDOW_DAYS = 7;

    /** Every key this class owns, for the test that proves none of them is public. */
    public const ALL_KEYS = [
        self::APP_ID_KEY, self::SECRET_KEY, self::TOKEN_KEY,
        self::EXPIRES_KEY, self::USER_KEY,
    ];

    /* ------------------------------------------------------ what a screen may ask */

    public static function hasAppId(): bool
    {
        return self::plain(self::APP_ID_KEY, false) !== null;
    }

    public static function hasSecret(): bool
    {
        return self::plain(self::SECRET_KEY, true) !== null;
    }

    public static function hasToken(): bool
    {
        return self::plain(self::TOKEN_KEY, true) !== null;
    }

    /** When the stored token expires, or null if there is none. */
    public static function expiresAt(): ?int
    {
        $raw = self::plain(self::EXPIRES_KEY, false);

        return $raw === null ? null : (int) $raw;
    }

    /** Days until the token expires — negative once it has. Null with no token. */
    public static function daysLeft(): ?int
    {
        $at = self::expiresAt();

        if ($at === null || ! self::hasToken()) {
            return null;
        }

        return (int) floor(($at - time()) / 86400);
    }

    /** True when the token is within REFRESH_WINDOW_DAYS of expiry, or past it. */
    public static function needsRefresh(): bool
    {
        $days = self::daysLeft();

        return $days !== null && $days <= self::REFRESH_WINDOW_DAYS;
    }

    /** True when the token has expired outright and only a reconnect will do. */
    public static function expired(): bool
    {
        $days = self::daysLeft();

        return $days !== null && $days < 0;
    }

    /** The Instagram account id, or ''. Not a secret; the screen prints it. */
    public static function userId(): string
    {
        return (string) (self::plain(self::USER_KEY, false) ?? '');
    }

    /* ------------------------------------------- what only InstagramClient may ask */

    /** The app id, or null. */
    public static function appId(): ?string
    {
        return self::plain(self::APP_ID_KEY, false);
    }

    /**
     * The app secret in plaintext, or null.
     *
     * ONE CALLER: InstagramClient, which puts it in a POST body to Meta. If a
     * second caller ever appears, the question to ask is what it is going to do
     * with it — every answer but "send it to Meta" is a leak.
     */
    public static function secret(): ?string
    {
        return self::plain(self::SECRET_KEY, true);
    }

    /** The long-lived token in plaintext, or null. Same one caller. */
    public static function token(): ?string
    {
        return self::plain(self::TOKEN_KEY, true);
    }

    /* ---------------------------------------------------------------------- writes */

    /**
     * Save the app id and secret. An empty secret LEAVES THE STORED ONE ALONE.
     *
     * That is the SecretStore contract's own third state, and the reason is the
     * screen: the secret box is drawn empty with a placeholder saying whether one
     * is stored, because a row of asterisks discloses the length. An empty box
     * therefore already means "unchanged", so it cannot also mean "delete" —
     * forget() is the explicit way to remove one.
     */
    public static function saveApp(string $appId, ?string $secret): void
    {
        self::put(self::APP_ID_KEY, trim($appId), false);

        $secret = $secret === null ? '' : trim($secret);

        if ($secret !== '') {
            self::put(self::SECRET_KEY, $secret, true);
        }
    }

    /**
     * Store a freshly minted long-lived token.
     *
     * `$expiresIn` is Meta's own `expires_in`, in seconds. Clamped to something
     * sane rather than trusted: a remote server that answers `expires_in: 0`
     * would otherwise mark a perfectly good token as expired the instant it
     * arrived, and one that answers a year would let it lapse unrefreshed.
     * Sixty days is what Meta documents; the clamp keeps us inside a week either
     * side of the truth whatever arrives.
     */
    public static function saveToken(string $token, int $expiresIn, ?string $userId = null): void
    {
        $token = trim($token);

        if ($token === '') {
            return;
        }

        $expiresIn = max(3600, min($expiresIn, 90 * 86400));

        self::put(self::TOKEN_KEY, $token, true);
        self::put(self::EXPIRES_KEY, (string) (time() + $expiresIn), false);

        if ($userId !== null && trim($userId) !== '') {
            self::put(self::USER_KEY, trim($userId), false);
        }
    }

    /**
     * Forget the token but keep the app id and secret — "disconnect".
     *
     * The two halves are separate on purpose. Disconnecting is something an owner
     * does to stop this shop reading his account; retyping an app id and secret
     * out of the Meta dashboard afterwards is a chore, and making him do it is how
     * a disconnect button becomes a button nobody presses.
     */
    public static function forgetToken(): void
    {
        self::put(self::TOKEN_KEY, '', true);
        self::put(self::EXPIRES_KEY, '', false);
        self::put(self::USER_KEY, '', false);
    }

    /** Forget everything, app registration included. */
    public static function forgetAll(): void
    {
        self::forgetToken();
        self::put(self::APP_ID_KEY, '', false);
        self::put(self::SECRET_KEY, '', true);
    }

    /* --------------------------------------------------------------------- plumbing */

    private static function put(string $key, string $plain, bool $encrypted): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $plain === '' ? '' : ($encrypted ? Crypt::encryptString($plain) : $plain),
                /*
                 * NOT AUTOLOADED. `autoload` is what puts a row in the snapshot
                 * SettingsService::all() builds and hands to anything that asks
                 * for the settings map — which is a lot of places, including some
                 * that log what they were given. A credential belongs in exactly
                 * one query made by exactly one reader.
                 *
                 * ▲ AND IT DOES NOT KEEP THE ROW OUT OF `Setting::map()`, which
                 * is worth the line because this flag reads as though it would.
                 * That method is `Setting::query()->get(['key','value'])` with NO
                 * autoload filter — every row, by design, because its callers want
                 * one key by name. So the CIPHERTEXT is in that snapshot whatever
                 * this flag says, and the encryption above is therefore the actual
                 * protection rather than a second belt over the autoload one.
                 * Established by running it: InstagramProfileTest's `it stores the
                 * secret and the token encrypted` asserted against Setting::map()
                 * first and was red, and it now asserts against both maps
                 * separately with this distinction written into it.
                 */
                'autoload' => false,
            ],
        );

        Setting::flushMap();
    }

    /**
     * One row, decrypted if it is meant to be.
     *
     * A FAILED DECRYPT RETURNS NULL RATHER THAN THROWING, and the reason is the
     * one TranslationCredentials gives: an APP_KEY rotation makes every ciphertext
     * in the database unreadable, and degrading to "nothing configured" greys the
     * Instagram screen's buttons and leaves the shop showing the posts it already
     * has. Throwing would take out any screen that asks whether Instagram is
     * connected — including the one the owner has to use to fix it.
     */
    private static function plain(string $key, bool $encrypted): ?string
    {
        $stored = Setting::query()->find($key)?->value;

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        if (! $encrypted) {
            return $stored;
        }

        try {
            $plain = Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null;
        }

        return $plain === '' ? null : $plain;
    }
}
