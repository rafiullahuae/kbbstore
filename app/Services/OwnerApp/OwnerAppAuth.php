<?php

declare(strict_types=1);

namespace App\Services\OwnerApp;

use App\Models\AdminUser;
use App\Models\OwnerAppDevice;
use App\Models\OwnerAppMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Who is holding the phone. The owner app's own guard, apart from the admin's
 * session and the shopper's: it shares no cookie with either.
 *
 * ── TWO TOKENS, BOTH HttpOnly, NEITHER STORED ──────────────────────────────
 *
 *   kbb_oa_d   the DEVICE. 256 random bits, set once when a member enrols a
 *              phone with their email and PIN, kept for a year. This is what
 *              "saved in the browser" means: the phone is remembered, and the
 *              PIN alone unlocks it from then on. The PIN itself is never
 *              stored anywhere in the browser.
 *   kbb_oa_s   the SESSION. 256 random bits, issued on each unlock, dead after
 *              owner_app_idle_hours without use.
 *
 * Both are HttpOnly, Secure, SameSite=Strict and scoped to the app's own path,
 * so no script can read them, no other site can send them, and no page of the
 * shop or the admin ever receives them. The server keeps only SHA-256 hashes
 * (owner_app_devices.token_hash / session_hash): a copy of the database does
 * not unlock a single phone.
 *
 * ── GUESSING THE PIN ───────────────────────────────────────────────────────
 *
 *   per member   5 wrong PINs in a row lock the member for 15 minutes, on
 *                every device.
 *   per device   10 wrong PINs in a row (two lockouts) REVOKE the device: it
 *                must be enrolled again with the email as well.
 *   per address  30 wrong attempts from one IP in 15 minutes -> 429, and 10
 *                failed enrolments -> 429.
 *
 * A 4-digit PIN is 10,000 guesses; at five per fifteen minutes that is over
 * three weeks of continuous guessing on one device, which is revoked after ten.
 * Every attempt, right or wrong, is a row in owner_app_logins.
 *
 * ── CSRF ───────────────────────────────────────────────────────────────────
 *
 * Every write carries X-OA-CSRF, an HMAC of the session token under the app
 * key. The page is given it in a JSON body that only a same-origin script can
 * read; a forged cross-site request has neither the header nor, under
 * SameSite=Strict, the cookie.
 */
final class OwnerAppAuth
{
    public const DEVICE_COOKIE = 'kbb_oa_d';

    public const SESSION_COOKIE = 'kbb_oa_s';

    public const MEMBER_LOCK_AFTER = 5;

    public const LOCK_MINUTES = 15;

    public const DEVICE_REVOKE_AFTER = 10;

    public const IP_MAX_FAILS = 30;

    public const IP_MAX_ENROL_FAILS = 10;

    public const MAX_DEVICES = 10;

    public const PIN_RULE = '/^\d{4,8}$/D';

    /** A hash of nothing anybody can type, so a miss costs what a hit costs. Made once per process. */
    private static ?string $dummy = null;

    /* ------------------------------------------------------------- reading */

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function token(): string
    {
        return WebPush::b64u(random_bytes(32));
    }

    public static function csrfFor(string $sessionToken): string
    {
        return hash_hmac('sha256', 'owner-app-csrf|'.$sessionToken, (string) config('app.key'));
    }

    /** The enrolled device this request comes from, revoked or not, or null. */
    public static function device(Request $request): ?OwnerAppDevice
    {
        $token = (string) $request->cookies->get(self::DEVICE_COOKIE, '');

        if (strlen($token) < 40 || strlen($token) > 64) {
            return null;
        }

        return OwnerAppDevice::query()->with('member.admin')->where('token_hash', self::hash($token))->first();
    }

    /** Is this device's session cookie the live one, and used recently enough? */
    public static function sessionValid(Request $request, OwnerAppDevice $device): bool
    {
        $token = (string) $request->cookies->get(self::SESSION_COOKIE, '');

        if ($token === '' || $device->session_hash === null || $device->session_seen_at === null) {
            return false;
        }

        if (! hash_equals((string) $device->session_hash, self::hash($token))) {
            return false;
        }

        return $device->session_seen_at->gt(now()->subHours(OwnerAppSettings::idleHours()));
    }

