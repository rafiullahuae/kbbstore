<?php

declare(strict_types=1);

namespace App\Services\OwnerApp;

use App\Models\AdminUser;
use App\Models\OwnerAppDevice;
use App\Models\OwnerAppMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
 * ── GUESSING THE PIN (Lane SEC) ────────────────────────────────────────────
 *
 * Every attempt is RESERVED before bcrypt runs, by a single conditional
 * UPDATE (reserveMember(), reserveDevice(), OwnerAppThrottle::reserve()):
 *
 *   UPDATE owner_app_members SET failed_count = failed_count + 1
 *    WHERE id = ? AND lock_level < 4 AND failed_count < 5
 *      AND (locked_until IS NULL OR locked_until <= now)
 *
 * Zero rows changed = locked, refused without a hash check. The old shape —
 * read the counter, check the PIN, write read+1 — let fifty guesses sent at
 * once all read 0 and all write 1: a lockout that counted a burst as one.
 *
 *   enrolled phone   5 wrong PINs lock the member on every phone: 15 min,
 *                    then 1 h, then 24 h, then until a Full Admin unlocks them
 *                    (Users & Roles → Owner app → Unlock now). A good PIN
 *                    resets the ladder.
 *   per device       10 wrong PINs REVOKE the device: email + PIN again.
 *   email + PIN      the same ladder on counters of its OWN (enrol_*), so a
 *                    stranger guessing at the sign-in form can never lock the
 *                    owner out of the phones he already has.
 *   per connection   30 wrong unlocks / 10 failed enrolments per 15 minutes
 *                    from one IPv4 address or IPv6 /64 -> 429.
 *
 * A PIN is 6 to 8 digits. The Full Admins are emailed when a member reaches
 * the 24-hour or the admin-only lock, and when a phone is revoked.
 *
 * ── CSRF, AND WHY GETS NEED IT TOO ─────────────────────────────────────────
 *
 * The cookies are path-scoped and HttpOnly, but any script running on a shop
 * page (an XSS, a third-party tag) is SAME-ORIGIN: it can fetch() the app's
 * path and the browser attaches the cookies. So the session cookie alone opens
 * nothing. Every request behind the PIN, GETs included, carries X-OA-CSRF, an
 * HMAC of the session token under the app key, which the server hands out in
 * exactly two places: the JSON answer to a good PIN (enrol, unlock). Nothing a
 * script can GET returns it. The app keeps it in memory; a fresh launch asks
 * for the PIN again, and /api/unlock rotates the session.
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

    public const PIN_RULE = '/^\d{6,8}$/D';

    /** Minutes for lock levels 1, 2 and 3. Level 4 waits for a Full Admin. */
    public const LADDER = [1 => 15, 2 => 60, 3 => 1440];

    public const ADMIN_LEVEL = 4;

    /** Lock levels at or above this email the Full Admins. */
    public const ALERT_LEVEL = 3;

    public const ENROL_LOCK_AFTER = 5;

    /** The member's two counters: unlocking an enrolled phone, and enrolling a new one. */
    private const UNLOCK = ['failed_count', 'locked_until', 'lock_level', self::MEMBER_LOCK_AFTER];

    private const ENROL = ['enrol_failed_count', 'enrol_locked_until', 'enrol_lock_level', self::ENROL_LOCK_AFTER];

    /** A hash of nothing anybody can type, so a miss costs what a hit costs. */
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
     * A PIN on an enrolled phone. Also the way back in for a phone whose
     * session is still live but whose app lost its in-memory CSRF value (a
     * fresh launch): the session is rotated and a fresh value returned.
     *
     * @return array{status:int, body:array<string,mixed>, session?:string}
     */
    public static function unlock(Request $request, OwnerAppDevice $device, string $pin): array
    {
        $ipKey = OwnerAppThrottle::bucket('pin', $request->ip());

        if (! OwnerAppThrottle::reserve($ipKey, self::IP_MAX_FAILS, self::LOCK_MINUTES * 60)) {
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

        // The member's attempt first: a locked member's phone is refused
        // without its device counter moving, as before.
        if (! self::reserve((int) $member->id, self::UNLOCK)) {
            self::escalate((int) $member->id, self::UNLOCK, 'unlock', $request);
            self::log($request, 'unlock', false, 'locked', $member->id, $device->id);

            return ['status' => 423, 'body' => self::lockedBody((int) $member->id, self::UNLOCK)];
        }

        if (! self::reserveDevice((int) $device->id)) {
            self::unreserve((int) $member->id, self::UNLOCK);
            self::revokeForPins($request, (int) $device->id);
            self::log($request, 'unlock', false, 'revoked', $member->id, $device->id);

            return self::revokedAnswer();
        }

        if (! preg_match(self::PIN_RULE, $pin) || ! Hash::check($pin, (string) $member->pin_hash)) {
            return self::wrongPin($request, $member, $device);
        }

        OwnerAppThrottle::release($ipKey);
        self::cleared((int) $member->id, self::UNLOCK);
        DB::table('owner_app_devices')->where('id', $device->id)->update(['failed_count' => 0]);
        $session = self::startSession($request, $device);
        self::log($request, 'unlock', true, 'ok', $member->id, $device->id);

        return ['status' => 200, 'body' => ['ok' => true, 'csrf' => self::csrfFor($session)], 'session' => $session];
    }

    /**
     * First use on a device: email + PIN. One answer for every refusal — an
     * unknown email, a member without access, a wrong PIN and a locked member
     * all read the same, so the form cannot be used to find out who is staff.
     *
     * And they COST the same. A malformed PIN is refused before the email is
     * looked up at all; every other refusal runs exactly one bcrypt check of
     * the configured cost (against a dummy hash when there is no real one) and
     * the same one-row UPDATE, so the time an answer takes says nothing about
     * whether the email belongs to a member.
     *
     * @return array{status:int, body:array<string,mixed>, session?:string, device?:string, device_id?:int}
     */
    public static function enrol(Request $request, string $email, string $pin, string $name): array
    {
        $ipKey = OwnerAppThrottle::bucket('enrol', $request->ip());
        $generic = ['status' => 422, 'body' => ['ok' => false, 'code' => 'not_recognised',
            'message' => 'That email and PIN were not recognised. After several tries the account is paused.']];

        if (! OwnerAppThrottle::reserve($ipKey, self::IP_MAX_ENROL_FAILS, self::LOCK_MINUTES * 60)) {
            self::log($request, 'enrol', false, 'ip_limited', null, null);

            return ['status' => 429, 'body' => ['ok' => false, 'code' => 'ip_limited', 'message' => 'Too many attempts from this connection. Try again in a few minutes.']];
        }

        if (! preg_match(self::PIN_RULE, $pin)) {
            self::log($request, 'enrol', false, 'bad_pin', null, null);

            return $generic;
        }

        $email = mb_strtolower(trim($email));
        $member = $email === '' ? null : OwnerAppMember::query()
            ->whereIn('admin_user_id', AdminUser::query()->select('id')->whereRaw('LOWER(email) = ?', [$email]))
            ->first();

        if ($member === null || ! $member->enabled || $member->pin_hash === null) {
            // The same two statements a member's refusal runs, against no row.
            self::reserve(0, self::ENROL);
            self::escalate(0, self::ENROL, 'enrol', $request);
            Hash::check($pin, self::dummyHash());
            self::log($request, 'enrol', false, $member === null ? 'unknown' : 'disabled', $member?->id, null);

            return $generic;
        }

        if (! self::reserve((int) $member->id, self::ENROL)) {
            Hash::check($pin, self::dummyHash());
            self::escalate((int) $member->id, self::ENROL, 'enrol', $request);
            self::log($request, 'enrol', false, 'locked', $member->id, null);

            return $generic;
        }

        if (! Hash::check($pin, (string) $member->pin_hash)) {
            self::escalate((int) $member->id, self::ENROL, 'enrol', $request);
            self::log($request, 'enrol', false, 'bad_pin', $member->id, null);

            return $generic;
        }

        OwnerAppThrottle::release($ipKey);

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
        self::cleared((int) $member->id, self::ENROL);
        $session = self::startSession($request, $device);
        self::log($request, 'enrol', true, 'ok', $member->id, $device->id);

        return ['status' => 200, 'body' => ['ok' => true, 'csrf' => self::csrfFor($session)], 'session' => $session, 'device' => $token, 'device_id' => (int) $device->id];
    }

    /** Does this request carry the CSRF value for its own session cookie? */
    public static function csrfMatches(Request $request): bool
    {
        $sent = (string) $request->headers->get('X-OA-CSRF', '');
        $session = (string) $request->cookies->get(self::SESSION_COOKIE, '');

        return $sent !== '' && $session !== '' && hash_equals(self::csrfFor($session), $sent);
    }

    /** Is the member locked out of the PIN pad, the sign-in form, or both? For the admin screen. */
    public static function lockState(object $m): array
    {
        $now = now();
        $pad = (int) ($m->lock_level ?? 0) >= self::ADMIN_LEVEL || ($m->locked_until !== null && $now->lt($m->locked_until));
        $form = (int) ($m->enrol_lock_level ?? 0) >= self::ADMIN_LEVEL || (($m->enrol_locked_until ?? null) !== null && $now->lt($m->enrol_locked_until));

        return [
            'locked' => $pad || $form,
            'admin_only' => (int) ($m->lock_level ?? 0) >= self::ADMIN_LEVEL || (int) ($m->enrol_lock_level ?? 0) >= self::ADMIN_LEVEL,
        ];
    }

    /** What a Full Admin's "Unlock now" clears: both ladders, every counter. */
    public const UNLOCKED = [
        'failed_count' => 0, 'locked_until' => null, 'lock_level' => 0,
        'enrol_failed_count' => 0, 'enrol_locked_until' => null, 'enrol_lock_level' => 0,
    ];

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

    /** Is this a PIN we will accept? Six to eight digits, not 111111 and not 123456. */
    public static function pinProblem(string $pin): ?string
    {
        if (! preg_match(self::PIN_RULE, $pin)) {
            return 'A PIN is 6 to 8 digits.';
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
    private static function wrongPin(Request $request, OwnerAppMember $member, OwnerAppDevice $device): array
    {
        $memberId = (int) $member->id;
        $locked = self::escalate($memberId, self::UNLOCK, 'unlock', $request);

        if ((int) DB::table('owner_app_devices')->where('id', $device->id)->value('failed_count') >= self::DEVICE_REVOKE_AFTER) {
            self::revokeForPins($request, (int) $device->id);
            self::log($request, 'unlock', false, 'revoked', $memberId, $device->id);

            return self::revokedAnswer();
        }

        self::log($request, 'unlock', false, 'bad_pin', $memberId, $device->id);

        $row = DB::table('owner_app_members')->where('id', $memberId)->first(['failed_count', 'locked_until', 'lock_level']);

        if ($locked !== null || self::isLocked($row, self::UNLOCK)) {
            return ['status' => 423, 'body' => self::lockedBody($memberId, self::UNLOCK)];
        }

        $left = max(1, self::MEMBER_LOCK_AFTER - (int) $row->failed_count);
        $nextLevel = (int) $row->lock_level + 1;
        $then = $nextLevel >= self::ADMIN_LEVEL
            ? 'before the app is locked until a Full Admin unlocks it'
            : 'before a '.[1 => '15-minute', 2 => 'one-hour', 3 => '24-hour'][$nextLevel].' pause';

        return ['status' => 422, 'body' => ['ok' => false, 'code' => 'bad_pin', 'left' => $left,
            'message' => 'Wrong PIN. '.$left.' '.($left === 1 ? 'try' : 'tries').' left '.$then.'.']];
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function revokedAnswer(): array
    {
        return ['status' => 403, 'body' => ['ok' => false, 'code' => 'no_device',
            'message' => 'Too many wrong PINs. This device has been signed out; sign in again with your email and PIN.']];
    }

    private static function revokeForPins(Request $request, int $deviceId): void
    {
        $was = DB::table('owner_app_devices')->where('id', $deviceId)->whereNull('revoked_at')->exists();
        self::revoke($deviceId, 'too_many_wrong_pins');

        if ($was) {
            OwnerAppAlerts::deviceRevoked($deviceId, (string) $request->ip());
        }
    }

    /**
     * Take one attempt from a member's ladder, atomically. False = locked
     * (or the counter is already full and its lock is being applied).
     *
     * @param array{0:string,1:string,2:string,3:int} $c
     */
    private static function reserve(int $memberId, array $c): bool
    {
        [$count, $until, $level, $max] = $c;

        return DB::table('owner_app_members')
            ->where('id', $memberId)
            ->where($level, '<', self::ADMIN_LEVEL)
            ->where($count, '<', $max)
            ->where(fn ($q) => $q->whereNull($until)->orWhere($until, '<=', now()))
            ->increment($count) === 1;
    }

    /** @param array{0:string,1:string,2:string,3:int} $c */
    private static function unreserve(int $memberId, array $c): void
    {
        DB::table('owner_app_members')->where('id', $memberId)->where($c[0], '>', 0)->decrement($c[0]);
    }

    private static function reserveDevice(int $deviceId): bool
    {
        return DB::table('owner_app_devices')
            ->where('id', $deviceId)
            ->whereNull('revoked_at')
            ->where('failed_count', '<', self::DEVICE_REVOKE_AFTER)
            ->increment('failed_count') === 1;
    }

    /**
     * When the counter is full, step the ladder ONCE: a compare-and-set on the
     * level, so of every request that saw it full exactly one applies the
     * lock. Also the repair path — a counter left full by a request that died
     * between its reservation and this call is locked by the next attempt.
     * Returns the new level when this call applied it.
     *
     * @param array{0:string,1:string,2:string,3:int} $c
     */
    private static function escalate(int $memberId, array $c, string $kind, Request $request): ?int
    {
        [$count, $until, $level, $max] = $c;
        $row = DB::table('owner_app_members')->where('id', $memberId)->first([$count, $level]);

        if ($row === null || (int) $row->{$count} < $max || (int) $row->{$level} >= self::ADMIN_LEVEL) {
            return null;
        }

        $was = (int) $row->{$level};
        $now = $was + 1;
        $changed = DB::table('owner_app_members')->where('id', $memberId)->where($level, $was)->where($count, '>=', $max)->update([
            $count => 0,
            $level => $now,
            $until => $now >= self::ADMIN_LEVEL ? null : now()->addMinutes(self::LADDER[$now]),
        ]);

        if ($changed !== 1) {
            return null;
        }

        if ($now >= self::ALERT_LEVEL) {
            OwnerAppAlerts::memberLocked($memberId, $now, $kind, (string) $request->ip());
        }

        return $now;
    }

    /** @param array{0:string,1:string,2:string,3:int} $c */
    private static function isLocked(?object $row, array $c): bool
    {
        [, $until, $level] = $c;

        return $row !== null && ((int) $row->{$level} >= self::ADMIN_LEVEL
            || ($row->{$until} !== null && now()->lt(\Illuminate\Support\Carbon::parse($row->{$until}))));
    }

    /**
     * @param array{0:string,1:string,2:string,3:int} $c
     * @return array<string,mixed>
     */
    private static function lockedBody(int $memberId, array $c): array
    {
        [, $until, $level] = $c;
        $row = DB::table('owner_app_members')->where('id', $memberId)->first([$until, $level]);

        if ($row === null || (int) $row->{$level} >= self::ADMIN_LEVEL || $row->{$until} === null) {
            return ['ok' => false, 'code' => 'locked', 'admin_unlock' => true,
                'message' => 'Too many wrong PINs. The app is locked until a Full Admin unlocks it in Users & Roles → Owner app.'];
        }

        $minutes = max(1, (int) ceil(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($row->{$until}), false) / 60));

        return ['ok' => false, 'code' => 'locked', 'retry_minutes' => $minutes,
            'message' => 'Too many wrong PINs. The app is paused for '.self::duration($minutes).'.'];
    }

    private static function duration(int $minutes): string
    {
        if ($minutes >= 120 && $minutes % 60 === 0) {
            return ($minutes / 60).' hours';
        }
        if ($minutes === 60) {
            return '1 hour';
        }

        return $minutes.' minute'.($minutes === 1 ? '' : 's');
    }

    /** @param array{0:string,1:string,2:string,3:int} $c */
    private static function cleared(int $memberId, array $c): void
    {
        DB::table('owner_app_members')->where('id', $memberId)->update([$c[0] => 0, $c[1] => null, $c[2] => 0]);
    }

    /**
     * A bcrypt hash of random bytes AT THE CONFIGURED COST, kept in the cache.
     * Made with Hash::make() on every request instead, an unknown email would
     * cost a make() AND a check() — twice a member's time, the very oracle
     * this exists to close. Remade when the cost setting changes.
     */
    private static function dummyHash(): string
    {
        if (self::$dummy !== null && ! Hash::needsRehash(self::$dummy)) {
            return self::$dummy;
        }

        try {
            $hash = \Illuminate\Support\Facades\Cache::get('kbb.owner_app.dummy_hash');
            if (! is_string($hash) || $hash === '' || Hash::needsRehash($hash)) {
                $hash = Hash::make(bin2hex(random_bytes(16)));
                \Illuminate\Support\Facades\Cache::forever('kbb.owner_app.dummy_hash', $hash);
            }
        } catch (\Throwable) {
            $hash = Hash::make(bin2hex(random_bytes(16)));
        }

        return self::$dummy = $hash;
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