    /** Why this device may not be used at all, or null when it may. */
    public static function barred(?OwnerAppDevice $device): ?string
    {
        if ($device === null || $device->revoked_at !== null) {
            return 'no_device';
        }

        $member = $device->member;

        if ($member === null || ! $member->enabled || $member->pin_hash === null || $member->admin === null) {
            return 'disabled';
        }

        return null;
    }

    /* ---------------------------------------------------------- unlocking */

    /**
     * @return array{status:int, body:array<string,mixed>, session?:string}
     */
    public static function unlock(Request $request, OwnerAppDevice $device, string $pin): array
    {
        $ipKey = 'owner-app-pin:'.$request->ip();

        if (RateLimiter::tooManyAttempts($ipKey, self::IP_MAX_FAILS)) {
            self::log($request, 'unlock', false, 'ip_limited', $device->member_id, $device->id);

            return ['status' => 429, 'body' => ['ok' => false, 'code' => 'ip_limited', 'message' => 'Too many attempts from this connection. Try again in a few minutes.']];
        }

        if (($why = self::barred($device)) !== null) {
            self::log($request, 'unlock', false, $why, $device->member_id, $device->id);

            return ['status' => 403, 'body' => ['ok' => false, 'code' => $why, 'message' => $why === 'disabled'
                ? 'This account no longer has access to the app.'
                : 'This device has been signed out. Sign in again with your email and PIN.']];
        }

        $member = $device->member;

        if ($member->locked_until !== null && $member->locked_until->isFuture()) {
            self::log($request, 'unlock', false, 'locked', $member->id, $device->id);

            return ['status' => 423, 'body' => self::lockedBody($member)];
        }

        if (! preg_match(self::PIN_RULE, $pin) || ! Hash::check($pin, (string) $member->pin_hash)) {
            return self::wrongPin($request, $member, $device, 'unlock', $ipKey);
        }

        self::cleared($member, $device);
        $session = self::startSession($request, $device);
        self::log($request, 'unlock', true, 'ok', $member->id, $device->id);

        return ['status' => 200, 'body' => ['ok' => true, 'csrf' => self::csrfFor($session)], 'session' => $session];
    }

    /**
     * First use on a device: email + PIN. One answer for every refusal — an
     * unknown email, a member without access, a wrong PIN and a locked member
     * all read the same, so the form cannot be used to find out who is staff.
     *
     * @return array{status:int, body:array<string,mixed>, session?:string, device?:string, device_id?:int}
     */
    public static function enrol(Request $request, string $email, string $pin, string $name): array
    {
        $ipKey = 'owner-app-enrol:'.$request->ip();
        $generic = ['status' => 422, 'body' => ['ok' => false, 'code' => 'not_recognised',
            'message' => 'That email and PIN were not recognised. After several tries the account is paused for 15 minutes.']];

        if (RateLimiter::tooManyAttempts($ipKey, self::IP_MAX_ENROL_FAILS)) {
            self::log($request, 'enrol', false, 'ip_limited', null, null);

            return ['status' => 429, 'body' => ['ok' => false, 'code' => 'ip_limited', 'message' => 'Too many attempts from this connection. Try again in a few minutes.']];
        }

        $email = mb_strtolower(trim($email));
        $admin = $email === '' ? null : AdminUser::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $member = $admin === null ? null : OwnerAppMember::query()->where('admin_user_id', $admin->id)->first();

        if ($member === null || ! $member->enabled || $member->pin_hash === null) {
            Hash::check($pin, self::$dummy ??= Hash::make(random_bytes(16)));
            RateLimiter::hit($ipKey, self::LOCK_MINUTES * 60);
            self::log($request, 'enrol', false, $member === null ? 'unknown' : 'disabled', $member?->id, null);

            return $generic;
        }

        if ($member->locked_until !== null && $member->locked_until->isFuture()) {
            Hash::check($pin, self::$dummy ??= Hash::make(random_bytes(16)));
            RateLimiter::hit($ipKey, self::LOCK_MINUTES * 60);
            self::log($request, 'enrol', false, 'locked', $member->id, null);

            return $generic;
        }

        if (! preg_match(self::PIN_RULE, $pin) || ! Hash::check($pin, (string) $member->pin_hash)) {
            RateLimiter::hit($ipKey, self::LOCK_MINUTES * 60);
            self::countFailure($member);
            self::log($request, 'enrol', false, 'bad_pin', $member->id, null);

            return $generic;
        }

        $token = self::token();
        $device = OwnerAppDevice::query()->create([
            'member_id' => $member->id,
            'token_hash' => self::hash($token),
            'name' => self::deviceName($name, (string) $request->userAgent()),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'ip' => mb_substr((string) $request->ip(), 0, 45),
            'last_seen_at' => now(),
        ]);

        self::trimDevices($member);
        self::cleared($member, $device);
        $session = self::startSession($request, $device);
        self::log($request, 'enrol', true, 'ok', $member->id, $device->id);

        return ['status' => 200, 'body' => ['ok' => true, 'csrf' => self::csrfFor($session)], 'session' => $session, 'device' => $token, 'device_id' => (int) $device->id];
    }

    public static function lock(OwnerAppDevice $device): void
    {
        DB::table('owner_app_devices')->where('id', $device->id)->update(['session_hash' => null, 'session_seen_at' => null]);
    }

    /** Revoke a device: no session, no push, never usable again. */
    public static function revoke(int $deviceId, string $reason): void
    {
        DB::table('owner_app_devices')->where('id', $deviceId)->whereNull('revoked_at')->update([
            'revoked_at' => now(), 'revoked_reason' => mb_substr($reason, 0, 40),
            'session_hash' => null, 'session_seen_at' => null, 'updated_at' => now(),
        ]);
        DB::table('owner_app_push_subscriptions')->where('device_id', $deviceId)->delete();
    }

    /** Every session a member holds ends (a new PIN, access switched off). */
    public static function endSessions(int $memberId): void
    {
        DB::table('owner_app_devices')->where('member_id', $memberId)->update(['session_hash' => null, 'session_seen_at' => null]);
    }

    /** Keep the session alive: at most one write a minute, and never from the poll. */
    public static function touch(OwnerAppDevice $device): void
    {
        if ($device->session_seen_at !== null && $device->session_seen_at->gt(now()->subMinute())) {
            return;
        }
        DB::table('owner_app_devices')->where('id', $device->id)->update(['session_seen_at' => now(), 'last_seen_at' => now()]);
    }

    /* ------------------------------------------------------------- cookies */

    public static function cookiePath(Request $request): string
    {
        return rtrim($request->getBasePath(), '/').'/'.OwnerAppPath::current();
    }

    public static function cookie(Request $request, string $name, ?string $value, int $minutes): Cookie
    {
        return Cookie::create(
            $name,
            $value ?? '',
            $value === null ? 1 : time() + $minutes * 60,
            self::cookiePath($request),
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );
    }

    /** Is this a PIN we will accept? Four to eight digits, not 1111 and not 1234. */
    public static function pinProblem(string $pin): ?string
    {
        if (! preg_match(self::PIN_RULE, $pin)) {
            return 'A PIN is 4 to 8 digits.';
        }
        if (count(array_unique(str_split($pin))) === 1) {
            return 'Choose a PIN that is not one digit repeated.';
        }
        if (str_contains('01234567890', $pin) || str_contains('09876543210', $pin)) {
            return 'Choose a PIN that is not a straight run like 1234.';
        }

        return null;
    }

    /* ------------------------------------------------------------ private */

    /** @return array{status:int, body:array<string,mixed>} */
    private static function wrongPin(Request $request, OwnerAppMember $member, OwnerAppDevice $device, string $kind, string $ipKey): array
    {
        RateLimiter::hit($ipKey, self::LOCK_MINUTES * 60);
        $locked = self::countFailure($member);

        $deviceFails = (int) $device->failed_count + 1;
        DB::table('owner_app_devices')->where('id', $device->id)->update(['failed_count' => $deviceFails]);

        if ($deviceFails >= self::DEVICE_REVOKE_AFTER) {
            self::revoke((int) $device->id, 'too_many_wrong_pins');
            self::log($request, $kind, false, 'revoked', $member->id, $device->id);

            return ['status' => 403, 'body' => ['ok' => false, 'code' => 'no_device',
                'message' => 'Too many wrong PINs. This device has been signed out; sign in again with your email and PIN.']];
        }

        self::log($request, $kind, false, 'bad_pin', $member->id, $device->id);

        if ($locked) {
            return ['status' => 423, 'body' => self::lockedBody($member->refresh())];
        }

        $left = self::MEMBER_LOCK_AFTER - (int) $member->failed_count;

        return ['status' => 422, 'body' => ['ok' => false, 'code' => 'bad_pin', 'left' => $left,
            'message' => 'Wrong PIN. '.$left.' '.($left === 1 ? 'try' : 'tries').' left before a 15-minute pause.']];
    }

    /** One more wrong PIN for the member. True when it has just locked them. */
    private static function countFailure(OwnerAppMember $member): bool
    {
        $fails = (int) $member->failed_count + 1;

        if ($fails >= self::MEMBER_LOCK_AFTER) {
            DB::table('owner_app_members')->where('id', $member->id)->update([
                'failed_count' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES),
            ]);
            $member->failed_count = 0;

            return true;
        }

        DB::table('owner_app_members')->where('id', $member->id)->update(['failed_count' => $fails]);
        $member->failed_count = $fails;

        return false;
    }

    /** @return array<string,mixed> */
    private static function lockedBody(OwnerAppMember $member): array
    {
        $minutes = max(1, (int) ceil(now()->diffInSeconds($member->locked_until, false) / 60));

        return ['ok' => false, 'code' => 'locked', 'retry_minutes' => $minutes,
            'message' => 'Too many wrong PINs. The app is paused for '.$minutes.' minute'.($minutes === 1 ? '' : 's').'.'];
    }

    private static function cleared(OwnerAppMember $member, OwnerAppDevice $device): void
    {
        DB::table('owner_app_members')->where('id', $member->id)->update(['failed_count' => 0, 'locked_until' => null]);
        DB::table('owner_app_devices')->where('id', $device->id)->update(['failed_count' => 0]);
    }

    private static function startSession(Request $request, OwnerAppDevice $device): string
    {
        $session = self::token();
        DB::table('owner_app_devices')->where('id', $device->id)->update([
            'session_hash' => self::hash($session),
            'session_seen_at' => now(),
            'last_seen_at' => now(),
            'ip' => mb_substr((string) $request->ip(), 0, 45),
            'updated_at' => now(),
        ]);

        return $session;
    }

    private static function trimDevices(OwnerAppMember $member): void
    {
        $live = DB::table('owner_app_devices')->where('member_id', $member->id)->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')->orderByDesc('id')->pluck('id')->all();

        foreach (array_slice($live, self::MAX_DEVICES) as $old) {
            self::revoke((int) $old, 'replaced');
        }
    }

    private static function deviceName(string $typed, string $ua): string
    {
        $typed = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $typed) ?? '');

        if ($typed !== '') {
            return mb_substr($typed, 0, 80);
        }

        $os = match (true) {
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            default => 'Device',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => '',
        };

        return trim($os.' · '.$browser, ' ·');
    }

    public static function log(Request $request, string $kind, bool $ok, string $reason, ?int $memberId, ?int $deviceId): void
    {
        try {
            DB::table('owner_app_logins')->insert([
                'member_id' => $memberId,
                'device_id' => $deviceId,
                'kind' => $kind,
                'success' => $ok,
                'reason' => mb_substr($reason, 0, 24),
                'ip' => mb_substr((string) $request->ip(), 0, 45),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
        }
    }
}
